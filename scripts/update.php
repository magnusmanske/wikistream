#!/usr/bin/php
<?PHP

error_reporting(E_ERROR|E_CORE_ERROR|E_ALL|E_COMPILE_ERROR);
ini_set('display_errors', 'On');
ini_set('memory_limit','500M');

require_once ( __DIR__.'/wikistream.php' ) ;

$config = (new WikiStreamConfig)->get_config_instance();
$ws = new WikiStream($config);

if ( isset($argv[1]) and $argv[1]=='reset' ) {
	$ws->reset_all();
}

if ( isset($argv[1]) and $argv[1]=='json' ) {
	$ws->generate_main_page_data();
} else if ( isset($argv[1]) and $argv[1]=='test' ) {
	print_r($ws->config);
} else if ( isset($argv[1]) and $argv[1]=='person' ) {
	$ws->update_persons();
} else if ( isset($argv[1]) and $argv[1]=='sec_labels' ) {
	$ws->import_missing_section_labels();
} else if ( isset($argv[1]) and $argv[1]=='whitelist' ) {
	$ws->import_movie_whitelist();
} else {
	$ws->update_from_sparql();
	$ws->make_rc_unavailable();
	// $ws->import_item_whitelist();
	$ws->add_missing_item_details();	
	$ws->update_persons();
	$ws->import_missing_section_labels();
	$ws->generate_main_page_data();
}

/* NOTES

Commons iframe:
<iframe src="https://commons.wikimedia.org/wiki/File:Tess_of_the_Storm_Country_(1914).webm?embedplayer=yes" width="null" height="20" frameborder="0" webkitallowfullscreen="true" mozallowfullscreen="true" allowfullscreen></iframe>

Internet archive iframe:
<iframe src="https://archive.org/embed/peril_of_doc_ock" width="640" height="480" frameborder="0" webkitallowfullscreen="true" mozallowfullscreen="true" allowfullscreen></iframe>

Youtube iframe no cookies:
<iframe width="1440" height="762" 
src="https://www.youtube-nocookie.com/embed/7cjVj1ZyzyE" frameborder="0" allow="autoplay; encrypted-media" webkitallowfullscreen="true" mozallowfullscreen="true" allowfullscreen></iframe>

*/

?>