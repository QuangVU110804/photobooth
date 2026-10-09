<?php
header('Content-Type: application/json');
if (isset($_POST['frame'])) {
    $frameName = basename($_POST['frame']);
    $src = 'C:/xampp/htdocs/photobooth/resources/img/frames/' . $frameName;
    $dst = 'C:/xampp/htdocs/photobooth/resources/img/frames/current_active_frame.png';
    
    if (file_exists($src)) {
        copy($src, $dst);
        echo json_encode(['status' => 'success', 'active' => $frameName]);
        exit;
    }
}
echo json_encode(['status' => 'error']);