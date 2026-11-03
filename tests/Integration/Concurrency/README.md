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
12. Controlled SQLite lock diagnostics (deferred snapshot upgrade vs held-writer wait)
13. Controlled DEFERRED vs IMMEDIATE synthetic write-race comparison (diagnostic PDO only)
14. Financial-service DEFERRED vs IMMEDIATE comparison (test-only `Phase3ImmediateSQLiteConnection` + `Phase3ImmediateSqlitePdo` via `Connection::resolverFor`, Phase 3 workers only)

Phase 4B.3/4B.4/4B.5 append lock diagnostic JSON on the final test stdout (`Phase3 lock diagnostics`, `Phase3 txn-mode comparison`, `Phase3 financial txn-mode comparison`). Financial-op timings are whole-operation wall clocks (including service retries). Controlled experiments expose `write_attempt_ms` / `begin_wait_ms` for synthetic SQL. IMMEDIATE financial workers may also report `begin_wait_ms_samples` from the test-only connection subclass. On PHP 8.2, IMMEDIATE must go through a PDO subclass so `inTransaction()` / `commit()` / `rollBack()` stay aligned with Laravel's transaction counter (raw `exec('BEGIN IMMEDIATE')` alone breaks PDO commit). Primary driver code `5` is never labeled `SQLITE_BUSY_SNAPSHOT` unless an extended code `517` is actually present. Production services never call `BEGIN IMMEDIATE`; the financial trial activates it only through the worker-scoped SQLite connection resolver when `EQUITY_PHASE3_SQLITE_TXN_MODE=immediate`.

## Limitations

- SQLite serializes writers; this is not MariaDB/InnoDB row-level locking.
- `lockForUpdate()` does not provide row locks on SQLite; reliability comes from DB-level locking, unique constraints, and application retries on `SQLITE_BUSY` / `SQLITE_LOCKED`.
- Under 4-way write waves, some workers may exhaust retries and surface `SQLITE_BUSY` / `SQLITE_LOCKED` (driver codes 5 / 6). Tests treat those structured codes as expected lock contention when financial integrity is preserved. Surfaced unique-constraint failures after service retries are treated as application failures, not acceptable contention. Already-reversed outcomes require the exact production integrity message, not any `LedgerIntegrityException`.
- Wave synchronization uses a shared `start_at` timestamp (parent→worker IPC via files/stdin is fragile on Windows). Held/release uses a disposable SQLite barrier with short-lived PDO connections.
- Worker counts are bounded (default 4) with explicit timeouts.
- Process termination is used only for dedicated Phase 3 interrupt workers whose PID was recorded by the coordinator.
- Workdir cleanup (`Phase3WorkdirCleanup`) refuses to recurse through symlinks, directory junctions, or other realpath redirections; suspicious entries are left in place and reported as cleanup failures.
