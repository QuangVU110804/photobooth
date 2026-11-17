<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/frames.php';
$grouped = ($_GET['grouped'] ?? null) === '1';
$directory = __DIR__ . '/../resources/img/frames';
$frames = listPhotoFrames($directory, $grouped);
if (!$grouped) {
    echo json_encode($frames);
    return;
}

header('Cache-Control: no-store');
$other = [];
$folders = [];
$nestedByName = [];
foreach ($frames as $name) {
    $parts = explode('/', $name, 2);
    if (count($parts) === 1) {
        $other[] = $name;
    } else {
        $folders[$parts[0]][] = $name;
        $nestedByName[$parts[1]][] = $name;
    }
}

// Keep legacy frame URLs available without showing identical copies in the topic picker.
$other = array_values(array_filter($other, static function (string $name) use ($directory, $nestedByName): bool {
    if (!isset($nestedByName[$name])) return true;
    $hash = @hash_file('sha256', $directory . '/' . $name);
    foreach ($nestedByName[$name] as $nested) {
        if ($hash !== false && $hash === @hash_file('sha256', $directory . '/' . $nested)) return false;
    }
    return true;
}));

$numberedGroups = [];
$groups = [];
foreach ($folders as $folder => $names) {
    $hasPrefix = preg_match('/^([0-9]+) - (\S.*)$/', (string) $folder, $prefix);
    $label = $hasPrefix ? $prefix[2] : (string) $folder;
    if (in_array($label, ['Khác', 'Tất cả khung'], true)) {
        $other = array_merge($other, $names);
        continue;
    }
    $group = ['label' => $label === '20-11' ? '20/11' : $label, 'frames' => $names];
    if ($hasPrefix) $numberedGroups[(int) $prefix[1]][] = $group;
    else $groups[] = $group;
}
ksort($numberedGroups, SORT_NUMERIC);
$groups = array_merge(array_merge(...array_values($numberedGroups)), $groups);
if ($other) $groups[] = ['label' => 'Khác', 'frames' => $other];
echo json_encode($groups, JSON_UNESCAPED_UNICODE);
