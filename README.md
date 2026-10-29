# DatabaseStore::add() deadlocks under concurrent RateLimiter hits

With the `database` cache store on MySQL, concurrent `RateLimiter::hit()` calls for the same key fail with:

```
SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction
(SQL: insert ignore into `cache` (`key`, `value`, `expiration`) values (..., i:0;, ...))
```

It happens when the counter row has **expired** rather than being absent, which in an app is the first burst of
requests after a user has been idle longer than the decay window (e.g. a SPA firing parallel API requests on page
load, all going through `throttleApi()`).

## Cause

1. `DatabaseStore::get()` (called by `add()`) sees the expired row and deletes it.
2. InnoDB keeps the deleted row in the primary key index until purge.
3. Two or more concurrent `add()` calls run `INSERT IGNORE` for that key. Each duplicate check finds the
   delete-marked row and takes a shared (S) lock on it, which they can all hold at once.
4. The row turns out to be deleted, so each needs an exclusive (X) lock to insert over it, which the others' S
   locks block. InnoDB rolls back all but one.

This is the INSERT deadlock described in the MySQL manual under
[Locks Set by Different SQL Statements](https://dev.mysql.com/doc/refman/8.4/en/innodb-locks-set.html).
Without the delete (the key has never existed) the same race doesn't deadlock.

## Running it

```sh
docker compose run --rm php composer install

docker compose run --rm php php lock-sequence.php    # the lock sequence in plain SQL, deterministic
docker compose run --rm php php repro.php expired    # RateLimiter race on an expired row: deadlocks
docker compose run --rm php php repro.php missing    # control, same race on a never-existing key: none
```

`repro.php [expired|missing] [rounds=100] [workers=16]` forks `workers` processes per round, each with its own
connection, and has them call `RateLimiter::hit()` at the same instant.

## Expected output

```
$ docker compose run --rm php php lock-sequence.php
[A] deleted the expired row, holding it for 1s
[B] INSERT IGNORE (waits on A)
[C] INSERT IGNORE (waits on A)
[A] committed
[C] FAILED: SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction
[B] inserted
```

Observed with `illuminate/cache` v13.39.0, 300 rounds each, against MySQL 8.4.13:

| workers | `expired` deadlocks | `missing` deadlocks |
| ------: | ------------------: | ------------------: |
|       4 |           0 / 1,200 |           0 / 1,200 |
|       8 |           1 / 2,400 |           0 / 2,400 |
|      16 |       3,389 / 4,800 |           0 / 4,800 |

The rate depends heavily on how many requests land in the same few milliseconds, so it may take more workers or
rounds to appear on a faster machine.

## Patch

[`patches/illuminate-cache-database-store-add-deadlock.patch`](patches/illuminate-cache-database-store-add-deadlock.patch)
is applied to `illuminate/cache` on `composer install` by
[cweagans/composer-patches](https://github.com/cweagans/composer-patches). On MySQL and MariaDB it changes
`DatabaseStore::add()` from `INSERT IGNORE` to `INSERT ... ON DUPLICATE KEY UPDATE` with a no-op update:

- `ON DUPLICATE KEY UPDATE` takes an exclusive (X) lock in the duplicate check instead of a shared (S) one, so
  concurrent adds queue behind each other rather than all holding S and then needing X. The no-op update
  leaves an existing row unchanged and reports 0 affected rows, so `add()` still returns `false` for it.
- If InnoDB purges the deleted row while it is locked, the lock passes to the gap before the next key, and two
  inserts into that gap can still deadlock on rare occasions. The statement is atomic, so it runs in
  `$connection->transaction(..., 3)`, which retries on deadlock. This can't retry when `add()` is called inside
  an outer transaction, because MySQL rolls back the whole transaction on deadlock.

With the patch, `illuminate/cache` v13.39.0, 300 rounds, MySQL 8.4:

| workers | `expired` deadlocks | `missing` deadlocks |
| ------: | ------------------: | ------------------: |
|      16 |           0 / 4,800 |           0 / 4,800 |
|      32 |           0 / 9,600 |                   — |

Upsert without the retry left 14 / 4,800 deadlocks at 16 workers. Retry without the upsert left 2 / 9,600 at
32 workers.

To see the unpatched behaviour, remove the `extra.patches` entry and run
`docker compose run --rm php composer patches-relock && docker compose run --rm php composer patches-repatch`.
`lock-sequence.php` uses raw SQL and deadlocks either way.
