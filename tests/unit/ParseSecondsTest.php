<?php

declare(strict_types=1);

namespace WikiStream\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for WikiStream::parse_seconds().
 *
 * The method is private static, so we invoke it via ReflectionMethod.
 * Every regex branch is covered, including the < 120 second filter.
 */
final class ParseSecondsTest extends TestCase
{
    private static function parse(string $input): int
    {
        $m = new ReflectionMethod(\WikiStream::class, 'parse_seconds');
        return $m->invoke(null, $input);
    }

    // -----------------------------------------------------------------------
    // Branch 1: H:MM:SS  (regex: ^(\d+)[,:](\d+)[:\'](\d+)$)
    // -----------------------------------------------------------------------

    #[DataProvider('providerHmmss')]
    public function test_hmmss_format(string $input, int $expected): void
    {
        $this->assertSame($expected, self::parse($input));
    }

    public static function providerHmmss(): array
    {
        return [
            'colon separators 1:23:45' => ['1:23:45',  5025],
            'colon separators 0:02:00' => ['0:02:00',   120],
            'comma+colon 1,23:45'      => ['1,23:45',  5025],
            // 0:01:59 = 119 s → filtered to 0
            'below threshold 0:01:59'  => ['0:01:59',     0],
        ];
    }

    // -----------------------------------------------------------------------
    // Branch 2: MM:SS  (regex: ^(\d+):(\d+)$)
    // -----------------------------------------------------------------------

    #[DataProvider('providerMmss')]
    public function test_mmss_format(string $input, int $expected): void
    {
        $this->assertSame($expected, self::parse($input));
    }

    public static function providerMmss(): array
    {
        return [
            'exactly 2 min'         => ['2:00',  120],
            '3 min 5 sec'           => ['3:05',  185],
            '90:00 (90 min)'        => ['90:00', 5400],
            // 1:59 = 119 s → filtered
            'below threshold 1:59'  => ['1:59',    0],
        ];
    }

    // -----------------------------------------------------------------------
    // Branch 3: M,SS'  (regex: ^(\d+),(\d+)'$)
    // -----------------------------------------------------------------------

    #[DataProvider('providerMssApostrophe')]
    public function test_mss_apostrophe_format(string $input, int $expected): void
    {
        $this->assertSame($expected, self::parse($input));
    }

    public static function providerMssApostrophe(): array
    {
        return [
            // (2×60 + 30) × 60 = 9000 s
            "2,30'"  => ["2,30'",  9000],
            // (1×60 + 0) × 60 = 3600 s
            "1,00'"  => ["1,00'",  3600],
        ];
    }

    // -----------------------------------------------------------------------
    // Branch 4: plain number of seconds  (regex: ^(\d+[.0-9]*)$)
    // -----------------------------------------------------------------------

    #[DataProvider('providerPlainSeconds')]
    public function test_plain_seconds_format(string $input, int $expected): void
    {
        $this->assertSame($expected, self::parse($input));
    }

    public static function providerPlainSeconds(): array
    {
        return [
            'integer 180'          => ['180',   180],
            'integer 3600'         => ['3600', 3600],
            'decimal 183.7'        => ['183.7', 184],
            // 100 < 120 → filtered
            'below threshold 100'  => ['100',     0],
            // 119.9 < 120 so it is filtered to 0 before round() is applied
            'boundary 119.9'       => ['119.9',   0],
        ];
    }

    // -----------------------------------------------------------------------
    // Branch 5: "N min M sec"
    // -----------------------------------------------------------------------

    #[DataProvider('providerMinSec')]
    public function test_min_sec_format(string $input, int $expected): void
    {
        $this->assertSame($expected, self::parse($input));
    }

    public static function providerMinSec(): array
    {
        return [
            'spaced 2 min 5 sec'     => ['2 min 5 sec',   125],
            // "2min5sec": branch 5 requires a space before "min" so it does
            // not match. Branch 7 ("N minutes") matches "2min" (the "min"
            // keyword is found), giving $m[1]=2 → 2×60 = 120 s.
            'compact 2min5sec hits branch7 as 2min' => ['2min5sec', 120],
            '90 min 0 sec'           => ['90 min 0 sec', 5400],
            // 1 min 59 sec = 119 s → filtered
            'below threshold 1 min 59 sec' => ['1 min 59 sec', 0],
        ];
    }

    // -----------------------------------------------------------------------
    // Branch 6: "N h M m"
    // -----------------------------------------------------------------------

    #[DataProvider('providerHourMin')]
    public function test_hour_min_format(string $input, int $expected): void
    {
        $this->assertSame($expected, self::parse($input));
    }

    public static function providerHourMin(): array
    {
        return [
            '1 h 30 m'   => ['1 h 30 m',  5400],
            '0 h 2 m'    => ['0 h 2 m',    120],
            // No space before "h" or "m": same reason as above, the regex
            // requires a literal space character; without it → else → 0.
            'compact no-space 2h0m falls through' => ['2h0m', 0],
        ];
    }

    // -----------------------------------------------------------------------
    // Branch 7: "N minutes" / "N minute" / "N min." (case-insensitive)
    // -----------------------------------------------------------------------

    #[DataProvider('providerMinutesWord')]
    public function test_minutes_word_format(string $input, int $expected): void
    {
        $this->assertSame($expected, self::parse($input));
    }

    public static function providerMinutesWord(): array
    {
        return [
            '95 minutes'          => ['95 minutes',   5700],
            '95.5 minutes'        => ['95.5 minutes', 5730],
            '2 minute'            => ['2 minute',       120],
            '60 min.'             => ['60 min.',       3600],
            '120 MINUTES'         => ['120 MINUTES',  7200],
            // 1.5 minutes = 90 s → filtered
            'below threshold 1.5 minutes' => ['1.5 minutes', 0],
        ];
    }

    // -----------------------------------------------------------------------
    // Unrecognised input → 0 (the else branch logs and returns 0 after filter)
    // -----------------------------------------------------------------------

    public function test_unrecognised_input_returns_zero(): void
    {
        // Suppress the error_log output during test
        $result = @self::parse('not a duration at all');
        $this->assertSame(0, $result);
    }

    public function test_empty_string_returns_zero(): void
    {
        $result = @self::parse('');
        $this->assertSame(0, $result);
    }
}
