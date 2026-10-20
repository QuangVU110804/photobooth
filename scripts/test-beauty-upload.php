<?php
$module=__DIR__.'/../lib/beauty-upload.php';if(is_file($module))require_once $module;
function uploadCheck($ok,$label){if(!$ok)throw new RuntimeException($label);}
uploadCheck(function_exists('decodeBeautyImage'),'GPU image handoff must have server validation');
$dir=sys_get_temp_dir().'/beauty-upload-'.bin2hex(random_bytes(8));mkdir($dir);
try {
    $im=imagecreatetruecolor(120,80);imagefill($im,0,0,0xb4826e);imagejpeg($im,$dir.'/raw.jpg');imagedestroy($im);
    $bytes=file_get_contents($dir.'/raw.jpg');$hash=hash('sha256',$bytes);
    $url='data:image/jpeg;base64,'.base64_encode($bytes);
    uploadCheck(decodeBeautyImage($url,$dir.'/raw.jpg')===$bytes,'Valid same-size JPEG accepted without saving another file');
    foreach(['','data:image/png;base64,'.base64_encode($bytes),'data:image/jpeg;base64,not-base64',str_repeat('x',33554433)] as $bad){
        try{decodeBeautyImage($bad,$dir.'/raw.jpg');throw new RuntimeException('Invalid image accepted');}catch(InvalidArgumentException $ok){}
    }
    $im=imagecreatetruecolor(80,120);imagejpeg($im,$dir.'/wrong.jpg');imagedestroy($im);
    $rotated='data:image/jpeg;base64,'.base64_encode(file_get_contents($dir.'/wrong.jpg'));
    try{decodeBeautyImage($rotated,$dir.'/raw.jpg');throw new RuntimeException('Wrong dimensions accepted');}catch(InvalidArgumentException $ok){}
    $exif="Exif\0\0II\x2a\0\x08\0\0\0".pack('v',1).pack('vvVv',0x112,3,1,6)."\0\0".pack('V',0);
    file_put_contents($dir.'/raw.jpg',substr($bytes,0,2)."\xff\xe1".pack('n',strlen($exif)+2).$exif.substr($bytes,2));
    uploadCheck(is_string(decodeBeautyImage($rotated,$dir.'/raw.jpg')),'Upright result of EXIF portrait accepted');
    uploadCheck(count(glob($dir.'/*'))===2,'Validation writes no intermediate photos');
} finally {foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
echo "GPU photo handoff validation passed\n";
