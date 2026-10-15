<?php
require_once __DIR__ . '/config.php';

define('DB_FILE', $config['foldersAbs']['data'] . DIRECTORY_SEPARATOR . $config['database']['file'] . '.txt');
define('MAIL_FILE', $config['foldersAbs']['data'] . DIRECTORY_SEPARATOR . $config['mail']['file'] . '.txt');
define('IMG_DIR', $config['foldersAbs']['images']);

function getImagesFromDB() {
    if (!is_file(DB_FILE)) return [];
    $handle = @fopen(DB_FILE, 'rb');
    if (!$handle) return [];
    try {
        if (!flock($handle, LOCK_SH)) throw new RuntimeException('Could not lock image database');
        return decodeImageDB(stream_get_contents($handle));
    } finally { fclose($handle); }
}

function decodeImageDB(string $contents): array {
    $images = json_decode($contents, true);
    if (!is_array($images) || array_values($images) !== $images) return [];
    return array_values(array_filter($images, function ($name) { return is_string($name) && $name !== ''; }));
}

/** Hold one lock across the complete read/modify/write transaction. */
function updateImageDB(callable $update): void {
    $handle = @fopen(DB_FILE, 'c+b');
    if (!$handle) throw new RuntimeException('Could not open image database');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Could not lock image database');
        $images = $update(decodeImageDB(stream_get_contents($handle)));
        $encoded = json_encode(array_values($images), JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
            throw new RuntimeException('Could not write image database');
        }
    } finally { fclose($handle); }
}

function getImagesFromDirectory($directory) {
    $files = @scandir($directory) ?: [];
    return array_values(array_filter($files, function ($name) use ($directory) {
        return preg_match('/\.(jpg|jpeg)$/i', $name) && is_file($directory . DIRECTORY_SEPARATOR . $name);
    }));
}

function appendImageToDB($filename) {
    updateImageDB(function ($images) use ($filename) {
        if (!in_array($filename, $images, true)) $images[] = $filename;
        return $images;
    });
}

function deleteImageFromDB($filename) {
    updateImageDB(function ($images) use ($filename) {
        return array_filter($images, function ($image) use ($filename) { return $image !== $filename; });
    });
}

/** Serialize email subscriptions just like photo database updates. */
function appendMailAddressToDB(string $address): void {
    $handle = @fopen(MAIL_FILE, 'c+b');
    if (!$handle) throw new RuntimeException('Could not open email database');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Could not lock email database');
        $addresses = json_decode(stream_get_contents($handle), true);
        if (!is_array($addresses)) $addresses = [];
        if (!in_array($address, $addresses, true)) $addresses[] = $address;
        $encoded = json_encode(array_values($addresses), JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
            throw new RuntimeException('Could not write email database');
        }
    } finally { fclose($handle); }
}

function isImageInDB($filename) {
    $images = getImagesFromDB();

    return in_array($filename, $images, true);
}

function getDBSize() {
    if (file_exists(DB_FILE)) {
        return (int) filesize(DB_FILE);
    }
    return 0;
}

function rebuildPictureDB() {
    try {
        // Scan under the same lock as append/delete, and only index actual photos.
        updateImageDB(function () {
            $output = [];
            foreach (getImagesFromDirectory(IMG_DIR) as $name) {
                $path = IMG_DIR . DIRECTORY_SEPARATOR . $name;
                if (is_link($path) || !@getimagesize($path)) continue;
                $output[] = [filemtime($path), $name];
            }
            usort($output, function ($a, $b) { return ($a[0] <=> $b[0]) ?: strcmp($a[1], $b[1]); });
            return array_column($output, 1);
        });
        echo json_encode('success');
    } catch (RuntimeException $error) {
        http_response_code(500);
        echo json_encode('error');
    }
}
