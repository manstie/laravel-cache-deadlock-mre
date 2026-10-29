<?php

/**
 * Fires concurrent RateLimiter::hit() calls for one key at the moment its
 * counter row has expired, the way a SPA's parallel requests do after the
 * user has been idle for longer than the decay window.
 *
 * Usage: php repro.php [expired|missing] [rounds] [workers]
 *
 *   expired  (default) the counter row exists but has expired, so each hit's
 *            DatabaseStore::get() deletes it before add() re-inserts it
 *   missing  each round hits a key that has never existed, so add() never
 *            meets a deleted row (control: no deadlocks)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;

const KEY = 'api1';
const EXIT_OK = 0;
const EXIT_DEADLOCK = 2;
const EXIT_OTHER_ERROR = 1;

$mode = $argv[1] ?? 'expired';
$rounds = (int) ($argv[2] ?? 100);
$workers = (int) ($argv[3] ?? 16);

if (!in_array($mode, ['expired', 'missing'], true)) {
    fwrite(STDERR, "Unknown mode '{$mode}', expected 'expired' or 'missing'\n");
    exit(1);
}

setUpDatabase();
$parent = connect();

// One hit so both the counter and its :timer row exist
(new RateLimiter(new Repository(new DatabaseStore($parent, 'cache'))))->hit(KEY);

$deadlocks = 0;
$otherErrors = 0;

for ($round = 0; $round < $rounds; $round++) {
    if ($mode === 'expired') {
        // The previous window's rows are still there, but expired
        $parent->table('cache')->update(['expiration' => 1]);
        $key = KEY;
    } else {
        // Not deleted between rounds, as InnoDB keeps deleted rows in the
        // index until purged, which is the very condition being ruled out
        $key = KEY . ':' . $round;
    }

    // Workers connect first, then all fire at the same instant
    $startAt = microtime(true) + 0.05;
    $pids = [];

    for ($i = 0; $i < $workers; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            exit(hit($key, $startAt));
        }
        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $code = pcntl_wexitstatus($status);
        $deadlocks += $code === EXIT_DEADLOCK ? 1 : 0;
        $otherErrors += $code === EXIT_OTHER_ERROR ? 1 : 0;
    }
}

$total = $rounds * $workers;
printf("mode=%s rounds=%d workers=%d hits=%d deadlocks=%d other_errors=%d\n", $mode, $rounds, $workers, $total, $deadlocks, $otherErrors);

exit($deadlocks > 0 ? 1 : 0);

function hit(string $key, float $startAt): int
{
    $limiter = new RateLimiter(new Repository(new DatabaseStore(connect(), 'cache')));

    while (microtime(true) < $startAt) {
        usleep(100);
    }

    try {
        $limiter->hit($key);

        return EXIT_OK;
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), '1213 Deadlock')) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return EXIT_DEADLOCK;
        }

        fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");

        return EXIT_OTHER_ERROR;
    }
}
