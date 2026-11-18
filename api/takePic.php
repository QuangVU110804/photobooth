<?php
header('Content-Type: application/json');

// Reject removed/unknown modes before loading config or renaming a capture.
if (!isset($_POST['style']) || !in_array($_POST['style'], ['photo', 'chroma'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid capture style']);
    exit;
}

require_once '../lib/config.php';
require_once '../lib/db.php';
require_once '../lib/log.php';
require_once __DIR__ . '/../lib/capture.php';
require_once __DIR__ . '/../lib/face-effects.php';

// Decode and validate before an existing raw capture can be renamed/replaced.
if (isset($_POST['file']) && !faceEffectFilename($_POST['file'])) apiError('Invalid capture filename');
$cameraBytes = null;
$captureSource = $_POST['captureSource'] ?? (($config['preview']['mode'] === 'device_cam' && $config['preview']['camTakesPic']) ? 'browser' : 'external');
if (!in_array($captureSource, ['browser', 'external'], true)) apiError('Invalid capture source');
if (!$config['dev']['demo_images'] && $captureSource === 'browser') {
    try { $cameraBytes = decodeCameraImage($_POST['canvasimg'] ?? null, 'image/jpeg'); }
    catch (InvalidArgumentException $error) { apiError($error->getMessage()); }
}

function takePicture($filename) {
    global $config, $cameraBytes, $captureSource;
    try {
        saveCameraCapture($config, $filename, $captureSource, $cameraBytes);
    } catch (CameraCaptureError $error) {
        if ($error->details) die(json_encode($error->details));
        apiError($error->getMessage(), $error->getCode());
    }
}

try { $file = $_POST['file'] ?? newCaptureFilename($config); }
catch (InvalidArgumentException $error) { apiError($error->getMessage()); }
$random = bin2hex(random_bytes(16)) . '.jpg';

$filename_tmp = $config['foldersAbs']['tmp'] . DIRECTORY_SEPARATOR . $file;
$filename_random = $config['foldersAbs']['tmp'] . DIRECTORY_SEPARATOR . $random;

if (file_exists($filename_tmp)) {
    rename($filename_tmp, $filename_random);
}

if (!isset($_POST['style'])) {
    $errormsg = basename($_SERVER['PHP_SELF']) . ': No style provided';
    logErrorAndDie($errormsg);
}

switch ($_POST['style']) {
    case 'photo':
        takePicture($filename_tmp);

        $LogData = [
            'success' => 'image',
            'file' => $file,
            'php' => basename($_SERVER['PHP_SELF']),
        ];
        break;
    case 'chroma':
        takePicture($filename_tmp);

        $LogData = [
            'success' => 'chroma',
            'file' => $file,
            'php' => basename($_SERVER['PHP_SELF']),
        ];
        break;
    default:
        $errormsg = basename($_SERVER['PHP_SELF']) . ': Invalid photo style provided';
        logErrorAndDie($errormsg);
        break;
}

// send imagename to frontend
$LogString = json_encode($LogData);
if ($config['dev']['loglevel'] > 1) {
    logError($LogData);
}
die($LogString);
