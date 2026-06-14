<?php

/**
 * Wall-clock watchdog for long-running CLI entry points (scripts/update.php).
 *
 * WHY THIS EXISTS
 * ---------------
 * The hourly update pipeline occasionally wedged and ran for *weeks*. The
 * cause is in the shared Magnus-tools library under public_html/php (which we
 * cannot patch from this repo):
 *
 *   - ToolforgeCommon::csvUrlGenerator() (backs getSPARQL_TSV) calls
 *     set_time_limit(0) and sets NO CURLOPT_TIMEOUT / CURLOPT_CONNECTTIMEOUT.
 *   - WikidataItemList::getMultipleURLsInParallel() builds curl handles with
 *     no timeout and pumps `while ($running > 0)`.
 *
 * If WDQS or the Wikidata API accepts the TCP connection but then goes silent
 * (a half-open / stalled peer), curl_exec() blocks forever. Nothing — no PHP
 * time limit, no curl timeout — ever interrupts it, and WikiStream's
 * SparqlRetryTrait only retries on *thrown* exceptions, never on a hang.
 *
 * Since we can't add the missing socket timeouts in the shared library, we
 * bound the whole process from the outside: a forked watchdog sleeps for a
 * wall-clock deadline and then SIGKILLs the worker. SIGKILL is the only thing
 * guaranteed to terminate a process stuck in an uninterruptible blocking
 * syscall — SIGTERM/SIGALRM may not break out of a wedged curl_exec().
 *
 * USAGE
 * -----
 *   Watchdog::arm(Watchdog::resolveTimeout(getenv('WIKISTREAM_UPDATE_TIMEOUT')));
 *
 * Call it once, as early as possible, before any DB/network resources are
 * opened or shutdown functions registered.
 */
class Watchdog
{
    /** Default deadline: 3 hours. A healthy run is far shorter. */
    public const DEFAULT_TIMEOUT = 10800;

    /**
     * Turn a raw env/config value into a timeout in seconds.
     *
     *   - unset / empty / non-numeric  -> $default (fail safe: keep the guard)
     *   - "0" or negative              -> 0        (explicitly disabled)
     *   - positive number              -> that many seconds
     *
     * @param mixed $raw Typically the result of getenv() (string|false).
     */
    public static function resolveTimeout(mixed $raw, int $default = self::DEFAULT_TIMEOUT): int
    {
        if ($raw === false || $raw === null || $raw === '' || !is_numeric($raw)) {
            return $default;
        }
        $seconds = (int) $raw;
        return $seconds > 0 ? $seconds : 0;
    }

    /**
     * Fork a watchdog that SIGKILLs this process after $seconds of wall-clock
     * time. A non-positive timeout disables the guard. Degrades gracefully
     * (runs unguarded, with a log line) if pcntl/posix are unavailable or the
     * fork fails.
     */
    public static function arm(int $seconds): void
    {
        if ($seconds <= 0) {
            return; // explicitly disabled
        }
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            error_log('Watchdog: pcntl/posix unavailable; running without a timeout');
            return;
        }

        $workerPid = getmypid();
        $pid       = pcntl_fork();

        if ($pid === -1) {
            error_log('Watchdog: fork failed; running without a timeout');
            return;
        }

        if ($pid === 0) {
            self::runChild($workerPid, $seconds);
            // unreachable
        }

        // Parent (the worker): stand the watchdog down when we finish or die
        // before the deadline, so it never lingers or kills a recycled PID.
        register_shutdown_function(static function () use ($pid): void {
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status, WNOHANG);
        });
    }

    /**
     * Watchdog child loop. Wakes periodically so it can exit promptly if the
     * worker has already gone (reparented / no longer exists) — this guards
     * against killing an unrelated process that reused the worker's PID.
     */
    private static function runChild(int $workerPid, int $seconds): never
    {
        $waited = 0;
        $tick   = 5;
        while ($waited < $seconds) {
            sleep(min($tick, $seconds - $waited));
            $waited += $tick;
            if (posix_getppid() !== $workerPid || !posix_kill($workerPid, 0)) {
                exit(0); // worker already gone — nothing to kill
            }
        }
        error_log("Watchdog: update.php exceeded {$seconds}s; sending SIGKILL to pid {$workerPid}");
        posix_kill($workerPid, SIGKILL);
        exit(0);
    }
}
