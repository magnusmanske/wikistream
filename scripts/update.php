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
} else if ( isset($argv[1]) and $argv[1]=='purge_items_without_files' ) {
	$ws->purge_items_without_files();
} else if ( isset($argv[1]) and $argv[1]=='whitelist' ) {
	$ws->import_movie_whitelist();
} else {
	$ws->update_from_sparql();
	$ws->make_rc_unavailable();
	$ws->import_item_whitelist();
	$ws->purge_items_without_files();
	$ws->add_missing_item_details();	
	$ws->update_persons();
	$ws->import_missing_section_labels();
	$ws->generate_main_page_data();
}

?>