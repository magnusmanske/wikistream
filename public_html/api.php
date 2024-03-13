<?PHP

error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR|E_ALL);
ini_set('display_errors', 'On');

require_once ( __DIR__.'/../scripts/wikistream.php' ) ;

$config = (new WikiStreamConfig)->get_config_instance();
$ws = new WikiStream($config);

$action = $ws->tfc->getRequest('action','');
$ws->language = preg_replace('|[^a-z-]|','',$ws->tfc->getRequest('language','en'));


$out = [ 'status'=>'OK' ];

if ( $action=='get_entry' ) {
	$q = preg_replace('|\D|','',$ws->tfc->getRequest('q',0))*1;
	$out['data'] = $ws->getEntry($q);
} else if ( $action=='get_all_sections' ) {
	$out['data'] = $ws->get_top_sections(PHP_INT_MAX);
} else if ( $action=='get_section' ) {
	# ATTENTION always assumes property is set
	$max = $ws->tfc->getRequest('max',25);
	if ($max=='all') $max = PHP_INT_MAX;
	else $max = $max*1;
	$section = (object) [
		'section_q' => $ws->tfc->getRequest('q',0)*1,
		'property' => $ws->tfc->getRequest('prop',0)*1,
	];
	$wil = new WikidataItemList();
	$wil->loadItems([$section->section_q]);
	$item = $wil->getItem($section->section_q);
	if ( isset($item) ) $out['data'] = $ws->populate_section($section,$item,$max);
	else $out['status'] = "No such item Q{$section->section_q}";
} else if ( $action=='search' ) {
	$query = $ws->tfc->getRequest('query','');
	$out['data']['entries'] = $ws->search_entries($query);
	$out['data']['sections'] = $ws->search_sections($query);
	$out['data']['people'] = $ws->search_people($query);
} else if ( $action=='get_person' ) {
	$q = $ws->tfc->getRequest('q',0)*1;
	$out['data'] = $ws->getPerson($q);
} else if ( $action=='get_items_by_year' ) {
	$year = $ws->tfc->getRequest('year','50')*1;
	$out['data'] = $ws->get_items_by_year($year);
} else if ( $action=='get_candidate_items' ) {
	$limit = $ws->tfc->getRequest('limit','50')*1;
	$offset = $ws->tfc->getRequest('offset','0')*1;
	$out['data'] = $ws->get_candidate_items($limit,$offset);
	$out['total_candidates'] = $ws->get_total_candidate_items();
} else if ( $action=='log' ) {
	$event = $ws->tfc->getRequest('event','');
	$q = $ws->tfc->getRequest('q',0)*1;
	$source_prop = $ws->tfc->getRequest('source_prop','');
	$source_key = $ws->tfc->getRequest('source_key','');
	if ( $q==0 and $source_key!='' and $source_prop!='' ) $q = $ws->getItemForFile($source_prop,$source_key);
	$ws->logEvent($event,$q);
} else {
	$out['status'] = "Bad action: {$action}";
}

header('Content-Type: application/json');
print json_encode ( $out ) ;
?>