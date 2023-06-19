<?php //index.php / written by kroc camen of camen design
/* ======================================================================================================================= */
//hello, just a heads-up that all of the code on this site is now yours to use anyway you want anywhere you want, enjoy!
include "code/shared.php";

/* === input ============================================================================================================= */
$entry_id = '';  //id (YYYYMMDDHHMM) of an individual content entry to show (clicked on the permalink)
$filter   = '';  //the tag to filter by
$page     = 0;   //page number. note that this is 0-based throughout the code, and adjusted when outputted

/* --- querystring ------------------------------------------------------------------------------------------------------- */
if ($_GET) {
	//if a content-entry id is given, all other tags must be ignored:
	if (!$entry_id = reset (preg_grep (_regex_content_id, array_keys ($_GET)))) {
		
		//find the tag to filter by
		$filter = reset (array_diff (
			preg_grep ('/^[a-zA-Z0-9-]{1,20}$/', array_keys ($_GET)), explode ('|', _disallowed_tags)
		));
		
		//if "?rss" output the rss feed and end here
		if (array_key_exists ("rss", $_GET)) outputCachedRSS ($filter);
		
		//page?
		$page = (preg_match ('/^[1-9]{1}[0-9]{0,2}$/', get ('page'), $_)) ? (int) $_[0]-1 : 0;
	}
}


/* === main content ====================================================================================================== */
//get a fully cached page; if a cached file is found the code will end here and not continue!
//if the cache doesn’t exist, a primed `cache` class-instance is returned to manipulate
$cache = outputCachedPage (
	//cache id: the cache id is a unique space-delimited list of tags, for selective deleting later on
	'page'.($entry_id ? " $entry_id" : ($filter ? " $filter" : ' index')).($page ? ' page-'.($page+1) : '')
);

//select the content from the database:
//there’s no easy way to find if we’re out of pages here without extra queries so we select one more entry than necessary,
//and if the row count is not equal to that, we know we’re at the end of the table. this is cheap enough to do because we’re
//only selecting a single column of just timestamps
if (!$rows = $database->query (
	'SELECT [when] FROM [content] '.
	($entry_id ? "WHERE [when]=$entry_id " : ($filter ? "WHERE [tags] LIKE '%|$filter|%' " : '')).
	'ORDER BY 1 DESC LIMIT '.($page*5).',6', database::query_single_array

//no data? load the error page
)) outputErrorPage ('error-404', '404', 404);

//pull in the base template for the page
$cache->getPage ('index', $entry_id  //title:
	//- if a content entry is being shown, use the content title
	? rssTitle ($database->query ("SELECT [title] FROM [content] WHERE [when]=$entry_id;", database::query_single))
	//- otherwise, show tag filter and/or page number
	: ($filter . ($filter && $page ? ' · ' : '').($page ? 'page '.($page+1) : '')),
$filter);

/* --- content entries --------------------------------------------------------------------------------------------------- */
//drop the dummy 6th entry per-page (used to check if we’re at the end of the recordset)
$end = (count ($rows) == 6) ? (bool) array_pop ($rows) : false;

//each content entry is just repeated down the page, with different contents injected in
//`array_reduce` iteratively concatenates a string together of each of the content entries, no `for` loop needed
//the `getCachedEntry` function is in 'code/content.php', you can see the source code for that just by typing in the url
replaceTemplateTag ($cache->content, 'CONTENT', array_reduce ($rows, 'getCachedEntry'));

/* --- page links -------------------------------------------------------------------------------------------------------- */
//previous page link
replaceTemplateTag ($cache->content, 'PAGE_PREVIOUS', $entry_id ? '' : (
	$page ? replaceTemplateTag (loadTemplate ('nav-previous'),
		//if on page 2, don’t use "page=1", it’s ugly
		'HREF', '/'.($page==1
			? ($filter ? "?$filter" : '') : makeQueryString (array ("$filter" => '', 'page' => $page))
		)
	) : ''
));

//next page
replaceTemplateTag ($cache->content, 'PAGE_NEXT', $entry_id ? '' : (
	$end ? replaceTemplateTag (loadTemplate ('nav-next'),
		//fixme: an elegant way to not include `$filter` in the array if not present?
		'HREF', '/'.makeQueryString (array ("$filter" => '', 'page' => $page+2))
	) : ''
));

$cache->save ();

/* ==================================================================================================== code is art === */ ?>