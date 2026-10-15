<?php
header('Content-Type: application/json');

require_once '../lib/config.php';
require_once __DIR__ . '/../lib/api.php';

$mode = $_POST['mode'] ?? null;
if (!in_array($mode, ['pre-command', 'post-command', 'reboot', 'shutdown'], true)) apiError('Invalid shell command mode');
if (in_array($mode, ['reboot', 'shutdown'], true)) requireAdminAccess();

switch ($mode) {
    case 'pre-command':
        $cmd = sprintf($config['pre_photo']['cmd']);
        break;
    case 'post-command':
        $filename = requirePhotoFilename($_POST['filename'] ?? null);
        $cmd = sprintf($config['post_photo']['cmd'], escapeshellarg($filename));
        break;
    case 'reboot':
        $cmd = 'sudo ' . sprintf($config['reboot']['cmd']);
        break;
    case 'shutdown':
        $cmd = 'sudo ' . sprintf($config['shutdown']['cmd']);
        break;
    default:
        $cmd = 'echo "Error for mode ' . $mode . ' - command not defined in configuration"';
        break;
}
if (trim($cmd) === '' || trim($cmd) === 'sudo') apiError('Shell command is not configured', 503);

$success = exec($cmd, $output, $retval);

if (isset($success)) {
    switch ($retval) {
        case 127:
            $output = 'Command not found';
            $success = false;
            break;
        case 0:
            $success = true;
            break;
        default:
            $success = 'unknown';
            break;
    }

    $LogData = [
        'success' => $success,
        'output' => $output,
        'retval' => $retval,
        'command' => $cmd,
        'php' => basename($_SERVER['PHP_SELF']),
    ];
    $LogString = json_encode($LogData);
    if ($config['dev']['loglevel'] > 1) {
        logError($LogData);
    }
    echo $LogString;
} else {
    $LogData = [
        'success' => 'false',
        'command' => $cmd,
        'php' => basename($_SERVER['PHP_SELF']),
    ];
    $LogString = json_encode($LogData);
    if ($config['dev']['loglevel'] > 0) {
        logError($LogData);
    }
    echo $LogString;
}
