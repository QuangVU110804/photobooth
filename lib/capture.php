<?php
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/face-effects.php';

function decodeCameraImage($value, string $mime): string {
    $prefix = 'data:' . $mime . ';base64,';
    if (!is_string($value) || strlen($value) > 33554432 || strncmp($value, $prefix, strlen($prefix)) !== 0) {
        throw new InvalidArgumentException('Invalid camera image');
    }
    $bytes = base64_decode(substr($value, strlen($prefix)), true);
    $info = $bytes !== false ? @getimagesizefromstring($bytes) : false;
    if (!$info || $info['mime'] !== $mime || $info[0] * $info[1] > 40000000) {
        throw new InvalidArgumentException('Invalid camera image');
    }
    $decoded = @imagecreatefromstring($bytes);
    if (!$decoded) throw new InvalidArgumentException('Invalid camera image');
    imagedestroy($decoded);
    return $bytes;
}

/** A random suffix protects simultaneous captures and same-second retakes. */
function newCaptureFilename(array $config): string {
    $name = ($config['picture']['naming'] === 'dateformatted' ? date('Ymd_His') . '_' : '') . bin2hex(random_bytes(12)) . '.jpg';
    $prefix = $config['database']['file'];
    if ($prefix === 'db') return $name;
    // Keep generated raw names compatible with the effects API.
    if (!is_string($prefix) || !faceEffectFilename($prefix . '_' . $name)) {
        throw new InvalidArgumentException('Invalid database filename prefix');
    }
    return $prefix . '_' . $name;
}

class CameraCaptureError extends RuntimeException {
    public array $details = [];
}

/** Serialize commands for the booth's configured external camera. */
function lockExternalCamera(array $config) {
    $handle = @fopen($config['foldersAbs']['tmp'] . '/.external-camera.lock', 'c+b');
    if (!$handle) throw new CameraCaptureError('Could not open camera lock', 500);
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new CameraCaptureError('Máy ảnh ngoài đang bận. Hãy thử lại.', 409);
    }
    return $handle;
}

function saveCameraCapture(array $config, string $filename, string $source, ?string $bytes): void {
    if ($config['dev']['demo_images']) {
        $images = glob(__DIR__ . '/../resources/img/demo/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE) ?: [];
        if (!$images) throw new CameraCaptureError('No demo JPEG images available', 503);
        if (!@copy($images[array_rand($images)], $filename)) throw new CameraCaptureError('Could not save demo capture', 500);
        return;
    }
    if ($source === 'browser') {
        if ($bytes === null || @file_put_contents($filename, $bytes) !== strlen($bytes)) {
            throw new CameraCaptureError('Could not save capture', 500);
        }
        $flip = $config['preview']['flip'];
        if ($flip !== 'off') {
            $image = imagecreatefromjpeg($filename);
            if ($flip === 'flip-horizontal') imageflip($image, IMG_FLIP_HORIZONTAL);
            if ($flip === 'flip-vertical') imageflip($image, IMG_FLIP_VERTICAL);
            $saved = @imagejpeg($image, $filename);
            imagedestroy($image);
            if (!$saved) throw new CameraCaptureError('Could not save capture', 500);
        }
        return;
    }
    $command = $config['take_picture']['cmd'];
    if (trim($command) === '') throw new CameraCaptureError('Capture command is not configured', 503);
    $lock = lockExternalCamera($config);
    $cwd = getcwd();
    try {
        // gphoto needs a writable working directory.
        if (str_starts_with($command, 'gphoto')) chdir(dirname($filename));
        $cmd = sprintf($command, escapeshellarg($filename)) . ' 2>&1';
        exec($cmd, $output, $returnValue);
        $details = ['cmd' => $cmd, 'returnValue' => $returnValue, 'output' => $output, 'php' => basename($_SERVER['PHP_SELF'])];
        if ($returnValue && $config['dev']['loglevel'] > 1) logError(['error' => 'Take picture command returned an error code'] + $details);
        if (!is_file($filename) || !@getimagesize($filename)) {
            $error = new CameraCaptureError('Máy ảnh ngoài chưa tạo được ảnh. Kiểm tra kết nối và lệnh digiCamControl/gphoto2, hoặc chọn camera trên thiết bị.', 502);
            $error->details = ['error' => $error->getMessage()] + $details;
            logError($error->details);
            throw $error;
        }
    } finally {
        if ($cwd !== false) chdir($cwd);
        fclose($lock);
    }
}
