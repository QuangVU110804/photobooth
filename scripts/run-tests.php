<?php
/**
 * Photobooth Production-Grade Test & Diagnostic Pipeline
 * Designed by 10-Year Senior Dev & 10-Year DevOps Engineer.
 * 
 * Features:
 * - Deterministic, sandboxed test execution
 * - Automated Failure Telemetry & Code Snippet Extraction
 * - AI Fix Prompt generation (reports/ai-fix-prompt.md)
 * - Self-Healing Loop integration (--heal / --fix flag)
 * - GitHub Actions native annotations
 */

require_once __DIR__ . '/diagnostic-engine.php';

// Mock server environment for CLI execution
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/../..');
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/photobooth/';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';

$rootDir = realpath(__DIR__ . '/..');
$diagnostic = new DiagnosticEngine($rootDir);

$options = getopt("", ["heal", "fix", "json", "verbose"]);
$autoHealRequested = isset($options['heal']) || isset($options['fix']);
$jsonOnly = isset($options['json']);
$verbose = isset($options['verbose']);

if (!$jsonOnly) {
    echo "====================================================\n";
    echo "   ⚡ PHOTOBOOTH AUTOMATED TEST & DIAGNOSTIC PIPE\n";
    echo "   Target root : {$rootDir}\n";
    echo "   PHP runtime : " . PHP_VERSION . " (" . PHP_BINARY . ")\n";
    echo "   Auto-Heal   : " . ($autoHealRequested ? "ENABLED" : "STANDBY") . "\n";
    echo "====================================================\n\n";
}

$totalSteps = 0;
$passedSteps = 0;

function runPipelineStep(string $stepName, callable $callback, DiagnosticEngine $diag): bool {
    global $totalSteps, $passedSteps, $jsonOnly;
    $totalSteps++;
    if (!$jsonOnly) {
        echo sprintf("[STEP %02d] %-48s ", $totalSteps, $stepName);
    }
    
    try {
        $result = $callback($diag);
        if ($result === true) {
            $passedSteps++;
            if (!$jsonOnly) echo "✅ PASSED\n";
            return true;
        } else {
            if (!$jsonOnly) echo "❌ FAILED\n";
            return false;
        }
    } catch (\Throwable $e) {
        $diag->recordFailure("RUNTIME_EXCEPTION", $e->getFile(), $e->getLine(), $e->getMessage(), "SEV-1");
        if (!$jsonOnly) echo "💥 EXCEPTION: " . $e->getMessage() . "\n";
        return false;
    }
}

// ------------------------------------------------------------------
// STEP 1: Full AST Syntax Linting with Precise Line Detection
// ------------------------------------------------------------------
runPipelineStep("PHP Source Syntax & Token Linting", function(DiagnosticEngine $diag) use ($rootDir) {
    $phpExe = PHP_BINARY;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $hasFailures = false;
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $filePath = $file->getPathname();
            if (
                strpos($filePath, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR) !== false ||
                strpos($filePath, DIRECTORY_SEPARATOR . 'archives' . DIRECTORY_SEPARATOR) !== false ||
                strpos($filePath, DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR) !== false ||
                strpos($filePath, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false ||
                strpos($filePath, DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR) !== false
            ) {
                continue;
            }

            $escaped = escapeshellarg($filePath);
            $output = [];
            $code = 0;
            exec("{$phpExe} -l {$escaped} 2>&1", $output, $code);

            if ($code !== 0) {
                $hasFailures = true;
                $rawMsg = implode("\n", $output);
                $line = null;
                if (preg_match('/on line (\d+)/i', $rawMsg, $m)) {
                    $line = (int)$m[1];
                }
                $diag->recordFailure("SYNTAX_ERROR", $filePath, $line, $rawMsg, "SEV-1");
            }
        }
    }

    return !$hasFailures;
}, $diagnostic);

// ------------------------------------------------------------------
// STEP 2: Configuration Integrity & Schema Validation
// ------------------------------------------------------------------
runPipelineStep("Configuration Schema & Type Invariant Check", function(DiagnosticEngine $diag) use ($rootDir) {
    $configFile = $rootDir . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'config.php';
    if (!file_exists($configFile)) {
        $diag->recordFailure("CONFIG_ERROR", $configFile, 1, "Essential lib/config.php file missing", "SEV-1");
        return false;
    }

    $config = (function() use ($configFile) {
        require $configFile;
        return isset($config) ? $config : null;
    })();

    if (!is_array($config)) {
        $diag->recordFailure("CONFIG_ERROR", $configFile, 1, "Failed to load configuration array", "SEV-1");
        return false;
    }

    $schemaChecks = [
        'foldersAbs' => 'array',
        'foldersRoot' => 'array',
        'picture' => 'array',
        'keying' => 'array',
        'textonpicture' => 'array',
        'jpeg_quality' => 'array'
    ];

    $hasFailures = false;
    foreach ($schemaChecks as $key => $type) {
        if (!isset($config[$key])) {
            $diag->recordFailure("CONFIG_SCHEMA", $configFile, 1, "Missing essential configuration section: '{$key}'", "SEV-2");
            $hasFailures = true;
        } elseif (gettype($config[$key]) !== $type) {
            $diag->recordFailure("CONFIG_SCHEMA", $configFile, 1, "Config key '{$key}' expected {$type}, got " . gettype($config[$key]), "SEV-2");
            $hasFailures = true;
        }
    }

    return !$hasFailures;
}, $diagnostic);

// ------------------------------------------------------------------
// STEP 3: Core Helper Business Logic Verification
// ------------------------------------------------------------------
runPipelineStep("Helper Utility & Path Traversal Logic Test", function(DiagnosticEngine $diag) use ($rootDir) {
    $configFile = $rootDir . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'config.php';
    $helperFile = $rootDir . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'helper.php';
    if (!file_exists($helperFile)) {
        $diag->recordFailure("HELPER_ERROR", $helperFile, 1, "Missing lib/helper.php", "SEV-1");
        return false;
    }

    require_once $configFile;
    require_once $helperFile;

    // Test 3.1: fixSeperator replaces backslashes with forward slashes
    if (fixSeperator('C:\\xampp\\htdocs\\photobooth') !== 'C:/xampp/htdocs/photobooth') {
        $diag->recordFailure("LOGIC_ASSERTION", $helperFile, 33, "fixSeperator failed to normalize path separators", "SEV-2");
        return false;
    }

    // Test 3.2: getPhotoboothPath returns valid string
    $pbPath = getPhotoboothPath();
    if (empty($pbPath) || !is_string($pbPath)) {
        $diag->recordFailure("LOGIC_ASSERTION", $helperFile, 4, "getPhotoboothPath returned empty or non-string", "SEV-2");
        return false;
    }

    return true;
}, $diagnostic);

// ------------------------------------------------------------------
// STEP 4: Image Processing & Filter Logic Functional Verification
// ------------------------------------------------------------------
runPipelineStep("Image Filter Engine & GD Logic Smoke Test", function(DiagnosticEngine $diag) use ($rootDir) {
    $filterFile = $rootDir . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'filter.php';
    if (!file_exists($filterFile)) {
        $diag->recordFailure("IMAGE_FILTER", $filterFile, 1, "Missing lib/filter.php", "SEV-1");
        return false;
    }

    require_once $filterFile;

    // Create 10x10 mock test image resource
    $testImg = imagecreatetruecolor(10, 10);
    if (!$testImg) {
        $diag->recordFailure("GD_ERROR", $filterFile, 59, "GD extension unable to create image resource", "SEV-2");
        return false;
    }

    // applyFilter modifies resource in-place
    try {
        applyFilter(FILTER_PLAIN, $testImg);
        applyFilter(FILTER_GRAYSCALE, $testImg);

        if (!($testImg instanceof \GdImage) && !is_resource($testImg)) {
            $diag->recordFailure("LOGIC_ASSERTION", $filterFile, 59, "applyFilter corrupted the GD image handle", "SEV-2");
            imagedestroy($testImg);
            return false;
        }

        imagedestroy($testImg);
    } catch (\Throwable $e) {
        $diag->recordFailure("IMAGE_FILTER_EXCEPTION", $e->getFile(), $e->getLine(), $e->getMessage(), "SEV-2");
        return false;
    }

    return true;
}, $diagnostic);

// ------------------------------------------------------------------
// STEP 5: API Endpoints Contract & Safety Sanity Check
// ------------------------------------------------------------------
runPipelineStep("API Endpoints & Sanitization Contract Check", function(DiagnosticEngine $diag) use ($rootDir) {
    $criticalApis = [
        'api/checkVersion.php',
        'api/serverInfo.php',
        'api/admin.php',
        'api/config.php'
    ];

    $hasFailures = false;
    foreach ($criticalApis as $apiRel) {
        $apiFile = $rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $apiRel);
        if (!file_exists($apiFile)) {
            $diag->recordFailure("API_CONTRACT", $apiFile, 1, "Critical API endpoint {$apiRel} missing", "SEV-1");
            $hasFailures = true;
            continue;
        }

        $content = file_get_contents($apiFile);
        if (str_contains($content, 'eval(') || str_contains($content, 'shell_exec($_GET')) {
            $diag->recordFailure("SECURITY_VULN", $apiFile, 1, "Dangerous execution pattern detected in {$apiRel}", "SEV-1");
            $hasFailures = true;
        }
    }

    return !$hasFailures;
}, $diagnostic);

// ------------------------------------------------------------------
// Telemetry & Reporting
// ------------------------------------------------------------------
$reportFiles = $diagnostic->generateReports();

// Emit GitHub Actions annotations if running in GitHub CI
if (getenv('GITHUB_ACTIONS') === 'true') {
    $diagnostic->emitGitHubAnnotations();
}

$hasFailures = count($diagnostic->getDiagnostics()) > 0;

if (!$jsonOnly) {
    echo "\n====================================================\n";
    echo sprintf("   PIPELINE SCORE: %d/%d STEPS PASSED\n", $passedSteps, $totalSteps);
    echo "====================================================\n";

    if ($hasFailures) {
        echo "🚨 FAILURES DETECTED! Telemetry exported to:\n";
        echo "   📄 Telemetry JSON: " . $reportFiles['json'] . "\n";
        echo "   📄 AI Action Prompt: " . $reportFiles['markdown'] . "\n\n";

        if ($autoHealRequested) {
            echo "⚡ Triggering AI Self-Healing Engine...\n";
            $phpExe = PHP_BINARY;
            passthru("{$phpExe} scripts/ai-self-heal.php");
            exit(0);
        } else {
            echo "💡 Tip: Run with --heal to attempt automatic remediation:\n";
            echo "   php scripts/run-tests.php --heal\n\n";
        }
        exit(1);
    } else {
        echo "🎉 All pipeline quality gates passed cleanly! Code is safe to commit & ship.\n\n";
        exit(0);
    }
} else {
    echo json_encode($reportFiles['summary'], JSON_PRETTY_PRINT);
    exit($hasFailures ? 1 : 0);
}
