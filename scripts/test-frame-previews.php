<?php
/** Real GD, cache and endpoint regression checks; never writes production frames. */
require_once dirname(__DIR__) . '/lib/frames.php';

$fixture = sys_get_temp_dir() . '/photobooth-frame-tests-' . bin2hex(random_bytes(8));
$checks = 0;
function frameCheck($condition, $label) {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($label);
}
function frameEndpoint($endpoint, $get = [], $post = [], $server = []) {
    global $fixture;
    $code = '$_GET=' . var_export($get, true) . ';$_POST=' . var_export($post, true) . ';'
        . '$_SERVER=array_replace($_SERVER,' . var_export($server, true) . ');'
        . 'register_shutdown_function(function(){fwrite(STDERR,"STATUS:".(http_response_code()?:200));});'
        . 'require ' . var_export($fixture . '/app/api/' . $endpoint, true) . ';';
    $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    frameCheck($exit === 0 && !preg_match('/Warning|Fatal error|Notice|Deprecated/i', $stderr), 'Endpoint has no PHP diagnostics: ' . $stderr);
    preg_match('/STATUS:(\d+)/', $stderr, $status);
    return [(int) ($status[1] ?? 0), $body];
}
try {
    $frames = $fixture . '/app/resources/img/frames';
    foreach ([$frames, $fixture . '/app/api', $fixture . '/app/lib'] as $folder) mkdir($folder, 0755, true);
    $root = dirname(__DIR__);
    foreach (['getFrames.php', 'setFrame.php', 'framePreview.php'] as $endpoint) copy($root . '/api/' . $endpoint, $fixture . '/app/api/' . $endpoint);
    copy($root . '/lib/frames.php', $fixture . '/app/lib/frames.php');
    copy($root . '/lib/multishot-frames.php', $fixture . '/app/lib/multishot-frames.php');
    file_put_contents($fixture . '/app/lib/config.php', '<?php $config=["foldersAbs"=>["tmp"=>' . var_export($fixture . '/cache', true) . ']];');

    $image = imagecreatetruecolor(1200, 800);
    imagealphablending($image, false); imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, 0, 0, 399, 799, imagecolorallocatealpha($image, 240, 40, 80, 0));
    imagefilledrectangle($image, 400, 0, 799, 799, imagecolorallocatealpha($image, 20, 180, 90, 64));
    $name = 'Khung ảnh #1 + bạn.png';
    $source = $frames . '/' . $name;
    imagepng($image, $source);
    copy($source, $frames . '/current_active_frame.png');
    file_put_contents($frames . '/notes.txt', 'not a frame');
    $sourceHash = hash_file('sha256', $source);
    frameCheck(listPhotoFrames($frames) === [$name], 'List keeps Unicode names and excludes the active copy and non-PNG files');
    frameCheck(resolvePhotoFrame($frames, $name) === realpath($source), 'Resolve returns the actual selected file');
    foreach (['../outside.png', '..\\outside.png', '/absolute.png', 'current_active_frame.png', 'CURRENT_ACTIVE_FRAME.PNG', 'missing.png', ['a.png'], "bad\0.png", ''] as $bad) {
        frameCheck(resolvePhotoFrame($frames, $bad) === null, 'Unsafe or unavailable frame is rejected');
    }
    frameCheck(listPhotoFrames($fixture . '/missing') === [], 'Missing directory gives an empty frame list');

    $preview = photoFramePreview($source, $fixture . '/cache/frame-previews');
    frameCheck(getimagesize($preview)[0] === 360 && getimagesize($preview)[1] === 240, 'Preview bounds and aspect ratio are preserved');
    $decoded = imagecreatefrompng($preview);
    foreach ([[30, 0, 240, 40, 80], [180, 64, 20, 180, 90], [330, 127, 0, 0, 0]] as [$x, $alpha, $r, $g, $b]) {
        $pixel = imagecolorsforindex($decoded, imagecolorat($decoded, $x, 100));
        frameCheck($pixel === ['red' => $r, 'green' => $g, 'blue' => $b, 'alpha' => $alpha], 'Preview preserves opaque, semitransparent and transparent pixels');
    }
    touch($preview, 1000000000); clearstatcache(true, $preview);
    frameCheck(photoFramePreview($source, $fixture . '/cache/frame-previews') === $preview && filemtime($preview) === 1000000000, 'Warm cache reuses bytes without rewriting the PNG');
    frameCheck(hash_file('sha256', $source) === $sourceHash, 'Preview generation leaves the original PNG untouched');
    touch($source, time() + 5); clearstatcache(true, $source);
    $updated = photoFramePreview($source, $fixture . '/cache/frame-previews');
    frameCheck($updated !== $preview && is_file($updated), 'Replacing a frame invalidates its cached preview');

    $small = imagecreatetruecolor(80, 120); imagepng($small, $frames . '/small.png');
    $smallPreview = photoFramePreview($frames . '/small.png', $fixture . '/cache/frame-previews');
    frameCheck(getimagesize($smallPreview)[0] === 80 && getimagesize($smallPreview)[1] === 120, 'Small frames are not upscaled');
    imagejpeg($small, $frames . '/fake.png');
    try {
        photoFramePreview($frames . '/fake.png', $fixture . '/cache/frame-previews');
        frameCheck(false, 'JPEG disguised as PNG must be rejected');
    } catch (InvalidArgumentException $error) { frameCheck(true, 'Disguised PNG is rejected before writing a preview'); }

    [$status, $body] = frameEndpoint('framePreview.php', ['frame' => $name]);
    $fromEndpoint = imagecreatefromstring($body);
    frameCheck($status === 200 && $fromEndpoint && imagesx($fromEndpoint) === 360, 'Actual preview endpoint serves a decodable small PNG');
    foreach (['../notes.txt', ['small.png'], 'missing.png', 'fake.png', 'current_active_frame.png'] as $bad) {
        [$status, $body] = frameEndpoint('framePreview.php', ['frame' => $bad]);
        frameCheck(in_array($status, [400, 404], true) && isset(json_decode($body, true)['error']), 'Invalid preview request is a clean JSON error');
    }
    [$status, $body] = frameEndpoint('setFrame.php', [], ['frame' => $name]);
    frameCheck(json_decode($body, true)['status'] === 'success' && hash_file('sha256', $frames . '/current_active_frame.png') === $sourceHash, 'Selection still copies the original full-resolution frame');
    [$status, $body] = frameEndpoint('setFrame.php', [], ['frame' => 'fake.png']);
    frameCheck(json_decode($body, true)['status'] === 'error' && hash_file('sha256', $frames . '/current_active_frame.png') === $sourceHash, 'Invalid PNG selection preserves the active frame');
    [$status, $body] = frameEndpoint('getFrames.php');
    frameCheck($status === 200 && in_array($name, json_decode($body, true), true), 'Frame list API keeps its filename-array contract');
    [$status, $body] = frameEndpoint('getFrames.php', ['grouped' => '1']);
    frameCheck($status === 200 && json_decode($body, true) === [
        ['label' => 'Khác', 'frames' => [$name, 'fake.png', 'small.png']],
    ], 'Root filenames are grouped under Khác');

    foreach (['Studio/Sub', '20-11', 'Sinh nhật', 'Thư mục trống'] as $folder) mkdir($frames . '/' . $folder, 0755, true);
    $studioFrame = 'Studio/' . $name;
    $eventFrame = '20-11/Mẫu mới #2.PNG';
    foreach ([$studioFrame, 'Studio/Mẫu mới.png', $eventFrame, 'Sinh nhật/Khung mới.png', 'Studio/current_active_frame.png', 'Studio/Sub/Hidden.png'] as $path) {
        copy($frames . '/small.png', $frames . '/' . $path);
    }
    [$status, $body] = frameEndpoint('getFrames.php', ['grouped' => '1']);
    frameCheck($status === 200 && json_decode($body, true) === [
        ['label' => '20/11', 'frames' => [$eventFrame]],
        ['label' => 'Sinh nhật', 'frames' => ['Sinh nhật/Khung mới.png']],
        ['label' => 'Studio', 'frames' => [$studioFrame, 'Studio/Mẫu mới.png']],
        ['label' => 'Khác', 'frames' => [$name, 'fake.png', 'small.png']],
    ], 'Nonempty folders set their topic labels, with 20-11 shown as 20/11');
    [, $body] = frameEndpoint('getFrames.php');
    frameCheck(json_decode($body, true) === [$name, 'fake.png', 'small.png'], 'The flat route still advertises only root filenames');
    [$status, $body] = frameEndpoint('framePreview.php', ['frame' => $eventFrame]);
    $fromTopic = imagecreatefromstring($body);
    frameCheck($status === 200 && $fromTopic && imagesx($fromTopic) === 80, 'Topic frames support preview requests with Unicode and uppercase PNG extensions');
    [, $body] = frameEndpoint('setFrame.php', [], ['frame' => $studioFrame]);
    frameCheck(json_decode($body, true)['status'] === 'success' && hash_file('sha256', $frames . '/current_active_frame.png') === hash_file('sha256', $frames . '/small.png'), 'Selecting a topic frame uses its original even when its name matches a root frame');
    foreach (['Studio/../small.png', '../frames/small.png', 'Studio/Sub/Hidden.png', 'Studio/current_active_frame.png', 'Studio\\Mẫu mới.png', 'Studio:extra/Mẫu mới.png'] as $path) {
        frameCheck(resolvePhotoFrame($frames, $path) === null, 'A topic frame path cannot bypass the frame directory or use the active copy');
    }

    rename($frames . '/Studio', $frames . '/010 - Studio');
    rename($frames . '/Sinh nhật', $frames . '/2 - Lễ hội');
    rename($frames . '/20-11', $frames . '/01 - 20-11');
    mkdir($frames . '/0 Sự kiện');
    copy($frames . '/small.png', $frames . '/0 Sự kiện/Mẫu.png');
    [$status, $body] = frameEndpoint('getFrames.php', ['grouped' => '1']);
    frameCheck($status === 200 && json_decode($body, true) === [
        ['label' => '20/11', 'frames' => ['01 - ' . $eventFrame]],
        ['label' => 'Lễ hội', 'frames' => ['2 - Lễ hội/Khung mới.png']],
        ['label' => 'Studio', 'frames' => ['010 - ' . $studioFrame, '010 - Studio/Mẫu mới.png']],
        ['label' => '0 Sự kiện', 'frames' => ['0 Sự kiện/Mẫu.png']],
        ['label' => 'Khác', 'frames' => [$name, 'fake.png', 'small.png']],
    ], 'Folder renames update the catalog with numeric order and readable labels');

    $cache = $fixture . '/cache/frame-previews';
    $multiFrames = $fixture . '/app/resources/img/Multi-frames';
    mkdir($multiFrames . '/01 - Studio', 0755, true);
    $multiName = '01 - Studio/Bộ ảnh.png';
    copy($frames . '/small.png', $multiFrames . '/' . $multiName);

    $singleSource = resolvePhotoFrame($frames, $name);
    $oldSingle = photoFramePreview($singleSource, $cache);
    touch($singleSource, time() + 30);
    clearstatcache(true, $singleSource);
    $currentSingle = photoFramePreview($singleSource, $cache);
    $multiSource = resolvePhotoFrame($multiFrames, $multiName);
    $currentMulti = photoFramePreview($multiSource, $cache);
    copy($frames . '/small.png', $frames . '/removed.png');
    $oldRemoved = photoFramePreview(resolvePhotoFrame($frames, 'removed.png'), $cache);
    unlink($frames . '/removed.png');

    $fresh = $cache . '/' . str_repeat('b', 64) . '.png';
    $nonCache = $cache . '/backup.png';
    $data = $fixture . '/cache/' . basename($oldRemoved);
    $nested = $cache . '/' . str_repeat('c', 64) . '.png';
    mkdir($nested);
    foreach ([$fresh, $nonCache, $data, $nested . '/keep.png'] as $path) copy($oldRemoved, $path);
    $old = time() - 7200;
    foreach ([$oldSingle, $oldRemoved, $currentSingle, $currentMulti, $nonCache, $data, $nested] as $path) touch($path, $old);

    $lockPath = $cache . '/.cleanup.lock';
    file_put_contents($lockPath, '1');
    touch($lockPath, $old);
    $lock = fopen($lockPath, 'r+b');
    flock($lock, LOCK_EX);
    try {
        [$status, $body] = frameEndpoint('framePreview.php', ['frame' => $name]);
        frameCheck($status === 200 && $body === file_get_contents($currentSingle) && is_file($oldRemoved), 'A busy cleanup lock does not block a warm preview or start a competing sweep');
    } finally {
        fclose($lock);
    }

    $conditional = ['HTTP_IF_NONE_MATCH' => 'W/"' . photoFramePreviewKey($multiSource) . '"'];
    [$status, $body] = frameEndpoint('framePreview.php', ['mode' => 'multi', 'frame' => $multiName], [], $conditional);
    clearstatcache();
    frameCheck($status === 304 && $body === '', 'Revalidating a multi preview keeps the 304 response with no body');
    frameCheck(!file_exists($oldSingle) && !file_exists($oldRemoved), 'Revalidation removes aged previews for replaced and removed frames');
    frameCheck(is_file($currentSingle) && is_file($currentMulti) && filemtime($currentSingle) === $old && filemtime($currentMulti) === $old, 'Sweep retains current previews for both frame libraries without rewriting them');
    frameCheck(is_file($fresh) && is_file($nonCache) && is_file($data) && is_file($nested . '/keep.png'), 'Sweep skips recent files, unrelated names, nested entries and files outside the preview cache');

    copy($currentMulti, $oldRemoved);
    touch($oldRemoved, $old);
    [$status, $body] = frameEndpoint('framePreview.php', ['mode' => 'multi', 'frame' => $multiName]);
    frameCheck($status === 200 && $body === file_get_contents($currentMulti) && is_file($oldRemoved), 'A warm multi preview is served during the cleanup interval without another sweep');

    unlink($lockPath);
    [$status, $body] = frameEndpoint('framePreview.php', ['frame' => $name], [], ['REQUEST_METHOD' => 'HEAD']);
    clearstatcache();
    frameCheck($status === 200 && $body === '' && is_file($lockPath) && !is_file($oldRemoved), 'First sweep initializes its lock and runs on a HEAD request without sending a body');

    frameCheck(glob($cache . '/*.tmp') === [], 'Cache publication leaves no partial files');
    echo "$checks frame preview checks passed\n";
} finally {
    // The only cleanup target is the freshly allocated test fixture.
    if (is_dir($fixture)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
        }
        rmdir($fixture);
    }
}
