<?php
header('Content-Type: application/json');

require_once '../lib/config.php';
require_once '../lib/log.php';
require_once __DIR__ . '/../lib/api.php';
if (!in_array($_POST['play'] ?? null, ['start', 'stop'], true)) apiError('Invalid preview action');
if (empty($config['preview'][$_POST['play'] === 'start' ? 'cmd' : 'killcmd'])) apiError('Preview command is not configured', 503);

$simpleExec = $config['preview']['simpleExec'];

function isRunning($pid) {
    try {
        $result = shell_exec(sprintf('ps %d', $pid));

        if (is_string($result) && count(preg_split("/\n/", $result)) > 2) {
            return true;
        }
    } catch (Exception $e) {
        return false;
    }

    return false;
}

if ($_POST['play'] === 'start') {
    $cmd = sprintf($config['preview']['cmd']);
    if ($simpleExec) {
        exec($cmd, $output, $returnValue);
        if ($returnValue !== 0) apiError('Preview command failed', 500);
        $LogData = [
            'isRunning' => true,
            'pid' => intval(1),
            'php' => basename($_SERVER['PHP_SELF']),
        ];
    } else {
        $pid = exec($cmd, $out, $returnValue);
        if ($returnValue !== 0 || !ctype_digit((string) $pid) || (int) $pid <= 0 || (int) $pid >= PHP_INT_MAX) {
            apiError('Preview command did not return a valid process ID', 500);
        }
        $pid = (int) $pid;
        sleep(3);
        $LogData = [
            'isRunning' => isRunning($pid),
            'pid' => $pid - 1,
            'cmd' => $cmd,
            'php' => basename($_SERVER['PHP_SELF']),
        ];
    }
} else {
    $killcmd = sprintf($config['preview']['killcmd']);
    $success = exec($killcmd, $output, $retval);
    if ($retval !== 0) apiError('Preview stop command failed', 500);
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
    }
    if ($simpleExec) {
        $LogData = [
            'isRunning' => false,
            'pid' => intval(0),
            'php' => basename($_SERVER['PHP_SELF']),
        ];
    } else {
        $LogData = [
            'isRunning' => isRunning((int)($_POST['pid'] ?? 0)),
            'cmd' => $killcmd,
            'pid' => intval($_POST['pid'] ?? 0),
            'php' => basename($_SERVER['PHP_SELF']),
        ];
    }
}
$LogString = json_encode($LogData);
if ($config['dev']['loglevel'] > 1) {
    logError($LogData);
}
die($LogString);
