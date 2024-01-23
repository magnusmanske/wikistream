<?PHP

error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR|E_ALL);
ini_set('display_errors', 'On');

require_once ( '/data/project/wikivibes/scripts/wikivibes.php' ) ;

$wv = new WikiVibes;
$action = $wv->tfc->getRequest('action','');
$wv->language = preg_replace('|[^a-z-]|','',$wv->tfc->getRequest('language','en'));


$out = [ 'status'=>'OK' ];

if ( $action=='get_entry' ) {
	$q = preg_replace('|\D|','',$wv->tfc->getRequest('q',0))*1;
	$out['data'] = $wv->getEntry($q);
} else if ( $action=='get_all_sections' ) {
	$out['data'] = $wv->get_top_sections(PHP_INT_MAX);
} else if ( $action=='get_section' ) {
	# ATTENTION always assumes property is set
	$max = $wv->tfc->getRequest('max',25);
	if ($max='all') $max = PHP_INT_MAX;
	else $max = $max*1;
	$section = (object) [
		'section_q' => $wv->tfc->getRequest('q',0)*1,
		'property' => $wv->tfc->getRequest('prop',0)*1,
	];
	$wil = new WikidataItemList();
	$wil->loadItems([$section->section_q]);
	$item = $wil->getItem($section->section_q);
	if ( isset($item) ) $out['data'] = $wv->populate_section($section,$item,$max);
	else $out['status'] = "No such item Q{$section->section_q}";
} else if ( $action=='search' ) {
	$query = $wv->tfc->getRequest('query','');
	$out['data']['entries'] = $wv->search_entries($query);
	$out['data']['sections'] = $wv->search_sections($query);
	$out['data']['people'] = $wv->search_people($query);
} else if ( $action=='get_person' ) {
	$q = $wv->tfc->getRequest('q',0)*1;
	$out['data'] = $wv->getPerson($q);
} else {
	$out['status'] = "Bad action: {$action}";
}

header('Content-Type: application/json');
print json_encode ( $out ) ;
?>