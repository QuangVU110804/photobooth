<?php
header('Content-Type: application/json');

require_once '../lib/config.php';
require_once '../lib/log.php';
require_once __DIR__ . '/../lib/api.php';

$url = 'https://api.github.com/repos/' . $config['ui']['github'] . '/photobooth/releases/latest';
$gh = $config['ui']['github'];
$options = [
    'http' => [
        'method' => 'GET',
        'timeout' => 5,
        'header' => "User-Agent: $gh/photobooth\r\n",
    ],
];

$context = stream_context_create($options);
$content = @file_get_contents($url, false, $context);
$data = $content !== false ? json_decode($content, true) : null;
if (!is_array($data) || !is_string($data['tag_name'] ?? null) || !preg_match('/^v?\d+\.\d+\.\d+(?:[-+][a-zA-Z0-9.-]+)?$/', $data['tag_name'])) {
    apiError('Release information is unavailable', 503);
}
$remoteVersion = ltrim($data['tag_name'], 'v');
$localVersion = $config['photobooth']['version'];

$LogData = [
    'updateAvailable' => version_compare($remoteVersion, $localVersion, '>'),
    'currentVersion' => $localVersion,
    'availableVersion' => $remoteVersion,
    'php' => basename($_SERVER['PHP_SELF']),
];
$LogString = json_encode($LogData);
if ($config['dev']['loglevel'] > 0) {
    logError($LogData);
}
die($LogString);
