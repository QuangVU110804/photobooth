<?php
require_once __DIR__ . '/config.php';

function logError($data) {
    global $config;
    $logfile = $config['foldersAbs']['tmp'] . DIRECTORY_SEPARATOR . $config['dev']['logfile'];

    $file_data = date('c') . ":\n" . print_r($data, true) . "\n";
    // Append under one lock: cost depends on the new entry, not log history.
    // The debug panel scrolls to the bottom to show the latest messages.
    file_put_contents($logfile, $file_data, FILE_APPEND | LOCK_EX);
}

function logErrorAndDie($errormsg) {
    $ErrorData = [
        'error' => $errormsg,
    ];
    $ErrorString = json_encode($ErrorData);
    logError($ErrorData);
    die($ErrorString);
}
