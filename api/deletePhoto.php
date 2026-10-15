<?php
header('Content-Type: application/json');

require_once '../lib/db.php';
require_once '../lib/config.php';
require_once '../lib/log.php';
require_once __DIR__ . '/../lib/api.php';

$file = requirePhotoFilename($_POST['file'] ?? null);
if (!$config['picture']['allow_delete'] && !$config['gallery']['allow_delete']) apiError('Photo deletion is disabled', 403);

if (empty($_POST['file'])) {
    $errormsg = basename($_SERVER['PHP_SELF']) . ': No file provided';
    logErrorAndDie($errormsg);
}

$images = [];
$unavailableImages = [];
$failedImages = [];
$success = true;
$images = [
    $config['foldersAbs']['images'] . DIRECTORY_SEPARATOR . $file,
    $config['foldersAbs']['thumbs'] . DIRECTORY_SEPARATOR . $file,
    $config['foldersAbs']['keying'] . DIRECTORY_SEPARATOR . $file,
];

if (!$config['picture']['keep_original']) {
    $images[] = $config['foldersAbs']['tmp'] . DIRECTORY_SEPARATOR . $file;
}

foreach ($images as $image) {
    if (is_link($image)) { $success = false; $failedImages[] = $image; continue; }
    if (is_readable($image)) {
        if (!unlink($image)) {
            $errormsg = basename($_SERVER['PHP_SELF']) . ': Could not delete ' . $image;
            logError($errormsg);
            $success = false;
            $failedImages[] = $image;
        }
    } else {
        $unavailableImages[] = $image;
    }
}

if ($success) {
    try { clearPhotoCaches($file); } catch (RuntimeException $error) { $success = false; }
}
if ($success && $config['database']['enabled']) {
    try { deleteImageFromDB($file); }
    catch (RuntimeException $error) { apiError($error->getMessage(), 500); }
}
if (!$success) http_response_code(500);

$LogData = [
    'success' => $success,
    'file' => $file,
    'unavailable' => $unavailableImages,
    'failed' => $failedImages,
];
if (!$success) $LogData['error'] = 'Could not delete all photo files';
$LogString = json_encode($LogData);
if (!$success || $config['dev']['loglevel'] > 1) {
    logError($LogData);
}
echo $LogString;
