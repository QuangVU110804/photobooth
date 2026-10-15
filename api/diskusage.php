<?php
header('Content-Type: application/json');

require_once '../lib/config.php';
require_once '../lib/hzip.php';
require_once __DIR__ . '/../lib/api.php';
requireAdminAccess();

$data = $_POST;
$file = date('Ymd-Hi') . '.zip';

if (($data['type'] ?? null) !== 'zip') apiError('Invalid archive action');

if ($data['type'] == 'zip') {
    $source = $config['foldersAbs']['data'];
    $zipOut = $config['foldersAbs']['archives'] . DIRECTORY_SEPARATOR . $file;

    HZip::zipDir($source, $zipOut);

    echo json_encode([
        'success' => 'zip',
        'file' => $file,
    ]);
}
