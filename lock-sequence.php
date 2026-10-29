<?php

/**
 * Replays, in plain SQL and in a fixed order, the lock sequence behind the
 * deadlock, without Laravel's timing luck:
 *
 *   A: DELETE the expired row          (holds X on it until A commits)
 *   B: INSERT IGNORE the same key      (duplicate check waits for S on it)
 *   C: INSERT IGNORE the same key      (duplicate check waits for S on it)
 *   A: COMMIT                          (B and C are both granted S, then both
 *                                       need X to insert over the deleted row,
 *                                       each blocked by the other's S)
 *
 * In Laravel A's DELETE is DatabaseStore::get() removing an expired row, and
 * B and C are DatabaseStore::add(). The DELETE autocommits there, so the
 * window is milliseconds wide; it's held open here so the outcome is certain.
 */

require __DIR__ . '/bootstrap.php';

const KEY = 'api1';

setUpDatabase();
connect()->table('cache')->insert(['key' => KEY, 'value' => 'i:34;', 'expiration' => 1]);

$pids = [
    'A' => run(function () {
        $db = connect();
        $db->beginTransaction();
        $db->table('cache')->where('key', KEY)->delete();
        step('A', 'deleted the expired row, holding it for 1s');
        sleep(1);
        $db->commit();
        step('A', 'committed');
    }),
    'B' => run(fn() => insertIgnoreAfter('B', 200_000)),
    'C' => run(fn() => insertIgnoreAfter('C', 400_000)),
];

foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}

function insertIgnoreAfter(string $name, int $microseconds): void
{
    $db = connect();
    usleep($microseconds);
    step($name, 'INSERT IGNORE (waits on A)');

    try {
        $inserted = $db->table('cache')->insertOrIgnore(['key' => KEY, 'value' => 'i:0;', 'expiration' => time() + 60]);
        step($name, $inserted ? 'inserted' : 'ignored as duplicate');
    } catch (Throwable $e) {
        step($name, 'FAILED: ' . $e->getPrevious()?->getMessage());
    }
}

function run(Closure $callback): int
{
    $pid = pcntl_fork();
    if ($pid === 0) {
        $callback();
        exit(0);
    }

    return $pid;
}

function step(string $name, string $message): void
{
    printf("[%s] %s\n", $name, $message);
}
