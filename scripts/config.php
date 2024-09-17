<?php class WikiStreamConfig
{
	/// Returns an instance of the appropriate config class, as per the config.js file in the tool root directory
	function get_config_instance()
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
			?q (wdt:P31/(wdt:P279*)) wd:Q11424 ; wdt:P6216 wd:Q19652 .
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
	];
	public $people_props = [161, 57];
	public $misc_section_props = [31, 166, 136, 462, 495, 364, 361];
	public $grouping_props = [];
	public $file_props = [10, 724, 1651, 4015, 11731];
	public $bad_sections = [11424];
	public $skip_section_q = [838368, 226730];
	public $interface_config = [
		"missing_icon" => "Missing-image-232x150.png",
		"toolname" => "wikiflix",
		"performer_prop" => "P161",
		"associated_people_props" => [57],
		"help_page" => "https://www.wikidata.org/wiki/Help:WikiFlix",
	];

	public function add_special_sections(&$ws, &$out)
	{
		$out["sections"][] = [
			"title" => "Female directors",
			"entries" => $this->get_items_by_female_directors($ws, 25),
		];
	}

	protected function get_items_by_female_directors(
		&$ws,
		$num = 25,
		$section_q = null
	) {
		return $ws->get_item_view(
			"vw_ranked_entries_blacklist",
			$num,
			$section_q,
			'SELECT DISTINCT `item_q` FROM `section`,`person` WHERE `property`=57 AND `person`.`q`=`section_q` AND `person`.`gender`="F"'
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
	];

	public function add_special_sections(&$ws, &$out)
	{
		# Nothing
	}
}

?>
