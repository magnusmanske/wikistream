<?php

/**
 * Thin dispatcher behind public_html/api.php.
 *
 * The procedural script there was a 150-line if/else chain that mixed
 * routing, per-action input parsing, OAuth-protected branches, and
 * Cache-Control policy — none of it covered by tests. This class is a
 * straight port so the surface stays testable without OAuth or a web
 * server.
 *
 * One handler per `action`. Each handler reads its inputs through
 * `$this->ws->tfc->getRequest()` (mockable via the existing
 * ToolforgeCommon test stub) and either mutates `$out`/`$httpCode`
 * directly or throws — the top-level try/catch in handle() then
 * coerces uncaught Throwables into a generic 500 envelope.
 */
class ApiDispatcher
{
	private const PUBLIC_CACHEABLE = [
		'get_all_sections', 'get_section', 'get_person', 'get_group',
		'get_paginated_sections', 'get_paginated_groups',
		'get_items_by_year', 'get_candidate_items', 'search', 'get_special',
	];
	private const PRIVATE_CACHEABLE = ['get_entry'];

	public function __construct(
		private WikiStream $ws,
		private object $widar,
	) {}

	/**
	 * Dispatch one request.
	 *
	 * @return array{out: array<string,mixed>, http_code: int, cache_control: string}
	 */
	public function handle(string $action): array
	{
		$out      = ['status' => 'OK'];
		$httpCode = 200;

		try {
			$this->route($action, $out, $httpCode);
		} catch (\Throwable $e) {
			// Top-level safety net: with display_errors=Off (api.php),
			// an uncaught Throwable would otherwise leave a 200 OK with
			// empty body. Log server-side and emit a generic envelope.
			error_log("api.php uncaught (action={$action}): " . $e);
			$out      = ['status' => 'Internal server error'];
			$httpCode = 500;
		}

		return [
			'out'           => $out,
			'http_code'     => $httpCode,
			'cache_control' => $this->cacheControl($action, $out['status']),
		];
	}

	/**
	 * Pick the Cache-Control value for a finished response. Errors are
	 * ALWAYS no-store — a CDN must never cache a 5xx-equivalent for the
	 * next visitor after the backend recovers.
	 */
	private function cacheControl(string $action, string $status): string
	{
		if ($status !== 'OK') {
			return 'no-store';
		}
		if (in_array($action, self::PUBLIC_CACHEABLE, true)) {
			return 'public, max-age=300';
		}
		if (in_array($action, self::PRIVATE_CACHEABLE, true)) {
			return 'private, max-age=300';
		}
		return 'no-store';
	}

	private function route(string $action, array &$out, int &$httpCode): void
	{
		$ws = $this->ws;
		$tfc = $ws->tfc;

		switch ($action) {
			case 'get_entry':
				$q = (int) preg_replace('|\D|', '', (string) $tfc->getRequest('q', 0));
				$out['data'] = $ws->getEntry($q);
				// getEntry returns null when the item isn't in
				// vw_ranked_entries. The frontend renders an "item not
				// in WikiFlix" message in that case — just skip the
				// per-user watch-list lookup.
				if (isset($out['data'])) {
					try {
						$user_id = (int) $this->widar->get_user_id();
						$ws->ensure_user_exists($user_id, (string) $this->widar->get_username());
						$out['data']->on_user_item_list = $ws->is_user_watching_item($user_id, $q);
					} catch (\Throwable $_e) {
						$out['data']->on_user_item_list = false;
					}
				}
				return;

			case 'get_random_entry':
				$out['data'] = ['q' => $ws->getRandomEntryQ()];
				return;

			case 'get_special':
				$key    = preg_replace('|[^a-z_]|', '', (string) $tfc->getRequest('key', ''));
				$offset = max(0, (int) $tfc->getRequest('offset', 0));
				$limit  = $this->parseLimitWithMaxAll($tfc, 'all');
				if ($key === '') {
					$out['data'] = ['key' => '', 'entries' => [], 'total' => 0];
					return;
				}
				$page = $ws->get_special_entries($key, $offset, $limit);
				$out['data'] = [
					'key'     => $key,
					'entries' => $page['entries'] ?? [],
					'total'   => isset($page['total']) ? (int) $page['total'] : 0,
					'offset'  => $offset,
					'limit'   => $limit,
				];
				return;

			case 'get_all_sections':
				$out['data'] = $ws->get_top_sections(PHP_INT_MAX);
				return;

			case 'get_paginated_sections':
				$offset = max(0, (int) $tfc->getRequest('offset', 0));
				$limit  = max(0, min(100, (int) $tfc->getRequest('limit', 10)));
				$out['data'] = $ws->get_paginated_sections($offset, $limit);
				return;

			case 'get_paginated_groups':
				$offset = max(0, (int) $tfc->getRequest('offset', 0));
				$limit  = max(0, min(100, (int) $tfc->getRequest('limit', 10)));
				$out['data'] = $ws->get_paginated_groups($offset, $limit);
				return;

			case 'get_your_list':
				try {
					$user_id = (int) $this->widar->get_user_id();
					$ws->ensure_user_exists($user_id, (string) $this->widar->get_username());
					$subquery    = "SELECT q FROM vw_user_item_list WHERE user_id={$user_id}";
					$out['data'] = $ws->get_item_view('vw_ranked_entries', PHP_INT_MAX, null, $subquery);
				} catch (\Throwable $e) {
					error_log("api.php get_your_list: " . $e);
					$out['status'] = 'Request failed';
					$httpCode      = 500;
				}
				return;

			case 'set_user_item_list':
				$q     = (int) preg_replace('|\D|', '', (string) $tfc->getRequest('q', 0));
				$state = (int) $tfc->getRequest('state', 0);
				try {
					$user_id = (int) $this->widar->get_user_id();
					$ws->ensure_user_exists($user_id, (string) $this->widar->get_username());
					$ws->set_user_list_state($user_id, $q, $state);
				} catch (\Throwable $e) {
					error_log("api.php set_user_item_list: " . $e);
					$out['status'] = 'Request failed';
					$httpCode      = 500;
				}
				return;

			case 'get_section':
				$offset = max(0, (int) $tfc->getRequest('offset', 0));
				$limit  = $this->parseLimitWithMaxAll($tfc, 25);
				$section = (object) [
					'section_q' => (int) $tfc->getRequest('q', 0),
					'property'  => (int) $tfc->getRequest('prop', 0),
				];
				$wil = new WikidataItemList();
				$wil->loadItems([$section->section_q]);
				$item = $wil->getItem($section->section_q);
				if (isset($item)) {
					$populated           = $ws->populate_section($section, $item, $limit, $offset);
					$populated['offset'] = $offset;
					$populated['limit']  = $limit;
					$out['data']         = $populated;
				} else {
					$out['status'] = "No such item Q{$section->section_q}";
					$httpCode      = 404;
				}
				return;

			case 'search':
				$query = (string) $tfc->getRequest('query', '');
				$out['data']['entries']  = $ws->search_entries($query);
				$out['data']['sections'] = $ws->search_sections($query);
				$out['data']['people']   = $ws->search_people($query);
				$out['data']['groups']   = $ws->search_groups($query);
				return;

			case 'get_person':
				$out['data'] = $ws->getPerson((int) $tfc->getRequest('q', 0));
				return;

			case 'get_group':
				$q = (int) preg_replace('|\D|', '', (string) $tfc->getRequest('q', 0));
				$out['data'] = $ws->getGroup($q);
				return;

			case 'get_items_by_year':
				$year = (int) $tfc->getRequest('year', '50');
				$out['data'] = $ws->get_items_by_year($year);
				return;

			case 'get_candidate_items':
				$limit  = (int) $tfc->getRequest('limit',  '50');
				$offset = (int) $tfc->getRequest('offset', '0');
				$out['data']             = $ws->get_candidate_items($limit, $offset);
				$out['total_candidates'] = $ws->get_total_candidate_items();
				return;

			case 'log':
				$event       = (string) $tfc->getRequest('event', '');
				$q           = (int) $tfc->getRequest('q', 0);
				$source_prop = (string) $tfc->getRequest('source_prop', '');
				$source_key  = (string) $tfc->getRequest('source_key', '');
				if ($q === 0 && $source_key !== '' && $source_prop !== '') {
					$q = $ws->getItemForFile($source_prop, $source_key);
				}
				$ws->logEvent($event, $q);
				return;

			default:
				$out['status'] = "Bad action: {$action}";
				$httpCode      = 400;
				return;
		}
	}

	/**
	 * `limit` query param with legacy `max=all` fallback. Returns
	 * PHP_INT_MAX when max=all is requested. Negative or non-numeric
	 * values clamp to 0.
	 */
	private function parseLimitWithMaxAll(object $tfc, mixed $defaultMax): int
	{
		$limit_raw = $tfc->getRequest('limit', null);
		if ($limit_raw === null) {
			$max_raw = $tfc->getRequest('max', $defaultMax);
			return ($max_raw === 'all') ? PHP_INT_MAX : max(0, (int) $max_raw);
		}
		return max(0, (int) $limit_raw);
	}
}
