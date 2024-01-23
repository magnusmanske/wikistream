<?PHP

require_once ( '/data/project/wikivibes/public_html/php/ToolforgeCommon.php' ) ;
require_once ( '/data/project/wikivibes/public_html/php/wikidata.php' ) ;

class WikiVibes {
	public $tfc;
	public $language = 'en';
	protected $db;
	protected $people_props = [
		50, # author
		86, # composer
		175, # performer
		676, # lyrics by
		87, # librettist
		10806, # orchestrator
	];
	protected $misc_section_props = [
		31, # instance of
		7937, # form of creative work
		826, # tonailty
		870, # instrumentation
		155, # follows
		156, # followed by
		407, # language of work or name
	];
	protected $bad_sections = [105543609];

	public function __construct() {
		$this->tfc = new ToolforgeCommon('duplicity') ;
		$this->db = $this->tfc->openDBtool ( 'vibes_p' ) ;
	}

	public function getPerson($q,$add_audio=true) {
		$ret = (object)['q'=>$q,'entries'=>[]];
		$q *= 1 ;

		$sql = "SELECT * FROM `person` WHERE `q`={$q}" ;
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		if($o = $result->fetch_object()) {
			$ret->label = $o->label ;
			$ret->gender = $o->gender ;
			$ret->image = $o->image ;
		}

		if ( $add_audio ) {
			$sql = "SELECT * FROM `vw_ranked_entries` WHERE `q` IN (SELECT DISTINCT `audio_q` FROM `section` WHERE `property` IN (".implode(',',$this->people_props).") AND `section_q`={$q})";
			$result = $this->tfc->getSQL ( $this->db , $sql ) ;
			while($o = $result->fetch_object()) {
				$this->fix_audio_image($o);
				$ret->entries[] = $o ;
			}
		}
		return $ret ;
	}

	public function getEntry($q) {
		$ret = (object)[];
		$q *= 1 ;
		$sql = "SELECT * FROM `vw_ranked_entries` WHERE `q`={$q}";
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		if($o = $result->fetch_object()) $ret = $o ;
		else return; // Nothing

		$o->entry_files = json_decode($o->files);

		$sql = "SELECT * FROM `section` WHERE `audio_q`={$q}" ;
		if ( count($this->bad_sections)>0 ) $sql .= " AND `section_q` NOT IN (".implode($this->bad_sections).")";
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		$sections = [];
		$to_load = [] ;
		$ret->people = [];
		while($o = $result->fetch_object()) {
			if ( in_array($o->property, $this->people_props) ) {
				if ( !isset($ret->people["P{$o->property}"]) ) $ret->people["P{$o->property}"] = [];
				$ret->people["P{$o->property}"]["Q{$o->section_q}"] = $this->getPerson($o->section_q,false);
			} else {
				$sections[] = $o ;
				$to_load[] = $o->section_q;
			}
		}
		$wil = new WikidataItemList();
		$wil->loadItems($to_load);

		$ret->sections = [];
		foreach ( $sections AS $section ) {
			$item = $wil->getItem($section->section_q);
			if ( !isset($item) ) return;
			$s = $this->populate_section ( $section , $item ) ;
			if ( isset($s) and $s!=null ) $ret->sections[] = $s ;
		}

		return $ret ;
	}

	protected function get_items_in_db() {
		$ret = [] ;
		$sql = "SELECT `q` FROM `audio`" ;
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		while($o = $result->fetch_object()) $ret["Q{$o->q}"] = $o->q ;
		return $ret ;
	}

	public function update_from_sparql() {
		$new_qs = [] ;
		$existing_qs = $this->get_items_in_db();

		# All entries with a file on Commons
		$sparql = "SELECT ?q ?file { ?q wdt:P51 ?file ; wdt:P31/wdt:P279* wd:Q2188189 }";
		foreach ( $this->tfc->getSPARQL_TSV($sparql) AS $row ) {
			$row = (object) $row;
			$q = $this->tfc->parseItemFromURL($row->q);
			if ( isset($existing_qs[$q]) ) continue;
			$q_numeric = preg_replace('|\D|','',$q)*1 ;
			$new_qs[] = $q_numeric;
		}

		if ( count($new_qs)==0 ) return ; # Nothing new on the western front
		$new_qs = array_unique($new_qs);
		$sql = "INSERT IGNORE INTO `audio` (`q`) VALUES (".implode("),(",$new_qs).")" ;
		$this->tfc->getSQL ( $this->db , $sql ) ;
	}

	protected function get_earliest_year ( $item , $property ) {
		$years = [];
		foreach ( $item->getClaims($property) AS $c ) {
			if ( $c->rank=='deprecated' ) continue;
			if ( !isset($c) or !isset($c->mainsnak) or !isset($c->mainsnak->datavalue) ) continue;
			if ( preg_match('|^\+(\d{4})|',$c->mainsnak->datavalue->value->time,$m) ) $years[] = $m[1]*1;
		}
		if ( count($years) == 0 ) return 'null';
		sort($years,SORT_NUMERIC);
		return $years[0];
	}

	protected function add_audio_details($wil,$audio_q_numeric,&$qs,&$sections,&$entry_files) {
		$item = $wil->getItem($audio_q_numeric);
		if ( !isset($item) ) return;
		$qs[] = $audio_q_numeric;

		# Sections
		foreach ( array_merge($this->misc_section_props,$this->people_props,[361]) AS $prop ) {
			foreach ( $item->getClaims($prop) AS $claim ) {
				$target_q = $item->getTarget($claim);
				$target_q_numeric = preg_replace ( '/\D/' , '' , $target_q );
				if ( !$target_q ) continue;
				if ( in_array($target_q_numeric,$this->bad_sections) ) continue;
				$sections[] = "({$audio_q_numeric},{$prop},{$target_q_numeric})";
			}
		}

		# files
		foreach ( [51] AS $property ) {
			foreach ( $item->getClaims($property) AS $c ) {
				if ( !isset($c->mainsnak) ) continue ;
				if ( !isset($c->mainsnak->datavalue) ) continue ;
				if ( !isset($c->mainsnak->datavalue->value) ) continue ;
				if ( !isset($c->mainsnak->datavalue->type) ) continue ;
				if ( $c->mainsnak->datavalue->type != 'string' ) continue ;
				$key = $c->mainsnak->datavalue->value ;
				$key_safe = $this->db->real_escape_string($key);

				$is_trailer_safe = 0;
				if ( isset($c->qualifiers) and isset($c->qualifiers->P3831) ) {
					foreach ( $c->qualifiers->P3831 AS $qual ) {
						if ( $qual->datavalue->value->id=='Q89347362' ) $is_trailer_safe = 1;
					}
				}

				$entry_files[] = "({$audio_q_numeric},{$property},'{$key_safe}',{$is_trailer_safe})";
			}
		}

		$image = $item->getFirstString('P18');
		if ( $image=='' ) $image_safe = 'null';
		else $image_safe = '"'.$this->db->real_escape_string($image).'"';

		# Duration
		$minutes_safe = 'null';
		foreach ( $item->getClaims('P2047') AS $c ) {
			if ( $c->rank=='deprecated' ) continue;
			if ( $c->mainsnak->datavalue->value->unit == 'http://www.wikidata.org/entity/Q7727' ) {
				$minutes_safe = $c->mainsnak->datavalue->value->amount*1; # Minutes
			}
		}

		# Sites
		$sites_safe = count($item->getSitelinks());

		$ts_safe = $this->tfc->getCurrentTimestamp();

		$year_safe = $this->get_earliest_year($item,'P577');
		$title_safe = $this->db->real_escape_string($item->getLabel());
		$sql = "UPDATE `audio` set `title`='{$title_safe}',`year`={$year_safe},`minutes`={$minutes_safe},`image`={$image_safe},`sites`={$sites_safe},`ts`='{$ts_safe}' WHERE `q`={$audio_q_numeric}" ;
		$this->tfc->getSQL ( $this->db , $sql ) ;

		$this->update_item_labels($item);
	}

	protected function add_audio_details_chunk($chunk) {
		$wil = new WikidataItemList();
		$wil->loadItems($chunk);
		$qs = [] ;
		$sections = [] ;
		$entry_files = [] ;
		foreach ( $chunk AS $q_numeric ) {
			$this->add_audio_details($wil,$q_numeric,$qs,$sections,$entry_files);
		}
		if ( count($qs) == 0 ) return ;

		# Cleanup
		$qs = implode(",",$qs);
		$sql = "DELETE FROM `section` WHERE `audio_q` IN ($qs)";
		$this->tfc->getSQL ( $this->db , $sql ) ;
		$sql = "DELETE FROM `file` WHERE `audio_q` IN ($qs)";
		$this->tfc->getSQL ( $this->db , $sql ) ;

		# Insert sections
		if ( count($sections) > 0 ) {
			$sql = "INSERT IGNORE INTO `section` (`audio_q`,`property`,`section_q`) VALUES " ;
			$sql .= implode(",",$sections);
			$this->tfc->getSQL ( $this->db , $sql ) ;
		}

		# Insert audio filed
		if ( count($entry_files) > 0 ) {
			$sql = "INSERT IGNORE INTO `file` (`audio_q`,`property`,`key`,`is_trailer`) VALUES " ;
			$sql .= implode(",",$entry_files);
			$this->tfc->getSQL ( $this->db , $sql ) ;
		}

		# Make audio available
		$sql = "UPDATE `audio` SET `available`=1 WHERE `q` IN ($qs)";
		$this->tfc->getSQL ( $this->db , $sql ) ;
	}

	public function add_missing_audio_details() {
		$sql = "SELECT `q` FROM `audio` WHERE `available`=0" ;
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		$qs = [];
		while($o = $result->fetch_object()) $qs[] = $o->q ;
		if ( count($qs)==0 ) return ; # Nothing to do
		foreach ( array_chunk($qs,50) as $chunk ) {
			$this->add_audio_details_chunk($chunk);
		}
	}

	public function make_rc_unavailable() {
		$last_rc_check = '' ;
		$sql = "SELECT `value` FROM `kv` WHERE `key`='last_rc_check'" ;
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		while($o = $result->fetch_object()) $last_rc_check = $o->value ;

		$qs_audio = [];
		$sql = "SELECT `q` FROM `audio`" ;
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		while($o = $result->fetch_object()) $qs_audio[] = $o->q;

		$qs_person = [];
		$sql = "SELECT `q` FROM `person`" ;
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		while($o = $result->fetch_object()) $qs_person[] = $o->q;

		$qs = array_merge($qs_audio,$qs_person);

		$dbwd = $this->tfc->openDBwiki('wikidatawiki');
		$sql = "SELECT `rc_title`,`rc_timestamp` FROM `recentchanges`
				WHERE `rc_namespace`=0 AND `rc_timestamp`>'{$last_rc_check}'
				AND `rc_title` IN ('Q".implode("','Q",$qs)."')";
		$result = $this->tfc->getSQL ( $dbwd , $sql ) ;
		$qs = [] ;
		while($o = $result->fetch_object()) {
			$qs[$o->rc_title] = preg_replace('|\D|','',$o->rc_title)*1;
			if ( $last_rc_check<$o->rc_timestamp ) $last_rc_check = $o->rc_timestamp;
		}

		if ( count($qs)>0 ) {
			$sql = "UPDATE `audio` SET `available`=0 WHERE `q` IN (".implode(',',$qs).")" ;
			$this->tfc->getSQL ( $this->db , $sql ) ;
			$sql = "DELETE FROM `person` WHERE `q` IN (".implode(',',$qs).")" ;
			$this->tfc->getSQL ( $this->db , $sql ) ;
		}

		$sql = "UPDATE `kv` SET `value`='{$last_rc_check}' WHERE `key`='last_rc_check'" ;
		$this->tfc->getSQL ( $this->db , $sql ) ;
	}

	public function get_recently_added($num=25,$section_q=null) {
		return $this->get_audio_view('vw_recently_added',$num,$section_q);
	}

	// public function get_audio_by_female_directors($num=25,$section_q=null) {
	// 	return $this->get_audio_view('vw_audio_by_female_directors',$num,$section_q);
	// }

	public function get_ranked_audio($num=25,$section_q=null) {
		return $this->get_audio_view('vw_ranked_entries',$num,$section_q);
	}

	protected function get_audio_view($view_name,$num=25,$section_q=null) {
		$ret = [];
		$sql = "SELECT * FROM `{$view_name}`";
		if ( isset($section_q) and $section_q!=null ) $sql .= " WHERE `q` IN (SELECT audio_q FROM section WHERE section_q={$section_q})";
		// $sql .= " ORDER BY sites DESC,minutes DESC,q" ;
		$sql .= " LIMIT {$num}" ;
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		while($o = $result->fetch_object()) {
			$this->fix_audio_image($o);
			$ret[] = $o ;
		}
		return $ret;
	}

	protected function fix_audio_image(&$o) {
		if ( !isset($o->files) ) return $o;
		$o->files = json_decode($o->files);
		foreach ( $o->files AS $vf ) {
			if ( $o->image==null and isset($vf->{'10'}) ) $o->image = $vf->{'10'};
		}
		return $o ;
	}

	protected function update_item_labels($item) {
		$q = $item->getQ();
		$q_numeric = preg_replace('|\D|','',$q);
		$sql = "DELETE FROM `label` WHERE `q`={$q_numeric}" ;
		$this->tfc->getSQL ( $this->db , $sql ) ;
		$sql = [];
		foreach ( $item->j->labels AS $lang => $v ) {
			$lang_safe = $this->db->real_escape_string($lang) ;
			$value_safe = $this->db->real_escape_string($v->value) ;
			$sql[] = "({$q_numeric},'{$lang_safe}','{$value_safe}')";
		}
		if ( count($sql)>0 ) {
			$sql = "INSERT IGNORE INTO `label` (`q`,`language`,`value`) VALUES " . implode(',',$sql) ;
			$this->tfc->getSQL ( $this->db , $sql ) ;
		}
	}

	public function import_missing_section_labels() {
		$sql = "SELECT `section_q` AS `q` FROM `section` WHERE `section_q` NOT IN (SELECT DISTINCT `q` FROM label)";
		$sql .= " UNION SELECT `q` FROM `audio` WHERE `q` NOT IN (SELECT DISTINCT `q` FROM label)";
		$sql .= " UNION SELECT `q` FROM `person` WHERE `q` NOT IN (SELECT DISTINCT `q` FROM label)";
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		$qs = [];
		while($o = $result->fetch_object()) $qs[] = $o->q*1 ;
		foreach ( array_chunk($qs,50) as $chunk ) {
			$wil = new WikidataItemList();
			$wil->loadItems($chunk);
			foreach ( $chunk AS $q ) {
				$item = $wil->getItem($q);
				if ( !isset($item) ) continue;
				$this->update_item_labels($item);
			}
		}
	}

	public function get_top_sections($num=20,$properties=[],$skip_section_q=[838368,226730]) {
		if ( count($properties)==0 ) $properties = $this->misc_section_props;
		$skip_section_q = array_merge($skip_section_q,$this->bad_sections);
		$ret = [];
		$sql = "SELECT *,(SELECT `value` FROM `label` WHERE `language`='{$this->language}' AND `q`=`section_q`) AS `label` FROM `vw_section_property_q` WHERE `property` IN (".implode(',',$properties).") AND `section_q` NOT IN (".implode(',',$skip_section_q).") LIMIT {$num}" ;
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		while($o = $result->fetch_object()) $ret[] = $o ;
		return $ret;
	}

	public function get_random_sections($num=20,$properties=[],$skip_section_q=[838368,226730]) {
		$min_audio = 10;
		if ( count($properties)==0 ) $properties = $this->misc_section_props;
		$skip_section_q = array_merge($skip_section_q,$this->bad_sections);
		$ret = [];
		$sql = "SELECT *,(SELECT `value` FROM `label` WHERE `language`='{$this->language}' AND `q`=`section_q`) AS `label` FROM `vw_section_property_q` WHERE `cnt`>={$min_audio} AND `property` IN (".implode(',',$properties).") AND `section_q` NOT IN (".implode(',',$skip_section_q).") ORDER BY rand() LIMIT {$num}" ;
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		while($o = $result->fetch_object()) $ret[] = $o ;
		return $ret;
	}

	public function get_main_page_data() {
		$out = [ 'status'=>'OK' ];
		$out['sections'] = [];
		$out['sections'][] = [
			'title' => "Recently added",
			'entries' => $this->get_recently_added(25)
		];
		$out['sections'][] = [
			'title' => "Highly ranked",
			'entries' => $this->get_ranked_audio(25)
		];
		// $out['sections'][] = [
		// 	'title' => "Female directors",
		// 	'entries' => $this->get_audio_by_female_directors(25)
		// ];

		$sections = $this->get_random_sections(20); // $this->get_top_sections(20);
		$qs = [] ;
		foreach ( $sections AS $section ) $qs[] = $section->section_q;
		$wil = new WikidataItemList();
		$wil->loadItems($qs);
		foreach ( $sections AS $section ) {
			$item = $wil->getItem($section->section_q);
			if ( !isset($item) ) continue ;
			$out['sections'][] = $this->populate_section($section,$item);
		}

		$sql = "SELECT count(*) AS `cnt` FROM `audio`" ;
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		if($o = $result->fetch_object()) $out['entry_total'] = $o->cnt ;

		$sql = "SELECT count(*) AS `cnt` FROM `person`" ;
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		if($o = $result->fetch_object()) $out['person_total'] = $o->cnt ;

		$out['section_total'] = count($this->get_top_sections(PHP_INT_MAX));

		return $out ;
	}

	public function populate_section($section,$item,$max=25) {
		$title = $item->getLabel();
		$entries = $this->get_ranked_audio(PHP_INT_MAX,$section->section_q);
		$total = count($entries);
		$entries = array_slice ( $entries , 0 , $max ) ;
		return [
				'q' => $section->section_q,
				'title' => $title,
				'prop' => $section->property,
				'total' => $total,
				'entries' => $entries
			];
	}

	public function search_sections($query) {
		$ret = [];
		$query_safe = $this->db->real_escape_string(trim($query));
		if ( $query_safe=='' ) return $ret ; # Too broad a search

		$sql = "SELECT *,(SELECT `value` FROM `label` WHERE `language`='{$this->language}' AND `q`=`section_q`) AS `label` FROM `vw_section_property_q` WHERE `property` IN (".implode(',',$this->misc_section_props).") AND `section_q` IN (SELECT DISTINCT `q` FROM `label` WHERE `value` LIKE '%{$query_safe}%') LIMIT 50" ;
		// print "{$sql}\n";
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		$sections = [];
		while($o = $result->fetch_object()) $sections[] = $o ;


		$qs = [] ;
		foreach ( $sections AS $section ) $qs[] = $section->section_q;
		$wil = new WikidataItemList();
		$wil->loadItems($qs);
		foreach ( $sections AS $section ) {
			$item = $wil->getItem($section->section_q);
			if ( !isset($item) ) continue ;
			$ret[] = $this->populate_section($section,$item);
		}

		return $ret;
	}

	public function search_entries($query) {
		$ret = [];
		$query_safe = $this->db->real_escape_string(trim($query));
		if ( $query_safe=='' ) return $ret ; # Too broad a search
		$sql = "SELECT * FROM `vw_ranked_entries` WHERE `title` LIKE \"%{$query_safe}%\" LIMIT 50" ;
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		while($o = $result->fetch_object()) {
			$this->fix_audio_image($o);
			$ret[] = $o ;
		}
		return $ret;
	}

	public function search_people($query) {
		$ret = [];
		$query_safe = $this->db->real_escape_string(trim($query));
		if ( $query_safe=='' ) return $ret ; # Too broad a search
		$sql = "SELECT * FROM `person` WHERE `label` LIKE \"%{$query_safe}%\" LIMIT 50" ;
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		while($o = $result->fetch_object()) $ret[] = $o ;
		return $ret;
	}

	public function update_persons() {
		$sql = "SELECT DISTINCT `section_q` FROM `section` WHERE `property` IN (".implode(',',$this->people_props).") AND `section_q` NOT IN (SELECT `q` FROM `person`)";
		$result = $this->tfc->getSQL ( $this->db , $sql ) ;
		$qs = [] ;
		while($o = $result->fetch_object()) $qs[] = $o->section_q;
		foreach ( array_chunk($qs,50) as $chunk ) {
			$wil = new WikidataItemList();
			$wil->loadItems($chunk);
			$sql = [];
			foreach ( $chunk AS $q ) {
				$item = $wil->getItem($q);
				if ( !isset($item) ) continue;
				$label_safe = $this->db->real_escape_string($item->getLabel());
				$gender_safe = '?';
				if ( $item->hasTarget("P21","Q6581097") ) $gender_safe = 'M';
				if ( $item->hasTarget("P21","Q6581072") ) $gender_safe = 'F';
				$sites_safe = count($item->getSitelinks());
				$image = $item->getFirstString("P18");
				if ( isset($image) ) $image_safe = '"'.$this->db->real_escape_string($image).'"';
				else $image_safe = 'null';
				$sql[] = "({$q},'{$label_safe}','{$gender_safe}',{$image_safe},$sites_safe)" ;
				$this->update_item_labels($item);
			}
			$sql = "INSERT IGNORE INTO `person` (`q`,`label`,`gender`,`image`,`sites`) VALUES ".implode(',',$sql) ;
			$this->tfc->getSQL ( $this->db , $sql ) ;
		}
	}

	public function generate_main_page_data() {
		$out = $this->get_main_page_data();
		$out = 'var config = ' . json_encode($out) . ';' ;
		$filename = '/data/project/wikivibes/public_html/config.js';
		file_put_contents($filename,$out);
	}

	// NOT USED AT THE MOMENT
	public function import_audio_whitelist() {
		$qs = [];
		$existing_audio_qs = $this->get_items_in_db();
		$wt = $this->tfc->getWikiPageText('wikidatawiki','Help:WikiVibes/audio whitelist');
		$rows = explode("\n",$wt);
		foreach ( $rows AS $row ) {
			if ( !preg_match('|^\*.*?(\d{3,})|',$row,$m) ) continue;
			$q = $m[1]*1;
			if ( isset($existing_audio_qs['Q'.$q]) ) continue;
			$qs[] = $q ;
		}
		if ( count($qs)==0 ) return;
		$sql = "INSERT IGNORE INTO `audio` (`q`) VALUES (".implode('),(',$qs).")" ;
		$this->tfc->getSQL ( $this->db , $sql ) ;
	}

	public function reset_all () {
		$sql = "TRUNCATE `section`" ;
		$this->tfc->getSQL ( $this->db , $sql ) ;
		$sql = "TRUNCATE `file`" ;
		$this->tfc->getSQL ( $this->db , $sql ) ;
		$sql = "UPDATE `audio` SET `available`=0" ;
		$this->tfc->getSQL ( $this->db , $sql ) ;
	}

}


?>