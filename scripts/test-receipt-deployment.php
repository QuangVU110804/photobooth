<?php
/** Receipt deployment settings are isolated from this machine's private URL. */
$fixture = sys_get_temp_dir() . '/photobooth-receipt-deployment-' . bin2hex(random_bytes(8));
mkdir($fixture . '/lib', 0755, true);
mkdir($fixture . '/config');
copy(dirname(__DIR__) . '/lib/photo-receipt.php', $fixture . '/lib/photo-receipt.php');
$checks = 0;
$failures = 0;
function deploymentUrl($environment = null, $setting = ''): string {
    global $fixture;
    $code = 'putenv("PHOTOBOOTH_PUBLIC_URL");';
    if ($environment !== null) $code .= 'putenv(' . var_export('PHOTOBOOTH_PUBLIC_URL=' . $environment, true) . ');';
    $code .= '$config["webserver"]["public_url"]=' . var_export($setting, true) . ';';
    // Own boundary: the normal application loader supplies this deployment URL.
    $code .= 'function getPhotoboothUrl(){return "https://booth.example.test/event";}require ' . var_export($fixture . '/lib/photo-receipt.php', true) . ';try{echo photoReceiptUrl("photo.jpg");}catch(RuntimeException $e){echo "INVALID_BASE";}';
    $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || $errors !== '') throw new RuntimeException('Receipt deployment child failed: ' . $errors);
    return $body;
}
function deploymentCheck($actual, $expected, $label): void {
    global $checks, $failures;
    $checks++;
    if ($actual !== $expected) { $failures++; echo "FAIL: $label\n"; }
}
try {
    deploymentCheck(deploymentUrl(), 'https://booth.example.test/event/photo.php?image=photo.jpg', 'Fresh deployment uses its own installation URL');
    file_put_contents($fixture . '/config/photo-receipt.local.php', '<?php return "https://local.example.test/booth/";');
    deploymentCheck(deploymentUrl(), 'https://local.example.test/booth/photo.php?image=photo.jpg', 'Private deployment URL overrides automatic detection');
    deploymentCheck(deploymentUrl(null, 'https://tunnel.example.test/photobooth'), 'https://tunnel.example.test/photobooth/photo.php?image=photo.jpg', 'Admin public URL overrides old local settings');
    deploymentCheck(deploymentUrl('https://env.example.test/booth', 'https://tunnel.example.test/photobooth'), 'https://env.example.test/booth/photo.php?image=photo.jpg', 'Environment remains highest priority');
    deploymentCheck(deploymentUrl('https://public.example.test/site'), 'https://public.example.test/site/photo.php?image=photo.jpg', 'Environment overrides the private URL');
    deploymentCheck(deploymentUrl('javascript:invalid'), 'INVALID_BASE', 'Invalid explicit URL is rejected');
    unlink($fixture . '/config/photo-receipt.local.php');
    deploymentCheck(deploymentUrl(), 'https://booth.example.test/event/photo.php?image=photo.jpg', 'Removing private override restores automatic deployment URL');
} finally {
    foreach (['config/photo-receipt.local.php', 'lib/photo-receipt.php'] as $name) if (is_file($fixture . '/' . $name)) unlink($fixture . '/' . $name);
    rmdir($fixture . '/config'); rmdir($fixture . '/lib'); rmdir($fixture);
}
echo "$checks receipt deployment checks, $failures failures\n";
exit($failures ? 1 : 0);
