<?php

declare(strict_types=1);

namespace WikiStream\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Subclass that records calls and returns canned responses, so the retry
 * logic can be tested without touching the network.
 */
final class StubCurlHttpClient extends \CurlHttpClient
{
	/** @var list<array{0:string|false,1:int,2:int,3:string}> */
	public array $responses;
	/** @var array<string, list<array{0:string|false,1:int,2:int,3:string}>> Per-URL queue used in batch mode. */
	public array $responsesByUrl = [];
	public int $attempts = 0;
	public int $sleeps = 0;
	public int $batchCalls = 0;

	public function __construct(array $responses, int $maxAttempts = 3, int $concurrency = 4)
	{
		// Zero-microsecond backoff so the test doesn't actually sleep.
		parent::__construct("ua", 10, 30, $maxAttempts, [0, 0, 0, 0], $concurrency);
		$this->responses = $responses;
	}

	protected function executeRequest(string $url): array
	{
		$this->attempts++;
		// Pop next canned response, or repeat the last one.
		$next = array_shift($this->responses);
		return $next ?? [false, 0, CURLE_COULDNT_CONNECT ?? 7, "exhausted"];
	}

	protected function executeBatch(array $urls): array
	{
		$this->batchCalls++;
		$out = [];
		foreach ($urls as $k => $url) {
			$this->attempts++;
			$queue = $this->responsesByUrl[$url] ?? [];
			$next = array_shift($queue);
			$this->responsesByUrl[$url] = $queue;
			$out[$k] = $next ?? [false, 0, CURLE_COULDNT_CONNECT ?? 7, "exhausted"];
		}
		return $out;
	}

	protected function sleepBetweenAttempts(int $completedAttempts): void
	{
		$this->sleeps++;
	}
}

final class HttpClientTest extends TestCase
{
	public function test_returns_decoded_object_on_2xx(): void
	{
		$body = json_encode(["status" => "ok", "n" => 7]);
		$client = new StubCurlHttpClient([[$body, 200, 0, ""]]);

		$result = $client->getJson("https://example.test/ok");

		$this->assertIsObject($result);
		$this->assertSame("ok", $result->status);
		$this->assertSame(7, $result->n);
		$this->assertSame(1, $client->attempts);
		$this->assertSame(0, $client->sleeps);
	}

	public function test_retries_on_network_error_then_succeeds(): void
	{
		$body = json_encode(["ok" => true]);
		$client = new StubCurlHttpClient([
			[false, 0, 7, "Couldn't connect"],
			[$body, 200, 0, ""],
		]);

		$result = $client->getJson("https://example.test/retry");

		$this->assertIsObject($result);
		$this->assertSame(2, $client->attempts);
		$this->assertSame(1, $client->sleeps);
	}

	public function test_retries_on_5xx_then_succeeds(): void
	{
		$body = json_encode(["ok" => true]);
		$client = new StubCurlHttpClient([
			["", 502, 0, ""],
			[$body, 200, 0, ""],
		]);

		$result = $client->getJson("https://example.test/5xx");

		$this->assertIsObject($result);
		$this->assertSame(2, $client->attempts);
	}

	public function test_does_not_retry_on_4xx(): void
	{
		$client = new StubCurlHttpClient([
			["not found", 404, 0, ""],
			[json_encode(["ok" => true]), 200, 0, ""], // would succeed, but we should not retry
		]);

		$result = @$client->getJson("https://example.test/4xx");

		$this->assertNull($result);
		$this->assertSame(1, $client->attempts);
		$this->assertSame(0, $client->sleeps);
	}

	public function test_gives_up_after_max_attempts(): void
	{
		$client = new StubCurlHttpClient([
			["", 503, 0, ""],
			["", 503, 0, ""],
			["", 503, 0, ""],
		], maxAttempts: 3);

		$result = @$client->getJson("https://example.test/5xx-persistent");

		$this->assertNull($result);
		$this->assertSame(3, $client->attempts);
		$this->assertSame(2, $client->sleeps); // sleep between attempt 1→2 and 2→3, not after final
	}

	public function test_returns_null_on_invalid_json(): void
	{
		$client = new StubCurlHttpClient([["not valid json", 200, 0, ""]]);

		$result = $client->getJson("https://example.test/garbage");

		$this->assertNull($result);
	}

	public function test_returns_null_on_json_scalar(): void
	{
		// json_decode("true") returns bool true; we only accept objects.
		$client = new StubCurlHttpClient([["true", 200, 0, ""]]);

		$result = $client->getJson("https://example.test/scalar");

		$this->assertNull($result);
	}

	// ------------------------------------------------------------------
	// getJsonBatch — concurrent fetching with retry
	// ------------------------------------------------------------------

	public function test_batch_returns_results_keyed_by_input_keys(): void
	{
		$client = new StubCurlHttpClient([]);
		$client->responsesByUrl = [
			"https://a/" => [[json_encode(["name" => "a"]), 200, 0, ""]],
			"https://b/" => [[json_encode(["name" => "b"]), 200, 0, ""]],
		];

		$result = $client->getJsonBatch(["alpha" => "https://a/", "beta" => "https://b/"]);

		$this->assertArrayHasKey("alpha", $result);
		$this->assertArrayHasKey("beta", $result);
		$this->assertSame("a", $result["alpha"]->name);
		$this->assertSame("b", $result["beta"]->name);
	}

	public function test_batch_preserves_input_order(): void
	{
		$client = new StubCurlHttpClient([]);
		$client->responsesByUrl = [
			"https://a/" => [[json_encode(["i" => 1]), 200, 0, ""]],
			"https://b/" => [[json_encode(["i" => 2]), 200, 0, ""]],
			"https://c/" => [[json_encode(["i" => 3]), 200, 0, ""]],
		];

		$result = $client->getJsonBatch(["a" => "https://a/", "b" => "https://b/", "c" => "https://c/"]);

		$this->assertSame(["a", "b", "c"], array_keys($result));
	}

	public function test_batch_4xx_does_not_retry(): void
	{
		$client = new StubCurlHttpClient([], maxAttempts: 3);
		$client->responsesByUrl = [
			"https://a/" => [["", 404, 0, ""]],
			"https://b/" => [[json_encode(["ok" => true]), 200, 0, ""]],
		];

		$result = @$client->getJsonBatch(["a" => "https://a/", "b" => "https://b/"]);

		$this->assertNull($result["a"]);
		$this->assertIsObject($result["b"]);
		// One round only (both resolved on first pass)
		$this->assertSame(1, $client->batchCalls);
	}

	public function test_batch_retries_5xx_per_url(): void
	{
		$client = new StubCurlHttpClient([], maxAttempts: 3);
		$client->responsesByUrl = [
			"https://flaky/" => [
				["", 502, 0, ""],
				[json_encode(["ok" => true]), 200, 0, ""],
			],
			"https://stable/" => [[json_encode(["ok" => true]), 200, 0, ""]],
		];

		$result = $client->getJsonBatch(["x" => "https://flaky/", "y" => "https://stable/"]);

		$this->assertIsObject($result["x"]);
		$this->assertIsObject($result["y"]);
		// Round 1: both dispatched. Round 2: only "x" dispatched.
		$this->assertSame(2, $client->batchCalls);
	}

	public function test_batch_gives_up_on_persistent_5xx(): void
	{
		$client = new StubCurlHttpClient([], maxAttempts: 2);
		$client->responsesByUrl = [
			"https://bad/" => [
				["", 503, 0, ""],
				["", 503, 0, ""],
			],
		];

		$result = @$client->getJsonBatch(["bad" => "https://bad/"]);

		$this->assertNull($result["bad"]);
		$this->assertSame(2, $client->batchCalls);
	}

	public function test_batch_empty_input_returns_empty(): void
	{
		$client = new StubCurlHttpClient([]);
		$this->assertSame([], $client->getJsonBatch([]));
		$this->assertSame(0, $client->batchCalls);
	}

	// ------------------------------------------------------------------
	// 429 Too Many Requests and 408 Request Timeout are transient — both
	// getJson() and getJsonBatch() must retry them (previously treated
	// as permanent 4xx, which gave up immediately on Commons/Wikidata
	// rate-limit bursts).
	// ------------------------------------------------------------------

	public function test_retries_on_429_then_succeeds(): void
	{
		$body = json_encode(["ok" => true]);
		$client = new StubCurlHttpClient([
			["", 429, 0, ""],
			[$body, 200, 0, ""],
		]);

		$result = $client->getJson("https://example.test/429");

		$this->assertIsObject($result);
		$this->assertSame(2, $client->attempts);
		$this->assertSame(1, $client->sleeps);
	}

	public function test_retries_on_408_then_succeeds(): void
	{
		$body = json_encode(["ok" => true]);
		$client = new StubCurlHttpClient([
			["", 408, 0, ""],
			[$body, 200, 0, ""],
		]);

		$result = $client->getJson("https://example.test/408");

		$this->assertIsObject($result);
		$this->assertSame(2, $client->attempts);
	}

	public function test_batch_retries_429_per_url(): void
	{
		$client = new StubCurlHttpClient([], maxAttempts: 3);
		$client->responsesByUrl = [
			"https://flaky/" => [
				["", 429, 0, ""],
				[json_encode(["ok" => true]), 200, 0, ""],
			],
			"https://stable/" => [[json_encode(["ok" => true]), 200, 0, ""]],
		];

		$result = $client->getJsonBatch(["x" => "https://flaky/", "y" => "https://stable/"]);

		$this->assertIsObject($result["x"]);
		$this->assertIsObject($result["y"]);
		$this->assertSame(2, $client->batchCalls);
	}

	// ------------------------------------------------------------------
	// Backoff includes random jitter so parallel cron tasks don't all
	// retry in lockstep after a brief blip. The jitter is bounded at
	// +30% of the base, so the returned microseconds always fall in
	// [base, base * 1.3].
	// ------------------------------------------------------------------

	public function test_backoff_includes_jitter_within_30_percent(): void
	{
		// Bare CurlHttpClient so we can reach computeBackoffUs directly.
		// Backoff base = 1_000_000 for attempt 1 (default).
		$client = new \CurlHttpClient();
		$method = new \ReflectionMethod(\CurlHttpClient::class, 'computeBackoffUs');

		$base = 1_000_000;
		$max  = (int) ($base * 1.3);

		// Sample many times; verify every value is in range and at least
		// one is strictly above base (proves jitter actually fires).
		$seenAboveBase = false;
		for ($i = 0; $i < 50; $i++) {
			$us = $method->invoke($client, 1);
			$this->assertGreaterThanOrEqual($base, $us);
			$this->assertLessThanOrEqual($max, $us);
			if ($us > $base) $seenAboveBase = true;
		}
		$this->assertTrue($seenAboveBase, 'jitter never fired across 50 samples');
	}

	public function test_backoff_returns_zero_for_zero_base(): void
	{
		// Zero base (used by tests) must short-circuit: no jitter, no sleep.
		$client = new \CurlHttpClient(backoffUs: [0]);
		$method = new \ReflectionMethod(\CurlHttpClient::class, 'computeBackoffUs');
		$this->assertSame(0, $method->invoke($client, 1));
	}
}
