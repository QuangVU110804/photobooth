<?php
require_once __DIR__ . '/multishot.php';
require_once __DIR__ . '/beauty-upload.php';
require_once __DIR__ . '/applyEffects.php';
require_once __DIR__ . '/resize.php';
require_once __DIR__ . '/applyText.php';

function multishotProcess(array $config, string $dir, array $session, $value, $file, array $effects): array {
    $slot = multishotSlot($session, $value);
    if ($file !== multishotRawName($session, $slot)) throw new MultishotError('Ảnh nguồn không thuộc ô chụp', 400);
    if ($session['state'] !== 'capturing') throw new MultishotError('Phiên đã kết thúc', 409);
    $state = $session['shots'][$slot]['state'] ?? null;
    if ($state === 'edited') return multishotStatus($session);
    if ($state !== 'raw') throw new MultishotError('Ô này chưa có ảnh', 409);
    $path = $dir . '/' . $file;
    if (!is_file($path)) throw new RuntimeException('Capture file is missing');

    $faces = parseFaceEffects($effects['faceEffects'] ?? null);
    $beauty = parseBeautyEffects($effects['beautyEffects'] ?? null);
    foreach (array_keys($faces) as $name) {
        if ($name !== $file) throw new MultishotError('Hiệu ứng không thuộc ảnh nguồn', 400);
    }
    if (isset($effects['beautyImage']) && $beauty) throw new InvalidArgumentException('Invalid GPU beauty capture');
    $beautyBytes = isset($effects['beautyImage']) ? decodeBeautyImage($effects['beautyImage'], $path) : null;
    $image = $beautyBytes === null ? @imagecreatefromjpeg($path) : @imagecreatefromstring($beautyBytes);
    if (!$image) throw new InvalidArgumentException('Invalid JPEG capture');
    try {
        if ($beautyBytes === null) {
            $orientation = skinBeautyJpegOrientation($path);
            if ($beauty) applySkinBeauty($image, skinBeautyRawOrientation($beauty, $orientation));
            $image = orientPhotoPixels($image, $orientation);
        }
        unset($beautyBytes);
        $config['picture']['take_frame'] = false;
        $config['picture']['polaroid_effect'] = false;
        [$image] = editSingleImage($config, $image, $faces[$file] ?? []);
        [, , $w, $h] = $session['frame']['slots'][$slot];
        $sw = imagesx($image);
        $sh = imagesy($image);
        // Keep proportions and crop around the center of the still.
        $cw = max(1, min($sw, (int) round($sh * $w / $h)));
        $ch = max(1, min($sh, (int) round($sw * $h / $w)));
        $tile = imagecreatetruecolor($w, $h);
        imagecopyresampled($tile, $image, 0, 0, intdiv($sw - $cw, 2), intdiv($sh - $ch, 2), $w, $h, $cw, $ch);
        $path = "$dir/edited-$slot.jpg";
        if (!@imagejpeg($tile, "$path.new", 96) || !@rename("$path.new", $path)) throw new RuntimeException('Could not save edited slot');
        imagedestroy($tile);
    } finally {
        imagedestroy($image);
    }
    $session['shots'][$slot]['state'] = 'edited';
    multishotSave($dir, $session);
    return multishotStatus($session);
}

function renderMultishot(array $config, string $dir, array $frame): GdImage {
    $sheet = imagecreatetruecolor($frame['width'], $frame['height']);
    imagefill($sheet, 0, 0, 0xffffff);
    foreach ($frame['slots'] as $index => [$x, $y, $w, $h]) {
        $tile = @imagecreatefromjpeg("$dir/edited-$index.jpg");
        if (!$tile) throw new RuntimeException('Could not open edited slot');
        imagecopy($sheet, $tile, $x, $y, 0, 0, $w, $h);
        imagedestroy($tile);
    }
    $overlay = @imagecreatefrompng($dir . '/frame.png');
    if (!$overlay || imagesx($overlay) !== $frame['width'] || imagesy($overlay) !== $frame['height']) {
        throw new RuntimeException('Could not open captured frame');
    }
    imagealphablending($sheet, true);
    imagecopy($sheet, $overlay, 0, 0, 0, 0, $frame['width'], $frame['height']);
    imagedestroy($overlay);
    [$sheet] = addTextToImage($config['textonpicture'], $sheet, true);
    return $sheet;
}

function multishotComplete(array $config, string $dir, array $session): array {
    $file = $session['id'];
    $output = $config['foldersAbs']['images'] . '/' . $file;
    $thumb = $config['foldersAbs']['thumbs'] . '/' . $file;
    if ($session['state'] === 'deleted' || $session['state'] === 'cancelled') {
        throw new MultishotError('Bộ ảnh đã bị xóa hoặc hủy', 410);
    }
    if ($session['state'] === 'complete') {
        if (!is_file($output)) throw new MultishotError('Ảnh ghép đã bị xóa', 410);
        multishotClean($dir, $session, $config['picture']['keep_original']);
        return multishotStatus($session) + ['file' => $file];
    }
    if (array_diff(multishotStatus($session)['shots'], ['edited'])) throw new MultishotError('Chưa chụp đủ ảnh', 409);

    $session['state'] = 'publishing';
    multishotSave($dir, $session);
    if (!is_file($output) || !is_file($thumb)) {
        $sheet = renderMultishot($config, $dir, $session['frame']);
        try {
            $size = (int) $config['picture']['thumb_size'];
            $thumbnail = resizeImage($sheet, $size, $size);
            if (!@imagejpeg($thumbnail, "$dir/thumb.part", $config['jpeg_quality']['thumb'])
                || !@imagejpeg($sheet, "$dir/final.part", $config['jpeg_quality']['image'])
                || !@getimagesize("$dir/thumb.part") || !@getimagesize("$dir/final.part")) {
                throw new RuntimeException('Could not encode sheet and thumbnail');
            }
            imagedestroy($thumbnail);
            // The gallery cannot discover a final JPEG until its thumbnail is ready.
            if ((!is_file($thumb) && !@rename("$dir/thumb.part", $thumb))
                || (!is_file($output) && !@rename("$dir/final.part", $output))) {
                throw new RuntimeException('Could not publish sheet and thumbnail');
            }
        } finally {
            imagedestroy($sheet);
        }
    }
    if (!@chmod($output, octdec($config['picture']['permissions']))) throw new RuntimeException('Could not set photo permissions');
    if ($config['database']['enabled']) appendImageToDB($file);
    $session['state'] = 'complete';
    multishotSave($dir, $session);
    multishotClean($dir, $session, $config['picture']['keep_original']);
    return multishotStatus($session) + ['file' => $file];
}
