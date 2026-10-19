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

    if ($config['dev']['demo_images']) {
        $demoFolder = __DIR__ . '/../resources/img/demo/';
        $devImg = glob($demoFolder . '*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE) ?: [];
        if (!$devImg) apiError('No demo JPEG images available', 503);
        if (!copy($devImg[array_rand($devImg)], $filename)) apiError('Could not save demo capture', 500);
    } elseif ($captureSource === 'browser') {
        if (file_put_contents($filename, $cameraBytes) !== strlen($cameraBytes)) apiError('Could not save capture', 500);

        if ($config['preview']['flip'] != 'off') {
            $im = imagecreatefromjpeg($filename);
            switch ($config['preview']['flip']) {
                case 'flip-horizontal':
                    imageflip($im, IMG_FLIP_HORIZONTAL);
                    break;
                case 'flip-vertical':
                    imageflip($im, IMG_FLIP_VERTICAL);
                    break;
                default:
                    break;
            }
            imagejpeg($im, $filename);
            imagedestroy($im);
        }
    } else {
        //gphoto must be executed in a dir with write permission for other commands we stay in the api dir
        if (substr($config['take_picture']['cmd'], 0, strlen('gphoto')) === 'gphoto') {
            chdir(dirname($filename));
        }
        if (trim($config['take_picture']['cmd']) === '') apiError('Capture command is not configured', 503);
        $cmd = sprintf($config['take_picture']['cmd'], escapeshellarg($filename));
        $cmd .= ' 2>&1'; //Redirect stderr to stdout, otherwise error messages get lost.

        exec($cmd, $output, $returnValue);

        if ($returnValue && $config['dev']['loglevel'] > 1) {
            $ErrorData = [
                'error' => 'Take picture command returned an error code',
                'cmd' => $cmd,
                'returnValue' => $returnValue,
                'output' => $output,
                'php' => basename($_SERVER['PHP_SELF']),
            ];
            $ErrorString = json_encode($ErrorData);
            logError($ErrorData);
        }

        if (!is_file($filename) || !@getimagesize($filename)) {
            $ErrorData = [
                'error' => 'Máy ảnh ngoài chưa tạo được ảnh. Kiểm tra kết nối và lệnh digiCamControl/gphoto2, hoặc chọn camera trên thiết bị.',
                'cmd' => $cmd,
                'returnValue' => $returnValue,
                'output' => $output,
                'php' => basename($_SERVER['PHP_SELF']),
            ];
            $ErrorString = json_encode($ErrorData);
            logError($ErrorData);
            die($ErrorString);
        }
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
