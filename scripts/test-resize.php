<?php
/** Exercise GD resizing independently so native failures have a clear origin. */
require dirname(__DIR__) . '/lib/resize.php';
$checks = 0;
foreach ([[120, 80, 120, 120, 120, 80], [180, 120, 120, 120, 120, 80], [60, 40, 120, 120, 120, 80]] as [$width, $height, $maxWidth, $maxHeight, $wantWidth, $wantHeight]) {
    echo "Resize {$width}x{$height} within {$maxWidth}x{$maxHeight}\n";
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 40, 100, 180));
    $output = resizeImage($image, $maxWidth, $maxHeight);
    if (imagesx($output) !== $wantWidth || imagesy($output) !== $wantHeight) throw new RuntimeException('Resize returned incorrect dimensions');
    $checks++;
    if ($output === $image) throw new RuntimeException('Resize must return an independently owned image');
    imagedestroy($output);
    if (imagecolorat($image, 0, 0) !== 0x2864b4) throw new RuntimeException('Resize changed its source pixels');
    $checks++;
    imagedestroy($image);
}
echo "$checks resize checks passed\n";
