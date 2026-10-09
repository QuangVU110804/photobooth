<?php
header('Content-Type: application/json');
$dir = __DIR__ . '/../resources/img/frames/';
// Chỉ lấy các frame tùy chỉnh của Kiosk, bỏ qua các frame mặc định cũ
$files = glob($dir . 'kiosk_frame_*.png');
$result = [];
foreach ($files as $file) {
    $result[] = basename($file);
}
sort($result);
echo json_encode($result);