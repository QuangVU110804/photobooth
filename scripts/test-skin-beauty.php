<?php
/** Real GD pixel tests; no product configuration or photos are modified. */
$module = __DIR__ . '/../lib/skin-beauty.php';
if (is_file($module)) require_once $module;
function beautyCheck($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
beautyCheck(function_exists('parseBeautyEffects'), 'Skin beauty validation/rendering must exist');
function beautyPixel($im, $x, $y) {
    $v = imagecolorat($im, $x, $y);
    return [($v >> 16) & 255, ($v >> 8) & 255, $v & 255];
}
function beautyFixture() {
    $im = imagecreatetruecolor(128, 128);
    imagefill($im, 0, 0, imagecolorallocate($im, 180, 130, 110));
    return $im;
}
$region = ['outline' => [[.15,.15],[.85,.15],[.85,.85],[.15,.85]],
    'holes' => [[[.4,.4],[.6,.4],[.6,.6],[.4,.6]]]];
$settings = ['smooth' => 0, 'white' => 100, 'faces' => [$region]];
beautyCheck(parseBeautyEffects(null) === [], 'Absent settings are inert');
beautyCheck(parseBeautyEffects(json_encode($settings)) === $settings, 'Valid bounded settings survive validation');
foreach ([[], ['smooth'=>101,'white'=>0,'faces'=>[]], ['smooth'=>'20','white'=>0,'faces'=>[]],
    ['smooth'=>0,'white'=>0,'faces'=>array_fill(0,5,$region)],
    ['smooth'=>0,'white'=>0,'faces'=>[['outline'=>[[3,.2],[.2,.4],[.3,.3]],'holes'=>[]]]],
    ['smooth'=>0,'white'=>0,'faces'=>[$region],'file'=>'../photo.jpg']] as $bad) {
    try { parseBeautyEffects(json_encode($bad)); throw new RuntimeException('Invalid beauty request accepted'); }
    catch (InvalidArgumentException $expected) {}
}
try { parseBeautyEffects(str_repeat(' ', 32769)); throw new RuntimeException('Unbounded request accepted'); }
catch (InvalidArgumentException $expected) {}
$im = beautyFixture();
applySkinBeauty($im, $settings);
beautyCheck(beautyPixel($im, 32, 32)[0] > 180 && beautyPixel($im, 32, 32)[1] > 130, 'Whitening lifts only detected skin');
beautyCheck(beautyPixel($im, 2, 2) === [180,130,110], 'Background is bit-exact');
beautyCheck(beautyPixel($im, 64, 64) === [180,130,110], 'Eye/lip exclusion remains bit-exact');
imagedestroy($im);
$im = beautyFixture();
imagesetpixel($im, 32, 32, imagecolorallocate($im, 160, 110, 90));
$off = $settings; $off['white'] = 0;
applySkinBeauty($im, $off);
beautyCheck(beautyPixel($im, 32, 32) === [160,110,90], 'Zero levels preserve source pixels');
$smooth = $off; $smooth['smooth'] = 100;
imagefilledrectangle($im, 77, 25, 82, 45, imagecolorallocate($im, 0, 0, 0));
applySkinBeauty($im, $smooth);
beautyCheck(beautyPixel($im, 32, 32)[0] > 160, 'Smoothing suppresses small blemishes');
beautyCheck(beautyPixel($im, 78, 32)[0] < 15, 'Smoothing protects strong edges');
imagedestroy($im);
$im = beautyFixture(); $settings['faces'] = [];
applySkinBeauty($im, $settings);
beautyCheck(beautyPixel($im, 32, 32) === [180,130,110], 'No face never affects the background');
imagedestroy($im);
$im = beautyFixture(); $one = beautyFixture();
$overlap = $settings; $overlap['faces'] = [$region,$region];
applySkinBeauty($im,$overlap); $overlap['faces'] = [$region]; applySkinBeauty($one,$overlap);
beautyCheck(beautyPixel($im,32,32)===beautyPixel($one,32,32),'Overlapping faces never apply whitening twice');
imagedestroy($im);imagedestroy($one);
beautyCheck(function_exists('skinBeautyRawOrientation'),'DSLR beauty contours must map back to encoded JPEG orientation');
$oriented = skinBeautyRawOrientation(['smooth'=>0,'white'=>100,'faces'=>[['outline'=>[[.1,.2],[.4,.2],[.4,.5]],'holes'=>[]]]],6);
beautyCheck(abs($oriented['faces'][0]['outline'][0][0]-.2)<.000001 && abs($oriented['faces'][0]['outline'][0][1]-.9)<.000001,'Orientation 6 maps displayed contours back to raw pixels');
foreach ([1=>[.1,.2],2=>[.9,.2],3=>[.9,.8],4=>[.1,.8],5=>[.2,.1],6=>[.2,.9],7=>[.8,.9],8=>[.8,.1]] as $orientation=>$expected) {
    $actual=skinBeautyRawOrientation(['smooth'=>0,'white'=>100,'faces'=>[['outline'=>[[.1,.2],[.4,.2],[.4,.5]],'holes'=>[]]]],$orientation)['faces'][0]['outline'][0];
    beautyCheck(abs($actual[0]-$expected[0])<.000001 && abs($actual[1]-$expected[1])<.000001,'EXIF inverse transform '.$orientation);
}
$jpegFile = tempnam(sys_get_temp_dir(),'beauty-exif-');
try {
    $im=beautyFixture();imagejpeg($im,$jpegFile);imagedestroy($im);$jpeg=file_get_contents($jpegFile);
    $exif="Exif\0\0II\x2a\0\x08\0\0\0".pack('v',1).pack('vvVv',0x112,3,1,6)."\0\0".pack('V',0);
    file_put_contents($jpegFile,substr($jpeg,0,2)."\xff\xe1".pack('n',strlen($exif)+2).$exif.substr($jpeg,2));
    beautyCheck(skinBeautyJpegOrientation($jpegFile)===6,'EXIF parser reads real JPEG metadata without another dependency');
    file_put_contents($jpegFile,"\xff\xd8\xff\xe1\0\x20truncated");
    beautyCheck(skinBeautyJpegOrientation($jpegFile)===1,'Truncated metadata degrades to identity');
} finally { unlink($jpegFile); }
echo "Skin beauty request and GD pixel checks passed\n";
