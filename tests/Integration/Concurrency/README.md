# Phase 3 — SQLite concurrency and reliability

## How to run

From the repository root:

```bash
php scripts/phase3-sqlite-concurrency-runner.php
```

The runner:

1. Creates a unique disposable SQLite file and workdir under the system temp directory (`equity-phase3-<token>.sqlite`).
2. Migrates and seeds **only** that disposable database.
3. Runs `phpunit.phase3-sqlite-concurrency.xml`.
4. Cleans up owned disposable artifacts (including WAL/SHM/journal sidecars and the workdir).

Do **not** point the default `php artisan test` / `phpunit.xml` suite at a file database.

## What it covers

True multi-process workers (`scripts/phase3-worker.php`) coordinated with a shared `start_at` timestamp for wave races and a disposable SQLite barrier for held/release signals on controlled workers:

1. Concurrent transaction posting (same account)
2. Concurrent idempotency (identical + conflicting payloads)
3. Write contention / `busy_timeout`
4. WAL reader/writer isolation
5. Controlled mid-posting atomic rollback
6. Fresh-connection balance reconciliation
7. Interrupted worker recovery + `PRAGMA integrity_check`
8. Concurrent postings to different accounts
9. Concurrent category-ledger creation race
10. Concurrent reversal of the same transaction (distinct keys + shared idempotency key)
11. Concurrent reversal of different transactions

## Limitations

- SQLite serializes writers; this is not MariaDB/InnoDB row-level locking.
- `lockForUpdate()` does not provide row locks on SQLite; reliability comes from DB-level locking, unique constraints, and application retries on `SQLITE_BUSY` / `SQLITE_LOCKED`.
- Under 4-way write waves, some workers may exhaust retries and surface `SQLITE_BUSY` / `SQLITE_LOCKED` (driver codes 5 / 6). Tests treat those structured codes as expected lock contention when financial integrity is preserved. Surfaced unique-constraint failures after service retries are treated as application failures, not acceptable contention. Already-reversed outcomes require the exact production integrity message, not any `LedgerIntegrityException`.
- Wave synchronization uses a shared `start_at` timestamp (parent→worker IPC via files/stdin is fragile on Windows). Held/release uses a disposable SQLite barrier with short-lived PDO connections.
- Worker counts are bounded (default 4) with explicit timeouts.
- Process termination is used only for dedicated Phase 3 interrupt workers whose PID was recorded by the coordinator.
- Workdir cleanup (`Phase3WorkdirCleanup`) refuses to recurse through symlinks, directory junctions, or other realpath redirections; suspicious entries are left in place and reported as cleanup failures.
