<?php

/** Resolve a PNG in the frames directory or one topic folder beneath it. */
function resolvePhotoFrame(string $directory, $name): ?string {
    if (!is_string($name) || str_contains($name, '\\') || str_contains($name, ':')
        || preg_match('/[\x00-\x1f\x7f]/', $name)
        || !preg_match('/\.png\z/i', $name)) return null;

    $parts = explode('/', $name, 3);
    $filename = array_pop($parts);
    $topic = $parts[0] ?? null;
    if (count($parts) > 1 || in_array($topic, ['', '.', '..'], true)
        || strcasecmp($filename, 'current_active_frame.png') === 0) return null;

    $directory = realpath($directory);
    if ($directory === false) return null;
    $parent = $topic === null ? $directory : $directory . DIRECTORY_SEPARATOR . $topic;
    $path = realpath($parent . DIRECTORY_SEPARATOR . $filename);
    return $path !== false && dirname($path) === $parent && is_file($path)
        && strcasecmp(basename($path), 'current_active_frame.png') !== 0 ? $path : null;
}

function listPhotoFrames(string $directory, bool $includeTopics = false): array {
    $directory = rtrim($directory, '/\\');
    $paths = glob($directory . '/*') ?: [];
    if ($includeTopics) $paths = array_merge($paths, glob($directory . '/*/*') ?: []);

    $names = [];
    foreach ($paths as $path) {
        $name = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($directory) + 1));
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

function cleanPhotoFramePreviews(string $cacheDirectory, array $frameDirectories, string $requestedKey): void {
    $now = time();
    $lockPath = $cacheDirectory . '/.cleanup.lock';
    $lastSweep = @stat($lockPath);
    if (($lastSweep['size'] ?? 0) === 1 && $lastSweep['mtime'] > $now - 3600) return;

    $cache = realpath($cacheDirectory);
    $parent = realpath(dirname($cacheDirectory));
    if ($parent === false || $cache !== $parent . DIRECTORY_SEPARATOR . 'frame-previews'
        || !is_dir($cache) || is_link($cacheDirectory) || is_link($lockPath)) return;

    $lock = @fopen($lockPath, 'c+b');
    if ($lock === false) return;
    try {
        if (!@flock($lock, LOCK_EX | LOCK_NB)) return;
        $lastSweep = fstat($lock);
        if (!$lastSweep || $lastSweep['size'] > 1
            || ($lastSweep['size'] === 1 && $lastSweep['mtime'] > $now - 3600)) return;
        if (@fwrite($lock, '1') !== 1 || !@fflush($lock)) return;

        $current = [$requestedKey . '.png' => true];
        try {
            foreach ($frameDirectories as $directory) {
                foreach (listPhotoFrames($directory, true) as $name) {
                    $source = resolvePhotoFrame($directory, $name);
                    if ($source !== null) $current[photoFramePreviewKey($source) . '.png'] = true;
                }
            }
        } catch (RuntimeException $error) {
            // Retry on a later sweep if a frame changes while the catalog is being read.
            return;
        }

        foreach (glob($cache . '/*.png') ?: [] as $path) {
            $name = basename($path);
            if (isset($current[$name]) || !preg_match('/\A[a-f0-9]{64}\.png\z/', $name)
                || is_link($path) || !is_file($path)) continue;
            // Give freshly published previews time to finish serving concurrent requests.
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime <= $now - 900) @unlink($path);
        }
    } finally {
        fclose($lock);
    }
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

function photoFrameGroups(array $frames): array {
    $other = [];
    $folders = [];
    foreach ($frames as $name) {
        $parts = explode('/', $name, 2);
        if (count($parts) === 1) $other[] = $name;
        else $folders[$parts[0]][] = $name;
    }

    $numberedGroups = [];
    $groups = [];
    foreach ($folders as $folder => $names) {
        $hasPrefix = preg_match('/^([0-9]+) - (\S.*)$/', (string) $folder, $prefix);
        $label = $hasPrefix ? $prefix[2] : (string) $folder;
        if (in_array($label, ['Khác', 'Tất cả khung'], true)) {
            $other = array_merge($other, $names);
            continue;
        }
        $group = ['label' => $label === '20-11' ? '20/11' : $label, 'frames' => $names];
        if ($hasPrefix) $numberedGroups[(int) $prefix[1]][] = $group;
        else $groups[] = $group;
    }
    ksort($numberedGroups, SORT_NUMERIC);
    $groups = array_merge(array_merge(...array_values($numberedGroups)), $groups);
    if ($other) $groups[] = ['label' => 'Khác', 'frames' => $other];
    return $groups;
}
