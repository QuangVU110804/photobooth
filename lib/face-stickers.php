<?php
/** Catalog-backed PNG stickers. No filesystem writes or client-supplied paths. */
function stickerNumber($value, float $min, float $max): bool {
    return (is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= $min && $value <= $max;
}
function stickerPair($value, float $min, float $max): bool {
    return is_array($value) && array_is_list($value) && count($value) === 2
        && stickerNumber($value[0],$min,$max) && stickerNumber($value[1],$min,$max);
}
function stickerCatalog(?string $directory = null): array {
    static $defaultCatalog;
    $useDefault = $directory === null;
    if ($useDefault && isset($defaultCatalog)) return $defaultCatalog;
    $directory = $directory ?? __DIR__ . '/../resources/face-effects';
    $invalid = static function() { throw new InvalidArgumentException('Invalid sticker catalog, effect or part'); };
    $catalogPath = $directory . '/catalog.json';
    if (!is_file($catalogPath) || filesize($catalogPath) > 65536) $invalid();
    try { $raw = json_decode(file_get_contents($catalogPath), true, 16, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { $invalid(); }
    if (!is_array($raw) || array_diff(array_keys($raw), ['version','effects']) || ($raw['version'] ?? null) !== 1
        || !isset($raw['effects']) || !is_array($raw['effects']) || !array_is_list($raw['effects']) || count($raw['effects']) > 32) $invalid();
    $base = realpath($directory . '/stickers');
    if ($base === false) $invalid();
    $presets = ['eyes','left-eye','right-eye','left-cheek','right-cheek','nose','mouth','forehead','chin','face'];
    $output = [];
    foreach ($raw['effects'] as $effect) {
        if (!is_array($effect) || array_diff(array_keys($effect),['id','title','parts'])
            || !is_string($effect['id'] ?? null) || !preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/',$effect['id'])
            || in_array($effect['id'],['none','glasses','blush'],true) || isset($output[$effect['id']])
            || !is_string($effect['title'] ?? null) || trim($effect['title']) === '' || preg_match_all('/./us',$effect['title']) > 80
            || !is_array($effect['parts'] ?? null) || !array_is_list($effect['parts']) || count($effect['parts']) < 1 || count($effect['parts']) > 4) $invalid();
        $parts = [];
        foreach ($effect['parts'] as $part) {
            if (!is_array($part) || array_diff(array_keys($part),['file','anchor','width','origin','offset','opacity'])
                || !is_string($part['file'] ?? null) || !preg_match('/\A[a-zA-Z0-9_-]{1,120}\.png\z/',$part['file'])
                || !in_array($part['anchor'] ?? null,$presets,true) || !stickerNumber($part['width'] ?? null,.01,4)) $invalid();
            $origin = array_key_exists('origin',$part) ? $part['origin'] : [.5,.5];
            $offset = array_key_exists('offset',$part) ? $part['offset'] : [0,0];
            $opacity = array_key_exists('opacity',$part) ? $part['opacity'] : 1.0;
            if (!stickerPair($origin,0,1) || !stickerPair($offset,-2,2) || !stickerNumber($opacity,0,1)) $invalid();
            $path = realpath($base . DIRECTORY_SEPARATOR . $part['file']);
            if ($path === false || dirname($path) !== $base || !is_file($path) || filesize($path) > 2097152) $invalid();
            $info = @getimagesize($path);
            if (!$info || $info[2] !== IMAGETYPE_PNG || $info[0] < 1 || $info[1] < 1 || $info[0] > 1536 || $info[1] > 1536) $invalid();
            $parts[] = ['file'=>$part['file'],'anchor'=>$part['anchor'],'width'=>$part['width'],
                'origin'=>$origin,'offset'=>$offset,'opacity'=>(float)$opacity,'path'=>$path,'imageWidth'=>$info[0],'imageHeight'=>$info[1]];
        }
        $output[$effect['id']] = ['id'=>$effect['id'],'title'=>trim($effect['title']),'parts'=>$parts];
    }
    if ($useDefault) $defaultCatalog = $output;
    return $output;
}
function parseFaceSticker(stdClass $raw, ?string $directory = null): array {
    $value = get_object_vars($raw);
    if (count($value) !== 6 || array_diff(array_keys($value),['type','effect','part','center','width','angle'])
        || ($value['type'] ?? null) !== 'sticker' || !is_string($value['effect'] ?? null) || !is_int($value['part'] ?? null)
        || !stickerPair($value['center'] ?? null,-1,2) || !stickerNumber($value['width'] ?? null,0,2) || $value['width'] <= 0
        || !stickerNumber($value['angle'] ?? null,-M_PI,M_PI)) throw new InvalidArgumentException('Invalid PNG sticker payload');
    $catalog = stickerCatalog($directory);
    if (!isset($catalog[$value['effect']]['parts'][$value['part']])) throw new InvalidArgumentException('Unknown PNG sticker component');
    return $value;
}
/** GD memory is not consistently counted by PHP; reserve native image storage too. */
function stickerBudget(int $width, int $height, int $reserved): void {
    if ($width < 1 || $height < 1 || $width > 4000000 || $height > 4000000 || $width * $height > 4000000)
        throw new InvalidArgumentException('PNG sticker surface exceeds pixel budget');
    $limit = trim(ini_get('memory_limit'));
    if ($limit === '-1') return;
    $bytes = (int)$limit;
    switch (strtolower(substr($limit,-1))) { case 'g': $bytes *= 1024; case 'm': $bytes *= 1024; case 'k': $bytes *= 1024; }
    if ($bytes > 0 && memory_get_usage(true) + $reserved + $width * $height * 8 + 65536 > $bytes)
        throw new InvalidArgumentException('Insufficient memory for PNG sticker');
}
function stickerSurface(int $width, int $height, int $reserved): GdImage {
    stickerBudget($width,$height,$reserved);
    $image = imagecreatetruecolor($width,$height);
    imagealphablending($image,false); imagesavealpha($image,true);
    imagefill($image,0,0,imagecolorallocatealpha($image,0,0,0,127));
    return $image;
}
/** Render bounded local surfaces; all entries have already passed payload validation. */
function applyFaceStickers(GdImage $image, array $elements, ?string $directory = null): void {
    $catalog = stickerCatalog($directory);
    $width = imagesx($image); $height = imagesy($image); $sources = []; $sourceBytes = 0;
    try {
        foreach ($elements as $element) {
            if (($element['type'] ?? null) !== 'sticker') { applyFaceEffects($image,[$element]); continue; }
            $part = $catalog[$element['effect']]['parts'][$element['part']];
            if ($part['opacity'] <= 0) continue;
            $w = max(1,(int)round($element['width'] * $width));
            $h = max(1,(int)round($w * $part['imageHeight'] / $part['imageWidth']));
            $cos = abs(cos($element['angle'])); $sin = abs(sin($element['angle']));
            $boundW = (int)ceil($w*$cos+$h*$sin)+2; $boundH = (int)ceil($w*$sin+$h*$cos)+2;
            $cx = $element['center'][0]*$width; $cy = $element['center'][1]*$height;
            if ($cx+$boundW/2 <= 0 || $cy+$boundH/2 <= 0 || $cx-$boundW/2 >= $width || $cy-$boundH/2 >= $height) continue;
            $reserve = $width*$height*4+$sourceBytes;
            stickerBudget($w,$h,$reserve); stickerBudget($boundW,$boundH,$reserve+$w*$h*4);
            if (!isset($sources[$part['path']])) {
                stickerBudget($part['imageWidth'],$part['imageHeight'],$reserve);
                $source = @imagecreatefrompng($part['path']);
                if (!$source) throw new InvalidArgumentException('Invalid sticker PNG');
                if (!imageistruecolor($source)) imagepalettetotruecolor($source);
                $sources[$part['path']] = $source;
                $sourceBytes += $part['imageWidth']*$part['imageHeight']*4;
            }
            $source = $sources[$part['path']]; $opacitySource = null; $scaled = null; $rotated = null;
            try {
                if ($part['opacity'] < 1) {
                    $opacitySource = stickerSurface($part['imageWidth'],$part['imageHeight'],$width*$height*4+$sourceBytes);
                    imagecopy($opacitySource,$source,0,0,0,0,$part['imageWidth'],$part['imageHeight']);
                    for ($y=0;$y<$part['imageHeight'];$y++) for ($x=0;$x<$part['imageWidth'];$x++) {
                        $color = imagecolorat($opacitySource,$x,$y);
                        $alpha = (int)round(127-(127-(($color>>24)&127))*$part['opacity']);
                        imagesetpixel($opacitySource,$x,$y,($color&0xffffff)|($alpha<<24));
                    }
                    $source = $opacitySource;
                }
                $reserved = $width*$height*4+$sourceBytes+($opacitySource?$part['imageWidth']*$part['imageHeight']*4:0);
                $scaled = stickerSurface($w,$h,$reserved);
                imagecopyresampled($scaled,$source,0,0,0,0,$w,$h,$part['imageWidth'],$part['imageHeight']);
                $rendered = $scaled;
                if (abs($element['angle']) > 0.000001) {
                    stickerBudget($boundW,$boundH,$reserved+$w*$h*4);
                    $rotated = imagerotate($scaled,-rad2deg($element['angle']),imagecolorallocatealpha($scaled,0,0,0,127));
                    if (!$rotated) throw new InvalidArgumentException('Could not rotate PNG sticker');
                    imagesavealpha($rotated,true); $rendered = $rotated;
                }
                imagealphablending($image,true);
                imagecopy($image,$rendered,(int)round($cx-imagesx($rendered)/2),(int)round($cy-imagesy($rendered)/2),0,0,imagesx($rendered),imagesy($rendered));
            } finally {
                if ($rotated) imagedestroy($rotated);
                if ($scaled) imagedestroy($scaled);
                if ($opacitySource) imagedestroy($opacitySource);
            }
        }
    } catch (InvalidArgumentException $error) {
        // Existing applyEffects rendering errors are handled as RuntimeException.
        throw new RuntimeException($error->getMessage(), 0, $error);
    } finally { foreach ($sources as $source) imagedestroy($source); }
}
