#!/usr/bin/php
<?PHP

error_reporting(E_ERROR|E_CORE_ERROR|E_ALL|E_COMPILE_ERROR);
ini_set('display_errors', 'On');
ini_set('memory_limit','500M');

require_once ( '/data/project/wikivibes/scripts/wikivibes.php' ) ;


$wv = new WikiVibes;

if ( isset($argv[1]) and $argv[1]=='reset' ) {
	$wv->reset_all();
}

if ( isset($argv[1]) and $argv[1]=='json' ) {
	$wv->generate_main_page_data();
} else if ( isset($argv[1]) and $argv[1]=='person' ) {
	$wv->update_persons();
} else if ( isset($argv[1]) and $argv[1]=='sec_labels' ) {
	$wv->import_missing_section_labels();
} else if ( isset($argv[1]) and $argv[1]=='whitelist' ) {
	$wv->import_movie_whitelist();
} else {
	$wv->update_from_sparql();
	$wv->make_rc_unavailable();
	// $wv->import_audio_whitelist();
	$wv->add_missing_audio_details();	
	$wv->update_persons();
	$wv->import_missing_section_labels();
	$wv->generate_main_page_data();
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