<?php

declare(strict_types=1);

namespace WikiStream\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for WikiStream::get_earliest_year().
 *
 * The method is protected and operates on an item-like object whose only
 * required method is getClaims(). We pass anonymous-class fakes rather than
 * mocking the full WikidataItem, keeping the tests self-contained.
 */
final class GetEarliestYearTest extends TestCase
{
    private \WikiStream $ws;
    private ReflectionMethod $method;

    protected function setUp(): void
    {
        $tfc = $this->createStub(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn(new \stdClass());

        $this->ws     = new \WikiStream(new \WikiStreamConfigWikiFlix(), $tfc);
        $this->method = new ReflectionMethod(\WikiStream::class, 'get_earliest_year');
    }

    private function invoke(object $item, string $property): mixed
    {
        return $this->method->invoke($this->ws, $item, $property);
    }

    // ------------------------------------------------------------------
    // Helpers to build fake claim objects
    // ------------------------------------------------------------------

    /** Build a normal (preferred/normal rank) claim with a Wikibase time value. */
    private static function claim(string $timeValue, string $rank = 'normal'): object
    {
        return (object) [
            'rank'     => $rank,
            'mainsnak' => (object) [
                'datavalue' => (object) [
                    'value' => (object) [
                        'time' => $timeValue,
                    ],
                ],
            ],
        ];
    }

    /** Build a claim that is missing the datavalue entirely. */
    private static function claimWithoutDatavalue(): object
    {
        return (object) [
            'rank'     => 'normal',
            'mainsnak' => (object) [],   // no datavalue key
        ];
    }

    /** Build a claim that is missing the mainsnak entirely. */
    private static function claimWithoutMainsnak(): object
    {
        return (object) ['rank' => 'normal'];
    }

    /** Fake item whose getClaims() returns the provided array. */
    private static function itemWith(array $claims): object
    {
        return new class($claims) {
            public function __construct(private array $claims) {}
            public function getClaims(mixed $prop): array { return $this->claims; }
        };
    }

    // ------------------------------------------------------------------
    // No claims at all → "null"
    // ------------------------------------------------------------------

    public function test_no_claims_returns_null_string(): void
    {
        $item = self::itemWith([]);

        $this->assertSame('null', $this->invoke($item, 'P577'));
    }

    // ------------------------------------------------------------------
    // Single normal claim with a valid date → returns the year as int
    // ------------------------------------------------------------------

    public function test_single_valid_claim_returns_year(): void
    {
        $item = self::itemWith([
            self::claim('+1925-03-15T00:00:00Z'),
        ]);

        $this->assertSame(1925, $this->invoke($item, 'P577'));
    }

    // ------------------------------------------------------------------
    // Deprecated claims are skipped entirely
    // ------------------------------------------------------------------

    public function test_deprecated_claim_is_skipped(): void
    {
        $item = self::itemWith([
            self::claim('+1925-01-01T00:00:00Z', 'deprecated'),
        ]);

        $this->assertSame('null', $this->invoke($item, 'P577'));
    }

    // ------------------------------------------------------------------
    // Mix of deprecated and normal → only non-deprecated year is returned
    // ------------------------------------------------------------------

    public function test_deprecated_claim_skipped_non_deprecated_used(): void
    {
        $item = self::itemWith([
            self::claim('+1910-06-01T00:00:00Z', 'deprecated'),
            self::claim('+1925-01-01T00:00:00Z', 'normal'),
        ]);

        $this->assertSame(1925, $this->invoke($item, 'P577'));
    }

    // ------------------------------------------------------------------
    // Multiple valid claims → the earliest year is returned
    // ------------------------------------------------------------------

    public function test_multiple_valid_claims_returns_earliest(): void
    {
        $item = self::itemWith([
            self::claim('+1928-00-00T00:00:00Z'),
            self::claim('+1921-00-00T00:00:00Z'),
            self::claim('+1935-00-00T00:00:00Z'),
        ]);

        $this->assertSame(1921, $this->invoke($item, 'P577'));
    }

    // ------------------------------------------------------------------
    // preferred rank is treated the same as normal (not deprecated)
    // ------------------------------------------------------------------

    public function test_preferred_rank_claim_is_included(): void
    {
        $item = self::itemWith([
            self::claim('+1932-00-00T00:00:00Z', 'preferred'),
        ]);

        $this->assertSame(1932, $this->invoke($item, 'P577'));
    }

    // ------------------------------------------------------------------
    // Claim missing mainsnak is skipped gracefully
    // ------------------------------------------------------------------

    public function test_claim_without_mainsnak_is_skipped(): void
    {
        $item = self::itemWith([
            self::claimWithoutMainsnak(),
            self::claim('+1930-00-00T00:00:00Z'),
        ]);

        $this->assertSame(1930, $this->invoke($item, 'P577'));
    }

    // ------------------------------------------------------------------
    // Claim missing datavalue is skipped gracefully
    // ------------------------------------------------------------------

    public function test_claim_without_datavalue_is_skipped(): void
    {
        $item = self::itemWith([
            self::claimWithoutDatavalue(),
            self::claim('+1918-00-00T00:00:00Z'),
        ]);

        $this->assertSame(1918, $this->invoke($item, 'P577'));
    }

    // ------------------------------------------------------------------
    // Time value that does not start with +YYYY does not match the regex
    // and is skipped – only the valid claim contributes
    // ------------------------------------------------------------------

    public function test_non_matching_time_format_is_skipped(): void
    {
        $item = self::itemWith([
            self::claim('unknown'),          // no leading +YYYY
            self::claim('+1915-00-00T00:00:00Z'),
        ]);

        $this->assertSame(1915, $this->invoke($item, 'P577'));
    }

    // ------------------------------------------------------------------
    // All claims have non-matching time formats → "null"
    // ------------------------------------------------------------------

    public function test_all_non_matching_formats_returns_null_string(): void
    {
        $item = self::itemWith([
            self::claim('unknown'),
            self::claim(''),
        ]);

        $this->assertSame('null', $this->invoke($item, 'P577'));
    }

    // ------------------------------------------------------------------
    // Year is returned as an integer, not a string
    // ------------------------------------------------------------------

    public function test_returned_year_is_integer(): void
    {
        $item = self::itemWith([
            self::claim('+1899-12-31T00:00:00Z'),
        ]);

        $result = $this->invoke($item, 'P577');

        $this->assertIsInt($result);
        $this->assertSame(1899, $result);
    }
}
