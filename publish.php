<?php
// publish.php: generate all the static HTML files from the articles
//==============================================================================
// camen design, copyright (cc-by) Kroc Camen, 2003-2020
// licenced under Creative Commons Attribution 4.0
// <creativecommons.org/licenses/by/4.0/deed.en_GB>

// initialise
define ('BEGIN', microtime (true));
error_reporting (-1);
set_time_limit (0);

date_default_timezone_set ('Europe/London');

ob_start ();
header ('Content-Type: text/plain;charset=UTF-8');
echo "PUBLISHING...\n"; ob_flush (); flush ();

include 'lib/functions.php';

// a quick and dirty SQLite database class
include 'lib/sqlite.php';

// "ReMarkable" is my Markdown-like syntax for writing in plain text and
// converting to HTML see the ReMarkable folder for documentation,
// or the website <camendesign.com/code/remarkable>
include 'lib/remarkable/remarkable.php';

// DOMTemplate - an innovative templating method that separates logic & markup;
// this gives us the benefit of single-file templates without embedded logic
include 'lib/domtemplate/domtemplate.php';

//==============================================================================
// [1]: generate a SQLite database from the articles
//==============================================================================
// we read-in all the ReMarkable files, collect the meta data, process the
// ReMarkable text into HTML and put all of this data into a SQLite database.
// this database is not used to serve visitors on the site, instead it's a
// temporary way to quickly access all the site data multiple different ways
// for spitting out thousands of repetative static HTML files

// initialise the database:
//------------------------------------------------------------------------------
echo "GENERATING DATABASE: "; ob_flush (); flush ();

@mkdir ('cache', 0444);
// switch to the cache directory where the database will reside
chdir ('cache');
// delete any old database
@unlink ('db.sqlite');
// create and connect to the database
$db = new database ('db.sqlite', <<<'SQL'
	CREATE TABLE "article" (
		"name"		TEXT		PRIMARY KEY,
		"date"		INTEGER,
		"updated"	INTEGER,
		"title"		TEXT,
		"url"		TEXT,
		"licence"	TEXT,
		"type"		TEXT,
		"tags"		TEXT,
		"enclosures"	TEXT,
		"html"		TEXT
	);
	CREATE TABLE "tag" (
		"name"	TEXT	PRIMARY KEY
	);
SQL
);

// we're going to use this SQL statement a lot,
// so compile it for fast re-use
$db_insert = $db->prepare (
	'INSERT INTO "article" VALUES '.
	'(:name, :date, :updated, :title, :url, :licence, :type, :tags, :enclosures, :html);'
);

// scan all articles:
//------------------------------------------------------------------------------
chdir ('../content');

// get a list of the main site article types (e.g. "blog", "code", "art" &c.)
$types = array_filter (
	// include only directories, but ignore directories starting with "."
	preg_grep ('/^\./', scandir ('.'), PREG_GREP_INVERT), 'is_dir'
);
$tags = array ();

// get each article in each content-type folder:
foreach ($types as $type) foreach (preg_grep ('/\.rem$/', scandir ($type)) as $file_name) {
	// read in the article
	$file_path = $type.DIRECTORY_SEPARATOR.$file_name;
	$rem = trim (preg_replace ('/\r\n?/', "\n", @file_get_contents ($file_path)));
	// split the article into the meta data and the article-text
	list ($meta, $content) = explode ("\n\n", $rem, 2);
	// if the meta data is not present, error
	if ($meta[0] != '{') die ("meta data in file $file_path is missing");
	// the header is a JSON object containing the
	// meta-information (date, tags, enclosure &c.)
	if (!$meta = json_decode ($meta, true)) die ("meta data in $file_path is malformed");
	
	// if the article is a draft, ignore it
	if (@$meta['draft']) continue;
	
	// index the tags on the articles
	if (isset ($meta['tags'])) foreach ($meta['tags'] as $tag) if (!in_array ($tag, $tags)) $tags[] = $tag;
	
	// update article meta data:
	// --------------------------------------------------------------------------
	// if the "date" or "updated" fields are missing from the meta data, they
	// will be added. this is so that you can write an article, excluding the
	// date, and the publish-date will be added automatically at publish time
	$modified = false;
	// if the file has no date: it's new -- give it a date
	if (!isset ($meta['date']))    {$meta['date']    = date ('YmdHi'); $modified = true;}
	// if the file has no updated meta, add it.
	// the entry will be pushed to the top of the RSS feed
	if (!isset ($meta['updated'])) {$meta['updated'] = date ('YmdHi'); $modified = true;}
	// save the file if the date/updated fields have been changed:
	// (of course, I could have just used `json_encode` here,
	//  but then it wouldn't be tidy)
	if ($modified) {
		// what if we can't save to disk?
		if (!is_writable ("$file_path")) @chmod ("$file_path", 0444) or die ("Unable to save $file_path");
		file_put_contents ("$file_path",
			"{\t\"date\"\t\t:\t${meta['date']},\n"
			."\t\"updated\"\t:\t${meta['updated']}"
			// optional stuff
			.(@$meta['title']     ? ",\n\t\"title\"\t:\t\"".str_replace ('"', '\"', $meta['title'])
						."\"" : '')
			.(@$meta['licence']   ? ",\n\t\"licence\"\t:\t\"${meta['licence']}\"" : '')
			.(@$meta['tags']      ? ",\n\t\"tags\"\t\t:\t[\"".implode ('", "',$meta['tags'])."\"]" : '')
			.(@$meta['enclosure'] ? ",\n\t\"enclosure\"\t:\t[\""
						.implode ('", "',$meta['enclosure'])."\"]" : '')
			.(@$meta['url']       ? ",\n\t\"url\"\t\t:\t\"${meta['url']}\"" : '')
			.(@$meta['draft']     ? ",\n\t\"draft\"\t\t:\t${meta['draft']}\n" : "\n")
			//the article text
			."}\n\n".$content
		);
	}
	
	// add the article to the database:
	//--------------------------------------------------------------------------
	$db_insert->execute (array (
		':name'		=> pathinfo ($file_name, PATHINFO_FILENAME),
		':date'		=> $meta['date'],
		':updated'	=> $meta['updated'],
		// get the title of the article: (it might be in the meta data,
		// otherwise find the first ReMarkable heading)
		':title'	=> @$meta['title'] ? $meta['title']
				   : (preg_match ('/^# (.*?) #(?: \(#.+?\))?$/m', $content, $_) ? $_[1] : ''),
		'url'		=> @$meta['url'],
		'licence'	=> @$meta['licence'],
		'type'		=> $type,
		'tags'		=> @$meta['tags']      ? '|'.implode ('|', $meta['tags']).'|' : '',
		'enclosures'	=> @$meta['enclosure'] ? implode ('|', $meta['enclosure']) : '',
		'html'		=> // run the article through remarkable
				   remarkable (str_replace ('&__HREF__;',
					$type.'/'.pathinfo ($file_name, PATHINFO_FILENAME), $content
				   ), 0, 124, '.')
	));
}

echo "OK\n"; ob_flush (); flush ();
chdir ('..');

//==============================================================================
// [2]: generate the anciliary pages (i.e. "about")
//==============================================================================
// remember to do HTML and REM source views

// generate the 404, 409, 410, 500

//==============================================================================
// [3]: generate the article pages
//==============================================================================
// loop over the categories (types + tags), including the root as a catch-all
foreach (array_merge (array (''), $types, $tags) as $category) {
	echo "PROCESSING ARTICLES: $category\n"; ob_flush (); flush ();
	
	// ensure the cache sub-folder exists for a cateogry
	if ($category && !file_exists ("cache/$category")) mkdir ("cache/$category", 0444);
	
	// select the type of SQL query, it'll vary
	// between types, tags and root (all articles)
	switch (true) {
		// the category is a content-type:
		// (i.e. "blog", "art", "code" &c.)
		case in_array ($category, $types):
			$sql = 'SELECT * FROM "article" WHERE "type"="'.$category.'" ORDER BY "updated" DESC;';
			break;
		
		case in_array ($category, $tags):
			$sql = 'SELECT * FROM "article" WHERE "tags" LIKE "|'.$category.'|" ORDER BY "updated" DESC;';
			break;
		
		// root: all articles
		default:
			$sql = 'SELECT * FROM "article" ORDER BY "updated" DESC;';
	}
	
	// fetch the articles for this category
	$articles = $db->query ($sql, database::QUERY_ARRAY);
	
	// write out each article page
	foreach ($articles as $article) {
		// generate the article page:
		//----------------------------------------------------------------------
		// get the HTML template
		$template = new DOMTemplate (file_get_contents ('theme/templates/article.html'));
		
		$template->setValue ('/html/article', $article[9], true);
		// write the file
		file_put_contents ('cache/'.($category ? "$category/" : '').$article[0].'.html', $template);
		
		// generate the HTML code view:
		//----------------------------------------------------------------------
		
		// generate the ReMarkable source view:
		//----------------------------------------------------------------------
	}
	
	//==========================================================================
	// [4]: generate the category index
	//==========================================================================
	$template = new DOMTemplate (file_get_contents ('theme/templates/index.html'));
	file_put_contents ('cache/'.($category ? "$category/" : '').'index.html', $template);
	
	//==========================================================================
	// [5]: generate the category RSS feed
	//==========================================================================
	$template = new DOMTemplate (file_get_contents ('theme/templates/index.xml'));
	file_put_contents ('cache/'.($category ? "$category/" : '').'index.xml', $template);
}

### generate the directory listing
### generate the article directory file previews
### (e.g. .php files within an article directory)

//==============================================================================
// [x]: generate the sitemap
//==============================================================================
$template = new DOMTemplate (file_get_contents ('theme/templates/sitemap.xml'));
file_put_contents ('cache/sitemap.xml', $template);

//@unlink ('db.sqlite');

die ('OK - EXECUTION TIME: '.round ((microtime (true) - BEGIN), 2).'s');

?>