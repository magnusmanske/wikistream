#!/usr/bin/php
<?PHP

error_reporting(E_ERROR|E_CORE_ERROR|E_ALL|E_COMPILE_ERROR);
ini_set('display_errors', 'On');
ini_set('memory_limit','1500M');

require_once ( __DIR__.'/wikistream.php' ) ;

$config = (new WikiStreamConfig)->get_config_instance();
$ws = new WikiStream($config);

$cmd = $argv[1] ?? '';

if ( $cmd === 'reset' ) {
	$ws->reset_all();
}

match ($cmd) {
	'json'                      => $ws->generate_main_page_data(),
	'json2'                     => $ws->generate_all_data(),
	'test'                      => print_r($ws->config),
	'person'                    => $ws->update_persons(),
	'sec_labels'                => $ws->import_missing_section_labels(),
	'purge_items_without_files' => $ws->purge_items_without_files(),
	'annotate_ia_movies'        => $ws->annotate_ia_movies(),
	'import_commons_video_minutes' => $ws->import_commons_video_minutes(),
	'update_item_no_files'      => $ws->update_item_no_files_search_results(),
	'reset'                     => null, // already handled above
	default                     => (function() use ($ws) {
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
		$ws->import_commons_video_minutes();
		$ws->generate_main_page_data();
		print "Main page data generated\n";

		# Update candidate items with no files
		$ws->update_item_no_files();
		$ws->update_item_no_files_search_results();

		# These edits will take too long to percolate into SPARQL to be useful now, so prepare for the next update
		$ws->annotate_ia_movies();

		# Might run out of memory so run this last
		$ws->generate_all_data();
	})(),
};

?>
