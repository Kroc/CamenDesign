<?php //code.php / written by kroc camen of camen design
/* ======================================================================================================================= */
//"open source", should mean 'open > source'
//there is no difference between the live copy of the page, and one you could download in a zip, ne?


//"*.php?this-php" shows the source code of the page for regular pages, but is not required for the pages
//in '/code/' that don’t have any HTML to display anyway
if (array_key_exists ('this-php', $_GET) || substr ($_SERVER['REQUEST_URI'], 0, 6) == '/code/') {
	//todo: add line numbers (can use ob_start to post-process the highlight_file dump, or use it as a read)
	//idea: show code and highlight line on error
	
	//define syntax colours (similar to my textmate theme)
	ini_set ("highlight.comment", "#008000");
	ini_set ("highlight.default", "#0000FF");
	ini_set ("highlight.keyword", "#3C4C72");
	ini_set ("highlight.string",  "#000080");
	
	header ('Content-type: text/html;charset=UTF-8');
	exit (preg_replace (
		//hyperlink the includes so people can follow the code easily
		'/((?:include|require)(?:_once)?)(&nbsp;)?<\/span><span style="color: #000080">([\'"])(.*?)\3/',
		'$1$2</span><span style="color: #000080">$3<a href="$4">$4</a>$3',
		//get the source code, pre-syntax coloured
		highlight_file ($_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME'], true)
	));
}

//converts a block of source code into html with line numbers
//this needs to be expanded to do syntax colouring automatically without turning into speghetti code
function markupSyntax ($s_text) {
	return '<pre>'.array_reduce (
		//split into lines
		explode ("\n", preg_replace (
			//retain syntax markup tags
			'/&lt;(\/?)(samp|var|dfn|i)&gt;/', '<$1$2>',
			//also auto-encodes the pre block to save having to type "&gt;" & "&lt;" manually
			htmlspecialchars (trim ($s_text), ENT_NOQUOTES)
		)),
		create_function ('$a,$v','$a.="<code>$v</code>";return $a;')
	).'</pre>';
}

/* ==================================================================================================== code is art === */ ?>