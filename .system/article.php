<?php
//==============================================================================
// camen design © copyright (c) Kroc Camen 2008-2023, BSD 2-clause
// (see LICENSE.TXT). bugs / suggestions → kroc@camendesign.com
//
declare( strict_types=1 );
include "shared.php";

// this class wraps the data fields for articles and hides away
// the specifics of how the article applies the data to the template
//
class ArticleTemplate
    extends BaseTemplate
{
    // every article has a canonical category known as the type, this is
    // where the article is physically located, e.g. "/code/dom_templating",
    // however articles can also be viewed from their other categories such
    // as "/web-dev/dom_templating" even though the "web-dev" folder doesn't
    // exist -- we must be careful to manage the two locations separately
    //
    public string $type ='';            // article's canonical category (type)

    public string $next ='';            // name of next article in series
    public string $prev ='';            // name of previous article in series

    public string $date_published ='';  // page published time (optional)
    public string $date_updated ='';    // page updated time (optional)

    public array  $tags = [];           // array of article tags
    public string $licence ='';         // article licence
    public array  $enclosures =[];      // article attachments

    public string $url ='';

    public function __construct(){
        parent::__construct( 'article.html' );
    }

    public function __toString(): string {

        // the canonical URL always points to the actual location of the
        // article, even when being viewed from a different category
        $this->canonical_url = '/'.$this->type.'/'.$this->name;
        $this->set([
            // the canonical URL <link> tag is only present on article
            // pages as these can appear in multiple categories
            './link[@rel="canonical"]/@href'
            => $this->canonical_url,
            './link[@rel="alternate"][@type="application/rss+xml"]/@href'
            => $this->category ? "/$this->category/rss" : '/rss',
            './link[@rel="alternate"][@type="application/rss+xml"]/@title'
            => $this->category ? "Just $this->category" : 'All categories'
        ]);

        // the path is likewise the same but using OS-dependant slashes,
        // it is used as a stub for files sharing the same base name but
        // with differing extensions, and for the directory where related
        // files for the article are stored
        $this->path = $this->type.SLASH.$this->name;
        // set the HTML title based on selected category
        $this->title = (
            // if a readable title is already provided
            $this->title
                // use that, but TitleCase it
            ?   rssTitle( reMarkable( "# $this->title #" ))
                // if not use the article slug from the URL
            :   $this->name
        );

        //----------------------------------------------------------------------
        // previous / next articles in the series?
        //----------------------------------------------------------------------
        if ($this->prev !== ''){
            $this->setValue(
                './header//a[@rel="previous"]/@href',
                ($this->category ? "/$this->category/" : '/').$this->prev
            );
        } else {
            $this->remove( './header//a[@rel="previous"]' );
        }
        if ($this->next !== ''){
            $this->setValue(
                './header//a[@rel="next"]/@href',
                ($this->category ? "/$this->category/" : '/').$this->next
            );
        } else {
            $this->remove( './header//a[@rel="next"]' );
        }
        
        // article date:
        //----------------------------------------------------------------------
        // convert timestamp for the date formatting functions below:
        // for strict types, the chosen timestamp will be a float and
        // needs converting
        $mktime = (string)(
            $this->date_updated > $this->date_published
            ?   $this->date_updated
            :   $this->date_published
        );
        $date = mktime(
            (integer) substr( $mktime, 8,  2 ),
            (integer) substr( $mktime, 10, 2 ), 0,
            (integer) substr( $mktime, 4,  2 ),
            (integer) substr( $mktime, 6,  2 ),
            (integer) substr( $mktime, 0,  4 )
        );
        $this->replaceTextArray( './article/header/time', [
            // 'M': "A short textual representation of a month, three letters"
            '__MON__'   => date( 'M', $date ),
            // 'F': “A full textual representation of a month”
            '__MONTH__' => date( 'F', $date ),
            // 'j': “Day of the month without leading zeros”
            '__DAY__'   => date( 'j', $date ),
            // 'Y': “A full numeric representation of a year, 4 digits”
            '__YEAR__'  => date( 'Y', $date ),
            // 'c': “ISO 8601 date” (for `datetime` attribute of `<time>`)
            '__DATE__'  => date( 'c', $date ),
            // 'g': “12-hour format of an hour without leading zeros”
            '__HOUR__'  => date( 'g', $date ),
            // 'i': “Minutes with leading zeros”
            '__MINS__'  => date( 'i', $date ),
            // 'a': “Lowercase Ante meridiem and Post meridiem”
            '__AMPM__'  => date( 'a', $date )
        ])->set([
            // 'c': “ISO 8601 date” (for `datetime` attribute of `<time>`)
            './article/header/time/@datetime'    => date( 'c', $date ),
            // 'F': “A full textual representation of a month”
            './article/header/time/abbr/@title'  => date( 'F', $date )
        ]);

        // article categories:
        //----------------------------------------------------------------------
        $tag_template = $this->repeat(
            './article/header/ul/li'
        );
        // primary category:
        $tag_template->set([
            'a@href'    => "/$this->type/$this->name",
            // TODO: this should be done subtractively rather than additively
            'a@rel'     => 'bookmark'
                           .($this->type === $this->category ? ' tag' : ''),
            'a'         => $this->type
        ])->next();
        
        // additional categories:
        if (!empty( $this->tags )) foreach (
            $this->tags as $_tag
        ) $tag_template->set([
            'a@href'    => "/$_tag/$this->name",
            // TODO: this should be done subtractively rather than additively
            'a@rel'     => 'bookmark'.($_tag == $this->category ? ' tag' : ''),
            'a'         => $_tag
        ])->next();
        
        // fix whitespace (todo: last element)
        $this->appendAfter( './article/header/ul/li', "\n\t\t\t" );

        // article licence:
        //----------------------------------------------------------------------
        switch ($this->licence){
            case 'cc-by':
                $this->remove(
                    './article/header/small[1]|./article/header/small[3]'
                ); break;
            case 'cc-by-nc':
                $this->remove(
                    './article/header/small[1]|./article/header/small[2]'
                ); break;
            case 'copyright':
                $this->remove(
                    './article/header/small[2]|./article/header/small[3]'
                ); break;
            default:
                $this->remove( './article/header/small' );
        }

        // enclosures:
        //----------------------------------------------------------------------
        if (empty( $this->enclosures )) {
            $this->remove( './article/header/a' );
        } else {
            // get the HTML to form the enclosure template
            $enc_template = $this->repeat( './article/header/a' );
            
            // template enclosures
            foreach ($this->enclosures as $enclosure) {
                $this->templateEnclosure( $enc_template, $enclosure );
            }
        }

        // article content:
        //----------------------------------------------------------------------
        $this->content = "\n\n".reMarkable(
            // TODO: keep string-replacement for these?
            template_tags( $this->content, [
                'TITLE' => $this->title,
                'HREF'  => $this->canonical_url,
                'URL'   => $this->url
            ]),
            1        // indent by 1 tab to match the <article> HTML depth
        );

        return parent::__toString();
    }

    private function templateEnclosure(
        kroc\DOMTemplateRepeaterArray $template,
        string $enclosure
    ){
        // for enclosures that are images we want to create a preview image,
        // but for both images and any other file format we want to template
        // HTML for the file icon
        if ([$preview_width, $preview_height, $preview_type] = getimagesize(
            APP_ROOT.$this->path.SLASH.$enclosure
        )) {
            // is the image a 32-bit PNG (has transparency)
            // <camendesign.com/code/uth1_is-png-32bit>
            $is_alpha = ($preview_type == IMAGETYPE_PNG)
            ?   ord( file_get_contents(
                    APP_ROOT.$this->path.SLASH.$enclosure,
                    false, null, 25, 1
                )) & 4
            :   false;
            
            // decide the preview file’s file type:
            // * a JPG always has a JPG preview
            // * a PNG has a JPG preview unless it has transparency,
            //   resulting in a PNG preview
            $preview_file = ($preview_width <= APP_PREVIEW_SIZE)
            ?   $enclosure
            :   pathinfo( $enclosure, PATHINFO_FILENAME ).
                '_preview.'.($is_alpha ? 'png' : 'jpg')
            ;
            // scale the height according to ratio
            $preview_height = ($preview_width <= APP_PREVIEW_SIZE)
            ?   $preview_height
            :   APP_PREVIEW_SIZE * ($preview_height / $preview_width);
            $preview_width  = ($preview_width <= APP_PREVIEW_SIZE)
            ?   $preview_width
            :   APP_PREVIEW_SIZE;
            
            // a preview file does not exist, create it...
            //------------------------------------------------------------------
            if ($preview_file != $enclosure && !file_exists(
                APP_ROOT.$this->path.SLASH.$preview_file
            )) {
                $image_preview = imagecreatetruecolor(
                    $preview_width, $preview_height
                );
                if ($is_alpha) imagealphablending( $image_preview, false );

                switch ($preview_type) {
                    case IMAGETYPE_JPEG:
                        $image = imagecreatefromjpeg(
                            APP_ROOT.$this->path.SLASH.$enclosure
                        ); break;
                    case IMAGETYPE_PNG:
                        $image = imagecreatefrompng(
                            APP_ROOT.$this->path.SLASH.$enclosure
                        ); break;
                }

                // resize the image
                imagecopyresampled(
                    $image_preview, $image, 0, 0, 0, 0,
                    $preview_width, $preview_height,
                    imagesx( $image ), imagesy( $image )
                );
                imagedestroy( $image );

                // save the preview image:
                // TODO: watch out for RSS being called first before the
                //       preview image has been generated (best to force
                //       it from localhost). will move this to a separate
                //       file for general thumbnailing
                if ($is_alpha) {
                    // if transparent, preview must be PNG,
                    imagesavealpha( $image_preview, true );
                    imagepng(
                        $image_preview,
                        APP_ROOT.$this->path.SLASH.$preview_file
                    );

                } else {
                    // otherwise use a JPG preview instead for better filesize.
                    // resized PNGs are very large due to anti-aliasing
                    imagejpeg(
                        $image_preview,
                        APP_ROOT.$this->path.SLASH.$preview_file,
                        // why 80?
                        // <ebrueggeman.com/article_php_image_optimization.php>
                        80
                    );
                }
                imagedestroy( $image_preview );
            }
        }

        $template->replaceTextArray('.', [
            '__NAME__' => pathinfo( $enclosure, PATHINFO_FILENAME ),
            '__SIZE__' => array_reduce (
                // an inline way to format file size
                [' B', ' KB', ' MB'],
                function ($a,$b) {
                    return is_numeric( $a )
                    ? ($a>=1024 ? $a/1024 : number_format( $a, strlen( $b )-2 ).$b)
                    : $a;
                },
                filesize( APP_ROOT.$this->path.SLASH.$enclosure )
            )
        ])->set([
            './@href'     => "/$this->canonical_url/$enclosure",
            './@type'     => mimeType( $enclosure )
        ])->next();
    }
}


// input:
//==============================================================================
// each article on the website is of a particular content-type (determining its
// 'shape') -- blog | photo | quote &c. and exists as a text file (written in
// ReMarkable) in a folder for that type, e.g. “/blog/hello.rem”
//
// the mod_rewrite rules map article names
// to this script in a number of locations:
//
// 1. the actual location           ->  “/blog/hello” (permalink)
// 2. each tag for the article      ->  “/web-dev/hello”, “/code-is-art/hello”
// 3. no tag, from the home page    ->  “/hello”
//
// therefore the input to this script is not necessarily the physical location
// of the article, merely an article fragment name and an optional type or tag
// (referred to as the category), to apply to the next / previous-article links
//
// “article.php?article=blog/hello.rem”
$url_requested = (
    preg_match( '/^\.?[-a-z0-9_\/]+\.rem$/', @$_GET['article'] )
    ? $_GET['article'] : false
) or errorPage(
    'malformed_request.html',
    'Error: Malformed Request',
    ['URL' => '?article=category/article.rem']
);

// check if there is a category specified:
// note: this is the category specified in the URL; which can be
// any (or none!) of the types or tags the article is assigned to
$url_category = preg_match( '/^([-a-z0-9]+)\//', $url_requested, $_ )
    ? $_[1] : ''
;
// and the second half which is the article name (URL-version) to view:
$url_article = pathinfo( $url_requested, PATHINFO_FILENAME );
// as a file path with OS slashes
$path_requested = (
    $url_category ? $url_category.SLASH : ''
).$url_article;

// for the home page and index pages of each category,
// the latest article is shown. mod_rewrite handles this by rewriting:
//
//  “/” (home page) ->  “/latest”
//  “/blog/”        ->  “/blog/latest”
//
$is_latest = ($url_article == 'latest');


// process:
//==============================================================================

// retrieve the article index that
// lists all metadata for articles
$index_array = getIndex();

// filter down the index to only articles
// in the current category being viewed
$data = $url_category ? array_values(
    array_filter( $index_array, function($v) use ($url_category) {
        return (bool)(strpos( $v, "|$url_category|" ) !== false);
    })
) : $index_array;

// if viewing an index page, retrieve
// the latest article from the top of the stack
$_ = @explode ( '|', (string) $data[0] );
if ($is_latest) $url_article = array_pop( $_ );

// locate the index for the article
$index = @reset( preg_grep( "/\|$url_article$/", $index_array ));

// is the article name not in the index?
if (!$index) {
    // if it’s not an indexed article, then it may be
    // a ‘.rem’ file on disk we want to render (e.g. “/projects.rem”)
    if (!file_exists( APP_ROOT.$path_requested.'.rem' )) errorPageHTTP( 404 );
    $url_canonical = ($url_category ? $url_category.'/' : '').$url_article;

    // check if the file has a header,
    // it could be a draft (which is not indexed)
    if (@[$meta, $title, $content] = getArticle( $url_canonical )) {
        // if a date has not been provided in the draft, just use now
        $meta['date']    ??= date( 'YmdHi' );
        $meta['updated'] ??= date( 'YmdHi' );

        // generate a preview of the draft article
        //----------------------------------------------------------------------
        $article = new ArticleTemplate;
        $article->type = $url_category;
        $article->category = $url_category;
        $article->name = $url_article;
        $article->title = $meta['title'] ?? '';
        $article->date_published = (string) $meta['date'];
        $article->date_updated   = (string) $meta['updated'];
        $article->type = $url_category;
        $article->tags = $meta['tags'] ?? null;
        $article->licence = $meta['licence'] ?? null;
        $article->enclosures = $meta['enclosure'] ?? [];
        $article->content = $content;
        
        exit( $article );

    } else {
        // template a basic page rather than an article page
        //----------------------------------------------------------------------
        $template = new BaseTemplate( 'page.html' );
        $template->name = $url_article;
        $template->category = $url_category;
        $template->path = $path_requested;
        $template->canonical_url = $url_canonical;
        // TODO: .rem files should be able to specify title metadata
        $template->title = $url_article;
        $template->content = reMarkable(
            file_get_contents( APP_ROOT.$path_requested.'.rem' )
        );
        $html = (string) $template;
    };
    // what's the worst that could hap-
    goto output;
}

// article:
//==============================================================================

// extract the info from the article’s index
// (which looks like this: “datetime|type|tag|tag|tag|name”)
$article_type = @reset( array_slice( explode( '|', $index ), 1, 1 ));
$article_tags = count( explode( '|', $index )) == 3
              ? [] : array_slice( explode( '|', $index ), 2, -1 )
;
// the canonical URL for the article
$url_canonical = "$article_type/$url_article";
// the canonical path for the article, i.e. using OS-slashes
// (does NOT end in a slash)
$path_canonical = $article_type.SLASH.$url_article;

// if a category is specified and the article is not in that category
// redirect to the permalink version instead: (the user either typo’d
// or an article has changed its tags at some point)
if ($url_category && ($article_type != $url_category && !@in_array( $url_category, $article_tags ))) {
    // TODO: use HTTP/S here from the environment variable?
    header( 'Location: http://'.APP_HOST."/$url_canonical", true, 301 );
    exit;
}

// open the file and read the metadata and HTML
[$meta, $title, $content] = getArticle( $url_canonical );

// find the article’s relative position in the index
// so that we can link to the previous and next articles
$key = @reset( array_keys( $data, $index ));

// template the full page
//
$article = new ArticleTemplate;

// set the base properties:
// (used by all pages)
$article->category = $url_category;     // note: category from URL, not article
$article->name = $url_article;          // article name, i.e. URL slug
$article->title = $title;

// set the article properties:
// (specific to article pages)
//
// previous / next article links:
if (isset( $data[$key+1] ))
    $article->prev = @end( explode( '|', $data[$key+1] ))
;
if (isset( $data[$key-1] ))
    $article->next = @end( explode( '|', $data[$key-1] ))
;

// article:
//
// flatten the meta data array into variable scope
// (saves having to write `$a_meta['...']` a million times)
//
// TODO: extract here has problems with optional fields
//
extract( $meta, EXTR_PREFIX_ALL, 'm' );
$name = @end( explode( '/', $url_canonical, 2 ));

// article time & date:
$article->date_published = (string) $m_date;
$article->date_updated   = (string) $m_updated ?? null;

// article categories:
$article->type = $article_type;
$article->tags = $article_tags ?? null;

// article licence:
$article->licence = $m_licence ?? null;

// article enclosures:
$article->enclosures = $m_enclosure ?? [];

// article text:
//------------------------------------------------------------------------------
$article->content = $content;
$article->url = $m_url ?? '';

$html = (string) $article;


output:
//==============================================================================

// don’t cache on my localhost
if ($_SERVER['SERVER_ADDR'] != '127.0.0.1') {
    // before saving the cache,
    // replicate any sub folders in the cache area too
    @mkdir( APP_CACHE.dirname( $url_requested ), 0777, true );

    // save the cache
    file_put_contents(
        APP_CACHE.dirname( $url_requested )
        .'/'.pathinfo( $url_requested, PATHINFO_FILENAME ).'.html',
        $html, LOCK_EX
    ) or errorPage(
        'denied_cache.rem', 'Error: Permission Denied', ['PATH' => APP_CACHE]
    );
}

exit( preg_match( '/\.html($|\?)/', $_SERVER['REQUEST_URI'] ) ? template_tags(
    template_load( 'view-source.html' ), [
        'TITLE'  => pathinfo( $url_requested, PATHINFO_BASENAME ),
        'HEADER' => '',
        'CODE'   => htmlspecialchars( $html, ENT_NOQUOTES, 'UTF-8' )
    ]
) : $html );

?>