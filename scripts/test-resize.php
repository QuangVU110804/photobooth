<?php
/** Exercise GD resizing independently so native failures have a clear origin. */
require dirname(__DIR__) . '/lib/resize.php';
require dirname(__DIR__) . '/lib/filter.php';
require dirname(__DIR__) . '/lib/face-effects.php';
$checks = 0;
foreach (['memory', 'edited-jpeg'] as $variant) {
foreach ([[120, 80, 120, 120, 120, 80], [180, 120, 120, 120, 120, 80], [60, 40, 120, 120, 120, 80]] as [$width, $height, $maxWidth, $maxHeight, $wantWidth, $wantHeight]) {
    echo "Resize $variant {$width}x{$height} within {$maxWidth}x{$maxHeight}\n";
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 40, 100, 180));
    if ($variant === 'edited-jpeg') {
        ob_start(); imagejpeg($image); $jpeg = ob_get_clean();
        imagedestroy($image);
        $image = imagecreatefromstring($jpeg);
        applyFaceEffects($image, [['points' => [[.05,.2],[.35,.2],[.35,.8],[.05,.8]], 'color' => 'ink']]);
        imageflip($image, IMG_FLIP_HORIZONTAL);
        applyFilter('cssgram-reyes', $image);
    }
    $sourcePixel = imagecolorat($image, 0, 0);
    $output = resizeImage($image, $maxWidth, $maxHeight);
    if (imagesx($output) !== $wantWidth || imagesy($output) !== $wantHeight) throw new RuntimeException('Resize returned incorrect dimensions');
    $checks++;
    if ($output === $image) throw new RuntimeException('Resize must return an independently owned image');
    imagedestroy($output);
    if (imagecolorat($image, 0, 0) !== $sourcePixel) throw new RuntimeException('Resize changed its source pixels');
    $checks++;
    imagedestroy($image);
}
}
$image = imagecreatetruecolor(64, 64);
imagealphablending($image, false);
imagefill($image, 0, 0, imagecolorallocatealpha($image, 40, 100, 180, 63));
$output = resizeImage($image, 32, 32);
$color = imagecolorsforindex($output, imagecolorat($output, 16, 16));
if ([$color['red'], $color['green'], $color['blue'], $color['alpha']] !== [40, 100, 180, 63]) throw new RuntimeException('Resize did not preserve RGBA');
$checks++;
imagedestroy($output); imagedestroy($image);
echo "$checks resize checks passed\n";
