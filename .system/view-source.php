<?php
//==============================================================================
// camen design © copyright (c) Kroc Camen 2008-2022, BSD 2-clause
// (see LICENSE.TXT). bugs / suggestions → kroc@camendesign.com
//
declare(strict_types=1);

// view-source.php : where we let people see what is going on under the hood

/* ====================================================================================================================== */
//PHP view-source: whilst the .htaccess should show the PHP as text/plain (and does on my localhost), my live server is
//running PHP as a CGI-module and as such the .htaccess rules don’t work. here we simply print the requested code to screen

include_once 'shared.php';

//this is also to work around PHP as CGI
ini_set ("highlight.comment", "#008000");
ini_set ("highlight.default", "#0000FF");
ini_set ("highlight.keyword", "#3C4C72");
ini_set ("highlight.string",  "#000080");

$requested = (
    preg_match (
        '/^\.htaccess|(?:\.(?!\.)|\/(?!_)|[-a-z0-9_])+\.(php|rem|sh)$/i',
        //trying to view self?
        @$_GET['file'] ? $_GET['file'] : '/.system/view-source.php', $_
    ) ? $_[0] : false
) or errorPage (
    'malformed_request.rem', 'Error: Malformed Request', ['URL' => '?file=path/to/script.php']
);

switch ($_[1]) {
    case 'php': $html = str_replace (
        //retain _real_ tabs. 8 spaces *is not* the same as a tab-stop, grrr
        //pretty print using PHP’s built in syntax highlighter
        '§'.'§', "\t", highlight_string (
            trim (str_replace ("\t", '§'.'§', file_get_contents (APP_ROOT.$requested))), true
        )
    ); break;
    
    case 'rem': //find the permalink location
    $article = pathinfo ($requested, PATHINFO_FILENAME);
    $index_array = getIndex ();
    $index = @reset (preg_grep ("/\|$article$/", $index_array));
    if ($index) {
        $type = @reset (array_slice (explode ('|', $index), 1, 1));
        if ($requested != "$type/$article.rem") {
            header ('Location: http://'.APP_HOST."/$type/$article.rem", true, 301);
            die ();
        }
    }
    
    default:
    $html = htmlspecialchars (file_get_contents (APP_ROOT.$requested), ENT_NOQUOTES, 'UTF-8');
}

die (template_tags (template_load ('view-source.html'), [
    'TITLE'  => pathinfo ($requested, PATHINFO_BASENAME),
    'HEADER' => @$_[1] == 'rem' ? template_load ('view-source.rem.html') : '',
    'CODE'   => $html
]));

?>