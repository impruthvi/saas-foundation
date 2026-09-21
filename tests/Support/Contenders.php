<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Runs several attempts in forked processes that start at the same instant.
 *
 * A forked child inherits its parent's open connection, so the first thing each
 * one does is throw that away and dial its own: two processes sharing a socket
 * are not two clients, they are one client corrupting itself. The start time is
 * computed before the fork, so every child waits for the same moment and the
 * overlap is arranged rather than hoped for.
 */
final class Contenders
{
    /**
     * Run each attempt in its own process and collect what each one came to.
     *
     * @param  list<callable(): Outcome>  $attempts
     * @return list<Outcome>
     */
    public static function race(array $attempts, float $headStart = 0.25): array
    {
        $startAt = microtime(true) + $headStart;
        $pids = [];

        foreach ($attempts as $attempt) {
            $pid = pcntl_fork();

            throw_if($pid === -1, RuntimeException::class, 'Could not fork a contender.');

            if ($pid === 0) {
                exit(self::attempt($attempt, $startAt));
            }

            $pids[] = $pid;
        }

        $outcomes = [];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);

            $outcomes[] = Outcome::tryFrom(
                pcntl_wifexited($status) ? pcntl_wexitstatus($status) : Outcome::Failed->value,
            ) ?? Outcome::Failed;
        }

        return $outcomes;
    }

    /**
     * @param  callable(): Outcome  $attempt
     */
    private static function attempt(callable $attempt, float $startAt): int
    {
        DB::purge();
        DB::reconnect();

        while (microtime(true) < $startAt) {
            time_nanosleep(0, 100_000);
        }

        try {
            return $attempt()->value;
        } catch (Throwable) {
            return Outcome::Failed->value;
        }
    }
}
