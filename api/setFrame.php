<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/frames.php';
$framesDir = __DIR__ . '/../resources/img/frames';
$frameName = $_POST['frame'] ?? null;
$src = resolvePhotoFrame($framesDir, $frameName);

$info = $src !== null ? @getimagesize($src) : false;
if (!$info || $info[2] !== IMAGETYPE_PNG) {
    echo json_encode(['status' => 'error']);
    exit;
}

$dst = $framesDir . DIRECTORY_SEPARATOR . 'current_active_frame.png';
if (@copy($src, $dst)) {
    echo json_encode(['status' => 'success', 'active' => $frameName]);
    exit;
}

echo json_encode(['status' => 'error']);
