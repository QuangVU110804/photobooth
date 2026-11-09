<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../lib/face-stickers.php';

try {
    $effects = array_values(stickerCatalog());
} catch (InvalidArgumentException $error) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid sticker catalog']);
    exit;
}

$publicPart = array_flip(['file', 'anchor', 'width', 'origin', 'offset', 'opacity']);
foreach ($effects as $index => $effect) {
    $effects[$index]['parts'] = array_map(static fn($part) => array_intersect_key($part, $publicPart), $effect['parts']);
}
echo json_encode(['version' => 1, 'effects' => $effects], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
