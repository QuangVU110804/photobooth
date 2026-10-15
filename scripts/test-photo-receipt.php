<?php
/** Run: C:\xampp\php\php.exe scripts/test-photo-receipt.php */
$root = dirname(__DIR__);
$failures = [];
$checks = 0;
function receiptCheck($condition, $message) {
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
        echo "FAIL: $message\n";
    }
}

$helper = $root . '/lib/photo-receipt.php';
receiptCheck(is_file($helper), 'Shared receipt URL and image resolver are available');
receiptCheck(is_file($root . '/photo.php'), 'QR destination has a photo receipt page');
if (!is_file($helper)) {
    exit(1);
}
require $helper;

$originalBase = getenv('PHOTOBOOTH_PUBLIC_URL');
putenv('PHOTOBOOTH_PUBLIC_URL=https://example.test/photobooth');
receiptCheck(photoReceiptUrl('20261014_140000.jpg') === 'https://example.test/photobooth/photo.php?image=20261014_140000.jpg', 'Configured QR opens the receipt with the exact photo');
receiptCheck(photoReceiptUrl('Ảnh #1 + bạn.jpg') === 'https://example.test/photobooth/photo.php?image=%E1%BA%A2nh%20%231%20%2B%20b%E1%BA%A1n.jpg', 'Unicode and reserved characters survive QR URL encoding');
putenv('PHOTOBOOTH_PUBLIC_URL=https://example.test/event/');
receiptCheck(photoReceiptUrl('photo.jpg') === 'https://example.test/event/photo.php?image=photo.jpg', 'Public installation URL can be changed without editing endpoints');
putenv('PHOTOBOOTH_PUBLIC_URL=https://example.test/photobooth');

foreach ([null, [], '', '../secret.jpg', '..\\secret.jpg', '/photo.jpg', "bad\0.jpg", 'file.php', '.hidden.jpg', '<script>.jpg'] as $invalid) {
    receiptCheck(photoReceiptFilename($invalid) === null, 'Invalid filename rejected: ' . json_encode($invalid));
}
receiptCheck(photoReceiptFilename('Ảnh #1.jpg') === 'Ảnh #1.jpg', 'Valid Unicode filename is preserved');

$fixtureDir = sys_get_temp_dir() . '/photobooth-receipt-' . bin2hex(random_bytes(6));
mkdir($fixtureDir);
foreach (['images', 'thumbs', 'qrcodes', 'print', 'tmp'] as $folder) {
    mkdir($fixtureDir . '/' . $folder);
}
$image = imagecreatetruecolor(120, 80);
imagejpeg($image, $fixtureDir . '/images/sample.jpg', 90);
imagedestroy($image);
file_put_contents($fixtureDir . '/images/broken.jpg', 'not an image');

function receiptEndpoint($path, $query, $fixtureDir, $extra = '') {
    global $root;
    $code = 'putenv("PHOTOBOOTH_PUBLIC_URL=https://example.test/photobooth");chdir(' . var_export($root, true) . '); $_SERVER["DOCUMENT_ROOT"] = ' . var_export(dirname($root), true) . '; $_SERVER["HTTP_HOST"] = "localhost:8080"; $_SERVER["PHP_SELF"] = ' . var_export($path, true) . '; $_GET = ' . var_export($query, true) . '; require "lib/config.php";';
    foreach (['images', 'thumbs', 'qrcodes', 'print', 'tmp'] as $folder) {
        $code .= '$config["foldersAbs"][' . var_export($folder, true) . '] = ' . var_export($fixtureDir . '/' . $folder, true) . ';';
    }
    $code .= 'register_shutdown_function(function () { fwrite(STDERR, "STATUS:" . (http_response_code() ?: 200)); });' . $extra . ' chdir(' . var_export(dirname($root . '/' . $path), true) . '); require ' . var_export($root . '/' . $path, true) . ';';
    $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $fixtureDir . '/stderr.log', 'w']], $pipes, $root);
    $body = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($process);
    $stderr = file_get_contents($fixtureDir . '/stderr.log');
    preg_match('/STATUS:(\d+)/', $stderr, $match);
    receiptCheck($exit === 0, "$path exits cleanly: $stderr");
    receiptCheck(!preg_match('/Warning|Fatal error|Notice|Deprecated/i', $stderr . (str_starts_with($body, "\x89PNG") ? '' : $body)), "$path has no PHP diagnostics");
    return [(int) ($match[1] ?? 0), $body];
}

try {
    $resolved = findPhotoReceiptImage($fixtureDir . '/images', 'sample.jpg');
    receiptCheck($resolved !== null && $resolved['width'] === 120 && $resolved['height'] === 80, 'Resolver reads actual image dimensions');
    receiptCheck($resolved !== null && $resolved['bytes'] === filesize($fixtureDir . '/images/sample.jpg'), 'Resolver reads actual file size');
    receiptCheck(findPhotoReceiptImage($fixtureDir . '/images', '../sample.jpg') === null, 'Resolver prevents traversal');
    receiptCheck(findPhotoReceiptImage($fixtureDir . '/images', 'missing.jpg') === null, 'Missing photo does not fall back to another photo');
    receiptCheck(findPhotoReceiptImage($fixtureDir . '/images', 'broken.jpg') === null, 'Corrupt photo is rejected');

    if (is_file($root . '/photo.php')) {
        [$status, $body] = receiptEndpoint('photo.php', ['image' => 'sample.jpg'], $fixtureDir);
        receiptCheck($status === 200 && str_contains($body, 'sample.jpg') && str_contains($body, '120') && str_contains($body, '80'), 'Page renders the requested photo and real metadata');
        [$status, $body] = receiptEndpoint('photo.php', ['image' => 'sample.jpg', 'download' => '1'], $fixtureDir);
        receiptCheck($status === 200 && $body === file_get_contents($fixtureDir . '/images/sample.jpg'), 'Download returns the original bytes');
        [$status, $body] = receiptEndpoint('photo.php', ['image' => 'sample.jpg', 'download' => '1'], $fixtureDir, '$config["download"]["enabled"] = false;');
        receiptCheck($status === 403, 'Download respects disabled-download configuration');
        foreach ([['query' => [], 'status' => 400], ['query' => ['image' => '../sample.jpg'], 'status' => 400], ['query' => ['image' => ['sample.jpg']], 'status' => 400], ['query' => ['image' => 'missing.jpg'], 'status' => 404], ['query' => ['image' => 'broken.jpg'], 'status' => 404]] as $case) {
            [$status, $body] = receiptEndpoint('photo.php', $case['query'], $fixtureDir);
            receiptCheck($status === $case['status'], 'Page has appropriate missing/invalid-photo status');
            receiptCheck(!str_contains($body, 'id="download-photo"'), 'Error page never exposes a download for another photo');
        }
    }

    // Compare the emitted QR to an independently specified destination, using the real renderer.
    require_once $root . '/vendor/phpqrcode/lib/full/qrlib.php';
    if (!is_dir(QR_CACHE_DIR)) mkdir(QR_CACHE_DIR, 0755, true);
    $expectedUrl = 'https://example.test/photobooth/photo.php?image=sample.jpg';
    QRcode::png($expectedUrl, $fixtureDir . '/expected.png', QR_ECLEVEL_M, 8, 2);
    [$status, $body] = receiptEndpoint('api/qrcode.php', ['filename' => 'sample.jpg'], $fixtureDir);
    receiptCheck($status === 200 && $body === file_get_contents($fixtureDir . '/expected.png'), 'Screen QR encodes the receipt URL, not a static JPEG');
    [$status, $cachedBody] = receiptEndpoint('api/qrcode.php', ['filename' => 'sample.jpg'], $fixtureDir);
    receiptCheck($cachedBody === $body, 'QR cache preserves the correct destination');
    [$status] = receiptEndpoint('api/qrcode.php', ['filename' => ['sample.jpg']], $fixtureDir);
    receiptCheck($status === 400, 'QR rejects array parameters without crashing');

    // A legacy print and QR must not be reused after changing the destination.
    copy($fixtureDir . '/images/sample.jpg', $fixtureDir . '/print/sample.jpg');
    QRcode::png('https://old.example/photo.jpg', $fixtureDir . '/qrcodes/sample.jpg', QR_ECLEVEL_M, 4, 4);
    $printConfig = '$config["print"]["qrcode"] = true; $config["print"]["cmd"] = "echo test-print %s";';
    [$status, $printBody] = receiptEndpoint('api/print.php', ['filename' => 'sample.jpg'], $fixtureDir, $printConfig);
    $printResponse = json_decode($printBody, true);
    receiptCheck(isset($printResponse['returnValue']) && $printResponse['returnValue'] === 0, 'Print QR flow remains operational with a harmless test print command');
    receiptCheck(isset($printResponse['msg']) && !str_contains($printResponse['msg'], '/print/sample.jpg '), 'New print uses a fresh receipt-specific cache');
    QRcode::png($expectedUrl, $fixtureDir . '/expected-print.png', QR_ECLEVEL_M, 4, 4);
    $matchingPrintQR = false;
    foreach (glob($fixtureDir . '/qrcodes/*.png') as $qrFile) {
        if (file_get_contents($qrFile) === file_get_contents($fixtureDir . '/expected-print.png')) {
            $matchingPrintQR = true;
        }
    }
    receiptCheck($matchingPrintQR, 'Printed QR encodes the same receipt destination');

    $longName = str_repeat('a', 230) . '.jpg';
    receiptCheck(copy($fixtureDir . '/images/sample.jpg', $fixtureDir . '/images/' . $longName), 'Long filename fixture can be stored');
    [$status, $longPrintBody] = receiptEndpoint('api/print.php', ['filename' => $longName], $fixtureDir, $printConfig);
    $longPrintResponse = json_decode($longPrintBody, true);
    receiptCheck(isset($longPrintResponse['returnValue']) && $longPrintResponse['returnValue'] === 0 && !isset($longPrintResponse['error']), 'A valid long image name can be printed without exceeding cache filename limits');
    $longPrintCache = array_filter(glob($fixtureDir . '/print/*.jpg'), static fn($file) => $file !== $fixtureDir . '/print/sample.jpg');
    receiptCheck(count($longPrintCache) === 2 && array_reduce($longPrintCache, static fn($ok, $file) => $ok && strlen(basename($file)) <= 255, true), 'Prints for distinct photos have distinct bounded cache filenames');
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($fixtureDir);
    $originalBase === false ? putenv('PHOTOBOOTH_PUBLIC_URL') : putenv('PHOTOBOOTH_PUBLIC_URL=' . $originalBase);
}
echo "$checks checks, " . count($failures) . " failures\n";
exit(empty($failures) ? 0 : 1);
