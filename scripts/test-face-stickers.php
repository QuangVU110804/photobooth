<?php
/** PNG/GD fixtures catch alpha loss, signed-rotation errors, unsafe payloads and partial writes. */
$root = dirname(__DIR__);
if (($argv[1] ?? '') === '--bench-child') {
    require $argv[2];
    $kind = $argv[3]; $rows = [];
    if ($kind === 'paired') require $argv[4];
    foreach ([[1280,720],[1800,1200],[3840,2160]] as [$width,$height]) foreach ([1,4] as $faces) {
        $elements = [];
        foreach (array_slice([[.25,.3],[.7,.3],[.25,.7],[.7,.7]],0,$faces) as [$cx,$cy]) {
            if ($kind === 'png') $elements[] = ['type'=>'sticker','effect'=>'gold-crown','part'=>0,'center'=>[$cx,$cy],'width'=>.18,'angle'=>.2];
            else foreach ([[-.06,-.02],[.01,-.02],[-.01,-.01],[.01,-.03],[-.05,-.03]] as [$x,$y]) {
                $elements[]=['color'=>'ink','points'=>[[$cx+$x,$cy+$y],[$cx+$x+.06,$cy+$y],[$cx+$x+.06,$cy+$y+.03],[$cx+$x,$cy+$y+.03]]];
            }
        }
        $samples=[];
        for($i=0;$i<45;$i++) {
            $image=imagecreatetruecolor($width,$height);
            $start=hrtime(true);applyFaceEffects($image,$elements);$elapsed=(hrtime(true)-$start)/1e6;
            imagedestroy($image);if($i>=5)$samples[]=$elapsed;
        }
        sort($samples);
        $row=['width'=>$width,'height'=>$height,'faces'=>$faces,'median_ms'=>round(($samples[19]+$samples[20])/2,4),'p95_ms'=>round($samples[37],4),'php_heap_peak_mib'=>round(memory_get_peak_usage(true)/1048576,2)];
        if ($kind === 'paired') {
            // Alternate order on the same allocated/hot surface to remove
            // process-order/CPU/cache noise from sub-millisecond comparisons.
            $current=[];$before=[];$image=imagecreatetruecolor($width,$height);
            for($i=0;$i<45;$i++)foreach(($i%2?['applyFaceEffects','legacyApplyFaceEffects']:['legacyApplyFaceEffects','applyFaceEffects']) as $fn){
                $start=hrtime(true);for($j=0;$j<8;$j++)$fn($image,$elements);$ms=(hrtime(true)-$start)/8e6;
                if($i>=5){if($fn==='applyFaceEffects')$current[]=$ms;else $before[]=$ms;}
            }
            imagedestroy($image);sort($current);sort($before);
            $row['paired_current_ms']=round(($current[19]+$current[20])/2,4);
            $row['paired_before_ms']=round(($before[19]+$before[20])/2,4);
            $row['paired_change_percent']=round(($row['paired_current_ms']/$row['paired_before_ms']-1)*100,2);
        }
        $rows[]=$row;
    }
    echo json_encode($rows);exit;
}
if (($argv[1] ?? '') === '--endpoint') {
    // Reuse the isolated endpoint runner; it preloads config without production writes.
    $argv = [__FILE__, '--endpoint', $argv[2], $argv[3]];
    require __DIR__ . '/test-filter-effects.php';
    exit;
}
if (!is_file($root . '/lib/face-stickers.php')) { fwrite(STDERR, "FAIL: PNG sticker PHP renderer must exist\n"); exit(1); }
require_once $root . '/lib/face-effects.php';
$checks = 0;
function stickerCheck($value, $label) { global $checks; $checks++; if (!$value) throw new RuntimeException('FAIL: ' . $label); }
function stickerReject(callable $callback, $label) { try { $callback(); } catch (InvalidArgumentException $e) { stickerCheck(true,$label);return; } throw new RuntimeException('FAIL: '.$label); }
function stickerRgb($im,$x,$y) { $n=imagecolorat($im,$x,$y);return [($n>>16)&255,($n>>8)&255,$n&255]; }
function stickerNear($actual,$expected,$label,$tolerance=3) { stickerCheck(max(array_map(fn($a,$b)=>abs($a-$b),$actual,$expected))<=$tolerance,$label.' '.json_encode($actual)); }
function stickerCleanup($dir) { foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f) { $f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname()); }rmdir($dir); }
$dir=sys_get_temp_dir().'/photobooth-stickers-'.bin2hex(random_bytes(8));
mkdir($dir);mkdir($dir.'/stickers');
$catalog=['version'=>1,'effects'=>[['id'=>'test','title'=>'Test','parts'=>[['file'=>'test.png','anchor'=>'eyes','width'=>1]]]]];
file_put_contents($dir.'/catalog.json',json_encode($catalog));
$asset=imagecreatetruecolor(20,10);imagealphablending($asset,false);imagesavealpha($asset,true);
imagefill($asset,0,0,imagecolorallocatealpha($asset,0,0,0,127));
imagefilledrectangle($asset,0,0,9,7,imagecolorallocatealpha($asset,240,20,40,0));
imagefilledrectangle($asset,10,0,19,7,imagecolorallocatealpha($asset,20,220,40,63));
imagepng($asset,$dir.'/stickers/test.png');imagedestroy($asset);
$entry=['type'=>'sticker','effect'=>'test','part'=>0,'center'=>[.5,.5],'width'=>.2,'angle'=>0];
try {
    $parsed=stickerCatalog($dir);
    stickerCheck($parsed['test']['parts'][0]['opacity']===1.0,'Default opacity matches JS');
    stickerCheck(parseFaceSticker((object)$entry,$dir)===$entry,'Valid tagged payload accepted');
    foreach(['center'=>[NAN,0],'width'=>0,'angle'=>M_PI+.1,'part'=>4,'effect'=>'missing','file'=>'test.png','type'=>'other'] as $key=>$value) {
        $bad=$entry;$bad[$key]=$value;stickerReject(fn()=>parseFaceSticker((object)$bad,$dir),'Reject invalid '.$key);
    }
    foreach([
        fn($c)=>array_replace($c,['version'=>2]),
        fn($c)=>array_replace($c,['effects'=>[$c['effects'][0],$c['effects'][0]]]),
        fn($c)=>['version'=>1,'effects'=>[array_replace($c['effects'][0],['id'=>'glasses'])]],
    ] as $change) {file_put_contents($dir.'/catalog.json',json_encode($change($catalog)));stickerReject(fn()=>stickerCatalog($dir),'Reject malformed catalog');}
    foreach(['../test.png','https://example.com/test.png','missing.png','not-png.png'] as $file) {
        file_put_contents($dir.'/stickers/not-png.png','not a png');
        $bad=$catalog;$bad['effects'][0]['parts'][0]['file']=$file;file_put_contents($dir.'/catalog.json',json_encode($bad));
        stickerReject(fn()=>stickerCatalog($dir),'Reject unsafe/invalid asset '.$file);
    }
    file_put_contents($dir.'/catalog.json',json_encode($catalog));
    $large=imagecreatetruecolor(1536,1536);imagepng($large,$dir.'/stickers/large.png');imagedestroy($large);
    $bad=$catalog;$bad['effects'][0]['parts'][0]['file']='large.png';file_put_contents($dir.'/catalog.json',json_encode($bad));
    stickerCheck(stickerCatalog($dir)['test']['parts'][0]['imageWidth']===1536,'PNG up to 1536px accepted for new medical assets');
    $large=imagecreatetruecolor(1537,1);imagepng($large,$dir.'/stickers/large.png');imagedestroy($large);
    $bad=$catalog;$bad['effects'][0]['parts'][0]['file']='large.png';file_put_contents($dir.'/catalog.json',json_encode($bad));
    stickerReject(fn()=>stickerCatalog($dir),'Oversized PNG dimensions rejected before decoding');
    file_put_contents($dir.'/stickers/big.png',file_get_contents($dir.'/stickers/test.png').str_repeat('x',2097153));
    $bad['effects'][0]['parts'][0]['file']='big.png';file_put_contents($dir.'/catalog.json',json_encode($bad));
    stickerReject(fn()=>stickerCatalog($dir),'PNG byte limit enforced before decoding');
    copy($dir.'/stickers/test.png',$dir.'/outside.png');
    if (@symlink($dir.'/outside.png',$dir.'/stickers/link.png')) {
        $bad['effects'][0]['parts'][0]['file']='link.png';file_put_contents($dir.'/catalog.json',json_encode($bad));
        stickerReject(fn()=>stickerCatalog($dir),'Symlink cannot escape sticker directory');
    }
    file_put_contents($dir.'/catalog.json',json_encode($catalog));
    stickerReject(fn()=>stickerBudget(4000001,1,0),'Oversized render surface rejected');
    stickerReject(fn()=>stickerBudget(2001,2000,0),'Surface area budget enforced');
    $memoryLimit=ini_get('memory_limit');ini_set('memory_limit','64M');
    stickerReject(fn()=>stickerBudget(100,100,67108864),'Reserved GD bytes count against memory limit');ini_set('memory_limit',$memoryLimit);
    foreach([0,M_PI/2,-M_PI/2] as $angle) {
        $im=imagecreatetruecolor(100,100);imagefill($im,0,0,imagecolorallocate($im,100,100,100));
        $e=$entry;$e['angle']=$angle;applyFaceStickers($im,[$e],$dir);
        if($angle===0){stickerNear(stickerRgb($im,44,48),[240,20,40],'Opaque red');stickerNear(stickerRgb($im,54,48),[60,160,70],'Half-transparent green');stickerNear(stickerRgb($im,45,54),[100,100,100],'Transparent bottom');}
        elseif($angle>0){stickerNear(stickerRgb($im,51,44),[240,20,40],'Clockwise red above center');stickerNear(stickerRgb($im,51,54),[60,160,70],'Clockwise green below center');}
        else {stickerNear(stickerRgb($im,48,55),[240,20,40],'Counterclockwise red below center');stickerNear(stickerRgb($im,48,45),[60,160,70],'Counterclockwise green above center');}
        stickerNear(stickerRgb($im,5,5),[100,100,100],'Rotation leaves background unchanged');imagedestroy($im);
    }
    $opaque=$catalog;$opaque['effects'][0]['parts'][0]['opacity']=.5;file_put_contents($dir.'/catalog.json',json_encode($opaque));
    $im=imagecreatetruecolor(100,100);imagefill($im,0,0,imagecolorallocate($im,100,100,100));applyFaceStickers($im,[$entry],$dir);
    stickerNear(stickerRgb($im,44,48),[170,60,70],'Catalog opacity blends opaque source',3);imagedestroy($im);
    file_put_contents($dir.'/catalog.json',json_encode($catalog));
    $im=imagecreatetruecolor(100,100);imagefill($im,0,0,imagecolorallocate($im,100,100,100));
    $clipped=$entry;$clipped['center']=[0,.5];applyFaceStickers($im,[$clipped],$dir);stickerNear(stickerRgb($im,4,48),[60,160,70],'Partial offscreen is clipped');
    $clipped['center']=[-1,-1];applyFaceStickers($im,[$clipped],$dir);stickerNear(stickerRgb($im,99,99),[100,100,100],'Fully offscreen does nothing');imagedestroy($im);
    $shipped=stickerCatalog();stickerCheck(isset($shipped['gold-crown']),'Original PNG catalog resolves');
    $shot=['type'=>'sticker','effect'=>'gold-crown','part'=>0,'center'=>[.5,.5],'width'=>.4,'angle'=>0];
    $raw=['shot.jpg'=>[$shot]];stickerCheck(parseFaceEffects(json_encode($raw))===$raw,'Existing payload map supports PNG');
    stickerReject(fn()=>parseFaceEffects(json_encode(['shot.jpg'=>array_fill(0,17,$shot)])),'17 PNG elements rejected');
    $bad=$shot;$bad['file']='crown.png';stickerReject(fn()=>parseFaceEffects(json_encode(['shot.jpg'=>[$shot,$bad]])),'Late invalid element rejects complete payload');

    // Actual API writes to an isolated app copy; production config/data is untouched.
    foreach(['app/api','app/lib','app/config','app/resources/face-effects/stickers','data','tmp','images','thumbs','keying','print','qrcodes'] as $folder) mkdir($dir.'/'.$folder,0755,true);
    foreach(glob($root.'/lib/*.php') as $file)copy($file,$dir.'/app/lib/'.basename($file));
    copy($root.'/api/applyEffects.php',$dir.'/app/api/applyEffects.php');copy($root.'/config/config.inc.php',$dir.'/app/config/config.inc.php');
    copy($root.'/resources/face-effects/catalog.json',$dir.'/app/resources/face-effects/catalog.json');
    foreach(glob($root.'/resources/face-effects/stickers/*.png') as $source)copy($source,$dir.'/app/resources/face-effects/stickers/'.basename($source));
    $base=imagecreatetruecolor(200,100);imagefill($base,0,0,imagecolorallocate($base,100,100,100));imagejpeg($base,$dir.'/tmp/shot.jpg',100);imagedestroy($base);
    $post=['file'=>'shot.jpg','style'=>'photo','filter'=>'plain','faceEffects'=>json_encode($raw)];
    $request=function($post,$overrides=[])use($dir){$p=proc_open([PHP_BINARY,__FILE__,'--endpoint',$dir,$dir.'/app'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode(['post'=>$post,'overrides'=>array_replace_recursive(['picture'=>['flip'=>'off']],$overrides)]));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);stickerCheck($code===0&&!preg_match('/Warning|Fatal|Deprecated|Notice/',$out.$err),'Endpoint exits cleanly '.$out.$err);return [$out,$err];};
    [$out,$err]=$request($post);stickerCheck(str_contains($err,'STATUS:200')&&is_file($dir.'/images/shot.jpg'),'Actual API persists sticker JPG');
    $saved=imagecreatefromjpeg($dir.'/images/shot.jpg');$rgb=stickerRgb($saved,100,50);stickerNear($rgb,[222,61,110],'Center gemstone survives JPEG export',25);imagedestroy($saved);
    foreach(['hat','mask'] as $id) {
        stickerCheck(isset($shipped[$id]),'New medical sticker is registered: '.$id);
        $medical=$shot;$medical['effect']=$id;
        [$out,$err]=$request(array_replace($post,['faceEffects'=>json_encode(['shot.jpg'=>[$medical]])]));
        stickerCheck(str_contains($err,'STATUS:200'),'API exports new medical PNG: '.$id);
        $saved=imagecreatefromjpeg($dir.'/images/shot.jpg');$changed=0;
        for($y=0;$y<100;$y++)for($x=0;$x<200;$x++)if(array_sum(array_map(fn($v)=>abs($v-100),stickerRgb($saved,$x,$y)))>50)$changed++;
        stickerCheck($changed>20,'Medical PNG is visible in exported JPG: '.$id);imagedestroy($saved);
    }
    $before=hash_file('sha256',$dir.'/images/shot.jpg');$post['faceEffects']=json_encode(['shot.jpg'=>[$shot,$bad]]);[$out,$err]=$request($post);
    stickerCheck(str_contains($err,'STATUS:400')&&hash_file('sha256',$dir.'/images/shot.jpg')===$before,'Invalid late element leaves existing JPG unchanged');
    $large=imagecreatetruecolor(3840,2160);imagejpeg($large,$dir.'/tmp/large.jpg',80);imagedestroy($large);
    $tooWide=$shot;$tooWide['width']=2;
    [$out,$err]=$request(['file'=>'large.jpg','style'=>'photo','filter'=>'plain','faceEffects'=>json_encode(['large.jpg'=>[$tooWide]])]);
    $error=json_decode($out,true);
    stickerCheck(str_contains($err,'STATUS:500')&&isset($error['error'])&&!is_file($dir.'/images/large.jpg')&&!is_file($dir.'/thumbs/large.jpg'),'Over-budget render returns controlled JSON error before writes');
    mkdir($dir.'/app/resources/img/frames',0755,true);
    $frame=imagecreatetruecolor(200,100);imagealphablending($frame,false);imagesavealpha($frame,true);
    imagefill($frame,0,0,imagecolorallocatealpha($frame,0,0,0,127));imagefilledrectangle($frame,0,0,199,3,imagecolorallocate($frame,20,40,230));imagepng($frame,$dir.'/app/resources/img/frames/test.png');imagedestroy($frame);
    foreach ([['plain','off','0',true],['cssgram-inkwell','horizontal','0',true],['cssgram-reyes','vertical','90',false],['cssgram-moon','both','180',true]] as $i=>[$filter,$flip,$rotation,$keep]) {
        $name='variant-'.$i.'.jpg';$control='control-'.$i.'.jpg';
        copy($dir.'/tmp/shot.jpg',$dir.'/tmp/'.$name);copy($dir.'/tmp/shot.jpg',$dir.'/tmp/'.$control);
        $e=$shot;$e['center']=[.3,.6];$e['width']=.25;
        $settings=['picture'=>['flip'=>$flip,'rotation'=>$rotation,'keep_original'=>$keep]];
        $p=['file'=>$name,'style'=>'photo','filter'=>$filter,'frame'=>'test.png','faceEffects'=>json_encode([$name=>[$e]])];
        [$out,$err]=$request($p,$settings);stickerCheck(str_contains($err,'STATUS:200'),'PNG/filter/frame/flip/rotation combination '.$i.' succeeds');
        stickerCheck(is_file($dir.'/tmp/'.$name)===$keep,'keep_original honored for PNG '.$i);
        unset($p['faceEffects']);$p['file']=$control;$request($p,$settings);
        $a=imagecreatefromjpeg($dir.'/images/'.$name);$b=imagecreatefromjpeg($dir.'/images/'.$control);$changed=0;
        for($y=0;$y<imagesy($a);$y++)for($x=0;$x<imagesx($a);$x++){
            $rgbA=stickerRgb($a,$x,$y);$rgbB=stickerRgb($b,$x,$y);
            if(array_sum(array_map(fn($v,$w)=>abs($v-$w),$rgbA,$rgbB))>50)$changed++;
        }
        stickerCheck($changed>10,'PNG remains visible through '.$filter.'/'.$flip.'/'.$rotation.' pipeline');
        stickerCheck(is_file($dir.'/thumbs/'.$name),'PNG combination creates thumbnail');imagedestroy($a);imagedestroy($b);
    }
    if (in_array('--benchmark',$argv,true)) {
        $bench=function($helper,$kind,$extra=''){$p=proc_open([PHP_BINARY,__FILE__,'--bench-child',$helper,$kind,$extra],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($err);return json_decode($out,true,512,JSON_THROW_ON_ERROR);};
        $report=['php'=>PHP_VERSION,'platform'=>PHP_OS_FAMILY,'samples'=>40,'warmup'=>5,'units'=>'milliseconds; PHP heap peak excludes native GD allocations','png'=>$bench($root.'/lib/face-effects.php','png'),'legacy_current'=>$bench($root.'/lib/face-effects.php','polygon')];
        $baseline=$root.'/reports/png-stickers-legacy.php';
        if(is_file($baseline)) {
            $source=file_get_contents($baseline);$body=substr($source,strpos($source,'function applyFaceEffects('));
            file_put_contents($dir.'/legacy-renderer.php',"<?php\n".str_replace('function applyFaceEffects(', 'function legacyApplyFaceEffects(', $body));
            $report['legacy_paired']=$bench($root.'/lib/face-effects.php','paired',$dir.'/legacy-renderer.php');
        }
        echo 'BENCHMARK '.json_encode($report,JSON_UNESCAPED_SLASHES)."\n";
    }
    echo "$checks PNG sticker PHP/GD/API checks passed\n";
} finally {stickerCleanup($dir);}
