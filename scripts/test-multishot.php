<?php
$root = dirname(__DIR__);
function multiCheck(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}

multiCheck(is_file($root . '/lib/multishot-frames.php'), 'The multishot catalog should be available');
require_once $root . '/lib/multishot-frames.php';
$frames = multishotFrames();
multiCheck(is_dir($root . '/resources/img/Multi-frames'), 'The multi-photo catalog uses the Multi-frames folder');
foreach ($frames as $frame) {
    multiCheck(str_contains(str_replace('\\', '/', $frame['path']), '/Multi-frames/'), 'All active multi-photo frames come from Multi-frames');
    multiCheck($frame['topic'] === 'HMU Studio', 'Numbered topic folders display the label without the sort prefix');
}
$layouts = [];
foreach ($frames as $frame) {
    $layouts[$frame['layout']] = count($frame['slots']);
    $png = imagecreatefrompng($frame['path']);
    foreach ($frame['slots'] as [$x, $y, $w, $h]) {
        multiCheck((imagecolorat($png, $x + intdiv($w, 2), $y + intdiv($h, 2)) >> 24) === 127, 'Photo slots must be transparent');
    }
    imagedestroy($png);
}
multiCheck($layouts === ['1x2' => 2, '1x3' => 3, '1x4' => 4, '2x2' => 4, '2x3' => 6], 'Catalog must contain all five layouts');
$bad = array_values($frames)[0];
$bad['slots'][0][2] = $bad['width'];
$validate = function ($entry) use ($root) {
    return multishotFrame($root . '/resources/img/Multi-frames', $entry);
};
multiCheck($validate($bad) === null, 'A slot outside the canvas must be rejected');
$bad = array_values($frames)[0];
array_pop($bad['slots']);
multiCheck($validate($bad) === null, 'A template with missing slots must be rejected');
$bad = array_values($frames)[0];
$bad['png'] = '../frames/current_active_frame.png';
multiCheck($validate($bad) === null, 'A template outside the catalog must be rejected');
echo "Multishot catalog checks passed\n";

multiCheck(is_file($root . '/lib/multishot.php'), 'The multishot session store should be available');
require_once $root . '/lib/multishot.php';
$tmp = sys_get_temp_dir() . '/multishot-' . bin2hex(random_bytes(6));
mkdir($tmp);
register_shutdown_function(function () use ($tmp) {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($tmp);
});
$config = ['foldersAbs' => ['tmp' => $tmp], 'picture' => ['keep_original' => true, 'naming' => 'dateformatted'], 'database' => ['file' => 'db'], 'preview' => ['flip' => 'off']];
$a = multishotStart($config, 'owner-a', 'hmu-1x3', 'browser', 'off');
$b = multishotStart($config, 'owner-b', 'hmu-2x3', 'external', 'off');
multiCheck($a['count'] === 3 && $b['count'] === 6 && $a['id'] !== $b['id'], 'Each session must keep its own frame and slot count');
multiCheck(is_file($tmp . '/multishot/.htaccess'), 'Session directory must deny direct HTTP reads');
multiCheck(multishotUse($config, $a['id'], 'owner-a', fn($dir, $s) => multishotStatus($s)) === $a, 'Starting a second session must not affect the first');

$discovery = $tmp . '/discovery';
mkdir($discovery . '/02 - Sự kiện', 0755, true);
$sample = $frames['hmu-1x2'];
copy($sample['path'], $discovery . '/02 - Sự kiện/Mẫu mới.png');
copy($sample['path'], $discovery . '/missing-metadata.png');
$sample['id'] = 'new-sample';
file_put_contents($discovery . '/02 - Sự kiện/Mẫu mới.json', json_encode($sample));
$sample['id'] = 'damaged-sample';
file_put_contents($discovery . '/damaged.png', substr(file_get_contents($frames['hmu-1x2']['path']), 0, 33));
file_put_contents($discovery . '/damaged.json', json_encode($sample));
$discovered = multishotFrames($discovery);
multiCheck(array_keys($discovered) === ['new-sample'], 'Discover new PNG and JSON pairs and skip PNGs without slot data');
multiCheck($discovered['new-sample']['topic'] === 'Sự kiện' && $discovered['new-sample']['png'] === '02 - Sự kiện/Mẫu mới.png', 'Nested Unicode PNGs use their directory topic and safe relative name');
try {
    multishotUse($config, $a['id'], 'owner-b', fn($dir, $s) => $s);
    throw new LogicException('A different browser must not read a session');
} catch (MultishotError $error) {
    multiCheck($error->getCode() === 404, 'Unknown and foreign sessions return the same status');
}
echo "Multishot session checks passed\n";


$config['dev'] = ['demo_images' => false];
$bytes = file_get_contents($root . '/scripts/fixtures/filter-face.jpg');
$jpeg = 'data:image/jpeg;base64,' . base64_encode($bytes);
$attempt = static function ($slot, $key, $image) use ($config, $a) {
    return multishotUse($config, $a['id'], 'owner-a', fn($dir, $s) => multishotCapture($config, $dir, $s, $slot, $key, $image));
};
multiCheck(function_exists('multishotCapture'), 'Sessions must accept a camera capture');
$scan = strpos($bytes, "\xff\xda");
$truncated = substr($bytes, 0, $scan);
multiCheck(@getimagesizefromstring($truncated) !== false && @imagecreatefromstring($truncated) === false, 'Truncated fixture has a valid JPEG header but is not decodable');
try {
    $attempt('0', 'shot-first', 'data:image/jpeg;base64,' . base64_encode($truncated));
    throw new LogicException('A corrupt webcam upload must never become a capture');
} catch (InvalidArgumentException $error) {
    $status = multishotUse($config, $a['id'], 'owner-a', fn($dir, $s) => multishotStatus($s));
    multiCheck($status['shots'][0] === 'empty', 'Reject the corrupt upload before recording a pending shot');
}
$recoveryPath = multishotDirectory($config, $a['id']) . '/pending-0.jpg';
file_put_contents($recoveryPath, $truncated);
$first = $attempt('0', 'shot-first', $jpeg);
multiCheck($first['shots'][0] === 'raw' && $first['shots'][1] === 'empty', 'Only the captured slot changes state');
multiCheck($attempt('0', 'shot-first', 'ignored replay') === $first, 'Replaying a confirmed request must return the existing capture');
foreach ([['1', 'wrong-order'], ['0', 'second-capture']] as [$slot, $key]) {
    try {
        $attempt($slot, $key, $jpeg);
        throw new LogicException('Repeating a slot or skipping processing must be rejected');
    } catch (MultishotError $error) {
        multiCheck($error->getCode() === 409, 'Out-of-order capture returns a conflict');
    }
}
$shotPath = multishotDirectory($config, $a['id']) . '/' . $first['file'];
multiCheck(file_get_contents($shotPath) === $bytes, 'Browser capture must preserve bytes with flip off');
echo "Multishot capture and replay checks passed\n";


multiCheck(is_file($root . '/lib/multishot-render.php'), 'The multishot compositor should be available');
require_once $root . '/lib/multishot-render.php';
$config['picture'] += ['flip' => 'off', 'rotation' => '0'];
$config['textonpicture'] = ['enabled' => false, 'font' => ''];
$config['jpeg_quality'] = ['image' => 95, 'thumb' => 85];
$colors = [0xe04040, 0x208040, 0x3060d0, 0xc07010, 0x802090, 0x208090];
foreach ($frames as $frame) {
    $batch = multishotStart($config, 'owner-a', $frame['id'], 'browser', 'off');
    foreach ($frame['slots'] as $i => $rect) {
        $color = $colors[$i];
        $image = imagecreatetruecolor(72, 140);
        imagefill($image, 0, 0, 0xeeeeee);
        imagefilledrectangle($image, 0, 26, 71, 114, $color);
        ob_start();
        imagejpeg($image, null, 100);
        $upload = 'data:image/jpeg;base64,' . base64_encode(ob_get_clean());
        imagedestroy($image);
        $capture = multishotUse($config, $batch['id'], 'owner-a', fn($dir, $s) => multishotCapture($config, $dir, $s, (string) $i, "request-$i", $upload));
        $process = fn($file) => multishotUse($config, $batch['id'], 'owner-a', fn($dir, $s) => multishotProcess($config, $dir, $s, (string) $i, $file, []));
        if ($i === 0) {
            try {
                $process($first['file'] === $capture['file'] ? 'foreign.jpg' : $first['file']);
                throw new LogicException('Processing must reject an arbitrary raw filename');
            } catch (MultishotError $error) {
                multiCheck($error->getCode() === 400, 'Filename must match the requested slot');
            }
        }
        $processed = $process($capture['file']);
        multiCheck($processed['shots'][$i] === 'edited' && $process($capture['file']) === $processed, 'Slot processing may be replayed');
    }
    $sheet = multishotUse($config, $batch['id'], 'owner-a', fn($dir, $s) => renderMultishot($config, $dir, $s['frame']));
    multiCheck(imagesx($sheet) === $frame['width'] && imagesy($sheet) === $frame['height'], 'Frame controls final dimensions');
    foreach ($frame['slots'] as $i => [$x, $y, $w, $h]) {
        foreach ([$y + 10, $y + intdiv($h, 2), $y + $h - 10] as $sy) {
            $pixel = imagecolorat($sheet, $x + intdiv($w, 2), $sy);
            foreach ([0, 8, 16] as $shift) {
                multiCheck(abs((($pixel >> $shift) & 255) - (($colors[$i] >> $shift) & 255)) < 10, 'Portrait must cover its assigned slot using centered crop');
            }
        }
    }
    multiCheck((imagecolorat($sheet, 12, 12) & 0xffffff) === 0x10343d, 'Opaque PNG decoration must remain above the photos');
    imagedestroy($sheet);
}
echo "All five multishot layouts render in slot order with centered crop\n";

multiCheck(function_exists('multishotComplete'), 'A multishot session must publish a finished JPEG');
foreach (['images', 'thumbs'] as $folder) {
    mkdir($tmp . '/' . $folder);
    $config['foldersAbs'][$folder] = $tmp . '/' . $folder;
}
$config['picture'] += ['thumb_size' => '120px', 'permissions' => '0644'];
$config['database']['enabled'] = false;
$publish = fn($cfg, $id) => multishotUse($cfg, $id, 'owner-a', fn($dir, $s) => multishotComplete($cfg, $dir, $s));
try {
    $publish($config, $a['id']);
    throw new LogicException('Incomplete session must not publish a JPEG');
} catch (MultishotError $error) {
    multiCheck($error->getCode() === 409, 'Incomplete sessions are rejected');
}
$file = $publish($config, $batch['id'])['file'];
multiCheck(is_file($tmp . '/images/' . $file) && is_file($tmp . '/thumbs/' . $file), 'Publish both the final photo and thumbnail');
multiCheck($publish($config, $batch['id'])['file'] === $file && count(glob($tmp . '/images/*.jpg')) === 1, 'Completion is idempotent');
$batchDir = multishotDirectory($config, $batch['id']);
multiCheck(count(glob($batchDir . '/raw-*.jpg')) === 6 && !glob($batchDir . '/edited-*.jpg'), 'Keep originals and clean processed tiles after publication');
$config['picture']['keep_original'] = false;
$publish($config, $batch['id']);
multiCheck(!glob($batchDir . '/raw-*.jpg'), 'Remove originals when keep_original is off');
multishotUse($config, $batch['id'], null, fn($dir, $s) => multishotForget($dir, $s, 'deleted', false));
unlink($tmp . '/images/' . $file);
try {
    $publish($config, $batch['id']);
    throw new LogicException('Deleted photo must not be recreated');
} catch (MultishotError $error) {
    multiCheck($error->getCode() === 410 && !is_file($tmp . '/images/' . $file), 'Deleted output must stay gone on replay');
}
echo "Multishot publication, retention and deletion replay checks passed\n";
