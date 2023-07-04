<?php
//==============================================================================
// camen design © copyright (c) Kroc Camen 2008-2022, BSD 2-clause
// (see LICENSE.TXT). bugs / suggestions → kroc@camendesign.com
//
declare( strict_types=1 );

// shared.php : where we avoid some duplication

// PHP 5.3 issues a warning if the timezone
// is not set when using date-related commands
date_default_timezone_set( 'Europe/London' );
error_reporting( E_ALL );

// “ReMarkable” is my Markdown-like syntax for writing in plain text
// and converting to HTML see the ReMarkable folder for documentation,
// or the website <camendesign.com/code/remarkable>
require_once 'remarkable/remarkable.php';

require_once 'domtemplate/domtemplate.php';

// could you BE any longer?
define( 'SLASH',            DIRECTORY_SEPARATOR );

// preferred domain
define( 'APP_HOST',         'camendesign.com' );
// full ‘.system’ path for absolute references
define ('APP_SYSTEM',       dirname( __FILE__ ).SLASH );
// full path to webroot
define( 'APP_ROOT',         realpath( APP_SYSTEM.'..'.SLASH ).SLASH );
// full path to cache folder
define( 'APP_CACHE',        APP_ROOT.'.cache'.SLASH );
// width (in px) of image previews
define( 'APP_PREVIEW_SIZE', 640  );

// <uk3.php.net/manual/en/function.is-dir.php#70005>
chdir( APP_ROOT );


// templating:
//==============================================================================

// simply load a file from the template directory
function template_load (string $template_name): string {
    return file_get_contents( APP_SYSTEM."design/templates/$template_name" );
}

// replace a marker (“&__TAG__;”) in the template with some other text
function template_tag (string $template, string $tag, string $content): string {
    return str_replace( "&__{$tag}__;", $content , $template );
}

// replace many markers in one go
function template_tags (string $template, array $values): string {
    foreach ($values as $key => &$value) {
        $template = template_tag( $template, (string) $key, (string) $value) ;
    }
    return $template;
}

//==============================================================================

// unfortunately this is the cheapest and most reliable way
// of doing this, despite being a bad way of going about it
function mimeType (string $extension): string {
    // you can pass a full filename if
    // you want to be lazy (saves extra code elsewhere)
    switch (pathinfo( strtolower( $extension ), PATHINFO_EXTENSION )) {
        // images
        case 'gif':                 return 'image/gif';                 break;
        case 'jpg': case 'jpeg':    return 'image/jpeg';                break;
        case 'png':                 return 'image/png';                 break;
        case 'bmp':                 return 'image/bmp';                 break;
        // code
        case 'asp':                 return 'text/asp';                  break;
        case 'css':                 return 'text/css';                  break;
        case 'html':                return 'text/html';                 break;
        case 'js':                  return 'application/javascript';    break;
        case 'php':                 return 'application/x-httpd-php';   break;
        // documents
        case 'pdf':                 return 'application/pdf';           break;
        case 'txt':                 return 'text/plain';                break;
        case 'rem':                 return 'text/remarkable';           break;
        // downloads
        case 'exe': case 'dmg':     return 'application/octet-stream';  break;
        case 'sh':                  return 'application/x-sh';          break;
        case 'zip':                 return 'application/zip';           break;
        // media
        case 'mp3':                 return 'audio/mpeg';                break;
        case 'oga':                 return 'audio/ogg';                 break;
        case 'ogv':                 return 'video/ogg';                 break;
        // fonts
        case 'ttf':                 return 'font/ttf';                  break;
        case 'otf':                 return 'font/otf';                  break;
        case 'woff':                return 'font/x-woff';               break;
        default:                    return 'application/octet-stream';  break;
    }
}

// convert an HTML string to a text representation
// for titles that don’t allow HTML (e.g. rss, `<title>`)
function rssTitle (string $string): string {
    // replace some HTML tags with text equivalents. this is done so that
    // formatting in the titles can be conveyed to people in their RSS readers,
    // where an `<em>` might stress a word in a particularly important way &c.
    $string = str_replace(
        ['<strong>', '</strong>', '<em>', '</em>', '<q>', '</q>', '<code>', '</code>', '<br />'],
        ['*'       , '*'        , '_'   , '_'    , '“'  , '”'   , '`'     , '`',       ' '     ],
        $string
    );
    // strip out any remaining HTML tags. PHP’s `strip_tags` also strips
    // double encoded tags (i.e. `&lt;...&gt;`) which we do not want
    return preg_replace( '/<[^<]+?>/', '', $string );
}

//==============================================================================

// read an article file and split it into
// the meta-data, and the article content
function getArticle (string $article): array {
    $_ = trim(
        preg_replace( '/\r\n?/', "\n", @file_get_contents( "$article.rem" ))
    );
    
    // open the file and read the header and HTML
    list ($meta, $content) = explode( "\n\n", $_, 2 );
    // if the file does not carry a header,
    // it’s not an article (like ‘/projects.rem’)
    if ($meta[0] != '{') return [];
    
    // the header is a JSON object containing
    // the meta-information (date, tags, enclosure &c.)
    if (!$meta = json_decode( $meta, true )) errorPage(
        // if the JSON did not decode, there must be a typo--exit here
        'json.rem', 'Error: Malformed JSON Header', ['PATH' => $article]
    );
    return [
        $meta,
        // if a title is provided in the meta use that,
        // else search for the first ReMarkable H1 and use that
        @$meta['title'] ? $meta['title'] : (
            preg_match( '/^# (.*?) #(?: \(#.+?\))?$/m', $content, $_ )
            ? $_[1] : ''
        ),
        $content
    ];
}

// produce a list of all the meta data from all of the articles, ordered by
// date and save to cache this is used as the basis for the next / prev article
// links and tag-clouds
function indexSite (): void {
    // get a list of the various content-types
    // from the folders in the site (blog | photo | quote &c.)
    //--------------------------------------------------------------------------
    $types = array_fill_keys( array_filter(
        // include only directories, but ignore directories starting with ‘.’
        preg_grep( '/^(\.|_)/', scandir( '.' ), PREG_GREP_INVERT ), 'is_dir'
    ), 0 );
    
    // generate an index of the articles on the site
    //--------------------------------------------------------------------------
    $list = $tags = [];
    $xml = '';
    
    // get each entry in each content-type folder
    foreach ($types as $type => &$count) foreach (
        preg_grep( '/\.rem$/', scandir( $type )) as $file_name
    ){
        $name = pathinfo( $file_name, PATHINFO_FILENAME );
        
        // get the article’s header and content.
        // if it doesn’t have a header, skip it
        if (!list ($meta, $title, $content) = getArticle( "$type/$name" )) continue;
        
        // if the article is a draft, do not index it.
        // it will be visible only at its permalink
        if (@$meta['draft']) continue;
        
        // update information:
        $modified = false;
        // if the file has no date: it’s new. give it a date
        if (!isset( $meta['date'] ))    {$meta['date']    = date( 'YmdHi' ); $modified = true;}
        // if the file has no updated meta, add it.
        // the entry will be pushed to the top of the RSS feed
        if (!isset( $meta['updated'] )) {$meta['updated'] = date( 'YmdHi' ); $modified = true;}
        // save the file if the date/updated fields have been changed:
        // (of course, I could have just used `json_encode` here,
        // but then it wouldn’t be tidy)
        if ($modified) {
            // what if we can’t save to disk?
            if (!is_writable( "$type/$file_name" )) @chmod( "$type/$file_name", 0777 ) or errorPage(
                'denied_article.rem', 'Error: Permission Denied', ['PATH' => "$type/$file_name"]
            );
            file_put_contents ("$type/$file_name",
                "{\t\"date\"\t\t:\t{$meta['date']},\n"
                ."\t\"updated\"\t:\t{$meta['updated']}"
                // optional stuff
                .(@$meta['title']     ? ",\n\t\"title\"\t:\t\"".str_replace( '"', '\"', $meta['title'] )
                    ."\"" : '')
                .(@$meta['licence']   ? ",\n\t\"licence\"\t:\t\"{$meta['licence']}\"" : '')
                .(@$meta['tags']      ? ",\n\t\"tags\"\t\t:\t[\"".implode ('", "',$meta['tags'])."\"]" : '')
                .(@$meta['enclosure'] ? ",\n\t\"enclosure\"\t:\t[\""
                    .implode ('", "',$meta['enclosure'])."\"]" : '')
                .(@$meta['url']       ? ",\n\t\"url\"\t\t:\t\"{$meta['url']}\"" : '')
                .(@$meta['draft']     ? ",\n\t\"draft\"\t\t:\t{$meta['draft']}\n" : "\n")
                // the entry’s content
                ."}\n\n".$content
            );
        }
        
        // add each entry to the XML sitemap
        $xml .= template_tags( template_load( 'url.xml' ), [
            'DOMAIN' => APP_HOST,
            'URL'    => "$type/$name",
            'DATE'   => date( 'c', filemtime( APP_ROOT."$type/$file_name" ))
        ]);
        
        // add this entry to the index
        array_push( $list,
            $meta['updated']."|$type|".(
                // avoid a warning if the article has no 'tags'
                isset($meta['tags']) ? @implode( '|', $meta['tags'] ) : ''
            ).(@$meta['tags'] ? '|' : '').$name
        );
        
        // add to the count for each of the tags
        if (isset( $meta['tags'] )) foreach ($meta['tags'] as $tag) @$tags[$tag]++;
        
        // add to the count for this content-type
        $count++;
    }
    arsort( $types ); // sort content-types by count, descending (for tag-cloud)
    arsort( $tags );  // sort tags by count, descending (also for tag-cloud)
    rsort ( $list );  // sort index, the updated-date is used for order
    
    // save list to cache
    //--------------------------------------------------------------------------
    if (!is_writable( APP_CACHE )) @chmod( APP_CACHE, 0777 ) or errorPage(
        'denied_cache.rem', 'Error: Permission Denied', ['PATH' => APP_CACHE]
    );
    file_put_contents( APP_CACHE.'index.types', serialize( $types ));
    file_put_contents( APP_CACHE.'index.tags',  serialize( $tags ));
    file_put_contents( APP_CACHE.'index.list',  serialize( $list ));
    
    // rebuild sitemap?
    //--------------------------------------------------------------------------
    // TODO: upon rebuilding the index & setting the update date, this issues
    //       a warning -- changing array pointer on an ad-hoc array
    clearstatcache(); $date = reset( @explode( '|', $list[0] ));
    if (!file_exists( APP_SYSTEM.'sitemap.xml' ) || filemtime( APP_SYSTEM.'sitemap.xml' ) < mktime(
        (integer) substr( $date, 8,  2 ),
        (integer) substr( $date, 10, 2 ), 0,
        (integer) substr( $date, 4,  2 ),
        (integer) substr( $date, 6,  2 ),
        (integer) substr( $date, 0,  4 )
    )) {
        // save the cache
        file_put_contents( APP_SYSTEM.'sitemap.xml',
            template_tag( template_load( 'base.xml' ), 'URLS', $xml ), LOCK_EX
        ) or errorPage(
            'denied_cache.rem', 'Error: Permission Denied', ['PATH' => APP_CACHE]
        );
    };
}

function getTypes (): array {
    if (!is_array( $types = unserialize( (string) @file_get_contents( APP_CACHE.'index.types' )))) {
        indexSite(); return getTypes();
    }
    return $types;
}

function getTags (): array {
    if (!is_array( $tags = unserialize( (string) @file_get_contents( APP_CACHE.'index.tags' )))) {
        indexSite(); return getTags();
    }
    return $tags;
}

function getIndex (): array {
    if (!is_array( $list = unserialize( (string) @file_get_contents( APP_CACHE.'index.list' )))) {
        indexSite(); return getIndex();
    }
    return $list;
}

//==============================================================================

// all pages share this base HTML template
//
class BaseTemplate
    extends kroc\DOMTemplate
{
    public string $name = '';           // name (URL-slug) of the page
    public string $category ='';        // optional, category (tag) of page

    public string $canonical_url = '';  // canonical URL of page
    public string $path = '';           // canonical path (i.e. OS slashes)
    public string $title = '';          // page title (optional)
    public ?string $content;            // page content (HTML string)

    public function __construct(
        string $template_name
    ){
        parent::__construct( file_get_contents(
            APP_SYSTEM.'templates'.SLASH.$template_name
        ));
    }

    public function __toString(): string {
        //----------------------------------------------------------------------
        // header:
        //----------------------------------------------------------------------
        // <head> meta:
        $this->set([
            './title' => $this->title.(
                // append the category, if present
                $this->category ? ' · '.$this->category : ''
            )
        // header links:
        ])->remove([
            // home page?
            './header//nav/ul[1]/li[1]/a/@rel'
                => $this->category || $this->name == 'projects',
            // projects page?
            './header//nav/ul[1]/li[2]/a/@rel'
                => $this->name !== 'projects',
        ]);

        // site categories:
        //
        // avoid a nasty race condition where by the index is not present
        // because of an error generating the index and this function tries
        // to load the index to create the category list on the error page
        //
        if (!file_exists( APP_CACHE.'index.list' )) {
            // if the index is not present, remove the category list entirely
            $this->remove( './header//ul' );
        } else {
            // create the sub-template for the category type label
            $type_template = $this->repeat( './header//ul[2]/li[1]' );
            // loop over all category types in the site
            foreach (
                getTypes() as $_type => $_count
            ) $type_template->set([
                'a' => $_type,
                'a/@href ' => "/$_type/"
            ])
            ->remove([ 'a@rel' => $this->category != $_type ])
            ->next();

            // create the sub-template for the category tag label
            $tag_template = $this->repeat( './header//ul[3]/li[1]' );
            // loop over all category tags in the site
            foreach (
                getTags() as $_tag => $_count
            ) $tag_template->set([
                'a' => $_tag,
                'a/@href ' => "/$_tag/"
            ])
            ->remove([ 'a@rel' => $this->category != $_tag ])
            ->next();

            // fix whitespace (todo: last element)
            $this->appendAfter(
                './header//ul[2]/li|./header//ul[3]/li', "\n\t\t"
            );
        }

        // page content:
        //----------------------------------------------------------------------
        if(isset( $this->content )) $this->append( 'article', $this->content );

        // page footer:
        //----------------------------------------------------------------------
        $this->set([
            // links to the source .rem & .html version of the article
            './footer/nav/a[@rel="nofollow"][1]/@href' => "$this->canonical_url.rem",
            './footer/nav/a[@rel="nofollow"][2]/@href' => "$this->canonical_url.html",
            './footer/form/input[@name="sites"]/@value' => APP_HOST,
        ]);

        return parent::__toString();
    }
}

//==============================================================================

// generate a whole HTML page, given the article content
// and optional header and footer block to include
function templatePage (
    string $content,
    string $title,
   ?string $header = '',
   ?string $footer = ''
) : string {
    return template_tags( template_load( 'base.html' ), [
        'TITLE'   => $title,
        'HEADER'  => $header,
        'CONTENT' => $content,
        'FOOTER'  => $footer,
    ]);
}

// the standard header
function templateHeader (
   ?string $href        = '',
   ?string $s_next      = '',
   ?string $s_prev      = '',
   ?string $s_canonical = ''
) : string {
    // check if there is a category specified:
    $filter  = preg_match( '/^([-a-z0-9]+)\//', $href, $_ ) ? $_[1] : '';
    // and the second half which is the article:
    $article = pathinfo( $href, PATHINFO_FILENAME );
    
    // avoid a nasty race condition where by the index is not present because
    // of an error generating the index and this function tries to load the
    // index to create the category list on the error page
    if (file_exists( APP_CACHE.'index.list' )) {
        $types = $tags = '';
        $tag   = template_load( 'tag.html' );
        $on    = template_load( 'tag-on.html' );
        
        foreach (getTypes() as $category => $count) $types .= template_tags( $tag, [
            'TAG'   => $category,
            'ON'    => $filter == $category ? $on : '',
            'HREF'  => "/$category/"
        ]);
        foreach (getTags() as $category => $count) $tags .= template_tags ( $tag, [
            'TAG'   => $category,
            'ON'    => $filter == $category ? $on : '',
            'HREF'  => "/$category/"
        ]);
        
        return template_tags( template_load( 'base.header.html' ), [
            'RSS_URL'     => $filter ? "/$filter/rss" : '/rss',
            'RSS_TITLE'   => $filter ? "Just $filter" : 'All categories',
            'CANONICAL'   => $s_canonical ? template_tag(
                template_load( 'base.header.canonical.html' ), 'URL', $s_canonical
            ) : '',
            // home page?
            'ALL_ON'      => !$filter && $article !== 'projects' ? $on : '',
            // projects page?
            'PROJECTS_ON' => $article == 'projects' ? $on : '',
            // type/tag cloud
            'CLOUD_TYPES' => $types,    'CLOUD_TAGS'  => $tags,
            // navigation links
            'PREV'        => $s_prev,   'NEXT'        => $s_next,
        ]);
    }
}

// the standard footer
function templateFooter (string $href): string {
    return template_tags( template_load( 'base.footer.html' ), [
        'HREF' => $href,
        'HOST' => APP_HOST
    ]);
}

//==============================================================================

// template the HTML for a content-entry (blog, quote, art &c.)
function templateArticle (
    array  &$meta,
    string $type,
    string $href,
    string &$s_content,
    string $category
) : string {
    // flatten the meta data array into variable scope
    // (saves having to write `$a_meta['...']` a million times)
    extract( $meta, EXTR_PREFIX_ALL, 'm' );
    $name = @end( explode( '/', $href, 2 ));

    // an image enclosure gets a preview image
    //--------------------------------------------------------------------------
    // (this switch statement is going to bend your mind a bit)
    if (@$m_enclosure) foreach ($m_enclosure as $enclosure) switch (true) {
        // for enclosures that are images we want to create a preview image,
        // but for both images and any other file format we want to template
        // HTML for the file icon
        case [$preview_width, $preview_height, $preview_type] = getimagesize( APP_ROOT."$href/$enclosure" ):
        
        // is the image a 32-bit PNG (has transparency)
        // <camendesign.com/code/uth1_is-png-32bit>
        $is_alpha = ($preview_type == IMAGETYPE_PNG)
            ? ord( file_get_contents( APP_ROOT."$href/$enclosure", false, null, 25, 1 )) & 4 : false
        ;
        // decide the preview file’s file type:
        // * a JPG always has a JPG preview
        // * a PNG has a JPG preview unless it has transparency,
        //   resulting in a PNG preview
        $preview_file = $preview_width<=APP_PREVIEW_SIZE
            ? $enclosure : pathinfo( $enclosure, PATHINFO_FILENAME ).'_preview.'.($is_alpha ? 'png' : 'jpg')
        ;
        // scale the height according to ratio
        $preview_height = $preview_width<=APP_PREVIEW_SIZE
                ? $preview_height:APP_PREVIEW_SIZE * ($preview_height / $preview_width);
        $preview_width  = $preview_width<=APP_PREVIEW_SIZE
                ? $preview_width :APP_PREVIEW_SIZE;
        
        if (// a preview file is required, and it does not exist, create it...
            //------------------------------------------------------------------
            $preview_file != $enclosure &&
            !file_exists( APP_ROOT."$href/$preview_file" )
        ) {
            $image_preview = imagecreatetruecolor( $preview_width, $preview_height );
            if ($is_alpha) imagealphablending( $image_preview, false );
            
            switch ($preview_type) {
                case IMAGETYPE_JPEG: $image = imagecreatefromjpeg( APP_ROOT."$href/$enclosure" ); break;
                case IMAGETYPE_PNG:  $image = imagecreatefrompng(  APP_ROOT."$href/$enclosure" ); break;
            }
            // resize the image
            imagecopyresampled(
                $image_preview, $image, 0, 0, 0, 0,
                $preview_width, $preview_height,
                imagesx( $image ), imagesy( $image )
            );
            imagedestroy( $image );
            
            // save the preview image:
            // TODO: watch out for RSS being called first before the preview
            //       image has been generated (best to force it from localhost).
            //       will move this to a separate file for general thumbnailing
            if ($is_alpha) {
                // if transparent, preview must be PNG,
                imagesavealpha( $image_preview, true );
                imagepng( $image_preview, APP_ROOT."$href/$preview_file" );
            } else {
                // otherwise, use a JPG preview instead for better filesize.
                // resized PNGs are very large due to anti-alias. why 80?
                // <ebrueggeman.com/article_php_image_optimization.php>
                imagejpeg( $image_preview, APP_ROOT."$href/$preview_file", 80 );
            }
            imagedestroy( $image_preview );
        }
        
        // no break, we fall through to default
        // and template the HTML, same as for non-images
        default:
        @$enclosure_html .= template_tags( template_load( 'article.enclosure.html' ), [
            'NAME' => pathinfo( $enclosure, PATHINFO_FILENAME ),
            'HREF' => "/$href/$enclosure",
            'MIME' => mimeType( $enclosure ),
            // an inline way to format file size
            'SIZE' => array_reduce (
                [' B', ' KB', ' MB'],
                function ($a,$b) {
                    return is_numeric( $a )
                    ? ($a>=1024 ? $a/1024 : number_format( $a, strlen( $b )-2 ).$b)
                    : $a;
                },
                filesize( APP_ROOT."$href/$enclosure" )
            )
        ]);
    }
    
    // convert timestamp for the date formatting functions below. for strict
    // types, the chosen timestamp will be a float and needs converting
    $mktime = (string)($m_updated>$m_date ? $m_updated : $m_date);
    $date = mktime(
        (integer) substr( $mktime, 8,  2 ),
        (integer) substr( $mktime, 10, 2 ), 0,
        (integer) substr( $mktime, 4,  2 ),
        (integer) substr( $mktime, 6,  2 ),
        (integer) substr( $mktime, 0,  4 )
    );
    return template_tags( template_load( 'article.html' ), [
        // time:
        //----------------------------------------------------------------------
        // 'M': “A short textual representation of a month, three letters”
        'MON'   => date( 'M', $date ),
        // 'F': “A full textual representation of a month”
        'MONTH' => date( 'F', $date ),
        // 'j': “Day of the month without leading zeros”
        'DAY'   => date( 'j', $date ),
        // 'Y': “A full numeric representation of a year, 4 digits”
        'YEAR'  => date( 'Y', $date ),
        // 'c': “ISO 8601 date” (for `datetime` attribute of `<time>`)
        'DATE'  => date( 'c', $date ),
        // 'g': “12-hour format of an hour without leading zeros”
        'HOUR'  => date( 'g', $date ),
        // 'i': “Minutes with leading zeros”
        'MINS'  => date( 'i', $date ),
        // 'a': “Lowercase Ante meridiem and Post meridiem”
        'AMPM'  => date( 'a', $date ),

        // article:
        //----------------------------------------------------------------------
        // we process the markup into HTML here to avoid a race condition where
        // reMarkable is trying to get the image dimensions of a preview image
        // before it gets generated in the enclosure section above
        'CONTENT'    => reMarkable( template_tags( $s_content, [
             'TITLE' => @$m_title,
             'HREF'  => $href,
             'URL'   => @$m_url
        ])),

        // optionals:
        //----------------------------------------------------------------------
        'LICENCE'    => @!$m_licence   ? '' : template_load( "licences/$m_licence.html" ),
        'ENCLOSURE'  => @!$m_enclosure ? '' : $enclosure_html,

        // tags:
        //----------------------------------------------------------------------
        'CATEGORIES' => template_tags( template_load( 'tag-bookmark.html' ), [
            'TAG' => $type, 'HREF' => "/$type/$name",
            'ON'  => $type==$category ? template_load( 'tag-bookmark-on.html' ) : ''
        ]).(@!$m_tags ? '' : array_reduce(
            // template each tag and concatenate into a list
            $m_tags, function ($a, $b) use($name, $category) {
                return $a .= template_tags( template_load( 'tag.html' ), [
                    'TAG' => $b, 'HREF' => "/$b/$name",
                    'ON'  => $b == $category
                             ? template_load( 'tag-on.html' )
                             : ''
                ]);
            })
        )
    ]);
}


//==============================================================================

// return the HTML for an error page
function errorPage (
    string $template,
    string $title,
   ?array  $data=[]
) : void {
    // template the error, swapping custom replacement fields
    die (templatePage(
        reMarkable( template_tags( template_load( "errors/$template" ), $data )), $title,
        templateHeader(),
        templateFooter( '.system/design/templates/errors/'.pathinfo( $template, PATHINFO_FILENAME ))
    ));
}

// return an error with an HTTP status code like 404, 500 &c.
function errorPageHTTP (int $http_code): void {
    // send the error code to the browser
    header( ' ', true, $http_code );
    // generate the error page
    $html = templatePage(
        reMarkable( template_load( "errors/$http_code.rem" )), (string) $http_code,
        templateHeader(), templateFooter( ".system/design/templates/errors/$http_code" )
    );
    // cache it
    file_put_contents( APP_CACHE."$http_code.html", $html, LOCK_EX ) or errorPage(
        'denied_cache.rem', 'Error: Permission Denied', ['PATH' => APP_CACHE]
    );
    // display it
    die( $html );
}

?>