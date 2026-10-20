<?php
require_once __DIR__.'/skin-beauty.php';

/** One in-memory GPU result, bound to the dimensions of this capture. */
function decodeBeautyImage($value,string $original): string {
    $prefix='data:image/jpeg;base64,';
    if(!is_string($value)||strlen($value)>33554432||strncmp($value,$prefix,strlen($prefix))!==0)
        throw new InvalidArgumentException('Invalid processed beauty image');
    $bytes=base64_decode(substr($value,strlen($prefix)),true);
    $info=$bytes!==false?@getimagesizefromstring($bytes):false;
    $raw=@getimagesize($original);
    if(!$info||!$raw||$info[2]!==IMAGETYPE_JPEG||$info[0]*$info[1]>40000000)
        throw new InvalidArgumentException('Invalid processed beauty JPEG');
    [$w,$h]=$raw;
    if(in_array(skinBeautyJpegOrientation($original),[5,6,7,8],true))[$w,$h]=[$h,$w];
    if($info[0]!==$w||$info[1]!==$h)throw new InvalidArgumentException('Processed beauty dimensions do not match capture');
    return $bytes;
}
