<?php //where we watermark hotlinked images
/* ====================================================================================================================== */
include "shared.php";

/* ============================================================================================================ input === */

//“hotlink.php?image=path/to/image.png”
$requested = (preg_match ('/^(\.(?!\.)|[-a-z0-9_\/])+\.(jpe?g|png)$/i', @$_GET['image'], $_) ? $_[0] : false) or errorPage (
	'malformed_request.rem', 'Error: Malformed Request', array ('URL' => '?image=path/to/image.png')
);

//does the requested file even exist?
if (!file_exists (APP_ROOT.$requested)) errorPageHTTP (404);


/* ========================================================================================================== process === */

$info = getimagesize (APP_ROOT.$requested);
switch ($info[2]) {
	case IMAGETYPE_JPEG : $image = imagecreatefromjpeg ($requested); break;
	case IMAGETYPE_PNG  : $image = imagecreatefrompng  ($requested); break;
	default             : errorPageHTTP (403);
}
//the image filters will cause transparent images to have a black background,
//so we work on a buffer that already has a white background
$buffer = imagecreatetruecolor ($info[0], $info[1]);
imagefilledrectangle ($buffer, 0, 0, $info[0], $info[1], 0xFFFFFF);

//if the image could not be loaded (too large for the PHP memory buffer),
//then we will just present a blank image (in the same dimensions) instead
if ($image) {
	imagecopy ($buffer, $image, 0, 0, 0, 0, $info[0], $info[1]);
	imagedestroy ($image);
}

//greyscale and dull the image
imagefilter ($buffer, IMG_FILTER_GRAYSCALE);
imagefilter ($buffer, IMG_FILTER_CONTRAST,   50);
imagefilter ($buffer, IMG_FILTER_BRIGHTNESS, 50);

//determine which text will fit on the image
$text = array (
	APP_HOST,
	'do not hotlink'
);
$widths = array (
	25 + (imagefontwidth (3) * strlen ($text[0])),
	25 + (imagefontwidth (1) * strlen ($text[1]))
);

//write on the text shadow
if ($info[0] > $widths[0]) imagestring ($buffer, 3, $info[0]-$widths[0], 15, $text[0], 0x000000);
if ($info[0] > $widths[1]) imagestring ($buffer, 1, $info[0]-$widths[1], 30, $text[1], 0x000000);
imagefilter ($buffer, IMG_FILTER_SMOOTH, 0);

//write on the text
if ($info[0] > $widths[0]) imagestring ($buffer, 3, $info[0]-$widths[0], 15, $text[0], 0xFFFFFF);
if ($info[0] > $widths[1]) imagestring ($buffer, 1, $info[0]-$widths[1], 30, $text[1], 0xFFFFFF);


/* =========================================================================================================== output === */

//before saving the cache, replicate any sub folders in the cache area too
@mkdir (APP_CACHE.dirname ($requested), 0777, true);

//save the image
switch ($info[2]) {
	case IMAGETYPE_JPEG: imagejpeg ($buffer, APP_CACHE.$requested, 80) or errorPageHTTP (403); break;
	case IMAGETYPE_PNG:  imagepng  ($buffer, APP_CACHE.$requested)     or errorPageHTTP (403); break;
}

//free memory
imagedestroy ($buffer);

//dump the file to the browser
header ("Content-Type: ".$info['mime']);
readfile (APP_CACHE.$requested);

/* =================================================================================================== code is art === */ ?>