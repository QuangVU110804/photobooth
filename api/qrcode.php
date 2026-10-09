<?php
error_reporting(0);
ini_set("display_errors", "0");

require_once "../lib/config.php";
require_once "../lib/helper.php";

$rawFilename = isset($_GET["filename"]) ? $_GET["filename"] : "default.jpg";
$filename = basename($rawFilename);

// URL tuyet doi den thu muc anh tren Tailscale Funnel
$url = "https://fidk0125d1s0006.tail8a1993.ts.net/photobooth/data/images/" . $filename;

include_once "../vendor/phpqrcode/lib/full/qrlib.php";

$cacheDir = __DIR__ . "/../data/qrcodes/";
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}

$qrCacheFile = $cacheDir . md5($url) . ".png";

if (!file_exists($qrCacheFile) || filesize($qrCacheFile) === 0) {
    ob_start();
    QRcode::png($url, $qrCacheFile, QR_ECLEVEL_M, 8, 2);
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
