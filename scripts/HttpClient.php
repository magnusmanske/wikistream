<?php

interface HttpClientInterface
{
	public function getJson(string $url): ?object;

	/**
	 * Fetch many URLs concurrently. Returns an array with the SAME KEYS as
	 * $urls; each value is the decoded object or null on failure. Each URL
	 * is independently retried under the same rules as getJson().
	 *
	 * @param array<int|string, string> $urls
	 * @return array<int|string, ?object>
	 */
	public function getJsonBatch(array $urls): array;
}

/**
 * Curl-backed JSON fetcher.
 *
 * Hardened against the kind of failures the hourly update.php pipeline
 * actually encounters in production: a slow archive.org peer, a Commons
 * 5xx blip, gzip negotiation. Connection timeout, total timeout, gzip
 * encoding, retry-with-backoff on transient failures, and a reused
 * curl handle (warm TCP/TLS keepalive across calls to the same host).
 *
 * Also offers getJsonBatch() backed by curl_multi for fetching many
 * URLs concurrently with the same retry semantics.
 */
class CurlHttpClient implements HttpClientInterface
{
	private string $userAgent;
	private int $connectTimeout;
	private int $timeout;
	private int $maxAttempts;
	/** Backoff in microseconds between attempts; element N is the wait before attempt N+1. */
	private array $backoffUs;
	/** Maximum simultaneous in-flight requests in getJsonBatch(). */
	private int $concurrency;

	/** @var \CurlHandle|resource|null Reused across calls. */
	private $ch = null;

	public function __construct(
		string $userAgent = "Mozilla/5.0 (Windows NT 5.1; rv:31.0) Gecko/20100101 Firefox/31.0",
		int $connectTimeout = 10,
		int $timeout = 30,
		int $maxAttempts = 3,
		array $backoffUs = [1_000_000, 3_000_000],
		int $concurrency = 4
	) {
		$this->userAgent      = $userAgent;
		$this->connectTimeout = $connectTimeout;
		$this->timeout        = $timeout;
		$this->maxAttempts    = max(1, $maxAttempts);
		$this->backoffUs      = $backoffUs;
		$this->concurrency    = max(1, $concurrency);
	}

	public function __destruct()
	{
		if ($this->ch !== null) {
			curl_close($this->ch);
			$this->ch = null;
		}
	}

	public function getJson(string $url): ?object
	{
		for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
			[$body, $status, $errno, $errstr] = $this->executeRequest($url);

			// Network-level failure → retry
			if ($errno !== 0) {
				if ($attempt < $this->maxAttempts) {
					$this->sleepBetweenAttempts($attempt);
					continue;
				}
				error_log("curl error for {$url}: {$errstr}");
				return null;
			}

			// Server error → retry
			if ($status >= 500 && $status < 600) {
				if ($attempt < $this->maxAttempts) {
					$this->sleepBetweenAttempts($attempt);
					continue;
				}
				error_log("HTTP {$status} for {$url}");
				return null;
			}

			// 4xx → permanent, do not retry
			if ($status >= 400) {
				error_log("HTTP {$status} for {$url}");
				return null;
			}

			// 2xx / 3xx → decode and return (null is a legitimate JSON value but
			// the callers all treat null as failure, matching prior behaviour)
			$decoded = json_decode((string) $body);
			return is_object($decoded) ? $decoded : null;
		}

		return null;
	}

	public function getJsonBatch(array $urls): array
	{
		$results = [];
		// Track URLs still pending by their input key; preserves duplicates.
		$pendingKeys = array_keys($urls);
		$attempts    = array_fill_keys($pendingKeys, 0);

		for ($round = 1; $round <= $this->maxAttempts; $round++) {
			if (empty($pendingKeys)) {
				break;
			}

			// Build chunks of $concurrency URLs per executeBatch() call so we
			// don't open hundreds of sockets at once against a single host.
			foreach (array_chunk($pendingKeys, $this->concurrency, true) as $chunkKeys) {
				$chunk = [];
				foreach ($chunkKeys as $k) {
					$chunk[$k] = $urls[$k];
				}
				$batchResponses = $this->executeBatch($chunk);

				foreach ($chunkKeys as $k) {
					[$body, $status, $errno, $errstr] = $batchResponses[$k]
						?? [false, 0, CURLE_COULDNT_CONNECT ?? 7, "no response"];

					$attempts[$k]++;
					$isTransient = ($errno !== 0) || ($status >= 500 && $status < 600);

					if (!$isTransient) {
						// Permanent result (2xx/3xx/4xx). Decode if 2xx/3xx.
						if ($errno === 0 && $status < 400) {
							$decoded = json_decode((string) $body);
							$results[$k] = is_object($decoded) ? $decoded : null;
						} else {
							error_log("HTTP {$status} for {$urls[$k]}");
							$results[$k] = null;
						}
						// Resolved — remove from pending.
						$pendingKeys = array_values(
							array_filter($pendingKeys, fn($x) => $x !== $k)
						);
					} elseif ($attempts[$k] >= $this->maxAttempts) {
						// Exhausted retries for transient failure.
						$msg = $errno !== 0
							? "curl error for {$urls[$k]}: {$errstr}"
							: "HTTP {$status} for {$urls[$k]}";
						error_log($msg);
						$results[$k] = null;
						$pendingKeys = array_values(
							array_filter($pendingKeys, fn($x) => $x !== $k)
						);
					}
					// else: keep in $pendingKeys for next round
				}
			}

			if (!empty($pendingKeys) && $round < $this->maxAttempts) {
				$this->sleepBetweenAttempts($round);
			}
		}

		// Restore the input key order.
		$ordered = [];
		foreach (array_keys($urls) as $k) {
			$ordered[$k] = $results[$k] ?? null;
		}
		return $ordered;
	}

	/**
	 * Wait between retry attempts. Override in tests to make retries instant.
	 */
	protected function sleepBetweenAttempts(int $completedAttempts): void
	{
		$idx = $completedAttempts - 1;
		$us  = $this->backoffUs[$idx] ?? end($this->backoffUs);
		if ($us > 0) {
			usleep($us);
		}
	}

	/**
	 * Issue one HTTP request. Returns [body, status_code, curl_errno, curl_error].
	 * Protected so tests can subclass and inject canned responses without hitting
	 * the network.
	 *
	 * @return array{0: string|false, 1: int, 2: int, 3: string}
	 */
	protected function executeRequest(string $url): array
	{
		if ($this->ch === null) {
			$this->ch = curl_init();
		}
		$ch = $this->ch;

		$this->applyOptions($ch, $url);

		$body   = curl_exec($ch);
		$errno  = curl_errno($ch);
		$errstr = $errno !== 0 ? curl_error($ch) : "";
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

		return [$body, $status, $errno, $errstr];
	}

	/**
	 * Issue $urls concurrently via curl_multi. Returns map of input-key →
	 * [body, status, errno, errstr]. Protected so tests can stub.
	 *
	 * @param array<int|string, string> $urls
	 * @return array<int|string, array{0:string|false,1:int,2:int,3:string}>
	 */
	protected function executeBatch(array $urls): array
	{
		if (empty($urls)) {
			return [];
		}

		$mh        = curl_multi_init();
		$handles   = []; // input-key => CurlHandle
		$handleKey = []; // (int) handle id → input-key

		foreach ($urls as $k => $url) {
			$ch = curl_init();
			$this->applyOptions($ch, $url);
			curl_multi_add_handle($mh, $ch);
			$handles[$k]                  = $ch;
			$handleKey[(int) $ch]          = $k;
		}

		// Standard curl_multi pump
		$active = null;
		do {
			$status = curl_multi_exec($mh, $active);
			if ($active) {
				curl_multi_select($mh, 1.0);
			}
		} while ($active && $status === CURLM_OK);

		$results = [];
		foreach ($handles as $k => $ch) {
			$body   = curl_multi_getcontent($ch);
			$errno  = curl_errno($ch);
			$errstr = $errno !== 0 ? curl_error($ch) : "";
			$code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$results[$k] = [$body !== null ? $body : false, $code, $errno, $errstr];

			curl_multi_remove_handle($mh, $ch);
			curl_close($ch);
		}
		curl_multi_close($mh);

		return $results;
	}

	private function applyOptions($ch, string $url): void
	{
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
		curl_setopt($ch, CURLOPT_ENCODING, ""); // negotiate gzip/deflate
	}
}
