<?php

/**
 * Retry-on-throw wrapper around `ToolforgeCommon::getSPARQL_TSV()`.
 *
 * Originally added to WikiStream (audits/STATUS.md P1.6) to survive
 * WDQS read timeouts. Now lives in a trait so both WikiStream and
 * QuickStatementsBot share one implementation rather than having two
 * drifting copies.
 *
 * Requires the using class to expose:
 *   - `$this->tfc` of type ToolforgeCommon (or a compatible stub)
 *
 * Test seam: set `$sparqlRetryBaseSleepUs = 0` via reflection (or in
 * a subclass) to disable real sleeps during tests.
 */
trait SparqlRetryTrait
{
	/**
	 * Microseconds to wait between SPARQL retry attempts (multiplied by
	 * the completed attempt number for a simple linear backoff). Tests
	 * set this to 0 via reflection to disable real sleeps.
	 */
	protected int $sparqlRetryBaseSleepUs = 2_000_000;

	/**
	 * Run a SPARQL query through ToolforgeCommon with a small retry on
	 * transient failures (WDQS read timeouts, 5xx, etc.). Returns an
	 * array of rows. On persistent failure: returns [] and sets
	 * $succeeded to false (so callers can distinguish a real "no rows"
	 * from "WDQS gave up three times"). On success: $succeeded is true.
	 *
	 * The cron survives a WDQS hiccup this way — the next hour's run
	 * gets another shot.
	 *
	 * @return list<mixed>
	 */
	protected function sparqlRetried(
		string $sparql,
		?bool &$succeeded = null,
		int $maxAttempts = 3,
	): array {
		$succeeded = false;
		$lastErr   = null;
		for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
			try {
				$out = [];
				foreach ($this->tfc->getSPARQL_TSV($sparql) as $row) {
					$out[] = $row;
				}
				$succeeded = true;
				return $out;
			} catch (\Throwable $e) {
				$lastErr = $e;
				if ($attempt < $maxAttempts) {
					$this->sparqlBackoffSleep($attempt);
				}
			}
		}
		$msg = $lastErr !== null ? $lastErr->getMessage() : 'unknown error';
		error_log("sparqlRetried: gave up after {$maxAttempts} attempts: {$msg}");
		return [];
	}

	protected function sparqlBackoffSleep(int $completedAttempts): void
	{
		if ($this->sparqlRetryBaseSleepUs <= 0) {
			return;
		}
		$us = $this->sparqlRetryBaseSleepUs * $completedAttempts
		    + random_int(0, 500_000);
		usleep($us);
	}
}
