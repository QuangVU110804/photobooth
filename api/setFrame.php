<?php
header('Content-Type: application/json');
$framesDir = __DIR__ . '/../resources/img/frames';
$frameName = $_POST['frame'] ?? '';

if (!is_string($frameName) || $frameName === '') {
    echo json_encode(['status' => 'error']);
    exit;
}

$availableFrames = glob($framesDir . '/*.png');
$availableNames = [];
foreach ($availableFrames as $file) {
    $name = basename($file);
    if (strcasecmp($name, 'current_active_frame.png') !== 0 && is_file($file)) {
        $availableNames[] = $name;
    }
}

if (!in_array($frameName, $availableNames, true)) {
    echo json_encode(['status' => 'error']);
    exit;
}

$src = $framesDir . DIRECTORY_SEPARATOR . $frameName;
$dst = $framesDir . DIRECTORY_SEPARATOR . 'current_active_frame.png';
if (copy($src, $dst)) {
    echo json_encode(['status' => 'success', 'active' => $frameName]);
    exit;
}

echo json_encode(['status' => 'error']);
