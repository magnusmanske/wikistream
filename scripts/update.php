#!/usr/bin/php
<?PHP

error_reporting(E_ERROR|E_CORE_ERROR|E_ALL|E_COMPILE_ERROR);
ini_set('display_errors', 'On');
ini_set('memory_limit','1500M');

require_once ( __DIR__.'/wikistream.php' ) ;
require_once ( __DIR__.'/Watchdog.php' ) ;

// Force-terminate a wedged run. Shared-library fetchers (public_html/php) set
// no curl timeout — a silently-stalled WDQS/Wikidata socket blocks forever,
// which is the "stuck for weeks" failure. We can't patch the shared lib here,
// so a forked watchdog SIGKILLs this process after a wall-clock deadline.
// Tune/disable via WIKISTREAM_UPDATE_TIMEOUT (seconds; 0 disables).
Watchdog::arm(Watchdog::resolveTimeout(getenv('WIKISTREAM_UPDATE_TIMEOUT')));

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
	'groups'                    => $ws->import_missing_groups(),
	'backfill_group_items'      => $ws->backfill_group_items(),
	'purge_items_without_files' => $ws->purge_items_without_files(),
	'purge_out_of_scope_items'  => $ws->purge_out_of_scope_items(),
	'annotate_ia_movies'        => $ws->annotate_ia_movies(),
	'annotate_pre_1900_public_domain' => $ws->annotate_pre_1900_public_domain(),
	'import_ia_curated_imdb_p724' => $ws->import_ia_curated_imdb_p724(),
	'import_commons_pd_films_via_p180' => $ws->import_commons_pd_films_via_p180(),
	'import_commons_video_minutes' => $ws->import_commons_video_minutes(),
	'import_p953_urls'          => $ws->import_p953_urls(),
	'import_ia_curated_films'   => $ws->import_ia_curated_films(),
	'update_item_no_files'      => $ws->update_item_no_files_search_results(),
	'reset'                     => null, // already handled above
	default                     => (function() use ($ws) {
		$ws->update_from_sparql();
		$ws->import_item_whitelist();
		$ws->import_item_blacklist();
		$ws->make_rc_unavailable();
		$ws->add_missing_item_details();
		$ws->clear_bad_genres();
		$ws->purge_out_of_scope_items();
		$ws->purge_items_without_files();
		$ws->remove_unused_people();
		$ws->update_persons();
		$ws->import_missing_groups();
		$ws->import_missing_section_labels();
		$ws->import_commons_video_minutes();
		$ws->generate_main_page_data();
		print "Main page data generated\n";

		# Update candidate items with no files
		$ws->update_item_no_files();
		$ws->update_item_no_files_search_results();

		# These edits will take too long to percolate into SPARQL to be useful now, so prepare for the next update
		$ws->annotate_ia_movies();
		$ws->annotate_pre_1900_public_domain();
		$ws->import_ia_curated_imdb_p724();
		$ws->import_commons_pd_films_via_p180();
		$ws->import_p953_urls();
		$ws->import_ia_curated_films();

		# Might run out of memory so run this last
		$ws->generate_all_data();
	})(),
};

?>
