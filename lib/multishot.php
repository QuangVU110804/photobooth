<?php
require_once __DIR__ . '/capture.php';
require_once __DIR__ . '/multishot-frames.php';

class MultishotError extends RuntimeException {}

function multishotRoot(array $config): string {
    return $config['foldersAbs']['tmp'] . '/multishot';
}

function multishotLock(string $path, int $mode, callable $work) {
    $lock = @fopen($path, 'c+b');
    if (!$lock) throw new RuntimeException('Could not open session lock');
    try {
        if (!flock($lock, $mode)) throw new MultishotError('Phiên đang xử lý, hãy thử lại', 409);
        return $work();
    } finally {
        fclose($lock);
    }
}

function multishotSave(string $dir, array $session): void {
    $json = json_encode($session, JSON_THROW_ON_ERROR);
    if (@file_put_contents($dir . '/state.new', $json) !== strlen($json)
        || !@rename($dir . '/state.new', $dir . '/state.json')) {
        throw new RuntimeException('Could not save capture state');
    }
}

function multishotStart(array $config, string $owner, $frameId, $source, $flip): array {
    $frame = is_string($frameId) ? (multishotFrames()[$frameId] ?? null) : null;
    if (!$frame) throw new MultishotError('Khung nhiều ảnh không hợp lệ', 400);
    if (!in_array($source, ['browser', 'external'], true) || !in_array($flip, ['off', 'flip-horizontal', 'flip-vertical'], true)) {
        throw new MultishotError('Nguồn camera hoặc chế độ lật không hợp lệ', 400);
    }
    $root = multishotRoot($config);
    if (!is_dir($root) && !@mkdir($root, 0755) && !is_dir($root)) throw new RuntimeException('Could not create session storage');
    return multishotLock($root . '/.gate', LOCK_SH, function () use ($root, $config, $frame, $owner, $source, $flip) {
        $deny = 'Require all denied' . PHP_EOL;
        if (!is_file($root . '/.htaccess') && @file_put_contents($root . '/.htaccess', $deny, LOCK_EX) !== strlen($deny)) {
            throw new RuntimeException('Could not protect session storage');
        }
        $id = 'multishot-' . newCaptureFilename($config);
        if (!faceEffectFilename($id)) throw new InvalidArgumentException('Invalid multishot filename');
        $dir = $root . '/' . $id;
        if (!@mkdir($dir, 0755) || !@copy($frame['path'], $dir . '/frame.png')) {
            throw new RuntimeException('Could not save session frame');
        }
        // Validate the copied PNG before requesting any exposures.
        $overlay = @imagecreatefrompng($dir . '/frame.png');
        if (!$overlay) {
            @unlink($dir . '/frame.png');
            @rmdir($dir);
            throw new MultishotError('Không đọc được PNG của khung bộ ảnh. Hãy chọn khung khác.', 400);
        }
        imagedestroy($overlay);
        unset($frame['path']);
        $session = [
            'id' => $id,
            'owner' => $owner,
            'frame' => $frame,
            'source' => $source,
            'flip' => $flip,
            'state' => 'capturing',
            'shots' => array_fill(0, count($frame['slots']), []),
        ];
        multishotSave($dir, $session);
        return multishotStatus($session);
    });
}

/** Only filenames issued at start can address session directories. */
function multishotDirectory(array $config, $id): ?string {
    if (!faceEffectFilename($id) || !str_starts_with($id, 'multishot-')) return null;
    $path = multishotRoot($config) . '/' . $id;
    return !is_link($path) && is_file($path . '/state.json') ? $path : null;
}

function multishotUse(array $config, $id, ?string $owner, callable $work) {
    if (!multishotDirectory($config, $id)) throw new MultishotError('Không tìm thấy phiên chụp', 404);
    return multishotLock(multishotRoot($config) . '/.gate', LOCK_SH, function () use ($config, $id, $owner, $work) {
        $dir = multishotDirectory($config, $id);
        if (!$dir) throw new MultishotError('Không tìm thấy phiên chụp', 404);
        return multishotLock($dir . '/.lock', LOCK_EX, function () use ($dir, $owner, $work) {
            $session = json_decode(file_get_contents($dir . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
            if ($owner !== null && !hash_equals($session['owner'], $owner)) throw new MultishotError('Không tìm thấy phiên chụp', 404);
            return $work($dir, $session);
        });
    });
}

function multishotStatus(array $session): array {
    return [
        'id' => $session['id'],
        'count' => count($session['shots']),
        'state' => $session['state'],
        'shots' => array_map(static fn(array $shot) => $shot['state'] ?? 'empty', $session['shots']),
    ];
}

function multishotSlot(array $session, $value): int {
    if (!is_string($value) || !preg_match('/^[0-5]$/D', $value) || !array_key_exists((int) $value, $session['shots'])) {
        throw new MultishotError('Ô ảnh không hợp lệ', 400);
    }
    return (int) $value;
}

function multishotRawName(array $session, int $slot): string {
    return 'raw-' . substr(hash('sha256', $session['id']), 0, 16) . "-$slot.jpg";
}

function multishotAsset(string $dir, array $session, $value, bool $edited): void {
    $slot = multishotSlot($session, $value);
    $state = $session['shots'][$slot]['state'] ?? null;
    if ($session['state'] !== 'capturing' || ($edited ? $state !== 'edited' : !in_array($state, ['raw', 'edited'], true))) {
        throw new MultishotError('Không tìm thấy ảnh', 404);
    }
    $path = $dir . '/' . ($edited ? "edited-$slot.jpg" : multishotRawName($session, $slot));
    if (!is_file($path)) throw new MultishotError('Không tìm thấy ảnh', 404);
    header('Content-Type: image/jpeg');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    readfile($path);
}

function multishotValidCapture(string $path): bool {
    $info = is_file($path) ? @getimagesize($path) : false;
    if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] * $info[1] > 40000000) return false;
    $image = @imagecreatefromjpeg($path);
    if (!$image) return false;
    imagedestroy($image);
    return true;
}

function multishotCapture(array $config, string $dir, array $session, $value, $request, $upload): array {
    $slot = multishotSlot($session, $value);
    if (!is_string($request) || !preg_match('/^[a-z0-9-]{8,64}$/D', $request)) throw new MultishotError('Mã lượt chụp không hợp lệ', 400);
    if ($session['state'] !== 'capturing') throw new MultishotError('Phiên đã kết thúc', 409);
    $shot = $session['shots'][$slot];
    $filename = multishotRawName($session, $slot);
    if (isset($shot['request']) && $shot['request'] !== $request) throw new MultishotError('Ô ảnh đã được nhận', 409);
    if (in_array($shot['state'] ?? '', ['raw', 'edited'], true)) {
        return multishotStatus($session) + ['file' => $filename];
    }
    for ($i = 0; $i < $slot; $i++) {
        if (($session['shots'][$i]['state'] ?? null) !== 'edited') throw new MultishotError('Chưa xử lý xong ô ảnh trước', 409);
    }

    $path = "$dir/$filename";
    $pending = "$dir/pending-$slot.jpg";
    if (!is_file($path)) {
        if (!multishotValidCapture($pending)) {
            $bytes = !$config['dev']['demo_images'] && $session['source'] === 'browser' ? decodeCameraImage($upload, 'image/jpeg') : null;
            $session['shots'][$slot] = ['request' => $request, 'state' => 'pending'];
            multishotSave($dir, $session);
            if (is_file($pending)) @unlink($pending);
            $config['preview']['flip'] = $session['flip'];
            saveCameraCapture($config, $pending, $session['source'], $bytes);
            // An unmodified browser JPEG was fully decoded before the exact bytes were written.
            if (($bytes === null || $session['flip'] !== 'off') && !multishotValidCapture($pending)) {
                @unlink($pending);
                throw new MultishotError('Ảnh chụp phải là JPEG hợp lệ, tối đa 40 MP', 400);
            }
        }
        if (!@rename($pending, $path)) throw new RuntimeException('Could not publish capture');
    }
    $session['shots'][$slot] = ['request' => $request, 'state' => 'raw'];
    multishotSave($dir, $session);
    return multishotStatus($session) + ['file' => $filename];
}

function multishotClean(string $dir, array $session, bool $keepOriginal): void {
    $files = ['frame.png', 'state.new', 'final.part', 'thumb.part'];
    foreach ($session['shots'] as $i => $_) {
        if (!$keepOriginal) $files[] = multishotRawName($session, $i);
        $files[] = "pending-$i.jpg";
        $files[] = "edited-$i.jpg";
        $files[] = "edited-$i.jpg.new";
    }
    foreach ($files as $file) {
        $path = "$dir/$file";
        if (is_file($path) && !@unlink($path)) throw new RuntimeException('Could not clean session files');
    }
}

function multishotForget(string $dir, array $session, string $state, bool $keepOriginal): array {
    $session['state'] = $state;
    multishotSave($dir, $session);
    multishotClean($dir, $session, $keepOriginal);
    return multishotStatus($session);
}

/** Hold the gate through the legacy reset, including deletion of the gallery database. */
function multishotReset(array $config) {
    $root = multishotRoot($config);
    if (!is_dir($root) && !@mkdir($root, 0755) && !is_dir($root)) throw new RuntimeException('Could not create session storage');
    $gate = @fopen($root . '/.gate', 'c+b');
    if (!$gate || !flock($gate, LOCK_EX)) throw new RuntimeException('Could not lock sessions for reset');
    foreach (glob($root . '/multishot-*.jpg', GLOB_ONLYDIR) ?: [] as $dir) {
        $id = basename($dir);
        if (!multishotDirectory($config, $id)) continue;
        $session = json_decode(file_get_contents($dir . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
        multishotForget($dir, $session, 'deleted', false);
        clearPhotoCaches($id);
    }
    return $gate;
}
