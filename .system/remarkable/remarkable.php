<?php
//==============================================================================
// ReMarkable v6 © copyright (C) Kroc Camen 2008-2019, BSD 2-clause
// (see LICENSE.TXT). bugs / suggestions → kroc@camendesign.com

// options:
//------------------------------------------------------------------------------
// combine these options using an OR `||` operator
// and supply to the options parameter
//
// output HTML “<br>” instead of XHTML (deafult) “<br />”
define ('REMARKABLE_NOXHTML',    1);
// output tabs as spaces, 2 per tab
define ('REMARKABLE_TABSPACE_2', 2);
// output tabs as spaces, 4 per tab
// (combine with above for 8 per tab)
define ('REMARKABLE_TABSPACE_4', 4);

//------------------------------------------------------------------------------
// allow ReMarkable usage from the command line:
// use `php ./path/to/remarkable.php <indent> <margin> <base_path> <options>`
// and pass the text via stdin, e.g.
//
// from file:   `php remarkable.php < documentation.rem`
// inline text: `echo "the quick¬brown fox" | php remarkable.php`
//
if (@$_SERVER['argv'][0] == basename (__FILE__)) exit (remarkable (
    file_get_contents ('php://stdin'),  // read stdin from command line
    $_SERVER['argv'][1] ?? 0,           // indent (optional)
    $_SERVER['argv'][2] ?? 124,         // margin (optional)
    $_SERVER['argv'][3] ?? './',        // base path (optional)
    $_SERVER['argv'][4] ?? 0            // options (optional)
));
//------------------------------------------------------------------------------
function remarkable (
    $source_text,       // source text to process, UTF-8 only
    $indent=0,          // indent the resulting HTML by n tabs
    $margin=124,        // word-wrap paragraphs at this character limit,
                        // use `false` for none
    $base_path='./',    // relative or absolute path from this script
                        // to any referenced image files
    $options=0          // (see options section at top of page)
) {
    // the reason 124 is used as the wrap margin is because Firefox’s
    // view->source window maxmized at 1024x768 is 124 chars wide and seems
    // like a modern enough standard for code compared to the behaviour of
    // writing readme files at 77 chars wide because that’s the viewport of a
    // maximised Notepad window on a 640x480 screen. tabs of 8 are used because
    // that is what Firefox & Notepad use and it maintains the same rendering
    // in both the editor, and browser
    
    // if an esentially empty string is given, return blank
    if (!strlen (trim ($source_text))) return '';
    
    // unify carriage returns (if you happen to be processing ReMarkable files
    // written on Windows/Linux/Mac &c.) a blank line is added to the end to
    // allow lists and blockquotes to convert if the user leaves no trailing
    // line we don’t left-trim inline whitespace in case the source text starts
    // with a purely typographical tab indent
    $source_text = trim (rtrim (preg_replace ('/\r\n?/', "\n", $source_text)), "\n")."\n\n";
    
    // will we be using the X in XHTML?
    $x = ($options && REMARKABLE_NOXHTML) ? '' : ' /';
    
    
    // list of mime-types for hyperlinks pointing directly to a file:
    //--------------------------------------------------------------------------
    // this is absolutely not supposed to be a comprehensive list, quite the
    // opposite in fact. this list is just my idea of the most important files
    // that are directly hyperlinked to in articles that users may want to be
    // warned about beforehand via CSS mime-type icons &c.
    //
    $mimes = array (
        // image-types
        // TODO: WebP
        'jpg'     => 'image/jpeg',              'jpeg' => 'image/jpeg',
        'png'     => 'image/png',
        'gif'     => 'image/gif',
        'bmp'     => 'image/bmp',
        'ai'      => 'application/postscript',
        'eps'     => 'application/postscript',
        'svg'     => 'image/svg+xml',           'svgz' => 'image/svg+xml',
        'psd'     => 'image/vnd.adobe.photoshop', 
        // document-types
        'txt'     => 'text/plain',
        'pdf'     => 'application/pdf',
        'doc'     => 'application/msword',
        'xls'     => 'application/vnd.ms-excel',
        'csv'     => 'text/csv',
        'ppt'     => 'application/vnd.ms-powerpoint',
        'odt'     => 'application/vnd.oasis.opendocument.text',
        'ods'     => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp'     => 'application/vnd.oasis.opendocument.presentation',
        
        // code-files
        'css'     => 'text/css',
        'js'      => 'application/javascript',
        // downloads
        'exe'     => 'application/octet-stream',
        'dmg'     => 'application/octet-stream',
        'iso'     => 'application/octet-stream',
        'zip'     => 'application/zip',
        'rar'     => 'application/x-rar-compressed',
        'tar'     => 'application/x-tar',
        'gz'      => 'application/x-gzip',
        'torrent' => 'application/x-bittorrent',
        // audio
        'oga'     => 'audo/ogg',                'wav'  => 'audio/wav',
        'mp3'     => 'audio/mpeg',              'm4a'  => 'audio/mp4a-latm',
        'midi'    => 'audio/midi',
        // video
        // TODO: WebM
        'mp4'     => 'video/mp4',               'm4v'  => 'video/mp4',
        'mpeg'    => 'video/mpeg',              'mpg'  => 'video/mpeg',
        'mov'     => 'video/quicktime',         'avi'  => 'video/x-msvideo',
        'ogv'     => 'video/ogg'
    );
    
    
    // [1] preprocess and remove HTML
    //==========================================================================
    // run a set of regexes to remove and process chunks of text that can cause
    // syntax conflicts. for example, in pre, samp and code sections any text
    // could appear that could accidentally trigger unwanted ReMarkable syntax.
    // these are removed and replaced by temporary placeholders in the form of
    // “¡TAG######!” where TAG is the name of the HTML tag being removed, or
    // other symbol and the number of hashes extends to the length of the
    // content being removed. at the end, the removed content is placed back
    
    // this will be used to store the HTML until the end.
    // it’ll expand for each HTML tag as it’s met
    $placeholders = array ();
    
    foreach (array (
        // placeholders already in the text
        // (e.g. documentation)
        //
        '@' => '/\xA1[@#A-Z1-6]+%*!/u',
        
        // <pre>
        //----------------------------------------------------------------------
        // e.g.                     or (with optional language)
        //
        //     ~~~>                     ~~~ PHP ~~~>        
        //         text goes here           code goes here
        //     <~~~                     <~~~
        //
        'PRE' => '/~~~(?: ([a-z0-6]+) ~~~)?>\n((?>(?R)|(?>.))*?)\n(\t*)<?~~~$/msi',
        
        // <code> / <samp>
        //----------------------------------------------------------------------
        // e.g. Using `_emphasis_` will generate ``<em>emphasis</em>``.
        //
        'CODE' => '/``(.+?)``(?!`)/',
        'SAMP' => '/`((?:``|[^`]+)+?)`(?!`)/',
        
        // <!-- … ---> / &__TOC__;
        //----------------------------------------------------------------------
        // HTML comments could contain ReMarkable syntax. the TOC marker is
        // stored here as the “&” must not be encoded by ReMarkable (allowing
        // for the TOC marker in CODE/PRE) and musn’t get wrapped in `<p>`
        //
        '#' => '/<!--(.*?)-->|&__TOC__;/s',
        
        // <img /> / <a><img /></a>
        //----------------------------------------------------------------------
        // img is dealt with here because the alt and title attributes could
        // contain ReMarkable syntax, also the syntax allows you to create
        // thumbnails and this has to be expanded into HTML. e.g.
        //
        // alt-text:    <"alt text" /path/to/image.png>
        // with title:  <"alt text" /path/to/image.png "title">
        // thumbnail:   <"alt text" /path/to/thumb.jpg = /path/to/image.png>
        //
        'IMG' => '/'
            .'<'
                .'("[^"]*")'
                .'([ ]|\n\s*)'
                .'(\S+?\.(?:png|gif|jpe?g|svgz?))'
                .'(?:(?2)=(?2)(\S+?))?'
                .'(?:(?2)((?1)))?'
            .'>/i',
        
        // <a>
        //----------------------------------------------------------------------
        // e.g.
        //     <Click here (http://google.com)>     with description
        //     <Click here (blog/hello)>.           relative, with description
        //     <description (/href) "title">        with title
        //     Visit <camendesign.com>              without description. protocol is optional
        //     E-mail me: <kroc@camendesign.com>    e-mail address, without description
        //
        'A' => '/'
            // the syntax always begins with "<"
            .'<'
            // $1: the optional description, any non "<"/">" text.
            //     when the decription is present, the URL follows via a space
            //     and an opening bracket; i.e. `<text (url)>`. without the
            //     description, the bracket is not required, i.e. `<url>`
            .'(?:([^<>]+?)[ ]\()?'
            // $2: the URL may be prepended with `^` to indicate "no-follow"
            .'(\^)?'
            // $3: capture for the whole URL
            .'('
                // $4: optional URL protocol; e.g. "http:", "https:", "ftp:"
                .'((?:[a-z]{3,10}:)?\/{0,3})?'
                // if the URL begins "www" consume this,
                // so that it's not included in the friendly URL
                .'(?:www\.)?'
                // $5: "friendly" URL -- no protocol or "www." (if present)
                // TODO: domain name parsing needs to enforce a character between dots,
                // TODO: and TLDs of any length (e.g. ".website", ".museum")
                .'('
                    // $6: email address
                    .'([a-z0-9._%+-]+@[a-z0-9.-]+)?'
                    // domain name, if protocol was present,
                    // i.e. the URL *cannot* be local
                    .'(?(4)[a-z0-9.-]{2,}(?:\.[a-z]{2,4})+'
                .'|'
                    // if there was no description, i.e. ``if $1 "" else "..."``
                    .'(?(1)|[a-z0-9.-]{2,}(?:\.[a-z]{2,4})+))'
                .')'
                // $7: path / filename (if present)
                //     e.g. "some.website/path/file"
                .'('
                    // do not allow URL path for e-mail addresses
                    .'(?(4)\/|(?(1)|\/))'
                    // the URL path / query-string &c.
                    .'[\/a-z0-9_!~*\'().;?:@&=+$,%-]*'
                .')?'
                // the URL "bookmark", i.e. "url#..."
                .'(?:\x23[\S]+)?'
            .')'
            // if the description was present, then the URL is wrapped
            // in parentheses; there must be a closing bracket
            .'(?(1)\))'
            // $8: optional title
            //     e.g. <desc (url) "title">
            .'(?:[ ]("[^"]*"))?'
            // closing angle-bracket,
            // and set case-insensitivity
            .'>/i',
        
        // <*>
        //----------------------------------------------------------------------
        // this last item removes all remaining HTML tags. the regex negative
        // before & after avoids accidentally detecting inline quotes
        // `<<an example>>`
        //
        '*' => '/(?<!<)<(\/)?([a-z1-6]+)(?(1)>|(?: [^>]+)?>(?!>))/'
        
    // (the `if` here is just an inline way of setting `$offset`
    // to 0 each loop without having to indent another level)
    ) as $tag => $regx) if (!$offset=0) while (
        // we don’t use `preg_match_all` because we will be removing the found
        // text and replacing it with sometimes longer strings, putting the
        // captured offsets out of place
        preg_match ($regx, $source_text, $m, PREG_OFFSET_CAPTURE, $offset)
    ) {
        $type = $tag;
        switch ($tag) {
            // ReMarkable markup is *not* processed inside <pre> blocks, they
            // are HTML encoded and then removed from the source text until
            // the end where they are re-inserted
            case 'PRE':
                //--------------------------------------------------------------
                // if language paramter given, wrap in a code span too;
                // HTML-encode the preformatted block (HTML code examples, &c.)
                $text = (strlen ($m[1][0]) ? '<pre><code>' : '<pre>').htmlspecialchars (
                    // if the PRE block was indented (inside a list),
                    // unindent accordingly
                    preg_replace ('/^\t{'.strlen ($m[3][0]).'}/m', '', $m[2][0]),
                    ENT_NOQUOTES,
                    'UTF-8'
                ).(
                    strlen ($m[1][0]) ? '</code></pre>' : '</pre>'
                );
                break;
            
            // samp “` … `” and code “`` … ``” are inline
            // pre blocks with contents displayed as-is
            case 'CODE':
            case 'SAMP':
                //--------------------------------------------------------------
                $text = ($tag == 'CODE' ? '<code>' : '<samp>').
                    htmlspecialchars ($m[1][0], ENT_NOQUOTES, 'UTF-8').
                    ($tag == 'CODE' ? '</code>' : '</samp>')
                ;
                break;
            
            // syntax for ReMarkable images is replaced with the HTML, but not
            // swapped for placeholders until all HTML tags are removed. this
            // is done so that the open and close part of the `<a>` tag will be
            // removed separately leaving the text between (for word wrapping).
            // this also applies for the thumbnail syntax that generates
            // “<a><img /></a>” which is detected later and split and indented
            case 'IMG':
                //--------------------------------------------------------------
                // get image size for width / height attributes on the img tag
                $info = getimagesize ("{$base_path}{$m[3][0]}");
                $link = $mimes[pathinfo ($m[4][0], PATHINFO_EXTENSION)] ?? '';
                // swap in the HTML
                $source_text = substr_replace ($source_text,
                    // if a thumbmail, include the link
                    (strlen($m[4][0]) ? "<a href=\"{$m[4][0]}\"".($link ? " type=\"$link\"" : '').'>' : '').
                    // construct the image tag
                    "<img src=\"{$m[3][0]}\" alt={$m[1][0]}".(@$m[5][0] ? " title={$m[5][0]}" : '')
                    .(isset ($info[0]) ? " width=\"{$info[0]}\" height=\"{$info[1]}\"" : '')."$x>"
                    .(strlen($m[4][0]) ? '</a>' : ''),
                    // replacement start and length
                    $m[0][1], strlen ($m[0][0])
                );
                // find the next image and don’t swap for a placeholder yet
                // (as described above) `continue 2` is used because `continue`
                // will loop the `switch` statement, not the `while`
                continue 2;
            
            // hyperlinks
            case 'A':
                //--------------------------------------------------------------
                // get mime type (if known) of what’s being linked to
                $link = @$mimes[pathinfo ($m[7][0], PATHINFO_EXTENSION)];
                // replace the ReMarkable syntax with HTML, as with img above
                $source_text = substr_replace ($source_text,
                    '<a href="'
                        // add deafult protocol if no link description,
                        // and protocol was omitted
                        .(!$m[1][0] && !$m[4][0]
                        ? (isset($m[6][0]) ? 'mailto:' : 'http://')
                        : ($m[4][0] == '//' ? 'http:' : '')).
                        // encode URLs (`&amp;`)
                        preg_replace ('/&(?!amp;)/i', '&amp;', $m[3][0])
                    .'"'.
                        // `rel` attribute
                        (($rel = (
                            // if e-mail address, no rel
                            isset($m[6][0]) ? ''
                            // construct possible rel values:
                            : trim (
                                // no-follow URL
                                ($m[2][0] ? 'nofollow ' : '').
                                // absolute URL
                                (!$m[1][0] || $m[4][0] ? 'external' : '')
                            )
                        )) ? " rel=\"$rel\"" : '').
                        // mime type, if linking directly to a common file
                        ($link ? " type=\"$link\"" : '').
                        // title?
                        (isset($m[8][0]) ? " title={$m[8][0]}" : '').
                    '>'.
                        // link text: either the description, or the friendly URL
                        (strlen($m[1][0]) ? $m[1][0] : $m[5][0]).
                    '</a>',
                    
                $m[0][1], strlen ($m[0][0]));
                // return to the `while` and find the next hyperlink. this is
                // done so as to not replace the whole hyperlink, inner text
                // and all, with a placeholder. the next case will remove all
                // HTML tags left behind
                continue 2;
            
            // we don’t use “*” as the tag name,
            // we set it according to the tag being removed
            case '*':
                //--------------------------------------------------------------
                // get the placeholder name
                $type = strtoupper ($m[2][0]);
            
            default:
                $text = $m[0][0];
        }
        
        // capture the element
        $placeholders[$type][] = $text;
        // replace with placeholder tag
        $source_text = substr_replace ($source_text,
            // make the placeholder tag the same size as
            // the content being replaced, for word wrapping to work
            "¡$type".str_repeat ('%', max (0, strlen ($text) - (strlen ($type) + 2))).'!',
            $m[0][1], strlen ($m[0][0])
        );
        // continue searching from after this placeholder
        $offset = $m[0][1] + strlen ($m[0][0]);
    }
    
    // encode essential HTML entities not already encoded (`&`, `<`, `>`).
    // we do not double encode, so that entities that you’ve already written
    // in the source text are kept
    $source_text = htmlspecialchars ($source_text, ENT_NOQUOTES, 'UTF-8', false);
    
    
    // [2] auto-correction: replace some ASCII conventions with unicode / HTML
    //==========================================================================
    foreach (array (
        '/(?<!-)--(?!-+)/'          => '—',                 // em-dash: “--”
        '/ -to- /'                  => '–',                 // en-dash “1 -to- 11”
        '/\(C\)/i'                  => '©',                 // copyright: “(C)”
        '/\(R\)/'                   => '®',                 // all rights reserved: “(R)”
        '/\^tm/i'                   => '™',                 // trademark: “^tm”
        '/(?<!\.)\.{3}(?!\.)/'      => '…',                 // ellipses
        '/(\d)(st|nd|rd|th)/'       => '$1<sup>$2</sup>',   // ordinals
        '/([\w])?\^([\w]+)/u'       => '$1<sup>$2</sup>',   // superscript
        '/([\d ])x( ?\d+)?\b/'      => '$1×$2',             // multiplication
        '/ 1\/2/'                   => '½',                 // fractions 1/2
        '/ 1\/4/'                   => '¼',                 // fractions 1/4
        '/ 3\/4/'                   => '¾',                 // fractions 3/4
        '/(\d)\'/'                  => '$1′',               // prime: 5′ (ft)
        '/([\d½¼¾])"/u'             => '$1″',               // double prime: 15″ (in)
        '/(\B)\'(.*?)\'(\B)/'       => '$1‘$2’$3',          // smart single quotes: ‘ ’
        '/(\B)"(.*?)"(\B)/'         => '$1“$2”$3',          // smart double quotes: “ ”
        '/(\B(?:\w+)?)\'(\w+\b)/'   => '$1’$2',             // apostrophes
        '/\+\/-/'                   => '±',                 // plus-minus: “+/-” -> "±"
        '/:therefore:|:ergo:/'      => '∴'                  // “:therefore:”
        
    ) as $regx => $replace) $source_text = preg_replace ($regx, $replace, $source_text);
    
    
    // [3] process inline markup
    //==========================================================================
    foreach (array (
        // <br />
        //----------------------------------------------------------------------
        // e.g.
        //     The quick brown fox _
        //     jumps over¬the lazy dog
        //
        '/¬| _(?=$)/m' => "<br$x>",

        // <hr />
        //----------------------------------------------------------------------
        // i.e.
        //     * * *
        //
        '/^\s*\* \* \*$/m' => "\n<hr$x>",
        
        // <em> / <strong>
        //----------------------------------------------------------------------
        // e.g.
        //     I’m _emphasising_ this point *strongly*.
        //
        '/(?:^|\b)_(?!\s)(.+?)(?!\s)_(?:\b|$)/' => '<em>$1</em>',
        '/\*(?!\s)(.+?)(?!\s)\*(?!\*)/' => '<strong>$1</strong>',
        
        // <del> / <ins>
        //----------------------------------------------------------------------
        // e.g.
        //     ---This statement is false--- [This statement is true].
        //
        '/---(?!-+)(.+?)(?<!-)---/' => '<del>$1</del>',
        '/\[(.+?)\]/' => '<ins>$1</ins>',
        
        // <cite>
        //----------------------------------------------------------------------
        // e.g.
        //     I’ve finished reading ~The Lion, the Witch and the Wardrobe~.
        //
        '/~(?!\s)(.+?)(?<!\s)~/' => '<cite>$1</cite>',
        
        // <q>
        //----------------------------------------------------------------------
        // e.g.
        //     He said «turn left here», but she said <<no, it’s definitely right>>.
        //
        '/(?:(\xAB)|(?:&lt;){2})(.*?)(?(1)\xBB|(?:&gt;){2})/u' => '<q>$2</q>',
        
        // <small>
        //----------------------------------------------------------------------
        // e.g.
        //     ((legalese goes here))
        //
        '/\({2}(.*?)\){2}(?!\))/s' => '<small>$1</small>',
        
    ) as $regx => $replace) $source_text = preg_replace ($regx, $replace, $source_text);
    
    $source_text = preg_replace_callback_array (array (
        // <dfn>
        //----------------------------------------------------------------------
        // e.g.
        //     I made some {{ASCII|American Standard Code for Information Interchange}} art.
        //
        '/\{\{([^|]+?)(?:\|([^}]+))?\}\}/' => function($m){
            return "<dfn".($m[2] ? " title=\"{$m[2]}\"" : '').">{$m[1]}</dfn>";
        },
        
        // <abbr>
        //----------------------------------------------------------------------
        // e.g.
        //     Red {vs.|versus} Blue.       (with title)
        //     Use {RAID} for redundancy.   (without title)
        //
        '/\{([^|}]+)(?:\|([^}]+))?\}/' => function($m){
            return "<abbr".(@$m[2] ? " title=\"{$m[2]}\"" : '').">{$m[1]}</abbr>";
        },
        
    ), $source_text);
    
    
    // [4] headings
    //==========================================================================
    while (preg_match (
        // e.g.
        //     ### title ### (#id)      atx-style, `# h1 #`, `## h2 ##`…, id is optional
        // or-
        //     Title (#id)              H2, id is optional
        //     ===========
        //     Title (#id)              H3, id is optional
        //     -----------
        //
        '/^(#{1,6})?(?(1) )(.*?)(?(1) \1)(?: \(#([0-9a-z_-]+)\))?(?(1)|\n([=-]+))(?:\n|$)/mi',
        $source_text, $m1, PREG_OFFSET_CAPTURE
    )) {
        // detect heading level (number of #’s or ‘=’ bar for H2 / ‘-’ bar for H3)
        $h = strlen ($m1[1][0]) ? strlen ($m1[1][0]) : (substr ($m1[4][0], 0, 1) == '=' ? 2 : 3);
        $title = &$m1[2][0]; $hid = &$m1[3][0];
        
        // title case the heading:
        //----------------------------------------------------------------------
        // original Title Case script © John Gruber <daringfireball.net>
        // javascript port © David Gouch <individed.com>
        
        // remove HTML, storing it for later
        //       placeholders    | tags     | entities
        $regx = '/\xA1[@#A-Z]+%*!|<\/?[^>]+>|&\S+;/u';
        preg_match_all ($regx, $title, $html, PREG_OFFSET_CAPTURE);
        $title = preg_replace ($regx, '', $title);
        
        // find each word (including punctuation attached)
        preg_match_all ('/[\w\p{L}&`\'‘’"“\.@:\/\{\(\[<>_]+-? */u', $title, $m2, PREG_OFFSET_CAPTURE);
        foreach ($m2[0] as $m3) {
            // shorthand these- "match" and "index"
            list ($m, $i) = $m3;
            
            // correct offsets for multi-byte characters (`PREG_OFFSET_CAPTURE` returns
            // *byte*-offset). we fix this by recounting the text before the offset using
            // multi-byte aware `strlen`
            $i = mb_strlen (substr ($title, 0, $i), 'UTF-8');
            
            // find words that should always be lowercase…
            // (never on the first word, and never if preceded by a colon)
            $m = $i>0 && mb_substr ($title, max (0, $i-2), 1, 'UTF-8') !== ':' && 
                !preg_match ('/[\x{2014}\x{2013}] ?/u', mb_substr ($title, max (0, $i-2), 2, 'UTF-8')) &&
                 preg_match ('/^(a(nd?|s|t)?|b(ut|y)|en|for|i[fn]|o[fnr]|t(he|o)|vs?\.?|via)[ \-]/i', $m)
            ?   // …and convert them to lowercase
                mb_strtolower ($m, 'UTF-8')
                
            // else: brackets and other wrappers
            : ( preg_match ('/[\'"_{(\[‘“]/u', mb_substr ($title, max (0, $i-1), 3, 'UTF-8'))
            ?   // convert first letter within wrapper to uppercase
                mb_substr ($m, 0, 1, 'UTF-8')
                .mb_strtoupper (mb_substr ($m, 1, 1, 'UTF-8'), 'UTF-8')
                .mb_substr ($m, 2, mb_strlen ($m, 'UTF-8')-2, 'UTF-8')
                
            // else: do not uppercase these cases
            : ( preg_match ('/[\])}]/', mb_substr ($title, max (0, $i-1), 3, 'UTF-8')) ||
                preg_match ('/[A-Z]+|&|\w+[._]\w+/u', mb_substr ($m, 1, mb_strlen ($m, 'UTF-8')-1, 'UTF-8'))
            ?   $m
                // if all else fails, then no more fringe-cases; uppercase the word
            :   mb_strtoupper (mb_substr ($m, 0, 1, 'UTF-8'), 'UTF-8')
                .mb_substr ($m, 1, mb_strlen ($m, 'UTF-8'), 'UTF-8')
            ));
            
            // resplice the title with the change
            // (`substr_replace` is not multi-byte aware)
            $title = mb_substr ($title, 0, $i, 'UTF-8').$m
                 .mb_substr ($title, $i+mb_strlen ($m, 'UTF-8'), mb_strlen ($title, 'UTF-8'), 'UTF-8')
            ;
        }
        // restore the HTML
        foreach ($html[0] as &$tag) $title = substr_replace ($title, $tag[0], $tag[1], 0);
        
        // replace heading with HTML
        $source_text = substr_replace ($source_text,
            "<h$h".($hid ? " id=\"$hid\"" : '').">$title</h$h>\n\n",
            $m1[0][1], strlen ($m1[0][0])
        );
    }
    
    
    // [5] blocks - lists / blockquotes
    //==========================================================================
    // see documentation (or read regex) for full list of supported bullet types.
    // note that this has capturing groups
    $bullet = '(?:([\x{2022}*+-])|(?-i:[a-z]\.|[ivxlcdm]+\.|#|(?:\d+\.){1,6}))(?:[ ]\(#([0-9a-z_-]+)\))?';
    
    // capture, convert and unindent lists and blockquotes, recursively:
    // (I hope you’re fluent in regex)
    do $source_text = preg_replace_callback_array (array (
        // «whitespace»
        //----------------------------------------------------------------------
        // remove white space on empty lines;
        // simplifies regexes dealing with multiple lines
        '/^\s+\n/m' => function(){return "\n";},
        
        // <blockquote>
        //----------------------------------------------------------------------
        // e.g.
        //     |    blockquote text
        //
        '/^(?:\|\ (.*)\n)?((?:\|(?:\t.*)?\n)+)(?:\|\ (.*)\n)?\n/m' => function($m){
            return
                "\n<blockquote>\n".($m[1] ? "<cite>{$m[1]}</cite>\n" : '')
                ."\n".preg_replace("/^\|\\t?/m", '', "{$m[2]}\n")
                .(isset($m[3]) ? "<cite>{$m[3]}</cite>\n" : '')."</blockquote>\n\n";
        },

        // <ul> / <ol>
        //----------------------------------------------------------------------
        // i.e. a number of li’s, see below
        //
        "/^(?:{$bullet}(?:\\t+.*\\n{1,2})+)+/mu" => function($m){
            return
                "\n<".(isset($m[1]) ? 'u' : 'o')."l>\n\n"
                .trim($m[0])."\n\n</".(isset($m[1]) ? 'u' : 'o')."l>\n\n";
        },

        // <li>
        //----------------------------------------------------------------------
        // e.g.
        //     •    text
        //
        "/(?:(?<=(?<!<[uo]l>)(\\n\\n)))?^{$bullet}((?:\\t+.*(\\n))+|(?:\\t+.*(?:\\n|(\\n\\n)))+)(?={$bullet}|\\n<\/[uo]l>)/mu" => function($m){
            return 
                '<li'.(strlen($m[3]) ? " id=\"{$m[3]}\"" : '').'>'
                .$m[1].$m[5].@$m[6]
                .preg_replace("/^\\t/m", '', trim($m[4]))
                .$m[1].$m[5].@$m[6]
                ."</li>\n\n";
        },

        // <dl>
        //----------------------------------------------------------------------
        //
        '/^(:: .*\n{1,2}(?:(?:\t+.*\n{1,2})+)?)+/m' => function($m){
            return "<dl>\n\n".trim($m[0])."\n</dl>\n\n";
        },
        
        // <dt> / <dd>
        //----------------------------------------------------------------------
        // e.g.
        //     :: definition term
        //         description…
        //
        '/^:: (?:\(#([0-9a-z_-]+)\))?(.*)\n{0,2}((?:\t+.*\n)+|(?:\t+.*(?:\n|(\n)\n)?)+)?\n(?=\n::|<\/dl>)/m' => function($m){
            return
                '<dt'.(isset($m[1]) ? " id=\"{$m[1]}\"" : '').">{$m[2]}</dt>\n\n"
                .(isset($m[3])
                    ?   "<dd>\n".@$m[4].preg_replace("/^\\t/m", '', $m[3]).@$m[4]."\n</dd>\n\n"
                    :   ''
                );
        },

        // <figure>
        //----------------------------------------------------------------------
        '/^fig.(?:\t+: ((?:.+\n)+)\n)?((?:\t+(?!:).*\n+)+)(?:\t+: ((?:.+\n)+))?\n/m' => function($m){
            return
                "<figure>\n"
                .(isset($m[1]) ? '<figcaption>'.trim($m[1])."</figcaption>\n" : '')."\n"
                .preg_replace("/^\\t/m", '', $m[2])
                .(isset($m[3]) ? '<figcaption>'.trim($m[3])."</figcaption>\n" : "\n")
                ."</figure>\n\n";
        },

        // linked block images `^<a><img /></a>$`
        //----------------------------------------------------------------------
        // this handles the special case where the only thing on a line is a hyperlinked image,
        // this should be split across three lines, and later *not* wrapped in a paragraph
        '/^(\xA1A%*!)(\xA1IMG%*!)(\xA1A%!)$/mu' => function($m){
            return "{$m[1]}\n{$m[2]}\n{$m[3]}";
        }
        
    ), $source_text, -1, $continue);
    // because a list can contain another list / blockquote,
    // once one is converted we loop again to catch the next level
    while ($continue);
    
    
    // [6] indent and word-wrap:
    //==========================================================================
    // start indenting at the base level for the whole document
    $depth = $indent;
    
    // the regex section above places blank lines either side of paragraphs in
    // lists and either side of any tag that begins / ends an indent. this
    // section steps through these blank lines assessing the content inbetween:
    foreach (preg_split ('/\n{2,}/', $source_text, -1, PREG_SPLIT_NO_EMPTY) as $chunk) {
        // indent according to the current level
        if ($depth) $chunk = preg_replace ('/^/m', str_repeat ("\t", $depth), $chunk);
        
        // check each condition…
        foreach (array (
            // PRE blocks (will always have no indent
            // regardless if they are inside an indented block)
            'pre'   => '/^\s*(\xA1PRE%*!)/u',
            // list item without paragraphs
            'li'    => '/^(\s*)(<li[^>]*>)\n\1(?P<p>.*)\n\1<\/li>/s',
            // `<dd>` without any paragraphs
            'dd'    => '/^(\s*)<dd>\n(?P<p>(?:\t+.*\n?)+)\1<\/dd>/m',
            // opening indent
            'open'  => '/(.*?)^(\s*)<((?:[uo]l|li|d[ld]|blockquote|figure)[^>]*)>$(?P<p>.*)/ms',
            // closing indent
            'close' => '/(.*?)^(\s*?)\t<(\/)([uo]l|li|d[ld]|blockquote|figure)>$(?P<p>.*)/ms',
            // block level elements that should not be wrapped in P tags
            'p' => '/^\s*(?:<\/?|(\xA1))(?:
                # tags alone on the line that should not be wrapped
                (a|img)
            |   # elements that start a line that should not be wrapped
                (?:article|aside|audio|blockquote|canvas|caption|col|colgroup|dialog|div|d[ltd]|embed
                  |fieldset|figure|figcaption|footer|form|h[1-6r]|header|hgroup|iframe|input|label|legend
                  |li|nav|noscript|object|[ou]l|optgroup|option|p|param|pre|script|section|select|source
                  |table|t(?:body|foot|head)|t[dhr]|textarea|video
                # don’t wrap HTML comments or TOC markers
                  |\#
                )
            )(?(1)%*!(?:.*?\1\2%!)?|[^>]*>)(?(2)(?:$|\n))/xui'
            
        ) as $tag => $regx) if (
            // once a match is found, capture the regex
            // results in `$m` and stop searching
            preg_match ($regx, $chunk, $m)
        ) break;
        
        // note: ReMarkable does not wrap paragraphs around block elements.
        // the “p” condition therefore works in reverse and we know that an
        // actual paragraph is matched when the regex doesn’t match and drops
        // out of the list of conditions -- leaving `$m` as empty
        
        // the “li”, “dd” and not-“p” conditions contain a paragraph of text
        // that has to be word-wrapped. this text is stored in the regex named
        // capture group “p” -> `$m['p']`. if no match is made `(!$m)` then
        // the whole chunk is a paragraph to be wrapped
        $p = rtrim (empty($m) ? $chunk : (string) @$m['p']);
        
        // as explained above, word-wrap these conditions:
        if (($tag == 'li' || $tag == 'dd' || !$m) && $margin>0) {
            // collapse whitespace in paragraphs. this removes HTML newlines
            // (except before or after a `<br />`) so that the paragraph can be
            // wrapped cleanly by ReMarkable
            $p = rtrim (preg_replace ('/(?<!<br \/>|<br>)\n\t*+(?!<br \/>|<br>)/', ' ', $p));
            // after a break, remove any double/triple indent
            // sometimes required by LIs with IDs
            $p = preg_replace ('/<br(?: \/)?>\n\t*+/', "<br$x>\n".str_repeat ("\t", $depth), $p);
            
            // word-wrap:
            // calculate the current loss of margin due to the indent level
            $width = $margin - (8 * ($depth+1));
            // keep finding oversized lines until none are left…
            do $p = preg_replace (
                // find i. any line that’s longer than the margin cut-off point
                //     ii. the last space before the margin,
                //         excluding within an HTML tag -or-
                //    iii. the first space after the margin
                //         (for lines with long URLs for example)
                //     iv. the first character after “>”
                //         (where a tag covers the cut-off point)
                '/^(?=.{'.($width+1).",})(.{1,{$width}}|.{{$width},}?) (?![^<]*?>)/m",
                // and chop
                "$1\n".str_repeat ("\t", $depth), $p, -1, $continue
            );
            while ($continue);
        }
        
        // reconstruct the chunk
        switch ($tag) {
            case 'pre'  : $chunk = $m[1];                                                                 break;
            case 'li'   : $chunk = "{$m[1]}{$m[2]}$p</li>";                                               break;
            case 'dd'   : $chunk = "{$m[1]}<dd>\n".preg_replace ('/^/m', "\t", "{$p}\n")."{$m[1]}</dd>";  break;
            case 'open' : $chunk = "{$m[1]}{$m[2]}<{$m[3]}>".preg_replace ('/\n/', "\n\t", $p); $depth++; break;
            case 'close': $chunk = "{$m[1]}{$m[2]}<{$m[3]}{$m[4]}>{$p}";                        $depth--; break;
            default:
                // wrap paragraph
                if (!$m) $chunk = str_repeat ("\t", $depth)."<p>\n"
                    .preg_replace ('/^/m', "\t", $p)."\n"
                    .str_repeat ("\t", $depth).'</p>'
                ;
        }
        $source_text = @$result .= "\n$chunk";
    };
    
    // [7] finalise:
    //==========================================================================
    // tidy up the HTML
    foreach (array (
        // indent block level linked images `<a><img /></a>`
        '/^(\t*)(<li[^>]*>)?(\xA1A%*!)\n\1(\xA1IMG%*!)\n\1(\xA1A%!)/mu' => "$1$2$3\n\t$1$4\n$1$5",
        // pair `<p>` tags together
        '/<\/p>\n\t*<p>/' => '</p><p>',
        // flatten a single line paragraph in a `<li>` -> `<li><p>...</p></li>`
        '/(<li[^>]*>)\n(\t*)\t<p>\n\t+(.*)\n\t+<\/p>\n\t+<\/li>/' => "$1\n$2\t<p>$3</p>\n$2</li>",
        // pair `<li>` tags together (except single-line ones)
        '/\n(\t*)<\/li>\n\t*(<li[^>]*>)\n/' => "\n$1</li>$2\n",
        // add double blank lines above H2,3
        // (easier to see headings when scrolling)
        '/^(\t*)(<h[23][^>]*>.*)$/m' => "$1\n$1\n$1$2",
        // but not when one immediately proceeds another
        '/(<\/h[23]>)(?:\n(\t*)){3}(<h[23][^>]*>)/' => "$1\n$2$3",
        // blank line either side of `<hr />`
        '/^(\t*)<hr(?: \/)?>/ms' => "$1\n$0\n$1",
        // blank line either side of PRE blocks
        // (have no indent themselves, so it has to be borrowed)
        '/^\xA1PRE%*!\n(\t*)/mu' => "$1\n$1$0\n$1",
        // remove tripple blank lines caused by combinations of the above
        '/^(?:(\t*)\n){3}/m' => "$1\n$1\n"
        
    ) as $regx => $replace) $source_text = preg_replace ($regx, $replace, $source_text);
    
    // restore placeholders:
    //--------------------------------------------------------------------------
    // restore in reverse order so that pre and code spans
    // that contain placeholders [documentation] don’t conflict
    //
    $placeholders = array_reverse ($placeholders, true);
    // restore each saved chunk of HTML, for each type of tag
    foreach ($placeholders as $tag => &$tags) foreach ($tags as &$html) if (
        preg_match ("/\\xA1$tag%*!/u", $source_text, $m, PREG_OFFSET_CAPTURE)
    ) $source_text = substr_replace ($source_text, $html, $m[0][1], strlen ($m[0][0]));
    
    // auto table of contents:
    //--------------------------------------------------------------------------
    // creates a table of contents from headings with IDs. this has to be done
    // last because `<code>` spans in headings would be duplicated in the TOC
    // and the HTML would not be restored correctly above. the offset is
    // captured so that only headings *after* the TOC marker are included
    // in the table of contents
    //
    if (preg_match ('/^(\t*)&__TOC__;/m', $source_text, $i, PREG_OFFSET_CAPTURE)) {
        preg_match_all ('/<h([2-6]) id="([0-9a-z_-]+)">(.*?)<\/h\1>/i', $source_text, $h, PREG_SET_ORDER, $i[0][1]);
        // the simplest way to create a nested list is to let ReMarkable do it!
        foreach ($h as &$m) @$toc .= str_repeat ("\t", (int) $m[1]-2)."#\t<a href=\"#{$m[2]}\">{$m[3]}</a>\n";
        $source_text = str_replace ('&__TOC__;', remarkable ($toc, strlen ($i[1][0]), $margin), $source_text);
    }
    
    // apply tab output preference
    if (
        $options && (REMARKABLE_TABSPACE_2 || REMARKABLE_TABSPACE_4)
    ) $source_text = preg_replace_callback ('/^(\t+)/m', function($m) use ($options){
        //8, 4, or 2 spaces?
        return str_repeat ((
            !($options xor (REMARKABLE_TABSPACE_2 || REMARKABLE_TABSPACE_4))
            ? '        ' : ($options && REMARKABLE_TABSPACE_4 ? '    ' : '  ')
        ), strlen($m[1]));
    }, $source_text);
    
    // a trailing line break is never given so that
    // ReMarkable can be used for short inline strings in your HTML
    return trim ($source_text, "\n");
}
?>