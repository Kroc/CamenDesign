<?php //content.php / written by kroc camen of camen design
/* ======================================================================================================================= */
require_once 'code.php';

header ('Content-type: '.(
	//rss?
	array_key_exists ('rss', $_GET) ? 'application/rss+xml' : (
	//show the html source? ('text/plain' will cause the browser to not render the html)
	array_key_exists ('this-html', $_GET) ? 'text/plain' : (
	//we have to serve as xml for a number of reasons, 1) safari can’t handle <legend> correctly, 2) firefox 2 won’t
	//display html5 tags as block, 3) all firefox versions insert an implied `<fieldset>` inbetween `<figure><legend>`
	stristr ($_SERVER['HTTP_ACCEPT'], 'application/xhtml+xml') !== false ? 'application/xhtml+xml' : 'text/html')
)).';charset=UTF-8', false);

/* --- constants --------------------------------------------------------------------------------------------------------- */
//the different content modules. these map to '/design/templates/content-types/<tag>'
define ('_content_types', 'tweet|blog|photo|art|poem|code');
//licences for content. these map to '/design/tempaltes/licences/<tag>'
//(if you’re wondering why 'licence' is spelt wrong, that’s because I’m british, and you’re not)
define ('_licence_tags',  'cc-by|copyright|mit');
//tags used for other categorical purposes, part of the system
define ('_reserved_tags',   _content_types.'|'._licence_tags);
//other words used in the querystring/cache, which thus cannot be used as a tag name
define ('_disallowed_tags', 'this-html|this-php|rss|page|index|tags|cloud');
//identify (rather weakly) timestamps in the querystring
define ('_regex_content_id', "/^(20\d{10})$/");  //YYYYMMDDHHMM


/* === caching =========================================================================================================== */

class cache {
	public $id      = '';
	public $content = '';
	
	function __construct ($s_cache_id = '') {
		$this->id      = $s_cache_id;
		$this->on_disk = (bool) ($this->content = @file_get_contents (_root."/data/cache/$s_cache_id.txt"));
	}
	
	public function save () {
		file_put_contents (_root."/data/cache/$this->id.txt", $this->content);
	}
}

function deleteCache ($s_tags) {
	$to_delete = preg_grep ("/\b(?:$s_tags)\b/", scandir (_root.'/data/cache/'));
	foreach ($to_delete as $filename) unlink (_root."/data/cache/$filename");
}

//what to bear in mind: there are two kinds of cached page -
//* fully cached: the full html from top to bottom is saved as a gzipped php include. the `outputCachedPage` function dumps
//                the file straight to screen if it exsits, and if it doesn’t, creates a `cachedPage` instance to prepare.
//                the php ends when the cache is dumped to screen, so execution stops at the `outputCachedPage` call!
//  exmaples:     the home pages which change only when new content is posted, which are infrequent enough to cache the full
//                html for fast delivery to viewers

//* partial cache: a partially cached page is where some of the page is static content that won’t change often, but some
//                 other template tags remain in place to be processed (generally where something is either per-user, or
//                 updating so frequent as to be no use cached)
//  examples:      input forms, where the form layout is static, but what the user typed has to be injected into the form

class cachedPage extends cache {
	public $gzip = false;	//compressed? (used for fully cached pages, don’t use for partially cached pages)
	
	function __construct ($s_cache_id, $b_gzip = false) {
		$this->gzip = $b_gzip;
		parent::__construct ($s_cache_id);
	}
	
	function getPage ($s_template_name, $s_title = '', $s_filter = '') {
		//load the base template for all pages
		$this->content = loadTemplate ('html');
		
		//page title, if provided
		if (!is_null ($s_title)) replaceTemplateTag ($this->content, 'HEAD_TITLE', $s_title ? " · $s_title" : '');
		
		//the "html" link on the page links to the same page, plus 'this-html' in the querystring
		replaceTemplateTag ($this->content, 'THIS-HTML',
			//remove "index.php" from the URL so it’s flattened to "/?this-html"
			str_replace('index.php', '', $_SERVER['SCRIPT_NAME']).
			makeQueryString (array_merge (array ('this-html' => ''), $_GET))
		);
		
		//load the page-specific template, and inject it into the base template used for all pages
		replaceTemplateTag ($this->content, 'MAIN', loadTemplate ("pages/$s_template_name"));
		
		//the "rss" link on the page also changes as per tag you are viewing
		replaceTemplateTag ($this->content, 'THIS-RSS', '/'.makeQueryString (makeTagArray (trim ("rss $s_filter"))));
		
		if (substr ($_SERVER['REQUEST_URI'], 0, 6) == '/data/') {
			replaceTemplateTag ($this->content, 'HEAD', loadTemplate ('admin/head'));
			replaceTemplateTag ($this->content, 'TAG_CLOUD', loadTemplate ('admin/menu'));
			replaceTemplateTag ($this->content, 'PASSWORD', loadTemplate ('admin/password'));
		} else {
			$template = loadTemplate ('head');
			replaceTemplateTag (
				$this->content, 'HEAD',
				//first, always the feed for everything
				repeatTemplate ($template, array ('HREF' => '/?rss', 'TITLE' => 'all content')).
				//if viewing a tag, include the feed for that
				($s_filter ? repeatTemplate (
					$template, array ('HREF'  => "/?rss&amp;$s_filter", 'TITLE' => $s_filter)
				) : '')
			);
		}
		//try pull the tag cloud from cache, otherwise start a new template
		if (!is_null ($s_filter)) replaceTemplateTag ($this->content, 'TAG_CLOUD', getTagCloud ($s_filter));
	}
	
	public function save () {
		if ($this->gzip)  {
			file_put_contents (
				_root."/data/cache/$this->id.txt.gz.php",
				//add the php header to cached files to set browser-cache values
				replaceTemplateTagArray ($_=<<<HEREDOC
<?php
//if the browser asks "has this document been modified since I last checked"...
if (isset (\$_SERVER["HTTP_IF_NONE_MATCH"])) {
	if ('"&__ETAG__"' === \$_SERVER["HTTP_IF_NONE_MATCH"]) {
		//tell the browser that nothing’s changed and exit, outputting no content at all!
		header ('HTTP/1.0 304 Not Modified');
		exit;
	}
}
header ('Content-Encoding: gzip');
/*header ('Expires: &__EXPIRE__');
header ('Cache-Control: max-age=3600, must-revalidate');*/
header ('Last-Modified: &__DATE__');
header ('ETag: "&__ETAG__"');

?>
HEREDOC
					, array (
						'EXPIRE' => gmdate ('D, d M Y H:i:s', time ()+(3600*24)).' GMT',
						'DATE'   => gmdate ('D, d M Y H:i:s').' GMT',
						'ETAG'   => md5 ($this->content)
					)
				).
				//gzipped content can include "<?" causing the cache loading to fail
				str_replace ('<?', "<?php echo('<?');?>", gzencode ($this->content, 9))
			);
			//a fully cached page is outputted when saved since the cache is dumped straight to screen when
			//loading and the script terminated, ergo it’s pointless saving a fully cached page, and then 
			//trying to manipulate it further, nobody would see it
			$this->output ();
		} else {
			parent::save ();
		}
	}
	
	public function output () {
		//for a fully cached page, use the on-disk content because that now has all the right browser-caching
		//headers embedded in it, so that the first time a page is viewed, the headers will be the same as the next
		if ($this->gzip) if (dumpCache ($this->id)) exit;
		
		//for partially cached pages, gzip the content on the way out. also note that should cache saving be
		//disabled, the call above will fall back to this output below
		//?/header ("Content-Encoding: gzip");
		exit (/*gzencode (*/$this->content/*)*/);
	}
}

/* ----------------------------------------------------------------------------------------------------------------------- */

//check to see if a cache of a page exists, and if so - output it straight to the screen and stop there,
//otherwise create a cache class to work with and return that. ergo, note that when this function is used that if the cache
//is on disk then program execution will stop at the `outputCachedPage` call!
function outputCachedPage ($s_cache_id) {
	if (dumpCache ($s_cache_id)) exit;
	return new cachedPage ($s_cache_id, true);
}

/* ----------------------------------------------------------------------------------------------------------------------- */

//this is the crux of the super fast caching on this site
//the number of lines of php executed to get from the start of index.php, to here are less than 30,
//and if you wanted, you could call `dumpCache` as the first line in your php script, and you’d be running just 3 or 4 lines
//of php to output a cached page! bring it on, Slashdot
function dumpCache ($s_cache_id) {
	//`include` returns true if successful. we can thus dump the cache to the screen without having to check if the
	//file exists first. if the cache existed, `inlcude` will return true, and if it didn’t, it’d return false
	//this is really, really fast
	return @include (_root."/data/cache/$s_cache_id.txt.gz.php");
}

/* ----------------------------------------------------------------------------------------------------------------------- */

function outputErrorPage ($s_template_name, $s_title, $i_http_error = 0) {
	//send a 404, 503 &c. ...
	if ($i_http_error) header (' ', true, $i_http_error);
	//try dump the error to screen from cache. execution will stop here if the cache is present
	$cache = outputCachedPage ("page $s_template_name");
	//if the cache wasn’t available, prepare it
	$cache->getPage ($s_template_name, $s_title);
	//saving will also dump to screen and exit (for fully cached pages)
	$cache->save ();
}


/* === website content =================================================================================================== */

function getTagCloud ($s_filter) {
	$tagcloud = new cache (trim ("tags $s_filter"));
	if (!$tagcloud->on_disk) {
		$tagcloud->content = loadTemplate ('nav-tagcloud');
		
		//the content-type tags are always listed first, then all the user-tags
		$tag = loadTemplate ('nav-tagcloud-tag');
		$tagcloud_types = '';
		$tagcloud_tags  = '';
		$on = loadTemplate ('nav-tagcloud-tag-on');
		
		replaceTemplateTag ($tagcloud->content, 'ALL_ON', !$s_filter ? $on : '');
		
		//loop over all tags in the database
		global $database;
		foreach ($database->query (
			"SELECT [tag], COUNT([title]) as [count] FROM [tags], [content] WHERE [tags] LIKE '%|'||[tag]||'|%'".
			" GROUP BY [tag] ORDER BY [count] DESC;", database::query_array
		) as $row) {
			if (isTagInList ($row[0], _content_types)) {
				$tagcloud_types .= repeatTemplate ($tag, array (
					'TAG'   => $row[0],
					'HREF'  => '/?'.$row[0],
					'COUNT' => $row[1],
					'ON'    => $s_filter == $row[0] ? $on : ''
				));
				
			} elseif (!isTagInList ($row[0], _reserved_tags)) {
				$tagcloud_tags .= repeatTemplate ($tag, array (
					'TAG'   => $row[0],
					'HREF'  => '/?'.$row[0],
					'COUNT' => $row[1],
					'ON'    => $s_filter == $row[0] ? $on : ''
				));
			}
		}
		replaceTemplateTag ($tagcloud->content, 'TAG_CLOUD_TYPES', $tagcloud_types);
		replaceTemplateTag ($tagcloud->content, 'TAG_CLOUD_TAGS',  $tagcloud_tags);
		$tagcloud->save ();
	}
	return $tagcloud->content;
}

/* ----------------------------------------------------------------------------------------------------------------------- */

//get a content-entry: either pull from cache if available, otherwise pull from the database
//the first parameter is a string to append the content to. this is a weird way of doing it, but it’s done because this
//function is called only as part of `array_reduce`, which sends a string as the first parameter to collect the results of
//each array item (the second parameter). search for this function name in '/index.php' for details
function getCachedEntry (&$s_content, $i_when) {
	static $compiled_query;
	
	//attempt to pull the content entry from cache
	$entry = new cache ($i_when);
	if (!$entry->on_disk) {
		//here we compile an SQL query to reuse and store it statically in this function (i.e. the variable keeps
		//its value for every call to this function). we can run this query over and over for each of the content-
		//entries down the page very quickly
		global $database;
		if (!$compiled_query) $compiled_query = $database->query (
			'SELECT [title], [content], [tags], [enclosure] FROM [content] WHERE [when]=? ORDER BY 1 DESC',
			database::query_prepare
		);
		//retrieve the particular content entry
		$compiled_query->execute (array ($i_when));
		$data = $compiled_query->fetch (PDO::FETCH_NUM);
		//template the HTML
		$entry->content = getEntry ($i_when, $data[0], $data[1], $data[2], $data[3]);
		$entry->save ();
	}
	return $s_content .= $entry->content;
}

/* ----------------------------------------------------------------------------------------------------------------------- */

//templates a content-entry
function getEntry ($i_when, $s_title, $s_content, $s_tags, $s_enclosure = '') {
	//convert timestamp for the date formatting functions below. I could have shoe-horned this into the block below,
	//but I couldn’t find anywhere that wouldn’t a) look ugly, and b) be a total hack
	$unix_when = unixTime ($i_when);
	
	//this shows the full density of the template system (effectively it is one line)
	return replaceTemplateTagArray (loadTemplate ('entry'), array (
		/* --- time ---------------------------------------------------------------------------------------------- */
		'HREF'  => "/?$i_when",
		'MONTH' => date ('M', $unix_when),	//"A short textual representation of a month, three letters"
		'DAY'   => date ('j', $unix_when),	//"Day of the month without leading zeros"
		'TH'    => date ('S', $unix_when),	//"English ordinal suffix for the day of the month, 2 characters"
		'YEAR'  => date ('y', $unix_when),	//"A two digit representation of a year"
		'DATE'  => date ('c', $unix_when),	//"ISO 8601 date" (for `datetime` attribute of `<time>`)
		'HOUR'  => date ('g', $unix_when),	//"12-hour format of an hour without leading zeros"
		'MINS'  => date ('i', $unix_when),	//"Minutes with leading zeros"
		'AMPM'  => date ('a', $unix_when),	//"Lowercase Ante meridiem and Post meridiem"
		/* --- content ------------------------------------------------------------------------------------------- */
		//this finds the first content-type tag in the taglist and both returns it, and assigns it to a variable
		//I would have thought that this would return a boolean, but it does not for some odd reason
		'TYPE'  => $content_type = preg_match ('/\|('._content_types.')\|/', $s_tags, $_) ? $_[1] : '',
		//load the template for that content type; blogs are shaped different from tweets and so on
		'ENTRY' => replaceTemplateTag (loadTemplate ("content-types/$content_type"),
			'CONTENT', formatContent ($s_content)
		),
		//title has to be given after content as the `TITLE` template tag is a part of the content template,
		//and not a part of the entry template
		'TITLE' => $s_title,
		/* --- licence ------------------------------------------------------------------------------------------- */
		//this line finds the first licence-type tag and returns the relevant html, otherwise nothing
		'LICENCE' => preg_match ('/\|('._licence_tags.')\|/', $s_tags, $_) ? loadTemplate ('licences/'.$_[1]) : '',
		/* --- enclosure ----------------------------------------------------------------------------------------- */
		//idea: exif information on the aside? / size information displayed somewhere
		'ENCLOSURE' => $s_enclosure ? getEnclosure ($s_enclosure, $content_type) : '',
		/* --- tags ---------------------------------------------------------------------------------------------- */
		'CONTENT_TYPE' => replaceTemplateTagArray (
			loadTemplate ('entry-tag'),
			array ('TAG' => $content_type, 'HREF' => "/?$content_type")
		),
		//the list of user-tags aside the content-entry
		'TAGS' => array_reduce (
			//this is a messy hack because `sort` does not return a ruddy array >:[
			sort ($_ = array_diff (
				//ignore reserved tags in the tag-list, like the licence
				explode ('|', trim ($s_tags, '|')), explode ('|', _reserved_tags)
			)) ? $_ : array (),
			//template each tag and concenate into a list
			create_function (
				'$a,$b', 'return $a.=repeatTemplate("'.addslashes (loadTemplate ('entry-tag')).
				'",array("TAG"=>$b,"HREF"=>"/?$b"));'
			)
		)
	));
}

/* ----------------------------------------------------------------------------------------------------------------------- */

//prepare the raw html from the database for display
function formatContent ($s_content) {
	//add 'type="mime/type"' to links in the content
	$s_content = preg_replace_callback (
		'/<a([^>]*)href="([^"]+)\.(gif|jpg|png|pdf|txt|css|js|zip|dmg|exe)"([^>]*)>/',
		create_function ('$m', 'return "<a type=\"".mimeType($m[3])."\"${m[1]}href=\"${m[2]}.${m[3]}\"${m[4]}>";'),
		//add `rel="external"` to outside links
		preg_replace_callback (
			'/<a[^>]*href="(?:[a-z]+):[^"]+"[^>]*>/', create_function ('$m',
				'return (strpos($m[0],"rel=\"")!==false)'.		//does 'rel="..."' already exist?
				'?str_replace("rel=\"","rel=\"external ",$m[0])'.	//insert "external" into `rel`
				':str_replace("<a ","<a rel=\"external\" ",$m[0]);'	//add `rel="external"`
			), $s_content
		)
	);
	
	//preserve the `<pre>` blocks from indenting and wrapping
	$chunks = preg_split ('/<\/?pre>/', $s_content);
	//is this odd/even? (<php-scripts.com/20051104/57/>)
	foreach ($chunks as $key => &$chunk) $chunk = ($key & 1)
		? markupSyntax ($chunk)		//`<pre>` blocks
		: wrapAndIndent ($chunk, 1)	//wrap and indent the HTML
	;
	return implode ('', $chunks);
}

/* ----------------------------------------------------------------------------------------------------------------------- */

function getEnclosure ($s_enclosure, $s_content_type) {
	$content = loadTemplate ("content-types/$s_content_type.enclosure");
	
	//preview?
	$enclosure = explode (';', $s_enclosure);
	$path = "/data/content-media".(substr ($enclosure[0], 0, 1) == "_" ? '/' : "/$s_content_type/");
	if (isset ($enclosure[2])) {
		$preview_image_size = getimagesize (_root.$path.$enclosure[2]);
		$content = replaceTemplateTagArray ($content, array (
			'ENCLOSURE_PREVIEW' => $path.rawurlencode ($enclosure[2]),
			'ENCLOSURE_WIDTH'   => $preview_image_size[0],
			'ENCLOSURE_HEIGHT'  => $preview_image_size[1]
		));
	}
	$content = replaceTemplateTagArray ($content, array (
		'ENCLOSURE_NAME' => htmlspecialchars ($enclosure[0]),
		'ENCLOSURE_HREF' => $path.rawurlencode ($enclosure[0]),
		'ENCLOSURE_MIME' => $enclosure[1],
		//an inline way to format a file size :)
		'ENCLOSURE_SIZE' => array_reduce (
			array (" B", " KB", " MB"), create_function (
				'$a,$b', 'return is_numeric($a)?($a>=1024?$a/1024:number_format($a,strlen($b)-2).$b):$a;'
			), filesize (_root.$path.$enclosure[0])
		)
	));
	return $content;
}


/* ======================================================================================================================= */

function outputCachedRSS ($s_tag = '') {
	//dump the cache to screen if it exists (and end here), otherwise returns a `cachedPage` class to work with
	$cache = outputCachedPage (trim ("rss $s_tag"));
	
	//you’ll have to edit '/design/templates/rss/rss' to set the title, description and logo &c.
	$cache->content = loadTemplate ('rss/rss');
		
	//rss header information
	replaceTemplateTagArray ($cache->content, array (
		'DOMAIN'   => 'http://'.$_SERVER['SERVER_NAME'].'/'.($s_tag ? "?$s_tag" : ''),
		'DATE'     => gmdate ('r'),
		'CATEGORY' => $s_tag ? "<category>$s_tag</category>" : ''
	));
	//select the content from the database:
	global $database;
	if (!$rows =& $database->query (
		'SELECT [when], [updated], [title], [content], [tags], [enclosure] FROM [content] '.
		($s_tag ? "WHERE [tags] LIKE '%|$s_tag|%' " : '').
		'ORDER BY 2 DESC LIMIT 10', database::query_array
	
	//invalid tag, no data?
	)) outputErrorPage ('error-404', '404', 404);
	
	$template = loadTemplate ('rss/rss-item');
	$items = '';
	foreach ($rows as &$row) {
		$tags = explode ('|', trim ($row[4], '|'));
		$content_type = reset (preg_grep ('/^('._content_types.')$/', $tags));
		
		$enclosure = explode (';', $row[5]);
		if ($enclosure[0]) {
			$file_size = filesize (_root."/data/content-media/$content_type/${enclosure[0]}");
			if (isset ($enclosure[2])) $row[3] = getEnclosure ($row[5], $content_type).$row[3];
			$enclosure_tag = "\n\t\t<enclosure url=\"http://${_SERVER['HTTP_HOST']}/data/content-media/$content_type/".rawurlencode ($enclosure[0])."\" length=\"$file_size\" type=\"${enclosure[1]}\" />";
		} else {
			$enclosure_tag = '';
		}
		$items .= repeatTemplate ($template, array (
			//idea: if updated ($row[1]!=$row[0]) then do something to the title
			//if a title is not given, use the enclosure filename if available
			'TITLE'       => "[$content_type] ".($row[2] ? rssTitle ($row[2]) : ($enclosure[0] ? $enclosure[0] : '')),
			'URL'         => 'http://'.$_SERVER['SERVER_NAME'].'/?'.$row[0],
			'DESCRIPTION' => htmlspecialchars (formatContent ($row[3], 2), ENT_NOQUOTES),
			'DATE'        => gmdate ('r', unixTime ($row[1])),
			'ENCLOSURE'   => $enclosure_tag,
			'CATEGORIES'  => array_reduce (
				$tags, create_function ('$a,$b', '$a.="\n\t\t<category>$b</category>";return $a;')
			)
		));
	}
	replaceTemplateTag ($cache->content, 'ITEMS', $items);
	$cache->save ();
}

/* ==================================================================================================== code is art === */ ?>