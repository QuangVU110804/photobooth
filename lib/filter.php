<?php

define('FILTER_PLAIN', 'plain');
define('FILTER_ANTIQUE', 'antique');
define('FILTER_AQUA', 'aqua');
define('FILTER_BLUE', 'blue');
define('FILTER_BLUR', 'blur');
define('FILTER_COLOR', 'color');
define('FILTER_COOL', 'cool');
define('FILTER_EDGE', 'edge');
define('FILTER_EMBOSS', 'emboss');
define('FILTER_EVERGLOW', 'everglow');
define('FILTER_GRAYSCALE', 'grayscale');
define('FILTER_GREEN', 'green');
define('FILTER_MEAN', 'mean');
define('FILTER_NEGATE', 'negate');
define('FILTER_PINK', 'pink');
define('FILTER_PIXELATE', 'pixelate');
define('FILTER_RED', 'red');
define('FILTER_RETRO', 'retro');
define('FILTER_SELECTIVE_BLUR', 'selective-blur');
define('FILTER_SEPIA_LIGHT', 'sepia-light');
define('FILTER_SEPIA_DARK', 'sepia-dark');
define('FILTER_SMOOTH', 'smooth');
define('FILTER_SUMMER', 'summer');
define('FILTER_VINTAGE', 'vintage');
define('FILTER_WASHED', 'washed');
define('FILTER_YELLOW', 'yellow');
define('FILTER_CSSGRAM_INKWELL', 'cssgram-inkwell');
define('FILTER_CSSGRAM_REYES', 'cssgram-reyes');
define('FILTER_CSSGRAM_MOON', 'cssgram-moon');

define('AVAILABLE_FILTERS', [
    FILTER_PLAIN => 'None',
    FILTER_ANTIQUE => 'Antique',
    FILTER_AQUA => 'Aqua',
    FILTER_BLUE => 'Blue',
    FILTER_BLUR => 'Blur',
    FILTER_COLOR => 'Color',
    FILTER_COOL => 'Cool',
    FILTER_EDGE => 'Edge',
    FILTER_EMBOSS => 'Emboss',
    FILTER_EVERGLOW => 'Everglow',
    FILTER_GRAYSCALE => 'Grayscale',
    FILTER_GREEN => 'Green',
    FILTER_MEAN => 'Mean',
    FILTER_NEGATE => 'Negate',
    FILTER_PINK => 'Pink',
    FILTER_PIXELATE => 'Pixelate',
    FILTER_RED => 'Red',
    FILTER_RETRO => 'Retro',
    FILTER_SELECTIVE_BLUR => 'Selective blur',
    FILTER_SEPIA_LIGHT => 'Sepia-light',
    FILTER_SEPIA_DARK => 'Sepia-dark',
    FILTER_SMOOTH => 'Smooth',
    FILTER_SUMMER => 'Summer',
    FILTER_VINTAGE => 'Vintage',
    FILTER_WASHED => 'Washed',
    FILTER_YELLOW => 'Yellow',
    FILTER_CSSGRAM_INKWELL => 'CSSgram Inkwell',
    FILTER_CSSGRAM_REYES => 'CSSgram Reyes',
    FILTER_CSSGRAM_MOON => 'CSSgram Moon',
]);

function applyFilter($imgfilter, $sourceResource) {
    switch ($imgfilter) {
        case 'cssgram-inkwell':
        case 'cssgram-reyes':
        case 'cssgram-moon':
            applyCssgramFilter($imgfilter, $sourceResource);
            break;
        case 'antique':
            imagefilter($sourceResource, IMG_FILTER_BRIGHTNESS, 0);
            imagefilter($sourceResource, IMG_FILTER_CONTRAST, -30);
            imagefilter($sourceResource, IMG_FILTER_COLORIZE, 75, 50, 25);
            break;
        case 'aqua':
            imagefilter($sourceResource, IMG_FILTER_COLORIZE, 0, 70, 0, 30);
            break;
        case 'blue':
            imagefilter($sourceResource, IMG_FILTER_COLORIZE, 0, 0, 100);
            break;
        case 'blur':
            $blur = 5;
            for ($i = 0; $i < $blur; $i++) {
                // each 5th time apply '_FILTER_SMOOTH' with 'level of smoothness' set to -7
                if ($i % 5 == 0) {
                    imagefilter($sourceResource, IMG_FILTER_SMOOTH, -7);
                }
                imagefilter($sourceResource, IMG_FILTER_GAUSSIAN_BLUR);
            }
            break;
        case 'color':
            imagefilter($sourceResource, IMG_FILTER_CONTRAST, -40);
            break;
        case 'cool':
            imagefilter($sourceResource, IMG_FILTER_MEAN_REMOVAL);
            imagefilter($sourceResource, IMG_FILTER_CONTRAST, -50);
            break;
        case 'edge':
            $emboss = [[1, 1, 1], [1, -7, 1], [1, 1, 1]];
            imageconvolution($sourceResource, $emboss, 1, 0);
            break;
        case 'emboss':
            $emboss = [[-2, -1, 0], [-1, 1, 1], [0, 1, 2]];
            imageconvolution($sourceResource, $emboss, 1, 0);
            break;
        case 'everglow':
            imagefilter($sourceResource, IMG_FILTER_BRIGHTNESS, -30);
            imagefilter($sourceResource, IMG_FILTER_CONTRAST, -5);
            imagefilter($sourceResource, IMG_FILTER_COLORIZE, 30, 30, 0);
            break;
        case 'grayscale':
            imagefilter($sourceResource, IMG_FILTER_GRAYSCALE);
            break;
        case 'green':
            imagefilter($sourceResource, IMG_FILTER_COLORIZE, 0, 100, 0);
            break;
        case 'mean':
            imagefilter($sourceResource, IMG_FILTER_MEAN_REMOVAL);
            break;
        case 'negate':
            imagefilter($sourceResource, IMG_FILTER_NEGATE);
            break;
        case 'pink':
            imagefilter($sourceResource, IMG_FILTER_COLORIZE, 50, -50, 50);
            break;
        case 'pixelate':
            imagefilter($sourceResource, IMG_FILTER_PIXELATE, 20);
            break;
        case 'red':
            imagefilter($sourceResource, IMG_FILTER_COLORIZE, 100, 0, 0);
            break;
        case 'retro':
            imagefilter($sourceResource, IMG_FILTER_GRAYSCALE);
            imagefilter($sourceResource, IMG_FILTER_COLORIZE, 100, 25, 25, 50);
            break;
        case 'selective-blur':
            $blur = 5;
            for ($i = 0; $i <= $blur; $i++) {
                imagefilter($sourceResource, IMG_FILTER_SELECTIVE_BLUR);
            }
            break;
        case 'sepia-dark':
            imagefilter($sourceResource, IMG_FILTER_GRAYSCALE);
            imagefilter($sourceResource, IMG_FILTER_BRIGHTNESS, -30);
            imagefilter($sourceResource, IMG_FILTER_COLORIZE, 90, 55, 30);
            break;
        case 'sepia-light':
            imagefilter($sourceResource, IMG_FILTER_GRAYSCALE);
            imagefilter($sourceResource, IMG_FILTER_COLORIZE, 90, 60, 40);
            break;
        case 'smooth':
            imagefilter($sourceResource, IMG_FILTER_SMOOTH, 2);
            break;
        case 'summer':
            imagefilter($sourceResource, IMG_FILTER_COLORIZE, 0, 150, 0, 50);
            imagefilter($sourceResource, IMG_FILTER_NEGATE);
            imagefilter($sourceResource, IMG_FILTER_COLORIZE, 25, 50, 0, 50);
            imagefilter($sourceResource, IMG_FILTER_NEGATE);
            break;
        case 'vintage':
            imagefilter($sourceResource, IMG_FILTER_BRIGHTNESS, 10);
            imagefilter($sourceResource, IMG_FILTER_GRAYSCALE);
            imagefilter($sourceResource, IMG_FILTER_COLORIZE, 40, 10, -15);
            break;
        case 'washed':
            imagefilter($sourceResource, IMG_FILTER_BRIGHTNESS, 30);
            imagefilter($sourceResource, IMG_FILTER_NEGATE);
            imagefilter($sourceResource, IMG_FILTER_COLORIZE, -50, 0, 20, 50);
            imagefilter($sourceResource, IMG_FILTER_NEGATE);
            imagefilter($sourceResource, IMG_FILTER_BRIGHTNESS, 10);
            break;
        case 'yellow':
            imagefilter($sourceResource, IMG_FILTER_COLORIZE, 100, 100, -100);
            break;
        default:
            break;
    }
}

/** CSSgram sRGB recipes, with blends inside the element's filter group.
 * https://github.com/una/CSSgram/tree/master/source/scss
 * Lookup tables replace per-pixel blend curves and matrix multiplication.
 * Intermediate channels are quantized to 8 bits, within ~1 channel of CSS.
 */
function applyCssgramFilter($filter, GdImage $image): void {
    static $tables = [];
    if (!isset($tables[$filter])) {
        $isReyes = $filter === 'cssgram-reyes';
        $isMoon = $filter === 'cssgram-moon';
        $amount = $isReyes ? .22 : .3;
        $sepia = [[.393,.769,.189], [.349,.686,.168], [.272,.534,.131]];
        $matrix = array_fill(0, 9, []);
        $tone = [];
        $moon = [];
        for ($value = 0; $value < 256; $value++) {
            $base = $value / 255;
            // All recipe soft-light sources are > .5. W3C D(Cb) has a
            // polynomial in shadows and sqrt elsewhere (not overlay blend).
            $d = $base <= .25 ? ((16 * $base - 12) * $base + 4) * $base : sqrt($base);
            if ($isMoon) {
                $moon[$value] = max(56, 255 * ($base + (2 * 160 / 255 - 1) * ($d - $base)));
            } else {
                foreach ([239,205,173] as $column => $overlay) {
                    $input = $isReyes ? 255 * ($base + .5 * (2 * $overlay / 255 - 1) * ($d - $base)) : $value;
                    for ($row = 0; $row < 3; $row++) {
                        $coefficient = $amount * $sepia[$row][$column] + ($row === $column ? 1 - $amount : 0);
                        $matrix[$row * 3 + $column][$value] = $input * $coefficient;
                    }
                }
            }
            $tone[$value] = $isReyes
                ? (min(255, $value * 1.1) - 127.5) * .85 + 127.5
                : min(255, max(0, ($value - 127.5) * 1.1 + 127.5) * 1.1);
        }
        $tables[$filter] = [$matrix, $tone, $moon];
    }
    [$matrix, $tone, $moon] = $tables[$filter];
    $width = imagesx($image);
    $height = imagesy($image);
    if (!imageistruecolor($image)) imagepalettetotruecolor($image);
    imagealphablending($image, false);
    if ($filter === 'cssgram-moon') {
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = imagecolorat($image, $x, $y);
                $gray = (int) ($tone[(int) (.2126 * $moon[($pixel >> 16) & 255] + .7152 * $moon[($pixel >> 8) & 255] + .0722 * $moon[$pixel & 255] + .5)] + .5);
                imagesetpixel($image, $x, $y, ($pixel & 0x7f000000) | ($gray << 16) | ($gray << 8) | $gray);
            }
        }
    } else {
        [$rr,$rg,$rb,$gr,$gg,$gb,$br,$bg,$bb] = $matrix;
        $isReyes = $filter === 'cssgram-reyes';
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = imagecolorat($image, $x, $y);
                $r = ($pixel >> 16) & 255; $g = ($pixel >> 8) & 255; $b = $pixel & 255;
                // Sepia clips before the next CSS primitive. Tone LUT keeps
                // contrast/brightness order and highlight clipping exact.
                $red = (int) ($rr[$r] + $rg[$g] + $rb[$b] + .5);
                $green = (int) ($gr[$r] + $gg[$g] + $gb[$b] + .5);
                $blue = (int) ($br[$r] + $bg[$g] + $bb[$b] + .5);
                $red = $tone[$red < 256 ? $red : 255];
                $green = $tone[$green < 256 ? $green : 255];
                $blue = $tone[$blue < 256 ? $blue : 255];
                if ($isReyes) {
                    // CSS saturate(.75), using its specified matrix weights.
                    $luma = .213 * $red + .715 * $green + .072 * $blue;
                    $red = (int) (.75 * $red + .25 * $luma + .5);
                    $green = (int) (.75 * $green + .25 * $luma + .5);
                    $blue = (int) (.75 * $blue + .25 * $luma + .5);
                } else {
                    $red = $green = $blue = (int) (.2126 * $red + .7152 * $green + .0722 * $blue + .5);
                }
                imagesetpixel($image, $x, $y, ($pixel & 0x7f000000) | ($red << 16) | ($green << 8) | $blue);
            }
        }
    }
    imagealphablending($image, true);
}
