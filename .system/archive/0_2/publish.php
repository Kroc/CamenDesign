<?php //written by kroc camen of camen design
/* =======================================================================================================================
   hello, just a heads-up that all of the code on this site is now yours to use anyway you want anywhere you want, enjoy!
   a more detailed and fun description of the licencing is here: <camendesign.com/blog/when_its_better>
   -----------------------------------------------------------------------------------------------------------------------
   this script generates the website by templating the contents of a data directory containing HTML files for each of the
   articles in the site. this script itself is not run live on the server, instead it produces a folder containing the
   website, that is uploaded. in order to use this script, you’ll need to download the zip file containing the necessary
   folder layout and template files - <camendesign.com/code/files/0.2/camendesign-0.2.zip>
*/
date_default_timezone_set ('Europe/London');

//text mode!
header ('Content-type: text/plain');
ob_start ();

//“ReMarkable” is my Markdown-like syntax for writing in plain text and converting to HTML
//see the ReMarkable folder for documentation, or the website <camendesign.com/code/remarkable>
include "ReMarkable/remarkable.php";


/* =======================================================================================================================
   1. compress CSS
   ======================================================================================================================= */
//compress the CSS file to save bandwidth for both me and the user,
//the .htaccess serves “.csz” as “text/css” with gzip encoding (done here)
msg ('* Compressing CSS file...');
file_put_contents ('./upload/design/design.csz', gzencode (file_get_contents ('./upload/design/design.css'), 9));
msg ("\t\t\t[done]\n");


/* =======================================================================================================================
   2. index data
   ======================================================================================================================= */
msg ('* Indexing data...');

/* get a list of the various content-types from the folders in the site (blog | photo | tweet &c.)
   ----------------------------------------------------------------------------------------------------------------------- */
//`array_fill_keys` is used to flip the array into `"type"=>0` format so we can clock up the counts of each type
$data_types = array_fill_keys (
	//get the list of directory contents and strip out files and “.”, “..”
	array_filter (scandir ('./data/'), create_function ('$v', 'return (strpos($v,".")===false);')),
0);

/* generate a list of all of the content in the site, the date => and the meta/content:
   ----------------------------------------------------------------------------------------------------------------------- */
$data_list = $data_tags = array ();

//get each entry in each content-type folder
foreach ($data_types as $type => &$count) foreach (preg_grep ('/\.rem$/', scandir ("./data/$type/")) as $file_name) {
	//open the file and read the header and HTML
	list ($meta, $content) = explode ("\n\n", file_get_contents ("./data/$type/$file_name"), 2);
	//the header is a JSON object containing the meta-information (date, tags, enclosure &c.)
	$meta = json_decode ($meta, true);
	
	//if the JSON did not decode, there must be a typo - exit here
	if (!$meta) die ("\n\n! JSON header error in: '$type/$file_name'\n\n");
	
	//update information:
	$modified = false;
	//if the file has no date: it’s new. give it a date
	if (!isset ($meta['date']))    {$meta['date']    = date ('YmdHi', time ()); $modified = true;}
	//if the file has no updated meta, add it. the entry will be pushed to the top of the RSS feed
	if (!isset ($meta['updated'])) {$meta['updated'] = date ('YmdHi', time ()); $modified = true;}
	//save the file if the date/updated fields have been changed
	if ($modified) {
		//of course, I could have just used `json_encode` here, but then it wouldn’t be tidy
		file_put_contents ("./data/$type/$file_name",
			"{\t\"date\"\t\t:\t${meta['date']},\n".
			"\t\"updated\"\t:\t${meta['updated']},\n".
			"\t\"title\"\t\t:\t\"".str_replace('"', '\"', $meta['title'])."\"".
			//optional stuff
			(isset ($meta['licence'])   ? ",\n\t\"licence\"\t:\t\"${meta['licence']}\"" : '').
			(isset ($meta['tags'])      ? ",\n\t\"tags\"\t\t:\t[\"".implode ('", "', $meta['tags'])."\"]" : '').
			(isset ($meta['enclosure']) ? ",\n\t\"enclosure\"\t:\t\"${meta['enclosure']}\"\n" : '').
			(isset ($meta['url'])       ? ",\n\t\"src\"\t:\t\"${meta['url']}\"\n" : "\n").
			//the entry’s content
			"}\n\n".$content
		);
	}
	
	//add this entry to the list, including the content and other information to produce the final HTML
	array_push ($data_list, array_merge ($meta, array (
		'type'    => $type,
		'file'    => "$type/$file_name",
		'name'	  => "$type/".substr ($file_name, 0, -4),
		'title'   => reMarkable ('# '.$meta['title'].' #'),
		'content' => formatContent ($content)
	)));
	
	//add to the count for each of the tags
	if (isset ($meta['tags'])) foreach ($meta['tags'] as $tag) @$data_tags[$tag]++;
	
	//add to the count for this content-type
	$count++;
}
arsort ($data_types);	//sort content-types by count, descending (for tag-cloud)
arsort ($data_tags);	//sort tags by count, descending (also for tag-cloud)
//sort entries by date, descending (for home page)
usort ($data_list, create_function ('$a,$b', 'return $a["date"]<$b["date"]?+1:-1;'));

msg ("\t\t\t\t[done]\n");


/* =======================================================================================================================
   3. create index pages
   ======================================================================================================================= */
msg ("* Creating Index pages:\n");
foreach (array_merge (array (''), array_keys ($data_types), array_keys ($data_tags)) as $filter) {
	//filter the list to just entries for a particular tag (or all entries if no filter)
	$data = $filter ? array_filter ($data_list, create_function (
		'$v', 'global $filter;return ($v["type"]==$filter||(isset($v["tags"])?in_array($filter,$v["tags"]):false));'
	)) : $data_list;
	
	msg ("\t\"$filter\"\t\t\t".str_repeat("\t", 1 - (int) (strlen ("\"$filter\"") / 8)));
	
	//create the folder if it doesn’t exist
	if ($filter && !file_exists ("./upload/$filter/")) mkdir ("./upload/$filter/", 0777);
	
	//split into pages
	foreach ($pages = array_chunk ($data, 5, true) as $page => $page_data) {
		//generate a page of content
		$content = ''; foreach ($page_data as $entry) $content .= templateEntry ($entry);
		
		//save the file
		file_put_contents (
			'./upload/'.($filter ? "$filter/" : '').($page+1).'.xhtml',
			gzencode (templatePage (
				$content, $filter, array (),
				//url
				($filter ? "$filter/" : '').($page+1),
				//title
				($filter ? " · $filter" : '').($page ? ' · page '.($page+1) : ''),
				//next page link
				($page != count ($pages)-1) ? replaceTemplateTag (loadTemplate ('nav-next-page'), 'HREF',
					'/'.($filter ? "$filter/" : '').($page+2)
				) : '',
				//previous page link
				$page ? replaceTemplateTag (loadTemplate ('nav-prev-page'), 'HREF',
					'/'.($filter ? "$filter/" : '').($page>1 ? $page : '')
				) : ''
			), 9)
		);
	}
	msg (count ($pages)."\t[done]\n");
}


/* =======================================================================================================================
   4. create permalink pages
   ======================================================================================================================= */
msg ('* Creating Permalink pages...');
foreach ($data_list as &$entry) {
	//for this article’s type, find the previous/next articles
	$data = array_values (array_filter (
		$data_list, create_function ('$v', 'global $entry;return ($v["type"]==$entry["type"]);')
	));
	//find the index of this article
	$index = array_reduce ($data, create_function (
		'$a,$v', 'static $c;$c++;return ($v["date"]=='.$entry['date'].'?$c:$a);'
	)) - 1;
	
	//save the file
	file_put_contents (
		"./upload/${entry['name']}.xhtml",
		gzencode (templatePage (
			//html
			templateEntry ($entry),
			//filter        tags            url             title
			$entry['type'], $entry['tags'], $entry['name'], ' · '.rssTitle ($entry['title']),
			//next, prev article links
			isset ($data[$index+1]) ? replaceTemplateTagArray (loadTemplate ('nav-prev-entry'), array (
				'HREF' => '/'.$data[$index+1]['name'],
				'TYPE' => $entry['type']
			)) : '',
			isset ($data[$index-1]) ? replaceTemplateTagArray (loadTemplate ('nav-next-entry'), array (
				'HREF' => '/'.$data[$index-1]['name'],
				'TYPE' => $entry['type']
			)) : ''
		), 9)
	);
}
msg ("\t\t".count ($data_list)."\t[done]\n");


/* =======================================================================================================================
   5. create 404/410 page
   ======================================================================================================================= */
msg ('* Creating 404/410 pages...');
file_put_contents ('./upload/http-404.xhtml', gzencode (templatePage (
	//html                            filter  tags      url         title     next, prev article links
	loadTemplate ('pages/error-404'), '',     array (), 'http-404', ' · 404', '',   ''), 9)
);
file_put_contents ('./upload/http-410.xhtml', gzencode (templatePage (
	//html                            filter  tags      url         title     next, prev article links
	loadTemplate ('pages/error-410'), '',     array (), 'http-410', ' · 410', '',   ''), 9)
);
msg ("\t\t\t[done]\n");


/* =======================================================================================================================
   6. create projects page
   ======================================================================================================================= */
msg ('* Creating Projects page...');
file_put_contents ('./upload/projects.xhtml', gzencode (templatePage (
	//html                                                    
	reMarkable (file_get_contents ("./server/projects.rem"), 0, 125, './data'),
	//filter  tags      url         title          next, prev
	'',       array (), 'projects', ' · projects', '',   ''
), 9));
msg ("\t\t\t[done]\n");


/* =======================================================================================================================
   7. RSS feeds
   ======================================================================================================================= */
msg ('* Creating RSS feeds...');
foreach (array_merge (array (''), array_keys ($data_types), array_keys ($data_tags)) as $filter) {
	//sort the data by updated date
	usort ($data_list, create_function ('$a,$b', 'return $a["updated"]<$b["updated"]?+1:-1;'));
	
	//filter the list to just entries for a particular tag (or all entries if no filter). limit to 10 items
	$data = array_slice ($filter ? array_filter ($data_list, create_function (
		'$v', 'global $filter;return ($v["type"]==$filter||(isset($v["tags"])?in_array($filter,$v["tags"]):false));'
	)) : $data_list, 0, 10);
	
	$content = '';
	foreach ($data as $item) $content .= replaceTemplateTagArray (loadTemplate ('rss/rss-item'), array (
		//if a title is not given, use the enclosure filename if available
		'TITLE'       => "[${item['type']}] ".($item['title']
			? rssTitle ($item['title'])
			: (isset ($item['enclosure']) ? $item['enclosure'] : '')
		),
		//if an external URL is provided (link content-type), link to that instead
		'URL'         => isset ($item['url']) ? $item['url'] : 'http://camendesign.com/'.$item['name'],
		'DESCRIPTION' => htmlspecialchars (preg_replace (
			'/(href|src)="\//', '$1="http://camendesign.com/',
			(isset ($item['enclosure']) ? templateEnclosure ($item['enclosure'], $item['name']) : '').
			$item['content']
		), ENT_NOQUOTES),
		'DATE'        => gmdate ('r', mktime (
			(integer) substr ($item['updated'], 8,  2),
			(integer) substr ($item['updated'], 10, 2), 0,
			(integer) substr ($item['updated'], 4,  2),
			(integer) substr ($item['updated'], 6,  2),
			(integer) substr ($item['updated'], 0,  4)
		)),
		'ENCLOSURE'   => isset ($item['enclosure'])
			? "\n\t\t<enclosure url=\"http://camendesign.com/${item['name']}/".
			   str_replace('%2F', '/', urlencode ($item['enclosure'])).'" '.
			   'length="'.filesize ("./data/${item['name']}/${item['enclosure']}").'" '.
			   'type="'.mimeType (substr(strrchr($item['enclosure'], '.'), 1)).'" />'
			: '',
		'CATEGORIES'  => isset ($item['tags']) ? array_reduce (
			array_merge (array ($item['type']), $item['tags']),
			create_function ('$a,$b', '$a.="\n\t\t<category>$b</category>";return $a;')
		) : ''
	));
	
	//save the file
	file_put_contents (
		'./upload/'.($filter ? "$filter/rss" : 'rss').'.rsz',
		gzencode (replaceTemplateTagArray (loadTemplate ('rss/rss'), array (
			'DOMAIN'   => 'http://camendesign.com/'.($filter ? "$filter/" : ''),
			'ROOT'     => 'http://camendesign.com/',
			'DATE'     => gmdate ('r'),
			'CATEGORY' => $filter ? "<category>$filter</category>" : '',
			'TITLE'    => $filter ? " · $filter" : '',
			'ITEMS'    => $content
		)), 9)
	);
}
msg ("\t\t\t".count (array_merge (array (''), array_keys ($data_types), array_keys ($data_tags)))."\t[done]\n");



/* / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / / */

//log a message to the screen
function msg ($s_message) {
	echo ($s_message); ob_flush (); flush ();
}

//template the HTML for a content-entry (blog, tweet, art &c.)
function templateEntry (&$a_meta) {	
	//convert timestamp for the date formatting functions below
	$unix_when = mktime (
		(integer) substr ($a_meta['date'], 8,  2),
		(integer) substr ($a_meta['date'], 10, 2), 0,
		(integer) substr ($a_meta['date'], 4,  2),
		(integer) substr ($a_meta['date'], 6,  2),
		(integer) substr ($a_meta['date'], 0,  4)
	);
	
	//this shows the full density of the template system (effectively it is one line)
	$html = replaceTemplateTagArray (loadTemplate ('entry'), array (
		/* --- time ---------------------------------------------------------------------------------------------- */
		'HREF'  => '/'.$a_meta['name'],
		'MON'   => date ('M', $unix_when),	//“A short textual representation of a month, three letters”
		'MONTH' => date ('F', $unix_when),	//“A full textual representation of a month”
		'DAY'   => date ('j', $unix_when),	//“Day of the month without leading zeros”
		'TH'    => date ('S', $unix_when),	//“English ordinal suffix for the day of the month, 2 characters”
		'YEAR'  => date ('y', $unix_when),	//“A two digit representation of a year”
		'DATE'  => date ('c', $unix_when),	//“ISO 8601 date” (for `datetime` attribute of `<time>`)
		'HOUR'  => date ('g', $unix_when),	//“12-hour format of an hour without leading zeros”
		'MINS'  => date ('i', $unix_when),	//“Minutes with leading zeros”
		'AMPM'  => date ('a', $unix_when),	//“Lowercase Ante meridiem and Post meridiem”
		/* --- content ------------------------------------------------------------------------------------------- */
		'TYPE'  => $a_meta['type'],
		//load the template for that content type; blogs are shaped different from tweets and so on
		'ENTRY' => replaceTemplateTag (loadTemplate ("content-types/${a_meta['type']}"),
			'CONTENT', $a_meta['content']
		),
		//title has to be given after content as the `TITLE` template tag is a part of the content template,
		//and not a part of the entry template
		'TITLE' => $a_meta['title'],
		/* --- optional extras ----------------------------------------------------------------------------------- */
		'LICENCE'   => isset ($a_meta['licence']) ? loadTemplate ('licences/'.$a_meta['licence']) : '',
		'ENCLOSURE' => isset ($a_meta['enclosure']) ? templateEnclosure ($a_meta['enclosure'], $a_meta['name']) : '',
		'URL'       => @$a_meta['url'],
		/* --- tags ---------------------------------------------------------------------------------------------- */
		'CONTENT_TYPE' => replaceTemplateTagArray (
			loadTemplate ('entry-tag'),
			array ('TAG' => $a_meta['type'], 'HREF' => "/${a_meta['type']}/")
		),
		//the list of user-tags aside the content-entry
		'TAGS' => isset ($a_meta['tags']) ? array_reduce (
			//template each tag and concenate into a list
			$a_meta['tags'], create_function (
				'$a,$b', 'return $a.=repeatTemplate("'.addslashes (loadTemplate ('entry-tag')).
				'",array("TAG"=>$b,"HREF"=>"/$b/"));'
			)
		) : ''
	));
	return $html;
}

//get the HTML for an enclosure
function templateEnclosure ($s_filename, $s_path) {
	//load the enclosure’s template
	$html = loadTemplate ('content-types/'.reset (explode ('/', $s_path)).'.enclosure');
	
	$enclosure_path = "/$s_path/";
	$enclosure_file = $enclosure_path.$s_filename;
	
	//an image enclosure gets a preview image
	if ($image_size = getimagesize ("./data$enclosure_file")) {
		//is the image a 32-bit PNG (has transparency)
		$is_alpha = ($image_size[2] == IMAGETYPE_PNG)
			? ord (file_get_contents ("./data$enclosure_file", false, null, 25, 1)) & 4
			: false
		;
		//decide the preview file’s file type:
		//* a JPG always has a JPG preview
		//* a PNG has a JPG preview unless it has transparency, resulting in a PNG preview
		$enclosure_preview = $image_size[0]<=640
			? $s_filename
			: pathinfo ($s_filename, PATHINFO_FILENAME).'_preview.'.($is_alpha ? 'png' : 'jpg')
		;
		//if a preview file is required, and it does not exist, create it...
		if (
			$enclosure_preview != $s_filename &&
			!file_exists ("./data$enclosure_path$enclosure_preview")
		) {
			switch ($image_size[2]) {
				case IMAGETYPE_JPEG: $image = imagecreatefromjpeg ("./data$enclosure_file"); break;
				case IMAGETYPE_PNG:  $image = imagecreatefrompng  ("./data$enclosure_file"); break;
			}
			//scale the height according to ratio
			$image_height = 640 * ($image_size[1] / $image_size[0]);
			$image_preview = imagecreatetruecolor (640, $image_height);
			//preserve transparency on PNGs
			if ($is_alpha) imagealphablending ($image_preview, false);
			//resize the image
			imagecopyresampled (
				$image_preview, $image, 0, 0, 0, 0,
				640, $image_height, $image_size[0], $image_size[1]
			);
			imagedestroy ($image);
			
			//save the preview image:
			if ($is_alpha) {
				//if transparent, preview must be PNG,
				imagesavealpha ($image_preview, true);
				imagepng ($image_preview, "./data$enclosure_path$enclosure_preview");
			} else {
				//otherwise, use a JPG preview instead for better filesize
				//resized PNGs are very large due to added colours by anti-alias
				//why 80? <ebrueggeman.com/article_php_image_optimization.php>
				imagejpeg ($image_preview, "./data$enclosure_path$enclosure_preview", 80);
			}
			imagedestroy ($image_preview);
		}
		
		//template the preview HTML
		$image_size = getimagesize ("./data$enclosure_path$enclosure_preview");
		replaceTemplateTagArray ($html, array (
			'ENCLOSURE_PREVIEW' => $enclosure_path.str_replace('%2F', '/', rawurlencode ($enclosure_preview)),
			'ENCLOSURE_WIDTH'   => $image_size[0],
			'ENCLOSURE_HEIGHT'  => $image_size[1]
		));
	}
	
	//insert regular enclosure information
	return replaceTemplateTagArray ($html, array (
		'ENCLOSURE_NAME' => end (explode ('/', htmlspecialchars ($s_filename))),
		'ENCLOSURE_HREF' => $enclosure_path.str_replace('%2F', '/', rawurlencode ($s_filename)),
		'ENCLOSURE_MIME' => mimeType (end (explode ('.', $s_filename))),
		//an inline way to format a file size :)
		'ENCLOSURE_SIZE' => array_reduce (
			array (' B', ' KB', ' MB'), create_function ('$a,$b',
				'return is_numeric($a)?($a>=1024?$a/1024:number_format($a,strlen($b)-2).$b):$a;'
			), filesize ("./data$enclosure_path$s_filename")
		)
	));
}

//generate a whole page's HTML
function templatePage ($s_content, $s_filter, $a_tags, $s_url, $s_title, $s_next, $s_prev) {
	//load the base template for the pages - see </design/templates/html>
	$html = loadTemplate ('html');
	
	replaceTemplateTagArray ($html, array (
		//the “html” link on the page links to the same page, plus “.xhtml” on the end
		'HTML_URL'   => "/$s_url.xhtml",
		'HEAD_TITLE' => $s_title,
		//if viewing a tag, include the rss feed for that
		'HEAD_RSS'   => $s_filter ? replaceTemplateTagArray (loadTemplate ('head'),
			array ('HREF'  => "/$s_filter/rss", 'TITLE' => $s_filter)
		) : ''
	));

	/* --- tag-cloud ------------------------------------------------------------------------------------------------- */
	global $data_types, $data_tags;
	
	$tagcloud_tag   = loadTemplate ('nav-tagcloud-tag');
	$tagcloud_types = '';
	$tagcloud_tags  = '';
	$on = loadTemplate ('nav-tagcloud-tag-on');

	replaceTemplateTag ($html, 'ALL_ON', !$s_filter && $s_url !== 'projects' ? $on : '');
	replaceTemplateTag ($html, 'PROJECTS_ON', $s_url == 'projects' ? $on : '');
	
	foreach ($data_types as $type => $count) $tagcloud_types .= repeatTemplate ($tagcloud_tag, array (
		'TAG'   => $type,
		'HREF'  => "/$type/",
		'COUNT' => $count,
		'ON'    => $s_filter == $type ? $on : ''
	));

	foreach ($data_tags as $tag => $count) $tagcloud_tags .= repeatTemplate ($tagcloud_tag, array (
		'TAG'   => $tag,
		'HREF'  => "/$tag/",
		'COUNT' => $count,
		'ON'    => ($s_filter == $tag || @in_array ($tag, $a_tags)) ? $on : ''
	));

	replaceTemplateTag ($html, 'TAG_CLOUD_TYPES', $tagcloud_types);
	replaceTemplateTag ($html, 'TAG_CLOUD_TAGS',  $tagcloud_tags);
	
	replaceTemplateTagArray ($html, array ('PREV' => $s_prev, 'NEXT' => $s_next));
		
	replaceTemplateTag ($html, 'CONTENT', $s_content);
	
	return $html;
}


/* === templating ======================================================================================================== */
//the simplicity of the next four functions hides their fundamental power. with them you can separate the PHP and HTML 100%
//they will scale from the smallest site like this one, up to an entrprise app or a heavy traffic web2.0 website
//learn them, use them; you will not look back

function loadTemplate ($s_template_name) {
	return file_get_contents ("./templates/$s_template_name");
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


/* ======================================================================================================================= */

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

//convert an HTML string to a text representation for titles that don’t allow html (e.g. rss, `<title>`)
function rssTitle ($s_string) {
	//replace some html tags with text equivalents. this is done so that formatting in the titles can be conveyed to
	//people in their rss readers, where an `<em>` might stress a word in a particulary important way &c.
	$s_string = str_replace (
		array ('<strong>', '</strong>', '<em>', '</em>', '<q>', '</q>', '<code>', '</code>', '<br />'),
		array ('*'       , '*'        , '_'   , '_'    , '“'  , '”'   , '`'     , '`',       ' '     ),
		$s_string
	);
	//strip out any remaining html tags
	return strip_tags ($s_string);
}

//prepare the raw html from the database for display
function formatContent ($s_content, $b_absoluteurls = false) {
	$s_content = reMarkable ($s_content, 0, 125, './data');
	
	//convert PRE blocks
	while (preg_match (
		'/<pre>(?:<code>)?(?!\n<code>)(.*?)(?:<\/code>)?<\/pre>/s', $s_content, $match, PREG_OFFSET_CAPTURE
	)) $s_content = substr_replace ($s_content,
		"<pre>\n".array_reduce (
			//split into lines
			explode ("\n", preg_replace (
				//retain syntax markup tags
				'/&lt;(\/?)(samp|var|dfn|i)&gt;/', '<$1$2>', $match[1][0]
			)),
			create_function ('$a,$v','$a.="<code>$v</code>\n";return $a;')
		)."</pre>",
		$match[0][1], strlen ($match[0][0])
	);
	return $s_content;
}

/* ==================================================================================================== code is art === */ ?>