<?php
header('Content-Type: application/json');

require_once '../../lib/config.php';
require_once '../../lib/db.php';
require_once '../../lib/resize.php';
require_once __DIR__ . '/../../lib/capture.php';

try {
    $data = decodeCameraImage($_POST['imgData'] ?? null, 'image/png');
    $file = newCaptureFilename($config);
} catch (InvalidArgumentException $error) { apiError($error->getMessage()); }

$filename_photo = $config['foldersAbs']['images'] . DIRECTORY_SEPARATOR . $file;
$filename_thumb = $config['foldersAbs']['thumbs'] . DIRECTORY_SEPARATOR . $file;
$filename_keying = $config['foldersAbs']['keying'] . DIRECTORY_SEPARATOR . $file;
$picture_permissions = $config['picture']['permissions'];
$thumb_size = substr($config['picture']['thumb_size'], 0, -2);

$imageResource = imagecreatefromstring($data);
if (!$imageResource) apiError('Invalid camera image');
if (!@imagejpeg($imageResource, $filename_photo, $config['jpeg_quality']['image'])) apiError('Could not save photo', 500);
if (!@copy($filename_photo, $filename_keying)) apiError('Could not save chroma image', 500);

// image scale, create thumbnail
$thumbResource = resizeImage($imageResource, $thumb_size, $thumb_size);
if (!@imagejpeg($thumbResource, $filename_thumb, $config['jpeg_quality']['thumb'])) apiError('Could not save thumbnail', 500);
imagedestroy($thumbResource);

imagedestroy($imageResource);

// insert into database
if ($config['database']['enabled']) {
    try { appendImageToDB($file); } catch (RuntimeException $error) { apiError($error->getMessage(), 500); }
}

// Change permissions
chmod($filename_photo, octdec($picture_permissions));

// send imagename to frontend
echo json_encode([
    'success' => true,
    'filename' => $file,
]);
