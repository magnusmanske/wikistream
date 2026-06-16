<?php

/**
 * QuickStatements bot: a sibling of WikiStream that owns every code path
 * which emits Wikidata edits via QuickStatements (audits/STATUS.md P1.2,
 * solid.md §1, complexity.md §1, design.md B.4).
 *
 * Eight responsibilities, all originally inlined into WikiStream:
 *   - C1: pre-1900 PD annotator    (annotate_pre_1900_public_domain)
 *   - C2: Commons P180 → film P10  (import_commons_pd_films_via_p180)
 *   - C3: IA curated → P724        (import_ia_curated_imdb_p724)
 *   - IA curated films ingest      (import_ia_curated_films)
 *   - P953 URL → host-property     (import_p953_urls + parseP953Url)
 *   - IA runtime → P2047           (annotate_ia_movies)
 *   - QS submission                (pushQuickStatements)
 *
 * Lives behind WikiStream as a façade — `update.php` keeps calling
 * `$ws->import_p953_urls()` etc., which delegates here. Tests inject a
 * subclass of this bot via the WikiStream constructor to override the
 * QS submission and Wikidata-item-list seams.
 */
class QuickStatementsBot
{
	use SparqlRetryTrait;

	// ------------------------------------------------------------------
	// Constants — all bot-specific tunables previously held on
	// WikiStream. Moved here so the bot is self-contained.
	// ------------------------------------------------------------------

	/** Wikidata Q-id for "do not use for WikiFlix" (P11484 qualifier). */
	private const WD_DO_NOT_USE = "Q124428688";

	/** Maximum P6216 annotations per pre-1900 PD invocation. */
	public const PRE_1900_PD_PER_RUN = 100;

	/** Maximum IA results considered per import_ia_curated_imdb_p724 invocation. */
	public const C3_IMDB_CANDIDATES_PER_RUN = 100;

	/** IA collections mined for IMDb→P724 links. */
	public const IA_C3_COLLECTIONS = ['feature_films', 'silent_films', 'prelinger'];

	/** Maximum Commons files examined per import_commons_pd_films_via_p180 invocation. */
	public const C2_FILES_PER_RUN = 100;

	/** Commons categories whose video members are mined for P180 → P10 links. */
	public const C2_COMMONS_CATEGORIES = [
		'Films in the public domain',
	];

	/** File extensions treated as video for the C2 pipeline. */
	public const C2_VIDEO_EXTENSIONS = ['webm', 'ogv', 'ogg', 'mp4'];

	/** Maximum QS add-statement commands per import_p953_urls invocation. */
	public const P953_COMMANDS_PER_RUN = 100;

	/** Maximum IA candidate films fetched per import_ia_curated_films invocation. */
	public const IA_CURATED_CANDIDATES_PER_RUN = 200;

	/**
	 * IA collection slugs considered curated enough to bypass the SPARQL
	 * #3 duration-agreement filter (see import_ia_curated_films()). Same
	 * set as IA_C3_COLLECTIONS, kept duplicated so the two tunables can
	 * drift independently.
	 */
	public const IA_CURATED_COLLECTIONS = ['feature_films', 'silent_films', 'prelinger'];

	// ------------------------------------------------------------------

	public $tfc;
	public $config;
	protected $db;
	protected HttpClientInterface $httpClient;

	public function __construct(
		$config,
		$tfc,
		$db,
		HttpClientInterface $httpClient,
	) {
		$this->config     = $config;
		$this->tfc        = $tfc;
		$this->db         = $db;
		$this->httpClient = $httpClient;
	}

	// ------------------------------------------------------------------
	// Shared helpers (protected — test seams)
	// ------------------------------------------------------------------

	/**
	 * Submit a batch of QuickStatements commands. No-op when the local
	 * QuickStatements library isn't installed (dev / test environments).
	 * Tests override this method to capture commands without touching QS.
	 */
	protected function pushQuickStatements(array $commands): void
	{
		if (count($commands) === 0) {
			return;
		}
		print "Running " . count($commands) . " QS commands\n";
		$qs_lib = "/data/project/quickstatements/public_html/quickstatements.php";
		if (!file_exists($qs_lib)) {
			return;
		}
		require_once $qs_lib;
		$qs = $this->tfc->getQS($this->config->toolkey, __DIR__ . "/../bot.ini");
		$this->tfc->runCommandsQS($commands, $qs);
	}

	/**
	 * Build a WikidataItemList pre-loaded with $qs. Tests override to
	 * return an already-populated list without hitting wbgetentities.
	 */
	protected function loadWikidataItemList(array $qs): WikidataItemList
	{
		$wil = new WikidataItemList();
		$wil->loadItems($qs);
		return $wil;
	}

	/**
	 * Snapshot of Q-numbers currently in the `item` table. Used by
	 * import_ia_curated_films to skip candidates that the normal cron
	 * pipeline will pick up anyway.
	 *
	 * @return array<string,int> "Q123" => 123
	 */
	protected function get_items_in_db(): array
	{
		$ret = [];
		$sql = "SELECT `q` FROM `item`";
		$result = $this->tfc->getSQL($this->db, $sql);
		while ($o = $result->fetch_object()) {
			$ret["Q{$o->q}"] = $o->q;
		}
		if (method_exists($result, 'free')) {
			$result->free();
		}
		return $ret;
	}

	// ------------------------------------------------------------------
	// 1) Ingest curated IA films
	// ------------------------------------------------------------------

	/**
	 * Ingest films whose IA P724 item belongs to a curated whitelist
	 * collection (see self::IA_CURATED_COLLECTIONS), bypassing the
	 * 60–150 % duration-agreement filter used by SPARQL query #3.
	 *
	 * Query #3 keeps working unchanged — this method is additive: any
	 * film already accepted by query #3 still is, and films newly added
	 * here are the ones query #3 rejected for duration or year reasons.
	 *
	 * Network cost is bounded by self::IA_CURATED_CANDIDATES_PER_RUN.
	 * The Wikidata items themselves are merely INSERTed into `item`
	 * (with available=0) — the normal pipeline picks them up on the
	 * next pass to fetch file/section claims.
	 */
	public function import_ia_curated_films(): void
	{
		$sparql =
			"SELECT DISTINCT ?q ?ia WHERE {
				?q (wdt:P31/(wdt:P279*)) wd:Q11424 ;
				   wdt:P2047 ?duration .
				?q p:P724 ?statement .
				MINUS { ?statement pq:P11484 wd:Q124428688 } .
				?statement ps:P724 ?ia .
				?statement pq:P2047 ?ia_duration .
				MINUS { ?q wdt:P31 wd:Q97570383 }
			}";

		// q (int) → ia identifier (string). Cap candidate count before
		// HTTP fan-out to bound load on the IA metadata API.
		$candidates = [];
		foreach ($this->sparqlRetried($sparql) as $row) {
			if (count($candidates) >= self::IA_CURATED_CANDIDATES_PER_RUN) {
				break;
			}
			$q = $this->tfc->parseItemFromURL((string) ($row["q"] ?? ""));
			$q_numeric = (int) preg_replace("|\D|", "", (string) $q);
			$ia = (string) ($row["ia"] ?? "");
			if ($q_numeric > 0 && $ia !== "") {
				$candidates[$q_numeric] = $ia;
			}
		}
		if (count($candidates) === 0) {
			return;
		}

		// Drop candidates that are already in the items table; the cron
		// pipeline will pick those up the normal way.
		$existing = $this->get_items_in_db();
		foreach ($candidates as $q_numeric => $_ia) {
			if (isset($existing["Q{$q_numeric}"])) {
				unset($candidates[$q_numeric]);
			}
		}
		if (count($candidates) === 0) {
			return;
		}

		// Batch IA metadata fetches in chunks of 50.
		$accepted = [];
		foreach (array_chunk($candidates, 50, true) as $chunk) {
			$urls = [];
			foreach ($chunk as $q_numeric => $ia) {
				$urls[$q_numeric] = "https://archive.org/metadata/" . rawurlencode($ia);
			}
			$responses = $this->httpClient->getJsonBatch($urls);
			foreach ($chunk as $q_numeric => $_ia) {
				$j = $responses[$q_numeric] ?? null;
				if (!isset($j)) {
					continue;
				}
				if (isset($j->is_dark) && $j->is_dark) {
					continue;
				}
				$collection = $j->metadata->collection ?? null;
				if ($collection === null) {
					continue;
				}
				// Normalise to array: IA returns a string when an item is
				// in exactly one collection and a list otherwise.
				if (is_string($collection)) {
					$collection = [$collection];
				}
				if (!is_array($collection)) {
					continue;
				}
				$matches = array_intersect(self::IA_CURATED_COLLECTIONS, $collection);
				if (count($matches) > 0) {
					$accepted[] = $q_numeric;
				}
			}
		}

		if (count($accepted) === 0) {
			return;
		}

		// Insert in chunks. INSERT IGNORE handles races against the
		// normal SPARQL pipeline writing the same q in parallel.
		// The candidate SPARQL above already constrains via P31/P279*
		// to Q11424, and purge_out_of_scope_items() catches anything
		// that slips through — no second scope filter here.
		foreach (array_chunk($accepted, 500) as $chunk) {
			$sql =
				"INSERT IGNORE INTO `item` (`q`) VALUES (" .
				implode("),(", $chunk) .
				")";
			$this->tfc->getSQL($this->db, $sql);
		}
	}

	// ------------------------------------------------------------------
	// 2) P953 URL → host-specific property
	// ------------------------------------------------------------------

	/**
	 * Promote wdt:P953 ("full work available at URL") values into the
	 * native host-specific properties (P10/P724/P1651/P4015/P11731) via
	 * QuickStatements, so the regular ingestion pipeline picks them up
	 * on the next cron run.
	 *
	 * Targets films that have at least one P953 statement but none of the
	 * supported host properties. Statements carrying the
	 * P11484=Q124428688 ("do not use for WikiFlix") opt-out qualifier or
	 * a deprecated rank are skipped. Bot activity is capped at
	 * self::P953_COMMANDS_PER_RUN commands per invocation.
	 */
	public function import_p953_urls(): void
	{
		$sparql =
			"SELECT DISTINCT ?q WHERE {
				?q (wdt:P31/(wdt:P279*)) wd:Q11424 ;
				   wdt:P953 ?url .
				MINUS { ?q wdt:P10 ?_c }
				MINUS { ?q wdt:P724 ?_i }
				MINUS { ?q wdt:P1651 ?_y }
				MINUS { ?q wdt:P4015 ?_v }
				MINUS { ?q wdt:P11731 ?_d }
				MINUS { ?q wdt:P31 wd:Q97570383 }
			}";

		$candidate_qs = [];
		foreach ($this->sparqlRetried($sparql) as $row) {
			$q = $this->tfc->parseItemFromURL((string) ($row["q"] ?? ""));
			$q_numeric = (int) preg_replace("|\D|", "", (string) $q);
			if ($q_numeric > 0) {
				$candidate_qs[] = $q_numeric;
			}
		}
		if (count($candidate_qs) === 0) {
			return;
		}
		$candidate_qs = array_values(array_unique($candidate_qs));

		$wil = $this->loadWikidataItemList($candidate_qs);

		$commands = [];
		foreach ($candidate_qs as $q_numeric) {
			if (count($commands) >= self::P953_COMMANDS_PER_RUN) {
				break;
			}
			$item = $wil->getItem($q_numeric);
			if (!isset($item)) {
				continue;
			}
			foreach ($item->getClaims("P953") as $claim) {
				if (count($commands) >= self::P953_COMMANDS_PER_RUN) {
					break;
				}
				if (isset($claim->rank) && $claim->rank === "deprecated") {
					continue;
				}
				if (!isset($claim->mainsnak->datavalue->value)) {
					continue;
				}
				$value = $claim->mainsnak->datavalue->value;
				if (!is_string($value)) {
					continue;
				}

				// Honour the same opt-out qualifier the file ingester respects.
				if (isset($claim->qualifiers->P11484)) {
					$opted_out = false;
					foreach ($claim->qualifiers->P11484 as $qual) {
						if (($qual->datavalue->value->id ?? "") === self::WD_DO_NOT_USE) {
							$opted_out = true;
							break;
						}
					}
					if ($opted_out) {
						continue;
					}
				}

				$parsed = self::parseP953Url($value);
				if ($parsed === null) {
					continue;
				}
				[$prop, $key] = $parsed;
				$key_safe = addslashes($key);
				$commands[] = "Q{$q_numeric}\tP{$prop}\t\"{$key_safe}\"\t/* Imported from P953 URL */";
			}
		}

		$this->pushQuickStatements($commands);
	}

	/**
	 * Parse a URL appearing in a wdt:P953 (full work available at URL) claim
	 * and identify the WikiFlix file-host property + key it should yield.
	 *
	 * Returns [property_id, key] for known hosts, or null when the URL does
	 * not map to one of the supported video hosts.
	 *
	 * Pure function, no side effects — testable in isolation.
	 *
	 * @return array{int,string}|null
	 */
	public static function parseP953Url(string $url): ?array
	{
		$url = trim($url);
		if ($url === "") {
			return null;
		}
		$parts = parse_url($url);
		if (!is_array($parts) || !isset($parts["host"])) {
			return null;
		}
		$host = strtolower($parts["host"]);
		$path = ltrim($parts["path"] ?? "", "/");

		// Internet Archive → P724 (only /details/<id> form is a watch page)
		if (preg_match('~^(?:www\.)?archive\.org$~', $host)) {
			if (preg_match('~^details/([^/?#]+)~', $path, $m)) {
				return [724, $m[1]];
			}
			return null;
		}

		// YouTube → P1651: youtube.com/watch?v=, /embed/, plus youtu.be/<id>
		if (preg_match('~^(?:www\.|m\.|music\.)?youtube\.com$~', $host)) {
			parse_str($parts["query"] ?? "", $q);
			if (isset($q["v"]) && is_string($q["v"]) && preg_match('~^[A-Za-z0-9_-]{6,}$~', $q["v"])) {
				return [1651, $q["v"]];
			}
			if (preg_match('~^embed/([A-Za-z0-9_-]{6,})~', $path, $m)) {
				return [1651, $m[1]];
			}
			return null;
		}
		if ($host === "youtu.be" || $host === "www.youtu.be") {
			if (preg_match('~^([A-Za-z0-9_-]{6,})~', $path, $m)) {
				return [1651, $m[1]];
			}
			return null;
		}

		// Vimeo → P4015 (numeric video ID)
		if (preg_match('~^(?:www\.|player\.)?vimeo\.com$~', $host)) {
			if (preg_match('~^(?:video/)?(\d+)~', $path, $m)) {
				return [4015, $m[1]];
			}
			return null;
		}

		// Dailymotion → P11731
		if (preg_match('~^(?:www\.)?dailymotion\.com$~', $host)) {
			if (preg_match('~^video/([^/?#]+)~', $path, $m)) {
				return [11731, $m[1]];
			}
			return null;
		}

		// Wikimedia Commons file pages → P10. Accept the common localised
		// File-namespace prefixes since they all resolve to the same file.
		if ($host === "commons.wikimedia.org" || $host === "commons.m.wikimedia.org") {
			if (preg_match('~^wiki/(?:File|Datei|Fichier|Archivo|Plik|Bild):(.+)$~', $path, $m)) {
				return [10, urldecode($m[1])];
			}
			return null;
		}

		return null;
	}

	// ------------------------------------------------------------------
	// 3) IA curated → IMDb → P724
	// ------------------------------------------------------------------

	/**
	 * Mine IA's curated film collections for items carrying an IMDb
	 * external-identifier, resolve the IMDb ID to a Wikidata Q via P345,
	 * and queue QuickStatements to add P724 for items that don't already
	 * carry one.
	 *
	 * Bounded by self::C3_IMDB_CANDIDATES_PER_RUN. The Wikidata side of
	 * the resolution explicitly excludes items that already have a P724
	 * statement, so the method is idempotent across cron runs.
	 */
	public function import_ia_curated_imdb_p724(): void
	{
		// imdb_id -> ia_identifier
		$candidates = [];
		foreach (self::IA_C3_COLLECTIONS as $collection) {
			if (count($candidates) >= self::C3_IMDB_CANDIDATES_PER_RUN) {
				break;
			}
			$remaining = self::C3_IMDB_CANDIDATES_PER_RUN - count($candidates);
			$query = "collection:{$collection} AND external-identifier:urn\\:imdb\\:*";
			$url =
				"https://archive.org/advancedsearch.php?" .
				"q=" . rawurlencode($query) .
				"&fl%5B%5D=identifier&fl%5B%5D=external-identifier" .
				"&rows={$remaining}&output=json";
			$j = $this->httpClient->getJson($url);
			if (!isset($j->response->docs) || !is_array($j->response->docs)) {
				continue;
			}
			foreach ($j->response->docs as $doc) {
				if (count($candidates) >= self::C3_IMDB_CANDIDATES_PER_RUN) {
					break;
				}
				$ext = $doc->{"external-identifier"} ?? null;
				if (is_array($ext)) {
					$ext = $ext[0] ?? null;
				}
				if (!is_string($ext)) {
					continue;
				}
				if (!preg_match('~^urn:imdb:(tt\d+)$~', $ext, $m)) {
					continue;
				}
				$imdb = $m[1];
				$ia = (string) ($doc->identifier ?? "");
				if ($ia === "" || isset($candidates[$imdb])) {
					continue;
				}
				$candidates[$imdb] = $ia;
			}
		}
		if (count($candidates) === 0) {
			return;
		}

		// Resolve IMDb -> Wikidata items lacking P724.
		$valuesList = '"' . implode('" "', array_keys($candidates)) . '"';
		$sparql =
			"SELECT ?q ?imdb WHERE {
				VALUES ?imdb { {$valuesList} }
				?q wdt:P345 ?imdb .
				FILTER NOT EXISTS { ?q wdt:P724 ?_ia }
			}";

		$commands = [];
		foreach ($this->sparqlRetried($sparql) as $row) {
			$q = $this->tfc->parseItemFromURL((string) ($row["q"] ?? ""));
			$q_numeric = (int) preg_replace("|\D|", "", (string) $q);
			$imdb = (string) ($row["imdb"] ?? "");
			if ($q_numeric <= 0 || !isset($candidates[$imdb])) {
				continue;
			}
			$ia_safe = addslashes($candidates[$imdb]);
			$commands[] = "Q{$q_numeric}\tP724\t\"{$ia_safe}\"\t/* WikiFlix C3: from IA curated collection */";
		}

		$this->pushQuickStatements($commands);
	}

	// ------------------------------------------------------------------
	// 4) Commons P180 → film P10
	// ------------------------------------------------------------------

	/**
	 * Walk a whitelist of Commons categories for video files, fetch each
	 * file's M-entity P180 (depicts) claim, and queue a QuickStatements
	 * command to add P10 to the depicted film — but only when the target
	 * is a film and lacks any existing P10 statement.
	 *
	 * The P180 link is the only signal we trust: title-matching files to
	 * film items is too noisy. Files without a single P180 are skipped.
	 * Files with multiple P180 values are skipped too — we can't tell
	 * which one the file is supposed to be a video of.
	 *
	 * Bounded by self::C2_FILES_PER_RUN files examined per invocation.
	 */
	public function import_commons_pd_films_via_p180(): void
	{
		// 1. Walk the whitelisted Commons categories for file members
		//    that have a video extension.
		$files = []; // canonical title (no "File:" prefix) — list, dedup later
		foreach (self::C2_COMMONS_CATEGORIES as $category) {
			if (count($files) >= self::C2_FILES_PER_RUN) {
				break;
			}
			$limit = self::C2_FILES_PER_RUN - count($files);
			$url =
				"https://commons.wikimedia.org/w/api.php" .
				"?action=query&list=categorymembers" .
				"&cmtitle=" . rawurlencode("Category:{$category}") .
				"&cmtype=file&cmlimit={$limit}&format=json";
			$j = $this->httpClient->getJson($url);
			$members = $j->query->categorymembers ?? null;
			if (!is_array($members)) {
				continue;
			}
			foreach ($members as $m) {
				$title = (string) ($m->title ?? "");
				if (strpos($title, "File:") !== 0) {
					continue;
				}
				$name = substr($title, 5);
				$ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
				if (!in_array($ext, self::C2_VIDEO_EXTENSIONS, true)) {
					continue;
				}
				$files[] = $name;
				if (count($files) >= self::C2_FILES_PER_RUN) {
					break;
				}
			}
		}
		$files = array_values(array_unique($files));
		if (count($files) === 0) {
			return;
		}

		// 2. For each video file, fetch the M-entity P180 claim. Take
		//    only the single-target case to avoid ambiguity.
		$fileToFilm = []; // commons filename -> wikidata Q-id string
		foreach ($files as $name) {
			$url =
				"https://commons.wikimedia.org/w/api.php" .
				"?action=wbgetentities&sites=commonswiki" .
				"&titles=" . rawurlencode("File:{$name}") .
				"&props=claims&format=json";
			$j = $this->httpClient->getJson($url);
			if (!isset($j->entities)) {
				continue;
			}
			// The single returned entity (M<id>) is keyed by ID.
			$entity = null;
			foreach ((array) $j->entities as $_id => $e) {
				$entity = $e;
				break;
			}
			if (!isset($entity->claims->P180)) {
				continue;
			}
			$p180 = $entity->claims->P180;
			if (!is_array($p180) || count($p180) !== 1) {
				continue; // require exactly one P180 to avoid ambiguity
			}
			$target = $p180[0]->mainsnak->datavalue->value->id ?? null;
			if (!is_string($target) || !preg_match('~^Q\d+$~', $target)) {
				continue;
			}
			$fileToFilm[$name] = $target;
		}
		if (count($fileToFilm) === 0) {
			return;
		}

		// 3. Verify each candidate film is a film AND lacks a P10
		//    statement entirely. The "no P10 yet" rule is conservative —
		//    if the film already has even one P10 statement, leave it
		//    alone rather than risk wrong/duplicate additions.
		$valuesList = "wd:" . implode(" wd:", array_values($fileToFilm));
		$sparql =
			"SELECT ?q WHERE {
				VALUES ?q { {$valuesList} }
				?q wdt:P31/wdt:P279* wd:Q11424 .
				FILTER NOT EXISTS { ?q wdt:P10 ?_x }
			}";

		$verifiedFilms = [];
		foreach ($this->sparqlRetried($sparql) as $row) {
			$q = $this->tfc->parseItemFromURL((string) ($row["q"] ?? ""));
			if ($q !== "") {
				$verifiedFilms[$q] = true;
			}
		}
		if (count($verifiedFilms) === 0) {
			return;
		}

		// 4. Emit QS commands.
		$commands = [];
		foreach ($fileToFilm as $name => $film_q) {
			if (!isset($verifiedFilms[$film_q])) {
				continue;
			}
			$q_numeric = (int) preg_replace("|\D|", "", $film_q);
			if ($q_numeric <= 0) {
				continue;
			}
			$name_safe = addslashes($name);
			$commands[] = "Q{$q_numeric}\tP10\t\"{$name_safe}\"\t/* WikiFlix C2: via P180 in Commons category */";
		}
		$this->pushQuickStatements($commands);
	}

	// ------------------------------------------------------------------
	// 5) pre-1900 PD annotator
	// ------------------------------------------------------------------

	/**
	 * Conservative bot pass: stamp P6216=Q19652 (public domain) on films
	 * dated before 1900 that have no copyright-status statement at all.
	 *
	 * Pre-1900 films are PD in every jurisdiction with a finite copyright
	 * term — the most lenient term (life + 100) covers anyone who lived
	 * to a plausible age. P459=Q47246828 ("published more than 95 years
	 * ago") is added as the determination-method qualifier, matching the
	 * Wikidata convention used on 32,954 existing film P6216 statements.
	 * That determination method is jurisdiction-specific (US 95-year
	 * rule), so it always carries P1001=Q30 ("applies to jurisdiction:
	 * United States") alongside it.
	 *
	 * Bounded by self::PRE_1900_PD_PER_RUN. The current candidate pool
	 * is ~86 films, so a single run usually clears it.
	 */
	public function annotate_pre_1900_public_domain(): void
	{
		$sparql =
			"SELECT DISTINCT ?q WHERE {
				?q wdt:P31/wdt:P279* wd:Q11424 ;
				   wdt:P577 ?date .
				FILTER(YEAR(?date) < 1900)
				FILTER NOT EXISTS { ?q wdt:P6216 ?_status }
				MINUS { ?q wdt:P31 wd:Q97570383 }
			}";

		$commands = [];
		foreach ($this->sparqlRetried($sparql) as $row) {
			if (count($commands) >= self::PRE_1900_PD_PER_RUN) {
				break;
			}
			$q = $this->tfc->parseItemFromURL((string) ($row["q"] ?? ""));
			$q_numeric = (int) preg_replace("|\D|", "", (string) $q);
			if ($q_numeric <= 0) {
				continue;
			}
			$commands[] = "Q{$q_numeric}\tP6216\tQ19652\tP459\tQ47246828\tP1001\tQ30\t/* WikiFlix C1: pre-1900 film, PD by age */";
		}

		$this->pushQuickStatements($commands);
	}

	// ------------------------------------------------------------------
	// 6) IA runtime → P2047 annotator (uses parse_seconds)
	// ------------------------------------------------------------------

	/**
	 * Tolerant string-to-seconds parser used by annotate_ia_movies to
	 * read IA's `runtime` and per-file `length` metadata.
	 *
	 * Accepted shapes (a representative sample, not exhaustive):
	 *   "1:23:45"       1h23m45s
	 *   "12:34"          12m34s
	 *   "75"             75s        (numeric scalar)
	 *   "12 min 30 sec"  12m30s
	 *   "1 h 25 m"       1h25m
	 *   "23 min"         23m
	 *
	 * Returns 0 for any duration under 120s — both as a sanity floor
	 * and because IA frequently mis-encodes "1m23s" trailers.
	 */
	protected static function parse_seconds(string $s): int
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
		return (int) round($seconds);
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
		foreach ($this->sparqlRetried($sparql) as $row) {
			$q = $this->tfc->parseItemFromURL($row["q"]);
			$ia = $row["ia"];
			$q2ia[$q] = $ia;
		}

		$qs_commands = [];

		$wil = $this->loadWikidataItemList(array_keys($q2ia));

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

		$this->pushQuickStatements($qs_commands);
	}
}
