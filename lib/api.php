<?php
require_once __DIR__ . '/photo-receipt.php';

function apiError(string $message, int $status = 400): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

/** Share the admin page's login/local-access policy with its endpoints. */
function requireAdminAccess(): void {
    global $config;
    if (!$config['login']['enabled'] || !$config['protect']['admin']) return;
    $remote = $_SERVER['REMOTE_ADDR'] ?? null;
    $server = $_SERVER['SERVER_ADDR'] ?? null;
    if (!$config['protect']['localhost_admin'] && $remote !== null && $server !== null && $remote === $server) return;
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $authenticated = ($_SESSION['auth'] ?? false) === true;
    // Release the session before long-running camera/archive operations.
    session_write_close();
    if (!$authenticated) apiError('Authentication required', 401);
}

function requirePhotoFilename($value): string {
    $name = photoReceiptFilename($value);
    if ($name === null) apiError('Invalid photo filename');
    return $name;
}

/** Existing files must resolve inside this one directory, including symlinks. */
function apiPhotoPath(string $directory, string $name): ?string {
    $root = realpath($directory);
    $path = realpath($directory . DIRECTORY_SEPARATOR . $name);
    return $root !== false && $path !== false && dirname($path) === $root && is_file($path) ? $path : null;
}

function clearPhotoCaches(string $filename): void {
    global $config;
    $url = photoReceiptUrl($filename);
    $hash = hash('sha256', $url);
    $paths = [
        $config['foldersAbs']['print'] . DIRECTORY_SEPARATOR . $filename,
        $config['foldersAbs']['qrcodes'] . DIRECTORY_SEPARATOR . $filename,
        $config['foldersAbs']['print'] . DIRECTORY_SEPARATOR . 'receipt-' . $hash . '.jpg',
        $config['foldersAbs']['qrcodes'] . DIRECTORY_SEPARATOR . 'print-receipt-' . $hash . '.png',
        $config['foldersAbs']['qrcodes'] . DIRECTORY_SEPARATOR . md5($url) . '.png',
    ];
    foreach ($paths as $path) {
        if (is_file($path) && !unlink($path)) throw new RuntimeException('Could not invalidate photo cache');
    }
}
