<?php
/**
 * Test Suite: Scroll interaction from preview caption button to templates section.
 * Verifies contract, accessibility attributes, and non-regression of core actions.
 */

$rootDir = realpath(__DIR__ . '/..');
$modernTemplate = file_get_contents($rootDir . '/template/modern.template.php');
$hmuFramesJs = file_get_contents($rootDir . '/resources/js/hmu-frames.js');
$hmuCss = file_get_contents($rootDir . '/resources/css/hmu-premium.css');

$failures = [];

// 1. Template verification
if (!preg_match('/<section[^>]+id=["\']hmu-templates["\'][^>]*class=["\'][^"\']*hmu-templates/i', $modernTemplate)) {
    $failures[] = "modern.template.php: <section> must have id='hmu-templates' and class='hmu-templates'.";
}

if (!preg_match('/<div[^>]*class=["\'][^"\']*hmu-preview-caption[^"\']*["\'][^>]*>[\s\S]*?<a[^>]+href=["\']#hmu-templates["\']/i', $modernTemplate)) {
    $failures[] = "modern.template.php: <div class='hmu-preview-caption'> must contain an <a> tag linking to '#hmu-templates'.";
}

// 2. JS verification: Scroll interaction
if (!str_contains($hmuFramesJs, 'hmu-templates') || !str_contains($hmuFramesJs, 'scrollIntoView')) {
    $failures[] = "hmu-frames.js: Must implement smooth scroll to 'hmu-templates' via scrollIntoView.";
}

// 3. CSS verification: Interactive styling
if (!preg_match('/\.hmu-preview-caption[^{]*\{[^}]*cursor:\s*pointer/i', $hmuCss) &&
    !preg_match('/\.hmu-preview-scroll-btn[^{]*\{[^}]*cursor:\s*pointer/i', $hmuCss)) {
    $failures[] = "hmu-premium.css: Must define cursor: pointer for .hmu-preview-caption or .hmu-preview-scroll-btn.";
}

// 4. Non-regression check: Core actions must be preserved
$requiredCoreClasses = ['takePic', 'takeCollage', 'gallery-button'];
foreach ($requiredCoreClasses as $cls) {
    if (!str_contains($modernTemplate, $cls)) {
        $failures[] = "modern.template.php: Core action class '{$cls}' was removed or modified!";
    }
}

if (!str_contains($hmuFramesJs, 'kioskFrameSelector') || !str_contains($hmuFramesJs, 'selectFrame')) {
    $failures[] = "hmu-frames.js: Frame selector logic was modified or corrupted!";
}

if (!empty($failures)) {
    echo "❌ TDD FAILURES:\n";
    foreach ($failures as $f) {
        echo " - " . $f . "\n";
    }
    exit(1);
}

echo "✅ ALL SCROLL INTERACTION TESTS PASSED!\n";
exit(0);
