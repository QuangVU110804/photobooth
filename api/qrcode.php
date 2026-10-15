<?php
error_reporting(0);
ini_set("display_errors", "0");

require_once __DIR__ . "/../lib/config.php";
require_once __DIR__ . "/../lib/photo-receipt.php";

$filename = photoReceiptFilename($_GET["filename"] ?? null);
if ($filename === null) {
    http_response_code(400);
    exit("Invalid photo filename");
}

// The URL-based cache key also separates new receipt QR codes from legacy image QR codes.
try {
    $url = photoReceiptUrl($filename);
} catch (RuntimeException $error) {
    http_response_code(500);
    exit("Photo receipt URL is not configured correctly");
}

include_once __DIR__ . "/../vendor/phpqrcode/lib/full/qrlib.php";

$cacheDir = $config['foldersAbs']['qrcodes'] . DIRECTORY_SEPARATOR;
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}

$qrCacheFile = $cacheDir . md5($url) . ".png";

if (!file_exists($qrCacheFile) || filesize($qrCacheFile) === 0) {
    ob_start();
    writePhotoReceiptQr($url, $qrCacheFile, QR_ECLEVEL_M, 8, 2);
    ob_end_clean();
}

while (ob_get_level()) {
    ob_end_clean();
}

if (file_exists($qrCacheFile) && filesize($qrCacheFile) > 0) {
    header("Content-Type: image/png");
    header("Content-Length: " . filesize($qrCacheFile));
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    readfile($qrCacheFile);
    exit;
}

http_response_code(500);
echo "Error generating QR";
