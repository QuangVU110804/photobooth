<?php
require_once __DIR__ . '/../lib/frames.php';

$source = resolvePhotoFrame(__DIR__ . '/../resources/img/frames', $_GET['frame'] ?? null);
if ($source === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Frame is unavailable']);
    exit;
}

try {
    $etag = 'W/"' . photoFramePreviewKey($source) . '"';
    header('Cache-Control: public, max-age=0, must-revalidate');
    header('ETag: ' . $etag);
    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    require_once __DIR__ . '/../lib/config.php';
    $preview = photoFramePreview($source, $config['foldersAbs']['tmp'] . '/frame-previews');
    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($preview));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($preview);
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
