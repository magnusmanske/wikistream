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
	public int $attempts = 0;
	public int $sleeps = 0;

	public function __construct(array $responses, int $maxAttempts = 3)
	{
		// Zero-microsecond backoff so the test doesn't actually sleep.
		parent::__construct("ua", 10, 30, $maxAttempts, [0, 0, 0, 0]);
		$this->responses = $responses;
	}

	protected function executeRequest(string $url): array
	{
		$this->attempts++;
		// Pop next canned response, or repeat the last one.
		$next = array_shift($this->responses);
		return $next ?? [false, 0, CURLE_COULDNT_CONNECT ?? 7, "exhausted"];
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
}
