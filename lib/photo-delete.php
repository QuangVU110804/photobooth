<?php
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/multishot.php';

function deletePhotoFiles(string $file): array {
    global $config;
    if (!multishotDirectory($config, $file)) return deletePhotoArtifacts($file);
    try {
        return multishotUse($config, $file, null, function ($dir, $session) use ($config, $file) {
            multishotForget($dir, $session, 'deleted', $config['picture']['keep_original']);
            return deletePhotoArtifacts($file);
        });
    } catch (RuntimeException $error) {
        return ['success' => false, 'file' => $file, 'unavailable' => [], 'failed' => [$error->getMessage()], 'error' => 'Could not delete all photo files'];
    }
}

function deletePhotoArtifacts(string $file): array {
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
