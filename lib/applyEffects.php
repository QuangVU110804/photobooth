<?php

require_once __DIR__ . '/face-effects.php';
require_once __DIR__ . '/skin-beauty.php';

/** Match browser-decoded landmark coordinates without changing the retained JPEG. */
function orientPhotoPixels(GdImage $image, int $orientation): GdImage {
    if (in_array($orientation, [2, 5, 7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
    elseif ($orientation === 4) imageflip($image, IMG_FLIP_VERTICAL);
    $angle = [3=>180, 5=>90, 6=>-90, 7=>-90, 8=>90][$orientation] ?? 0;
    if ($angle !== 0) {
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) throw new RuntimeException('Could not normalize photo orientation');
        imagedestroy($image);
        return $rotated;
    }
    return $image;
}

function editSingleImage($config, $imageResource, $image_filter, array $polygons = [], array $beauty = []) {
    $imageModified = false;
    // Only jpg/jpeg are supported
    if (!$imageResource) {
        $errormsg = basename($_SERVER['PHP_SELF']) . ': Could not read jpeg file. Are you taking raws?';
        logErrorAndDie($errormsg);
    }

    if ($beauty) $imageModified = applySkinBeauty($imageResource, $beauty);

    if ($polygons) {
        applyFaceEffects($imageResource, $polygons);
        $imageModified = true;
    }

    if ($config['picture']['flip'] !== 'off') {
        if ($config['picture']['flip'] === 'horizontal') {
            imageflip($imageResource, IMG_FLIP_HORIZONTAL);
        } elseif ($config['picture']['flip'] === 'vertical') {
            imageflip($imageResource, IMG_FLIP_VERTICAL);
        } elseif ($config['picture']['flip'] === 'both') {
            imageflip($imageResource, IMG_FLIP_BOTH);
        }
        $imageModified = true;
    }

    // apply filter
    if ($image_filter) {
        applyFilter($image_filter, $imageResource);
        $imageModified = true;
    }

    if ($config['picture']['polaroid_effect']) {
        $polaroid_rotation = $config['picture']['polaroid_rotation'];
        $imageResource = effectPolaroid($imageResource, $polaroid_rotation, 200, 200, 200);
        $imageModified = true;
    }

    if ($config['picture']['take_frame'] && testFile($config['picture']['frame'])) {
        $imageResource = applyFrame($imageResource, $config['picture']['frame']);
        $imageModified = true;
    }

    if ($config['picture']['rotation'] !== '0') {
        $imageResource = rotateResizeImage($imageResource, $config['picture']['rotation']);
        $imageModified = true;
    }
    return [$imageResource, $imageModified];
}

function addTextToImage($configText, $imageResource, $imageModified) {
    $fontpath = $configText['font'];
    if ($configText['enabled'] && testFile($fontpath)) {
        $fontcolor = $configText['font_color'];
        $fontsize = $configText['font_size'];
        $fontlocx = $configText['locationx'];
        $fontlocy = $configText['locationy'];
        $linespacing = $configText['linespace'];
        $fontrot = $configText['rotation'];
        $line1text = $configText['line1'];
        $line2text = $configText['line2'];
        $line3text = $configText['line3'];
        $imageResource = applyText($imageResource, $fontsize, $fontrot, $fontlocx, $fontlocy, $fontcolor, $fontpath, $line1text, $line2text, $line3text, $linespacing);
        $imageModified = true;
    }
    return [$imageResource, $imageModified];
}

function compressImage($config, $imageModified, $imageResource, $filename_tmp, $filename_photo) {
    if ($imageModified || ($config['jpeg_quality']['image'] >= 0 && $config['jpeg_quality']['image'] < 100)) {
        if (!@imagejpeg($imageResource, $filename_photo, $config['jpeg_quality']['image'])) {
            throw new RuntimeException('Could not save processed photo');
        }
        // preserve jpeg meta data
        if ($config['picture']['preserve_exif_data'] && $config['exiftool']['cmd']) {
            $cmd = sprintf($config['exiftool']['cmd'], escapeshellarg($filename_tmp), escapeshellarg($filename_photo));
            if (!empty($config['picture']['orientation_normalized'])) $cmd .= ' -Orientation=1 -n';
            $cmd .= ' 2>&1'; //Redirect stderr to stdout, otherwise error messages get lost.

            exec($cmd, $output, $returnValue);

            if ($returnValue) {
                $ErrorData = [
                    'error' => 'exiftool returned with an error code',
                    'cmd' => $cmd,
                    'returnValue' => $returnValue,
                    'output' => $output,
                ];
                $ErrorString = json_encode($ErrorData);
                logError($ErrorData);
                die($ErrorString);
            }
        }
    } else {
        if (!@copy($filename_tmp, $filename_photo)) throw new RuntimeException('Could not save processed photo');
    }
}
