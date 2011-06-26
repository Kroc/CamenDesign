<?php //gives a directory listing for a folder
/* ====================================================================================================================== */
include "shared.php";

/* ============================================================================================================ input === */

$path = (preg_match ('/^(?:(\.(?!\.)|[^.])+\/)*$/', @$_GET['path']) ? $_GET['path'] : false) or errorPage (
	'malformed_request.rem', 'Error: Malformed Request', array ('URL' => '?path=path/to/use/')
);

//<uk3.php.net/manual/en/function.is-dir.php#70005>
@chdir (APP_ROOT.$path) or errorPageHTTP (404);

$dir = preg_grep ('/^_/', array_diff (
	//other folders / files to ignore
	scandir ('.'), array ('.', '.DS_Store', 'Thumbs.db')
), PREG_GREP_INVERT);

//sort folders first, then by type, then alphabetically
//<camendesign.com/code/php_directory_sorting>
usort ($dir, create_function ('$a,$b', '
	return	is_dir ($a)
		? (is_dir ($b) ? strnatcasecmp ($a, $b) : -1)
		: (is_dir ($b) ? 1 : (
			strcasecmp (pathinfo ($a, PATHINFO_EXTENSION), pathinfo ($b, PATHINFO_EXTENSION)) == 0
			? strnatcasecmp ($a, $b)
			: strcasecmp (pathinfo ($a, PATHINFO_EXTENSION), pathinfo ($b, PATHINFO_EXTENSION))
		))
	;
'));


/* ========================================================================================================== process === */

//take each of the items in the directory and collect meta data
foreach ($dir as &$dir_item) {
	//select the icon to use
	switch (pathinfo ($dir_item, PATHINFO_EXTENSION)) {
		case '':  //directory
		$icon = $dir_item == '..' ? 'design/icons/parent.png' : 'design/icons/folder.png';
		break;
		
		//documents
		case 'html':	$icon = 'design/icons/html.png';	break;
		case 'txt':
		case 'log':
		case 'do':	$icon = 'design/icons/txt.png';		break;
		case 'pdf':	$icon = 'design/icons/pdf.png';		break;
		case 'rem':	$icon = 'design/icons/rem.png';		break;
		
		//code
		case 'php':	$icon = 'design/icons/php.png';		break;
		case 'css':	$icon = 'design/icons/css.png';		break;
		case 'js':	$icon = 'design/icons/js.png';		break;
		case 'sh':	$icon = 'design/icons/sh.png';		break;
		
		//media
		case 'mp3':	$icon = 'design/icons/mp3.png';		break;
		case 'ogg':
		case 'ogv':
		case 'oga':	$icon = 'design/icons/ogg.png';		break;
		
		//misc
		case 'zip':	$icon = 'design/icons/zip.png';		break;
		case 'ttf':
		case 'otf':
		case 'woff':	$icon = 'design/icons/font.png';	break;
		
		//images: generate a thumbnail
		//todo: fix this up, and move to separate PHP file for parallelism
		case 'jpg':
		case 'jpeg':
		case 'png':
		//does the thumbnail already exist?
		$icon = ".cache/$path".pathinfo ($dir_item, PATHINFO_FILENAME).'!ICON.png';
		if (!file_exists (APP_ROOT.$icon)) {
			//create a blank, transparent, canvas to work on
			$buffer = imagecreatetruecolor (128, 128);
			imagesavealpha ($buffer, true);
			imagefill ($buffer, 0, 0, imagecolorallocatealpha ($buffer, 0, 0, 0, 127));
			imagealphablending ($buffer, true);
			
			//load the requested file
			$info = getimagesize (APP_ROOT."$path/$dir_item");
			switch ($info[2]) {
				case IMAGETYPE_JPEG : $image = imagecreatefromjpeg (APP_ROOT."$path/$dir_item") or exit; break;
				case IMAGETYPE_PNG  : $image = imagecreatefrompng  (APP_ROOT."$path/$dir_item") or exit; break;
			}
			
			//determine scale
			if ($info[1] > $info[0]) {
				$height = 100;
				$width  = 100 * ($info[0] / $info[1]);
			} else {
				$height = 100 * ($info[1] / $info[0]);
				$width  = 100;
			}
			
			//draw on the frame border
			/*
			imagefilledrectangle (
				$buffer,
				((128-$width) / 2) - 14, ((128-$height) / 2) - 14,
				((128-$width) / 2) + $width + 14, ((128-$height) / 2) + $height + 14,
				0x000000
			);
			imagefilter ($buffer, IMG_FILTER_SMOOTH, 0);
			*/
			imagefilledrectangle (
				$buffer,
				((128-$width) / 2) - 10, ((128-$height) / 2) - 10,
				((128-$width) / 2) + $width + 10, ((128-$height) / 2) + $height + 10,
				0xF8F8F8
			);
			
			//paint on the image itself to the icon
			imagecopyresampled (
				$buffer, $image,
				(128-$width) / 2, (128-$height) / 2, 0, 0,
				$width, $height, $info[0], $info[1]
			);
			imagedestroy ($image);
			
			//before saving the cache, replicate any sub folders in the cache area too
			@mkdir (APP_CACHE.$path, 0777, true);
			
			//save the image
			imagepng  ($buffer, APP_ROOT.$icon) or errorPageHTTP (403);

			//free memory
			imagedestroy ($buffer);
		}
		break;
		
		default:	$icon = 'design/icons/document.png';
	}
	
	@$html .= template_tags (template_load ('dir.item.html'), array (
		'NAME' => $dir_item,
		'ICON' => $icon,
		'URL'  => '/'.$path.$dir_item.(is_dir ($dir_item) ? '/' : ''),
		'MIME' => is_dir ($dir_item) ? '' : template_tag (
			template_load ('dir.item.mime.html'), 'MIME', mimeType ($dir_item)
		)
	));
}


/* =========================================================================================================== output === */

exit (templatePage (template_tags (template_load ('dir.html'), array (
	'TITLE'	=> $path,
	'DIR'	=> $html
)), $path, templateHeader ($path)));


/* =================================================================================================== code is art === */ ?>