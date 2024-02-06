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
} else if ( isset($argv[1]) and $argv[1]=='annotate_ia_movies' ) {
	$ws->annotate_ia_movies();
} else if ( isset($argv[1]) and $argv[1]=='update_item_no_files' ) {
	$ws->update_item_no_files();
	$ws->update_item_no_files_search_results();
} else {
	$ws->update_from_sparql();
	$ws->import_item_whitelist();
	$ws->import_item_blacklist();
	$ws->make_rc_unavailable();
	$ws->add_missing_item_details();
	$ws->clear_bad_genres();
	$ws->purge_items_without_files();
	$ws->remove_unused_people();
	$ws->update_persons();
	$ws->import_missing_section_labels();
	$ws->generate_main_page_data();
	print "Main page data generated\n";

	# Update candidate items with no files
	$ws->update_item_no_files();
	$ws->update_item_no_files_search_results();

	# These edits will take too long to percolate into SPARQL to be useful now, so prepare for the next update
	$ws->annotate_ia_movies();

}

?>