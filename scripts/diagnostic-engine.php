<?php
/**
 * Photobooth AI Diagnostic Engine
 * Captures failures, extracts code snippets, analyzes patterns,
 * and generates structured telemetry for automated AI self-healing.
 */

class DiagnosticEngine {
    private string $rootDir;
    private string $reportsDir;
    private array $diagnostics = [];

    public function __construct(string $rootDir) {
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->reportsDir = $this->rootDir . DIRECTORY_SEPARATOR . 'reports';
        if (!is_dir($this->reportsDir)) {
            @mkdir($this->reportsDir, 0777, true);
        }
    }

    /**
     * Record a failure incident with full context
     */
    public function recordFailure(string $category, string $file, ?int $line, string $message, string $severity = 'SEV-2'): void {
        $cleanPath = str_replace($this->rootDir . DIRECTORY_SEPARATOR, '', $file);
        $cleanPath = str_replace('\\', '/', $cleanPath);

        $snippet = null;
        if ($line !== null && file_exists($file)) {
            $snippet = $this->extractCodeSnippet($file, $line, 6);
        }

        $suggestedFix = $this->analyzeProbableCause($message, $snippet);

        $this->diagnostics[] = [
            'id' => 'DIAG-' . strtoupper(substr(md5($category . $cleanPath . $line . $message), 0, 8)),
            'timestamp' => date('c'),
            'category' => $category,
            'severity' => $severity,
            'file' => $cleanPath,
            'absolute_file' => $file,
            'line' => $line,
            'error_message' => trim($message),
            'code_snippet' => $snippet,
            'probable_cause' => $suggestedFix['cause'],
            'suggested_remediation' => $suggestedFix['action'],
            'is_auto_fixable' => $suggestedFix['auto_fixable'],
            'auto_fix_strategy' => $suggestedFix['strategy']
        ];
    }

    /**
     * Extract context code window around the error line
     */
    private function extractCodeSnippet(string $filePath, int $targetLine, int $contextRadius = 5): array {
        $lines = @file($filePath);
        if ($lines === false) {
            return [];
        }

        $totalLines = count($lines);
        $start = max(1, $targetLine - $contextRadius);
        $end = min($totalLines, $targetLine + $contextRadius);

        $snippet = [];
        for ($i = $start; $i <= $end; $i++) {
            $snippet[] = [
                'line_number' => $i,
                'is_target' => ($i === $targetLine),
                'content' => rtrim($lines[$i - 1], "\r\n")
            ];
        }

        return $snippet;
    }

    /**
     * Pattern analysis to determine root cause and whether auto-patching is viable
     */
    private function analyzeProbableCause(string $message, ?array $snippet): array {
        $lower = strtolower($message);

        // Pattern 1: Missing semicolon or syntax token
        if (str_contains($lower, 'syntax error') || str_contains($lower, 'parse error')) {
            if (str_contains($lower, "unexpected ';'") || str_contains($lower, "expecting ';'")) {
                return [
                    'cause' => 'Missing semicolon or invalid statement terminator.',
                    'action' => 'Inspect line termination and ensure all PHP statements end with a semicolon.',
                    'auto_fixable' => true,
                    'strategy' => 'syntax_semicolon'
                ];
            }
            if (str_contains($lower, 'unexpected end of file') || str_contains($lower, 'unclosed')) {
                return [
                    'cause' => 'Unclosed bracket, parenthesis or string literal.',
                    'action' => 'Check balanced delimiters { }, [ ], ( ) and string quotes.',
                    'auto_fixable' => false,
                    'strategy' => 'syntax_brackets'
                ];
            }
            return [
                'cause' => 'PHP Syntax/Parse violation.',
                'action' => 'Correct PHP syntax based on the error token indicator.',
                'auto_fixable' => false,
                'strategy' => 'syntax_general'
            ];
        }

        // Pattern 2: Undefined Array Key (PHP 8.2 Warning/Notice)
        if (str_contains($lower, 'undefined array key')) {
            return [
                'cause' => 'Accessing non-existent array index without fallback (PHP 8.2 deprecation/notice).',
                'action' => 'Apply null coalescing operator ?? or isset() check before accessing key.',
                'auto_fixable' => true,
                'strategy' => 'null_coalesce'
            ];
        }

        // Pattern 3: Call to undefined function/method
        if (str_contains($lower, 'call to undefined function') || str_contains($lower, 'undefined method')) {
            return [
                'cause' => 'Function or helper is missing or not imported via require/include.',
                'action' => 'Verify function declaration or ensure the appropriate lib helper is required.',
                'auto_fixable' => false,
                'strategy' => 'missing_function'
            ];
        }

        // Pattern 4: Missing configuration key
        if (str_contains($lower, 'missing essential config') || str_contains($lower, 'config key')) {
            return [
                'cause' => 'Configuration schema validation mismatch.',
                'action' => 'Add the missing key to config/config.inc.php and lib/config.php with default value.',
                'auto_fixable' => true,
                'strategy' => 'config_schema'
            ];
        }

        // Pattern 5: Logic assertion failure
        if (str_contains($lower, 'assert') || str_contains($lower, 'failed') || str_contains($lower, 'mismatch')) {
            return [
                'cause' => 'Business logic or functional assertion failed.',
                'action' => 'Review function implementation logic against expected behavior in test suite.',
                'auto_fixable' => false,
                'strategy' => 'logic_assertion'
            ];
        }

        return [
            'cause' => 'Runtime anomaly or unhandled error state.',
            'action' => 'Review call stack and runtime parameters.',
            'auto_fixable' => false,
            'strategy' => 'manual_review'
        ];
    }

    /**
     * Persist telemetry report in JSON and AI Fix Prompt in Markdown
     */
    public function generateReports(): array {
        $jsonFile = $this->reportsDir . DIRECTORY_SEPARATOR . 'test-diagnostic.json';
        $mdFile = $this->reportsDir . DIRECTORY_SEPARATOR . 'ai-fix-prompt.md';

        $summary = [
            'run_id' => 'RUN-' . date('Ymd-His'),
            'timestamp' => date('c'),
            'total_failures' => count($this->diagnostics),
            'has_failures' => count($this->diagnostics) > 0,
            'auto_fixable_count' => count(array_filter($this->diagnostics, fn($d) => $d['is_auto_fixable'])),
            'diagnostics' => $this->diagnostics
        ];

        // 1. Write structured JSON
        file_put_contents($jsonFile, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // 2. Write AI Fix Prompt Markdown
        $md = "# 🤖 AI Self-Healing Diagnostic & Remediation Guide\n\n";
        $md .= "> **Generated by Photobooth Senior Dev & DevOps Pipeline**\n";
        $md .= "> Timestamp: `" . date('Y-m-d H:i:s T') . "` | Total Failures: `" . count($this->diagnostics) . "`\n\n";

        if (empty($this->diagnostics)) {
            $md .= "✅ **All pipeline checks passed cleanly. No remediation required.**\n";
        } else {
            $md .= "## 🚨 Action Required: Detected Failures\n\n";
            $md .= "AI Coding Assistant should read this artifact, locate the exact lines, analyze the cause, and perform atomic code edits to fix the root cause.\n\n";

            foreach ($this->diagnostics as $index => $diag) {
                $num = $index + 1;
                $md .= "### Failure #{$num}: [{$diag['severity']}] `{$diag['file']}`\n\n";
                $md .= "- **Diagnostic ID**: `{$diag['id']}`\n";
                $md .= "- **Category**: {$diag['category']}\n";
                $md .= "- **Target File**: `{$diag['file']}` (Line: " . ($diag['line'] ?? 'N/A') . ")\n";
                $md .= "- **Error Message**: `{$diag['error_message']}`\n";
                $md .= "- **Probable Cause**: {$diag['probable_cause']}\n";
                $md .= "- **Auto-Fixable**: " . ($diag['is_auto_fixable'] ? "✅ Yes" : "⚠️ Requires AI Logic Analysis") . "\n";
                $md .= "- **Remediation Action**: {$diag['suggested_remediation']}\n\n";

                if (!empty($diag['code_snippet'])) {
                    $md .= "#### Code Context Snippet:\n```php\n";
                    foreach ($diag['code_snippet'] as $item) {
                        $marker = $item['is_target'] ? '>>> ' : '    ';
                        $md .= sprintf("%s%4d | %s\n", $marker, $item['line_number'], $item['content']);
                    }
                    $md .= "```\n\n";
                }

                $md .= "#### AI Correction Prompt Checklist:\n";
                $md .= "1. [ ] Check file `{$diag['file']}` at line " . ($diag['line'] ?? 'N/A') . ".\n";
                $md .= "2. [ ] Apply minimal targeted patch to resolve `{$diag['error_message']}`.\n";
                $md .= "3. [ ] Re-run `php scripts/run-tests.php` to verify failure is cleared.\n\n";
                $md .= "---\n\n";
            }
        }

        file_put_contents($mdFile, $md);

        return [
            'json' => $jsonFile,
            'markdown' => $mdFile,
            'summary' => $summary
        ];
    }

    /**
     * Print GitHub Actions format error annotations
     */
    public function emitGitHubAnnotations(): void {
        foreach ($this->diagnostics as $diag) {
            $line = $diag['line'] ?? 1;
            $msg = str_replace(["\r", "\n"], ' ', $diag['error_message']);
            echo "::error file={$diag['file']},line={$line},title={$diag['category']}::{$msg}\n";
        }
    }

    public function getDiagnostics(): array {
        return $this->diagnostics;
    }
}
