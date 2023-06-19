<?php //where we redirect incoming links for version 0.1 of camendesign.com
/* ====================================================================================================================== */

if ($_GET) {
	//old 404
	if (array_key_exists ('404', $_GET)) redirect ('/404', 404);
	
	//view php
	if (array_key_exists ('this-php', $_GET)) redirect ('/.system/archive/0_1/');
	
	//viewing source?
	$html = array_key_exists ('this-html', $_GET);
	
	//if a content-entry id is given, all other tags must be ignored:
	if ($id = reset (preg_grep ("/^(20\d{10})$/", array_keys ($_GET)))) {
		$entries = array (
			//blog
			'200608242026' => 'blog/geos',
			'200610261710' => 'blog/digital-archaeology',
			'200612191339' => 'blog/are_we_being_served',
			'200703071820' => 'blog/a-r',
			'200708301303' => 'blog/competition_is_not_good',
			'200803052026' => 'blog/ie8-standards',
			'200805241404' => 'blog/when_its_better',
			'200806181021' => 'blog/hello',
			'200806291149' => 'blog/stop_writing_software',
			'200806291241' => 'blog/stop_writing_software',
			'200807050142' => 'blog/real-world_test',
			'200807131640' => 'blog/02-thoughts',
			'200807170951' => 'blog/these_things_i_believe',
			'200807191458' => 'blog/sentinel-returns_guide',
			'200808091233' => 'blog/minimalist_is_not_the_right_word',
			//art
			'200402291035' => 'art/clint_v_franklin',
			'200403071525' => 'art/tron-cubes',
			'200403081928' => 'art/tron-cubes-at-night',
			'200403131700' => 'art/dmai',
			'200404241716' => 'art/cute-and-evil',
			'200411151856' => 'art/ratchet-clank',
			'200508061756' => 'art/broom-of-pwnag3',
			'200511151934' => 'art/sam',
			'200608201713' => 'art/usual-suspects',
			'200611290140' => 'art/winters-wish',
			'200611290213' => 'art/back2back',
			'200612201653' => 'art/not-amused',
			'200703302347' => 'art/caralynne',
			'200803161726' => 'art/aol',
			'200808052239' => 'art/if-i-designed-engadget',
			//code
			'200707271949' => 'code/asp-templating',
			'200803020653' => 'code/cleanly-grouping-by',
			'200807051806' => 'code/uth1_is-png-32bit',
			'200807081323' => 'code/uth2_css3-hyperlinks',
			'200807101232' => 'code/uth3_sqlite',
			'200807111628' => 'code/html5-mathml-svg',
			'200807121257' => 'code/uth4_mime-type',
			//photo
			'200303081857' => 'photo/you-go-that-way',
			'200303082106' => 'photo/img0040',
			'200507051231' => 'photo/on-and-on',
			'200507061409' => 'photo/jag-xj220-1',
			'200507061410' => 'photo/jag-xj220-2',
			'200507061436' => 'photo/lotus-elise',
			'200705240910' => 'photo/busy',
			'200706030822' => 'photo/no-hands',
			'200706031620' => 'photo/always-forward',
			'200706051316' => 'photo/dsc00204',
			'200706141144' => 'photo/dsc00227',
			'200706141145' => 'photo/walk-the-walk',
			'200706141153' => 'photo/dsc00224',
			'200707161154' => 'photo/brighton-station',
			'200707221634' => 'photo/gull',
			'200707221645' => 'photo/at-an-angle',
			'200707271637' => 'photo/brighton-sky',
			'200710031851' => 'photo/dsc00360',
			'200710221339' => 'photo/picadilly-circus',
			'200801021306' => 'photo/muddy-4x4',
			'200801101339' => 'photo/entering_london-victoria',
			'200801101532' => 'photo/oxford-circus',
			'200801171003' => 'photo/modernisation',
			'200801241946' => 'photo/railway-stairs',
			'200802151058' => 'photo/dsc00547',
			'200804060859' => 'photo/dsc00611',
			'200804060910' => 'photo/silver-lining',
			'200804060925' => 'photo/dsc00641',
			'200804060926' => 'photo/dsc00642',
			'200804261534' => 'photo/dsc00676',
			'200807311234' => 'photo/sunflower',
			'200808021021' => 'photo/dsc00734',
			//poem
			'200505091048' => 'poem/milk_two-sugars',
			'200607062204' => 'poem/marilyn',
			'200712080148' => 'poem/in-the-way',
			'200801170942' => 'poem/some-advice',
			'200803252058' => 'poem/is-it',
			'200808031126' => 'poem/what-friends-need',
			//tweet
			'200801161127' => 'quote/dev-random_twitter',
			'200801170843' => 'quote/brands',
			'200801191141' => 'quote/digg',
			'200801191606' => 'quote/apostrophe',
			'200801212135' => 'quote/queen-jam',
			'200801212316' => 'quote/mozilla_apple',
			'200801220836' => 'quote/music-folder',
			'200801221054' => 'quote/carbon-offsetting',
			'200801281728' => 'quote/nationalism',
			'200801311954' => 'quote/dont-drive',
			'200802021547' => 'quote/internet-is-dumb',
			'200802030900' => 'quote/vista-dhcp',
			'200802101741' => 'quote/php-lies',
			'200802170814' => 'quote/explosive-compounds',
			'200802171207' => 'quote/paint-in-hex-plz',
			'200802210725' => 'quote/microsft_ala',
			'200803271733' => 'quote/dont-rush',
			'200804011850' => 'quote/april-fools-day',
			'200804040952' => 'quote/tcp-ip',
			'200804050856' => 'quote/mac-haters',
			'200804130826' => 'quote/super-mario-galaxy',
			'200804131122' => 'quote/spyro',
			'200804162157' => 'quote/mariokart',
			'200804211208' => 'quote/bbq-hula-hoops',
			'200804211714' => 'quote/save-money',
			'200804220832' => 'quote/code-is-art',
			'200804251111' => 'quote/microsft_olpc',
			'200805031701' => 'quote/ubuntu_heron',
			'200805080752' => 'quote/cheaper',
			'200805081020' => 'quote/spam',
			'200805100802' => 'quote/spore-drm',
			'200805160938' => 'quote/dont_make_me_choose',
			'200805160939' => 'quote/technology-hurdle',
			'200805181050' => 'quote/programming-channel',
			'200805181607' => 'quote/ready',
			'200805181754' => 'quote/unhinged-creativity',
			'200805201650' => 'quote/wifi-wifi-wifi-wifi',
			'200805202231' => 'quote/do-without',
			'200805211752' => 'quote/ui_aging',
			'200805221345' => 'quote/films-are-idiots',
			'200805232102' => 'quote/app-store_webapp',
			'200805271823' => 'quote/here_comes_the_science_bit',
			'200805291052' => 'quote/flash_html5-video-plz',
			'200806050924' => 'quote/no_more_windows-bashing',
			'200806050955' => 'quote/obsolete_innovate',
			'200806060750' => 'quote/monkey-gun',
			'200806071429' => 'quote/silverlight-weeds',
			'200806131547' => 'quote/kitchen-drm',
			'200806202134' => 'quote/apple-uk-localisation',
			'200807012201' => 'quote/crowd-spam',
			'200807031831' => 'quote/do-not-want-acrobat9',
			'200807040849' => 'quote/hate-mail-is-great',
			'200807290758' => 'quote/ogg-theora_apple',
			'200807291036' => 'quote/browser-usage-july-08',
			'200807291058' => 'quote/ie-move-on',
			'200807311347' => 'quote/legal-moral'
		);
		redirect (
			'/'.(isset ($entries[$id]) ? $entries[$id].($html ? '.html' : '') : '404'),
			isset ($entries[$id]) ? 301 : 404
		);
	}
	
	//find the tag to filter by "/?tag"
	$filter = reset (array_diff (
		preg_grep ('/^[a-zA-Z0-9-]{1,20}$/', array_keys ($_GET)),
		//ignore these querystring items used for other purposes (`array_diff`)
		explode ('|', 'page|rss|this-html')
	));
	
	//if "?rss" redirect to the rss feed and end here
	if (array_key_exists ("rss", $_GET)) redirect ('/'.($filter ? "$filter/" : '').'rss');
	
	//redirect to an index page (page numbers are no longer used, and ignored)
	redirect ('/'.($filter ? "$filter/" : '').($html ? 'latest.html' : ''));
}
//if no querystring, go home ("camendesign.com/index.php" => "camendesign.com/")
redirect ("/");


function redirect ($s_url, $i_http_code=301) {
	header (' ', true, $i_http_code);
	header ("location: $s_url");
	exit ();
}

?>