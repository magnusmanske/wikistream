<?php // $_SERVER['HOME'].
require_once __DIR__ . "/../public_html/php/ToolforgeCommon.php";
require_once __DIR__ . "/../public_html/php/wikidata.php";
require_once __DIR__ . "/../scripts/config.php";

class WikiStream
{
	public $tfc;
	public $language = "en";
	public $config;
	protected $db;

	public function __construct($config = null, $tfc = null)
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
	}

	public function getPerson($q, $add_files = true)
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
		}
		return $ret;
	}

	public function getEntry($q)
	{
		$ret = (object) [];
		$q *= 1;
		$sql = "SELECT * FROM `vw_ranked_entries` WHERE `q`={$q}";
		$result = $this->tfc->getSQL($this->db, $sql);
		if ($o = $result->fetch_object()) {
			$ret = $o;
		} else {
			return;
		} // Nothing

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
		while ($o = $result->fetch_object()) {
			if (in_array($o->property, $this->config->people_props)) {
				if (!isset($ret->people["P{$o->property}"])) {
					$ret->people["P{$o->property}"] = [];
				}
				$ret->people["P{$o->property}"][
					"Q{$o->section_q}"
				] = $this->getPerson($o->section_q, false);
			} else {
				$sections[] = $o;
				$to_load[] = $o->section_q;
			}
		}
		$wil = new WikidataItemList();
		$wil->loadItems($to_load);

		$ret->sections = [];
		foreach ($sections as $section) {
			$item = $wil->getItem($section->section_q);
			if (!isset($item)) {
				return;
			}
			$s = $this->populate_section($section, $item);
			if (isset($s) and $s != null) {
				$ret->sections[] = $s;
			}
		}

		return $ret;
	}

	protected function get_items_in_db()
	{
		$ret = [];
		$sql = "SELECT `q` FROM `item`";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$ret["Q{$o->q}"] = $o->q;
		}
		return $ret;
	}

	public function import_commons_video_minutes()
	{
		$sql = "SELECT * FROM `file` WHERE `property`=10 AND `minutes` IS NULL";
		$result = $this->tfc->getSQL($this->db, $sql);
		$minutes = ""; # Dummy
		while ($o = $result->fetch_object()) {
			unset($minutes);
			$url =
				"https://commons.wikimedia.org/w/api.php?action=query&format=json&prop=imageinfo&iiprop=metadata&&titles=File:" .
				urlencode($o->key);
			$j = $this->get_json_from_url($url);
			if (!isset($j) or !isset($j->query->pages)) {
				continue;
			} // TODO error message
			foreach ($j->query->pages as $page) {
				if (
					!isset($page) or
					!isset($page->imageinfo) or
					!isset($page->imageinfo[0]) or
					!isset($page->imageinfo[0]->metadata)
				) {
					continue;
				}
				foreach ($page->imageinfo[0]->metadata as $m) {
					if ($m->name == "playtime_seconds") {
						$minutes = round($m->value / 60);
					} elseif ($m->name == "playtime_minutes") {
						$minutes = round($m->value * 1);
					} elseif ($m->name == "length") {
						$minutes = round($m->value / 60);
					} elseif ($m->name == "duration") {
						$minutes = round($m->value / 60);
					}
				}
			}
			if (!isset($minutes)) {
				continue;
			}
			$sql = "UPDATE `file` SET `minutes`={$minutes} WHERE id={$o->id} AND `minutes` IS NULL";
			$this->tfc->getSQL($this->db, $sql);
		}
	}

	public function update_from_sparql()
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
				$q_numeric = preg_replace("|\D|", "", $q) * 1;
				$new_qs[] = $q_numeric;
				$found += 1;
			}
			// print "SPARQL #{$sparql_id} found {$found} items\n";
		}

		if (count($new_qs) == 0) {
			return;
		} # Nothing new on the western front
		$new_qs = array_unique($new_qs);
		print "Adding " . count($new_qs) . " new items\n";
		$sql =
			"INSERT IGNORE INTO `item` (`q`) VALUES (" .
			implode("),(", $new_qs) .
			")";
		$this->tfc->getSQL($this->db, $sql);
	}

	public function remove_unused_people()
	{
		$sql =
			"DELETE FROM person WHERE q NOT IN (SELECT DISTINCT section_q from section)";
		$this->tfc->getSQL($this->db, $sql);
	}

	protected function get_earliest_year($item, $property)
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
	) {
		$item = $wil->getItem($item_q_numeric);
		if (!isset($item)) {
			return;
		}

		# Sections
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
					throw new Exception("Bad genre");
				}
				$sections[] = "({$item_q_numeric},{$prop},{$target_q_numeric})";
			}
		}

		$qs[] = $item_q_numeric; # Only now, section filter might throw exception
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
						if ($qual->datavalue->value->id == "Q124428688") {
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
						if ($qual->datavalue->value->id == "Q622550") {
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
				"http://www.wikidata.org/entity/Q7727"
			) {
				$minutes_safe = $c->mainsnak->datavalue->value->amount * 1; # Minutes
			}
		}

		# Sites
		$sites_safe = count($item->getSitelinks());

		$ts_safe = $this->tfc->getCurrentTimestamp();

		$year_safe = $this->get_earliest_year($item, "P577");
		$title_safe = $this->db->real_escape_string($item->getLabel());
		$sql = "UPDATE `item` set `title`='{$title_safe}',`year`={$year_safe},`minutes`={$minutes_safe},`image`={$image_safe},`sites`={$sites_safe},`ts`='{$ts_safe}' WHERE `q`={$item_q_numeric}";
		$this->tfc->getSQL($this->db, $sql);

		$this->update_item_labels($item);
	}

	protected function add_item_details_chunk($chunk)
	{
		$wil = new WikidataItemList();
		$wil->loadItems($chunk);
		$qs = [];
		$sections = [];
		$entry_files = [];
		foreach ($chunk as $q_numeric) {
			try {
				$this->add_item_details(
					$wil,
					$q_numeric,
					$qs,
					$sections,
					$entry_files,
				);
			} catch (Exception $e) {
				// print "Filtered out {$q_numeric} for bad genre\n";
			}
		}
		if (count($qs) == 0) {
			return;
		}

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

		# Make item available
		$sql = "UPDATE `item` SET `available`=1 WHERE `q` IN ($qs)";
		$this->tfc->getSQL($this->db, $sql);
	}

	public function add_missing_item_details()
	{
		$sql = "SELECT `q` FROM `item` WHERE `available`=0";
		$result = $this->tfc->getSQL($this->db, $sql);
		$qs = [];
		while ($o = $result->fetch_object()) {
			$qs[] = $o->q;
		}
		if (count($qs) == 0) {
			return;
		} # Nothing to do
		foreach (array_chunk($qs, 50) as $chunk) {
			$this->add_item_details_chunk($chunk);
		}
	}

	public function make_rc_unavailable()
	{
		$last_rc_check = "";
		$sql = "SELECT `value` FROM `kv` WHERE `key`='last_rc_check'";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$last_rc_check = $o->value;
		}

		$qs_item = [];
		$sql = "SELECT `q` FROM `item`";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$qs_item[] = $o->q;
		}

		$qs_person = [];
		$sql = "SELECT `q` FROM `person`";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$qs_person[] = $o->q;
		}

		$qs = array_merge($qs_item, $qs_person);

		$dbwd = $this->tfc->openDBwiki("wikidatawiki");
		$sql =
			"SELECT `rc_title`,`rc_timestamp` FROM `recentchanges`
				WHERE `rc_namespace`=0 AND `rc_timestamp`>'{$last_rc_check}'
				AND `rc_title` IN ('Q" .
			implode("','Q", $qs) .
			"')";
		$result = $this->tfc->getSQL($dbwd, $sql);
		$qs = [];
		while ($o = $result->fetch_object()) {
			$qs[$o->rc_title] = preg_replace("|\D|", "", $o->rc_title) * 1;
			if ($last_rc_check < $o->rc_timestamp) {
				$last_rc_check = $o->rc_timestamp;
			}
		}

		if (count($qs) > 0) {
			$sql =
				"UPDATE `item` SET `available`=0 WHERE `q` IN (" .
				implode(",", $qs) .
				")";
			$this->tfc->getSQL($this->db, $sql);
			$sql =
				"DELETE FROM `person` WHERE `q` IN (" . implode(",", $qs) . ")";
			$this->tfc->getSQL($this->db, $sql);
		}

		$sql = "UPDATE `kv` SET `value`='{$last_rc_check}' WHERE `key`='last_rc_check'";
		$this->tfc->getSQL($this->db, $sql);
	}

	public function get_recently_added($num = 25, $section_q = null)
	{
		return $this->get_item_view("vw_recently_added", $num, $section_q);
	}

	public function get_ranked_items($num = 25, $section_q = null)
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
	) {
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
		return $ret;
	}

	protected function fix_item_image(&$o)
	{
		if (!isset($o->files)) {
			return $o;
		}
		$o->files = json_decode($o->files);
		foreach ($o->files as $vf) {
			if ($o->image == null and isset($vf->{'10'})) {
				$o->image = $vf->{'10'};
			}
		}
		return $o;
	}

	protected function update_item_labels($item)
	{
		$q = $item->getQ();
		$q_numeric = preg_replace("|\D|", "", $q);
		$sql = "DELETE FROM `label` WHERE `q`={$q_numeric}";
		$this->tfc->getSQL($this->db, $sql);
		$sql = [];
		foreach ($item->j->labels as $lang => $v) {
			$lang_safe = $this->db->real_escape_string($lang);
			$value_safe = $this->db->real_escape_string($v->value);
			$sql[] = "({$q_numeric},'{$lang_safe}','{$value_safe}')";
		}
		if (count($sql) > 0) {
			$sql =
				"INSERT IGNORE INTO `label` (`q`,`language`,`value`) VALUES " .
				implode(",", $sql);
			$this->tfc->getSQL($this->db, $sql);
		}
	}

	public function import_missing_section_labels()
	{
		$sql =
			"SELECT `section_q` AS `q` FROM `section` WHERE `section_q` NOT IN (SELECT DISTINCT `q` FROM label)";
		$sql .=
			" UNION SELECT `q` FROM `item` WHERE `q` NOT IN (SELECT DISTINCT `q` FROM label)";
		$sql .=
			" UNION SELECT `q` FROM `person` WHERE `q` NOT IN (SELECT DISTINCT `q` FROM label)";
		$result = $this->tfc->getSQL($this->db, $sql);
		$qs = [];
		while ($o = $result->fetch_object()) {
			$qs[] = $o->q * 1;
		}
		foreach (array_chunk($qs, 50) as $chunk) {
			$wil = new WikidataItemList();
			$wil->loadItems($chunk);
			foreach ($chunk as $q) {
				$item = $wil->getItem($q);
				if (!isset($item)) {
					continue;
				}
				$this->update_item_labels($item);
			}
		}
	}

	public function get_top_sections(
		$num = 20,
		$properties = [],
		$skip_section_q = null,
	) {
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
		return $ret;
	}

	public function get_random_sections(
		$num = 20,
		$properties = [],
		$skip_section_q = null,
	) {
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
		return $ret;
	}

	protected function get_year_stats()
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
		ksort($ret, SORT_NUMERIC);
		return $ret;
	}

	public function get_main_page_data(
		$max_movies_per_section = 25,
		$max_sections = 20,
	) {
		$out = ["status" => "OK"];
		$out["sections"] = [];
		$out["sections"][] = [
			"title_key" => "recently_edited",
			"entries" => $this->get_recently_added(25),
		];
		$out["sections"][] = [
			"title_key" => "highly_ranked",
			"entries" => $this->get_ranked_items(25),
		];
		$out["sections"][] = [
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
		foreach ($sections as $section) {
			$item = $wil->getItem($section->section_q);
			if (!isset($item)) {
				continue;
			}
			$out["sections"][] = $this->populate_section($section, $item);
		}

		$sql = "SELECT count(*) AS `cnt` FROM `item`";
		$result = $this->tfc->getSQL($this->db, $sql);
		if ($o = $result->fetch_object()) {
			$out["entry_total"] = $o->cnt;
		}

		$sql = "SELECT count(*) AS `cnt` FROM `person`";
		$result = $this->tfc->getSQL($this->db, $sql);
		if ($o = $result->fetch_object()) {
			$out["person_total"] = $o->cnt;
		}

		$out["section_total"] = count($this->get_top_sections(PHP_INT_MAX));

		$out["years"] = $this->get_year_stats();

		$out["misc"] = $this->config->interface_config;

		return $out;
	}

	public function populate_section($section, $item, $max = 25)
	{
		$title = $item->getLabel();
		$entries = $this->get_ranked_items(PHP_INT_MAX, $section->section_q);
		$total = count($entries);
		$entries = array_slice($entries, 0, $max);
		return [
			"q" => $section->section_q,
			"title" => $title,
			"prop" => $section->property,
			"total" => $total,
			"entries" => $entries,
		];
	}

	public function search_sections($query)
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

		$qs = [];
		foreach ($sections as $section) {
			$qs[] = $section->section_q;
		}
		$wil = new WikidataItemList();
		$wil->loadItems($qs);
		foreach ($sections as $section) {
			$item = $wil->getItem($section->section_q);
			if (!isset($item)) {
				continue;
			}
			$ret[] = $this->populate_section($section, $item);
		}

		return $ret;
	}

	public function search_entries($query)
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
		return $ret;
	}

	public function search_people($query)
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
		return $ret;
	}

	public function update_persons()
	{
		$sql =
			"SELECT DISTINCT `section_q` FROM `section` WHERE `property` IN (" .
			implode(",", $this->config->people_props) .
			") AND `section_q` NOT IN (SELECT `q` FROM `person`)";
		$result = $this->tfc->getSQL($this->db, $sql);
		$qs = [];
		while ($o = $result->fetch_object()) {
			$qs[] = $o->section_q;
		}
		foreach (array_chunk($qs, 50) as $chunk) {
			$wil = new WikidataItemList();
			$wil->loadItems($chunk);
			$sql = [];
			foreach ($chunk as $q) {
				$item = $wil->getItem($q);
				if (!isset($item)) {
					continue;
				}
				$label_safe = $this->db->real_escape_string($item->getLabel());
				$gender_safe = "?";
				if ($item->hasTarget("P21", "Q6581097")) {
					$gender_safe = "M";
				}
				if ($item->hasTarget("P21", "Q6581072")) {
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
				$sql[] = "({$q},'{$label_safe}','{$gender_safe}',{$image_safe},$sites_safe)";
				$this->update_item_labels($item);
			}
			$sql =
				"INSERT IGNORE INTO `person` (`q`,`label`,`gender`,`image`,`sites`) VALUES " .
				implode(",", $sql);
			$this->tfc->getSQL($this->db, $sql);
		}
	}

	public function generate_all_data()
	{
		$data = $this->get_main_page_data(PHP_INT_MAX, PHP_INT_MAX);
		$data = json_encode($data);
		$filename = __DIR__ . "/../public_html/all.json";
		file_put_contents($filename, $data);
	}

	public function generate_main_page_data()
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
	}

	public function import_item_whitelist()
	{
		if ($this->config->whitelist_page == "") {
			return;
		}
		$qs = [];
		$existing_item_qs = $this->get_items_in_db();
		$wt = $this->tfc->getWikiPageText(
			"wikidatawiki",
			$this->config->whitelist_page,
		);
		$rows = explode("\n", $wt);
		foreach ($rows as $row) {
			if (!preg_match("|^\*.*?(\d{3,})|", $row, $m)) {
				continue;
			}
			$q = $m[1] * 1;
			if (isset($existing_item_qs["Q" . $q])) {
				continue;
			}
			$qs[] = $q;
		}
		if (count($qs) == 0) {
			return;
		}
		$sql =
			"INSERT IGNORE INTO `item` (`q`) VALUES (" .
			implode("),(", $qs) .
			")";
		$this->tfc->getSQL($this->db, $sql);
	}

	public function import_item_blacklist()
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

	public function reset_all()
	{
		$sql = "TRUNCATE `section`";
		$this->tfc->getSQL($this->db, $sql);
		$sql = "TRUNCATE `file`";
		$this->tfc->getSQL($this->db, $sql);
		$sql = "DELETE FROM `item`";
		$this->tfc->getSQL($this->db, $sql);
	}

	public function purge_items_without_files()
	{
		$sql =
			"DELETE FROM section WHERE item_q NOT IN (SELECT item_q FROM `file`)";
		$this->tfc->getSQL($this->db, $sql);
		$sql = "DELETE FROM item WHERE q NOT IN (SELECT item_q FROM `file`)";
		$this->tfc->getSQL($this->db, $sql);
	}

	public function annotate_ia_movies()
	{
		ini_set("memory_limit", "4G");

		function parse_seconds($s)
		{
			$seconds = 0;
			if (preg_match('|^(\d+)[,:](\d+)[:\'](\d+)$|', $s, $m)) {
				$seconds = ($m[1] * 60 + $m[2]) * 60 + $m[3];
			} elseif (preg_match('|^(\d+):(\d+)$|', $s, $m)) {
				$seconds = $m[1] * 60 + $m[2];
			} elseif (preg_match('|^(\d+),(\d+)’$|', $s, $m)) {
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

		$userAgent =
			"Mozilla/5.0 (Windows NT 5.1; rv:31.0) Gecko/20100101 Firefox/31.0";

		$qs_commands = [];

		$wil = new WikidataItemList();
		$wil->loadItems(array_keys($q2ia));
		foreach ($q2ia as $q => $ia) {
			$item = $wil->getItem($q);
			if (!isset($item)) {
				continue;
			}
			// print "Item {$q}\n" ;

			$claims = $item->getClaims("P724");
			unset($claim);
			foreach ($claims as $c) {
				if (!isset($c->mainsnak)) {
					continue;
				} # Paranoia
				if ($c->mainsnak->datavalue->value == $ia) {
					$claim = $c;
				}
			}
			if (!isset($claim)) {
				// print "Can not find claim for {$ia} in {$q}\n";
				continue;
			}
			// print "Statement {$claim->id}\n";

			$url = "https://archive.org/metadata/{$ia}";
			// print "{$url}\n" ;

			$j = $this->get_json_from_url($url);
			if (isset($j->is_dark) and $j->is_dark) {
				// print "{$ia} is removed\n";
				$cmd = "-{$q}\tP724\t\"{$ia}\"\t/* File was removed from Internet Archive */";
				// print "{$cmd}\n";
				$qs_commands[] = $cmd;
				continue;
			}

			unset($minutes);
			if (isset($j->metadata) and isset($j->metadata->runtime)) {
				$seconds = parse_seconds($j->metadata->runtime);
				if ($seconds > 0) {
					$minutes = round($seconds / 60);
				}
			}
			if (!isset($minutes) and isset($j->files)) {
				foreach ($j->files as $file) {
					if (!isset($file->length)) {
						continue;
					}
					$seconds = parse_seconds($file->length);
					if ($seconds == 0) {
						continue;
					}
					if (!isset($minutes)) {
						$minutes = 0;
					}
					$minutes = max(round($seconds / 60), $minutes);
				}
			}

			if (isset($minutes)) {
				$cmd = "{$q}\tP724\t\"{$ia}\"\tP2047\t{$minutes}~1U7727\t/* Imported from Internet Archive */";
				// print "{$cmd}\n";
				$qs_commands[] = $cmd;
			} else {
				// print "No runtime in IA for {$q} / {$ia}\n";
			}
		}

		if (count($qs_commands) > 0) {
			require_once "/data/project/quickstatements/public_html/quickstatements.php";
			// print join("\n",$qs_commands)."\n";
			print "Running " . count($qs_commands) . " QS commands\n";
			$qs = $this->tfc->getQS("wikiflix", __DIR__ . "/../bot.ini");
			$this->tfc->runCommandsQS($qs_commands, $qs);
		}
	}

	public function update_item_no_files()
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
					SERVICE wikibase:label { bd:serviceParam wikibase:language \"[AUTO_LANGUAGE],en,fr,it,de\". }
		}";
		$to_insert = [];
		foreach ($this->tfc->getSPARQL_TSV($sparql) as $row) {
			$row = (object) $row;
			$q = $this->tfc->parseItemFromURL($row->q);
			if (isset($existing_qs[$q])) {
				continue;
			}
			$q_numeric = preg_replace("|\D|", "", $q) * 1;
			$existing_qs[$q] = $q_numeric;
			$i = [
				$q_numeric, # Safe
				'"' . $this->db->real_escape_string($row->qLabel) . '"', # Safe
				$row->year == "" ? "null" : $row->year * 1, # Safe
				$row->duration == "" ? "null" : $row->duration * 1, # Safe
				$row->sitelinks * 1, # Safe
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

	function get_json_from_url($url)
	{
		$userAgent =
			"Mozilla/5.0 (Windows NT 5.1; rv:31.0) Gecko/20100101 Firefox/31.0";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
		$j = json_decode(curl_exec($ch));
		return $j;
	}

	public function update_item_no_files_search_results()
	{
		$userAgent =
			"Mozilla/5.0 (Windows NT 5.1; rv:31.0) Gecko/20100101 Firefox/31.0";

		# Internet Archive
		if (true) {
			$sql = "SELECT * FROM `item_no_files` WHERE `ia_results` IS NULL";
			$result = $this->tfc->getSQL($this->db, $sql);
			while ($o = $result->fetch_object()) {
				$query = $o->title;
				if (isset($o->year)) {
					$query .= " {$o->year}";
				}
				$url =
					"https://archive.org/services/search/beta/page_production/?service_backend=metadata&user_query=" .
					urlencode($query) .
					"&page_type=collection_details&page_target=movies&hits_per_page=50&page=0";
				$j = $this->get_json_from_url($url);
				$hits = $j->response->body->hits->total * 1;
				$sql = "UPDATE `item_no_files` SET `ia_results`={$hits} WHERE `q`={$o->q}";
				$this->tfc->getSQL($this->db, $sql);
				sleep(2);
			}
		}

		# Commons TODO
		if (true) {
			$sql =
				"SELECT * FROM `item_no_files` WHERE `commons_results` IS NULL";
			$result = $this->tfc->getSQL($this->db, $sql);
			while ($o = $result->fetch_object()) {
				$query = "filetype:video \"{$o->title}\"";
				if (isset($o->year)) {
					$query .= " {$o->year}";
				}
				$url =
					"https://commons.wikimedia.org/w/api.php?action=query&list=search&srnamespace=6&format=json&srsearch=" .
					urlencode($query);
				$j = $this->get_json_from_url($url);
				$hits = count($j->query->search);
				$sql = "UPDATE `item_no_files` SET `commons_results`={$hits} WHERE `q`={$o->q}";
				$this->tfc->getSQL($this->db, $sql);
				// print "{$sql}\n" ;
			}
		}
	}

	public function get_candidate_items($limit, $offset)
	{
		$ret = [];
		$limit *= 1;
		$offset *= 1;
		$sql = "SELECT * FROM `item_no_files` WHERE `ia_results` IS NOT null AND `commons_results` is not null ORDER BY `sites` DESC LIMIT {$limit} OFFSET {$offset}";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$ret[] = $o;
		}
		return $ret;
	}

	public function get_total_candidate_items()
	{
		$ret = 0;
		$sql = "SELECT count(*) AS total FROM `item_no_files`";
		$result = $this->tfc->getSQL($this->db, $sql);
		if ($o = $result->fetch_object()) {
			$ret = $o->total;
		}
		return $ret;
	}

	public function get_items_by_year($year)
	{
		return $this->get_item_view(
			"vw_ranked_entries",
			PHP_INT_MAX,
			null,
			"SELECT q FROM item WHERE year={$year}",
		);
	}

	public function set_user_list_state($user_id, $q, $state)
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

	public function is_user_watching_item($user_id, $q)
	{
		$user_id *= 1;
		$q *= 1;
		$sql = "SELECT `id` FROM `user_item_list` WHERE `user_id`={$user_id} AND `q`={$q}";
		$result = $this->tfc->getSQL($this->db, $sql);
		if ($o = $result->fetch_object()) {
			return true;
		}
		return false;
	}

	public function clear_bad_genres()
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
		if (count($qs) == 0) {
			return;
		}

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
	}

	// Returns the item for a property/file combination
	// Returns the first one found (there might be multiple, not good)
	// Returns 0 if not found
	public function getItemForFile($prop, $key)
	{
		$prop_safe = $prop * 1;
		$key_safe = $this->db->real_escape_string($key);
		$sql = "SELECT `item_q` FROM `file` WHERE `property`={$prop_safe} AND `key`='{$key_safe}' LIMIT 1";
		$result = $this->tfc->getSQL($this->db, $sql);
		if ($o = $result->fetch_object()) {
			return $o->item_q;
		}
		return 0;
	}

	public function logEvent($event, $q = null)
	{
		$ts = $this->tfc->getCurrentTimestamp();
		$ts_safe = substr($ts, 0, 10); # Just the hour
		$event_safe = $this->db->real_escape_string($event);
		if (!isset($q) or $q == null) {
			$q_safe = "null";
		} else {
			$q_safe = $q * 1;
		}
		$sql = "INSERT INTO `logging` (`timestamp`,`event`,`q`,`counter`) VALUES ('{$ts_safe}','{$event_safe}',{$q},1) ON DUPLICATE KEY UPDATE `counter`=`counter`+1";
		$this->tfc->getSQL($this->db, $sql);
	}
}

?>
