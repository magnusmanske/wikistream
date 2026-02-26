<?php

declare(strict_types=1);

namespace WikiStream\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for WikiStreamConfig and the two concrete config classes.
 *
 * These are pure data/dispatch tests – no database or network access.
 */
final class ConfigTest extends TestCase
{
    // ------------------------------------------------------------------
    // WikiStreamConfigWikiFlix shape
    // ------------------------------------------------------------------

    public function test_wikiflix_toolkey(): void
    {
        $config = new \WikiStreamConfigWikiFlix();
        $this->assertSame('wikiflix', $config->toolkey);
    }

    public function test_wikiflix_tool_db(): void
    {
        $config = new \WikiStreamConfigWikiFlix();
        $this->assertSame('wikiflix_p', $config->tool_db);
    }

    public function test_wikiflix_has_people_props(): void
    {
        $config = new \WikiStreamConfigWikiFlix();
        $this->assertNotEmpty($config->people_props);
        $this->assertContainsOnly('int', $config->people_props);
    }

    public function test_wikiflix_has_misc_section_props(): void
    {
        $config = new \WikiStreamConfigWikiFlix();
        $this->assertNotEmpty($config->misc_section_props);
        $this->assertContainsOnly('int', $config->misc_section_props);
    }

    public function test_wikiflix_has_file_props(): void
    {
        $config = new \WikiStreamConfigWikiFlix();
        $this->assertNotEmpty($config->file_props);
        $this->assertContainsOnly('int', $config->file_props);
    }

    public function test_wikiflix_has_sparql_queries(): void
    {
        $config = new \WikiStreamConfigWikiFlix();
        $this->assertNotEmpty($config->sparql);
        foreach ($config->sparql as $sparql) {
            $this->assertIsString($sparql);
            $this->assertNotEmpty($sparql);
        }
    }

    public function test_wikiflix_bad_genres_are_integers(): void
    {
        $config = new \WikiStreamConfigWikiFlix();
        $this->assertIsArray($config->bad_genres);
        $this->assertContainsOnly('int', $config->bad_genres);
    }

    public function test_wikiflix_interface_config_has_required_keys(): void
    {
        $config = new \WikiStreamConfigWikiFlix();
        $this->assertArrayHasKey('toolname',        $config->interface_config);
        $this->assertArrayHasKey('missing_icon',    $config->interface_config);
        $this->assertArrayHasKey('performer_prop',  $config->interface_config);
        $this->assertArrayHasKey('help_page',       $config->interface_config);
    }

    public function test_wikiflix_interface_config_toolname(): void
    {
        $config = new \WikiStreamConfigWikiFlix();
        $this->assertSame('wikiflix', $config->interface_config['toolname']);
    }

    public function test_wikiflix_skip_section_q_are_integers(): void
    {
        $config = new \WikiStreamConfigWikiFlix();
        $this->assertIsArray($config->skip_section_q);
        $this->assertContainsOnly('int', $config->skip_section_q);
    }

    public function test_wikiflix_grouping_props_is_array(): void
    {
        $config = new \WikiStreamConfigWikiFlix();
        $this->assertIsArray($config->grouping_props);
    }

    public function test_wikiflix_blacklist_page_is_string(): void
    {
        $config = new \WikiStreamConfigWikiFlix();
        $this->assertIsString($config->blacklist_page);
    }

    // ------------------------------------------------------------------
    // WikiStreamConfigWikiVibes shape
    // ------------------------------------------------------------------

    public function test_wikivibes_toolkey(): void
    {
        $config = new \WikiStreamConfigWikiVibes();
        $this->assertSame('wikivibes', $config->toolkey);
    }

    public function test_wikivibes_tool_db(): void
    {
        $config = new \WikiStreamConfigWikiVibes();
        $this->assertSame('vibes_p', $config->tool_db);
    }

    public function test_wikivibes_has_people_props(): void
    {
        $config = new \WikiStreamConfigWikiVibes();
        $this->assertNotEmpty($config->people_props);
        $this->assertContainsOnly('int', $config->people_props);
    }

    public function test_wikivibes_has_more_people_props_than_wikiflix(): void
    {
        // WikiVibes has 6 people props (author, composer, performer, etc.)
        // WikiFlix has 2 (actor, director). This is a structural sanity check.
        $vibes = new \WikiStreamConfigWikiVibes();
        $flix  = new \WikiStreamConfigWikiFlix();
        $this->assertGreaterThan(count($flix->people_props), count($vibes->people_props));
    }

    public function test_wikivibes_has_sparql_queries(): void
    {
        $config = new \WikiStreamConfigWikiVibes();
        $this->assertNotEmpty($config->sparql);
        foreach ($config->sparql as $sparql) {
            $this->assertIsString($sparql);
            $this->assertNotEmpty($sparql);
        }
    }

    public function test_wikivibes_bad_genres_is_empty_array(): void
    {
        // WikiVibes has no bad genres defined
        $config = new \WikiStreamConfigWikiVibes();
        $this->assertIsArray($config->bad_genres);
        $this->assertEmpty($config->bad_genres);
    }

    public function test_wikivibes_interface_config_has_required_keys(): void
    {
        $config = new \WikiStreamConfigWikiVibes();
        $this->assertArrayHasKey('toolname',        $config->interface_config);
        $this->assertArrayHasKey('missing_icon',    $config->interface_config);
        $this->assertArrayHasKey('performer_prop',  $config->interface_config);
        $this->assertArrayHasKey('help_page',       $config->interface_config);
    }

    public function test_wikivibes_interface_config_toolname(): void
    {
        $config = new \WikiStreamConfigWikiVibes();
        $this->assertSame('wikivibes', $config->interface_config['toolname']);
    }

    public function test_wikivibes_grouping_props_contains_361(): void
    {
        // WikiVibes groups by P361 (part of); WikiFlix does not
        $config = new \WikiStreamConfigWikiVibes();
        $this->assertContains(361, $config->grouping_props);
    }

    public function test_wikivibes_file_props_contains_51(): void
    {
        // P51 is the audio file property for WikiVibes
        $config = new \WikiStreamConfigWikiVibes();
        $this->assertContains(51, $config->file_props);
    }

    public function test_wikivibes_blacklist_page_is_empty_string(): void
    {
        $config = new \WikiStreamConfigWikiVibes();
        $this->assertSame('', $config->blacklist_page);
    }

    // ------------------------------------------------------------------
    // The two configs share the same skip_section_q values
    // (Q838368 and Q226730 are skipped in both)
    // ------------------------------------------------------------------

    public function test_both_configs_share_skip_section_q(): void
    {
        $flix  = new \WikiStreamConfigWikiFlix();
        $vibes = new \WikiStreamConfigWikiVibes();

        $this->assertSame($flix->skip_section_q, $vibes->skip_section_q);
    }

    // ------------------------------------------------------------------
    // add_special_sections() on WikiVibes is a no-op (does not add to $out)
    // ------------------------------------------------------------------

    public function test_wikivibes_add_special_sections_is_noop(): void
    {
        $config = new \WikiStreamConfigWikiVibes();

        // We need a WikiStream instance as the first argument, but
        // add_special_sections for WikiVibes does nothing with it.
        $tfc = $this->createStub(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn(new \stdClass());
        $ws = new \WikiStream($config, $tfc);

        $out = ['sections' => []];
        $config->add_special_sections($ws, $out);

        $this->assertEmpty($out['sections']);
    }

    // ------------------------------------------------------------------
    // add_special_sections() on WikiFlix adds exactly one extra section
    // (Female directors). We verify the section key exists without
    // exercising the DB query (entries will be empty with our stub).
    // ------------------------------------------------------------------

    public function test_wikiflix_add_special_sections_adds_female_directors_section(): void
    {
        $config = new \WikiStreamConfigWikiFlix();

        $db = new class {
            public function real_escape_string(string $s): string { return addslashes($s); }
        };

        $emptyResult = new class {
            public function fetch_object(): false { return false; }
        };

        $tfc = $this->createStub(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn($db);
        $tfc->method('getSQL')->willReturn($emptyResult);

        $ws  = new \WikiStream($config, $tfc);
        $out = ['sections' => []];
        $config->add_special_sections($ws, $out);

        $this->assertCount(1, $out['sections']);
        $this->assertArrayHasKey('title',   $out['sections'][0]);
        $this->assertArrayHasKey('entries', $out['sections'][0]);
        $this->assertSame('Female directors', $out['sections'][0]['title']);
    }

    // ------------------------------------------------------------------
    // WikiFlix performer_prop is P161 (cast member / actor)
    // WikiVibes performer_prop is P175 (performer)
    // ------------------------------------------------------------------

    public function test_wikiflix_performer_prop_is_P161(): void
    {
        $config = new \WikiStreamConfigWikiFlix();
        $this->assertSame('P161', $config->interface_config['performer_prop']);
    }

    public function test_wikivibes_performer_prop_is_P175(): void
    {
        $config = new \WikiStreamConfigWikiVibes();
        $this->assertSame('P175', $config->interface_config['performer_prop']);
    }
}
