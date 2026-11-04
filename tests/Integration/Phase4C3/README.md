# Phase 4C.3 — Production IMMEDIATE multi-process verification

Verification-only harness for `FinancialDatabaseTransaction` with `equity.sqlite.financial_immediate` under genuine multi-process SQLite contention.

## Safety

- Never touches `database/database.sqlite` or its WAL/SHM/journal sidecars.
- Never reads or writes the application `.env`.
- Creates a disposable environment file in the owned workdir (`equity-phase4c3-<token>.env`) and redirects Laravel via `useEnvironmentPath()` / `loadEnvironmentFrom()` before `Kernel::bootstrap()`.
- Migrate/seed use `scripts/phase4c3-artisan.php` (not stock `artisan`).
- Uses a disposable SQLite file under the system temp directory (`equity-phase4c3-*.sqlite`).
- Parent PHPUnit process keeps `config('equity.sqlite.financial_immediate')` **false**.
- Workers enable the flag only via `EQUITY_FINANCIAL_IMMEDIATE` in the disposable child environment.

Environment-isolation unit tests (no Laravel Kernel / no real DB):

```bash
php vendor/bin/phpunit --configuration phpunit.phase4c3-environment-isolation.xml
```

## How to run

```bash
php scripts/phase4c3-sqlite-concurrency-runner.php
```

The runner: safety gate → exclusive disposable DB + workdir → migrate → CurrencySeeder → PHPUnit (`phpunit.phase4c3-sqlite-concurrency.xml`) → cleanup.

## Architecture

| Piece | Role |
| --- | --- |
| `scripts/phase4c3-sqlite-concurrency-runner.php` | Disposable DB lifecycle + PHPUnit invocation |
| `scripts/phase4c3-worker.php` | Child process; boots Laravel; sets financial_immediate; runs one financial action |
| `Phase3WorkerCoordinator` + `Phase3Barrier` | Shared start_at wave sync and held/release barrier keys |
| `Phase4C3ProductionImmediateConcurrencyTest` | Scenarios 1–8 with DEFERRED vs IMMEDIATE comparison |

Workers synchronize with a shared absolute `start_at` timestamp (barrier wave) or held/release barrier keys (controlled hold). Arbitrary sleep is only used for measured lock-hold windows after an explicit held signal, not as a substitute for concurrency.

## Scenarios

1. Concurrent financial transaction creation (same account)
2. Concurrent create with the same idempotency key (+ conflict payload)
3. Concurrent category ledger resolution (same user/category/currency)
4. Concurrent reversal with the same idempotency key
5. Concurrent reversal with different keys (exactly one reversal)
6. Concurrent account creation (same key + fingerprint)
7. Concurrent opening balance posting + opening equity resolution
8. Held IMMEDIATE writer vs financial contender: DEFERRED comparison, IMMEDIATE retry recovery (attempts ≥ 2 then success), and IMMEDIATE retry exhaustion (attempts == 3, lock failure). Workers report test-only `attempts` / `retry_pauses` via `TransientTransactionRetryBackoff::setSleeper`.

Each scenario runs twice: flag OFF (DEFERRED) and flag ON (production IMMEDIATE), except scenario 8 which adds a dedicated exhaustion wave under IMMEDIATE. Expected domain conflicts are counted separately from lock failures and unexpected errors.
