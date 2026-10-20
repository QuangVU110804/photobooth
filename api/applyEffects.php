<?php
header('Content-Type: application/json');

require_once '../lib/face-effects.php';
require_once __DIR__ . '/../lib/skin-beauty.php';
require_once __DIR__ . '/../lib/beauty-upload.php';
require_once __DIR__ . '/../lib/api.php';
require_once __DIR__ . '/../lib/frames.php';

// Validate the complete request before reading captures or creating any edits.
try {
    $faceEffects = parseFaceEffects($_POST['faceEffects'] ?? null);
    $beautyEffects = parseBeautyEffects($_POST['beautyEffects'] ?? null);
    if (isset($_POST['beautyImage']) && (($_POST['style'] ?? null) !== 'photo' || $beautyEffects)) throw new InvalidArgumentException('Invalid GPU beauty capture');
    if ($beautyEffects && ($_POST['style'] ?? null) !== 'photo') throw new InvalidArgumentException('Skin beauty requires a photo capture');
    if (array_key_exists('filter', $_POST) && $_POST['filter'] !== 'plain') {
        throw new InvalidArgumentException('Photo color filters are no longer supported');
    }
    if (!faceEffectFilename($_POST['file'] ?? null)) {
        throw new InvalidArgumentException('Invalid raw capture filename');
    }
    if (!in_array($_POST['style'] ?? null, ['photo', 'chroma'], true)) {
        throw new InvalidArgumentException('Invalid capture style');
    }
    $selectedFrame = null;
    if (array_key_exists('frame', $_POST)) {
        $selectedFrame = resolvePhotoFrame(__DIR__ . '/../resources/img/frames', $_POST['frame']);
        if ($selectedFrame === null) throw new InvalidArgumentException('Invalid selected PNG frame');
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

require_once '../lib/config.php';

try {
    $expectedRawNames = [];
    if (isset($_POST['file'], $_POST['style'])) $expectedRawNames[] = $_POST['file'];
    foreach ($faceEffects as $rawName => $polygons) {
        if (!in_array($rawName, $expectedRawNames, true)) {
            throw new InvalidArgumentException('faceEffects filename is not part of this capture');
        }
    }
    if ($selectedFrame !== null) {
        $frameInfo = @getimagesize($selectedFrame);
        if (!$frameInfo || $frameInfo[2] !== IMAGETYPE_PNG) {
            throw new InvalidArgumentException('Selected frame is not a valid PNG');
        }
        $config['picture']['frame'] = $selectedFrame;
        $config['picture']['take_frame'] = true;
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

require_once '../lib/db.php';
require_once '../lib/polaroid.php';
require_once '../lib/applyFrame.php';
require_once '../lib/resize.php';
require_once '../lib/applyText.php';
require_once '../lib/applyEffects.php';
require_once '../lib/log.php';

if (!extension_loaded('gd')) {
    $errormsg = basename($_SERVER['PHP_SELF']) . ': GD library not loaded! Please enable GD!';
    logErrorAndDie($errormsg);
}

if (empty($_POST['file'])) {
    $errormsg = basename($_SERVER['PHP_SELF']) . ': No file provided';
    logErrorAndDie($errormsg);
}

$file = $_POST['file'];

$quality = 100;
$imageModified = false;

if (!isset($_POST['style'])) {
    $errormsg = basename($_SERVER['PHP_SELF']) . ': No style provided';
    logErrorAndDie($errormsg);
}

$srcImages = [];
$srcImages[] = $file;

$filename_tmp = $config['foldersAbs']['tmp'] . DIRECTORY_SEPARATOR . $file;

try {
    foreach ($srcImages as $image) {
        $filename_photo = $config['foldersAbs']['images'] . DIRECTORY_SEPARATOR . $image;
        $filename_keying = $config['foldersAbs']['keying'] . DIRECTORY_SEPARATOR . $image;
        $filename_tmp = $config['foldersAbs']['tmp'] . DIRECTORY_SEPARATOR . $image;
        $filename_thumb = $config['foldersAbs']['thumbs'] . DIRECTORY_SEPARATOR . $image;

        if (apiPhotoPath($config['foldersAbs']['tmp'], $image) === null) apiError('Raw capture not found', 404);
        $beautyBytes = null;
        if (array_key_exists('beautyImage',$_POST)) {
            try { $beautyBytes = decodeBeautyImage($_POST['beautyImage'],$filename_tmp); }
            catch (InvalidArgumentException $e) { apiError($e->getMessage(),400); }
        }
        $imageResource = $beautyBytes !== null ? @imagecreatefromstring($beautyBytes) : @imagecreatefromjpeg($filename_tmp);
        if (!$imageResource) apiError('Invalid JPEG capture');

        $orientation = skinBeautyJpegOrientation($filename_tmp);
        $rawBeauty = $beautyEffects ? skinBeautyRawOrientation($beautyEffects, $orientation) : [];
        $normalizeOrientation = $beautyBytes === null && !empty($faceEffects[$image]) && $orientation !== 1;
        if ($normalizeOrientation) {
            // Legacy beauty regions address raw pixels; sticker anchors address upright pixels.
            if ($rawBeauty) { applySkinBeauty($imageResource, $rawBeauty); $rawBeauty = []; }
            $imageResource = orientPhotoPixels($imageResource, $orientation);
        }
        list($imageResource, $imageModified) = editSingleImage($config, $imageResource, $faceEffects[$image] ?? [], $rawBeauty);
        if ($beautyBytes !== null || $normalizeOrientation) { $imageModified = true; $config['picture']['orientation_normalized'] = true; }
        unset($beautyBytes);

        if ($config['keying']['enabled'] || $_POST['style'] === 'chroma') {
            $chroma_size = substr($config['keying']['size'], 0, -2);
            $chromaCopyResource = resizeImage($imageResource, $chroma_size, $chroma_size);
            if (!@imagejpeg($chromaCopyResource, $filename_keying, $config['jpeg_quality']['chroma'])) throw new RuntimeException('Could not save chroma image');
            imagedestroy($chromaCopyResource);
        }

        $configText = $config['textonpicture'];
        list($imageResource, $imageModified) = addTextToImage($configText, $imageResource, $imageModified);

        // image scale, create thumbnail
        $thumb_size = substr($config['picture']['thumb_size'], 0, -2);
        $thumbResource = resizeImage($imageResource, $thumb_size, $thumb_size);

        if (!@imagejpeg($thumbResource, $filename_thumb, $config['jpeg_quality']['thumb'])) throw new RuntimeException('Could not save thumbnail');
        imagedestroy($thumbResource);

        compressImage($config, $imageModified, $imageResource, $filename_tmp, $filename_photo);
        clearPhotoCaches($image);

        imagedestroy($imageResource);

        // insert into database
        if ($config['database']['enabled']) {
            if ($_POST['style'] !== 'chroma' || ($_POST['style'] === 'chroma' && $config['live_keying']['show_all'] === true)) {
                appendImageToDB($image);
            }
        }

        // Change permissions
        $picture_permissions = $config['picture']['permissions'];
        chmod($filename_photo, octdec($picture_permissions));
        if (!$config['picture']['keep_original']) unlink($filename_tmp);
    }

    if ($_POST['style'] === 'chroma' && $config['live_keying']['show_all'] === false) {
        unlink($filename_photo);
        unlink($filename_thumb);
    }
} catch (RuntimeException $error) {
    apiError($error->getMessage(), 500);
}

$LogData = [
    'file' => $file,
    'images' => $srcImages,
    'php' => basename($_SERVER['PHP_SELF']),
];
$LogString = json_encode($LogData);
if ($config['dev']['loglevel'] > 1) {
    logError($LogData);
}
echo $LogString;
