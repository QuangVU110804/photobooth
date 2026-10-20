<?php
define('SERVER_OS', DIRECTORY_SEPARATOR == '\\' || strtolower(substr(PHP_OS, 0, 3)) === 'win' ? 'windows' : 'linux');

require_once __DIR__ . '/arrayDeepMerge.php';
require_once __DIR__ . '/helper.php';

$default_config_file = __DIR__ . '/../config/config.inc.php';
$my_config_file = __DIR__ . '/../config/my.config.inc.php';
$basepath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;

$cmds = [
    'windows' => [
        'take_picture' => [
            'cmd' => 'digicamcontrol\CameraControlCmd.exe /capture /filename %s',
        ],
        'print' => [
            'cmd' => 'rundll32 C:\WINDOWS\system32\shimgvw.dll,ImageView_PrintTo %s Printer_Name',
        ],
        'exiftool' => [
            'cmd' => '',
        ],
        'nodebin' => [
            'cmd' => '',
        ],
        'reboot' => [
            'cmd' => '',
        ],
        'shutdown' => [
            'cmd' => '',
        ],
    ],
    'linux' => [
        'take_picture' => [
            'cmd' => 'gphoto2 --capture-image-and-download --filename=%s',
        ],
        'print' => [
            'cmd' => 'lp -o landscape -o fit-to-page %s',
        ],
        'exiftool' => [
            'cmd' => 'exiftool -overwrite_original -TagsFromFile %s %s',
        ],
        'nodebin' => [
            'cmd' => '/usr/bin/node',
        ],
        'reboot' => [
            'cmd' => '/sbin/shutdown -r now',
        ],
        'shutdown' => [
            'cmd' => '/sbin/shutdown -h now',
        ],
    ],
];

$mailTemplates = [
    'de' => [
        'mail' => [
            'subject' => 'Hier ist dein Bild',
            'text' => 'Hey, dein Bild ist angehangen.',
        ],
    ],
    'en' => [
        'mail' => [
            'subject' => 'Here is your picture',
            'text' => 'Hey, your picture is attached.',
        ],
    ],
    'es' => [
        'mail' => [
            'subject' => 'Aquí está tu foto',
            'text' => 'Hola, tu foto está adjunta.',
        ],
    ],
    'fr' => [
        'mail' => [
            'subject' => 'Voici votre photo',
            'text' => 'Hé, ta photo est attachée.',
        ],
    ],
];

require_once $default_config_file;

$config['take_picture']['cmd'] = $cmds[SERVER_OS]['take_picture']['cmd'];
$config['print']['cmd'] = $cmds[SERVER_OS]['print']['cmd'];
$config['exiftool']['cmd'] = $cmds[SERVER_OS]['exiftool']['cmd'];
$config['nodebin']['cmd'] = $cmds[SERVER_OS]['nodebin']['cmd'];
$config['reboot']['cmd'] = $cmds[SERVER_OS]['reboot']['cmd'];
$config['shutdown']['cmd'] = $cmds[SERVER_OS]['shutdown']['cmd'];

$config['adminpanel']['view_default'] = 'expert';

$config['remotebuzzer']['logfile'] = 'remotebuzzer_server.log';
$config['synctodrive']['logfile'] = 'synctodrive_server.log';
$config['dev']['logfile'] = 'error.log';

$config['ui']['github'] = 'PhotoboothProject';
$config['ui']['branding'] = 'Photobooth';

$defaultConfig = $config;

if (file_exists($my_config_file)) {
    require_once $my_config_file;

    $mailTemplate = $mailTemplates[$config['ui']['language'] ?? $defaultConfig['ui']['language']] ?? $mailTemplates['en'];
    if (empty($config['mail']['subject'])) {
        $config['mail']['subject'] = $mailTemplate['mail']['subject'];
    }
    if (empty($config['mail']['text'])) {
        $config['mail']['text'] = $mailTemplate['mail']['text'];
    }

    $config = array_deep_merge($defaultConfig, $config);
}

// Ignore retired settings from older local configurations.
unset($config['filters'], $config['icons']['filter']);
unset($config['collage'], $config['textoncollage'], $config['remotebuzzer']['collagetime'], $config['remotebuzzer']['collagebutton'], $config['remotebuzzer']['collagegpio'], $config['get_request']['collage'], $config['icons']['take_collage']);

if ($config['dev']['loglevel'] > 0) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

if (file_exists($my_config_file) && !is_writable($my_config_file)) {
    die('Abort. Can not write config/my.config.inc.php.');
} elseif (!file_exists($my_config_file) && !is_writable(__DIR__ . '/../config/')) {
    die('Abort. Can not create config/my.config.inc.php. Config folder is not writable.');
}

if (empty($config['ui']['folders_lang'])) {
    $config['ui']['folders_lang'] = getrootpath(__DIR__ . '/../resources/lang');
}

$config['ui']['folders_lang'] = fixSeperator($config['ui']['folders_lang']);

foreach ($config['folders'] as $key => $folder) {
    if (in_array($key, ['data', 'archives', 'config', 'private'], true)) {
        $path = $basepath . DIRECTORY_SEPARATOR . $folder;
    } else {
        $path = $basepath . DIRECTORY_SEPARATOR . $config['folders']['data'] . DIRECTORY_SEPARATOR . $folder;
        $config['foldersRoot'][$key] = $config['folders']['data'] . DIRECTORY_SEPARATOR . $folder;

    }

    if (!file_exists($path)) {
        if (!mkdir($path, 0755, true)) {
            die("Abort. Could not create $folder.");
        }
    } elseif (!is_writable($path)) {
        die("Abort. The folder $folder is not writable.");
    }

    $path = realpath($path);
    $config['foldersAbs'][$key] = $path;
    if (isset($config['foldersRoot'][$key])) $config['foldersJS'][$key] = fixSeperator(getrootpath($path));
}

if ($config['preview']['mode'] === 'gphoto') {
    $config['preview']['mode'] = 'device_cam';
}

// Preview need to be stopped before we can take an image
if (!empty($config['preview']['killcmd']) && $config['preview']['stop_time'] < $config['picture']['cntdwn_offset']) {
    $config['preview']['stop_time'] = $config['picture']['cntdwn_offset'] + 1;
}

$default_font = realpath($basepath . DIRECTORY_SEPARATOR . 'resources/fonts/GreatVibes-Regular.ttf');
$default_frame = realpath($basepath . DIRECTORY_SEPARATOR . 'resources/img/frames/frame.png');

if (empty($config['picture']['frame']) || !testFile($config['picture']['frame'])) {
    $config['picture']['frame'] = $default_frame;
}

if (empty($config['textonpicture']['font']) || !testFile($config['textonpicture']['font'])) {
    $config['textonpicture']['font'] = $default_font;
}

if (empty($config['print']['frame']) || !testFile($config['print']['frame'])) {
    $config['print']['frame'] = $default_frame;
}

if (empty($config['textonprint']['font']) || !testFile($config['textonprint']['font'])) {
    $config['textonprint']['font'] = $default_font;
}

if (isSubfolderInstall()) {
    $bg_root = getrootpath(__DIR__ . '/../resources/img/bg_stone.jpg');
    $bg_url = fixSeperator($bg_root);
} else {
    $bg_url = '/resources/img/bg_stone.jpg';
}

if (empty($config['background']['defaults'])) {
    $config['background']['defaults'] = 'url(' . $bg_url . ')';
}

if (empty($config['background']['admin'])) {
    $config['background']['admin'] = 'url(' . $bg_url . ')';
}

if (empty($config['background']['chroma'])) {
    $config['background']['chroma'] = 'url(' . $bg_url . ')';
}

if (!empty($config['picture']['frame'])) {
    $pf_root = getrootpath($config['picture']['frame']);
    $config['picture']['htmlframe'] = fixSeperator($pf_root);
}

if (empty($config['webserver']['ip'])) {
    $config['webserver']['ip'] = getPhotoboothIp();
}

require_once __DIR__ . '/photo-receipt.php';
// All sharing surfaces use the same public installation address.
try { $config['qr']['url'] = photoReceiptBaseUrl() . '/photo.php?image='; }
catch (RuntimeException $error) { $config['qr']['url'] = ''; }

$config['photobooth']['version'] = getPhotoboothVersion();
