<?php
header('Content-Type: application/json');
$dir = __DIR__ . '/../resources/img/frames';
$files = glob($dir . '/*.png');
$result = [];
foreach ($files as $file) {
    $name = basename($file);
    if (strcasecmp($name, 'current_active_frame.png') !== 0 && is_file($file)) {
        $result[] = $name;
    }
}
sort($result);
echo json_encode($result);
