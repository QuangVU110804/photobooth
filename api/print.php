<?php
header('Content-Type: application/json');

require_once '../lib/config.php';
require_once '../lib/db.php';
require_once '../lib/resize.php';
require_once '../lib/applyFrame.php';
require_once '../lib/applyText.php';
require_once '../lib/log.php';
require_once __DIR__ . '/../lib/photo-receipt.php';
require_once __DIR__ . '/../lib/api.php';

function applyQR($sourceResource, $qrPath, $x, $y) {
    $qr = imagecreatefrompng($qrPath);
    imagecopy($sourceResource, $qr, $x, $y, 0, 0, imagesx($qr), imagesy($qr));
    return $sourceResource;
}

function savePrintJpeg($image, string $path, int $quality): void {
    if (!@imagejpeg($image, $path, $quality)) apiError('Could not render print', 500);
}

$filename = requirePhotoFilename($_GET['filename'] ?? null);
$filename_source = apiPhotoPath($config['foldersAbs']['images'], $filename);
if ($filename_source === null) apiError('Photo not found', 404);
if (!is_string($config['print']['cmd']) || trim($config['print']['cmd']) === '') apiError('Print command is not configured', 503);
$filename_print = $config['foldersAbs']['print'] . DIRECTORY_SEPARATOR . $filename;
$filename_codes = $config['foldersAbs']['qrcodes'] . DIRECTORY_SEPARATOR . $filename;
$receiptPrintQr = $config['print']['qrcode'] && file_exists(__DIR__ . '/../vendor/phpqrcode/lib/full/qrlib.php');
if ($receiptPrintQr) {
    if (photoReceiptFilename($filename) === null) {
        logErrorAndDie('Invalid photo filename');
    }
    try {
        $receiptUrl = photoReceiptUrl($filename);
    } catch (RuntimeException $error) {
        logErrorAndDie('Photo receipt URL is not configured correctly');
    }
    // Do not reuse a printed image or QR containing the old static-image destination.
    $receiptCacheKey = hash('sha256', $receiptUrl);
    $filename_print = $config['foldersAbs']['print'] . DIRECTORY_SEPARATOR . 'receipt-' . $receiptCacheKey . '.jpg';
    $filename_codes = $config['foldersAbs']['qrcodes'] . DIRECTORY_SEPARATOR . 'print-receipt-' . $receiptCacheKey . '.png';
}
$quality = 100;
$status = false;

// exit with error if file does not exist
if (!file_exists($filename_source)) {
    $errormsg = "File $filename not found";
    logErrorAndDie($errormsg);
}

// Only jpg/jpeg are supported
$imginfo = @getimagesize($filename_source);
if (!$imginfo) apiError('Invalid source photo');
$mimetype = $imginfo['mime'];
if ($mimetype != 'image/jpg' && $mimetype != 'image/jpeg') {
    $errormsg = basename($_SERVER['PHP_SELF']) . ': The source file type ' . $mimetype . ' is not supported';
    logErrorAndDie($errormsg);
}

// text on print variables
$fontpath = $config['textonprint']['font'];
$fontcolor = $config['textonprint']['font_color'];
$fontsize = $config['textonprint']['font_size'];
$fontlocx = $config['textonprint']['locationx'];
$fontlocy = $config['textonprint']['locationy'];
$linespacing = $config['textonprint']['linespace'];
$fontrot = $config['textonprint']['rotation'];
$line1text = $config['textonprint']['line1'];
$line2text = $config['textonprint']['line2'];
$line3text = $config['textonprint']['line3'];

if (!file_exists($filename_print)) {
    $printCache = $filename_print;
    if (!is_dir(dirname($printCache)) || !is_writable(dirname($printCache))) apiError('Print directory is unavailable', 500);
    $filename_print = @tempnam(dirname($printCache), 'print-');
    if ($filename_print === false) apiError('Could not create print file', 500);
    // Failed renderings must never become reusable print-cache entries.
    register_shutdown_function(function () use ($filename_print) { if (is_file($filename_print)) @unlink($filename_print); });
    // rotate image if needed
    list($width, $height) = getimagesize($filename_source);
    if ($width > $height || $config['print']['no_rotate'] === true) {
        $image = imagecreatefromjpeg($filename_source);
        savePrintJpeg($image, $filename_print, $quality);
        imagedestroy($image);
        $rotateQr = false;
    } else {
        $image = imagecreatefromjpeg($filename_source);
        $resultRotated = imagerotate($image, 90, 0);
        savePrintJpeg($resultRotated, $filename_print, $quality);
        imagedestroy($image);
        $rotateQr = true;
        // re-define width & height after rotation
        list($width, $height) = getimagesize($filename_print);
    }

    $source = imagecreatefromjpeg($filename_print);

    if ($receiptPrintQr) {
        // create qr code
        if (!file_exists($filename_codes)) {
            $url = $receiptUrl;
            include '../vendor/phpqrcode/lib/full/qrlib.php';
            switch ($config['qr']['ecLevel']) {
                case 'QR_ECLEVEL_L':
                    $ecLevel = QR_ECLEVEL_L;
                    break;
                case 'QR_ECLEVEL_M':
                    $ecLevel = QR_ECLEVEL_M;
                    break;
                case 'QR_ECLEVEL_Q':
                    $ecLevel = QR_ECLEVEL_Q;
                    break;
                case 'QR_ECLEVEL_H':
                    $ecLevel = QR_ECLEVEL_H;
                    break;
                default:
                    $ecLevel = QR_ECLEVEL_M;
                    break;
            }
            writePhotoReceiptQr($url, $filename_codes, $ecLevel, $config['print']['qrSize'], $config['print']['qrMargin']);
            if ($rotateQr) {
                $image = imagecreatefrompng($filename_codes);
                $resultRotated = imagerotate($image, 90, 0);
                imagepng($resultRotated, $filename_codes, 0);
                imagedestroy($image);
            }
            if ($config['print']['qrBgColor'] != '#ffffff') {
                $tocolor = imagecreatefrompng($filename_codes);
                $qrwidth = imagesx($tocolor);
                $qrheight = imagesy($tocolor);
                list($r, $g, $b) = sscanf($config['print']['qrBgColor'], '#%02x%02x%02x');
                $selected = imagecolorallocate($tocolor, $r, $g, $b);

                for ($xpos = 0; $xpos < $qrwidth; $xpos++) {
                    for ($ypos = 0; $ypos < $qrheight; $ypos++) {
                        $currentcolor = imagecolorat($tocolor, $xpos, $ypos);
                        $parts = imagecolorsforindex($tocolor, $currentcolor);

                        if ($parts['red'] == 255 && $parts['green'] == 255 && $parts['blue'] == 255) {
                            imagesetpixel($tocolor, $xpos, $ypos, $selected);
                        }
                    }
                }
                imagepng($tocolor, $filename_codes, 0);
            }
        }

        if ($config['print']['print_frame'] && testFile($config['print']['frame'])) {
            $source = applyFrame($source, $config['print']['frame']);
        }

        list($qrWidth, $qrHeight) = getimagesize($filename_codes);

        if (is_numeric($config['print']['qrOffset'])) {
            $offset = $config['print']['qrOffset'];
        } else {
            $offset = 10;
        }

        switch ($config['print']['qrPosition']) {
            case 'topLeft':
                $x = $offset;
                $y = $offset;
                break;
            case 'top':
                $x = ($width - $qrWidth) / 2;
                $y = $offset;
                break;
            case 'topRight':
                $x = $width - ($qrWidth + $offset);
                $y = $offset;
                break;
            case 'right':
                $x = $width - $qrWidth - $offset;
                $y = ($height - $qrHeight) / 2;
                break;
            case 'bottomRight':
                $x = $width - ($qrWidth + $offset);
                $y = $height - ($qrHeight + $offset);
                break;
            case 'bottom':
                $x = ($width - $qrWidth) / 2;
                $y = $height - $qrHeight - $offset;
                break;
            case 'bottomLeft':
                $x = $offset;
                $y = $height - ($qrHeight + $offset);
                break;
            case 'left':
                $x = $offset;
                $y = ($height - $qrHeight) / 2;
                break;
            default:
                $x = $width - ($qrWidth + $offset);
                $y = $height - ($qrHeight + $offset);
                break;
        }
        $source = applyQR($source, $filename_codes, $x, $y);
    } else {
        if ($config['print']['print_frame'] && testFile($config['print']['frame'])) {
            $source = applyFrame($source, $config['print']['frame']);
        }
    }

    if ($config['textonprint']['enabled'] && testFile($config['textonprint']['font'])) {
        $source = applyText($source, $fontsize, $fontrot, $fontlocx, $fontlocy, $fontcolor, $fontpath, $line1text, $line2text, $line3text, $linespacing);
    }

    if ($config['print']['crop']) {
        $crop_width = $config['print']['crop_width'];
        $crop_height = $config['print']['crop_height'];
        $source = resizeCropImage($crop_width, $crop_height, $source);
    }

    savePrintJpeg($source, $filename_print, $quality);
    imagedestroy($source);
    if (!@rename($filename_print, $printCache)) apiError('Could not save rendered print', 500);
    $filename_print = $printCache;
}

// print image
$cmd = sprintf($config['print']['cmd'], escapeshellarg($filename_print));
$cmd .= ' 2>&1'; //Redirect stderr to stdout, otherwise error messages get lost.

exec($cmd, $output, $returnValue);
if ($returnValue !== 0) apiError('Print command failed', 500);

$LogData = [
    'status' => 'ok',
    'msg' => $cmd,
    'returnValue' => $returnValue,
    'output' => $output,
    'php' => basename($_SERVER['PHP_SELF']),
];
$LogString = json_encode($LogData);
if ($config['dev']['loglevel'] > 1) {
    logError($LogData);
}
die($LogString);
