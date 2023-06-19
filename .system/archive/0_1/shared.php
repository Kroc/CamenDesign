<?php //shared.php / written by kroc camen of camen design
/* ======================================================================================================================= */
//source code pretty printer
require_once 'code.php';
/* ----------------------------------------------------------------------------------------------------------------------- */

//the live server I’m on has a trailing slash, darn inconsistency
define ('_root', rtrim ($_SERVER['DOCUMENT_ROOT'], '/'));

include 'content.php';		//pulling content from the...
include 'database.php';		//...database


/* === templating ======================================================================================================== */
//the simplicity of the next four functions hides their fundamental power. with them you can separate the PHP and HTML 100%
//they will scale from the smallest site like this one, up to an entrprise app or a heavy traffic web2.0 website
//learn them, use them; you will not look back

function loadTemplate ($s_template_name) {
	return file_get_contents (_root."/design/templates/$s_template_name");
}

function replaceTemplateTag (&$s_template, $s_tag, $s_content) {
	$s_template = str_replace ("&__${s_tag}__", $s_content , $s_template);
	return $s_template;
}

function replaceTemplateTagArray (&$s_template, $a_values) {
	foreach ($a_values as $key=>$value) $s_template = replaceTemplateTag ($s_template, $key, $value);
	return $s_template;
}

function repeatTemplate ($s_template, $a_values) {
	return replaceTemplateTagArray ($s_template, $a_values);
}

//return the number of tabs before a template tag marker. this can be used with the `wrapAndIndent` function to format
//database content to the right indent level according the the template
function getTagIndent ($s_tag, &$s_template) {
	//`preg_match` returns the number of matches, anything positive is `true`,
	//so we can return the number of tab-characters from the regex
	return preg_match ("/(\t+)&__${s_tag}__/", $s_template, $_) ? strlen ($_[1]) : 0;
}


/* ======================================================================================================================= */

/* --- validation -------------------------------------------------------------------------------------------------------- */
//php produces a warning if you try access an array item that doesn’t exist
//we also `stripslashes` because of that damned, useless 'Magic Quotes' feature >:[
function get  ($s_key) {return (isset ($_GET [$s_key])) ? stripslashes ($_GET [$s_key]) : '';}
function post ($s_key) {return (isset ($_POST[$s_key])) ? stripslashes ($_POST[$s_key]) : '';}

//used in a number of places to check if a certain tag applies to a post &c. (e.g. licences &c.)
function isTagInList ($s_tag, $s_list) {
	return (strpos ("|$s_list|", "|$s_tag|") !== false);
}

//this is a piece of crap, because my elegant hack to get the mime-type from apache wouldn’t work on my live server :[
function mimeType ($s_extension) {
	switch (strtolower ($s_extension)) {
		//images
		case 'gif':			return 'image/gif';			break;
		case 'jpeg': case 'jpg':	return 'image/jpeg';			break;
		case 'png':			return 'image/png';			break;
		//code
		case 'asp':			return 'text/asp';			break;
		case 'css':			return 'text/css';			break;
		case 'html':			return 'text/html';			break;
		case 'js':			return 'text/javascript';		break;
		case 'php':			return 'application/x-httpd-php';	break;
		//documents
		case 'pdf':			return 'application/pdf';		break;
		case 'txt':			return 'text/plain';			break;
		//downloads
		case 'exe': case 'dmg':		return 'application/octet-stream';	break;
		case 'sh':			return 'application/x-sh';		break;
		case 'zip':			return 'application/zip';		break;
		default:			return '';				break;
	}
}

/* --- formatting -------------------------------------------------------------------------------------------------------- */
//returns a YYYYMMDDHHMMSS timestamp from a unix epoch (number of seconds since 1970)
function timestampFromUnix ($s_unix_epoch = null) {
	//if the parameter is left out, the current time is retrieved
	if (is_null ($s_unix_epoch)) $s_unix_epoch = time ();
	return date ('YmdHis', $s_unix_epoch);
}

//the php date and time functions usually work off of a unix epoch,
//this reverses the above function by converting a YYYYMMDDHHMMSS into unix time
function unixTime ($s_timestamp) {
	//the `mktime` function uses 'hour, minute, second, month, day, year' parameters
	//we just slice the textual timestamp up accordingly
	return mktime (
		(integer) substr ($s_timestamp, 8, 2),
		(integer) substr ($s_timestamp, 10, 2),
		//if seconds are left out of the timestamp, assume 0
		(integer) (strlen ($s_timestamp) > 12 ? substr ($s_timestamp, 12, 2) : 0),
		(integer) substr ($s_timestamp, 4, 2),
		(integer) substr ($s_timestamp, 6, 2),
		(integer) substr ($s_timestamp, 0, 4)
	);
}

//word wraps, and then indents to match. why? I’m anal-retentive about html output and believe that the source should
//always be indented correctly and wrapped accordingly, to allow better debugging and easy readability for those who are
//interested in the source code
function wrapAndIndent ($s_text, $i_dent_count = 2) {
	//indent already existing line breaks
	$indent = str_repeat  ("\t", $i_dent_count);
	$s_text = str_replace ("\n", "\n$indent", $s_text);
	//convert tabs to 8 spaces, for the wrap limit
	$s_text = str_replace ("\t", '        ',  $s_text);
	
	//the reason 125 is used as the wrap margin is because firefox’s view->source window maxmized at
	//1024x768 is 125 chars wide and seems like a modern enough standard for code, compared to the
	//behaviour of writing notepad readme files at 77 chars wide because that’s the viewport of a
	//maximised Notepad window on a 640x480 screen. tabs of 8 are used because this is what firefox &
	//Notepad use, and it encourages reworking code to use less indentation
	$offset = 0;
	while (preg_match (
		//I wrote this, and I still have no solid understanding why it works. essentially it finds
		//the first space character before the wrap margin that is not within an html tag, but also
		//remembers the number of tabs at the beginning of each line
		'/(?:^|\n)(?=.{125})((?:\040{8})+)?(?:.{'.(109-8*$i_dent_count).',}?)(\040)(?![^<]*>)/',
		$s_text, $a_result, PREG_OFFSET_CAPTURE, $offset
	) == 1) $s_text = substr_replace ($s_text, "\n".$a_result[1][0], $offset = $a_result[2][1], 1);
	
	//return sets of 8 spaces back to tabs
	return str_replace ('        ', "\t", $s_text);
}

//convert an html string to a text representation for titles that don’t allow html (e.g. rss, `<title>`)
function rssTitle ($s_string) {
	//replace some html tags with text equivalents. this is done so that formatting in the titles can be conveyed to
	//people in their rss readers, where an `<em>` might stress a word in a particulary important way &c.
	$s_string = str_replace (
		array ('<strong>', '</strong>', '<em>', '</em>', '«', '»', '<code>', '</code>', '<br />'),
		array ('*'       , '*'        , '_'   , '_'    , '“'  , '”'   , '`'     , '`',       ' '     ),
		$s_string
	);
	//strip out any remaining html tags
	return strip_tags ($s_string);
}

//similar to `http_build_query` function, but does so without the extereneous '='s
function makeQueryString ($a_items) {
	$result = '';
	foreach ($a_items as $key => $value) $result .= $key ? (($result ? '&amp;' : '?').$key.($value ? "=$value" : '')) : '';
	return $result;
}

//takes a space delimitated list of tags and splits them into an array similar to `$_GET`, i.e. key=>'',key=>''
function makeTagArray ($s_tag_list) {
	return array_map (
		create_function ('$v', 'return "";'),		//remove the value so you don’t get 0,1,2... instead
		array_flip (explode (' ', $s_tag_list))		//explode the list, and reverse to get tag=>? instead
	);
}

/* ==================================================================================================== code is art === */ ?>