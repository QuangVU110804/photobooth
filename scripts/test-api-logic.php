<?php
/** Isolated regression checks for the project audit. Run with PHP CLI + GD. */
$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/photobooth-api-audit-' . bin2hex(random_bytes(6));
mkdir($fixture);
foreach (['images', 'thumbs', 'tmp', 'keying', 'print', 'qrcodes', 'data', 'archives'] as $folder) mkdir($fixture . '/' . $folder);
$checks = 0;
$failures = [];
function auditCheck($ok, $label) {
    global $checks, $failures;
    $checks++;
    if (!$ok) { $failures[] = $label; echo "FAIL: $label\n"; }
}
function auditRun($code) {
    global $root, $fixture;
    $setup = '$_SERVER["DOCUMENT_ROOT"]=' . var_export(dirname($root), true) . ';$_SERVER["HTTP_HOST"]="localhost";$_SERVER["PHP_SELF"]="audit.php";require ' . var_export($root . '/lib/config.php', true) . ';';
    foreach (['images', 'thumbs', 'tmp', 'keying', 'print', 'qrcodes', 'data', 'archives'] as $folder) {
        $setup .= '$config["foldersAbs"][' . var_export($folder, true) . ']=' . var_export($fixture . '/' . $folder, true) . ';';
    }
    $setup .= '$config["database"]["file"]="audit";$config["database"]["enabled"]=false;$config["dev"]["loglevel"]=0;$config["login"]["enabled"]=false;';
    $setup .= 'register_shutdown_function(function(){fwrite(STDERR,"\nSTATUS:".(http_response_code()?:200));});';
    $p = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $fixture, '-r', "eval(base64_decode('" . base64_encode($setup . $code) . "'));"], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($p);
    preg_match('/STATUS:(\d+)/', $err, $m);
    return ['status' => (int)($m[1] ?? 0), 'body' => $body, 'json' => json_decode($body, true), 'exit' => $exit, 'stderr' => $err];
}
function auditEndpoint($endpoint, $get = [], $post = [], $extra = '') {
    global $root;
    return auditRun('$_GET=' . var_export($get, true) . ';$_POST=' . var_export($post, true) . ';' . $extra . 'chdir(' . var_export(dirname($root . '/' . $endpoint), true) . ');require ' . var_export($root . '/' . $endpoint, true) . ';');
}
function auditError($r, $status, $label) {
    auditCheck($r['exit'] === 0 && $r['status'] === $status && isset($r['json']['error']), $label . ' (got ' . $r['status'] . ', exit ' . $r['exit'] . ')');
    auditCheck(!preg_match('/Warning|Fatal error|Notice|Deprecated/i', $r['stderr'] . $r['body']), $label . ' has no PHP diagnostics');
}
function auditConcurrentWorkers(string $worker): array {
    return auditRun('$workers=[];for($i=0;$i<6;$i++){$workers[]=proc_open([PHP_BINARY,"-r",' . var_export($worker, true) . ',(string)$i],[0=>["file",PHP_OS_FAMILY==="Windows"?"NUL":"/dev/null","r"],1=>["pipe","w"],2=>["pipe","w"]],$pipes);fclose($pipes[1]);fclose($pipes[2]);}foreach($workers as $p){if(proc_close($p)!==0)exit(1);}');
}
try {
    $im = imagecreatetruecolor(40, 30);
    imagejpeg($im, $fixture . '/images/sample.jpg'); imagedestroy($im);
    copy($fixture . '/images/sample.jpg', $fixture . '/tmp/raw.jpg');
    $jpeg = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($fixture . '/images/sample.jpg'));
    file_put_contents($fixture . '/sentinel.jpg', 'outside-photo-directory');
    foreach (['../sentinel.jpg', ['sample.jpg'], "bad\0.jpg"] as $bad) {
        auditError(auditEndpoint('api/download.php', ['image' => $bad]), 400, 'Download rejects unsafe filename');
        auditError(auditEndpoint('api/deletePhoto.php', [], ['file' => $bad]), 400, 'Deletion rejects unsafe filename');
        auditError(auditEndpoint('api/print.php', ['filename' => $bad]), 400, 'Print rejects unsafe filename');
    }
    auditCheck(is_file($fixture . '/sentinel.jpg') && file_get_contents($fixture . '/sentinel.jpg') === 'outside-photo-directory', 'Rejected deletion preserves outside file');
    $im = imagecreatetruecolor(40, 30); imagejpeg($im, $fixture . '/images/sample.jpg'); imagedestroy($im);
    auditError(auditEndpoint('api/download.php', ['image' => 'sample.jpg'], [], '$config["download"]["enabled"]=false;'), 403, 'Download honors disabled configuration');
    auditError(auditEndpoint('api/download.php', ['image' => 'missing.jpg']), 404, 'Missing download is a clean 404');
    $r = auditEndpoint('api/download.php', ['image' => 'sample.jpg']);
    auditCheck($r['body'] === file_get_contents($fixture . '/images/sample.jpg'), 'Valid download preserves bytes');
    $unicodeName = 'Ảnh #1 + bạn.jpg'; copy($fixture . '/images/sample.jpg', $fixture . '/images/' . $unicodeName);
    $r = auditRun('$imagelist=[' . var_export($unicodeName, true) . '];$uiShape="shape";$btnClass="btn";$GALLERY_FOOTER=false;$config["foldersJS"]["images"]="/fixture/images";$config["foldersJS"]["thumbs"]="/fixture/thumbs";include "template/gallery.template.php";');
    auditCheck(strpos($r['body'], '/fixture/images/' . rawurlencode($unicodeName)) !== false, 'Gallery encodes Unicode and reserved filename characters');
    $r = auditEndpoint('slideshow/index.php', [], [], '$config["foldersJS"]["images"]="/fixture/images";$config["foldersJS"]["thumbs"]="/fixture/thumbs";');
    auditCheck(strpos($r['body'], '/fixture/images/' . rawurlencode($unicodeName)) !== false, 'Slideshow encodes Unicode and reserved filename characters');
    auditError(auditEndpoint('api/deletePhoto.php', [], ['file' => 'sample.jpg'], '$config["picture"]["allow_delete"]=false;$config["gallery"]["allow_delete"]=false;'), 403, 'Deletion honors disabled configuration');
    $im = imagecreatetruecolor(40, 30); imagejpeg($im, $fixture . '/images/sample.jpg'); imagedestroy($im);
    auditError(auditEndpoint('api/print.php', ['filename' => 'sample.jpg'], [], '$config["print"]["cmd"]="exit 7";$config["print"]["qrcode"]=false;'), 500, 'Failed printer command cannot report success');
    auditError(auditEndpoint('api/shellCommand.php', [], ['mode' => 'unknown']), 400, 'Unknown shell mode is rejected');
    auditError(auditEndpoint('api/previewCamera.php', [], ['play' => 'invalid']), 400, 'Invalid preview action is rejected');
    $versionFixture = 'class AuditVersionStream {public $context;private $offset=0;public function stream_open($p,$m,$o,&$opened){return true;}public function stream_read($n){$v=substr(base64_decode("PAYLOAD"),$this->offset,$n);$this->offset+=strlen($v);return $v;}public function stream_eof(){return $this->offset>=strlen(base64_decode("PAYLOAD"));}public function stream_stat(){return [];}}stream_wrapper_unregister("https");stream_wrapper_register("https","AuditVersionStream");';
    auditError(auditEndpoint('api/checkVersion.php', [], [], str_replace('PAYLOAD', base64_encode('service unavailable'), $versionFixture)), 503, 'Version API handles invalid upstream response');
    $r = auditEndpoint('api/checkVersion.php', [], [], str_replace('PAYLOAD', base64_encode('{"tag_name":"v4.0.0"}'), $versionFixture));
    auditCheck(($r['json']['updateAvailable'] ?? null) === false, 'Older release is not offered as an update');
    auditError(auditEndpoint('api/diskusage.php'), 400, 'Missing archive action returns one JSON error');
    auditError(auditEndpoint('api/sendPic.php', [], ['sendTo'=>['bad']], '$config["mail"]["enabled"]=true;'), 400, 'Email rejects array recipient');
    auditError(auditEndpoint('api/sendPic.php', [], ['sendTo'=>'test@example.test', 'image'=>['sample.jpg']], '$config["mail"]["enabled"]=true;'), 400, 'Email rejects array filename');
    auditError(auditEndpoint('api/sendPic.php', [], ['sendTo'=>'test@example.test', 'send-link'=>'yes']), 403, 'Disabled email cannot collect addresses');
    auditError(auditEndpoint('api/chromakeying/save.php', [], ['imgData'=>[]]), 400, 'Chroma rejects malformed canvas data');
    auditError(auditEndpoint('api/applyEffects.php', [], ['style'=>'photo']), 400, 'Effects require a raw filename');
    auditError(auditEndpoint('api/applyEffects.php', [], ['file'=>'raw.jpg']), 400, 'Effects require a capture style');
    auditError(auditEndpoint('api/applyEffects.php', [], ['file'=>'raw.jpg','style'=>'photo','filter'=>'plain'], '$config["foldersAbs"]["images"].="/unavailable";'), 500, 'Processed image write failure returns JSON');
    auditError(auditEndpoint('api/applyEffects.php', [], ['file'=>'raw.jpg','style'=>'photo','filter'=>'plain'], '$config["database"]["enabled"]=true;$config["foldersAbs"]["data"].="/unavailable";$config["picture"]["keep_original"]=false;'), 500, 'Database write failure returns JSON');
    auditCheck(is_file($fixture . '/tmp/raw.jpg'), 'Failed indexing preserves original capture');
    $im=imagecreatetruecolor(40,30);ob_start();imagepng($im);$png='data:image/png;base64,'.base64_encode(ob_get_clean());imagedestroy($im);
    auditError(auditEndpoint('api/chromakeying/save.php', [], ['imgData'=>$png], '$config["foldersAbs"]["thumbs"].="/unavailable";'), 500, 'Chroma thumbnail write failure cannot report success');
    auditError(auditEndpoint('api/previewCamera.php', [], ['play'=>'start'], '$config["preview"]["simpleExec"]=true;$config["preview"]["cmd"]="exit 7";'), 500, 'Failed preview command cannot report running');
    auditError(auditEndpoint('api/previewCamera.php', [], ['play'=>'stop'], '$config["preview"]["simpleExec"]=true;$config["preview"]["killcmd"]="exit 7";'), 500, 'Failed stop command cannot report a stopped preview');
    $r = auditEndpoint('api/previewCamera.php', [], ['play'=>'stop'], '$config["preview"]["simpleExec"]=true;$config["preview"]["killcmd"]="echo harmless";');
    auditCheck($r['status'] === 200 && ($r['json']['isRunning'] ?? null) === false, 'Successful simple preview stop retains the response contract');
    auditError(auditEndpoint('api/previewCamera.php', [], ['play'=>'start'], '$config["preview"]["simpleExec"]=false;$config["preview"]["cmd"]="echo not-a-pid";'), 500, 'Invalid asynchronous preview PID returns a JSON error');
    auditError(auditEndpoint('api/print.php', ['filename'=>'sample.jpg'], [], '$config["foldersAbs"]["print"].="/unavailable";$config["print"]["cmd"]="echo harmless %s";'), 500, 'Print rendering failure returns JSON before command');
    foreach (['api/admin.php', 'api/rebuildImageDB.php', 'api/serverInfo.php', 'api/diskusage.php'] as $endpoint) {
        auditError(auditEndpoint($endpoint, [], [], '$config["login"]["enabled"]=true;$config["protect"]["admin"]=true;$config["protect"]["localhost_admin"]=true;'), 401, 'Admin authentication enforced: ' . $endpoint);
    }
    $cam = '$config["preview"]["mode"]="device_cam";$config["preview"]["camTakesPic"]=true;$config["dev"]["demo_images"]=false;';
    foreach ([null, [], 'data:image/jpeg;base64,not-base64', 'data:image/jpeg;base64,' . base64_encode('not jpeg')] as $bad) {
        auditError(auditEndpoint('api/takePic.php', [], ['style' => 'photo', 'file' => 'raw.jpg', 'canvasimg' => $bad], $cam), 400, 'Capture rejects invalid camera payload');
    }
    $truncated = 'data:image/jpeg;base64,' . base64_encode(substr(file_get_contents($fixture . '/images/sample.jpg'), 0, 224));
    auditError(auditEndpoint('api/takePic.php', [], ['style'=>'photo','file'=>'raw.jpg','canvasimg'=>$truncated], $cam), 400, 'Capture rejects truncated JPEG before replacing raw input');
    auditCheck(file_get_contents($fixture . '/tmp/raw.jpg') === file_get_contents($fixture . '/images/sample.jpg'), 'Rejected captures preserve existing raw input');
    $r = auditEndpoint('api/takePic.php', [], ['style' => 'photo', 'canvasimg' => $jpeg], $cam . '$config["picture"]["naming"]="dateformatted";date_default_timezone_set("UTC");$stamp=time();$existing=date("Ymd_His",$stamp);file_put_contents($config["foldersAbs"]["images"]."/audit_".$existing.".jpg","sentinel");');
    auditCheck($r['exit'] === 0 && !empty($r['json']['file']) && !is_file($fixture . '/images/' . $r['json']['file']), 'Capture avoids filename already used by saved image');
    foreach ([['file'=>'raw.jpg','style'=>'photo','filter'=>[]], ['file'=>'raw.jpg','style'=>'photo','filter'=>'unknown']] as $post) {
        auditError(auditEndpoint('api/applyEffects.php', [], $post), 400, 'Effects reject invalid filter before processing');
    }
    file_put_contents($fixture . '/tmp/broken.jpg', 'not jpeg');
    auditError(auditEndpoint('api/applyEffects.php', [], ['file'=>'broken.jpg','style'=>'photo','filter'=>'plain']), 400, 'Corrupt capture is a clean JSON error');
    auditError(auditEndpoint('api/applyEffects.php', [], ['file'=>'missing.jpg','style'=>'photo','filter'=>'plain']), 404, 'Missing raw capture is a clean 404');
    $r = auditRun('require "lib/db.php";file_put_contents(DB_FILE,"{broken");echo json_encode(getImagesFromDB());');
    auditCheck($r['body'] === '[]', 'Malformed database reads as empty list');
    $r = auditRun('require "lib/db.php";file_put_contents(DB_FILE,"null");appendImageToDB("one.jpg");appendImageToDB("one.jpg");echo json_encode(getImagesFromDB());');
    auditCheck($r['exit'] === 0 && $r['json'] === ['one.jpg'], 'Append recovers malformed database and deduplicates');
    $r = auditRun('require "lib/db.php";file_put_contents(DB_FILE,json_encode(["one.jpg"]));deleteImageFromDB("one.jpg");echo json_encode(getImagesFromDB());');
    auditCheck($r['exit'] === 0 && $r['json'] === [], 'Deleting final database entry leaves a readable list');
    foreach (glob($fixture . '/images/*') as $path) { if (basename($path) !== 'sample.jpg' && is_file($path)) unlink($path); }
    file_put_contents($fixture . '/images/not-photo.txt', 'text');
    copy($fixture . '/images/sample.jpg', $fixture . '/images/older.jpg');
    touch($fixture . '/images/older.jpg', 1000000000); touch($fixture . '/images/sample.jpg', 1500000000);
    $r = auditRun('require "lib/db.php";ob_start();rebuildPictureDB();ob_end_clean();echo json_encode(getImagesFromDB());');
    auditCheck($r['json'] === ['older.jpg','sample.jpg'], 'Database rebuild includes only photos in oldest-first order');
    $r = auditRun('require "lib/resize.php";$im=imagecreatetruecolor(120,80);$out=rotateResizeImage($im,45);echo json_encode([imagesx($out),imagesy($out)]);');
    auditCheck($r['json'] === [120,80], 'Arbitrary rotation retains original canvas dimensions');
    $r = auditRun('require "lib/capture.php";$config["database"]["file"]="School Event";try{$name=newCaptureFilename($config);echo json_encode(faceEffectFilename($name));}catch(Throwable $e){echo "false";}');
    auditCheck($r['json'] === true, 'Custom database prefixes with spaces remain capturable and processable');
    auditError(auditEndpoint('api/print.php'), 400, 'Print requires a filename');
    auditError(auditEndpoint('api/sendPic.php', [], ['sendTo'=>'guest@example.com', 'send-link'=>'yes'], '$config["mail"]["enabled"]=true;$config["foldersAbs"]["data"].="/missing";'), 500, 'Email subscription write failure cannot report success');
    $r = auditEndpoint('api/sendPic.php', [], ['sendTo'=>'guest@example.com', 'send-link'=>'yes'], '$config["mail"]["enabled"]=true;');
    auditCheck(($r['json']['saved'] ?? false) === true, 'Valid email subscription is saved');
    // A directory at DB_FILE reproducibly denies a database update even when
    // tests run as an administrator. No permissions or real photos are changed.
    $r = auditEndpoint('api/deletePhoto.php', [], ['file'=>'absent.jpg'], '$config["database"]["enabled"]=true;require_once "lib/db.php";if(is_file(DB_FILE))unlink(DB_FILE);mkdir(DB_FILE);');
    auditError($r, 500, 'Deletion database failure is a JSON error');
    rmdir($fixture . '/data/audit.txt');
    $r = auditRun('require_once "lib/log.php";$path=$config["foldersAbs"]["tmp"]."/".$config["dev"]["logfile"];file_put_contents($path,"existing-log\\n");logError(["marker"=>"new-entry"]);echo json_encode(file_get_contents($path));');
    auditCheck(is_string($r['json']) && strpos($r['json'], "existing-log\n") === 0 && strpos($r['json'], 'new-entry') !== false, 'Logging appends without rewriting existing history');
    $setup = '$_SERVER["DOCUMENT_ROOT"]=' . var_export(dirname($root), true) . ';require ' . var_export($root . '/lib/config.php', true) . ';$config["foldersAbs"]["data"]=' . var_export($fixture . '/data', true) . ';$config["foldersAbs"]["tmp"]=' . var_export($fixture . '/tmp', true) . ';';
    $mailWorker = $setup . '$config["mail"]["file"]="concurrent-mail";require_once ' . var_export($root . '/lib/db.php', true) . ';for($i=0;$i<30;$i++)appendMailAddressToDB("guest-".$argv[1]."-".$i."@example.com");appendMailAddressToDB("shared@example.com");';
    $r = auditConcurrentWorkers($mailWorker);
    $addresses = json_decode(file_get_contents($fixture . '/data/concurrent-mail.txt'), true);
    $expectedAddresses = ['shared@example.com'];
    for ($workerNumber = 0; $workerNumber < 6; $workerNumber++) {
        for ($entry = 0; $entry < 30; $entry++) $expectedAddresses[] = "guest-$workerNumber-$entry@example.com";
    }
    sort($addresses); sort($expectedAddresses);
    auditCheck($r['exit'] === 0 && $addresses === $expectedAddresses, 'Concurrent subscriptions retain every address and deduplicate a shared recipient');
    $logWorker = $setup . '$config["dev"]["logfile"]="concurrent.log";for($i=0;$i<30;$i++)logError(["entry"=>"worker-entry-".$argv[1]."-".$i,"padding"=>str_repeat("x",4096)]);';
    $r = auditConcurrentWorkers($logWorker);
    preg_match_all('/worker-entry-(\d+-\d+)/', file_get_contents($fixture . '/tmp/concurrent.log'), $entries);
    $expectedEntries = [];
    for ($workerNumber = 0; $workerNumber < 6; $workerNumber++) {
        for ($entry = 0; $entry < 30; $entry++) $expectedEntries[] = "$workerNumber-$entry";
    }
    sort($entries[1]); sort($expectedEntries);
    auditCheck($r['exit'] === 0 && $entries[1] === $expectedEntries, 'Concurrent log writes retain all 180 complete entries');
    $worker = '$_SERVER["DOCUMENT_ROOT"]=' . var_export(dirname($root), true) . ';require ' . var_export($root . '/lib/config.php', true) . ';$config["foldersAbs"]["data"]=' . var_export($fixture . '/data', true) . ';$config["database"]["file"]="concurrent";require ' . var_export($root . '/lib/db.php', true) . ';for($i=0;$i<30;$i++)appendImageToDB($argv[1]."-".$i.".jpg");';
    $r = auditRun('$workers=[];for($i=0;$i<6;$i++){$workers[]=proc_open([PHP_BINARY,"-r",' . var_export($worker, true) . ',(string)$i],[0=>["file",PHP_OS_FAMILY==="Windows"?"NUL":"/dev/null","r"],1=>["file",PHP_OS_FAMILY==="Windows"?"NUL":"/dev/null","w"],2=>["file",PHP_OS_FAMILY==="Windows"?"NUL":"/dev/null","w"]],$pipes);}foreach($workers as $p)proc_close($p);echo file_get_contents($config["foldersAbs"]["data"]."/concurrent.txt");');
    auditCheck(is_array($r['json']) && count($r['json']) === 180 && count(array_unique($r['json'])) === 180, 'Concurrent database writers preserve all 180 unique images');
    // Load an independent configuration tree; never edit the real local config.
    mkdir($fixture . '/app/lib', 0755, true); mkdir($fixture . '/app/config');
    mkdir($fixture . '/app/resources/lang', 0755, true);
    mkdir($fixture . '/app/resources/img');
    copy($root . '/resources/img/bg_stone.jpg', $fixture . '/app/resources/img/bg_stone.jpg');
    foreach (glob($root . '/lib/*.php') as $path) copy($path, $fixture . '/app/lib/' . basename($path));
    copy($root . '/config/config.inc.php', $fixture . '/app/config/config.inc.php');
    copy($root . '/package.json', $fixture . '/app/package.json');
    file_put_contents($fixture . '/app/config/my.config.inc.php', '<?php $config=["ui"=>["language"=>"pt"],"mail"=>["subject"=>"","text"=>""]];');
    $configCode = '$_SERVER["DOCUMENT_ROOT"]=' . var_export($fixture, true) . ';require ' . var_export($fixture . '/app/lib/config.php', true) . ';echo json_encode([$config["mail"]["subject"],$config["mail"]["text"],$config["foldersJS"]["images"],$config["ui"]["folders_lang"],$config["background"]["defaults"]]);';
    $r = auditRun('$p=proc_open([PHP_BINARY,"-r",' . var_export($configCode, true) . '],[0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]],$pipes);fclose($pipes[0]);echo stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);proc_close($p);fwrite(STDERR,$err);');
    auditCheck(array_slice($r['json'] ?? [], 0, 2) === ['Here is your picture', 'Hey, your picture is attached.'] && !preg_match('/Warning|Notice/', $r['stderr']), 'Languages without mail templates use English without warnings');
    auditCheck(($r['json'][2] ?? '') === '/app/data/images', 'First configuration load exposes correct URLs for newly created folders');
    auditCheck(($r['json'][3] ?? '') === '/app/resources/lang', 'Language folder does not depend on endpoint working directory');
    auditCheck(($r['json'][4] ?? '') === 'url(/app/resources/img/bg_stone.jpg)', 'Default backgrounds do not depend on endpoint working directory');
    echo "$checks checks, " . count($failures) . " failures\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) { if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($fixture);
}
exit($failures ? 1 : 0);
