<?php

interface HttpClientInterface
{
	public function getJson(string $url): ?object;
}

/**
 * Curl-backed JSON fetcher.
 *
 * Hardened against the kind of failures the hourly update.php pipeline
 * actually encounters in production: a slow archive.org peer, a Commons
 * 5xx blip, gzip negotiation. Connection timeout, total timeout, gzip
 * encoding, retry-with-backoff on transient failures, and a reused
 * curl handle (warm TCP/TLS keepalive across calls to the same host).
 */
class CurlHttpClient implements HttpClientInterface
{
	private string $userAgent;
	private int $connectTimeout;
	private int $timeout;
	private int $maxAttempts;
	/** Backoff in microseconds between attempts; element N is the wait before attempt N+1. */
	private array $backoffUs;

	/** @var \CurlHandle|resource|null Reused across calls. */
	private $ch = null;

	public function __construct(
		string $userAgent = "Mozilla/5.0 (Windows NT 5.1; rv:31.0) Gecko/20100101 Firefox/31.0",
		int $connectTimeout = 10,
		int $timeout = 30,
		int $maxAttempts = 3,
		array $backoffUs = [1_000_000, 3_000_000]
	) {
		$this->userAgent      = $userAgent;
		$this->connectTimeout = $connectTimeout;
		$this->timeout        = $timeout;
		$this->maxAttempts    = max(1, $maxAttempts);
		$this->backoffUs      = $backoffUs;
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

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
		curl_setopt($ch, CURLOPT_ENCODING, ""); // negotiate gzip/deflate

		$body   = curl_exec($ch);
		$errno  = curl_errno($ch);
		$errstr = $errno !== 0 ? curl_error($ch) : "";
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

		return [$body, $status, $errno, $errstr];
	}
}
