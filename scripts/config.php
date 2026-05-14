<?php class WikiStreamConfig
{
	# Master switch for episode/track-style ingestion. When false (the
	# default), $episode_sparql is ignored entirely. Toggling this off
	# stops *new* episodes from being discovered but does not purge any
	# already-ingested rows — that's a separate operation.
	public $include_episodes = false;

	# SPARQL queries that discover episode-like items (TV episodes,
	# tracks, movements). Run alongside $sparql, but only when
	# $include_episodes is true. Kept separate so the toggle is a
	# one-liner and the queries can be reviewed independently.
	public $episode_sparql = [];

	# Property linking an item to its parent group (e.g. P179 series for
	# WikiFlix episodes, P361 part-of for WikiVibes tracks). 0 disables
	# group ingestion. Independent from $include_episodes — films can
	# also belong to a film series.
	public $group_membership_prop = 0;

	# Statement qualifier on the group_membership_prop claim carrying
	# the item's position within the group (P1545 series ordinal). 0
	# means positions are not recorded.
	public $group_position_qualifier = 0;

	# Property identifying a sub-grouping of items within their parent
	# group — e.g. P4908 ("season") for TV episodes. The mainsnak target
	# Q-number is stored in `group_item.subgroup` (as the numeric string
	# "112174548") so the frontend can render episodes grouped by
	# season. 0 disables subgroup ingestion.
	public $group_subgroup_prop = 0;

	# Q-numbers whose presence as the item's primary P31 marks the item
	# as an "episode" for UI purposes (drives the thumbnail badge). Also
	# used during ingestion to prefer the episode P31 when an item has
	# multiple instance-of claims.
	public $episode_type_qs = [];

	/// Returns an instance of the appropriate config class, as per the config.js file in the tool root directory
	function get_config_instance(): self
	{
		$config_file_name = __DIR__ . "/../config.json";
		if (!file_exists($config_file_name)) {
			die("{$config_file_name} does not exists");
		}
		$j = json_decode(file_get_contents($config_file_name));
		if (isset($j->site_mode)) {
			if ($j->site_mode == "wikiflix") {
				return new WikiStreamConfigWikiFlix();
			}
			if ($j->site_mode == "wikivibes") {
				return new WikiStreamConfigWikiVibes();
			}
		}
		die("Unknown site mode");
	}

	/**
	 * Tool-specific dispatch for WikiStream::get_special_entries(). Override
	 * in a subclass to add custom pseudo-section keys (e.g. "female_directors"
	 * in WikiFlix). The base implementation returns an empty page.
	 *
	 * Returns ['entries' => list, 'total' => int]. `total` is the total count
	 * of entries available for this key (independent of offset/limit).
	 */
	public function get_special_entries(&$ws, string $key, int $offset = 0, int $limit = PHP_INT_MAX): array
	{
		return ['entries' => [], 'total' => 0];
	}
}

class WikiStreamConfigWikiFlix extends WikiStreamConfig
{
	public $toolkey = "wikiflix";
	public $tool_db = "wikiflix_p";
	public $whitelist_page = ""; # 'Help:WikiFlix/Movie whitelist';
	public $blacklist_page = "Help:WikiFlix/Movie blacklist";
	public $bad_genres = [185529, 4373044, 3461143, 599558]; # P136
	public $sparql = [
		"SELECT DISTINCT ?q {
			?q (wdt:P31/(wdt:P279*)) wd:Q11424 ; wdt:P6216/(wdt:P279*) wd:Q19652 .
			# P279* below Q19652 also catches PD-equivalent subclasses
			# like Q88088423 (copyrighted, dedicated to the public domain
			# by copyright holder). The base case wdt:P6216 wd:Q19652 is
			# still matched because P279* allows zero hops.
			MINUS { ?q wdt:P31 wd:Q97570383 } # Glass positive
			OPTIONAL { ?q wdt:P724 ?ia }
			OPTIONAL { ?q wdt:P10 ?commons }
			OPTIONAL { ?q wdt:P1651 ?youtube }
			OPTIONAL { ?q wdt:P4015 ?vimeo }
			OPTIONAL { ?q wdt:P11731 ?dailymotion }
			BIND(BOUND(?ia)||BOUND(?commons)||BOUND(?youtube)||BOUND(?vimeo)||BOUND(?dailymotion) as ?hasMedia)
			FILTER(?hasMedia=true)
		}",
		"SELECT DISTINCT ?q {
			?q (wdt:P31/(wdt:P279*)) wd:Q11424 . # A film
			?q p:P10 ?statement . # Commons video
			?statement ps:P10 ?commons . # The video ID (not used here)
			?statement pq:P3831 wd:Q89347362 # full video
			MINUS {?q wdt:P6216 wd:Q19652 } # but don't bother with the public domain ones
		}",
		"SELECT DISTINCT ?q {
			?q (wdt:P31/(wdt:P279*)) wd:Q11424 . # A film
			?q wdt:P2047 ?duration . # with a duration
			?q p:P724 ?statement . # with an Internet Archive ID
			MINUS { ?statement pq:P11484 wd:Q124428688 } . # Without 'do not use for WikiFlix'
			?statement ps:P724 ?ia .
			?statement pq:P2047 ?ia_duration . # that also has a duration
			BIND(ABS(?ia_duration/?duration*100) AS ?percent)
			FILTER(?percent>=60 && ?percent<=150) # that is similar to the item duration
			?q wdt:P577 ?date . FILTER (year(?date)<=1928) # 1928 or earlier
		}",
		# Films licensed under any Creative Commons licence except CC-*-ND.
		# A film qualifies when at least one P275 statement points to a CC
		# licence (instance of Q284742). The ND exclusion is dynamic: any
		# P275 value whose English label contains "noderiv" disqualifies
		# the film, which covers BY-ND and BY-NC-ND across all versions
		# and jurisdictional variants without needing a hand-maintained
		# Q-ID list. CC-*-NC variants are intentionally allowed.
		"SELECT DISTINCT ?q {
			?q (wdt:P31/(wdt:P279*)) wd:Q11424 ; # A film
			   wdt:P275 ?lic .                   # with a licence claim
			?lic wdt:P31 wd:Q284742 .            # licence is a Creative Commons licence
			MINUS { ?q wdt:P31 wd:Q97570383 } # Glass positive
			MINUS {
				?q wdt:P275 ?nd_lic .
				?nd_lic rdfs:label ?nd_label .
				FILTER(LANG(?nd_label) = \"en\")
				FILTER(CONTAINS(LCASE(?nd_label), \"noderiv\"))
			}
			OPTIONAL { ?q wdt:P724 ?ia }
			OPTIONAL { ?q wdt:P10 ?commons }
			OPTIONAL { ?q wdt:P1651 ?youtube }
			OPTIONAL { ?q wdt:P4015 ?vimeo }
			OPTIONAL { ?q wdt:P11731 ?dailymotion }
			BIND(BOUND(?ia)||BOUND(?commons)||BOUND(?youtube)||BOUND(?vimeo)||BOUND(?dailymotion) as ?hasMedia)
			FILTER(?hasMedia=true)
		}",
	];
	public $people_props = [161, 57];
	public $misc_section_props = [31, 166, 136, 462, 495, 364, 361];
	public $grouping_props = [];
	public $file_props = [10, 724, 1651, 4015, 11731];
	public $bad_sections = [11424];
	public $skip_section_q = [838368, 226730];

	# Episode ingestion (GitHub issue #5). Episodes are matched as
	# P31/P279* descendants of Q21191270 ("television series episode")
	# and admitted under the same media + PD/CC rules as films. Series
	# membership (P179) plus its P1545 ordinal qualifier populate the
	# `group` / `group_item` tables.
	public $include_episodes = true;
	public $group_membership_prop = 179;
	public $group_position_qualifier = 1545;
	public $group_subgroup_prop = 4908; # season
	public $episode_type_qs = [21191270]; # television series episode
	public $episode_sparql = [
		# Public-domain TV episodes with playable media. The structural
		# constraints mirror SPARQL #1 in $sparql but anchor on
		# Q21191270 (P279*) so subclasses (anime episode etc.) qualify.
		"SELECT DISTINCT ?q {
			?q (wdt:P31/(wdt:P279*)) wd:Q21191270 ; wdt:P6216/(wdt:P279*) wd:Q19652 .
			OPTIONAL { ?q wdt:P724 ?ia }
			OPTIONAL { ?q wdt:P10 ?commons }
			OPTIONAL { ?q wdt:P1651 ?youtube }
			OPTIONAL { ?q wdt:P4015 ?vimeo }
			OPTIONAL { ?q wdt:P11731 ?dailymotion }
			BIND(BOUND(?ia)||BOUND(?commons)||BOUND(?youtube)||BOUND(?vimeo)||BOUND(?dailymotion) as ?hasMedia)
			FILTER(?hasMedia=true)
		}",
		# CC-licensed (except ND) TV episodes with playable media.
		# Same ND exclusion logic as SPARQL #4 in $sparql.
		"SELECT DISTINCT ?q {
			?q (wdt:P31/(wdt:P279*)) wd:Q21191270 ;
			   wdt:P275 ?lic .
			?lic wdt:P31 wd:Q284742 .
			MINUS {
				?q wdt:P275 ?nd_lic .
				?nd_lic rdfs:label ?nd_label .
				FILTER(LANG(?nd_label) = \"en\")
				FILTER(CONTAINS(LCASE(?nd_label), \"noderiv\"))
			}
			OPTIONAL { ?q wdt:P724 ?ia }
			OPTIONAL { ?q wdt:P10 ?commons }
			OPTIONAL { ?q wdt:P1651 ?youtube }
			OPTIONAL { ?q wdt:P4015 ?vimeo }
			OPTIONAL { ?q wdt:P11731 ?dailymotion }
			BIND(BOUND(?ia)||BOUND(?commons)||BOUND(?youtube)||BOUND(?vimeo)||BOUND(?dailymotion) as ?hasMedia)
			FILTER(?hasMedia=true)
		}",
	];

	public $interface_config = [
		"missing_icon" => "Missing-image-232x150.png",
		"toolname" => "wikiflix",
		"performer_prop" => "P161",
		"associated_people_props" => [57],
		"help_page" => "https://www.wikidata.org/wiki/Help:WikiFlix",
		"episode_type_qs" => [21191270],
	];

	public function add_special_sections(&$ws, &$out): void
	{
		$out["sections"][] = [
			"key" => "female_directors",
			"title_key" => "female_directors",
			"title" => "Female directors",
			"entries" => $this->get_items_by_female_directors($ws, 25),
		];
	}

	const FEMALE_DIRECTORS_SUBQUERY = 'SELECT DISTINCT `item_q` FROM `section`,`person` WHERE `property`=57 AND `person`.`q`=`section_q` AND `person`.`gender`="F"';

	public function get_special_entries(&$ws, string $key, int $offset = 0, int $limit = PHP_INT_MAX): array
	{
		switch ($key) {
			case "female_directors":
				return [
					'entries' => $ws->get_item_view(
						"vw_ranked_entries_blacklist",
						$limit,
						null,
						self::FEMALE_DIRECTORS_SUBQUERY,
						$offset,
					),
					'total' => $ws->get_item_view_count(
						"vw_ranked_entries_blacklist",
						null,
						self::FEMALE_DIRECTORS_SUBQUERY,
					),
				];
		}
		return ['entries' => [], 'total' => 0];
	}

	/**
	 * Back-compat helper for older main-page callers that just want a single
	 * page of female-director entries.
	 */
	protected function get_items_by_female_directors(
		&$ws,
		$num = 25,
		$section_q = null
	): array {
		return $ws->get_item_view(
			"vw_ranked_entries_blacklist",
			$num,
			$section_q,
			self::FEMALE_DIRECTORS_SUBQUERY,
		);
	}
}

class WikiStreamConfigWikiVibes extends WikiStreamConfig
{
	public $toolkey = "wikivibes";
	public $tool_db = "vibes_p";
	public $whitelist_page = ""; # 'Help:WikiVibes/audio whitelist';
	public $blacklist_page = "";
	public $bad_genres = []; # P136
	public $sparql = [
		"SELECT ?q ?file { ?q wdt:P51 ?file ; wdt:P31/wdt:P279* wd:Q2188189 }",
	];
	public $people_props = [
		50, # author
		86, # composer
		175, # performer
		676, # lyrics by
		87, # librettist
		10806, # orchestrator
	];
	public $misc_section_props = [
		31, # instance of
		7937, # form of creative work
		826, # tonailty
		870, # instrumentation
		155, # follows
		156, # followed by
		407, # language of work or name
	];
	public $grouping_props = [361];
	public $file_props = [51];
	public $bad_sections = [105543609];
	public $skip_section_q = [838368, 226730];
	public $interface_config = [
		"missing_icon" => "Missing-image-232x150.png",
		"toolname" => "wikivibes",
		"performer_prop" => "P175",
		"associated_people_props" => [],
		"help_page" => "https://www.wikidata.org/wiki/Help:WikiVibes",
		"episode_type_qs" => [],
	];

	public function add_special_sections(&$ws, &$out): void
	{
		# Nothing
	}
}

?>
