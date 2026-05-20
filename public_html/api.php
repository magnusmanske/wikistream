<?PHP

error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR|E_ALL);
ini_set('display_errors', 'Off');

require_once ( 'php/Widar.php' );
require_once ( __DIR__.'/../scripts/wikistream.php' ) ;

$config = (new WikiStreamConfig)->get_config_instance();

# Try login etc
$tool = $config->toolkey;
$tool_url = "https://{$tool}.toolforge.org/";
$widar = new Widar($tool) ;
$widar->attempt_verification_auto_forward ( $tool_url ) ;
$widar->authorization_callback = "{$tool_url}api.php" ;
if ( $widar->render_reponse ( true ) ) exit ( 0 ) ;

# Actual action
$ws = new WikiStream($config,$widar->tfc);
$action = $ws->tfc->getRequest('action','');
$ws->language = preg_replace('|[^a-z-]|','',$ws->tfc->getRequest('language','en'));

$out = [ 'status'=>'OK' ];
$httpCode = 200;

try {
	if ( $action=='get_entry' ) {
		$q = preg_replace('|\D|','',$ws->tfc->getRequest('q',0))*1;
		$out['data'] = $ws->getEntry($q);
		# getEntry returns null when the item isn't in vw_ranked_entries
		# (unknown q, or no playable media yet). The frontend renders an
		# "item not in WikiFlix" message when data is null, so just skip
		# the per-user watch-list lookup.
		if ( isset($out['data']) ) {
			try {
				$user_id = $widar->get_user_id()*1;
				$ws->ensure_user_exists($user_id, $widar->get_username());
				$out['data']->on_user_item_list = $ws->is_user_watching_item($user_id,$q);
			} catch (Throwable $e) {
				$out['data']->on_user_item_list = false;
			}
		}
	} else if ( $action=='get_random_entry' ) {
		$out['data'] = [ 'q' => $ws->getRandomEntryQ() ];
	} else if ( $action=='get_special' ) {
		$key    = preg_replace('|[^a-z_]|','',$ws->tfc->getRequest('key',''));
		$offset = max(0, (int) $ws->tfc->getRequest('offset', 0));
		# Pagination via `limit`; `max=all` retained for legacy callers.
		$limit_raw = $ws->tfc->getRequest('limit', null);
		if ($limit_raw === null) {
			$max_raw = $ws->tfc->getRequest('max', 'all');
			$limit   = ($max_raw === 'all') ? PHP_INT_MAX : max(0, (int) $max_raw);
		} else {
			$limit = max(0, (int) $limit_raw);
		}
		if ($key === '') {
			$out['data'] = [ 'key' => '', 'entries' => [], 'total' => 0 ];
		} else {
			$page = $ws->get_special_entries($key, $offset, $limit);
			$out['data'] = [
				'key'     => $key,
				'entries' => $page['entries'] ?? [],
				'total'   => isset($page['total']) ? (int) $page['total'] : 0,
				'offset'  => $offset,
				'limit'   => $limit,
			];
		}
	} else if ( $action=='get_all_sections' ) {
		$out['data'] = $ws->get_top_sections(PHP_INT_MAX);
	} else if ( $action=='get_paginated_sections' ) {
		$offset = max(0, (int) $ws->tfc->getRequest('offset', 0));
		$limit  = max(0, min(100, (int) $ws->tfc->getRequest('limit', 10)));
		$out['data'] = $ws->get_paginated_sections($offset, $limit);
	} else if ( $action=='get_paginated_groups' ) {
		$offset = max(0, (int) $ws->tfc->getRequest('offset', 0));
		$limit  = max(0, min(100, (int) $ws->tfc->getRequest('limit', 10)));
		$out['data'] = $ws->get_paginated_groups($offset, $limit);
	} else if ( $action=='get_your_list' ) {
		try {
			$user_id = $widar->get_user_id()*1;
			$ws->ensure_user_exists($user_id, $widar->get_username());
			$subquery = "SELECT q FROM vw_user_item_list WHERE user_id={$user_id}";
			$out['data'] = $ws->get_item_view('vw_ranked_entries',PHP_INT_MAX,null,$subquery) ;
		} catch (Throwable $e) {
			error_log("api.php get_your_list: " . $e);
			$out['status'] = 'Request failed';
			$httpCode = 500;
		}
	} else if ( $action=='set_user_item_list' ) {
		$q = preg_replace('|\D|','',$ws->tfc->getRequest('q',0))*1;
		$state = $ws->tfc->getRequest('state',0)*1;
		try {
			$user_id = $widar->get_user_id()*1;
			$ws->ensure_user_exists($user_id, $widar->get_username());
			$ws->set_user_list_state($user_id,$q,$state);
		} catch (Throwable $e) {
			error_log("api.php set_user_item_list: " . $e);
			$out['status'] = 'Request failed';
			$httpCode = 500;
		}
	} else if ( $action=='get_section' ) {
		# ATTENTION always assumes property is set
		$offset = max(0, (int) $ws->tfc->getRequest('offset', 0));
		$limit_raw = $ws->tfc->getRequest('limit', null);
		if ($limit_raw === null) {
			$max_raw = $ws->tfc->getRequest('max', 25);
			$limit   = ($max_raw === 'all') ? PHP_INT_MAX : max(0, (int) $max_raw);
		} else {
			$limit = max(0, (int) $limit_raw);
		}
		$section = (object) [
			'section_q' => $ws->tfc->getRequest('q',0)*1,
			'property' => $ws->tfc->getRequest('prop',0)*1,
		];
		$wil = new WikidataItemList();
		$wil->loadItems([$section->section_q]);
		$item = $wil->getItem($section->section_q);
		if ( isset($item) ) {
			$populated = $ws->populate_section($section, $item, $limit, $offset);
			$populated['offset'] = $offset;
			$populated['limit']  = $limit;
			$out['data'] = $populated;
		} else {
			$out['status'] = "No such item Q{$section->section_q}";
			$httpCode = 404;
		}
	} else if ( $action=='search' ) {
		$query = $ws->tfc->getRequest('query','');
		$out['data']['entries']  = $ws->search_entries($query);
		$out['data']['sections'] = $ws->search_sections($query);
		$out['data']['people']   = $ws->search_people($query);
		$out['data']['groups']   = $ws->search_groups($query);
	} else if ( $action=='get_person' ) {
		$q = $ws->tfc->getRequest('q',0)*1;
		$out['data'] = $ws->getPerson($q);
	} else if ( $action=='get_group' ) {
		$q = preg_replace('|\D|','',$ws->tfc->getRequest('q',0))*1;
		$out['data'] = $ws->getGroup($q);
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
		$httpCode = 400;
	}
} catch (Throwable $e) {
	# Top-level safety net: with display_errors=Off, an uncaught Throwable
	# would otherwise produce a 200 OK with empty body. Log server-side and
	# return a JSON envelope the SPA can parse.
	error_log("api.php uncaught (action={$action}): " . $e);
	$out = [ 'status' => 'Internal server error' ];
	$httpCode = 500;
}

# Cache-Control: tag read-only public endpoints as cacheable for 5 minutes.
# - get_entry includes per-user `on_user_item_list`, so use `private` so
#   only the user's own browser caches it (not shared CDN caches).
# - State-changing / per-user-write / randomised / log endpoints are never
#   cached.
# - Any non-OK status (4xx/5xx) is never cached — a stale error response
#   served to the next visitor from a CDN would mask a recovered backend.
$public_cacheable  = [ 'get_all_sections', 'get_section', 'get_person',
                       'get_group',
                       'get_paginated_sections', 'get_paginated_groups',
                       'get_items_by_year', 'get_candidate_items', 'search',
                       'get_special' ];
$private_cacheable = [ 'get_entry' ];
if ( $out['status'] !== 'OK' ) {
	header('Cache-Control: no-store');
} else if ( in_array($action, $public_cacheable,  true) ) {
	header('Cache-Control: public, max-age=300');
} else if ( in_array($action, $private_cacheable, true) ) {
	header('Cache-Control: private, max-age=300');
} else {
	header('Cache-Control: no-store');
}

http_response_code($httpCode);
header('Content-Type: application/json');
print json_encode ( $out ) ;
?>
