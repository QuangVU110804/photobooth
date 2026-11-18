<?php
require_once __DIR__ . '/../lib/multishot-frames.php';

$multi = ($_GET['mode'] ?? null) === 'multi';
$singleDirectory = __DIR__ . '/../resources/img/frames';
$multiDirectory = multishotFramesDirectory();
$directory = $multi ? $multiDirectory : $singleDirectory;
$source = resolvePhotoFrame($directory, $_GET['frame'] ?? null);
if ($multi && $source !== null && !multishotPngSize($source)) $source = null;
if ($source === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Frame is unavailable']);
    exit;
}

try {
    $key = photoFramePreviewKey($source);
    $etag = 'W/"' . $key . '"';
    require_once __DIR__ . '/../lib/config.php';
    $cacheDirectory = $config['foldersAbs']['tmp'] . '/frame-previews';
    cleanPhotoFramePreviews($cacheDirectory, [$singleDirectory, $multiDirectory], $key);
    header('Cache-Control: public, max-age=0, must-revalidate');
    header('ETag: ' . $etag);
    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    $preview = @fopen(photoFramePreview($source, $cacheDirectory), 'rb');
    if ($preview === false) {
        // A concurrent sweep can remove an obsolete cache entry before it is opened.
        clearstatcache();
        $preview = @fopen(photoFramePreview($source, $cacheDirectory), 'rb');
    }
    if ($preview === false) throw new RuntimeException('Could not read frame preview');
    try {
        header('Content-Type: image/png');
        header('Content-Length: ' . fstat($preview)['size']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') fpassthru($preview);
    } finally {
        fclose($preview);
    }
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $error->getMessage()]);
} catch (RuntimeException $error) {
    http_response_code(500);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $error->getMessage()]);
}
