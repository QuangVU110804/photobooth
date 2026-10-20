<?php
/** Shared only by the photo receipt and its screen/print QR producers. */
function photoReceiptFilename($value): ?string {
    if (!is_string($value) || $value === '' || strlen($value) > 255 || $value[0] === '.' ||
        preg_match('/[\x00-\x1f\x7f\\\\\/<>:"|?*]/', $value) ||
        !preg_match('/\.(jpe?g|png|webp)$/i', $value)) {
        return null;
    }
    return $value;
}

function photoReceiptUrl(string $filename): string {
    if (photoReceiptFilename($filename) === null) {
        throw new InvalidArgumentException('Invalid photo filename');
    }
    return photoReceiptBaseUrl() . '/photo.php?image=' . rawurlencode($filename);
}

function photoReceiptBaseUrl(): string {
    global $config;
    // Include the installation subfolder when overriding this public base URL.
    $base = getenv('PHOTOBOOTH_PUBLIC_URL');
    if ($base === false || $base === '') {
        $localSettings = __DIR__ . '/../config/photo-receipt.local.php';
        if (!empty($config['webserver']['public_url'])) {
            $base = $config['webserver']['public_url'];
        } elseif (is_file($localSettings)) {
            $base = require $localSettings;
        } elseif (function_exists('getPhotoboothUrl')) {
            $base = getPhotoboothUrl();
        } else {
            throw new RuntimeException('Photo receipt installation URL is unavailable');
        }
    }
    if (!is_string($base)) throw new RuntimeException('Photo receipt installation URL must be a string');
    $parts = parse_url($base);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) ||
        empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
        isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('PHOTOBOOTH_PUBLIC_URL must be an absolute HTTP(S) installation URL');
    }
    return rtrim($base, '/');
}

function findPhotoReceiptImage(string $directory, string $filename): ?array {
    if (photoReceiptFilename($filename) === null) {
        return null;
    }
    $root = realpath($directory);
    $path = realpath($directory . DIRECTORY_SEPARATOR . $filename);
    if ($root === false || $path === false || dirname($path) !== $root || !is_file($path) || !is_readable($path)) {
        return null;
    }
    $size = @getimagesize($path);
    if (!$size || !in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return null;
    }
    return ['path' => $path, 'width' => $size[0], 'height' => $size[1], 'mime' => $size['mime'], 'bytes' => filesize($path), 'modified' => filemtime($path)];
}

function writePhotoReceiptQr(string $url, string $path, $level, $size, $margin): void {
    if (QR_CACHEABLE && !is_dir(QR_CACHE_DIR) && !@mkdir(QR_CACHE_DIR, 0755, true) && !is_dir(QR_CACHE_DIR)) {
        throw new RuntimeException('QR mask cache is not writable');
    }
    // This bundled QR library predates PHP 8.2's implicit float-to-int deprecations.
    // Keep errors/warnings visible and restore the caller's reporting level afterwards.
    $reporting = error_reporting();
    error_reporting($reporting & ~E_DEPRECATED);
    try {
        QRcode::png($url, $path, $level, $size, $margin);
    } finally {
        error_reporting($reporting);
    }
}
