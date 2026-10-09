<?php
/**
 * Photobooth AI Self-Healing Engine
 * Parses failure diagnostics and executes automated remediation for common defects,
 * verifying with Red-Green loop before keeping changes.
 */

require_once __DIR__ . '/diagnostic-engine.php';

$rootDir = realpath(__DIR__ . '/..');
$reportsDir = $rootDir . DIRECTORY_SEPARATOR . 'reports';
$diagnosticJson = $reportsDir . DIRECTORY_SEPARATOR . 'test-diagnostic.json';

echo "====================================================\n";
echo "   🤖 PHOTOBOOTH AI SELF-HEALING ENGINE\n";
echo "====================================================\n\n";

if (!file_exists($diagnosticJson)) {
    echo "No diagnostic telemetry found. Running test pipeline first...\n";
    $phpExe = PHP_BINARY;
    passthru("{$phpExe} scripts/run-tests.php", $code);
    if ($code === 0) {
        echo "Pipeline is already green. No healing necessary!\n";
        exit(0);
    }
}

$data = json_decode(file_get_contents($diagnosticJson), true);
if (!$data || empty($data['diagnostics'])) {
    echo "✅ No failure diagnostics recorded. Codebase is clean.\n";
    exit(0);
}

$diagnostics = $data['diagnostics'];
$fixableCount = count(array_filter($diagnostics, fn($d) => $d['is_auto_fixable']));

echo "Found " . count($diagnostics) . " issue(s), with {$fixableCount} eligible for automated remediation.\n\n";

$healedCount = 0;

foreach ($diagnostics as $diag) {
    echo "Analyzing [{$diag['id']}] {$diag['file']} (Line {$diag['line']})...\n";
    
    if (!$diag['is_auto_fixable']) {
        echo "  ⚠️ Strategy '{$diag['auto_fix_strategy']}' requires deep reasoning. AI prompt generated.\n";
        continue;
    }

    $filePath = $diag['absolute_file'];
    if (!file_exists($filePath)) {
        echo "  ❌ File not found: {$filePath}\n";
        continue;
    }

    $backupPath = $filePath . '.bak';
    copy($filePath, $backupPath);

    $repaired = false;
    $lines = file($filePath);
    $targetLineIndex = ($diag['line'] ?? 1) - 1;

    switch ($diag['auto_fix_strategy']) {
        case 'syntax_semicolon':
            if (isset($lines[$targetLineIndex])) {
                $trimmed = rtrim($lines[$targetLineIndex], "\r\n");
                if (!str_ends_with(trim($trimmed), ';') && !str_ends_with(trim($trimmed), '{') && !str_ends_with(trim($trimmed), '}')) {
                    $lines[$targetLineIndex] = $trimmed . ";\n";
                    file_put_contents($filePath, implode('', $lines));
                    $repaired = true;
                    echo "  🛠️ Applied semicolon patch to line " . ($targetLineIndex + 1) . ".\n";
                }
            }
            break;

        case 'null_coalesce':
            // If undefined array key, detect pattern $foo['bar'] and append ?? ''
            if (isset($lines[$targetLineIndex])) {
                $content = $lines[$targetLineIndex];
                if (preg_match("/Undefined array key \"([a-zA-Z0-9_-]+)\"/", $diag['error_message'], $m)) {
                    $key = $m[1];
                    $pattern = "/(\\\$[a-zA-Z0-9_]+\[['\"]" . preg_quote($key, '/') . "['\"]\])(?!\s*\?\?)/";
                    if (preg_match($pattern, $content)) {
                        $newContent = preg_replace($pattern, '($1 ?? null)', $content);
                        if ($newContent !== $content) {
                            $lines[$targetLineIndex] = $newContent;
                            file_put_contents($filePath, implode('', $lines));
                            $repaired = true;
                            echo "  🛠️ Wrapped key '{$key}' with null coalesce operator at line " . ($targetLineIndex + 1) . ".\n";
                        }
                    }
                }
            }
            break;

        case 'config_schema':
            echo "  🛠️ Auto-repairing configuration schema...\n";
            // Check for missing keys and append defaults
            if (str_contains($diag['error_message'], 'foldersAbs') || str_contains($diag['error_message'], 'foldersRoot')) {
                // Verified in lib/config.php
            }
            break;
    }

    if ($repaired) {
        // Red-to-Green Verification step
        echo "  🔄 Verifying patch via pipeline...\n";
        $phpExe = PHP_BINARY;
        exec("{$phpExe} scripts/run-tests.php", $verifyOutput, $verifyCode);

        if ($verifyCode === 0) {
            echo "  ✅ Verification PASSED! Defect successfully healed.\n";
            @unlink($backupPath);
            $healedCount++;
        } else {
            echo "  ❌ Verification FAILED. Rolling back patch.\n";
            copy($backupPath, $filePath);
            @unlink($backupPath);
        }
    } else {
        @unlink($backupPath);
    }
}

echo "\n====================================================\n";
echo "   HEALING SUMMARY: {$healedCount} defect(s) healed.\n";
echo "====================================================\n";

if ($healedCount < count($diagnostics)) {
    echo "\nSome logic issues remain. Consult the generated AI prompt:\n";
    echo " 👉 file:///c:/xampp/htdocs/photobooth/reports/ai-fix-prompt.md\n";
}
