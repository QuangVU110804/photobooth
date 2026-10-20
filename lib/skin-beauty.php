<?php
/** Local face retouching. Bounded metadata only; no extra image uploads or files. */
function parseBeautyEffects($raw): array {
    if ($raw === null) return [];
    $invalid = static function() { throw new InvalidArgumentException('Invalid skin beauty settings or regions'); };
    if (!is_string($raw) || strlen($raw) > 32768) $invalid();
    try { $v = json_decode($raw, true, 12, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { $invalid(); }
    if (!is_array($v) || count($v) !== 3 || array_diff(array_keys($v), ['smooth','white','faces'])) $invalid();
    foreach (['smooth','white'] as $key) if (!isset($v[$key]) || !is_int($v[$key]) || $v[$key] < 0 || $v[$key] > 100) $invalid();
    if (!isset($v['faces']) || !is_array($v['faces']) || !array_is_list($v['faces']) || count($v['faces']) > 4) $invalid();
    foreach ($v['faces'] as $face) {
        if (!is_array($face) || count($face) !== 2 || array_diff(array_keys($face), ['outline','holes'])
            || !isset($face['outline'], $face['holes']) || !is_array($face['holes']) || !array_is_list($face['holes']) || count($face['holes']) > 5) $invalid();
        foreach (array_merge([$face['outline']], $face['holes']) as $polygon) {
            if (!is_array($polygon) || !array_is_list($polygon) || count($polygon) < 3 || count($polygon) > 40) $invalid();
            foreach ($polygon as $point) {
                if (!is_array($point) || !array_is_list($point) || count($point) !== 2) $invalid();
                foreach ($point as $coordinate) if ((!is_int($coordinate) && !is_float($coordinate)) || !is_finite((float)$coordinate) || $coordinate < -1 || $coordinate > 2) $invalid();
            }
        }
    }
    return $v;
}

function skinBeautyStep(float $a, float $b, float $v): float {
    $t = max(0, min(1, ($v - $a) / ($b - $a)));
    return $t * $t * (3 - 2 * $t);
}

/** Read only bounded JPEG metadata. Browser Image decoding honors EXIF; GD does not. */
function skinBeautyJpegOrientation(string $filename): int {
    $stream = @fopen($filename,'rb');
    if (!$stream) return 1;
    try { $bytes = fread($stream,131072); } finally { fclose($stream); }
    if ($bytes === false || substr($bytes,0,2) !== "\xff\xd8") return 1;
    $size = strlen($bytes); $offset = 2;
    while ($offset + 4 <= $size) {
        if (ord($bytes[$offset]) !== 255) return 1;
        while ($offset < $size && ord($bytes[$offset]) === 255) $offset++;
        if ($offset + 3 > $size) return 1;
        $marker = ord($bytes[$offset++]);
        if ($marker === 0xda || $marker === 0xd9) return 1;
        $length = unpack('n',substr($bytes,$offset,2))[1];
        if ($length < 2 || $offset + $length > $size) return 1;
        if ($marker === 0xe1 && substr($bytes,$offset+2,6) === "Exif\0\0") {
            $tiff = substr($bytes,$offset+8,$length-8); $n = strlen($tiff);
            $little = substr($tiff,0,2) === 'II';
            if (!$little && substr($tiff,0,2) !== 'MM') return 1;
            $number = static function(int $at,int $count) use ($tiff,$n,$little) {
                if ($at < 0 || $at+$count > $n) return null;
                return unpack($count===2 ? ($little?'v':'n') : ($little?'V':'N'),substr($tiff,$at,$count))[1];
            };
            if ($number(2,2) !== 42) return 1;
            $ifd = $number(4,4); if ($ifd === null || $ifd < 8 || $ifd+2 > $n) return 1;
            $count = min(512,$number($ifd,2));
            for ($i=0;$i<$count;$i++) {
                $entry = $ifd+2+$i*12;
                if ($entry+12 > $n) return 1;
                if ($number($entry,2) === 0x112 && $number($entry+2,2) === 3 && $number($entry+4,4) === 1) {
                    $orientation = $number($entry+8,2);
                    return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
                }
            }
        }
        $offset += $length;
    }
    return 1;
}

function skinBeautyRawOrientation(array $settings,int $orientation): array {
    if ($orientation <= 1 || $orientation > 8) return $settings;
    $point = static function(array $p) use ($orientation): array {
        [$x,$y] = $p;
        switch ($orientation) {
            case 2: return [1-$x,$y];
            case 3: return [1-$x,1-$y];
            case 4: return [$x,1-$y];
            case 5: return [$y,$x];
            case 6: return [$y,1-$x];
            case 7: return [1-$y,1-$x];
            case 8: return [1-$y,$x];
        }
    };
    foreach ($settings['faces'] as &$face) {
        $face['outline'] = array_map($point,$face['outline']);
        foreach ($face['holes'] as &$hole) $hole = array_map($point,$hole);
        unset($hole);
    }
    unset($face);
    return $settings;
}

/** Same edge-preserving blend as the preview, on <=512px local surfaces per face. */
function applySkinBeauty(GdImage $image, array $settings): bool {
    if (!$settings || (!$settings['smooth'] && !$settings['white']) || !$settings['faces']) return false;
    $iw = imagesx($image); $ih = imagesy($image); $changed = false; $previous = [];
    // Quantized chroma/edge weights avoid millions of PHP function calls per photo.
    static $weights;
    if ($weights === null) {
        $weights = ['cb'=>[],'cr'=>[],'y'=>[],'edge'=>[]];
        for ($i=0;$i<256;$i++) {
            $v=$i/255;
            $weights['cb'][$i]=skinBeautyStep(.30,.36,$v)*(1-skinBeautyStep(.48,.54,$v));
            $weights['cr'][$i]=skinBeautyStep(.51,.545,$v)*(1-skinBeautyStep(.65,.70,$v));
            $weights['y'][$i]=skinBeautyStep(.05,.16,$v)*(1-skinBeautyStep(.93,1,$v));
            $weights['edge'][$i]=1-skinBeautyStep(.030,.115,$v);
        }
    }
    $smoothLevel=$settings['smooth']/100*.85; $whiteLevel=$settings['white']/100*.22;
    // Total mask/CPU work stays <=512*512 pixels even with four large faces.
    $edgeBudget = (int)floor(512/sqrt(count($settings['faces'])));
    foreach ($settings['faces'] as $face) {
        $xs = array_column($face['outline'], 0); $ys = array_column($face['outline'], 1);
        $left = max(0, (int)floor(min($xs) * $iw) - 4); $top = max(0, (int)floor(min($ys) * $ih) - 4);
        $right = min($iw, (int)ceil(max($xs) * $iw) + 4); $bottom = min($ih, (int)ceil(max($ys) * $ih) + 4);
        $rw = $right - $left; $rh = $bottom - $top;
        if ($rw < 2 || $rh < 2) continue;
        $scale = min(1, $edgeBudget / max($rw, $rh)); $w = max(1, (int)round($rw * $scale)); $h = max(1, (int)round($rh * $scale));
        $src = $mask = $fine = $patch = null;
        try {
            $src = imagecreatetruecolor($w, $h); $mask = imagecreatetruecolor($w, $h); $patch = imagecreatetruecolor($w, $h);
            imagecopyresampled($src, $image, 0, 0, $left, $top, $w, $h, $rw, $rh);
            $polygon = static function($points) use ($iw,$ih,$left,$top,$rw,$rh,$w,$h) {
                $result = [];
                foreach ($points as [$x,$y]) { $result[] = (int)round(($x*$iw-$left)*$w/$rw); $result[] = (int)round(($y*$ih-$top)*$h/$rh); }
                return $result;
            };
            imagefilledpolygon($mask, $polygon($face['outline']), 0xffffff);
            foreach ($settings['faces'] as $other) foreach ($other['holes'] as $hole) imagefilledpolygon($mask, $polygon($hole), 0);
            imagefilter($mask, IMG_FILTER_GAUSSIAN_BLUR);
            // Recut feature interiors after feathering: whitening never bleeds into eyes/lips.
            foreach ($settings['faces'] as $other) foreach ($other['holes'] as $hole) imagefilledpolygon($mask, $polygon($hole), 0);
            foreach ($previous as $outline) imagefilledpolygon($mask,$polygon($outline),0);
            if ($settings['smooth']) {
                $fw = max(2, (int)round($w * min(1, 180 / max($w,$h)))); $fh = max(2, (int)round($h*$fw/$w));
                $fine = imagecreatetruecolor($fw, $fh);
                imagecopyresampled($fine, $src, 0, 0, 0, 0, $fw, $fh, $w, $h);
                imagefilter($fine, IMG_FILTER_GAUSSIAN_BLUR);
            }
            imagealphablending($patch, false); imagesavealpha($patch, true);
            imagefill($patch, 0, 0, 0x7f000000);
            $hasCorrection = false;
            for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
                $m = (imagecolorat($mask,$x,$y) & 255) / 255;
                if ($m < .004) continue;
                $rgb = imagecolorat($src,$x,$y); $r = ($rgb>>16)&255; $g = ($rgb>>8)&255; $b = $rgb&255;
                $luma = .299*$r + .587*$g + .114*$b;
                $cb = max(0,min(255,(int)round(($b-$luma)*.564+127.5))); $cr = max(0,min(255,(int)round(($r-$luma)*.713+127.5)));
                $tone = $weights['cb'][$cb]*$weights['cr'][$cr];
                $skin = $m*(.45+.55*$tone)*$weights['y'][(int)round($luma)];
                if (!$fine) {
                    $alpha=$whiteLevel*$skin;
                    if ($alpha >= 1/254) { imagesetpixel($patch,$x,$y,((int)round((1-$alpha)*127)<<24)|0xffffff); $hasCorrection=true; }
                    continue;
                }
                $fr = $r; $fg = $g; $fb = $b;
                if ($fine) {
                    $v = imagecolorat($fine, min($fw-1,(int)($x*$fw/$w)), min($fh-1,(int)($y*$fh/$h)));
                    $fr = ($v>>16)&255; $fg = ($v>>8)&255; $fb = $v&255;
                }
                $detail = min(255,(int)round(abs(.2126*($r-$fr)+.7152*($g-$fg)+.0722*($b-$fb))));
                $ks = $smoothLevel*$skin*$weights['edge'][$detail];
                $kw = $whiteLevel*$skin;
                $alpha = 1-(1-$ks)*(1-$kw);
                if ($alpha < 1/254) continue;
                // Encode a correction layer so whitening-only keeps full-resolution texture.
                $pr = (int)round(($fr*$ks*(1-$kw)+255*$kw)/$alpha);
                $pg = (int)round(($fg*$ks*(1-$kw)+255*$kw)/$alpha);
                $pb = (int)round(($fb*$ks*(1-$kw)+255*$kw)/$alpha);
                imagesetpixel($patch,$x,$y,((int)round((1-$alpha)*127)<<24)|(min(255,$pr)<<16)|(min(255,$pg)<<8)|min(255,$pb));
                $hasCorrection = true;
            }
            if (!$hasCorrection) continue;
            imagealphablending($image, true);
            imagecopyresampled($image,$patch,$left,$top,0,0,$rw,$rh,$w,$h);
            $changed = true;
            $previous[] = $face['outline'];
        } finally {
            foreach ([$src,$mask,$fine,$patch] as $resource) if ($resource instanceof GdImage) imagedestroy($resource);
        }
    }
    return $changed;
}
