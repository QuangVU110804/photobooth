<?php
require_once '../lib/config.php';
require_once __DIR__ . '/../lib/api.php';

$image = requirePhotoFilename($_GET['image'] ?? null);
if (!$config['download']['enabled']) apiError('Downloads are disabled', 403);
$directory = $config['foldersAbs'][$config['download']['thumbs'] ? 'thumbs' : 'images'];
$path = apiPhotoPath($directory, $image);
if ($path === null || !is_readable($path)) apiError('Photo not found', 404);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="photobooth-photo.' . strtolower(pathinfo($image, PATHINFO_EXTENSION)) . '"; filename*=UTF-8\'\'' . rawurlencode('photobooth-' . $image));
header('Content-Length: ' . filesize($path));
readfile($path);
