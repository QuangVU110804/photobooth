<?php
/**
 * Photobooth Automated Test Pipeline Runner
 * Runs automated checks before commits and during CI/CD.
 */

// Mock basic server variables for CLI execution
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

$rootDir = realpath(__DIR__ . '/..');
echo "====================================================\n";
echo "   PHOTOBOOTH TEST PIPELINE RUNNER\n";
echo "   Target directory: {$rootDir}\n";
echo "====================================================\n\n";

$errors = [];
$totalTests = 0;
$passedTests = 0;

function runStep($name, callable $callback) {
    global $errors, $totalTests, $passedTests;
    $totalTests++;
    echo "[RUNNING] {$name}... ";
    try {
        $result = $callback();
        if ($result === true) {
            $passedTests++;
            echo "PASSED\n";
        } else {
            $msg = is_string($result) ? $result : "Failed";
            $errors[] = "[{$name}] {$msg}";
            echo "FAILED: {$msg}\n";
        }
    } catch (\Throwable $e) {
        $errors[] = "[{$name}] Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

// ----------------------------------------------------
// Step 1: PHP Syntax Linting across all PHP files
// ----------------------------------------------------
runStep("PHP Syntax Linting (all PHP source files)", function() use ($rootDir) {
    $phpExe = PHP_BINARY;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $lintFailures = [];
    $scannedFiles = 0;

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $filePath = $file->getPathname();
            // Skip node_modules, archives, vendor, data, and cache folders
            if (
                strpos($filePath, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR) !== false ||
                strpos($filePath, DIRECTORY_SEPARATOR . 'archives' . DIRECTORY_SEPARATOR) !== false ||
                strpos($filePath, DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR) !== false ||
                strpos($filePath, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false
            ) {
                continue;
            }

            $scannedFiles++;
            $escapedPath = escapeshellarg($filePath);
            $cmd = escapeshellcmd("{$phpExe} -l {$escapedPath} 2>&1");
            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                $lintFailures[] = implode(" ", $output);
            }
        }
    }

    echo "(Scanned {$scannedFiles} files) ";
    if (!empty($lintFailures)) {
        return "Syntax errors found:\n" . implode("\n", array_slice($lintFailures, 0, 5));
    }
    return true;
});

// ----------------------------------------------------
// Step 2: Configuration Integrity Verification
// ----------------------------------------------------
runStep("Configuration Load & Integrity Test", function() use ($rootDir) {
    $configFile = $rootDir . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'config.php';
    if (!file_exists($configFile)) {
        return "lib/config.php not found!";
    }

    // Require configuration within isolated scope
    $loadConfig = function() use ($configFile) {
        require $configFile;
        return isset($config) ? $config : null;
    };

    $config = $loadConfig();
    if (!is_array($config)) {
        return "Failed to load default configuration array from lib/config.php";
    }

    $requiredKeys = ['foldersAbs', 'foldersRoot', 'picture', 'keying', 'textonpicture', 'jpeg_quality'];
    $missingKeys = [];
    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $config)) {
            $missingKeys[] = $key;
        }
    }

    if (!empty($missingKeys)) {
        return "Missing essential config keys: " . implode(', ', $missingKeys);
    }

    return true;
});

// ----------------------------------------------------
// Step 3: Core Lib Functions Availability Check
// ----------------------------------------------------
runStep("Core Library Functions Check", function() use ($rootDir) {
    $libFiles = [
        'lib/applyEffects.php',
        'lib/applyFrame.php',
        'lib/applyText.php',
        'lib/filter.php',
        'lib/resize.php',
        'lib/helper.php'
    ];

    foreach ($libFiles as $lib) {
        $path = $rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $lib);
        if (!file_exists($path)) {
            return "Required core library file is missing: {$lib}";
        }
    }

    return true;
});

// ----------------------------------------------------
// Step 4: API Structure and Endpoints Presence
// ----------------------------------------------------
runStep("API Endpoints Structure Check", function() use ($rootDir) {
    $apiEndpoints = [
        'api/checkVersion.php',
        'api/serverInfo.php',
        'api/admin.php',
        'api/config.php'
    ];

    foreach ($apiEndpoints as $endpoint) {
        $path = $rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $endpoint);
        if (!file_exists($path)) {
            return "Required API endpoint file missing: {$endpoint}";
        }
    }

    return true;
});

// ----------------------------------------------------
// Summary
// ----------------------------------------------------
echo "\n====================================================\n";
echo "   TEST SUMMARY: {$passedTests}/{$totalTests} PASSED\n";
echo "====================================================\n";

if (!empty($errors)) {
    echo "\nFailures encountered:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
    echo "\nPipeline execution FAILED.\n";
    exit(1);
}

echo "\nAll pipeline checks PASSED successfully!\n";
exit(0);
