<?php
//==============================================================================
// camen design © copyright (c) Kroc Camen 2008-2022, BSD 2-clause
// (see LICENSE.TXT). bugs / suggestions → kroc@camendesign.com
//
declare(strict_types=1);

// rss.php : where we generate RSS feeds

include "shared.php";

$domain = 'http://'.APP_HOST.'/';

// input:
//==============================================================================
// “rss.php?category=blog”
$category = preg_match ('/^[a-z0-9-]+$/', @$_GET['category'], $_) ? $_[0] : '';

// process:
//==============================================================================

//get a list of all categories in the site
$categories = array_keys (array_merge (getTypes (), getTags ()));

//d oes the requested category exist?
if ($category && !in_array ($category, $categories)) errorPageHTTP (404);

// filter down the index to only articles in the
// current category being viewed, limit to 10 items
foreach (array_slice (
    $category
    ? array_values (array_filter (getIndex (), function ($v) use ($category){
        return (strpos($v, "|$category|") !== false);
    }))
    : getIndex (),
0, 10) as $index) {
    // extrapolate the info from the article’s index
    // (which looks like this: “updated|type|tag|tag|tag|name”)
    $type = @reset (array_slice (explode ('|', $index), 1, 1));
    $name = @end (explode ('|', $index));
    $href = "$type/$name";
    
    // load the article; retrieve metadata and content
    list ($meta, $title, $content) = getArticle ($href);
    
    // template this item and append it to the rest
    @$rss.= template_tags (
        template_load ('item.rss'),
        [
            'TITLE'      => $title ? rssTitle (reMarkable ("# $title #")) : $name,
            'LINK'       => $domain.($category ? "$category/" : '').$name,
            'PERMALINK'  => $domain.$href,
            'DATE'       => gmdate ('r', mktime (
                        (integer) substr ((string) $meta['updated'], 8,  2),
                        (integer) substr ((string) $meta['updated'], 10, 2), 0,
                        (integer) substr ((string) $meta['updated'], 4,  2),
                        (integer) substr ((string) $meta['updated'], 6,  2),
                        (integer) substr ((string) $meta['updated'], 0,  4)
                    )),
            'DESCRIPTION'=> htmlspecialchars (preg_replace (
                        '/(href|src)="?\//', "$1=\"$domain",
                        reMarkable (template_tags ($content.<<<TXT


((<Discuss this in the forum (//forum.camendesign.com/)>))
TXT
                        , [
                            'TITLE' => @$meta['title'],
                            'HREF'  => $href,
                            'URL'   => @$meta['url']
                        ]))
                    )),
            // slice the categories out of the index
            // i.e. “updated|type|tag|tag|tag|name”
            'CATEGORIES' => array_reduce (
                array_slice (explode ('|', $index), 1, -1),
                function ($a, $b) use ($domain){
                    $a .= "<category domain=\"$domain$b\">$b</category>\n";
                    return $a;
                }
            ),
            'ENCLOSURE'  => @$meta['enclosure'] ? array_reduce (
                $meta['enclosure'],
                function ($a, $b) use ($domain, $href){
                    return $a .= "<enclosure url=\"$domain$href/$b\""
                        ." length=\"".filesize ("$href/$b")."\""
                        ." type=\"".mimeType ($b)."\" />\n";
                }
            ) : '',
        ]
    );
}

$rss = template_tags (template_load ('base.rss'), [
    'DOMAIN'   => $domain,
    'CATEGORY' => $category ? "$category/" : '',
    'DATE'     => gmdate ('r'),
    'CATTAG'   => $category ? "<category>$category</category>" : '',
    'TITLE'    => $category ? " · $category" : '',
    'ITEMS'    => $rss
]);


// output:
//==============================================================================

// before saving the cache, replicate any sub folders in the cache area too
@mkdir (APP_CACHE.$category, 0777, true);

// save the cache
file_put_contents (
    APP_CACHE.($category ? "$category/" : '').'feed.rss', $rss, LOCK_EX
) or errorPage (
    'denied_cache.rem', 'Error: Permission Denied', ['PATH' => APP_CACHE]
);

//dump the RSS to the browser
header ("Content-type: application/rss+xml; charset=UTF-8");
exit ($rss);

?>