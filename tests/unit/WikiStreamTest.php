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
    // ensure_user_exists() inserts (id,name) so the user.id matches the
    // MediaWiki user id used in user_item_list. INSERT IGNORE makes
    // subsequent logins a no-op.
    // ------------------------------------------------------------------

    public function test_ensure_user_exists_inserts_id_and_name(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $captured = '';
        $tfc->expects($this->once())
            ->method('getSQL')
            ->willReturnCallback(function ($db, string $sql) use (&$captured) {
                $captured = $sql;
                return $this->emptyResult();
            });

        $ws->ensure_user_exists(42, 'Magnus Manske');

        $this->assertStringContainsString('INSERT IGNORE', $captured);
        $this->assertStringContainsString('`user`', $captured);
        $this->assertStringContainsString('42', $captured);
        $this->assertStringContainsString("'Magnus Manske'", $captured);
    }

    public function test_ensure_user_exists_escapes_apostrophe_in_username(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $captured = '';
        $tfc->expects($this->once())
            ->method('getSQL')
            ->willReturnCallback(function ($db, string $sql) use (&$captured) {
                $captured = $sql;
                return $this->emptyResult();
            });

        $ws->ensure_user_exists(7, "O'Brien");

        // The fake db's real_escape_string is addslashes(), so ' -> \'
        $this->assertStringContainsString("'O\\'Brien'", $captured);
    }

    public function test_ensure_user_exists_casts_user_id_to_int(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $captured = '';
        $tfc->expects($this->once())
            ->method('getSQL')
            ->willReturnCallback(function ($db, string $sql) use (&$captured) {
                $captured = $sql;
                return $this->emptyResult();
            });

        // String input simulating $widar->get_user_id() that hasn't been
        // explicitly cast; the method must reject non-integer values.
        $ws->ensure_user_exists('99); DROP TABLE user;--', 'attacker');

        $this->assertStringContainsString('VALUES (99,', $captured);
        $this->assertStringNotContainsString('DROP', $captured);
    }

    public function test_ensure_user_exists_noop_on_empty_username(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();
        $tfc->expects($this->never())->method('getSQL');

        $ws->ensure_user_exists(5, '');
    }

    public function test_ensure_user_exists_noop_on_zero_user_id(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();
        $tfc->expects($this->never())->method('getSQL');

        $ws->ensure_user_exists(0, 'somebody');
    }

    // ------------------------------------------------------------------
    // get_sibling_group_entries() — fetch other items in the same
    // group(s) as the given item (e.g. other episodes of the same series).
    // ------------------------------------------------------------------

    /**
     * Build a fake sibling row as the JOIN query would produce.
     */
    private function siblingRow(int $group_q, string $group_title, int $q, string $title, $position = null): \stdClass
    {
        $row = new \stdClass();
        $row->group_q        = $group_q;
        $row->group_title    = $group_title;
        $row->q              = $q;
        $row->title          = $title;
        $row->image          = null;
        $row->year           = null;
        $row->minutes        = null;
        $row->sites          = 1;
        $row->ts             = '20260514000000';
        $row->ts_added       = '2026-05-14 00:00:00';
        $row->primary_type_q = 21191270;
        $row->files          = '[]';
        $row->is_silent      = 0;
        $row->group_position = $position;
        return $row;
    }

    public function test_get_sibling_group_entries_returns_empty_array_when_no_rows(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();
        $tfc->method('getSQL')->willReturn($this->emptyResult());

        $this->assertSame([], $ws->get_sibling_group_entries(123));
    }

    public function test_get_sibling_group_entries_returns_empty_for_invalid_q(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();
        $tfc->expects($this->never())->method('getSQL');

        $this->assertSame([], $ws->get_sibling_group_entries(0));
        $this->assertSame([], $ws->get_sibling_group_entries(-5));
    }

    public function test_get_sibling_group_entries_emits_sql_that_excludes_current_item(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $captured = '';
        $tfc->expects($this->once())
            ->method('getSQL')
            ->willReturnCallback(function ($db, string $sql) use (&$captured) {
                $captured = $sql;
                return $this->emptyResult();
            });

        $ws->get_sibling_group_entries(42);

        $this->assertStringContainsString('group_item', $captured);
        $this->assertStringContainsString('vw_ranked_entries', $captured);
        $normalized = str_replace([' ', '`'], '', $captured);
        // Current item is excluded from the sibling list
        $this->assertStringContainsString('item_q!=42', $normalized);
        // The sibling set is scoped to groups the current item belongs to
        $this->assertStringContainsString('item_q=42', $normalized);
    }

    public function test_get_sibling_group_entries_groups_rows_by_group_q(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $rows = [
            $this->siblingRow(100, 'Series A', 11, 'A-Ep-1', 1),
            $this->siblingRow(100, 'Series A', 12, 'A-Ep-2', 2),
            $this->siblingRow(200, 'Series B', 21, 'B-Ep-1', 1),
        ];
        $tfc->method('getSQL')->willReturn($this->makeResult($rows));

        $groups = $ws->get_sibling_group_entries(42);

        $this->assertCount(2, $groups);
        $this->assertSame(100, (int) $groups[0]->q);
        $this->assertSame('Series A', $groups[0]->title);
        $this->assertSame(2, $groups[0]->total);
        $this->assertCount(2, $groups[0]->entries);
        $this->assertSame(11, (int) $groups[0]->entries[0]->q);
        $this->assertSame(12, (int) $groups[0]->entries[1]->q);

        $this->assertSame(200, (int) $groups[1]->q);
        $this->assertSame('Series B', $groups[1]->title);
        $this->assertSame(1, $groups[1]->total);
        $this->assertCount(1, $groups[1]->entries);
        $this->assertSame(21, (int) $groups[1]->entries[0]->q);
    }

    public function test_get_sibling_group_entries_strips_join_only_fields_from_entries(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $rows = [$this->siblingRow(100, 'Series A', 11, 'A-Ep-1', 1)];
        $tfc->method('getSQL')->willReturn($this->makeResult($rows));

        $groups = $ws->get_sibling_group_entries(42);
        $entry  = $groups[0]->entries[0];

        // JOIN-only columns must not leak into the per-entry shape consumed by <entry-thumb>
        $this->assertObjectNotHasProperty('group_q', $entry);
        $this->assertObjectNotHasProperty('group_title', $entry);
        $this->assertObjectNotHasProperty('group_position', $entry);
        // Standard entry fields are preserved
        $this->assertSame(11, (int) $entry->q);
        $this->assertSame('A-Ep-1', $entry->title);
        $this->assertSame(21191270, (int) $entry->primary_type_q);
    }

    public function test_get_sibling_group_entries_decodes_files_via_fix_item_image(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $row = $this->siblingRow(100, 'Series A', 11, 'A-Ep-1', 1);
        $row->files = '[{"property":10,"key":"Some_file.webm","is_trailer":0,"minutes":12}]';
        $tfc->method('getSQL')->willReturn($this->makeResult([$row]));

        $groups = $ws->get_sibling_group_entries(42);
        $entry  = $groups[0]->entries[0];

        // fix_item_image json_decodes the `files` field
        $this->assertIsArray($entry->files);
        $this->assertSame(10, (int) $entry->files[0]->property);
    }

    public function test_get_sibling_group_entries_casts_q_safely(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $captured = '';
        $tfc->method('getSQL')
            ->willReturnCallback(function ($db, string $sql) use (&$captured) {
                $captured = $sql;
                return $this->emptyResult();
            });

        // SQL-injection-style payload must be reduced to the leading int
        $ws->get_sibling_group_entries('42); DROP TABLE `group_item`;--');

        $this->assertStringNotContainsString('DROP', $captured);
        $this->assertStringContainsString('42', $captured);
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
    // get_item_view — offset support
    // ------------------------------------------------------------------

    public function test_get_item_view_emits_offset_when_nonzero(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $captured = '';
        $tfc->expects($this->once())
            ->method('getSQL')
            ->willReturnCallback(function ($db, string $sql) use (&$captured) {
                $captured = $sql;
                return $this->emptyResult();
            });

        $ws->get_item_view('vw_ranked_entries', 25, null, null, 50);
        $this->assertStringContainsString('LIMIT 25', $captured);
        $this->assertStringContainsString('OFFSET 50', $captured);
    }

    public function test_get_item_view_omits_offset_when_zero(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $captured = '';
        $tfc->method('getSQL')
            ->willReturnCallback(function ($db, string $sql) use (&$captured) {
                $captured = $sql;
                return $this->emptyResult();
            });

        $ws->get_item_view('vw_ranked_entries', 25);
        $this->assertStringContainsString('LIMIT 25', $captured);
        $this->assertStringNotContainsString('OFFSET', $captured);
    }

    public function test_get_item_view_clamps_negative_offset(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $captured = '';
        $tfc->method('getSQL')
            ->willReturnCallback(function ($db, string $sql) use (&$captured) {
                $captured = $sql;
                return $this->emptyResult();
            });

        $ws->get_item_view('vw_ranked_entries', 25, null, null, -100);
        $this->assertStringNotContainsString('OFFSET', $captured);
    }

    // ------------------------------------------------------------------
    // get_special_entries — pagination shape
    // ------------------------------------------------------------------

    public function test_get_special_entries_returns_entries_and_total(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $entry = new \stdClass();
        $entry->q = 42;
        $entry->title = 'Foo';
        $entry->image = null;

        $count = new \stdClass();
        $count->cnt = 137;

        $callCount = 0;
        $tfc->method('getSQL')
            ->willReturnCallback(function ($db, string $sql) use (&$callCount, $entry, $count) {
                $callCount++;
                if (stripos($sql, 'SELECT *') === 0) {
                    $this->assertStringContainsString('OFFSET 50', $sql);
                    return $this->makeResult([$entry]);
                }
                if (stripos($sql, 'SELECT COUNT(*)') === 0) {
                    return $this->makeResult([$count]);
                }
                return $this->emptyResult();
            });

        $page = $ws->get_special_entries('popular_entries', 50, 25);
        $this->assertArrayHasKey('entries', $page);
        $this->assertArrayHasKey('total', $page);
        $this->assertCount(1, $page['entries']);
        $this->assertSame(42, $page['entries'][0]->q);
        $this->assertSame(137, $page['total']);
    }

    public function test_get_special_entries_unknown_key_delegates_to_config(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();
        // WikiFlix config returns ['entries' => [], 'total' => 0] for unknown keys.
        $tfc->method('getSQL')->willReturn($this->emptyResult());

        $page = $ws->get_special_entries('not_a_real_key', 0, 10);
        $this->assertSame(['entries' => [], 'total' => 0], $page);
    }

    // ------------------------------------------------------------------
    // populate_section — offset threads through
    // ------------------------------------------------------------------

    public function test_populate_section_threads_offset(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        // Stub item with a label.
        $j = new \stdClass();
        $j->id = 'Q1';
        $item = new \WikidataItem($j);
        // Override getLabel via a tiny anonymous subclass.
        $item = new class($j) extends \WikidataItem {
            public function getLabel(string $lang = 'en'): string { return 'Sample'; }
        };

        $section = (object) ['section_q' => 7, 'property' => 31];

        $offsetSql = '';
        $countResult = (object) ['cnt' => 99];
        $tfc->method('getSQL')
            ->willReturnCallback(function ($db, string $sql) use (&$offsetSql, $countResult) {
                if (stripos($sql, 'SELECT *') === 0) {
                    $offsetSql = $sql;
                    return $this->emptyResult();
                }
                if (stripos($sql, 'SELECT COUNT(*)') === 0) {
                    return $this->makeResult([$countResult]);
                }
                return $this->emptyResult();
            });

        $result = $ws->populate_section($section, $item, 25, 100);
        $this->assertSame(99, $result['total']);
        $this->assertSame('Sample', $result['title']);
        $this->assertStringContainsString('OFFSET 100', $offsetSql);
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

    // ------------------------------------------------------------------
    // getRandomEntryQ — does not use ORDER BY RAND()
    // ------------------------------------------------------------------

    public function test_getRandomEntryQ_uses_min_max_strategy(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $bounds          = new \stdClass();
        $bounds->lo      = 10;
        $bounds->hi      = 100;

        $pick     = new \stdClass();
        $pick->q  = 42;

        $callCount = 0;
        $tfc->method('getSQL')->willReturnCallback(
            function ($db, string $sql) use (&$callCount, $bounds, $pick) {
                $callCount++;
                if ($callCount === 1) {
                    $this->assertStringContainsString('MIN(q)', $sql);
                    $this->assertStringContainsString('MAX(q)', $sql);
                    $this->assertStringNotContainsString('RAND()', $sql);
                    return $this->makeResult([$bounds]);
                }
                $this->assertStringContainsString('WHERE `q` >=', $sql);
                $this->assertStringNotContainsString('RAND()', $sql);
                return $this->makeResult([$pick]);
            }
        );

        $q = $ws->getRandomEntryQ();
        $this->assertSame(42, $q);
        $this->assertSame(2, $callCount);
    }

    public function test_getRandomEntryQ_returns_null_on_empty_table(): void
    {
        [$ws, $tfc] = $this->makeWikiStream();

        $bounds          = new \stdClass();
        $bounds->lo      = null;
        $bounds->hi      = null;

        $tfc->expects($this->once())->method('getSQL')
            ->willReturn($this->makeResult([$bounds]));

        $this->assertNull($ws->getRandomEntryQ());
    }

    public function test_import_commons_video_minutes_no_rows_skips_http(): void
    {
        $httpClient = $this->createMock(\HttpClientInterface::class);
        $httpClient->expects($this->never())->method('getJson');

        [$ws, $tfc] = $this->makeWikiStream(httpClient: $httpClient);
        $tfc->method('getSQL')->willReturn($this->emptyResult());

        $ws->import_commons_video_minutes();
    }

    // ------------------------------------------------------------------
    // C1: annotate_pre_1900_public_domain() emits QuickStatements
    // commands setting P6216=Q19652 (public domain) with the
    // determination-method qualifier P459=Q47246828 ("published more
    // than 95 years ago") for films dated before 1900 that have no
    // existing P6216 statement. Hard-bounded by a per-run cap.
    // ------------------------------------------------------------------

    /**
     * Build a recording WikiStream that captures pushQuickStatements()
     * arguments instead of pushing them.
     *
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    private function makeQsCapturingWikiStream(\ToolforgeCommon $tfc): object
    {
        $config = new \WikiStreamConfigWikiFlix();
        return new class($config, $tfc, null) extends \WikiStream {
            public array $capturedCommands = [];
            protected function pushQuickStatements(array $commands): void
            {
                $this->capturedCommands = $commands;
            }
        };
    }

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function test_annotate_pre_1900_public_domain_emits_correct_qs_shape(): void
    {
        $db = $this->makeFakeDb();
        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($db);
        $tfc->method('getSPARQL_TSV')->willReturn([
            ['q' => 'http://www.wikidata.org/entity/Q1001'],
            ['q' => 'http://www.wikidata.org/entity/Q1002'],
        ]);
        $tfc->method('parseItemFromURL')->willReturnCallback(
            fn(string $url) => preg_match('~Q\d+$~', $url, $m) ? $m[0] : ''
        );

        $ws = $this->makeQsCapturingWikiStream($tfc);
        $ws->annotate_pre_1900_public_domain();

        $this->assertCount(2, $ws->capturedCommands);
        $joined = implode("\n", $ws->capturedCommands);
        // Each line is: "Q<id>\tP6216\tQ19652\tP459\tQ47246828\t/* comment */"
        $this->assertStringContainsString("Q1001\tP6216\tQ19652\tP459\tQ47246828", $joined);
        $this->assertStringContainsString("Q1002\tP6216\tQ19652\tP459\tQ47246828", $joined);
    }

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function test_annotate_pre_1900_public_domain_caps_per_run(): void
    {
        $db = $this->makeFakeDb();
        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($db);
        $rows = [];
        for ($q = 1; $q <= 200; $q++) {
            $rows[] = ['q' => "http://www.wikidata.org/entity/Q{$q}"];
        }
        $tfc->method('getSPARQL_TSV')->willReturn($rows);
        $tfc->method('parseItemFromURL')->willReturnCallback(
            fn(string $url) => preg_match('~Q\d+$~', $url, $m) ? $m[0] : ''
        );

        $ws = $this->makeQsCapturingWikiStream($tfc);
        $ws->annotate_pre_1900_public_domain();

        $this->assertSame(\WikiStream::PRE_1900_PD_PER_RUN, count($ws->capturedCommands));
    }

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function test_annotate_pre_1900_public_domain_no_candidates_skips_qs(): void
    {
        $db = $this->makeFakeDb();
        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($db);
        $tfc->method('getSPARQL_TSV')->willReturn([]);

        $ws = $this->makeQsCapturingWikiStream($tfc);
        $ws->annotate_pre_1900_public_domain();

        $this->assertCount(0, $ws->capturedCommands);
    }

    // ------------------------------------------------------------------
    // C3: import_ia_curated_imdb_p724() walks the curated IA collections
    // for items carrying an IMDb external-identifier, resolves the IMDb
    // ID to a Wikidata Q-id via P345 (skipping items that already have
    // P724), and queues a QuickStatements command to add the IA P724.
    // ------------------------------------------------------------------

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function test_import_ia_curated_imdb_p724_emits_qs_for_resolved_imdb(): void
    {
        $db = $this->makeFakeDb();
        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($db);

        // SPARQL resolves IMDb -> Wikidata Q. tt0001 -> Q501, tt0002 -> Q502.
        $tfc->method('getSPARQL_TSV')->willReturn([
            ['q' => 'http://www.wikidata.org/entity/Q501', 'imdb' => 'tt0001'],
            ['q' => 'http://www.wikidata.org/entity/Q502', 'imdb' => 'tt0002'],
        ]);
        $tfc->method('parseItemFromURL')->willReturnCallback(
            fn(string $url) => preg_match('~Q\d+$~', $url, $m) ? $m[0] : ''
        );

        // HTTP: IA search returns 3 results in feature_films, 1 in silent_films, 0 in prelinger.
        $http = $this->createMock(\HttpClientInterface::class);
        $http->method('getJson')->willReturnCallback(function (string $url) {
            $r = new \stdClass();
            $r->response = new \stdClass();
            $r->response->docs = [];
            if (str_contains($url, 'feature_films')) {
                $r->response->docs = [
                    (object) ['identifier' => 'ia-id-1', 'external-identifier' => 'urn:imdb:tt0001'],
                    (object) ['identifier' => 'ia-id-2', 'external-identifier' => 'urn:imdb:tt0002'],
                    (object) ['identifier' => 'ia-id-3', 'external-identifier' => 'urn:imdb:tt0003'], // unresolved
                ];
            }
            if (str_contains($url, 'silent_films')) {
                $r->response->docs = [
                    (object) ['identifier' => 'ia-id-1-dup', 'external-identifier' => 'urn:imdb:tt0001'], // dup IMDb
                ];
            }
            return $r;
        });

        $config = new \WikiStreamConfigWikiFlix();
        $ws = new class($config, $tfc, $http) extends \WikiStream {
            public array $capturedCommands = [];
            protected function pushQuickStatements(array $commands): void
            {
                $this->capturedCommands = $commands;
            }
        };
        $ws->import_ia_curated_imdb_p724();

        $this->assertCount(2, $ws->capturedCommands, 'Two resolved IMDb IDs should yield two commands.');
        $joined = implode("\n", $ws->capturedCommands);
        $this->assertStringContainsString("Q501\tP724\t\"ia-id-1\"", $joined);
        $this->assertStringContainsString("Q502\tP724\t\"ia-id-2\"", $joined);
        $this->assertStringNotContainsString('ia-id-3', $joined, 'Unresolved IMDb tt0003 yields no command.');
    }

    // ------------------------------------------------------------------
    // C2: import_commons_pd_films_via_p180() walks a whitelist of
    // Commons categories, restricts to video files, fetches each file's
    // Wikidata M-entity P180 (depicts) claim, and queues a QS command
    // to add P10 to film items that lack a P10 statement. Only files
    // explicitly linked to a film via P180 are accepted — title
    // matching is unsafe.
    // ------------------------------------------------------------------

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function test_import_commons_pd_films_via_p180_links_p180_films(): void
    {
        $db = $this->makeFakeDb();
        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($db);
        // SPARQL film-verification: Q700 is a film with no P10; Q701 is not a film.
        $tfc->method('getSPARQL_TSV')->willReturn([
            ['q' => 'http://www.wikidata.org/entity/Q700'],
        ]);
        $tfc->method('parseItemFromURL')->willReturnCallback(
            fn(string $url) => preg_match('~Q\d+$~', $url, $m) ? $m[0] : ''
        );

        $http = $this->createMock(\HttpClientInterface::class);
        $http->method('getJson')->willReturnCallback(function (string $url) {
            // Category members
            if (str_contains($url, 'list=categorymembers')) {
                $r = new \stdClass();
                $r->query = new \stdClass();
                $r->query->categorymembers = [
                    (object) ['title' => 'File:GoodVideo.webm'],
                    (object) ['title' => 'File:NotAVideo.jpg'], // filtered by extension
                    (object) ['title' => 'File:WrongTarget.webm'], // P180 points to Q701 (not a film)
                ];
                return $r;
            }
            // wbgetentities for a single file
            if (str_contains($url, 'action=wbgetentities')) {
                $r = new \stdClass();
                $r->entities = new \stdClass();
                if (str_contains($url, 'GoodVideo.webm')) {
                    $r->entities->M1 = (object) [
                        'claims' => (object) [
                            'P180' => [
                                (object) ['mainsnak' => (object) [
                                    'datavalue' => (object) [
                                        'value' => (object) ['id' => 'Q700'],
                                    ],
                                ]],
                            ],
                        ],
                    ];
                } elseif (str_contains($url, 'WrongTarget.webm')) {
                    $r->entities->M2 = (object) [
                        'claims' => (object) [
                            'P180' => [
                                (object) ['mainsnak' => (object) [
                                    'datavalue' => (object) [
                                        'value' => (object) ['id' => 'Q701'],
                                    ],
                                ]],
                            ],
                        ],
                    ];
                }
                return $r;
            }
            return null;
        });

        $config = new \WikiStreamConfigWikiFlix();
        $ws = new class($config, $tfc, $http) extends \WikiStream {
            public array $capturedCommands = [];
            protected function pushQuickStatements(array $commands): void
            {
                $this->capturedCommands = $commands;
            }
        };
        $ws->import_commons_pd_films_via_p180();

        $joined = implode("\n", $ws->capturedCommands);
        $this->assertStringContainsString("Q700\tP10\t\"GoodVideo.webm\"", $joined, 'P180-linked video to a P10-less film must be queued.');
        $this->assertStringNotContainsString('NotAVideo.jpg', $joined, 'Non-video files must be filtered by extension.');
        $this->assertStringNotContainsString('Q701', $joined, 'P180 targets not verified as P10-less films must be skipped.');
        $this->assertCount(1, $ws->capturedCommands);
    }

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function test_import_commons_pd_films_via_p180_no_ia_results_skips_sparql(): void
    {
        $db = $this->makeFakeDb();
        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($db);
        $tfc->expects($this->never())->method('getSPARQL_TSV');

        $http = $this->createMock(\HttpClientInterface::class);
        $http->method('getJson')->willReturnCallback(function () {
            $r = new \stdClass();
            $r->query = new \stdClass();
            $r->query->categorymembers = [];
            return $r;
        });

        $config = new \WikiStreamConfigWikiFlix();
        $ws = new class($config, $tfc, $http) extends \WikiStream {
            public array $capturedCommands = [];
            protected function pushQuickStatements(array $commands): void
            {
                $this->capturedCommands = $commands;
            }
        };
        $ws->import_commons_pd_films_via_p180();

        $this->assertCount(0, $ws->capturedCommands);
    }

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function test_import_ia_curated_imdb_p724_no_ia_results_skips_sparql(): void
    {
        $db = $this->makeFakeDb();
        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($db);
        $tfc->expects($this->never())->method('getSPARQL_TSV');

        $http = $this->createMock(\HttpClientInterface::class);
        $http->method('getJson')->willReturnCallback(function () {
            $r = new \stdClass();
            $r->response = new \stdClass();
            $r->response->docs = [];
            return $r;
        });

        $config = new \WikiStreamConfigWikiFlix();
        $ws = new class($config, $tfc, $http) extends \WikiStream {
            public array $capturedCommands = [];
            protected function pushQuickStatements(array $commands): void
            {
                $this->capturedCommands = $commands;
            }
        };
        $ws->import_ia_curated_imdb_p724();

        $this->assertCount(0, $ws->capturedCommands);
    }

    // ------------------------------------------------------------------
    // B5: the P11484 "do not use for WikiFlix" qualifier (value
    // Q124428688) must suppress file ingestion for ANY property in
    // $config->file_props — not just P10 / P724. Regression test
    // protects the universal opt-out behaviour at add_item_details.
    // ------------------------------------------------------------------

    /**
     * Build a Wikidata claim object with a string mainsnak value and an
     * optional P11484 opt-out qualifier.
     */
    private function makeFileClaim(string $key, bool $optOut = false): object
    {
        $claim = new \stdClass();
        $claim->mainsnak = new \stdClass();
        $claim->mainsnak->datavalue = new \stdClass();
        $claim->mainsnak->datavalue->value = $key;
        $claim->mainsnak->datavalue->type = 'string';
        if ($optOut) {
            $claim->qualifiers = new \stdClass();
            $qual = new \stdClass();
            $qual->datavalue = new \stdClass();
            $qual->datavalue->value = (object) ['id' => 'Q124428688'];
            $claim->qualifiers->P11484 = [$qual];
        }
        return $claim;
    }

    /**
     * `$ws` matches the file-wide convention for the WikiStream-under-test
     * variable; suppress PHPMD's ShortVariable rule for this method only.
     *
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function test_p11484_opt_out_applies_to_all_file_props(): void
    {
        [$ws] = $this->makeWikiStream();

        // One claim per file property; mix opted-out and clean.
        $claims = [
            10    => [
                $this->makeFileClaim('Clean-Commons.webm'),
                $this->makeFileClaim('OptedOut-Commons.webm', optOut: true),
            ],
            724   => [$this->makeFileClaim('clean-ia-id')],
            1651  => [$this->makeFileClaim('optedout-yt-id', optOut: true)],
            4015  => [$this->makeFileClaim('clean-vimeo-id')],
            11731 => [$this->makeFileClaim('optedout-dm-id', optOut: true)],
        ];

        // Only getClaims is overridden; the bootstrap stub's defaults
        // (empty label, empty sitelinks, empty getFirstString/getTarget)
        // are sufficient for the opt-out code path we're exercising.
        $item = new class($claims) extends \WikidataItem {
            private array $claimsByProp;
            public function __construct(array $claimsByProp)
            {
                parent::__construct((object) ['id' => 'Q42']);
                $this->claimsByProp = $claimsByProp;
            }
            public function getClaims(string|int $prop): array
            {
                $key = (int) preg_replace('/\D/', '', (string) $prop);
                return $this->claimsByProp[$key] ?? [];
            }
        };

        $wil = new \WikidataItemList();
        $wil->setItem(42, $item);

        $qs = [];
        $sections = [];
        $entry_files = [];
        $items_for_labels = []; // array form so add_item_details defers labels.
        $item_rows = [];

        // add_item_details is protected and takes references. bindTo() (the
        // instance form of Closure::bind) lets the closure see protected
        // methods while keeping by-reference args working — reflection's
        // invokeArgs cannot pass references through.
        $invoke = function ($wil, $q, &$qs, &$sections, &$entry_files, &$items_for_labels, &$item_rows) {
            $this->add_item_details($wil, $q, $qs, $sections, $entry_files, $items_for_labels, $item_rows);
        };
        $invoke = $invoke->bindTo($ws, \WikiStream::class);
        $invoke($wil, 42, $qs, $sections, $entry_files, $items_for_labels, $item_rows);

        // Clean files for every property are kept; opted-out keys are dropped.
        $joined = implode("\n", $entry_files);
        $this->assertStringContainsString('Clean-Commons.webm', $joined, 'Clean P10 file must be kept.');
        $this->assertStringContainsString('clean-ia-id', $joined, 'Clean P724 file must be kept.');
        $this->assertStringContainsString('clean-vimeo-id', $joined, 'Clean P4015 file must be kept.');

        $this->assertStringNotContainsString('OptedOut-Commons.webm', $joined, 'P10 opt-out must filter the file.');
        $this->assertStringNotContainsString('optedout-yt-id', $joined, 'P1651 opt-out must filter the file.');
        $this->assertStringNotContainsString('optedout-dm-id', $joined, 'P11731 opt-out must filter the file.');

        $this->assertCount(3, $entry_files, 'Exactly 3 clean files (one per non-opted-out claim) must be ingested.');
    }

    // A4: WikiStream::parseP953Url() maps a wdt:P953 URL to a (file-prop,
    // key) pair so the bot can add the corresponding native file-host
    // property (P10/P724/P1651/P4015/P11731). Pure function, table-driven
    // tests.
    // ------------------------------------------------------------------

    public static function provideP953UrlCases(): array
    {
        return [
            ['https://archive.org/details/CharlieChaplin_TheGoldRush',          [724,  'CharlieChaplin_TheGoldRush']],
            ['http://archive.org/details/some-id',                              [724,  'some-id']],
            ['https://www.archive.org/details/another',                         [724,  'another']],
            ['https://archive.org/embed/abc',                                   null],
            ['https://www.youtube.com/watch?v=dQw4w9WgXcQ',                     [1651, 'dQw4w9WgXcQ']],
            ['https://youtube.com/watch?v=abc123XYZ',                           [1651, 'abc123XYZ']],
            ['https://m.youtube.com/watch?v=zzzz_-9999',                        [1651, 'zzzz_-9999']],
            ['https://www.youtube.com/embed/dQw4w9WgXcQ',                       [1651, 'dQw4w9WgXcQ']],
            ['https://youtu.be/dQw4w9WgXcQ',                                    [1651, 'dQw4w9WgXcQ']],
            ['https://vimeo.com/12345678',                                      [4015, '12345678']],
            ['https://player.vimeo.com/video/12345678',                         [4015, '12345678']],
            ['https://vimeo.com/notnumeric',                                    null],
            ['https://www.dailymotion.com/video/x7tgad0',                       [11731, 'x7tgad0']],
            ['https://dailymotion.com/video/abcd',                              [11731, 'abcd']],
            ['https://commons.wikimedia.org/wiki/File:Big_Buck_Bunny.webm',     [10,   'Big_Buck_Bunny.webm']],
            ['https://commons.m.wikimedia.org/wiki/File:Sintel.webm',           [10,   'Sintel.webm']],
            ['https://commons.wikimedia.org/wiki/File:Some%20Title.webm',       [10,   'Some Title.webm']],
            ['',                                                                null],
            ['not a url at all',                                                null],
            ['https://example.com/random',                                      null],
            ['https://twitter.com/some/post',                                   null],
            ['https://www.youtube.com/channel/UCabcd',                          null],
        ];
    }

    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideP953UrlCases')]
    public function test_parseP953Url(string $url, ?array $expected): void
    {
        $this->assertSame($expected, \WikiStream::parseP953Url($url));
    }

    // ------------------------------------------------------------------
    // A4: import_p953_urls() promotes wdt:P953 URLs into native host
    // properties via QuickStatements; honours the P11484 opt-out
    // qualifier and a per-run cap.
    // ------------------------------------------------------------------

    private function makeP953Claim(string $url, bool $optOut = false): object
    {
        $claim = new \stdClass();
        $claim->mainsnak = new \stdClass();
        $claim->mainsnak->datavalue = new \stdClass();
        $claim->mainsnak->datavalue->value = $url;
        $claim->mainsnak->datavalue->type = 'string';
        if ($optOut) {
            $claim->qualifiers = new \stdClass();
            $qual = new \stdClass();
            $qual->datavalue = new \stdClass();
            $qual->datavalue->value = (object) ['id' => 'Q124428688'];
            $claim->qualifiers->P11484 = [$qual];
        }
        return $claim;
    }

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    private function makeRecordingWikiStream(\WikidataItemList $wil, \ToolforgeCommon $tfc): object
    {
        $config = new \WikiStreamConfigWikiFlix();
        return new class($config, $tfc, null, $wil) extends \WikiStream {
            public array $capturedCommands = [];
            private \WikidataItemList $injectedWil;
            public function __construct($config, $tfc, $http, \WikidataItemList $wil)
            {
                parent::__construct($config, $tfc, $http);
                $this->injectedWil = $wil;
            }
            protected function loadWikidataItemList(array $qs): \WikidataItemList
            {
                unset($qs);
                return $this->injectedWil;
            }
            protected function pushQuickStatements(array $commands): void
            {
                $this->capturedCommands = $commands;
            }
        };
    }

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function test_import_p953_urls_emits_commands_for_known_hosts(): void
    {
        $db = $this->makeFakeDb();
        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($db);
        $tfc->method('getSPARQL_TSV')->willReturn([
            ['q' => 'http://www.wikidata.org/entity/Q42'],
        ]);
        $tfc->method('parseItemFromURL')->willReturnCallback(
            fn(string $url) => preg_match('~Q\d+$~', $url, $m) ? $m[0] : ''
        );

        $claims = [
            $this->makeP953Claim('https://archive.org/details/foo'),
            $this->makeP953Claim('https://www.youtube.com/watch?v=AAAAAAAAAAA'),
            $this->makeP953Claim('https://example.com/unsupported'),
            $this->makeP953Claim('https://vimeo.com/123', optOut: true),
        ];
        $item = new class($claims) extends \WikidataItem {
            private array $p953;
            public function __construct(array $p953)
            {
                parent::__construct((object) ['id' => 'Q42']);
                $this->p953 = $p953;
            }
            public function getClaims(string|int $prop): array
            {
                $key = (int) preg_replace('/\D/', '', (string) $prop);
                return $key === 953 ? $this->p953 : [];
            }
        };

        $wil = new \WikidataItemList();
        $wil->setItem(42, $item);

        $ws = $this->makeRecordingWikiStream($wil, $tfc);
        $ws->import_p953_urls();

        $this->assertCount(2, $ws->capturedCommands);

        $joined = implode("\n", $ws->capturedCommands);
        $this->assertStringContainsString("Q42\tP724\t\"foo\"", $joined);
        $this->assertStringContainsString("Q42\tP1651\t\"AAAAAAAAAAA\"", $joined);
        $this->assertStringNotContainsString('123', $joined);
    }

    // ------------------------------------------------------------------
    // A5: import_ia_curated_films() INSERTs items whose IA collection is
    // in the curated whitelist.
    // ------------------------------------------------------------------

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function test_import_ia_curated_films_inserts_only_whitelisted_collections(): void
    {
        $db = $this->makeFakeDb();
        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($db);
        $tfc->method('getSPARQL_TSV')->willReturn([
            ['q' => 'http://www.wikidata.org/entity/Q101', 'ia' => 'feature-film-1'],
            ['q' => 'http://www.wikidata.org/entity/Q102', 'ia' => 'random-clip'],
            ['q' => 'http://www.wikidata.org/entity/Q103', 'ia' => 'silent-film-1'],
            ['q' => 'http://www.wikidata.org/entity/Q104', 'ia' => 'dark-item'],
        ]);
        $tfc->method('parseItemFromURL')->willReturnCallback(
            fn(string $url) => preg_match('~Q\d+$~', $url, $m) ? $m[0] : ''
        );

        $insertSqls = [];
        $tfc->method('getSQL')->willReturnCallback(function ($db, string $sql) use (&$insertSqls) {
            if (stripos($sql, 'INSERT') === 0) {
                $insertSqls[] = $sql;
            }
            return $this->emptyResult();
        });

        $http = $this->createMock(\HttpClientInterface::class);
        $http->method('getJsonBatch')->willReturnCallback(function (array $urlsByKey) {
            $byQ = [];
            foreach ($urlsByKey as $q => $url) {
                $j = new \stdClass();
                $j->metadata = new \stdClass();
                if (str_contains($url, 'feature-film-1')) {
                    $j->metadata->collection = ['feature_films_unsorted', 'feature_films'];
                } elseif (str_contains($url, 'silent-film-1')) {
                    $j->metadata->collection = ['silent_films'];
                } elseif (str_contains($url, 'random-clip')) {
                    $j->metadata->collection = ['some_random_collection'];
                } elseif (str_contains($url, 'dark-item')) {
                    $j->is_dark = true;
                }
                $byQ[$q] = $j;
            }
            return $byQ;
        });

        $config = new \WikiStreamConfigWikiFlix();
        $ws = new \WikiStream($config, $tfc, $http);
        $ws->import_ia_curated_films();

        $joined = implode("\n", $insertSqls);
        $this->assertStringContainsString('101', $joined);
        $this->assertStringContainsString('103', $joined);
        $this->assertStringNotContainsString('102', $joined);
        $this->assertStringNotContainsString('104', $joined);
    }

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function test_import_ia_curated_films_no_candidates_skips_http(): void
    {
        $db = $this->makeFakeDb();
        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($db);
        $tfc->method('getSPARQL_TSV')->willReturn([]);

        $http = $this->createMock(\HttpClientInterface::class);
        $http->expects($this->never())->method('getJsonBatch');
        $http->expects($this->never())->method('getJson');

        $config = new \WikiStreamConfigWikiFlix();
        $ws = new \WikiStream($config, $tfc, $http);
        $ws->import_ia_curated_films();
    }

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function test_import_ia_curated_films_caps_per_run(): void
    {
        $db = $this->makeFakeDb();
        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($db);

        $rows = [];
        for ($q = 1; $q <= 200; $q++) {
            $rows[] = ['q' => "http://www.wikidata.org/entity/Q{$q}", 'ia' => "ia-id-{$q}"];
        }
        $tfc->method('getSPARQL_TSV')->willReturn($rows);
        $tfc->method('parseItemFromURL')->willReturnCallback(
            fn(string $url) => preg_match('~Q\d+$~', $url, $m) ? $m[0] : ''
        );
        $tfc->method('getSQL')->willReturn($this->emptyResult());

        $http = $this->createMock(\HttpClientInterface::class);
        $batchCalls = 0;
        $http->method('getJsonBatch')->willReturnCallback(function (array $urlsByKey) use (&$batchCalls) {
            $batchCalls++;
            $byQ = [];
            foreach (array_keys($urlsByKey) as $q) {
                $j = new \stdClass();
                $j->metadata = new \stdClass();
                $j->metadata->collection = ['feature_films'];
                $byQ[$q] = $j;
            }
            return $byQ;
        });

        $config = new \WikiStreamConfigWikiFlix();
        $ws = new \WikiStream($config, $tfc, $http);
        $ws->import_ia_curated_films();

        $this->assertGreaterThan(0, $batchCalls);
    }

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function test_import_p953_urls_caps_commands_per_run(): void
    {
        $db = $this->makeFakeDb();
        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($db);

        $rows = [];
        for ($q = 1; $q <= 150; $q++) {
            $rows[] = ['q' => "http://www.wikidata.org/entity/Q{$q}"];
        }
        $tfc->method('getSPARQL_TSV')->willReturn($rows);
        $tfc->method('parseItemFromURL')->willReturnCallback(
            fn(string $url) => preg_match('~Q\d+$~', $url, $m) ? $m[0] : ''
        );

        $wil = new \WikidataItemList();
        for ($q = 1; $q <= 150; $q++) {
            $claim = $this->makeP953Claim("https://archive.org/details/id{$q}");
            $item = new class([$claim], $q) extends \WikidataItem {
                private array $p953;
                public function __construct(array $p953, int $q)
                {
                    parent::__construct((object) ['id' => "Q{$q}"]);
                    $this->p953 = $p953;
                }
                public function getClaims(string|int $prop): array
                {
                    $key = (int) preg_replace('/\D/', '', (string) $prop);
                    return $key === 953 ? $this->p953 : [];
                }
            };
            $wil->setItem($q, $item);
        }

        $ws = $this->makeRecordingWikiStream($wil, $tfc);
        $ws->import_p953_urls();

        $this->assertSame(\WikiStream::P953_COMMANDS_PER_RUN, count($ws->capturedCommands));
    }

    // ------------------------------------------------------------------
    // Episode / group ingestion
    //
    // 1. add_item_details derives item.primary_type_q from P31, preferring
    //    matches in $config->episode_type_qs.
    // 2. add_item_details collects (group_q, item_q, position) tuples from
    //    $config->group_membership_prop, reading the position from the
    //    P1545 series-ordinal qualifier.
    // 3. update_from_sparql only runs episode_sparql queries when
    //    $config->include_episodes is true.
    // ------------------------------------------------------------------

    /**
     * Build a Wikidata claim object whose target is an item Q-id.
     */
    private function makeItemClaim(string $targetQ, ?string $ordinal = null): object
    {
        $claim = new \stdClass();
        $claim->rank = 'normal';
        $claim->mainsnak = new \stdClass();
        $claim->mainsnak->datavalue = new \stdClass();
        $claim->mainsnak->datavalue->value = (object) [
            'entity-type' => 'item',
            'numeric-id'  => (int) preg_replace('/\D/', '', $targetQ),
            'id'          => $targetQ,
        ];
        $claim->mainsnak->datavalue->type = 'wikibase-entityid';
        if ($ordinal !== null) {
            $claim->qualifiers = new \stdClass();
            $qual = new \stdClass();
            $qual->datavalue = new \stdClass();
            $qual->datavalue->value = $ordinal;
            $qual->datavalue->type  = 'string';
            $claim->qualifiers->P1545 = [$qual];
        }
        return $claim;
    }

    /**
     * Build a WikidataItem stub with the given P31/P179/etc. claim map.
     * `getTarget` returns the target Q-id of a claim (mirrors the real
     * helper). All file-prop claim arrays default to empty.
     */
    private function makeClaimsItem(int $q, array $claimsByProp): \WikidataItem
    {
        return new class($q, $claimsByProp) extends \WikidataItem {
            private array $claimsByProp;
            public function __construct(int $q, array $claimsByProp)
            {
                parent::__construct((object) ['id' => "Q{$q}", 'labels' => new \stdClass()]);
                $this->claimsByProp = $claimsByProp;
            }
            public function getClaims(string|int $prop): array
            {
                $key = (int) preg_replace('/\D/', '', (string) $prop);
                return $this->claimsByProp[$key] ?? [];
            }
            public function getTarget(object $claim): string
            {
                return $claim->mainsnak->datavalue->value->id ?? '';
            }
        };
    }

    /**
     * Invoke the protected add_item_details with by-reference buffers and
     * an extra `$group_items` buffer the new implementation must accept.
     * Returns the buffers so tests can assert on them.
     *
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    private function invokeAddItemDetails(\WikiStream $ws, \WikidataItemList $wil, int $q): array
    {
        $qs = $sections = $entry_files = $items_for_labels = $item_rows = $group_items = [];
        $invoke = function ($wil, $q, &$qs, &$sections, &$entry_files, &$items_for_labels, &$item_rows, &$group_items) {
            $this->add_item_details($wil, $q, $qs, $sections, $entry_files, $items_for_labels, $item_rows, $group_items);
        };
        $invoke = $invoke->bindTo($ws, \WikiStream::class);
        $invoke($wil, $q, $qs, $sections, $entry_files, $items_for_labels, $item_rows, $group_items);
        return [
            'qs'           => $qs,
            'sections'     => $sections,
            'entry_files'  => $entry_files,
            'item_rows'    => $item_rows,
            'group_items'  => $group_items,
        ];
    }

    public function test_add_item_details_primary_type_q_uses_episode_match_when_present(): void
    {
        // Item has two P31 claims: Q11424 (film) AND Q21191270 (episode).
        // Because Q21191270 is in episode_type_qs, primary_type_q must be 21191270.
        [$ws] = $this->makeWikiStream();
        $item = $this->makeClaimsItem(5583524, [
            31 => [
                $this->makeItemClaim('Q11424'),
                $this->makeItemClaim('Q21191270'),
            ],
        ]);
        $wil = new \WikidataItemList();
        $wil->setItem(5583524, $item);

        $out = $this->invokeAddItemDetails($ws, $wil, 5583524);

        $this->assertCount(1, $out['item_rows']);
        $row = $out['item_rows'][0];
        // item_rows is a list of SQL VALUES tuples. We accept any
        // representation that names primary_type_q=21191270 in the row.
        $this->assertStringContainsString('21191270', $row);
    }

    public function test_add_item_details_primary_type_q_falls_back_to_first_p31_when_no_episode_match(): void
    {
        // Only P31=Q11424 (film). Result must be 11424.
        [$ws] = $this->makeWikiStream();
        $item = $this->makeClaimsItem(123, [
            31 => [$this->makeItemClaim('Q11424')],
        ]);
        $wil = new \WikidataItemList();
        $wil->setItem(123, $item);

        $out = $this->invokeAddItemDetails($ws, $wil, 123);

        $this->assertCount(1, $out['item_rows']);
        $this->assertStringContainsString('11424', $out['item_rows'][0]);
    }

    public function test_add_item_details_primary_type_q_is_null_when_no_p31(): void
    {
        [$ws] = $this->makeWikiStream();
        $item = $this->makeClaimsItem(456, []);
        $wil = new \WikidataItemList();
        $wil->setItem(456, $item);

        $out = $this->invokeAddItemDetails($ws, $wil, 456);

        $this->assertCount(1, $out['item_rows']);
        // The item_rows tuple must encode primary_type_q as SQL NULL.
        $this->assertMatchesRegularExpression('/,NULL\)$/i', $out['item_rows'][0]);
    }

    public function test_add_item_details_collects_group_item_with_position_from_p1545(): void
    {
        // Episode of Mr. Bean (Q484020), ordinal "13".
        [$ws] = $this->makeWikiStream();
        $item = $this->makeClaimsItem(5583524, [
            31  => [$this->makeItemClaim('Q21191270')],
            179 => [$this->makeItemClaim('Q484020', ordinal: '13')],
        ]);
        $wil = new \WikidataItemList();
        $wil->setItem(5583524, $item);

        $out = $this->invokeAddItemDetails($ws, $wil, 5583524);

        $this->assertCount(1, $out['group_items'], 'one P179 → one group_item row');
        $tuple = $out['group_items'][0];
        // Tuple format: (group_q, item_q, position-or-NULL)
        $this->assertStringContainsString('484020',  $tuple);
        $this->assertStringContainsString('5583524', $tuple);
        $this->assertMatchesRegularExpression('/,\s*13(\.0+)?\)$/', $tuple);
    }

    public function test_add_item_details_group_item_position_null_for_non_numeric_ordinal(): void
    {
        // Some series use "S01E13"-style ordinals — store position as NULL.
        [$ws] = $this->makeWikiStream();
        $item = $this->makeClaimsItem(5583524, [
            31  => [$this->makeItemClaim('Q21191270')],
            179 => [$this->makeItemClaim('Q484020', ordinal: 'S01E13')],
        ]);
        $wil = new \WikidataItemList();
        $wil->setItem(5583524, $item);

        $out = $this->invokeAddItemDetails($ws, $wil, 5583524);

        $this->assertCount(1, $out['group_items']);
        $this->assertMatchesRegularExpression('/,NULL\)$/i', $out['group_items'][0]);
    }

    public function test_add_item_details_skips_group_collection_when_membership_prop_disabled(): void
    {
        // With WikiVibes (group_membership_prop=0), P179 must NOT be collected
        // into group_items even if the item claims it.
        $config = new \WikiStreamConfigWikiVibes();
        $db  = $this->makeFakeDb();
        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($db);
        $ws  = new \WikiStream($config, $tfc);

        $item = $this->makeClaimsItem(789, [
            31  => [$this->makeItemClaim('Q11424')],
            179 => [$this->makeItemClaim('Q484020', ordinal: '1')],
        ]);
        $wil = new \WikidataItemList();
        $wil->setItem(789, $item);

        $out = $this->invokeAddItemDetails($ws, $wil, 789);
        $this->assertSame([], $out['group_items']);
    }

    public function test_update_from_sparql_includes_episode_queries_when_switch_on(): void
    {
        $config = new class extends \WikiStreamConfigWikiFlix {
            public $sparql = ['SELECT ?q { ?q wdt:P31 wd:Q11424 }'];
            public $episode_sparql = ['SELECT ?q { ?q wdt:P31 wd:Q21191270 }'];
            public $bad_genres = [];
            public $include_episodes = true;
        };

        $callsBySparql = [];
        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($this->makeFakeDb());
        $tfc->method('getSPARQL_TSV')->willReturnCallback(function (string $sparql) use (&$callsBySparql) {
            $callsBySparql[] = $sparql;
            return [];
        });
        $tfc->method('getSQL')->willReturn($this->emptyResult());

        $ws = new \WikiStream($config, $tfc);
        $ws->update_from_sparql();

        $joined = implode("\n", $callsBySparql);
        $this->assertStringContainsString('wd:Q11424',    $joined, 'main SPARQL must run');
        $this->assertStringContainsString('wd:Q21191270', $joined, 'episode SPARQL must run when include_episodes=true');
    }

    public function test_update_from_sparql_skips_episode_queries_when_switch_off(): void
    {
        $config = new class extends \WikiStreamConfigWikiFlix {
            public $sparql = ['SELECT ?q { ?q wdt:P31 wd:Q11424 }'];
            public $episode_sparql = ['SELECT ?q { ?q wdt:P31 wd:Q21191270 }'];
            public $bad_genres = [];
            public $include_episodes = false;
        };

        $callsBySparql = [];
        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($this->makeFakeDb());
        $tfc->method('getSPARQL_TSV')->willReturnCallback(function (string $sparql) use (&$callsBySparql) {
            $callsBySparql[] = $sparql;
            return [];
        });
        $tfc->method('getSQL')->willReturn($this->emptyResult());

        $ws = new \WikiStream($config, $tfc);
        $ws->update_from_sparql();

        $joined = implode("\n", $callsBySparql);
        $this->assertStringContainsString('wd:Q11424',    $joined, 'main SPARQL must still run');
        $this->assertStringNotContainsString('wd:Q21191270', $joined, 'episode SPARQL must be skipped');
    }
}
