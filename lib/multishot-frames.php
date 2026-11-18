<?php
require_once __DIR__ . '/frames.php';

const MULTISHOT_LAYOUTS = ['1x2' => 2, '1x3' => 3, '1x4' => 4, '2x2' => 4, '2x3' => 6];

function multishotPngSize(string $path): ?array {
    $info = @getimagesize($path);
    if (!$info || $info[2] !== IMAGETYPE_PNG || max($info[0], $info[1]) > 6000 || $info[0] * $info[1] > 12000000) return null;
    if (@file_get_contents($path, false, null, -12) !== "\0\0\0\0IEND\xaeB\x60\x82") return null;
    return [$info[0], $info[1]];
}

function multishotFrame(string $directory, $entry): ?array {
    if (!is_array($entry)) return null;
    foreach (['id', 'name', 'topic', 'layout', 'png'] as $key) {
        if (!is_string($entry[$key] ?? null) || trim($entry[$key]) === '') return null;
    }
    if (!preg_match('/^[a-z0-9-]{1,64}$/D', $entry['id']) || ($entry['crop'] ?? '') !== 'cover') return null;
    $count = MULTISHOT_LAYOUTS[$entry['layout']] ?? 0;
    $slots = $entry['slots'] ?? null;
    $width = $entry['width'] ?? null;
    $height = $entry['height'] ?? null;
    if (!$count || !is_array($slots) || !array_is_list($slots) || count($slots) !== $count
        || !is_int($width) || !is_int($height) || min($width, $height) < 1) return null;

    foreach ($slots as $index => $rect) {
        if (!is_array($rect) || !array_is_list($rect) || count($rect) !== 4 || count(array_filter($rect, 'is_int')) !== 4) return null;
        [$x, $y, $w, $h] = $rect;
        if ($x < 0 || $y < 0 || min($w, $h) < 1 || $x > $width - $w || $y > $height - $h) return null;
        for ($i = 0; $i < $index; $i++) {
            [$a, $b, $c, $d] = $slots[$i];
            if ($x < $a + $c && $x + $w > $a && $y < $b + $d && $y + $h > $b) return null;
        }
    }

    $path = resolvePhotoFrame($directory, $entry['png']);
    $size = $path !== null ? multishotPngSize($path) : null;
    if ($size !== [$width, $height]) return null;
    return array_intersect_key($entry, array_flip(['id', 'name', 'topic', 'layout', 'png', 'crop', 'width', 'height', 'slots']))
        + ['path' => $path];
}

function multishotFramesDirectory(): string {
    return __DIR__ . '/../resources/img/Multi-frames';
}

/** Discover PNGs like the single-photo catalog. The adjacent JSON defines the photo windows. */
function multishotFrames(?string $directory = null): array {
    $directory ??= multishotFramesDirectory();
    $frames = [];
    foreach (photoFrameGroups(listPhotoFrames($directory, true)) as $group) {
        foreach ($group['frames'] as $png) {
            $path = resolvePhotoFrame($directory, $png);
            if ($path === null) continue;
            $metadata = substr($path, 0, -4) . '.json';
            if (!is_file($metadata) || is_link($metadata) || filesize($metadata) > 65536) continue;
            $raw = @file_get_contents($metadata);
            $entry = $raw !== false ? json_decode($raw, true) : null;
            if (!is_array($entry)) continue;
            $entry['png'] = $png;
            $entry['topic'] = $group['label'];
            $frame = multishotFrame($directory, $entry);
            if (!$frame || isset($frames[$frame['id']])) continue;
            $frames[$frame['id']] = $frame;
        }
    }
    return $frames;
}
