<?php // $_SERVER['HOME'].
// Guard the shared-library requires with class_exists so test bootstraps can
// pre-declare stubs for ToolforgeCommon / WikidataItem / WikidataItemList
// (see tests/bootstrap.php) without triggering a class-redeclaration fatal.
// In production, the classes are not yet declared and the requires fire normally.
if (!class_exists('ToolforgeCommon')) {
	require_once __DIR__ . "/../public_html/php/ToolforgeCommon.php";
}
if (!class_exists('WikidataItem') || !class_exists('WikidataItemList')) {
	require_once __DIR__ . "/../public_html/php/wikidata.php";
}
require_once __DIR__ . "/../scripts/config.php";
require_once __DIR__ . "/../scripts/HttpClient.php";

/**
 * Thrown by WikiStream::add_item_details when the item carries a bad-genre
 * claim. Distinct type so the chunk handler can catch only this case and
 * let real failures propagate.
 */
class BadGenreException extends \RuntimeException {}

class WikiStream
{
	// Wikidata item/property IDs used in PHP logic
	private const WD_GENDER_MALE        = "Q6581097";
	private const WD_GENDER_FEMALE      = "Q6581072";
	private const WD_TRAILER            = "Q622550";   // P3831 qualifier value: trailer
	private const WD_DO_NOT_USE         = "Q124428688"; // P11484 qualifier: do not use for WikiFlix
	private const WD_UNIT_MINUTE        = "http://www.wikidata.org/entity/Q7727";

	public $tfc;
	public $language = "en";
	public $config;
	protected $db;
	protected HttpClientInterface $httpClient;

	public function __construct($config = null, $tfc = null, ?HttpClientInterface $httpClient = null)
	{
		if ($config == null) {
			die("Config not set");
		}
		$this->config = $config;
		if ($tfc == null) {
			$this->tfc = new ToolforgeCommon($this->config->toolkey);
		} else {
			$this->tfc = $tfc;
		}
		$this->db = $this->tfc->openDBtool($this->config->tool_db);
		$this->httpClient = $httpClient ?? new CurlHttpClient();
	}

	public function getPerson($q, $add_files = true): object
	{
		$ret = (object) ["q" => $q, "entries" => []];
		$q *= 1;

		$sql = "SELECT * FROM `person` WHERE `q`={$q}";
		$result = $this->tfc->getSQL($this->db, $sql);
		if ($o = $result->fetch_object()) {
			$ret->label = $o->label;
			$ret->gender = $o->gender;
			$ret->image = $o->image;
		}
		$this->freeResult($result);

		if ($add_files) {
			$sql =
				"SELECT * FROM `vw_ranked_entries` WHERE `q` IN (SELECT DISTINCT `item_q` FROM `section` WHERE `property` IN (" .
				implode(",", $this->config->people_props) .
				") AND `section_q`={$q})";
			$result = $this->tfc->getSQL($this->db, $sql);
			while ($o = $result->fetch_object()) {
				$this->fix_item_image($o);
				$ret->entries[] = $o;
			}
			$this->freeResult($result);
		}
		return $ret;
	}

	// Batch-fetch person records (without files) for a list of Q-numbers.
	// Returns an array keyed by numeric Q-id.
	protected function getPersonsBatch(array $qs): array
	{
		if (count($qs) === 0) {
			return [];
		}
		$qs_safe = implode(",", array_map(fn($q) => (int) $q, $qs));
		$sql = "SELECT * FROM `person` WHERE `q` IN ({$qs_safe})";
		$result = $this->tfc->getSQL($this->db, $sql);
		$people = [];
		while ($o = $result->fetch_object()) {
			$people[(int) $o->q] = $o;
		}
		$this->freeResult($result);
		return $people;
	}

	public function getRandomEntryQ(): ?int
	{
		// ORDER BY RAND() forces a full scan + filesort over the view; on a
		// growing dataset that's a per-request latency cliff. Instead pick
		// a random q in [min,max] and return the next row at/after it.
		// Slightly gap-biased (items after a large gap get picked more
		// often), which is acceptable for "random entry" semantics.
		$sql = "SELECT MIN(q) AS `lo`, MAX(q) AS `hi` FROM `vw_ranked_entries`";
		$result = $this->tfc->getSQL($this->db, $sql);
		$lo = $hi = null;
		if ($o = $result->fetch_object()) {
			$lo = isset($o->lo) ? (int) $o->lo : null;
			$hi = isset($o->hi) ? (int) $o->hi : null;
		}
		$this->freeResult($result);
		if ($lo === null || $hi === null || $lo > $hi) {
			return null;
		}

		$rand = $lo === $hi ? $lo : mt_rand($lo, $hi);
		$sql = "SELECT `q` FROM `vw_ranked_entries` WHERE `q` >= {$rand} ORDER BY `q` LIMIT 1";
		$result = $this->tfc->getSQL($this->db, $sql);
		$q = null;
		if ($o = $result->fetch_object()) {
			$q = (int) $o->q;
		}
		$this->freeResult($result);
		if ($q !== null) {
			return $q;
		}

		// No row at or after $rand (the highest q happens to be unavailable);
		// wrap around to the smallest q.
		$sql = "SELECT `q` FROM `vw_ranked_entries` ORDER BY `q` LIMIT 1";
		$result = $this->tfc->getSQL($this->db, $sql);
		if ($o = $result->fetch_object()) {
			$q = (int) $o->q;
		}
		$this->freeResult($result);
		return $q;
	}

	public function getEntry($q): ?object
	{
		$ret = (object) [];
		$q *= 1;
		$sql = "SELECT * FROM `vw_ranked_entries` WHERE `q`={$q}";
		$result = $this->tfc->getSQL($this->db, $sql);
		if ($o = $result->fetch_object()) {
			$ret = $o;
		} else {
			$this->freeResult($result);
			return null;
		} // Nothing
		$this->freeResult($result);

		$o->entry_files = json_decode($o->files);

		$sql = "SELECT * FROM `section` WHERE `item_q`={$q}";
		if (count($this->config->bad_genres) > 0) {
			$sql .=
				" AND `section_q` NOT IN (" .
				implode($this->config->bad_genres) .
				")";
		}
		$result = $this->tfc->getSQL($this->db, $sql);
		$sections = [];
		$to_load = [];
		$ret->people = [];
		$person_rows = []; // collect [property, section_q] pairs for batch loading
		while ($o = $result->fetch_object()) {
			if (in_array($o->property, $this->config->people_props)) {
				$person_rows[] = $o;
			} else {
				$sections[] = $o;
				$to_load[] = $o->section_q;
			}
		}
		$this->freeResult($result);

		// Batch-fetch all person records in a single query
		$person_qs = array_map(fn($r) => $r->section_q, $person_rows);
		$persons_by_q = $this->getPersonsBatch($person_qs);
		foreach ($person_rows as $o) {
			if (!isset($ret->people["P{$o->property}"])) {
				$ret->people["P{$o->property}"] = [];
			}
			$q_num = (int) $o->section_q;
			$person_obj = (object) ["q" => $q_num, "entries" => []];
			if (isset($persons_by_q[$q_num])) {
				$p = $persons_by_q[$q_num];
				$person_obj->label = $p->label;
				$person_obj->gender = $p->gender;
				$person_obj->image = $p->image;
			}
			$ret->people["P{$o->property}"]["Q{$o->section_q}"] = $person_obj;
		}
		$wil = new WikidataItemList();
		$wil->loadItems($to_load);
		$itemsByQ = [];
		foreach ($to_load as $q) {
			$it = $wil->getItem($q);
			if ($it !== null) {
				$itemsByQ[(int) $q] = $it;
			}
		}
		$ret->sections = $this->populate_sections_batch($sections, $itemsByQ);

		return $ret;
	}

	protected function get_items_in_db(): array
	{
		$ret = [];
		$sql = "SELECT `q` FROM `item`";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$ret["Q{$o->q}"] = $o->q;
		}
		$this->freeResult($result);
		return $ret;
	}

	public function import_commons_video_minutes(): void
	{
		$sql = "SELECT `id`, `key` FROM `file` WHERE `property`=10 AND `minutes` IS NULL";
		$result = $this->tfc->getSQL($this->db, $sql);
		// Collect (id, key) rows first so we can chunk and batch-fetch.
		$rows = [];
		while ($o = $result->fetch_object()) {
			$rows[] = $o;
		}
		$this->freeResult($result);
		if (empty($rows)) {
			return;
		}

		// Commons API: up to 50 titles per request via titles=A|B|C.
		// Build a map from canonical title (spaces, no "File:" prefix) → file row,
		// so we can match the API's normalized response title back to the row.
		foreach (array_chunk($rows, 50) as $chunk) {
			$rowsByCanonical = []; // canonical title → list of file rows (in case of duplicate keys)
			$titles = [];
			$seenKeys = [];
			foreach ($chunk as $o) {
				$canonical = str_replace("_", " ", $o->key);
				$rowsByCanonical[$canonical][] = $o;
				if (!isset($seenKeys[$o->key])) {
					$titles[] = "File:" . $o->key;
					$seenKeys[$o->key] = true;
				}
			}
			// Per-title urlencode then join with the literal pipe character.
			$titlesParam = implode("|", array_map("urlencode", $titles));
			$url =
				"https://commons.wikimedia.org/w/api.php?action=query&format=json&prop=imageinfo&iiprop=metadata&titles=" .
				$titlesParam;
			$j = $this->get_json_from_url($url);
			if (!isset($j) || !isset($j->query) || !isset($j->query->pages)) {
				continue;
			}

			foreach ($j->query->pages as $page) {
				if (!isset($page) || !isset($page->title)) {
					continue;
				}
				if (
					!isset($page->imageinfo) ||
					!isset($page->imageinfo[0]) ||
					!isset($page->imageinfo[0]->metadata)
				) {
					continue;
				}

				$pageTitle = $page->title;
				if (strpos($pageTitle, "File:") === 0) {
					$pageTitle = substr($pageTitle, 5);
				}
				$matchingRows = $rowsByCanonical[$pageTitle] ?? [];
				if (empty($matchingRows)) {
					continue;
				}

				$minutes = null;
				foreach ($page->imageinfo[0]->metadata as $m) {
					if (!isset($m->name) || !isset($m->value)) {
						continue;
					}
					if ($m->name == "playtime_seconds") {
						$minutes = round($m->value / 60);
					} elseif ($m->name == "playtime_minutes") {
						$minutes = round((float) $m->value);
					} elseif ($m->name == "length") {
						$minutes = round($m->value / 60);
					} elseif ($m->name == "duration") {
						$minutes = round($m->value / 60);
					}
				}
				if ($minutes === null) {
					continue;
				}
				$minutes_safe = (int) $minutes;
				foreach ($matchingRows as $row) {
					$id_safe = (int) $row->id;
					$sql = "UPDATE `file` SET `minutes`={$minutes_safe} WHERE id={$id_safe} AND `minutes` IS NULL";
					$this->tfc->getSQL($this->db, $sql);
				}
			}
		}
	}

	public function update_from_sparql(): void
	{
		$new_qs = [];
		$existing_qs = $this->get_items_in_db();

		# All entries with a file on Commons
		foreach ($this->config->sparql as $sparql_id => $sparql) {
			# Filter out bad genres
			if (
				isset($this->config->bad_genres) and
				count($this->config->bad_genres) > 0
			) {
				$genre_filter =
					"  . VALUES ?bad_genre { wd:Q" .
					implode(" wd:Q", $this->config->bad_genres) .
					"} MINUS { ?q wdt:P136 ?bad_genre }";
				$sparql = "SELECT ?q { {" . $sparql . "}" . $genre_filter . "}";
			}

			$found = 0;
			foreach ($this->tfc->getSPARQL_TSV($sparql) as $row) {
				$row = (object) $row;
				$q = $this->tfc->parseItemFromURL($row->q);
				if (isset($existing_qs[$q])) {
					continue;
				}
				// Cast via (int) — on PHP 8.x, "" * 1 is a TypeError, and
				// parseItemFromURL can return "" for a row with a malformed
				// ?q binding. Skip such rows rather than inserting q=0.
				$q_numeric = (int) preg_replace("|\D|", "", (string) $q);
				if ($q_numeric <= 0) {
					continue;
				}
				$new_qs[] = $q_numeric;
				$found += 1;
			}
			// print "SPARQL #{$sparql_id} found {$found} items\n";
		}

		if (count($new_qs) == 0) {
			return;
		} # Nothing new on the western front
		$new_qs = array_values(array_unique($new_qs));
		print "Adding " . count($new_qs) . " new items\n";
		foreach (array_chunk($new_qs, 500) as $chunk) {
			$sql =
				"INSERT IGNORE INTO `item` (`q`) VALUES (" .
				implode("),(", $chunk) .
				")";
			$this->tfc->getSQL($this->db, $sql);
		}
	}

	public function remove_unused_people(): void
	{
		// NOT EXISTS is preferred over NOT IN (subquery) on MariaDB — the
		// optimiser handles index lookups directly, no full-table scan of
		// the subquery's result set.
		$sql =
			"DELETE FROM person WHERE NOT EXISTS (SELECT 1 FROM section WHERE section.section_q = person.q)";
		$this->tfc->getSQL($this->db, $sql);
	}

	protected function get_earliest_year($item, $property): int|string
	{
		$years = [];
		foreach ($item->getClaims($property) as $c) {
			if ($c->rank == "deprecated") {
				continue;
			}
			if (
				!isset($c) or
				!isset($c->mainsnak) or
				!isset($c->mainsnak->datavalue)
			) {
				continue;
			}
			if (
				preg_match(
					"|^\+(\d{4})|",
					$c->mainsnak->datavalue->value->time,
					$m,
				)
			) {
				$years[] = $m[1] * 1;
			}
		}
		if (count($years) == 0) {
			return "null";
		}
		sort($years, SORT_NUMERIC);
		return $years[0];
	}

	protected function add_item_details(
		$wil,
		$item_q_numeric,
		&$qs,
		&$sections,
		&$entry_files,
		&$items_for_labels = null,
		&$item_rows = null
	): void {
		$item = $wil->getItem($item_q_numeric);
		if (!isset($item)) {
			return;
		}

		# Sections — collect into a per-item buffer first so a bad-genre
		# claim discovered mid-loop doesn't leave partial rows in the
		# caller's $sections array.
		$itemSections = [];
		foreach (
			array_merge(
				$this->config->misc_section_props,
				$this->config->people_props,
				$this->config->grouping_props,
			)
			as $prop
		) {
			foreach ($item->getClaims($prop) as $claim) {
				$target_q = $item->getTarget($claim);
				$target_q_numeric = preg_replace("/\D/", "", $target_q);
				if (!$target_q) {
					continue;
				}
				if (in_array($target_q_numeric, $this->config->bad_genres)) {
					throw new BadGenreException("Bad genre for Q{$item_q_numeric}: target Q{$target_q_numeric}");
				}
				$itemSections[] = "({$item_q_numeric},{$prop},{$target_q_numeric})";
			}
		}

		// All section claims passed the bad-genre filter — commit them.
		foreach ($itemSections as $row) {
			$sections[] = $row;
		}
		$qs[] = $item_q_numeric;
		# Files
		foreach ($this->config->file_props as $property) {
			foreach ($item->getClaims($property) as $c) {
				if (isset($c->rank) and $c->rank == "deprecated") {
					continue;
				}
				if (!isset($c->mainsnak)) {
					continue;
				}
				if (!isset($c->mainsnak->datavalue)) {
					continue;
				}
				if (!isset($c->mainsnak->datavalue->value)) {
					continue;
				}
				if (!isset($c->mainsnak->datavalue->type)) {
					continue;
				}
				if ($c->mainsnak->datavalue->type != "string") {
					continue;
				}
				$key = $c->mainsnak->datavalue->value;
				$key_safe = $this->db->real_escape_string($key);

				# Check for "do not use for WikiFlix" qualifier
				$skip_file = false;
				if (isset($c->qualifiers) and isset($c->qualifiers->P11484)) {
					foreach ($c->qualifiers->P11484 as $qual) {
						if ($qual->datavalue->value->id == self::WD_DO_NOT_USE) {
							$skip_file = true;
						}
					}
				}
				if ($skip_file) {
					continue;
				}

				# Check for trailer qualifier
				$is_trailer_safe = 0;
				if (isset($c->qualifiers) and isset($c->qualifiers->P3831)) {
					foreach ($c->qualifiers->P3831 as $qual) {
						if ($qual->datavalue->value->id == self::WD_TRAILER) {
							$is_trailer_safe = 1;
						}
					}
				}

				$entry_files[] = "({$item_q_numeric},{$property},'{$key_safe}',{$is_trailer_safe})";
			}
		}

		$image = $item->getFirstString("P18");
		if ($image == "") {
			$image = $item->getFirstString("P154");
		}
		if ($image == "") {
			$image = $item->getFirstString("P3383");
		}
		if ($image == "") {
			$image_safe = "null";
		} else {
			$image_safe = '"' . $this->db->real_escape_string($image) . '"';
		}

		# Duration
		$minutes_safe = "null";
		foreach ($item->getClaims("P2047") as $c) {
			if ($c->rank == "deprecated") {
				continue;
			}
			if (
				$c->mainsnak->datavalue->value->unit ==
				self::WD_UNIT_MINUTE
			) {
				$minutes_safe = $c->mainsnak->datavalue->value->amount * 1; # Minutes
			}
		}

		# Sites
		$sites_safe = count($item->getSitelinks());

		$ts_safe = $this->tfc->getCurrentTimestamp();

		$year_safe = $this->get_earliest_year($item, "P577");
		$title_safe = $this->db->real_escape_string($item->getLabel());

		// Defer the item UPDATE so the caller can issue one batched
		// INSERT ... ON DUPLICATE KEY UPDATE for the whole chunk.
		if (is_array($item_rows)) {
			$item_rows[] = "({$item_q_numeric},'{$title_safe}',{$year_safe},{$minutes_safe},{$image_safe},{$sites_safe},'{$ts_safe}')";
		} else {
			$sql = "UPDATE `item` set `title`='{$title_safe}',`year`={$year_safe},`minutes`={$minutes_safe},`image`={$image_safe},`sites`={$sites_safe},`ts`='{$ts_safe}' WHERE `q`={$item_q_numeric}";
			$this->tfc->getSQL($this->db, $sql);
		}

		// Defer label refresh — caller batches via update_item_labels_batch().
		if (is_array($items_for_labels)) {
			$items_for_labels[] = $item;
		} else {
			$this->update_item_labels($item);
		}
	}

	protected function add_item_details_chunk($chunk): void
	{
		$wil = new WikidataItemList();
		$wil->loadItems($chunk);
		$qs = [];
		$sections = [];
		$entry_files = [];
		$items_for_labels = [];
		$item_rows = [];
		foreach ($chunk as $q_numeric) {
			try {
				$this->add_item_details(
					$wil,
					$q_numeric,
					$qs,
					$sections,
					$entry_files,
					$items_for_labels,
					$item_rows,
				);
			} catch (BadGenreException $e) {
				// Expected control flow: item carries a configured bad-genre
				// claim, drop it from this chunk. Genuine errors no longer
				// get swallowed here.
			}
		}
		if (count($qs) == 0) {
			return;
		}

		# Wrap all writes for this chunk in a single transaction. Reduces commit
		# overhead on InnoDB from 6+ fsyncs to 1, and prevents partial-chunk
		# state if a later write fails.
		$this->beginTransaction();
		try {
			# Cleanup
			$qs = implode(",", $qs);
			$sql = "DELETE FROM `section` WHERE `item_q` IN ($qs)";
			$this->tfc->getSQL($this->db, $sql);
			$sql = "DELETE FROM `file` WHERE `item_q` IN ($qs)";
			$this->tfc->getSQL($this->db, $sql);

			# Insert sections
			if (count($sections) > 0) {
				$sql =
					"INSERT IGNORE INTO `section` (`item_q`,`property`,`section_q`) VALUES ";
				$sql .= implode(",", $sections);
				$this->tfc->getSQL($this->db, $sql);
			}

			# Insert item filed
			if (count($entry_files) > 0) {
				$sql =
					"INSERT IGNORE INTO `file` (`item_q`,`property`,`key`,`is_trailer`) VALUES ";
				$sql .= implode(",", $entry_files);
				$this->tfc->getSQL($this->db, $sql);
			}

			# Batched item upsert: one round-trip for the chunk instead of one per item.
			# Rows that already exist (the expected case here, since we SELECTed from
			# `item` WHERE available=0) get UPDATEd via ON DUPLICATE KEY UPDATE.
			if (count($item_rows) > 0) {
				$sql =
					"INSERT INTO `item` (`q`,`title`,`year`,`minutes`,`image`,`sites`,`ts`) VALUES " .
					implode(",", $item_rows) .
					" ON DUPLICATE KEY UPDATE `title`=VALUES(`title`),`year`=VALUES(`year`),`minutes`=VALUES(`minutes`),`image`=VALUES(`image`),`sites`=VALUES(`sites`),`ts`=VALUES(`ts`)";
				$this->tfc->getSQL($this->db, $sql);
			}

			# Make item available
			$sql = "UPDATE `item` SET `available`=1 WHERE `q` IN ($qs)";
			$this->tfc->getSQL($this->db, $sql);

			# Batched label refresh for the entire chunk
			$this->update_item_labels_batch($items_for_labels);

			$this->commit();
		} catch (\Throwable $e) {
			$this->rollback();
			throw $e;
		}
	}

	protected function beginTransaction(): void
	{
		// ToolforgeCommon::getSQL takes $sql by reference, so we can't pass
		// string literals here (or anywhere else in this file).
		$sql = "START TRANSACTION";
		$this->tfc->getSQL($this->db, $sql);
	}

	protected function commit(): void
	{
		$sql = "COMMIT";
		$this->tfc->getSQL($this->db, $sql);
	}

	protected function rollback(): void
	{
		$sql = "ROLLBACK";
		$this->tfc->getSQL($this->db, $sql);
	}

	/**
	 * Free a mysqli result set if the object supports it. Defensive against
	 * test-harness fakes that don't implement free().
	 */
	protected function freeResult($result): void
	{
		if (is_object($result) && method_exists($result, 'free')) {
			$result->free();
		}
	}

	public function add_missing_item_details(): void
	{
		$sql = "SELECT `q` FROM `item` WHERE `available`=0";
		$result = $this->tfc->getSQL($this->db, $sql);
		$qs = [];
		while ($o = $result->fetch_object()) {
			$qs[] = $o->q;
		}
		$this->freeResult($result);
		if (count($qs) == 0) {
			return;
		} # Nothing to do
		foreach (array_chunk($qs, 50) as $chunk) {
			$this->add_item_details_chunk($chunk);
		}
	}

	public function make_rc_unavailable(): void
	{
		// 1. Read last check timestamp from kv.
		$last_rc_check = "";
		$sql = "SELECT `value` FROM `kv` WHERE `key`='last_rc_check'";
		$result = $this->tfc->getSQL($this->db, $sql);
		if ($o = $result->fetch_object()) {
			$last_rc_check = $o->value;
		}
		$this->freeResult($result);

		// First-run safety: bound the lookback so we don't scan
		// months of recentchanges history the first time through.
		if ($last_rc_check === "") {
			$last_rc_check = date("YmdHis", strtotime("-1 day"));
		}

		// 2. Single scan of wikidatawiki.recentchanges for ns=0 changes
		//    since last check. Replaces the previous chunked approach which
		//    loaded every item.q + every person.q into PHP first and then
		//    issued (count/1000) cross-DB queries with rc_title IN (...).
		$dbwd = $this->tfc->openDBwiki("wikidatawiki");
		$last_rc_check_safe = $this->db->real_escape_string($last_rc_check);
		$sql = "SELECT `rc_title`,`rc_timestamp` FROM `recentchanges` " .
			"WHERE `rc_namespace`=0 AND `rc_timestamp`>'{$last_rc_check_safe}'";
		$result = $this->tfc->getSQL($dbwd, $sql);
		$changedQs = [];
		$newestTs = $last_rc_check;
		while ($o = $result->fetch_object()) {
			$q = (int) preg_replace("|\D|", "", $o->rc_title);
			if ($q > 0) {
				$changedQs[$q] = true;
			}
			if ($newestTs < $o->rc_timestamp) {
				$newestTs = $o->rc_timestamp;
			}
		}
		$this->freeResult($result);

		// 3. Apply the diff to our tool DB. Wrap the writes (and the
		//    kv UPSERT) in a single transaction so a mid-run crash doesn't
		//    leave us out of sync with kv.last_rc_check.
		$this->beginTransaction();
		try {
			if (!empty($changedQs)) {
				// Chunk the IN-clause so a flood of changes doesn't blow up
				// query text length. WHERE q IN (...) uses the PK index on
				// both tables — most changedQs aren't in our DB and
				// affect zero rows, which is fine.
				foreach (array_chunk(array_keys($changedQs), 1000) as $chunk) {
					$qList = implode(",", $chunk);
					$sql = "UPDATE `item` SET `available`=0 WHERE `q` IN ({$qList})";
					$this->tfc->getSQL($this->db, $sql);
					$sql = "DELETE FROM `person` WHERE `q` IN ({$qList})";
					$this->tfc->getSQL($this->db, $sql);
				}
			}

			// Persist the high-water mark. UPSERT so the kv row is created
			// on first run (the prior UPDATE-only form silently did nothing
			// until someone seeded the row manually).
			$newest_safe = $this->db->real_escape_string($newestTs);
			$sql = "INSERT INTO `kv` (`key`,`value`) VALUES ('last_rc_check','{$newest_safe}') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)";
			$this->tfc->getSQL($this->db, $sql);
			$this->commit();
		} catch (\Throwable $e) {
			$this->rollback();
			throw $e;
		}
	}

	public function get_recently_added($num = 25, $section_q = null): array
	{
		return $this->get_item_view("vw_recently_added", $num, $section_q);
	}

	/**
	 * Look up entries for a "pseudo-section" — one of the main-page rows
	 * that isn't backed by a Wikidata Q-id (e.g. "Recently edited",
	 * "Highly ranked"). Built-in keys are handled here; tool-specific
	 * keys (e.g. WikiFlix's "Female directors") are delegated to the
	 * config class.
	 */
	public function get_special_entries(string $key, int $max = PHP_INT_MAX): array
	{
		switch ($key) {
			case "recently_edited":
				return $this->get_recently_added($max);
			case "highly_ranked":
				return $this->get_ranked_items($max);
			case "popular_entries":
				return $this->get_item_view("vw_popular_entries", $max);
		}
		return $this->config->get_special_entries($this, $key, $max);
	}

	public function get_ranked_items($num = 25, $section_q = null): array
	{
		return $this->get_item_view(
			"vw_ranked_entries_blacklist",
			$num,
			$section_q,
		);
	}

	public function get_item_view(
		$view_name,
		$num = 25,
		$section_q = null,
		$subquery = null,
	): array {
		$ret = [];
		$sql = "SELECT * FROM `{$view_name}` WHERE 1=1";
		if (isset($section_q) and $section_q != null) {
			$sql .= " AND `q` IN (SELECT item_q FROM section WHERE section_q={$section_q})";
		}
		if ($subquery != null) {
			$sql .= " AND q IN ({$subquery})";
		}
		$sql .= " LIMIT {$num}";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$this->fix_item_image($o);
			$ret[] = $o;
		}
		$this->freeResult($result);
		return $ret;
	}

	protected function get_item_view_count($view_name, $section_q = null): int
	{
		$sql = "SELECT COUNT(*) AS `cnt` FROM `{$view_name}` WHERE 1=1";
		if (isset($section_q) and $section_q != null) {
			$sql .= " AND `q` IN (SELECT item_q FROM section WHERE section_q={$section_q})";
		}
		$result = $this->tfc->getSQL($this->db, $sql);
		$cnt = 0;
		if ($o = $result->fetch_object()) {
			$cnt = (int) $o->cnt;
		}
		$this->freeResult($result);
		return $cnt;
	}

	protected function fix_item_image(&$o): void
	{
		if (!isset($o->files)) {
			return;
		}
		$o->files = json_decode($o->files);
		foreach ($o->files as $vf) {
			if ($o->image == null and isset($vf->{'10'})) {
				$o->image = $vf->{'10'};
			}
		}
	}

	protected function update_item_labels($item): void
	{
		$this->update_item_labels_batch([$item]);
	}

	/**
	 * Batched label refresh for many items: one DELETE for the entire q-set
	 * followed by one multi-row INSERT. With chunks of 50 items × ~30
	 * languages each this collapses 50+50 round-trips into 2.
	 *
	 * Items with no labels are still included in the DELETE (so any stale
	 * rows for those q's are cleared, matching the per-item behaviour).
	 *
	 * @param array $items list of WikidataItem
	 */
	protected function update_item_labels_batch(array $items): void
	{
		if (empty($items)) {
			return;
		}

		$qNumerics = [];
		$valueRows = [];
		foreach ($items as $item) {
			$q = $item->getQ();
			$q_numeric = (int) preg_replace("|\D|", "", $q);
			if ($q_numeric === 0) {
				continue;
			}
			$qNumerics[] = $q_numeric;
			foreach ($item->j->labels as $lang => $v) {
				$lang_safe = $this->db->real_escape_string($lang);
				$value_safe = $this->db->real_escape_string($v->value);
				$valueRows[] = "({$q_numeric},'{$lang_safe}','{$value_safe}')";
			}
		}

		if (empty($qNumerics)) {
			return;
		}

		$qList = implode(",", array_values(array_unique($qNumerics)));
		$sql = "DELETE FROM `label` WHERE `q` IN ({$qList})";
		$this->tfc->getSQL($this->db, $sql);

		if (empty($valueRows)) {
			return;
		}

		// Cap each INSERT at ~1000 rows to stay well under max_allowed_packet
		// for pathological large label sets. Typical chunks fit in one INSERT.
		foreach (array_chunk($valueRows, 1000) as $valueChunk) {
			$sql = "INSERT IGNORE INTO `label` (`q`,`language`,`value`) VALUES " . implode(",", $valueChunk);
			$this->tfc->getSQL($this->db, $sql);
		}
	}

	public function import_missing_section_labels(): void
	{
		$sql =
			"SELECT DISTINCT `section_q` AS `q` FROM `section` WHERE NOT EXISTS (SELECT 1 FROM `label` WHERE `label`.`q` = `section`.`section_q`)" .
			" UNION SELECT `q` FROM `item` WHERE NOT EXISTS (SELECT 1 FROM `label` WHERE `label`.`q` = `item`.`q`)" .
			" UNION SELECT `q` FROM `person` WHERE NOT EXISTS (SELECT 1 FROM `label` WHERE `label`.`q` = `person`.`q`)";
		$result = $this->tfc->getSQL($this->db, $sql);
		$qs = [];
		while ($o = $result->fetch_object()) {
			$qs[] = $o->q * 1;
		}
		$this->freeResult($result);
		foreach (array_chunk($qs, 50) as $chunk) {
			$wil = new WikidataItemList();
			$wil->loadItems($chunk);
			$itemsBatch = [];
			foreach ($chunk as $q) {
				$item = $wil->getItem($q);
				if (!isset($item)) {
					continue;
				}
				$itemsBatch[] = $item;
			}
			if (empty($itemsBatch)) {
				continue;
			}
			$this->beginTransaction();
			try {
				$this->update_item_labels_batch($itemsBatch);
				$this->commit();
			} catch (\Throwable $e) {
				$this->rollback();
				throw $e;
			}
		}
	}

	public function get_top_sections(
		$num = 20,
		$properties = [],
		$skip_section_q = null,
	): array {
		if ($skip_section_q == null) {
			$skip_section_q = $this->config->skip_section_q;
		}
		if (count($properties) == 0) {
			$properties = $this->config->misc_section_props;
		}
		$skip_section_q = array_merge(
			$skip_section_q,
			$this->config->bad_genres,
		);
		$ret = [];
		$sql =
			"SELECT *,(SELECT `value` FROM `label` WHERE `language`='{$this->language}' AND `q`=`section_q`) AS `label` FROM `vw_section_property_q` WHERE `property` IN (" .
			implode(",", $properties) .
			") AND `section_q` NOT IN (" .
			implode(",", $skip_section_q) .
			") LIMIT {$num}";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$ret[] = $o;
		}
		$this->freeResult($result);
		return $ret;
	}

	protected function get_top_sections_count(
		$properties = [],
		$skip_section_q = null,
	): int {
		if ($skip_section_q == null) {
			$skip_section_q = $this->config->skip_section_q;
		}
		if (count($properties) == 0) {
			$properties = $this->config->misc_section_props;
		}
		$skip_section_q = array_merge(
			$skip_section_q,
			$this->config->bad_genres,
		);
		$sql =
			"SELECT COUNT(*) AS `cnt` FROM `vw_section_property_q` WHERE `property` IN (" .
			implode(",", $properties) .
			") AND `section_q` NOT IN (" .
			implode(",", $skip_section_q) .
			")";
		$result = $this->tfc->getSQL($this->db, $sql);
		$cnt = 0;
		if ($o = $result->fetch_object()) {
			$cnt = (int) $o->cnt;
		}
		$this->freeResult($result);
		return $cnt;
	}

	public function get_random_sections(
		$num = 20,
		$properties = [],
		$skip_section_q = null,
	): array {
		if ($skip_section_q == null) {
			$skip_section_q = $this->config->skip_section_q;
		}
		$min_items = 10;
		if (count($properties) == 0) {
			$properties = $this->config->misc_section_props;
		}
		$skip_section_q = array_merge(
			$skip_section_q,
			$this->config->bad_genres,
		);
		$ret = [];
		$sql =
			"SELECT *,(SELECT `value` FROM `label` WHERE `language`='{$this->language}' AND `q`=`section_q`) AS `label` FROM `vw_section_property_q` WHERE `cnt`>={$min_items} AND `property` IN (" .
			implode(",", $properties) .
			") AND `section_q` NOT IN (" .
			implode(",", $skip_section_q) .
			") ORDER BY rand() LIMIT {$num}";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$ret[] = $o;
		}
		$this->freeResult($result);
		return $ret;
	}

	protected function get_year_stats(): array
	{
		$ret = [];
		$sql =
			"SELECT `year`,count(*) AS cnt FROM `item` WHERE `year` IS NOT NULL GROUP BY `year` ORDER BY `year`";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$decade = floor($o->year / 10) * 10;
			if (!isset($ret[$decade])) {
				$ret[$decade] = [];
			}
			$ret[$decade][] = $o;
		}
		$this->freeResult($result);
		ksort($ret, SORT_NUMERIC);
		return $ret;
	}

	public function get_main_page_data(
		$max_movies_per_section = 25,
		$max_sections = 20,
	): array {
		$out = ["status" => "OK"];
		$out["sections"] = [];
		$out["sections"][] = [
			"key" => "recently_edited",
			"title_key" => "recently_edited",
			"entries" => $this->get_recently_added(25),
		];
		$out["sections"][] = [
			"key" => "highly_ranked",
			"title_key" => "highly_ranked",
			"entries" => $this->get_ranked_items(25),
		];
		$out["sections"][] = [
			"key" => "popular_entries",
			"title_key" => "popular_entries",
			"entries" => $this->get_item_view(
				"vw_popular_entries",
				$max_movies_per_section,
			),
		];
		$this->config->add_special_sections($this, $out);

		if ($max_sections == PHP_INT_MAX) {
			$sections = $this->get_top_sections($max_sections);
		} else {
			$sections = $this->get_random_sections($max_sections);
		}
		$qs = [];
		foreach ($sections as $section) {
			$qs[] = $section->section_q;
		}
		$wil = new WikidataItemList();
		$wil->loadItems($qs);
		$itemsByQ = [];
		foreach ($qs as $q) {
			$it = $wil->getItem($q);
			if ($it !== null) {
				$itemsByQ[(int) $q] = $it;
			}
		}
		foreach ($this->populate_sections_batch($sections, $itemsByQ, $max_movies_per_section) as $populated) {
			$out["sections"][] = $populated;
		}

		// One round-trip for the top-level totals instead of three.
		$sql = "SELECT (SELECT COUNT(*) FROM `item`) AS `items`, (SELECT COUNT(*) FROM `person`) AS `people`";
		$result = $this->tfc->getSQL($this->db, $sql);
		if ($o = $result->fetch_object()) {
			$out["entry_total"]  = $o->items;
			$out["person_total"] = $o->people;
		}
		$this->freeResult($result);

		$out["section_total"] = $this->get_top_sections_count();

		$out["years"] = $this->get_year_stats();

		$out["misc"] = $this->config->interface_config;

		return $out;
	}

	public function populate_section($section, $item, $max = 25): array
	{
		$title = $item->getLabel();
		$total = $this->get_item_view_count("vw_ranked_entries_blacklist", $section->section_q);
		$entries = $this->get_ranked_items($max, $section->section_q);
		return [
			"q" => $section->section_q,
			"title" => $title,
			"prop" => $section->property,
			"total" => $total,
			"entries" => $entries,
		];
	}

	/**
	 * Batched analogue of populate_section() for many sections at once.
	 *
	 * Replaces the N+1 pattern (one COUNT query and one ranked-entries query
	 * per section) with two batched queries total: one aggregate count, one
	 * windowed top-N. The big win is in generate_all_data, which iterates
	 * over potentially thousands of sections; for the common 20-section main
	 * page the overhead is negligible.
	 *
	 * @param array $sections list of section row-objects with section_q + property
	 * @param array $itemsByQ map of section_q (int) → WikidataItem (for the title)
	 * @return array list of populated section blocks (skipping sections whose item is missing)
	 */
	protected function populate_sections_batch(array $sections, array $itemsByQ, int $max = 25): array
	{
		if (empty($sections)) {
			return [];
		}
		$sectionQs = [];
		foreach ($sections as $s) {
			$sectionQs[] = (int) $s->section_q;
		}
		$sectionQs = array_values(array_unique($sectionQs));
		$qList = implode(",", $sectionQs);

		// Batched totals: one GROUP BY across all section_qs.
		$totals = [];
		$sql = "SELECT s.`section_q`, COUNT(DISTINCT v.`q`) AS `cnt`
			FROM `section` s
			JOIN `vw_ranked_entries_blacklist` v ON v.`q` = s.`item_q`
			WHERE s.`section_q` IN ({$qList})
			GROUP BY s.`section_q`";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$totals[(int) $o->section_q] = (int) $o->cnt;
		}
		$this->freeResult($result);

		// Batched top-N entries per section via ROW_NUMBER() window function.
		// Same ordering as vw_ranked_entries (sites DESC, minutes DESC, q).
		$entriesBySection = [];
		$max_safe = max(1, (int) $max);
		$sql = "SELECT * FROM (
			SELECT v.*, s.`section_q` AS `_bucket`,
				ROW_NUMBER() OVER (PARTITION BY s.`section_q` ORDER BY v.`sites` DESC, v.`minutes` DESC, v.`q`) AS `_rn`
			FROM `vw_ranked_entries_blacklist` v
			JOIN `section` s ON s.`item_q` = v.`q`
			WHERE s.`section_q` IN ({$qList})
		) ranked WHERE `_rn` <= {$max_safe}";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$bucket = (int) $o->_bucket;
			// Strip the helper columns so the row shape matches get_ranked_items().
			unset($o->_bucket, $o->_rn);
			$this->fix_item_image($o);
			$entriesBySection[$bucket][] = $o;
		}
		$this->freeResult($result);

		$out = [];
		foreach ($sections as $section) {
			$sq = (int) $section->section_q;
			if (!isset($itemsByQ[$sq])) {
				continue;
			}
			$out[] = [
				"q" => $section->section_q,
				"title" => $itemsByQ[$sq]->getLabel(),
				"prop" => $section->property,
				"total" => $totals[$sq] ?? 0,
				"entries" => $entriesBySection[$sq] ?? [],
			];
		}
		return $out;
	}

	public function search_sections($query): array
	{
		$ret = [];
		$query_safe = $this->db->real_escape_string(trim($query));
		if ($query_safe == "") {
			return $ret;
		} # Too broad a search
		$sql =
			"SELECT *,(SELECT `value` FROM `label` WHERE `language`='{$this->language}' AND `q`=`section_q`) AS `label` FROM `vw_section_property_q` WHERE `property` IN (" .
			implode(",", $this->config->misc_section_props) .
			") AND `section_q` IN (SELECT DISTINCT `q` FROM `label` WHERE `value` LIKE '%{$query_safe}%') LIMIT 50";
		// print "{$sql}\n";
		$result = $this->tfc->getSQL($this->db, $sql);
		$sections = [];
		while ($o = $result->fetch_object()) {
			$sections[] = $o;
		}
		$this->freeResult($result);

		$qs = [];
		foreach ($sections as $section) {
			$qs[] = $section->section_q;
		}
		$wil = new WikidataItemList();
		$wil->loadItems($qs);
		$itemsByQ = [];
		foreach ($qs as $q) {
			$it = $wil->getItem($q);
			if ($it !== null) {
				$itemsByQ[(int) $q] = $it;
			}
		}
		return $this->populate_sections_batch($sections, $itemsByQ);
	}

	public function search_entries($query): array
	{
		$ret = [];
		$query_safe = $this->db->real_escape_string(trim($query));
		if ($query_safe == "") {
			return $ret;
		} # Too broad a search
		$sql = "SELECT * FROM `vw_ranked_entries` WHERE `title` LIKE \"%{$query_safe}%\" LIMIT 50";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$this->fix_item_image($o);
			$ret[] = $o;
		}
		$this->freeResult($result);
		return $ret;
	}

	public function search_people($query): array
	{
		$ret = [];
		$query_safe = $this->db->real_escape_string(trim($query));
		if ($query_safe == "") {
			return $ret;
		} # Too broad a search
		$sql = "SELECT * FROM `person` WHERE `label` LIKE \"%{$query_safe}%\" LIMIT 50";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$ret[] = $o;
		}
		$this->freeResult($result);
		return $ret;
	}

	public function update_persons(): void
	{
		$sql =
			"SELECT DISTINCT `section_q` FROM `section` WHERE `property` IN (" .
			implode(",", $this->config->people_props) .
			") AND NOT EXISTS (SELECT 1 FROM `person` WHERE `person`.`q` = `section`.`section_q`)";
		$result = $this->tfc->getSQL($this->db, $sql);
		$qs = [];
		while ($o = $result->fetch_object()) {
			$qs[] = $o->section_q;
		}
		$this->freeResult($result);
		foreach (array_chunk($qs, 50) as $chunk) {
			$wil = new WikidataItemList();
			$wil->loadItems($chunk);
			$personRows = [];
			$itemsBatch = [];
			foreach ($chunk as $q) {
				$item = $wil->getItem($q);
				if (!isset($item)) {
					continue;
				}
				$label_safe = $this->db->real_escape_string($item->getLabel());
				$gender_safe = "?";
				if ($item->hasTarget("P21", self::WD_GENDER_MALE)) {
					$gender_safe = "M";
				}
				if ($item->hasTarget("P21", self::WD_GENDER_FEMALE)) {
					$gender_safe = "F";
				}
				$sites_safe = count($item->getSitelinks());
				$image = $item->getFirstString("P18");
				if (isset($image)) {
					$image_safe =
						'"' . $this->db->real_escape_string($image) . '"';
				} else {
					$image_safe = "null";
				}
				$personRows[] = "({$q},'{$label_safe}','{$gender_safe}',{$image_safe},$sites_safe)";
				$itemsBatch[] = $item;
			}
			if (empty($personRows) && empty($itemsBatch)) {
				continue;
			}
			$this->beginTransaction();
			try {
				if (!empty($personRows)) {
					$sql =
						"INSERT IGNORE INTO `person` (`q`,`label`,`gender`,`image`,`sites`) VALUES " .
						implode(",", $personRows);
					$this->tfc->getSQL($this->db, $sql);
				}
				$this->update_item_labels_batch($itemsBatch);
				$this->commit();
			} catch (\Throwable $e) {
				$this->rollback();
				throw $e;
			}
		}
	}

	public function generate_all_data(): void
	{
		$data = $this->get_main_page_data(PHP_INT_MAX, PHP_INT_MAX);
		$data = json_encode($data);
		$filename = __DIR__ . "/../public_html/all.json";
		file_put_contents($filename, $data);
	}

	public function generate_main_page_data(): void
	{
		$out = $this->get_main_page_data();
		$out = "var config = " . json_encode($out) . ";";
		$filename = __DIR__ . "/../public_html/config.js";
		file_put_contents($filename, $out);

		$sql =
			"SELECT (select count(q) from item) as items,(select count(id) FROM `file`) AS files,(select count(q) from person) as people";
		$result = $this->tfc->getSQL($this->db, $sql);
		if ($o = $result->fetch_object()) {
			$ts = $this->tfc->getCurrentTimestamp();
			$ts_safe = substr($ts, 0, 10); # Just the hour
			$sql = "REPLACE INTO `logging` (`timestamp`,`event`,`q`,`counter`) VALUES ('{$ts_safe}','total_items',0,{$o->items})";
			$this->tfc->getSQL($this->db, $sql);
			$sql = "REPLACE INTO `logging` (`timestamp`,`event`,`q`,`counter`) VALUES ('{$ts_safe}','total_files',0,{$o->files})";
			$this->tfc->getSQL($this->db, $sql);
			$sql = "REPLACE INTO `logging` (`timestamp`,`event`,`q`,`counter`) VALUES ('{$ts_safe}','total_people',0,{$o->people})";
			$this->tfc->getSQL($this->db, $sql);
		}
		$this->freeResult($result);
	}

	public function import_item_whitelist(): void
	{
		if ($this->config->whitelist_page == "") {
			return;
		}
		$wt = $this->tfc->getWikiPageText(
			"wikidatawiki",
			$this->config->whitelist_page,
		);
		$qs = [];
		foreach (explode("\n", $wt) as $row) {
			if (!preg_match("|^\*.*?(\d{3,})|", $row, $m)) {
				continue;
			}
			$qs[] = (int) $m[1];
		}
		if (count($qs) == 0) {
			return;
		}
		// Skip the prefetch: the whitelist page lists at most dozens of Qs,
		// so building a hashmap of every `item` row to filter them is
		// gratuitous. INSERT IGNORE silently no-ops on existing rows.
		$qs = array_values(array_unique($qs));
		$sql =
			"INSERT IGNORE INTO `item` (`q`) VALUES (" .
			implode("),(", $qs) .
			")";
		$this->tfc->getSQL($this->db, $sql);
	}

	public function import_item_blacklist(): void
	{
		# Delete old blacklist
		$sql = "TRUNCATE `blacklist`";
		$this->tfc->getSQL($this->db, $sql);

		if ($this->config->blacklist_page == "") {
			return;
		}

		# Get item list from blacklist page
		$qs = [];
		$wt = $this->tfc->getWikiPageText(
			"wikidatawiki",
			$this->config->blacklist_page,
		);
		$rows = explode("\n", $wt);
		foreach ($rows as $row) {
			if (!preg_match("|^\*.*?(\d{3,})|", $row, $m)) {
				continue;
			}
			$q = $m[1] * 1;
			$qs[] = $q;
		}
		if (count($qs) == 0) {
			return;
		}

		# Create new blacklist
		$sql =
			"INSERT IGNORE INTO `blacklist` (`q`) VALUES (" .
			implode("),(", $qs) .
			")";
		$this->tfc->getSQL($this->db, $sql);
	}

	public function reset_all(): void
	{
		// FK shape:
		//   section.item_q REFERENCES item.q
		//   file.item_q    REFERENCES item.q
		// Truncating the children (no incoming FKs of their own) is always
		// fine. For the parent `item`, some MariaDB versions reject TRUNCATE
		// on a table that any FK references — even when those children are
		// empty — so we briefly disable FK checks to use TRUNCATE here too
		// (faster than DELETE and resets AUTO_INCREMENT). finally{} restores
		// the session flag even if a query errors out.
		$sql = "SET FOREIGN_KEY_CHECKS=0";
		$this->tfc->getSQL($this->db, $sql);
		try {
			$sql = "TRUNCATE `section`";
			$this->tfc->getSQL($this->db, $sql);
			$sql = "TRUNCATE `file`";
			$this->tfc->getSQL($this->db, $sql);
			$sql = "TRUNCATE `item`";
			$this->tfc->getSQL($this->db, $sql);
		} finally {
			$sql = "SET FOREIGN_KEY_CHECKS=1";
			$this->tfc->getSQL($this->db, $sql);
		}
	}

	public function purge_items_without_files(): void
	{
		// NOT EXISTS form. Section must be cleared before item due to the
		// FK section.item_q → item.q.
		$this->beginTransaction();
		try {
			$sql = "DELETE FROM `section` WHERE NOT EXISTS (SELECT 1 FROM `file` WHERE `file`.`item_q` = `section`.`item_q`)";
			$this->tfc->getSQL($this->db, $sql);
			$sql = "DELETE FROM `item` WHERE NOT EXISTS (SELECT 1 FROM `file` WHERE `file`.`item_q` = `item`.`q`)";
			$this->tfc->getSQL($this->db, $sql);
			$this->commit();
		} catch (\Throwable $e) {
			$this->rollback();
			throw $e;
		}
	}

	private static function parse_seconds(string $s): int
	{
		$seconds = 0;
		if (preg_match('|^(\d+)[,:](\d+)[:\'](\d+)$|', $s, $m)) {
			$seconds = ($m[1] * 60 + $m[2]) * 60 + $m[3];
		} elseif (preg_match('|^(\d+):(\d+)$|', $s, $m)) {
			$seconds = $m[1] * 60 + $m[2];
		} elseif (preg_match('|^(\d+),(\d+)\'$|', $s, $m)) {
			$seconds = ($m[1] * 60 + $m[2]) * 60;
		} elseif (preg_match('|^(\d+[.0-9]*)$|', $s, $m)) {
			$seconds = $s * 1;
		} elseif (preg_match("|(\d+) *min (\d+) *sec|", $s, $m)) {
			$seconds = $m[1] * 60 + $m[2] * 1;
		} elseif (preg_match("|(\d+) *h (\d+) *m|", $s, $m)) {
			$seconds = ($m[1] * 60 + $m[2] * 1) * 60;
		} elseif (
			preg_match(
				"/(\d+[.0-9]*) *(min|minute|minutes|min\.)/i",
				$s,
				$m,
			)
		) {
			$seconds = $m[1] * 60;
		} else {
			error_log("Length: {$s}");
		}
		if ($seconds < 120) {
			$seconds = 0;
		} // Filter out some faulty parsing
		return round($seconds);
	}

	public function annotate_ia_movies(): void
	{
		ini_set("memory_limit", "4G");

		$sparql = "SELECT ?q ?ia {
			?q (wdt:P31/(wdt:P279*)) wd:Q11424 ; wdt:P724 ?ia . # A film with an Internet Archive value
			?q p:P724 ?statement .
			?statement ps:P724 ?ia .
			MINUS { ?statement pq:P2047 ?duration }
		}"; # LIMIT 1000
		$q2ia = [];
		foreach ($this->tfc->getSPARQL_TSV($sparql) as $row) {
			$q = $this->tfc->parseItemFromURL($row["q"]);
			$ia = $row["ia"];
			$q2ia[$q] = $ia;
		}

		$qs_commands = [];

		$wil = new WikidataItemList();
		$wil->loadItems(array_keys($q2ia));

		// Pre-filter to items where the matching IA claim actually exists, so
		// the batch fetch only includes URLs we'd act on.
		$toFetch = []; // q => url
		foreach ($q2ia as $q => $ia) {
			$item = $wil->getItem($q);
			if (!isset($item)) {
				continue;
			}
			$hasMatchingClaim = false;
			foreach ($item->getClaims("P724") as $c) {
				if (!isset($c->mainsnak)) {
					continue;
				}
				if ($c->mainsnak->datavalue->value == $ia) {
					$hasMatchingClaim = true;
					break;
				}
			}
			if (!$hasMatchingClaim) {
				continue;
			}
			$toFetch[$q] = "https://archive.org/metadata/{$ia}";
		}

		// Batch-fetch in chunks of 100 to bound memory (responses can be
		// MB-sized for movies with many files). Inside each chunk the HTTP
		// client runs `concurrency` requests in parallel.
		foreach (array_chunk($toFetch, 100, true) as $chunk) {
			$responses = $this->httpClient->getJsonBatch($chunk);
			foreach ($chunk as $q => $url) {
				$ia = $q2ia[$q];
				$j  = $responses[$q] ?? null;
				if (!isset($j)) {
					continue; // network/5xx after retries
				}

				if (isset($j->is_dark) && $j->is_dark) {
					$qs_commands[] = "-{$q}\tP724\t\"{$ia}\"\t/* File was removed from Internet Archive */";
					continue;
				}

				$minutes = null;
				if (isset($j->metadata) && isset($j->metadata->runtime)) {
					$seconds = self::parse_seconds($j->metadata->runtime);
					if ($seconds > 0) {
						$minutes = round($seconds / 60);
					}
				}
				if ($minutes === null && isset($j->files)) {
					foreach ($j->files as $file) {
						if (!isset($file->length)) {
							continue;
						}
						$seconds = self::parse_seconds($file->length);
						if ($seconds == 0) {
							continue;
						}
						if ($minutes === null) {
							$minutes = 0;
						}
						$minutes = max(round($seconds / 60), $minutes);
					}
				}

				if ($minutes !== null) {
					$qs_commands[] = "{$q}\tP724\t\"{$ia}\"\tP2047\t{$minutes}~1U7727\t/* Imported from Internet Archive */";
				}
			}
		}

		if (count($qs_commands) > 0) {
			require_once "/data/project/quickstatements/public_html/quickstatements.php";
			print "Running " . count($qs_commands) . " QS commands\n";
			$qs = $this->tfc->getQS("wikiflix", __DIR__ . "/../bot.ini");
			$this->tfc->runCommandsQS($qs_commands, $qs);
		}
	}

	public function update_item_no_files(): void
	{
		# Remove items where there is already one with a file
		$sql =
			"DELETE FROM `item_no_files` WHERE q IN (SELECT DISTINCT `item_q` FROM `file`)";
		$this->tfc->getSQL($this->db, $sql);

		# Get existing QIDs
		$existing_qs = [];
		$sql = "SELECT `q` FROM `item_no_files`";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$existing_qs["Q{$o->q}"] = $o->q;
		}
		$this->freeResult($result);

		# Get all candidate items
		$sparql = "SELECT DISTINCT ?q ?qLabel (year(?date) AS ?year) ?duration ?sitelinks {
					  ?q (wdt:P31/(wdt:P279*)) wd:Q11424 ; wdt:P6216 wd:Q19652 ; wikibase:sitelinks ?sitelinks .
					  MINUS { ?q wdt:P31 wd:Q97570383 } # Glass positive
					  MINUS { ?q wdt:P793 wd:Q1268687 } # Lost film
					  MINUS { ?q wdt:P12020 wd:Q122238711 } # Lost film
					  OPTIONAL { ?q wdt:P724 ?ia }
					  OPTIONAL { ?q wdt:P10 ?commons }
					  OPTIONAL { ?q wdt:P1651 ?youtube }
					  OPTIONAL { ?q wdt:P4015 ?vimeo }
					  OPTIONAL { ?q wdt:P11731 ?dailymotion }
					  BIND(BOUND(?ia)||BOUND(?commons)||BOUND(?youtube)||BOUND(?vimeo)||BOUND(?dailymotion) as ?hasMedia)
					  FILTER(?hasMedia=false)
					  OPTIONAL { ?q wdt:P577 ?date }
					  OPTIONAL { ?q wdt:P2047 ?duration }
					SERVICE wikibase:label { bd:serviceParam wikibase:language \"[AUTO_LANGUAGE],en,fr,it,de,mul\". }
		}";
		$to_insert = [];
		foreach ($this->tfc->getSPARQL_TSV($sparql) as $row) {
			$row = (object) $row;
			$q = $this->tfc->parseItemFromURL($row->q);
			if (isset($existing_qs[$q])) {
				continue;
			}
			// (int) cast — see update_from_sparql() above for context.
			$q_numeric = (int) preg_replace("|\D|", "", (string) $q);
			if ($q_numeric <= 0) {
				continue;
			}
			$existing_qs[$q] = $q_numeric;
			$year_safe       = $row->year     === "" ? "null" : (int) $row->year;
			$duration_safe   = $row->duration === "" ? "null" : (int) $row->duration;
			$sitelinks_safe  = (int) $row->sitelinks;
			$i = [
				$q_numeric,
				'"' . $this->db->real_escape_string($row->qLabel) . '"',
				$year_safe,
				$duration_safe,
				$sitelinks_safe,
			];
			$to_insert[] = "(" . implode(",", $i) . ")";
		}

		# Insert new items into database
		if (count($to_insert) > 0) {
			$sql =
				"INSERT IGNORE INTO `item_no_files` (`q`,`title`,`year`,`minutes`,`sites`) VALUES " .
				implode(",", $to_insert);
			$this->tfc->getSQL($this->db, $sql);
		}
	}

	private function get_json_from_url($url): ?object
	{
		return $this->httpClient->getJson($url);
	}

	private function search_internet_archive_via_imdb($q_numeric): array {
		$ret = [];
		$q = "Q{$q_numeric}";
		$wil = new WikidataItemList();
		$wil->loadItems([$q]);
		$item = $wil->getItem($q);
		if ( isset($item) ) {
			foreach ($item->getClaims("P345") as $c) {
				if ($c->rank == "deprecated") {
					continue;
				}
				if (
					!isset($c) or
					!isset($c->mainsnak) or
					!isset($c->mainsnak->datavalue)
				) {
					continue;
				}
				$imdb_id = $c->mainsnak->datavalue->value;
				$query = "external-identifier:\"urn:imdb:{$imdb_id}\"";
				$url =
					"https://archive.org/services/search/beta/page_production/?service_backend=metadata&user_query=" .
					urlencode($query) .
					"&page_type=collection_details&page_target=movies&hits_per_page=50&page=0";
				$j = $this->get_json_from_url($url);
				foreach ( $j->response->body->hits->hits as $hit ) {
					$ret[] = $hit->fields->identifier;
				}
			}
		}
		return $ret;
	}

	private function search_internet_archive_via_title_and_year($o): int {
		$query = "\"{$o->title}\"";
		if (isset($o->year)) {
			$query .= " {$o->year}";
		}
		$url =
			"https://archive.org/services/search/beta/page_production/?service_backend=metadata&user_query=" .
			urlencode($query) .
			"&page_type=collection_details&page_target=movies&hits_per_page=50&page=0";
		$j = $this->get_json_from_url($url);
		$hits = $j->response->body->hits->total * 1;
		return $hits;
	}

	public function update_item_no_files_search_results(): void
	{
		# Internet Archive
		$sql = "SELECT * FROM `item_no_files` WHERE `ia_results` IS NULL LIMIT 100";
		$result = $this->tfc->getSQL($this->db, $sql);
		// Buffer rows first so we can free the result set before issuing
		// per-row UPDATEs (each UPDATE goes through the same connection).
		$iaRows = [];
		while ($o = $result->fetch_object()) {
			$iaRows[] = $o;
		}
		$this->freeResult($result);
		foreach ($iaRows as $o) {
			if (trim($o->title) == '') continue;
			$hits = count($this->search_internet_archive_via_imdb($o->q));
			if ($hits == 0) $hits = $this->search_internet_archive_via_title_and_year($o);
			$sql = "UPDATE `item_no_files` SET `ia_results`={$hits} WHERE `q`={$o->q}";
			$this->tfc->getSQL($this->db, $sql);
			sleep(2);
		}

		# Commons — batched
		$sql =
			"SELECT * FROM `item_no_files` WHERE `commons_results` IS NULL LIMIT 100";
		$result = $this->tfc->getSQL($this->db, $sql);
		$urlByQ = [];
		$rowByQ = [];
		while ($o = $result->fetch_object()) {
			$query = "filetype:video \"{$o->title}\"";
			if (isset($o->year)) {
				$query .= " {$o->year}";
			}
			$urlByQ[(int) $o->q] = "https://commons.wikimedia.org/w/api.php?action=query&list=search&srnamespace=6&format=json&srsearch=" .
				urlencode($query);
			$rowByQ[(int) $o->q] = $o;
		}
		$this->freeResult($result);
		if (!empty($urlByQ)) {
			$responses = $this->httpClient->getJsonBatch($urlByQ);
			foreach ($urlByQ as $q => $_url) {
				$j = $responses[$q] ?? null;
				$hits = (isset($j) && isset($j->query->search) && is_array($j->query->search))
					? count($j->query->search)
					: 0;
				$sql = "UPDATE `item_no_files` SET `commons_results`={$hits} WHERE `q`={$q}";
				$this->tfc->getSQL($this->db, $sql);
			}
		}
	}

	public function get_candidate_items($limit, $offset): array
	{
		$ret = [];
		$limit *= 1;
		$offset *= 1;
		$sql = "SELECT * FROM `item_no_files` /*WHERE `ia_results` IS NOT null AND `commons_results` is not null*/ ORDER BY `sites` DESC LIMIT {$limit} OFFSET {$offset}";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$ret[] = $o;
		}
		$this->freeResult($result);
		return $ret;
	}

	public function get_total_candidate_items(): int
	{
		$ret = 0;
		$sql = "SELECT count(*) AS total FROM `item_no_files`";
		$result = $this->tfc->getSQL($this->db, $sql);
		if ($o = $result->fetch_object()) {
			$ret = (int) $o->total;
		}
		$this->freeResult($result);
		return $ret;
	}

	public function get_items_by_year($year): array
	{
		return $this->get_item_view(
			"vw_ranked_entries",
			PHP_INT_MAX,
			null,
			"SELECT q FROM item WHERE year={$year}",
		);
	}

	public function set_user_list_state($user_id, $q, $state): void
	{
		$user_id *= 1;
		$q *= 1;
		$state *= 1;
		if ($state == 0) {
			$sql = "DELETE FROM `user_item_list` WHERE `user_id`={$user_id} AND `q`={$q}";
		} else {
			$sql = "INSERT IGNORE INTO `user_item_list` (`user_id`,q) VALUES ({$user_id},{$q})";
		}
		$this->tfc->getSQL($this->db, $sql);
	}

	public function is_user_watching_item($user_id, $q): bool
	{
		$user_id *= 1;
		$q *= 1;
		$sql = "SELECT `id` FROM `user_item_list` WHERE `user_id`={$user_id} AND `q`={$q}";
		$result = $this->tfc->getSQL($this->db, $sql);
		$found = false;
		if ($o = $result->fetch_object()) {
			$found = true;
		}
		$this->freeResult($result);
		return $found;
	}

	public function clear_bad_genres(): void
	{
		if (!isset($this->config->bad_genres)) {
			return;
		}
		if (count($this->config->bad_genres) == 0) {
			return;
		}

		# Get items in bad genres
		$qs = [];
		$sql =
			"SELECT DISTINCT item_q FROM section WHERE section_q IN (" .
			implode(",", $this->config->bad_genres) .
			") AND property=136";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$qs[] = $o->item_q;
		}
		$this->freeResult($result);
		if (count($qs) == 0) {
			return;
		}

		$this->beginTransaction();
		try {
			# Clear files
			$sql = "DELETE FROM `file` WHERE item_q IN (" . implode(",", $qs) . ")";
			$this->tfc->getSQL($this->db, $sql);

			# Clear sections
			$sql =
				"DELETE FROM `section` WHERE item_q IN (" .
				implode(",", $qs) .
				") OR section_q IN (" .
				implode(",", $qs) .
				")";
			$this->tfc->getSQL($this->db, $sql);
			$sql =
				"DELETE FROM `section` WHERE section_q IN (" .
				implode(",", $this->config->bad_genres) .
				") AND property=136";
			$this->tfc->getSQL($this->db, $sql);

			# Clear items
			$sql = "DELETE FROM `item` WHERE q IN (" . implode(",", $qs) . ")";
			$this->tfc->getSQL($this->db, $sql);
			$this->commit();
		} catch (\Throwable $e) {
			$this->rollback();
			throw $e;
		}
	}

	// Returns the item for a property/file combination
	// Returns the first one found (there might be multiple, not good)
	// Returns 0 if not found
	public function getItemForFile($prop, $key): int
	{
		$prop_safe = $prop * 1;
		$key_safe = $this->db->real_escape_string($key);
		$sql = "SELECT `item_q` FROM `file` WHERE `property`={$prop_safe} AND `key`='{$key_safe}' LIMIT 1";
		$result = $this->tfc->getSQL($this->db, $sql);
		$item_q = 0;
		if ($o = $result->fetch_object()) {
			$item_q = (int) $o->item_q;
		}
		$this->freeResult($result);
		return $item_q;
	}

	public function logEvent($event, $q = null): void
	{
		$ts = $this->tfc->getCurrentTimestamp();
		$ts_safe = substr($ts, 0, 10); # Just the hour
		$event_safe = $this->db->real_escape_string($event);
		if (!isset($q) or $q == null) {
			$q_safe = "null";
		} else {
			$q_safe = $q * 1;
		}
		$sql = "INSERT INTO `logging` (`timestamp`,`event`,`q`,`counter`) VALUES ('{$ts_safe}','{$event_safe}',{$q_safe},1) ON DUPLICATE KEY UPDATE `counter`=`counter`+1";
		$this->tfc->getSQL($this->db, $sql);
	}
}

?>
