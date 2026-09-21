<?php

declare(strict_types=1);

namespace WikiStream\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * Unit tests for WikiStream::purge_deleted_commons_files() and its helpers.
 *
 * The tool DB and the commonswiki replica are both served by the same mocked
 * ToolforgeCommon; queries are told apart by their SQL text, so the tests
 * don't depend on the order in which the method issues them.
 */
final class PurgeDeletedCommonsFilesTest extends TestCase
{
    private function makeFakeDb(): object
    {
        return new class {
            public function real_escape_string(string $s): string
            {
                return addslashes($s);
            }
        };
    }

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

    /** Wrap plain strings as {name: ...} rows. */
    private function nameRows(array $names): object
    {
        return $this->makeResult(array_map(
            fn(string $n): object => (object) ['name' => $n],
            $names,
        ));
    }

    /**
     * Build a WikiStream whose getSQL answers from $answers: an ordered list
     * of [substring-to-match, result] pairs. The first pair whose substring
     * occurs in the SQL wins; unmatched queries return an empty result.
     *
     * Every SQL string issued is appended to $captured.
     */
    private function makeWikiStream(
        array $answers,
        array &$captured,
        ?\WikiStreamConfigWikiFlix $config = null,
    ): \WikiStream {
        $config ??= new \WikiStreamConfigWikiFlix();
        $config->db_statement_timeout_sec = 0;

        $db  = $this->makeFakeDb();
        $tfc = $this->createMock(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($db);
        $tfc->method('openDBwiki')->willReturn($db);

        $tfc->method('getSQL')
            ->willReturnCallback(function ($ignored, string $sql) use (&$captured, $answers) {
                $captured[] = $sql;
                foreach ($answers as [$needle, $result]) {
                    if (str_contains($sql, $needle)) {
                        return $result;
                    }
                }
                return $this->makeResult([]);
            });

        return new \WikiStream($config, $tfc);
    }

    /** SQL statements that write to the tool DB. */
    private function writes(array $captured): array
    {
        return array_values(array_filter(
            $captured,
            fn(string $sql): bool => str_starts_with($sql, 'DELETE') || str_starts_with($sql, 'UPDATE'),
        ));
    }

    // ------------------------------------------------------------------
    // commonsPageTitle()
    // ------------------------------------------------------------------

    #[DataProvider('provideTitles')]
    public function test_commonsPageTitle_normalisation(string $in, string $expected): void
    {
        $m = new ReflectionMethod(\WikiStream::class, 'commonsPageTitle');
        $this->assertSame($expected, $m->invoke(null, $in));
    }

    public static function provideTitles(): array
    {
        return [
            'spaces to underscores' => ['A Trip to the Moon.ogv', 'A_Trip_to_the_Moon.ogv'],
            'already underscored'   => ['A_Trip.ogv', 'A_Trip.ogv'],
            'File: prefix'          => ['File:A Trip.ogv', 'A_Trip.ogv'],
            'file: lowercase'       => ['file: A Trip.ogv', 'A_Trip.ogv'],
            'surrounding space'     => ['  A Trip.ogv  ', 'A_Trip.ogv'],
            'case untouched'        => ['éclair.ogv', 'éclair.ogv'],
            'empty'                 => ['   ', ''],
        ];
    }

    // ------------------------------------------------------------------
    // purge_deleted_commons_files()
    // ------------------------------------------------------------------

    public function test_deletes_file_rows_for_names_missing_from_commons(): void
    {
        $captured = [];
        $ws = $this->makeWikiStream([
            ['FROM `file` WHERE `property` IN', $this->nameRows(['Alive.ogv', 'Gone.ogv'])],
            ['FROM `page`',                     $this->nameRows(['Alive.ogv'])],
        ], $captured);

        $ws->purge_deleted_commons_files();

        $writes = $this->writes($captured);
        $delete = $writes[0] ?? '';
        $this->assertStringStartsWith('DELETE FROM `file`', $delete);
        $this->assertStringContainsString("'Gone.ogv'", $delete);
        $this->assertStringNotContainsString("'Alive.ogv'", $delete);
        // …and the image columns are cleared for the same name.
        $this->assertStringContainsString('UPDATE `item` SET `image`=NULL', implode("\n", $writes));
    }

    public function test_no_writes_when_every_name_still_exists(): void
    {
        $captured = [];
        $ws = $this->makeWikiStream([
            ['FROM `file` WHERE `property` IN', $this->nameRows(['Alive.ogv'])],
            ['FROM `page`',                     $this->nameRows(['Alive.ogv'])],
        ], $captured);

        $ws->purge_deleted_commons_files();

        $this->assertSame([], $this->writes($captured));
    }

    public function test_matches_stored_spelling_with_spaces_against_underscored_page_title(): void
    {
        $captured = [];
        $ws = $this->makeWikiStream([
            ['FROM `file` WHERE `property` IN', $this->nameRows(['A Trip to the Moon.ogv'])],
            ['FROM `page`',                     $this->nameRows(['A_Trip_to_the_Moon.ogv'])],
        ], $captured);

        $ws->purge_deleted_commons_files();

        $this->assertSame([], $this->writes($captured));
    }

    public function test_deletes_using_the_raw_stored_spelling_not_the_canonical_title(): void
    {
        $captured = [];
        $ws = $this->makeWikiStream([
            ['FROM `file` WHERE `property` IN', $this->nameRows(['A Trip to the Moon.ogv'])],
            // Page lookup finds nothing → the file is gone.
            ['FROM `page`',                     $this->nameRows(['Something_else.ogv'])],
        ], $captured);

        $ws->purge_deleted_commons_files();

        $delete = $this->writes($captured)[0] ?? '';
        $this->assertStringContainsString("'A Trip to the Moon.ogv'", $delete);
    }

    public function test_clears_image_columns_for_deleted_posters(): void
    {
        $captured = [];
        $ws = $this->makeWikiStream([
            ['FROM `item` WHERE `image`',   $this->nameRows(['Poster.jpg'])],
            ['FROM `page`',                 $this->nameRows(['Other.jpg'])],
        ], $captured);

        $ws->purge_deleted_commons_files();

        $writes = implode("\n", $this->writes($captured));
        foreach (['item', 'person', 'group'] as $table) {
            $this->assertStringContainsString("UPDATE `{$table}` SET `image`=NULL", $writes);
        }
        $this->assertStringContainsString("'Poster.jpg'", $writes);
    }

    public function test_aborts_when_too_many_names_look_deleted(): void
    {
        // 60 names, none found on Commons: over both the 5% fraction and the
        // 50-name absolute floor, so this must look like a broken lookup.
        $names = [];
        for ($i = 0; $i < 60; $i++) {
            $names[] = "File{$i}.ogv";
        }
        $captured = [];
        $ws = $this->makeWikiStream([
            ['FROM `file` WHERE `property` IN', $this->nameRows($names)],
            // One hit, so the per-chunk "no hits at all" guard does not fire.
            ['FROM `page`',                     $this->nameRows(['File0.ogv'])],
        ], $captured);

        $ws->purge_deleted_commons_files();

        $this->assertSame([], $this->writes($captured));
    }

    public function test_skips_a_chunk_that_returns_no_hits_at_all(): void
    {
        $captured = [];
        $ws = $this->makeWikiStream([
            ['FROM `file` WHERE `property` IN', $this->nameRows(['Gone.ogv'])],
            ['FROM `page`',                     $this->makeResult([])],
        ], $captured);

        $ws->purge_deleted_commons_files();

        $this->assertSame([], $this->writes($captured));
    }

    public function test_no_commons_lookup_when_the_db_has_no_file_names(): void
    {
        $captured = [];
        $ws = $this->makeWikiStream([], $captured);

        $ws->purge_deleted_commons_files();

        $this->assertSame([], $this->writes($captured));
        foreach ($captured as $sql) {
            $this->assertStringNotContainsString('FROM `page`', $sql);
        }
    }

    public function test_file_table_is_not_queried_when_no_commons_props_configured(): void
    {
        $config = new \WikiStreamConfigWikiFlix();
        $config->commons_file_props = [];
        $captured = [];
        $ws = $this->makeWikiStream([
            ['FROM `item` WHERE `image`', $this->nameRows(['Poster.jpg'])],
            ['FROM `page`',               $this->nameRows(['Other.jpg'])],
        ], $captured, $config);

        $ws->purge_deleted_commons_files();

        $writes = $this->writes($captured);
        foreach ($captured as $sql) {
            $this->assertStringNotContainsString('FROM `file`', $sql);
        }
        $this->assertStringContainsString('UPDATE `item` SET `image`=NULL', implode("\n", $writes));
    }

    public function test_both_configs_declare_commons_file_props_within_file_props(): void
    {
        foreach ([new \WikiStreamConfigWikiFlix(), new \WikiStreamConfigWikiVibes()] as $config) {
            $this->assertNotEmpty($config->commons_file_props);
            $this->assertSame(
                [],
                array_diff($config->commons_file_props, $config->file_props),
                get_class($config) . ': commons_file_props must be a subset of file_props',
            );
        }
    }
}
