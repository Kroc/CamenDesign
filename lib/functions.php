<?php //functions.php
/* ====================================================================================================================== */
/* Camen Design v.4 © Copyright (CC-BY) Kroc Camen 2013
   licenced under Creative Commons Attribution 3.0 <creativecommons.org/licenses/by/3.0/deed.en_GB>
   you may do whatever you want to this code as long as you give credit to Kroc Camen, <camendesign.com>
*/

//unfortunately this is the cheapest and most reliable way of doing this, despite being a bad way of going about it
function mimeType ($s_extension) {
	//you can pass a full filename if you want to be lazy (saves extra code elsewhere)
	switch (pathinfo (strtolower ($s_extension), PATHINFO_EXTENSION)) {
		//images
		case 'gif':				return 'image/gif';			break;
		case 'jpg': case 'jpeg': 		return 'image/jpeg';			break;
		case 'png':				return 'image/png';			break;
		//code
		case 'asp':				return 'text/asp';			break;
		case 'css':				return 'text/css';			break;
		case 'html':				return 'text/html';			break;
		case 'js':				return 'application/javascript';	break;
		case 'php':				return 'application/x-httpd-php';	break;
		//documents
		case 'pdf':				return 'application/pdf';		break;
		case 'txt': case 'do': case 'log':	return 'text/plain';			break;
		case 'rem':				return 'text/remarkable';		break;
		//downloads
		case 'exe': case 'dmg':			return 'application/octet-stream';	break;
		case 'sh':				return 'application/x-sh';		break;
		case 'zip':				return 'application/zip';		break;
		//media
		case 'mp3':				return 'audio/mpeg';			break;
		case 'oga':				return 'audio/ogg';			break;
		case 'ogv':				return 'video/ogg';			break;
		//fonts
		case 'ttf':				return 'font/ttf';			break;
		case 'otf':				return 'font/otf';			break;
		case 'woff':				return 'font/x-woff';			break;
		default:				return 'application/octet-stream';	break;
	}
}

//convert an HTML string to a text representation for titles that don’t allow HTML (e.g. rss, `<title>`)
function rssTitle ($s_string) {
	//replace some HTML tags with text equivalents. this is done so that formatting in the titles can be conveyed to
	//people in their RSS readers, where an `<em>` might stress a word in a particulary important way &c.
	$s_string = str_replace (
		array ('<strong>', '</strong>', '<em>', '</em>', '<q>', '</q>', '<code>', '</code>', '<br />'),
		array ('*'       , '*'        , '_'   , '_'    , '“'  , '”'   , '`'     , '`',       ' '     ),
		$s_string
	);
	//strip out any remaining HTML tags
	//PHP’s `strip_tags` also strips double encoded tags (i.e. `&lt;...&gt;`) which we do not want
	return preg_replace ('/<[^<]+?>/', '', $s_string);
}

/* =================================================================================================== code is art === */ ?>