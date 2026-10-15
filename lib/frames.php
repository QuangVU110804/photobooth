<?php

/** Resolve a selectable frame without allowing paths or the shared active copy. */
function resolvePhotoFrame(string $directory, $name): ?string {
    if (!is_string($name) || $name === '' || basename($name) !== $name
        || strpos($name, '/') !== false || strpos($name, '\\') !== false
        || preg_match('/[\x00-\x1f\x7f]/', $name)
        || !preg_match('/\.png\z/i', $name)
        || strcasecmp($name, 'current_active_frame.png') === 0) return null;

    $directory = realpath($directory);
    if ($directory === false) return null;
    $path = realpath($directory . DIRECTORY_SEPARATOR . $name);
    return $path !== false && dirname($path) === $directory && is_file($path) ? $path : null;
}

function listPhotoFrames(string $directory): array {
    $names = [];
    foreach (glob($directory . '/*.png') ?: [] as $path) {
        $name = basename($path);
        if (resolvePhotoFrame($directory, $name) !== null) $names[] = $name;
    }
    sort($names);
    return $names;
}

/** Include the recipe version so a later renderer change invalidates old PNGs. */
function photoFramePreviewKey(string $source): string {
    $stat = @stat($source);
    if (!$stat) throw new RuntimeException('Frame is unavailable');
    return hash('sha256', 'png-360x480-v1:' . $source . ':' . $stat['size'] . ':' . $stat['mtime'] . ':' . $stat['ctime']);
}

/** Cache a transparent preview; original frames remain the capture/print source. */
function photoFramePreview(string $source, string $cacheDirectory): string {
    $path = $cacheDirectory . DIRECTORY_SEPARATOR . photoFramePreviewKey($source) . '.png';
    if (is_file($path) && is_readable($path)) return $path;

    $info = @getimagesize($source);
    if (!$info || $info[2] !== IMAGETYPE_PNG) throw new InvalidArgumentException('Frame must be a valid PNG');
    if (!extension_loaded('gd')) throw new RuntimeException('GD library is unavailable');
    if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0755, true) && !is_dir($cacheDirectory)) {
        throw new RuntimeException('Could not create frame preview cache');
    }

    $image = @imagecreatefrompng($source);
    if (!$image) throw new InvalidArgumentException('Frame must be a valid PNG');
    $preview = null;
    $temporary = null;
    try {
        $scale = min(1, 360 / $info[0], 480 / $info[1]);
        $width = max(1, (int) round($info[0] * $scale));
        $height = max(1, (int) round($info[1] * $scale));
        $preview = imagecreatetruecolor($width, $height);
        imagealphablending($preview, false);
        imagesavealpha($preview, true);
        if (!imagecopyresampled($preview, $image, 0, 0, 0, 0, $width, $height, $info[0], $info[1])) {
            throw new RuntimeException('Could not resize frame preview');
        }
        // Publish complete files only, including when multiple clients miss the cache.
        $temporary = @tempnam($cacheDirectory, 'frame-');
        if ($temporary === false || !@imagepng($preview, $temporary, 6) || !@rename($temporary, $path)) {
            throw new RuntimeException('Could not save frame preview');
        }
        return $path;
    } finally {
        if ($temporary && is_file($temporary)) @unlink($temporary);
        if ($preview) imagedestroy($preview);
        imagedestroy($image);
    }
}
