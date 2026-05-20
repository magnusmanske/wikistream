<?php

declare(strict_types=1);

namespace WikiStream\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the thin dispatcher that sits behind public_html/api.php.
 *
 * The audit (testing.md T1, audits/STATUS.md P1.10) flagged the
 * dispatcher as a 0%-covered HTTP surface — every action did its own
 * input parsing and error reporting and any of them could regress
 * silently. These tests cover the input-parsing edges, the
 * Cache-Control table, and the error envelope.
 */
final class ApiDispatcherTest extends TestCase
{
    /**
     * Build a dispatcher with a mocked WikiStream + the bootstrap Widar
     * stub. Returns [dispatcher, ws, tfc, widar] so tests can stub
     * behaviour per-case.
     *
     * @return array{0:\ApiDispatcher, 1:\WikiStream, 2:object, 3:object}
     */
    private function makeDispatcher(
        array $requestParams = [],
        ?object $widar = null,
    ): array {
        $config = new \WikiStreamConfigWikiFlix();
        $config->db_statement_timeout_sec = 0;

        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn(new class {
            public function real_escape_string(string $s): string { return addslashes($s); }
        });
        $tfc->method('getRequest')->willReturnCallback(
            fn(string $key, mixed $default = null) => $requestParams[$key] ?? $default,
        );

        // Build a partial mock so individual tests can stub specific
        // WikiStream methods without touching the constructor.
        $ws = $this->getMockBuilder(\WikiStream::class)
            ->setConstructorArgs([$config, $tfc])
            ->onlyMethods([
                'getEntry', 'getRandomEntryQ', 'get_special_entries',
                'get_top_sections', 'get_paginated_sections',
                'get_paginated_groups', 'ensure_user_exists',
                'is_user_watching_item', 'get_item_view',
                'set_user_list_state', 'populate_section',
                'search_entries', 'search_sections', 'search_people',
                'search_groups', 'getPerson', 'getGroup',
                'get_items_by_year', 'get_candidate_items',
                'get_total_candidate_items', 'getItemForFile', 'logEvent',
            ])
            ->getMock();

        $widar ??= new \Widar();
        return [new \ApiDispatcher($ws, $widar), $ws, $tfc, $widar];
    }

    // ------------------------------------------------------------------
    // Dispatcher: action routing & error envelopes
    // ------------------------------------------------------------------

    public function test_unknown_action_returns_400_with_bad_action_status(): void
    {
        [$disp] = $this->makeDispatcher(['action' => 'made_up_action']);
        $result = $disp->handle('made_up_action');

        $this->assertSame(400, $result['http_code']);
        $this->assertSame('Bad action: made_up_action', $result['out']['status']);
        $this->assertSame('no-store', $result['cache_control']);
    }

    public function test_empty_action_is_treated_as_unknown(): void
    {
        [$disp] = $this->makeDispatcher();
        $result = $disp->handle('');

        $this->assertSame(400, $result['http_code']);
        $this->assertSame('no-store', $result['cache_control']);
    }

    public function test_uncaught_throwable_becomes_500_with_generic_message(): void
    {
        [$disp, $ws] = $this->makeDispatcher();
        $ws->method('getRandomEntryQ')->willThrowException(new \RuntimeException('db gone'));

        // The server-side log line is fine to emit; redirect it so the
        // test output stays clean.
        $prev = ini_set('error_log', '/dev/null');
        try {
            $result = $disp->handle('get_random_entry');
        } finally {
            if ($prev !== false) ini_set('error_log', $prev);
        }

        $this->assertSame(500, $result['http_code']);
        $this->assertSame('Internal server error', $result['out']['status']);
        // The vendored message MUST NOT leak to the client.
        $this->assertStringNotContainsString('db gone', json_encode($result['out']));
        $this->assertSame('no-store', $result['cache_control']);
    }

    // ------------------------------------------------------------------
    // Cache-Control policy
    // ------------------------------------------------------------------

    public function test_cache_control_private_for_get_entry(): void
    {
        [$disp, $ws] = $this->makeDispatcher(['q' => '42']);
        $ws->method('getEntry')->willReturn(null);

        $result = $disp->handle('get_entry');
        $this->assertSame(200, $result['http_code']);
        $this->assertSame('private, max-age=300', $result['cache_control']);
    }

    public function test_cache_control_public_for_get_special(): void
    {
        [$disp, $ws] = $this->makeDispatcher(['key' => 'popular_entries']);
        $ws->method('get_special_entries')->willReturn(['entries' => [], 'total' => 0]);

        $result = $disp->handle('get_special');
        $this->assertSame('public, max-age=300', $result['cache_control']);
    }

    public function test_cache_control_no_store_for_log(): void
    {
        [$disp, $ws] = $this->makeDispatcher(['event' => 'click']);
        $ws->method('logEvent');

        $result = $disp->handle('log');
        $this->assertSame('no-store', $result['cache_control']);
    }

    public function test_cache_control_no_store_when_status_not_ok(): void
    {
        // Cacheable action (get_section) returning 404 must NOT be
        // cached — a CDN that caches "No such item" for 5 min would
        // mask backend recovery.
        [$disp] = $this->makeDispatcher(['q' => '9999']);
        $result = $disp->handle('get_section');

        $this->assertSame(404, $result['http_code']);
        $this->assertSame('no-store', $result['cache_control']);
    }

    // ------------------------------------------------------------------
    // get_special — input sanitisation
    // ------------------------------------------------------------------

    public function test_get_special_strips_non_word_chars_from_key(): void
    {
        [$disp, $ws] = $this->makeDispatcher([
            // The dispatcher regex strips any char outside [a-z_].
            // SQL keywords (uppercase) and punctuation get dropped;
            // an attacker can still pad with lowercase letters but
            // can't introduce SQL syntax or escape the key into
            // another column.
            'key'   => 'popular_123!; DROP TABLE x; --',
            'limit' => '25',
        ]);

        $capturedKey = null;
        $ws->expects($this->once())->method('get_special_entries')
            ->willReturnCallback(function (string $key, int $offset, int $limit) use (&$capturedKey) {
                $capturedKey = $key;
                return ['entries' => [], 'total' => 0];
            });

        $disp->handle('get_special');

        // Punctuation, digits, and uppercase letters are all stripped.
        // (Lowercase letters from the payload — like 'x' — would be
        // concatenated, but they're inert as table refs without
        // surrounding SQL syntax.)
        $this->assertStringNotContainsString(';', $capturedKey);
        $this->assertStringNotContainsString(' ', $capturedKey);
        $this->assertStringNotContainsString('DROP', $capturedKey);
        $this->assertStringNotContainsString('1', $capturedKey);
        $this->assertStringNotContainsString('!', $capturedKey);
        $this->assertMatchesRegularExpression('/^[a-z_]+$/', $capturedKey);
    }

    public function test_get_special_empty_key_returns_empty_page_without_db_lookup(): void
    {
        [$disp, $ws] = $this->makeDispatcher(['key' => '']);
        $ws->expects($this->never())->method('get_special_entries');

        $result = $disp->handle('get_special');
        $this->assertSame('', $result['out']['data']['key']);
        $this->assertSame([], $result['out']['data']['entries']);
        $this->assertSame(0, $result['out']['data']['total']);
    }

    public function test_get_special_max_all_means_no_limit(): void
    {
        [$disp, $ws] = $this->makeDispatcher(['key' => 'popular', 'max' => 'all']);

        $capturedLimit = null;
        $ws->expects($this->once())->method('get_special_entries')
            ->willReturnCallback(function (string $key, int $offset, int $limit) use (&$capturedLimit) {
                $capturedLimit = $limit;
                return ['entries' => [], 'total' => 0];
            });

        $disp->handle('get_special');
        $this->assertSame(PHP_INT_MAX, $capturedLimit);
    }

    public function test_get_special_negative_limit_clamps_to_zero(): void
    {
        [$disp, $ws] = $this->makeDispatcher(['key' => 'popular', 'limit' => '-5']);

        $capturedLimit = null;
        $ws->expects($this->once())->method('get_special_entries')
            ->willReturnCallback(function (string $key, int $offset, int $limit) use (&$capturedLimit) {
                $capturedLimit = $limit;
                return ['entries' => [], 'total' => 0];
            });

        $disp->handle('get_special');
        $this->assertSame(0, $capturedLimit);
    }

    // ------------------------------------------------------------------
    // get_paginated_* — limit is clamped to [0, 100]
    // ------------------------------------------------------------------

    public function test_get_paginated_sections_clamps_limit_to_100(): void
    {
        [$disp, $ws] = $this->makeDispatcher(['limit' => '500']);

        $captured = null;
        $ws->expects($this->once())->method('get_paginated_sections')
            ->willReturnCallback(function (int $offset, int $limit) use (&$captured) {
                $captured = $limit;
                return [];
            });

        $disp->handle('get_paginated_sections');
        $this->assertSame(100, $captured);
    }

    // ------------------------------------------------------------------
    // get_entry — OAuth-protected watchlist enrichment
    // ------------------------------------------------------------------

    public function test_get_entry_with_widar_failure_sets_on_user_item_list_false(): void
    {
        // Throwable inside the Widar block (e.g. failed OAuth handshake)
        // must NOT crash the request — the response stays 200 OK with
        // on_user_item_list=false, preserving the cacheable shape.
        $widar = new class extends \Widar {
            public function get_user_id(): int {
                throw new \RuntimeException('OAuth verification failed');
            }
        };
        [$disp, $ws] = $this->makeDispatcher(['q' => '42'], $widar);

        $entry = (object) ['q' => 42, 'title' => 'Metropolis'];
        $ws->method('getEntry')->willReturn($entry);

        $result = $disp->handle('get_entry');

        $this->assertSame(200, $result['http_code']);
        $this->assertSame('OK', $result['out']['status']);
        $this->assertFalse($result['out']['data']->on_user_item_list);
    }

    public function test_get_entry_coerces_injection_in_q(): void
    {
        [$disp, $ws] = $this->makeDispatcher([
            'q' => '42; DROP TABLE `item`; --',
        ]);

        $capturedQ = null;
        $ws->expects($this->once())->method('getEntry')
            ->willReturnCallback(function ($q) use (&$capturedQ) {
                $capturedQ = $q;
                return null;
            });

        $disp->handle('get_entry');
        $this->assertSame(42, $capturedQ);
    }

    // ------------------------------------------------------------------
    // set_user_item_list — Widar failure becomes 500 + Request failed
    // ------------------------------------------------------------------

    public function test_set_user_item_list_widar_failure_returns_500(): void
    {
        $widar = new class extends \Widar {
            public function get_user_id(): int {
                throw new \RuntimeException('vendored Widar internals leaked');
            }
        };
        [$disp] = $this->makeDispatcher(['q' => '1', 'state' => '1'], $widar);

        $prev = ini_set('error_log', '/dev/null');
        try {
            $result = $disp->handle('set_user_item_list');
        } finally {
            if ($prev !== false) ini_set('error_log', $prev);
        }

        $this->assertSame(500, $result['http_code']);
        $this->assertSame('Request failed', $result['out']['status']);
        // Vendored message MUST NOT leak.
        $this->assertStringNotContainsString('vendored', json_encode($result['out']));
    }

    // ------------------------------------------------------------------
    // log — only the explicit q reaches logEvent when both q and
    // source_key/source_prop are supplied; otherwise fallback lookup
    // ------------------------------------------------------------------

    public function test_log_uses_explicit_q_when_supplied(): void
    {
        [$disp, $ws] = $this->makeDispatcher([
            'q'           => '42',
            'event'       => 'click',
            'source_key'  => 'abc',
            'source_prop' => '123',
        ]);
        $ws->expects($this->never())->method('getItemForFile');
        $ws->expects($this->once())->method('logEvent')->with('click', 42);

        $disp->handle('log');
    }

    public function test_log_falls_back_to_file_lookup_when_q_zero(): void
    {
        [$disp, $ws] = $this->makeDispatcher([
            'event'       => 'play',
            'source_key'  => 'Some File.webm',
            'source_prop' => '10',
        ]);
        $ws->expects($this->once())->method('getItemForFile')
            ->with('10', 'Some File.webm')
            ->willReturn(99);
        $ws->expects($this->once())->method('logEvent')->with('play', 99);

        $disp->handle('log');
    }

    // ------------------------------------------------------------------
    // get_section — missing item returns 404
    // ------------------------------------------------------------------

    public function test_get_section_missing_item_returns_404(): void
    {
        // WikidataItemList stub returns null from getItem() unless
        // setItem has been called — which it hasn't in this test.
        [$disp] = $this->makeDispatcher(['q' => '9999', 'prop' => '161']);

        $result = $disp->handle('get_section');

        $this->assertSame(404, $result['http_code']);
        $this->assertSame('No such item Q9999', $result['out']['status']);
        $this->assertSame('no-store', $result['cache_control']);
    }
}
