<?php
/** Isolated GD/endpoint tests: php scripts/test-filter-effects.php [--benchmark] */
$root = dirname(__DIR__);

// Mark only lib/config.php as preloaded. Its normal loader creates production
// folders; these tests instead use the read-only defaults and temporary folders.
class EffectsPreloadedConfig {
    public $context;
    private $offset = 0;
    public function stream_open($path, $mode, $options, &$openedPath) {
        global $root;
        $openedPath = realpath($root . '/lib/config.php');
        return true;
    }
    public function stream_read($count) {
        $data = substr('<?php ', $this->offset, $count);
        $this->offset += strlen($data);
        return $data;
    }
    public function stream_eof() { return $this->offset >= 6; }
    public function stream_stat() { return []; }
    public function stream_set_option($option, $arg1, $arg2) { return false; }
}
function effectsConfig($directory) {
    global $root, $config;
    stream_wrapper_register('effectsconfig', EffectsPreloadedConfig::class);
    require 'effectsconfig://preloaded';
    stream_wrapper_unregister('effectsconfig');
    require $root . '/config/config.inc.php';
    foreach (['data', 'tmp', 'images', 'thumbs', 'keying', 'print', 'qrcodes'] as $folder) {
        $config['foldersAbs'][$folder] = $directory . '/' . $folder;
    }
    $config['database']['enabled'] = false;
    $config['keying']['enabled'] = false;
    $config['picture']['flip'] = 'horizontal';
    $config['picture']['rotation'] = '0';
    $config['picture']['polaroid_effect'] = false;
    $config['picture']['take_frame'] = false;
    $config['picture']['keep_original'] = true;
    $config['picture']['preserve_exif_data'] = false;
    $config['picture']['thumb_size'] = '120px';
    $config['jpeg_quality']['image'] = 100;
    $config['jpeg_quality']['thumb'] = 100;
    $config['textonpicture']['enabled'] = false;
    $config['dev']['loglevel'] = 0;
    $config['dev']['logfile'] = 'test.log';
    require_once $root . '/lib/helper.php';
}

// Child processes execute the actual API with config already loaded, allowing
// die() and HTTP status assertions without a server or production data writes.
if (($argv[1] ?? '') === '--endpoint') {
    $directory = $argv[2];
    $root = $argv[3];
    effectsConfig($directory);
    $request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $_POST = $request['post'];
    foreach ($request['overrides'] ?? [] as $section => $values) {
        $config[$section] = array_replace($config[$section] ?? [], $values);
    }
    $endpoint = $request['endpoint'] ?? 'applyEffects.php';
    $_SERVER['PHP_SELF'] = '/api/' . $endpoint;
    register_shutdown_function(function () { fwrite(STDERR, 'STATUS:' . (http_response_code() ?: 200)); });
    chdir($root . '/api');
    require $root . '/api/' . $endpoint;
    exit;
}

$checks = 0;
$failures = 0;
function effectsCheck($condition, $message) {
    global $checks, $failures;
    $checks++;
    if (!$condition) { $failures++; echo "FAIL: $message\n"; }
}
function effectsPixel($image, $x, $y) {
    $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
    return [$color['red'], $color['green'], $color['blue']];
}
function effectsNear($actual, $expected, $tolerance = 2) {
    foreach ($expected as $i => $value) {
        if (abs($actual[$i] - $value) > $tolerance) return false;
    }
    return true;
}
function effectsSolid($rgb = [40, 100, 180], $width = 120, $height = 80) {
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
    return $image;
}
function effectsEndpoint($directory, $post, $overrides = [], $endpoint = 'applyEffects.php') {
    $process = proc_open([PHP_BINARY, __FILE__, '--endpoint', $directory, $directory . '/app'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode(['post' => $post, 'overrides' => $overrides, 'endpoint' => $endpoint], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    preg_match('/STATUS:(\d+)/', $stderr, $match);
    effectsCheck($exit === 0 && !preg_match('/Warning|Fatal error|Notice|Deprecated/i', $stderr . $body), 'Endpoint exits without diagnostics (exit ' . $exit . ', status ' . ($match[1] ?? 'none') . '): ' . $stderr . ' ' . $body);
    return [(int) ($match[1] ?? 0), json_decode($body, true)];
}
function effectsSnapshot($directory) {
    $hashes = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $entry) {
        if ($entry->isFile()) $hashes[$entry->getPathname()] = hash_file('sha256', $entry->getPathname());
    }
    ksort($hashes);
    return $hashes;
}
function effectsCleanup($directory) {
    // Only the freshly created test directory is passed here.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
    }
    rmdir($directory);
}

$directory = sys_get_temp_dir() . '/photobooth-filter-effects-' . bin2hex(random_bytes(8));
mkdir($directory);
foreach (['data', 'tmp', 'images', 'thumbs', 'keying', 'print', 'qrcodes'] as $folder) mkdir($directory . '/' . $folder);
// Byte-identical backend copy gives the real endpoint its own resource directory
// for alpha PNG fixtures, with no writes to the repository's frames or config.
foreach (['app/api', 'app/lib', 'app/config', 'app/resources/img/frames'] as $folder) mkdir($directory . '/' . $folder, 0755, true);
foreach (glob($root . '/lib/*.php') as $source) copy($source, $directory . '/app/lib/' . basename($source));
foreach (['applyEffects.php', 'takePic.php'] as $endpoint) copy($root . '/api/' . $endpoint, $directory . '/app/api/' . $endpoint);
if (getenv('PHOTOBOOTH_TRACE_GD') === '1') {
    $tracePath = $directory . '/app/api/applyEffects.php';
    $traceSource = file_get_contents($tracePath);
    foreach (["require_once '../lib/config.php';", '$imageResource = @imagecreatefromjpeg', 'list($imageResource, $imageModified) = editSingleImage', 'list($imageResource, $imageModified) = addTextToImage', '$thumbResource = resizeImage', 'if (!@imagejpeg($thumbResource', 'compressImage($config', 'clearPhotoCaches($image)'] as $number => $needle) {
        $traceSource = str_replace($needle, 'fwrite(STDERR, "GD stage ' . $number . '\\n");' . "\n" . $needle, $traceSource);
    }
    file_put_contents($tracePath, $traceSource);
}
copy($root . '/config/config.inc.php', $directory . '/app/config/config.inc.php');
$frame = imagecreatetruecolor(120, 80);
imagealphablending($frame, false); imagesavealpha($frame, true);
imagefill($frame, 0, 0, imagecolorallocatealpha($frame, 0, 0, 0, 127));
imagefilledrectangle($frame, 0, 0, 20, 20, imagecolorallocatealpha($frame, 230, 30, 80, 0));
imagepng($frame, $directory . '/app/resources/img/frames/Session Frame.png');
imagefilledrectangle($frame, 0, 0, 20, 20, imagecolorallocatealpha($frame, 20, 200, 50, 0));
imagepng($frame, $directory . '/app/resources/img/frames/current_active_frame.png');
imagedestroy($frame);
$activeFrameHash = hash_file('sha256', $directory . '/app/resources/img/frames/current_active_frame.png');
$notPng = effectsSolid();
imagejpeg($notPng, $directory . '/app/resources/img/frames/Not PNG.png', 100);
imagedestroy($notPng);
try {
    effectsConfig($directory);
    require_once $root . '/lib/filter.php';
    require_once $root . '/lib/applyEffects.php';
    require_once $root . '/lib/applyFrame.php';
    require_once $root . '/lib/resize.php';
    require_once $root . '/lib/polaroid.php';

    // Hand-calculated sRGB fixtures: SVG sepia matrix; CSS contrast about .5;
    // CSS multiplicative brightness; Rec.709 gray; W3C soft-light piecewise
    // polynomial/sqrt. Literal outputs catch swapped blend/filter order,
    // incorrect luminance weights, additive brightness and missed clipping.
    $pixels = [[0, 0, 0], [255, 255, 255], [40, 100, 180], [180, 60, 20], [250, 220, 150], [18, 36, 58]];
    $expected = [
        'cssgram-inkwell' => [[0,0,0], [255,255,255], [106,106,106], [95,95,95], [250,250,250], [30,30,30]],
        'cssgram-reyes' => [[19,19,19], [236,236,236], [106,132,165], [173,107,74], [235,235,191], [62,70,77]],
        'cssgram-moon' => [[54,54,54], [255,255,255], [117,117,117], [105,105,105], [255,255,255], [55,55,55]],
    ];
    foreach ($expected as $filter => $outputs) {
        effectsCheck(isset(AVAILABLE_FILTERS[$filter]), "$filter is selectable");
        foreach ($pixels as $i => $rgb) {
            $image = effectsSolid($rgb, 1, 1);
            applyFilter($filter, $image);
            effectsCheck(effectsNear(effectsPixel($image, 0, 0), $outputs[$i]), "$filter maps " . json_encode($rgb) . ' to ' . json_encode($outputs[$i]) . ', got ' . json_encode(effectsPixel($image, 0, 0)));
            imagedestroy($image);
        }
        $image = effectsSolid([40,100,180], 1, 1);
        imagealphablending($image, false);
        imagesetpixel($image, 0, 0, imagecolorallocatealpha($image, 40, 100, 180, 63));
        applyFilter($filter, $image);
        effectsCheck(imagecolorsforindex($image, imagecolorat($image, 0, 0))['alpha'] === 63, "$filter preserves source alpha");
        imagedestroy($image);
    }
    $image = effectsSolid();
    applyFilter('plain', $image);
    effectsCheck(effectsPixel($image, 0, 0) === [40,100,180], 'Legacy plain filter remains unchanged');
    imagedestroy($image);

    $polygon = ['points' => [[.05,.2], [.35,.2], [.35,.8], [.05,.8]], 'color' => 'ink'];
    $map = ['shot.jpg' => [$polygon]];
    $helper = $root . '/lib/face-effects.php';
    effectsCheck(is_file($helper), 'Face helper supports validated polygons');
    if (is_file($helper)) {
        require_once $helper;
        effectsCheck(parseFaceEffects(null) === [] && parseFaceEffects('{}') === [], 'Omitted payload and empty object are valid');
        effectsCheck(parseFaceEffects(json_encode($map)) === $map, 'Valid normalized polygon survives parsing');
        $invalid = [[], '', '{', '[]', 'null', 'true', '42', '{"shot.jpg":{}}', '{"shot.jpg":[[]]}', '{"shot.jpg":[{"color":"ink"}]}', '{"shot.jpg":[{"points":[[0,0],[1,0]],"color":"ink"}]}'];
        foreach (['../shot.jpg', '..\\shot.jpg', '/shot.jpg', '.shot.jpg', 'shot.png', "shot\0.jpg", 'shot.jpg/evil.jpg', 'CON.jpg'] as $name) $invalid[] = json_encode([$name => [$polygon]]);
        foreach (['blue', null, [], 1] as $color) $invalid[] = json_encode(['shot.jpg' => [array_replace($polygon, ['color' => $color])]]);
        foreach ([['0', 0], [true, 0], [null, 0], [-1.01, 0], [2.01, 0], [0, 0, 0], ['x' => 0, 'y' => 0]] as $point) {
            $bad = $polygon; $bad['points'][0] = $point;
            $invalid[] = json_encode(['shot.jpg' => [$bad]]);
        }
        $invalid[] = '{"shot.jpg":[{"points":[[1e999,0],[0,1],[1,1]],"color":"ink"}]}';
        $invalid[] = json_encode(['shot.jpg' => [array_replace($polygon, ['points' => array_fill(0, 33, [0,0])])]]);
        $invalid[] = json_encode(['shot.jpg' => array_fill(0, 33, $polygon)]);
        $invalid[] = json_encode(array_fill_keys(array_map(fn($n) => "shot$n.jpg", range(0,12)), []));
        $invalid[] = str_repeat(' ', 65537) . '{}';
        foreach ($invalid as $raw) {
            $rejected = false;
            try { parseFaceEffects($raw); } catch (InvalidArgumentException $e) { $rejected = true; }
            effectsCheck($rejected, 'Malformed or oversized face payload rejected: ' . substr(json_encode($raw), 0, 110));
        }
        $boundary = ['points' => [[-1,-1],[2,-1],[2,2]], 'color' => 'white'];
        $maximum = array_fill_keys(array_map(fn($n) => "shot$n.jpg", range(0,11)), [$boundary]);
        effectsCheck(count(parseFaceEffects(json_encode($maximum))) === 12, 'Maximum files and coordinate bounds accepted');
        effectsCheck(count(parseFaceEffects(json_encode(['shot.jpg' => array_fill(0,32,$boundary)]))['shot.jpg']) === 32, 'Maximum polygons accepted');
        effectsCheck(count(parseFaceEffects(json_encode(['shot.jpg' => [array_replace($boundary, ['points' => array_fill(0,32, [-1,2])])]]))['shot.jpg'][0]['points']) === 32, 'Maximum points accepted');
        effectsCheck(parseFaceEffects(str_pad('{}', 65536)) === [], 'Exactly 65536 bytes accepted');
        foreach (['ink' => [18,36,58], 'pink' => [242,148,172], 'white' => [255,255,255]] as $color => $rgb) {
            $image = effectsSolid();
            applyFaceEffects($image, [array_replace($polygon, ['color' => $color])]);
            effectsCheck(effectsPixel($image, 20, 40) === $rgb && effectsPixel($image, 100, 40) === [40,100,180], "$color polygon is filled and bounded");
            imagedestroy($image);
        }
        $image = effectsSolid();
        [$image, $modified] = editSingleImage($config, $image, 'cssgram-reyes', [$polygon]);
        effectsCheck($modified && effectsNear(effectsPixel($image, 100, 40), [62,70,77]), 'Face geometry is filtered after raw horizontal flip');
        effectsCheck(effectsNear(effectsPixel($image, 20, 40), [106,132,165]), 'Flip moves geometry away from original side');
        imagedestroy($image);
        $plainConfig = $config; $plainConfig['picture']['flip'] = 'off';
        $image = effectsSolid();
        [$image, $modified] = editSingleImage($plainConfig, $image, false, [$polygon]);
        effectsCheck($modified && effectsPixel($image, 20, 40) === [18,36,58], 'Face-only edit marks image modified so compression persists it');
        imagedestroy($image);
    }

    $rawImage = effectsSolid();
    imagejpeg($rawImage, $directory . '/tmp/shot.jpg', 100);
    imagedestroy($rawImage);
    $before = effectsSnapshot($directory);
    foreach (['[]', '{', [], str_repeat(' ',65537) . '{}', json_encode(['unusedfile.jpg' => [$polygon]])] as $raw) {
        [$status, $body] = effectsEndpoint($directory, ['file' => 'shot.jpg', 'style' => 'photo', 'filter' => 'plain', 'faceEffects' => $raw]);
        effectsCheck($status === 400 && isset($body['error']), 'Invalid face JSON or unrelated file returns HTTP 400 JSON');
        effectsCheck(effectsSnapshot($directory) === $before, 'Invalid request touches no image, thumbnail, copy or log');
    }
    foreach (['filter', 'plain', '', ['photo']] as $badStyle) {
        $before = effectsSnapshot($directory);
        [$status, $body] = effectsEndpoint($directory, ['file' => 'shot.jpg', 'style' => $badStyle, 'filter' => 'plain']);
        effectsCheck($status === 400 && isset($body['error']), 'Style outside photo/chroma rejected');
        effectsCheck(effectsSnapshot($directory) === $before, 'Invalid style performs no writes');
    }
    foreach (['../shot.jpg', '..\\shot.jpg', 'shot.png', ['shot.jpg']] as $badFile) {
        $before = effectsSnapshot($directory);
        [$status, $body] = effectsEndpoint($directory, ['file' => $badFile, 'style' => 'photo', 'filter' => 'plain']);
        effectsCheck($status === 400 && isset($body['error']), 'Unsafe raw filename rejected at endpoint');
        effectsCheck(effectsSnapshot($directory) === $before, 'Invalid raw filename performs no writes');
    }
    $badPolygon = array_replace($polygon, ['color' => 'blue']);
    $before = effectsSnapshot($directory);
    [$status, $body] = effectsEndpoint($directory, ['file' => 'shot.jpg', 'style' => 'photo', 'filter' => 'plain', 'faceEffects' => json_encode(['shot.jpg' => [$polygon, $badPolygon]])]);
    effectsCheck($status === 400 && isset($body['error']) && effectsSnapshot($directory) === $before, 'Invalid later polygon rejects entire request before any geometry or writes');
    [$status, $body] = effectsEndpoint($directory, ['file' => 'shot.jpg', 'style' => 'photo', 'filter' => 'cssgram-reyes', 'faceEffects' => json_encode($map)]);
    effectsCheck($status === 200 && ($body['file'] ?? '') === 'shot.jpg', 'Single endpoint succeeds');
    if (is_file($directory . '/images/shot.jpg')) {
        $saved = imagecreatefromjpeg($directory . '/images/shot.jpg');
        effectsCheck(effectsNear(effectsPixel($saved, 100, 40), [62,70,77], 4), 'Single endpoint persists filtered flipped face');
        effectsCheck(effectsNear(effectsPixel($saved, 20, 40), [106,132,165], 4), 'Single endpoint persists CSSgram on background');
        imagedestroy($saved);
    } else effectsCheck(false, 'Single endpoint writes photo');

    foreach (['../Session Frame.png', '..\\Session Frame.png', '/Session Frame.png', 'current_active_frame.png', 'missing.png', 'shot.jpg', 'Not PNG.png', ['Session Frame.png'], "bad\0.png"] as $invalidFrame) {
        $before = effectsSnapshot($directory);
        [$status, $body] = effectsEndpoint($directory, ['file' => 'shot.jpg', 'style' => 'photo', 'filter' => 'plain', 'frame' => $invalidFrame]);
        effectsCheck($status === 400 && isset($body['error']), 'Invalid session frame returns HTTP 400 JSON');
        effectsCheck(effectsSnapshot($directory) === $before, 'Invalid frame touches no images or copies');
    }
    [$status, $body] = effectsEndpoint($directory, ['file' => 'shot.jpg', 'style' => 'photo', 'filter' => 'cssgram-reyes', 'faceEffects' => json_encode($map), 'frame' => 'Session Frame.png']);
    effectsCheck($status === 200, 'Session frame photo succeeds');
    $saved = imagecreatefromjpeg($directory . '/images/shot.jpg');
    effectsCheck(effectsNear(effectsPixel($saved, 10, 10), [230,30,80], 4), 'Selected alpha frame applied after flip/color despite take_frame false');
    effectsCheck(effectsNear(effectsPixel($saved, 100, 40), [62,70,77], 4), 'Transparent frame leaves face visible');
    imagedestroy($saved);
    [$status, $body] = effectsEndpoint($directory, ['file' => 'shot.jpg', 'style' => 'photo', 'filter' => 'plain']);
    $saved = imagecreatefromjpeg($directory . '/images/shot.jpg');
    effectsCheck($status === 200 && effectsNear(effectsPixel($saved, 10, 10), [40,100,180], 4), 'Omitted frame/effects leave subsequent legacy request unchanged');
    imagedestroy($saved);

    $image = effectsSolid(); imagejpeg($image, $directory . '/tmp/disposable.jpg', 100); imagedestroy($image);
    $disposableRequest = ['file' => 'disposable.jpg', 'style' => 'photo', 'filter' => 'plain', 'faceEffects' => json_encode(['disposable.jpg' => [$polygon]])];
    [$status, $body] = effectsEndpoint($directory, $disposableRequest, ['picture' => ['keep_original' => false, 'flip' => 'off']]);
    $saved = imagecreatefromjpeg($directory . '/images/disposable.jpg');
    effectsCheck($status === 200 && !is_file($directory . '/tmp/disposable.jpg') && effectsNear(effectsPixel($saved, 20, 40), [18,36,58], 4), 'Face-only request persists with keep_original false and no filter/flip');
    imagedestroy($saved);
    $finalHash = hash_file('sha256', $directory . '/images/disposable.jpg');
    [$status, $body] = effectsEndpoint($directory, $disposableRequest, ['picture' => ['keep_original' => false]]);
    effectsCheck(isset($body['error']) && hash_file('sha256', $directory . '/images/disposable.jpg') === $finalHash, 'Repeat request without raw input cannot re-filter saved image');

    $image = effectsSolid(); imagejpeg($image, $directory . '/tmp/chroma.jpg', 100); imagedestroy($image);
    [$status, $body] = effectsEndpoint($directory, ['file' => 'chroma.jpg', 'style' => 'chroma', 'filter' => 'cssgram-moon', 'faceEffects' => json_encode(['chroma.jpg' => [$polygon]])], ['live_keying' => ['show_all' => true], 'keying' => ['size' => '120px']]);
    $saved = imagecreatefromjpeg($directory . '/images/chroma.jpg');
    effectsCheck($status === 200 && effectsNear(effectsPixel($saved, 100, 40), [55,55,55], 4), 'Chroma style retains the same geometry/filter contract');
    imagedestroy($saved);

    // Removed modes must fail before touching even an existing capture.
    for ($i = 0; $i < 4; $i++) {
        $image = effectsSolid(); imagejpeg($image, $directory . "/tmp/group-$i.jpg", 100); imagedestroy($image);
    }
    $legacyCollage = ['collage' => ['limit' => 4, 'layout' => '2+2', 'resolution' => '50dpi', 'take_frame' => 'off', 'background' => '', 'placeholder' => false, 'keep_single_images' => false], 'textoncollage' => ['enabled' => false]];
    $before = effectsSnapshot($directory);
    [$status, $body] = effectsEndpoint($directory, ['file' => 'group.jpg', 'style' => 'collage', 'filter' => 'plain'], $legacyCollage);
    effectsCheck($status === 400 && isset($body['error']), 'Removed collage processing returns HTTP 400 even with legacy config');
    effectsCheck(effectsSnapshot($directory) === $before, 'Removed processing mode performs no writes');
    $before = effectsSnapshot($directory);
    [$status, $body] = effectsEndpoint($directory, ['file' => 'shot.jpg', 'style' => 'collage', 'collageNumber' => 0, 'canvasimg' => 'data:image/jpeg;base64,' . base64_encode(file_get_contents($directory . '/tmp/shot.jpg'))], ['preview' => ['mode' => 'device_cam', 'camTakesPic' => true, 'flip' => 'off'], 'dev' => ['demo_images' => false]] + $legacyCollage, 'takePic.php');
    effectsCheck($status === 400 && isset($body['error']), 'Removed collage capture returns HTTP 400');
    effectsCheck(effectsSnapshot($directory) === $before, 'Rejected capture never renames existing raw input or creates a shot');
    effectsCheck(hash_file('sha256', $directory . '/app/resources/img/frames/current_active_frame.png') === $activeFrameHash, 'Session-selected frame never changes shared current active frame');

    if (in_array('--benchmark', $argv, true)) {
        foreach ([[1280,720], [1800,1200]] as [$width,$height]) {
            foreach (array_keys($expected) as $filter) {
                $image = effectsSolid([40,100,180], $width, $height);
                $start = hrtime(true); applyFilter($filter, $image);
                printf("BENCH %s %dx%d: %.3f s\n", $filter, $width, $height, (hrtime(true)-$start)/1e9);
                imagedestroy($image);
            }
        }
    }
} finally {
    effectsCleanup($directory);
}
echo "$checks checks, $failures failures\n";
exit($failures ? 1 : 0);
