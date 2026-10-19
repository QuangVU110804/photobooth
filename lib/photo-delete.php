<?php
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/db.php';

function deletePhotoFiles(string $file): array {
    global $config;
    $folders = ['images', 'thumbs', 'keying'];
    if (!$config['picture']['keep_original']) $folders[] = 'tmp';
    $result = ['success' => true, 'file' => $file, 'unavailable' => [], 'failed' => []];
    foreach ($folders as $folder) {
        $path = $config['foldersAbs'][$folder] . DIRECTORY_SEPARATOR . $file;
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            $result['failed'][] = $path;
        } elseif (is_file($path)) {
            if (!@unlink($path)) $result['failed'][] = $path;
        } else {
            $result['unavailable'][] = $path;
        }
    }
    try {
        if (!$result['failed']) {
            clearPhotoCaches($file);
            if ($config['database']['enabled']) deleteImageFromDB($file);
        }
    } catch (RuntimeException $error) {
        $result['failed'][] = $error->getMessage();
    }
    $result['success'] = !$result['failed'];
    if (!$result['success']) $result['error'] = 'Could not delete all photo files';
    return $result;
}
