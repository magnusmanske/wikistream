<?php

declare(strict_types=1);

namespace WikiStream\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Unit tests for WikiStream public and protected methods that depend on the
 * database ($tfc / $db) or the HTTP client.
 *
 * All external dependencies are replaced with PHPUnit mocks or hand-rolled
 * fakes – no real database or network calls are made.
 */
final class WikiStreamTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build a WikiStream instance with a mocked ToolforgeCommon and a
     * fake mysqli-like $db object.
     *
     * @return array{0: \WikiStream, 1: MockObject, 2: object}
     */
    private function makeWikiStream(
        ?\WikiStreamConfigWikiFlix $config = null,
        ?\HttpClientInterface $httpClient = null,
    ): array {
        $config ??= new \WikiStreamConfigWikiFlix();

        $db  = $this->makeFakeDb();
        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($db);

        $ws = new \WikiStream($config, $tfc, $httpClient);

        return [$ws, $tfc, $db];
    }

    /**
     * Return a minimal object that satisfies the $db interface used by
     * WikiStream (real_escape_string + the result of getSQL is handled
     * by $tfc, so $db only needs real_escape_string here).
     */
    private function makeFakeDb(): object
    {
        return new class {
            public function real_escape_string(string $s): string
            {
                // Minimal escaping sufficient for tests
                return addslashes($s);
            }
        };
    }

    /**
     * Return a fake mysqli result that yields the given rows one by one
     * from fetch_object(), then returns false.
     */
    private function makeResult(array $rows): object
    {
        return new class($rows) {
            private int $index = 0;
            public function __construct(private array $rows) {}
            public function fetch_object(): object|false
            {
                return $this->rows[$this->index++] ?? false;
            }
        };
    }

    /** Return a result that immediately returns false (empty result set). */
    private function emptyResult(): object
    {
        return $this->makeResult([]);
    }

    // ------------------------------------------------------------------
    // search_entries() / search_people() / search_sections()
    // empty-query guard – must return [] without touching the DB
    // ------------------------------------------------------------------

    public function test_search_entries_empty_string_returns_empty_array(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();
        $tfc->expects($this->never())->method('getSQL');

        $this->assertSame([], $ws->search_entries(''));
    }

    public function test_search_entries_whitespace_only_returns_empty_array(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();
        $tfc->expects($this->never())->method('getSQL');

        $this->assertSame([], $ws->search_entries('   '));
    }

    public function test_search_people_empty_string_returns_empty_array(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();
        $tfc->expects($this->never())->method('getSQL');

        $this->assertSame([], $ws->search_people(''));
    }

    public function test_search_people_whitespace_only_returns_empty_array(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();
        $tfc->expects($this->never())->method('getSQL');

        $this->assertSame([], $ws->search_people('   '));
    }

    public function test_search_sections_empty_string_returns_empty_array(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();
        $tfc->expects($this->never())->method('getSQL');

        $this->assertSame([], $ws->search_sections(''));
    }

    public function test_search_sections_whitespace_only_returns_empty_array(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();
        $tfc->expects($this->never())->method('getSQL');

        $this->assertSame([], $ws->search_sections('   '));
    }

    // ------------------------------------------------------------------
    // search_entries() with a non-empty query issues a SQL query and
    // returns the result rows (with fix_item_image applied).
    // ------------------------------------------------------------------

    public function test_search_entries_non_empty_query_returns_rows(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $row          = new \stdClass();
        $row->title   = 'Metropolis';
        $row->image   = 'poster.jpg';
        // No `files` property → fix_item_image returns the object unchanged

        $tfc->expects($this->once())
            ->method('getSQL')
            ->willReturn($this->makeResult([$row]));

        $results = $ws->search_entries('Metropolis');

        $this->assertCount(1, $results);
        $this->assertSame('Metropolis', $results[0]->title);
    }

    // ------------------------------------------------------------------
    // search_people() with a non-empty query returns rows from the DB.
    // ------------------------------------------------------------------

    public function test_search_people_non_empty_query_returns_rows(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $row        = new \stdClass();
        $row->q     = 42;
        $row->label = 'Charlie Chaplin';

        $tfc->expects($this->once())
            ->method('getSQL')
            ->willReturn($this->makeResult([$row]));

        $results = $ws->search_people('Chaplin');

        $this->assertCount(1, $results);
        $this->assertSame('Charlie Chaplin', $results[0]->label);
    }

    // ------------------------------------------------------------------
    // getPersonsBatch() with an empty array must not call the DB at all.
    // ------------------------------------------------------------------

    public function test_getPersonsBatch_empty_array_returns_empty_without_db_call(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();
        $tfc->expects($this->never())->method('getSQL');

        $method = new ReflectionMethod(\WikiStream::class, 'getPersonsBatch');
        $result = $method->invoke($ws, []);

        $this->assertSame([], $result);
    }

    // ------------------------------------------------------------------
    // getPersonsBatch() with Q-numbers issues one SQL query and returns
    // rows keyed by numeric Q-id.
    // ------------------------------------------------------------------

    public function test_getPersonsBatch_returns_rows_keyed_by_q(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $p1        = new \stdClass();
        $p1->q     = 10;
        $p1->label = 'Buster Keaton';
        $p1->gender = 'M';
        $p1->image  = null;

        $p2        = new \stdClass();
        $p2->q     = 20;
        $p2->label = 'Mary Pickford';
        $p2->gender = 'F';
        $p2->image  = 'pickford.jpg';

        $tfc->expects($this->once())
            ->method('getSQL')
            ->willReturn($this->makeResult([$p1, $p2]));

        $method = new ReflectionMethod(\WikiStream::class, 'getPersonsBatch');
        $result = $method->invoke($ws, [10, 20]);

        $this->assertArrayHasKey(10, $result);
        $this->assertArrayHasKey(20, $result);
        $this->assertSame('Buster Keaton', $result[10]->label);
        $this->assertSame('Mary Pickford', $result[20]->label);
    }

    // ------------------------------------------------------------------
    // getPersonsBatch() casts Q-numbers to int for the array key.
    // ------------------------------------------------------------------

    public function test_getPersonsBatch_keys_are_integers(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $p       = new \stdClass();
        $p->q    = '7';   // DB may return a string
        $p->label = 'Harold Lloyd';
        $p->gender = 'M';
        $p->image  = null;

        $tfc->method('getSQL')->willReturn($this->makeResult([$p]));

        $method = new ReflectionMethod(\WikiStream::class, 'getPersonsBatch');
        $result = $method->invoke($ws, [7]);

        $this->assertArrayHasKey(7, $result);
        $this->assertIsInt(array_key_first($result));
    }

    // ------------------------------------------------------------------
    // get_item_view_count() with no section_q issues a COUNT query and
    // returns the integer from the result.
    // ------------------------------------------------------------------

    public function test_get_item_view_count_returns_integer_from_db(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $row      = new \stdClass();
        $row->cnt = '42';  // DB often returns strings

        $tfc->expects($this->once())
            ->method('getSQL')
            ->with($this->anything(), $this->stringContains('COUNT(*)'))
            ->willReturn($this->makeResult([$row]));

        $method = new ReflectionMethod(\WikiStream::class, 'get_item_view_count');
        $result = $method->invoke($ws, 'vw_ranked_entries_blacklist');

        $this->assertSame(42, $result);
        $this->assertIsInt($result);
    }

    // ------------------------------------------------------------------
    // get_item_view_count() with a section_q appends an IN subquery.
    // ------------------------------------------------------------------

    public function test_get_item_view_count_with_section_q_adds_subquery(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $row      = new \stdClass();
        $row->cnt = '5';

        $tfc->expects($this->once())
            ->method('getSQL')
            ->with(
                $this->anything(),
                $this->logicalAnd(
                    $this->stringContains('COUNT(*)'),
                    $this->stringContains('section_q=99'),
                ),
            )
            ->willReturn($this->makeResult([$row]));

        $method = new ReflectionMethod(\WikiStream::class, 'get_item_view_count');
        $result = $method->invoke($ws, 'vw_ranked_entries_blacklist', 99);

        $this->assertSame(5, $result);
    }

    // ------------------------------------------------------------------
    // get_item_view_count() returns 0 when the result set is empty.
    // ------------------------------------------------------------------

    public function test_get_item_view_count_returns_zero_on_empty_result(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $tfc->method('getSQL')->willReturn($this->emptyResult());

        $method = new ReflectionMethod(\WikiStream::class, 'get_item_view_count');
        $result = $method->invoke($ws, 'vw_ranked_entries_blacklist');

        $this->assertSame(0, $result);
    }

    // ------------------------------------------------------------------
    // logEvent() uses $q_safe (not raw $q) in the SQL statement.
    // When $q is null, the literal string "null" appears in the SQL.
    // ------------------------------------------------------------------

    public function test_logEvent_null_q_uses_null_literal_in_sql(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $tfc->method('getCurrentTimestamp')->willReturn('20240101120000');

        $tfc->expects($this->once())
            ->method('getSQL')
            ->with(
                $this->anything(),
                $this->stringContains(',null,'),
            );

        $ws->logEvent('play');
    }

    // ------------------------------------------------------------------
    // logEvent() with a numeric $q uses the cast integer value in SQL,
    // not the raw input.
    // ------------------------------------------------------------------

    public function test_logEvent_numeric_q_uses_integer_in_sql(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $tfc->method('getCurrentTimestamp')->willReturn('20240101120000');

        $tfc->expects($this->once())
            ->method('getSQL')
            ->with(
                $this->anything(),
                $this->logicalAnd(
                    $this->stringContains(',42,'),
                    // The raw un-cast value should NOT be a string like "42abc"
                    $this->logicalNot($this->stringContains('42abc')),
                ),
            );

        $ws->logEvent('play', 42);
    }

    // ------------------------------------------------------------------
    // HttpClient injection: the injected client is called by
    // get_json_from_url() rather than the default CurlHttpClient.
    // ------------------------------------------------------------------

    public function test_http_client_is_called_by_get_json_from_url(): void
    {
        $fakeResponse = (object) ['status' => 'ok'];

        $httpClient = $this->createMock(\HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('getJson')
            ->with('https://example.com/test')
            ->willReturn($fakeResponse);

        [$ws] = $this->makeWikiStream(httpClient: $httpClient);

        $method = new ReflectionMethod(\WikiStream::class, 'get_json_from_url');
        $result = $method->invoke($ws, 'https://example.com/test');

        $this->assertSame($fakeResponse, $result);
    }

    // ------------------------------------------------------------------
    // HttpClient injection: when the client returns null (e.g. curl
    // failure), get_json_from_url() propagates null to the caller.
    // ------------------------------------------------------------------

    public function test_http_client_returning_null_propagates_null(): void
    {
        $httpClient = $this->createMock(\HttpClientInterface::class);
        $httpClient->method('getJson')->willReturn(null);

        [$ws] = $this->makeWikiStream(httpClient: $httpClient);

        $method = new ReflectionMethod(\WikiStream::class, 'get_json_from_url');
        $result = $method->invoke($ws, 'https://example.com/fail');

        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    // is_user_watching_item() returns true when the DB has a matching row.
    // ------------------------------------------------------------------

    public function test_is_user_watching_item_returns_true_when_row_exists(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $row     = new \stdClass();
        $row->id = 1;

        $tfc->method('getSQL')->willReturn($this->makeResult([$row]));

        $this->assertTrue($ws->is_user_watching_item(5, 99));
    }

    // ------------------------------------------------------------------
    // is_user_watching_item() returns false when the DB has no row.
    // ------------------------------------------------------------------

    public function test_is_user_watching_item_returns_false_when_no_row(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $tfc->method('getSQL')->willReturn($this->emptyResult());

        $this->assertFalse($ws->is_user_watching_item(5, 99));
    }

    // ------------------------------------------------------------------
    // get_total_candidate_items() returns the integer total from the DB.
    // ------------------------------------------------------------------

    public function test_get_total_candidate_items_returns_integer(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $row        = new \stdClass();
        $row->total = '137';

        $tfc->method('getSQL')->willReturn($this->makeResult([$row]));

        $result = $ws->get_total_candidate_items();

        $this->assertSame(137, (int) $result);
    }

    // ------------------------------------------------------------------
    // get_total_candidate_items() returns 0 when the result is empty.
    // ------------------------------------------------------------------

    public function test_get_total_candidate_items_returns_zero_on_empty(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $tfc->method('getSQL')->willReturn($this->emptyResult());

        $this->assertSame(0, $ws->get_total_candidate_items());
    }

    // ------------------------------------------------------------------
    // import_commons_video_minutes — batched titles, title-normalisation,
    // duplicate-key handling
    // ------------------------------------------------------------------

    public function test_import_commons_video_minutes_batches_titles_and_maps_back(): void
    {
        // Two rows. Note row #1 has an underscore in its key — the API returns
        // titles with spaces, so the canonical mapping must handle it.
        $row1 = (object) ['id' => 101, 'key' => 'My_Movie.webm'];
        $row2 = (object) ['id' => 102, 'key' => 'Other Film.ogv'];

        // Commons response for the batched titles=A|B call.
        $apiResponse = (object) [
            'query' => (object) [
                'pages' => (object) [
                    '1' => (object) [
                        'title' => 'File:My Movie.webm', // normalised from underscore
                        'imageinfo' => [(object) [
                            'metadata' => [
                                (object) ['name' => 'playtime_seconds', 'value' => '120'],
                            ],
                        ]],
                    ],
                    '2' => (object) [
                        'title' => 'File:Other Film.ogv',
                        'imageinfo' => [(object) [
                            'metadata' => [
                                (object) ['name' => 'length', 'value' => '300'],
                            ],
                        ]],
                    ],
                ],
            ],
        ];

        $httpClient = $this->createMock(\HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('getJson')
            ->with($this->stringContains('titles='))
            ->willReturn($apiResponse);

        [$ws, $tfc] = $this->makeWikiStream(httpClient: $httpClient);

        $selectResult = $this->makeResult([$row1, $row2]);
        $updates = [];
        $tfc->method('getSQL')
            ->willReturnCallback(function ($db, string $sql) use ($selectResult, &$updates) {
                if (stripos($sql, 'SELECT') === 0) {
                    return $selectResult;
                }
                $updates[] = $sql;
                return $this->emptyResult();
            });

        $ws->import_commons_video_minutes();

        // playtime_seconds 120 → 2 min for id 101; length 300 → 5 min for id 102.
        $this->assertCount(2, $updates);
        $this->assertMatchesRegularExpression('/`minutes`=2 .* id=101/', $updates[0]);
        $this->assertMatchesRegularExpression('/`minutes`=5 .* id=102/', $updates[1]);
    }

    public function test_import_commons_video_minutes_skips_missing_imageinfo(): void
    {
        $row = (object) ['id' => 10, 'key' => 'Missing.webm'];
        $apiResponse = (object) [
            'query' => (object) [
                'pages' => (object) [
                    '-1' => (object) ['title' => 'File:Missing.webm'], // no imageinfo
                ],
            ],
        ];
        $httpClient = $this->createMock(\HttpClientInterface::class);
        $httpClient->method('getJson')->willReturn($apiResponse);

        [$ws, $tfc] = $this->makeWikiStream(httpClient: $httpClient);

        $selectResult = $this->makeResult([$row]);
        $updates = [];
        $tfc->method('getSQL')
            ->willReturnCallback(function ($db, string $sql) use ($selectResult, &$updates) {
                if (stripos($sql, 'SELECT') === 0) {
                    return $selectResult;
                }
                $updates[] = $sql;
                return $this->emptyResult();
            });

        $ws->import_commons_video_minutes();
        $this->assertSame([], $updates);
    }

    // ------------------------------------------------------------------
    // update_item_labels_batch — single DELETE + single INSERT per call
    // ------------------------------------------------------------------

    private function makeItemWithLabels(int $q, array $labels): \WikidataItem
    {
        $j = new \stdClass();
        $j->id = "Q{$q}";
        $j->labels = (object) [];
        foreach ($labels as $lang => $value) {
            $j->labels->{$lang} = (object) ['value' => $value];
        }
        $item = new \WikidataItem($j);
        return $item;
    }

    public function test_update_item_labels_batch_empty_does_nothing(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();
        $tfc->expects($this->never())->method('getSQL');

        $method = new ReflectionMethod(\WikiStream::class, 'update_item_labels_batch');
        $method->invoke($ws, []);
    }

    public function test_update_item_labels_batch_emits_one_delete_and_one_insert(): void
    {
        $item1 = $this->makeItemWithLabels(7, ['en' => 'Buster', 'fr' => 'Buster (fr)']);
        $item2 = $this->makeItemWithLabels(8, ['en' => 'Mary']);

        [$ws, $tfc] = $this->makeWikiStream();
        $sqls = [];
        $tfc->method('getSQL')->willReturnCallback(function ($db, string $sql) use (&$sqls) {
            $sqls[] = $sql;
            return $this->emptyResult();
        });

        $method = new ReflectionMethod(\WikiStream::class, 'update_item_labels_batch');
        $method->invoke($ws, [$item1, $item2]);

        $this->assertCount(2, $sqls, 'one DELETE and one INSERT');
        $this->assertMatchesRegularExpression('/^DELETE FROM `label`.*\b7\b.*\b8\b/', $sqls[0]);
        $this->assertStringContainsString('INSERT IGNORE INTO `label`', $sqls[1]);
        $this->assertStringContainsString("(7,'en','Buster')", $sqls[1]);
        $this->assertStringContainsString("(7,'fr','Buster (fr)')", $sqls[1]);
        $this->assertStringContainsString("(8,'en','Mary')", $sqls[1]);
    }

    public function test_update_item_labels_batch_no_labels_still_deletes(): void
    {
        // Item with no labels — its prior label rows should still be cleared.
        $item = $this->makeItemWithLabels(99, []);

        [$ws, $tfc] = $this->makeWikiStream();
        $sqls = [];
        $tfc->method('getSQL')->willReturnCallback(function ($db, string $sql) use (&$sqls) {
            $sqls[] = $sql;
            return $this->emptyResult();
        });

        $method = new ReflectionMethod(\WikiStream::class, 'update_item_labels_batch');
        $method->invoke($ws, [$item]);

        $this->assertCount(1, $sqls);
        $this->assertStringStartsWith('DELETE FROM `label`', $sqls[0]);
    }

    public function test_import_commons_video_minutes_no_rows_skips_http(): void
    {
        $httpClient = $this->createMock(\HttpClientInterface::class);
        $httpClient->expects($this->never())->method('getJson');

        [$ws, $tfc] = $this->makeWikiStream(httpClient: $httpClient);
        $tfc->method('getSQL')->willReturn($this->emptyResult());

        $ws->import_commons_video_minutes();
    }
}
