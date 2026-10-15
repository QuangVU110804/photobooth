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
