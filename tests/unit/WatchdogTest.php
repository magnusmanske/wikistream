<?php

declare(strict_types=1);

namespace WikiStream\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Watchdog::resolveTimeout() — the pure timeout-resolution logic
 * that decides whether (and for how long) update.php arms its kill switch.
 *
 * The forking SIGKILL side of arm() is an OS-level integration concern and is
 * not unit-tested here; resolveTimeout() is the part that carries the policy.
 */
final class WatchdogTest extends TestCase
{
    /** @return iterable<string, array{mixed, int}> */
    public static function cases(): iterable
    {
        $default = \Watchdog::DEFAULT_TIMEOUT;

        yield 'unset env (getenv false)'  => [false, $default];
        yield 'null'                      => [null, $default];
        yield 'empty string'             => ['', $default];
        yield 'garbage'                  => ['abc', $default];
        yield 'whitespace'               => ['   ', $default];
        yield 'explicit zero disables'   => ['0', 0];
        yield 'negative disables'        => ['-1', 0];
        yield 'positive seconds'         => ['600', 600];
        yield 'numeric int'             => [600, 600];
        yield 'three hour default value' => ['10800', 10800];
    }

    #[DataProvider('cases')]
    public function testResolveTimeout(mixed $raw, int $expected): void
    {
        $this->assertSame($expected, \Watchdog::resolveTimeout($raw));
    }

    public function testDefaultIsThreeHours(): void
    {
        $this->assertSame(3 * 60 * 60, \Watchdog::DEFAULT_TIMEOUT);
    }

    public function testCustomDefaultIsHonoured(): void
    {
        $this->assertSame(42, \Watchdog::resolveTimeout(false, 42));
        // A valid value still overrides the supplied default.
        $this->assertSame(5, \Watchdog::resolveTimeout('5', 42));
    }

    public function testArmWithNonPositiveTimeoutIsNoOp(): void
    {
        // Disabled guard must not fork or raise; just return cleanly.
        \Watchdog::arm(0);
        \Watchdog::arm(-10);
        $this->assertTrue(true);
    }
}
