<?php
header('Content-Type: application/json');

require_once '../lib/config.php';
require_once '../lib/db.php';
require_once '../lib/log.php';
require_once __DIR__ . '/../lib/api.php';
require_once __DIR__ . '/../lib/photo-delete.php';

$batch = array_key_exists('files', $_POST);
if ($batch) {
    if (isset($_POST['file']) || !is_array($_POST['files']) || count($_POST['files']) < 1 || count($_POST['files']) > 100) {
        apiError('Provide between 1 and 100 photo filenames');
    }
    // Validate the entire request before making any filesystem changes.
    $files = array_values(array_unique(array_map('requirePhotoFilename', $_POST['files'])));
    if (!$config['gallery']['allow_delete']) apiError('Gallery photo deletion is disabled', 403);
} else {
    $files = [requirePhotoFilename($_POST['file'] ?? null)];
    if (!$config['picture']['allow_delete'] && !$config['gallery']['allow_delete']) apiError('Photo deletion is disabled', 403);
}

$deleted = [];
$failed = [];
$results = [];
foreach ($files as $file) {
    $result = deletePhotoFiles($file);
    $results[] = $result;
    if ($result['success']) $deleted[] = $file;
    else $failed[] = $file;
}
$data = $batch ? ['success' => !$failed, 'deleted' => $deleted, 'failed' => $failed, 'results' => $results] : $results[0];
if ($failed) {
    http_response_code(500);
    $data['error'] = 'Could not delete all photo files';
}
if ($failed || $config['dev']['loglevel'] > 1) logError($data);
echo json_encode($data);
