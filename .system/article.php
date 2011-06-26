<?php //where we generate the HTML for a particular article, cache it and then show it
/* ====================================================================================================================== */
/* each article on the website is of a particular content-type (determining its ‘shape’)—blog | photo | quote &c.
   and exists as a text file (written in ReMarkable) in a folder for that type, e.g. “/blog/hello.rem”

   the mod_rewrite rules map article names to this script in a number of locations:
   1. the actual location 	->	“/blog/hello” (permalink)
   2. each tag for the article	->	“/web-dev/hello”, “/code-is-art/hello”
   3. no tag, from the home page->	“/hello”

   therefore the input to this script is not necesarily the physical location of the article, merely an article fragment
   name and an optional type or tag (referred to as the category), to apply to the next / previous-article links
*/
include "shared.php";

/* ============================================================================================================ input === */

//“article.php?article=blog/hello.rem”
$requested = (preg_match ('/^\.?[-a-z0-9_\/]+\.rem$/', @$_GET['article']) ? $_GET['article'] : false) or errorPage (
	'malformed_request.html', 'Error: Malformed Request', array ('URL' => '?article=category/article.rem')
);

//check if there is a category specified:
$category = preg_match ('/^([-a-z0-9]+)\//', $requested, $_) ? $_[1] : '';
//and the second half which is the article to view:
$article  = pathinfo ($requested, PATHINFO_FILENAME);

//for the home page and index pages of each category, the latest article is shown
//mod_rewrite handles this by rewriting:
//  “/” (home page)	->	“/latest”
//  “/blog/”		->	“/blog/latest”
$is_latest = ($article == 'latest');

/* ========================================================================================================== process === */

//retrieve the article index that lists all metadata for articles
$index_array = getIndex ();

//filter down the index to only articles in the current category being viewed
$data = $category ? array_values (
	array_filter ($index_array, create_function ('$v', "return (strpos(\$v,'|$category|')!==false);"))
) : $index_array;

//if viewing an index page, retrieve the latest article from the top of the stack
if ($is_latest) $article = end (@explode ('|', $data[0]));

//locate the index for the article
$index = @reset (preg_grep ("/\|$article$/", $index_array));

//is the article name not in the index?
if (!$index) {
	//if it’s not an indexed article, then it may be a ‘.rem’ file on disk we want to render (“/projects.rem”)
	if (!file_exists (APP_ROOT.$requested)) errorPageHTTP (404);
	$href = ($category ? "$category/" : '')."$article";
	
	//check if the file has a header, it could be a draft (which is not indexed)
	if (list ($meta, $title, $content) = getArticle ($href)) {
		//if a date has not been provided in the draft, just use now
		if (@!$meta['date'])	$meta['date']    = date ('YmdHi');
		if (@!$meta['updated'])	$meta['updated'] = date ('YmdHi');
		//generate a preview of the draft article
		exit (templatePage (
			//article HTML
			templateArticle ($meta, $category, $href, $content, $category),
			//HTML title
			($category ? $category.' · ' : '').(
				//if there’s a title use that, if not use the article name
				$title ? rssTitle (reMarkable ("# $title #")) : $article
			),
			template_load ('base.header.draft.html')
		));
	} else {
		//generate a generic page
		$html = templatePage (                                        //title
			reMarkable (file_get_contents (APP_ROOT.$requested)), $article,
			templateHeader ($href), templateFooter ($href)
		);
	};
	
} else {
	//extrapolate the info from the article’s index
	//(which looks like this: “datetime|type|tag|tag|tag|name”)
	$type = @reset (array_slice (explode ('|', $index), 1, 1));
	$tags = count ($index) == 3 ? array () : array_slice (explode ('|', $index), 2, -1);
	$href = "$type/$article";
	
	//if a category is specified and the article is not in that category, redirect to the permalink version
	//instead: (the user either typo’d or an article has changed its tags at some point)
	if ($category && ($type != $category && !@in_array ($category, $tags))) {
		header ('Location: http://'.APP_HOST."/$href", true, 301);
		exit;
	}
	
	//open the file and read the metadata and HTML
	list ($meta, $title, $content) = getArticle ($href);
	
	//find the article’s relative position in the index so that we can link to the previous and next articles
	$key = @reset (array_keys ($data, $index));
	
	//template the full page
	$html = templatePage (
		//article HTML
		templateArticle ($meta, $type, $href, $content, $category),
		//HTML title
		($category ? $category.($is_latest ? '' : ' · ') : '').
		($is_latest ? ($category ? '' : 'camen design') : (
			//if there’s a title use that, if not use the article name
			$title ? rssTitle (reMarkable ("# $title #")) : $article
		)),
		//website header—contains the previous / next article links
		templateHeader ($requested,
			//next, prev article links
			isset ($data[$key+1]) ? template_tags (template_load ('article-prev.html'), array (
				'HREF' => ($category ? "/$category/" : '/').@end (explode ('|', $data[$key+1]))
			)) : '',
			isset ($data[$key-1]) ? template_tags (template_load ('article-next.html'), array (
				'HREF' => ($category ? "/$category/" : '/').@end (explode ('|', $data[$key-1]))
			)) : '',
			//canonical URL
			$href
		),
		templateFooter ($href)
	);
}


/* =========================================================================================================== output === */

//don’t cache on my localhost (hate having to delete cache files when writing articles)
if ($_SERVER['SERVER_ADDR'] != '127.0.0.1') {
	//before saving the cache, replicate any sub folders in the cache area too
	@mkdir (APP_CACHE.dirname ($requested), 0777, true);
	
	//save the cache
	file_put_contents (
		APP_CACHE.dirname ($requested).'/'.pathinfo ($requested, PATHINFO_FILENAME).'.html', $html, LOCK_EX
	) or errorPage (
		'denied_cache.rem', 'Error: Permission Denied', array ('PATH' => APP_CACHE)
	);
}

exit (preg_match ('/\.html($|\?)/', $_SERVER['REQUEST_URI']) ? template_tags (
	template_load ('view-source.html'), array (
		'TITLE'  => pathinfo ($requested, PATHINFO_BASENAME),
		'HEADER' => '',
		'CODE'   => htmlspecialchars ($html, ENT_NOQUOTES, 'UTF-8')
	)
) : $html);

/* =================================================================================================== code is art === */ ?>