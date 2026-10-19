<?php
require_once __DIR__ . '/photo-receipt.php';
require_once __DIR__ . '/face-stickers.php';

/** Raw capture names only; no paths, streams, hidden files or Windows devices. */
function faceEffectFilename($name): bool {
    return photoReceiptFilename($name) !== null
        && preg_match('/\.jpg\z/i', $name) === 1
        && strpos($name, '..') === false
        && preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $name) !== 1;
}

/** Decode only bounded, normalized polygons. Throws before any GD/file work. */
function parseFaceEffects($raw): array {
    if ($raw === null) return [];
    if (!is_string($raw) || strlen($raw) > 65536) {
        throw new InvalidArgumentException('faceEffects must be JSON of at most 65536 bytes');
    }
    try {
        $decoded = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new InvalidArgumentException('Invalid faceEffects JSON', 0, $e);
    }
    if (!$decoded instanceof stdClass || count(get_object_vars($decoded)) > 12) {
        throw new InvalidArgumentException('faceEffects must be an object with at most 12 raw filenames');
    }
    $result = [];
    foreach ($decoded as $name => $polygons) {
        if (!faceEffectFilename($name) || !is_array($polygons) || count($polygons) > 32) {
            throw new InvalidArgumentException('Invalid faceEffects filename or polygon list');
        }
        $result[$name] = [];
        $stickerCount = 0;
        foreach ($polygons as $polygon) {
            if ($polygon instanceof stdClass && ($polygon->type ?? null) === 'sticker') {
                if (++$stickerCount > 16) throw new InvalidArgumentException('Too many PNG stickers');
                $result[$name][] = parseFaceSticker($polygon);
                continue;
            }
            if (!$polygon instanceof stdClass || !isset($polygon->points, $polygon->color)
                || count(get_object_vars($polygon)) !== 2
                || !in_array($polygon->color, ['ink', 'pink', 'white'], true)
                || !is_array($polygon->points) || count($polygon->points) < 3 || count($polygon->points) > 32) {
                throw new InvalidArgumentException('Invalid faceEffects polygon or color');
            }
            foreach ($polygon->points as $point) {
                if (!is_array($point) || count($point) !== 2) {
                    throw new InvalidArgumentException('Face points must be [x,y] pairs');
                }
                foreach ($point as $coordinate) {
                    if ((!is_int($coordinate) && !is_float($coordinate)) || !is_finite((float) $coordinate)
                        || $coordinate < -1 || $coordinate > 2) {
                        throw new InvalidArgumentException('Face coordinates must be finite numbers in [-1,2]');
                    }
                }
            }
            $result[$name][] = ['points' => $polygon->points, 'color' => $polygon->color];
        }
    }
    return $result;
}

/** Render validated polygons/PNG stickers into the existing raw-oriented image. */
function applyFaceEffects(GdImage $image, array $polygons): void {
    if (!$polygons) return;
    foreach ($polygons as $polygon) {
        if (($polygon['type'] ?? null) === 'sticker') { applyFaceStickers($image, $polygons); return; }
    }
    $width = imagesx($image);
    $height = imagesy($image);
    $colors = ['ink' => [18,36,58], 'pink' => [242,148,172], 'white' => [255,255,255]];
    if (function_exists('imageantialias')) imageantialias($image, true);
    foreach ($polygons as $polygon) {
        $points = [];
        foreach ($polygon['points'] as [$x, $y]) {
            // GD clips coordinates to the image; bounded input needs no masks
            // or offscreen surfaces, including landmarks outside the capture.
            $points[] = (int) round($x * ($width - 1));
            $points[] = (int) round($y * ($height - 1));
        }
        $color = imagecolorallocate($image, ...$colors[$polygon['color']]);
        imagefilledpolygon($image, $points, $color);
    }
    if (function_exists('imageantialias')) imageantialias($image, false);
}
