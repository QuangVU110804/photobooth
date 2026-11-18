<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/frames.php';
$grouped = ($_GET['grouped'] ?? null) === '1';
$frames = listPhotoFrames(__DIR__ . '/../resources/img/frames', $grouped);
if (!$grouped) {
    echo json_encode($frames);
    return;
}

header('Cache-Control: no-store');
echo json_encode(photoFrameGroups($frames), JSON_UNESCAPED_UNICODE);
