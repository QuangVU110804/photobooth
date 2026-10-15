<?php
// Only used by the owned CLI test server. Never routes a production request.
$testRoot = getenv('PHOTOBOOTH_FILTER_TEST_DIR');
if (PHP_SAPI !== 'cli-server' || !$testRoot || !is_dir($testRoot)) { http_response_code(404); exit; }
$root = dirname(__DIR__);
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$route = preg_replace('#^/photobooth/#', '', $path);
if ($route === '_filter-test-log') {
    header('Content-Type: application/json');
    $rows = file_exists($testRoot . '/requests.jsonl') ? file($testRoot . '/requests.jsonl', FILE_IGNORE_NEW_LINES) : [];
    echo '[' . implode(',', $rows) . ']'; exit;
}
if ($route === '_filter-test-frame') { echo file_exists($testRoot . '/active-frame.txt') ? file_get_contents($testRoot . '/active-frame.txt') : ''; exit; }
if (str_starts_with($route, '_filter-test-data/')) {
    $relative = substr($route, strlen('_filter-test-data/'));
    $file = realpath($testRoot . '/' . rawurldecode($relative));
    if (!$file || !str_starts_with($file, realpath($testRoot) . DIRECTORY_SEPARATOR)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg'); readfile($file); exit;
}
if (!in_array($route, ['index.php', '', 'api/config.php', 'api/setFrame.php', 'api/framePreview.php', 'api/takePic.php', 'api/applyEffects.php', 'api/deletePhoto.php'], true)) return false;
if ($route === 'api/setFrame.php') {
    // Frame writes are the external boundary: don't overwrite the real active frame.
    header('Content-Type: application/json');
    $valid = is_string($_POST['frame'] ?? null) && basename($_POST['frame']) === $_POST['frame'] && is_file($root . '/resources/img/frames/' . $_POST['frame']);
    if (($_COOKIE['filterTestMode'] ?? '') === 'frame-fail') $valid = false;
    if ($valid) file_put_contents($testRoot . '/active-frame.txt', $_POST['frame']);
    echo json_encode(['status' => $valid ? 'success' : 'error']); exit;
}
require_once $root . '/lib/config.php';
$config['ui']['skip_welcome'] = true;
$config['ui']['style'] = 'modern';
if (($_COOKIE['filterTestMode'] ?? '') === 'classic') $config['ui']['style'] = 'classic';
$config['ui']['notification_timeout'] = '.05';
$config['ui']['folders_lang'] = '/photobooth/resources/lang';
$config['dev']['demo_images'] = false;
$config['dev']['loglevel'] = 0;
$config['dev']['reload_on_error'] = false;
$config['database']['enabled'] = false;
$config['login']['enabled'] = false;
$config['preview']['mode'] = 'device_cam';
$config['preview']['camTakesPic'] = true;
if (($_COOKIE['filterTestMode'] ?? '') === 'external') { $config['preview']['mode'] = 'none'; $config['preview']['camTakesPic'] = false; }
$config['preview']['videoWidth'] = 640;
$config['preview']['videoHeight'] = 480;
$config['preview']['cmd'] = '';
$config['preview']['killcmd'] = '';
$config['preview']['asBackground'] = false;
$config['picture']['keep_original'] = true;
$config['picture']['cntdwn_time'] = '1';
$config['picture']['cntdwn_offset'] = '0';
$config['picture']['time_to_live'] = '300';
$config['picture']['no_cheese'] = true;
$config['picture']['flip'] = 'off';
$config['picture']['rotation'] = '0';
$config['picture']['polaroid_effect'] = false;
$config['picture']['take_frame'] = false;
$config['picture']['retry_on_error'] = 0;
$config['textonpicture']['enabled'] = false;
$config['filters']['enabled'] = true;
$config['filters']['disabled'] = [];
$config['qr']['enabled'] = false;
$config['print']['auto'] = false;
$config['pre_photo']['cmd'] = '';
$config['post_photo']['cmd'] = '';
$config['synctodrive']['enabled'] = false;
$config['remotebuzzer']['usebuttons'] = false;
$config['remotebuzzer']['userotary'] = false;
$config['remotebuzzer']['usenogpio'] = false;
foreach (['images', 'tmp', 'thumbs', 'qrcodes', 'keying', 'print'] as $folder) {
    $config['foldersAbs'][$folder] = $testRoot . '/' . $folder;
    $config['foldersJS'][$folder] = '/photobooth/_filter-test-data/' . $folder;
}
if ($route === 'api/config.php') {
    header('Content-Type: application/javascript');
    foreach (['login', 'mail'] as $key) $config[$key]['password'] = 'secret';
    $config['login']['username'] = 'secret';
    echo 'const config = ' . json_encode($config) . ';'; exit;
}
if (str_starts_with($route, 'api/')) {
    if ($route === 'api/framePreview.php') { require $root . '/' . $route; exit; }
    $log = ['route' => $route, 'filter' => $_POST['filter'] ?? null, 'style' => $_POST['style'] ?? null, 'file' => $_POST['file'] ?? null, 'frame' => $_POST['frame'] ?? null, 'faceEffects' => $_POST['faceEffects'] ?? null];
    file_put_contents($testRoot . '/requests.jsonl', json_encode($log) . "\n", FILE_APPEND);
    chdir($root . '/api'); require $root . '/' . $route;
} else { chdir($root); require $root . '/index.php'; }
