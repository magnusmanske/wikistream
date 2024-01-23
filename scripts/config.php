<?PHP


class WikiStreamConfig {

	function get_config_instance() {
		$config_file_name = __DIR__."/../config.json";
		if ( !file_exists($config_file_name) ) die ( "{$config_file_name} does not exists" ) ;
		$j = json_decode ( file_get_contents ( $config_file_name ) ) ;
		if ( isset($j->site_mode) ) {
			if ( $j->site_mode=='wikiflix' ) return new WikiStreamConfigWikiFlix;
			if ( $j->site_mode=='wikivibes' ) return new WikiStreamConfigWikiVibes;
		}
		die("Unknown site mode");
	}
}

class WikiStreamConfigWikiFlix extends WikiStreamConfig {
	public $toolkey = "wikiflix";
	public $tool_db = 'wikiflix_p';
	public $whitelist_page = 'Help:WikiFlix/Movie whitelist';
	public $sparql =  "SELECT ?q ?commons ?ia ?youtube ?vimeo {
		  ?q (wdt:P31/(wdt:P279*)) wd:Q11424 ; wdt:P6216 wd:Q19652 .
		  MINUS { ?q wdt:P31 wd:Q97570383 } # Glass positive
		  OPTIONAL { ?q wdt:P724 ?ia }
		  OPTIONAL { ?q wdt:P10 ?commons }
		  OPTIONAL { ?q wdt:P1651 ?youtube }
		  OPTIONAL { ?q wdt:P4015 ?vimeo }
		}";
	public $people_props = [161,57];
	public $misc_section_props = [31,166,136,462,495,364,361];
	public $grouping_props = [];
	public $file_props = [10,724,1651,4015];
	public $bad_sections = [11424];
	public $skip_section_q = [838368,226730];

	public function add_special_sections(&$ws,&$out) {
		$out['sections'][] = [
			'title' => "Female directors",
			'entries' => $this->get_items_by_female_directors($ws,25)
		];
	}


	protected function get_items_by_female_directors(&$ws,$num=25,$section_q=null) {
		return $ws->get_item_view('vw_item_by_female_directors',$num,$section_q);
	}

}

class WikiStreamConfigWikiVibes extends WikiStreamConfig {
	public $toolkey = "wikivibes";
	public $tool_db = 'vibes_p';
	public $whitelist_page = 'Help:WikiVibes/audio whitelist';
	public $sparql = "SELECT ?q ?file { ?q wdt:P51 ?file ; wdt:P31/wdt:P279* wd:Q2188189 }";
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
	public $skip_section_q = [838368,226730];

	public function add_special_sections(&$ws,&$out) {
		# Nothing
	}
}


?>