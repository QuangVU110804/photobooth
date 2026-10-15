<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/frames.php';
echo json_encode(listPhotoFrames(__DIR__ . '/../resources/img/frames'));
