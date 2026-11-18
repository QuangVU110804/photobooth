<?php
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/multishot-render.php';
require_once __DIR__ . '/../lib/log.php';
require_once __DIR__ . '/../lib/db.php';

header('Cache-Control: no-store');
$action = $_GET['action'] ?? ($_POST['action'] ?? null);
$reads = ['frames', 'status', 'raw', 'edited'];
$writes = ['start', 'capture', 'process', 'complete', 'cancel'];
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ((!in_array($action, $reads, true) || $method !== 'GET') && (!in_array($action, $writes, true) || $method !== 'POST')) {
    apiError('Invalid multishot action', 400);
}
if (!$config['picture']['enabled'] || $config['button']['force_buzzer']) apiError('Multishot is unavailable', 403);

// Bind private photos to the booth's browser session, and release PHP's
// session lock while waiting on the camera or processing photos.
if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) apiError('Could not open browser session', 500);
$auth = ($_SESSION['auth'] ?? false) === true;
if ($config['login']['enabled'] && $config['protect']['index'] && !$auth
    && ($config['protect']['localhost_index'] || !isset($_SERVER['SERVER_ADDR'])
        || ($_SERVER['REMOTE_ADDR'] ?? '') !== $_SERVER['SERVER_ADDR'])) {
    session_write_close();
    apiError('Authentication required', 401);
}
$owner = $_SESSION['multishot_owner'] ??= bin2hex(random_bytes(32));
session_write_close();

header('Content-Type: application/json; charset=utf-8');
try {
    if ($action === 'frames') {
        $frames = [];
        foreach (multishotFrames() as $frame) {
            unset($frame['path']);
            if (isset($_GET['layout']) && $frame['layout'] !== $_GET['layout']) continue;
            $frame['preview'] = 'api/framePreview.php?mode=multi&frame=' . rawurlencode($frame['png']);
            $frames[] = $frame;
        }
        $result = ['frames' => $frames];
    } elseif ($action === 'start') {
        $result = multishotStart($config, $owner, $_POST['frame'] ?? null, $_POST['source'] ?? null, $_POST['flip'] ?? null);
    } else {
        $params = $method === 'GET' ? $_GET : $_POST;
        if ($method === 'POST') ignore_user_abort(true);
        $result = multishotUse($config, $params['id'] ?? null, $owner, function ($dir, $session) use ($action, $config, $params) {
            if ($action === 'status') return multishotStatus($session);
            if ($action === 'capture') {
                return multishotCapture($config, $dir, $session, $params['slot'] ?? null, $params['request'] ?? null, $params['canvasimg'] ?? null);
            }
            if ($action === 'process') {
                return multishotProcess($config, $dir, $session, $params['slot'] ?? null, $params['file'] ?? null, $params);
            }
            if ($action === 'complete' || ($action === 'cancel' && in_array($session['state'], ['publishing', 'complete'], true))) {
                return multishotComplete($config, $dir, $session);
            }
            if ($action === 'cancel') {
                if ($session['state'] === 'deleted') throw new MultishotError('Bộ ảnh đã bị xóa', 410);
                return multishotForget($dir, $session, 'cancelled', $config['picture']['keep_original']);
            }
            if ($action === 'raw' || $action === 'edited') {
                multishotAsset($dir, $session, $params['slot'] ?? null, $action === 'edited');
                return null;
            }
            throw new MultishotError('Invalid multishot action', 400);
        });
    }
    if ($result !== null) echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (MultishotError | CameraCaptureError $error) {
    apiError($error->getMessage(), $error->getCode());
} catch (InvalidArgumentException $error) {
    apiError($error->getMessage());
} catch (Throwable $error) {
    error_log($error->getMessage());
    apiError('Không xử lý được bộ ảnh. Hãy thử lại.', 500);
}
