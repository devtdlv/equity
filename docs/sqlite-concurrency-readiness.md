# SQLite concurrency readiness

**Status:** Initiative closed. `equity.sqlite.financial_immediate` remains **disabled** by default.
**Audience:** Operators and maintainers deciding whether to enable SQLite `BEGIN IMMEDIATE` for financial writes.
**Last verified against source:** Phase 4C.4 documentation pass (config, providers, services, Phase 4C.3 harness docs).

---

## 1. Summary of completed phases

| Phase | Outcome |
| --- | --- |
| **4A / 4B** | File-SQLite isolation runners (Phase 2 workflows; Phase 3 multi-process contention diagnostics). Established disposable-DB safety gates and fail-closed isolation patterns. |
| **4C.1** | Production infrastructure: `EquitySQLiteConnector` / `EquitySQLiteConnection` / `ImmediateAwareSqlitePdo`, `FinancialDatabaseTransaction`, `DatabaseServiceProvider` registration. Flag hardcoded off. |
| **4C.2** | Financial services wired through `FinancialDatabaseTransaction`; nested IMMEDIATE-under-DEFERRED fails closed when the flag is on. |
| **4C.3** | Multi-process verification of production IMMEDIATE vs DEFERRED on disposable file SQLite (scenarios 1–8, env-isolation tests, isolated artisan). Flag stays off in parent processes. |
| **4C.4** | This readiness note. No further implementation in this initiative. |

---

## 2. Current configuration and default behavior

- **Config:** `config/equity.php` → `sqlite.financial_immediate` => **`false`** (hardcoded; not read from `.env` in Phase 4C).
- **When false (default):** `FinancialDatabaseTransaction::run` uses Laravel’s normal SQLite `BEGIN` (DEFERRED). Infrastructure classes remain registered; they do not change begin mode.
- **When true (not enabled for production by this initiative):** outermost SQLite financial writes via `FinancialDatabaseTransaction` request `BEGIN IMMEDIATE`. Non-SQLite drivers are unchanged (`$connection->transaction()`).
- **SQLite connection defaults** (`config/database.php`): `busy_timeout` 5000 ms, `journal_mode` wal (env-overridable). Writers remain database-serialized under SQLite.
- **Boot fail-closed:** If `financial_immediate` is enabled while a non-Equity SQLite connection resolver was registered first, `DatabaseServiceProvider` refuses to boot.

**Verified default:** `config('equity.sqlite.financial_immediate')` resolves to `false` from source; Phase 4C.3 parent tests and `scripts/phase4c3-prove-isolation-entrypoints.php` assert the hardcoded false remains in `config/equity.php`.

---

## 3. Scope of financial operations using IMMEDIATE

IMMEDIATE applies **only** when the flag is true, the driver is `sqlite`, and the write goes through `FinancialDatabaseTransaction` (or joins an Equity IMMEDIATE outer via `assertCompatibleImmediateOuterTransaction`).

| Service | Operations |
| --- | --- |
| `FinancialTransactionService` | Create transaction; reverse transaction; resolve/create category ledger account |
| `FinancialAccountService` | Create financial account (including nested path) |
| `OpeningBalanceService` | Post opening balance (asserts compatible outer when joining) |
| `OpeningEquityAccountService` | Resolve/create opening equity ledger (asserts compatible outer when joining) |

Retry policy for concurrency/busy paths remains service-owned (e.g. `FinancialTransactionService::MAX_CONCURRENCY_ATTEMPTS = 3`). `FinancialDatabaseTransaction` does not change retry counts.

Out of scope for this flag: non-financial Laravel transactions, MariaDB/MySQL, ad-hoc `DB::transaction()` that bypasses `FinancialDatabaseTransaction`.

---

## 4. Evidence from existing concurrency tests

Evidence is **conditional**: disposable temp SQLite files, controlled workers, `busy_timeout=5000`, WAL, single host. It does **not** by itself prove production load, multi-host NFS, or long-lived operator deployments.

### Phase 4C.3 (production path, disposable DB)

Harness: `php scripts/phase4c3-sqlite-concurrency-runner.php` (isolated env file + `phase4c3-artisan.php`; never targets `database/database.sqlite`).

Representative results from the Phase 4C.3 verification closure run:

| Check | Result |
| --- | --- |
| Environment isolation unit suite | 21 tests, 229 assertions, **2 skipped** (symlink creation unavailable; hardlink/path-traversal cases covered) |
| Isolation entry-point proof script | PASS (flag default false; redirect before bootstrap) |
| Multi-process concurrency suite | **10 tests, 450 assertions**, 0 skipped |

Scenarios covered (each DEFERRED vs IMMEDIATE where applicable): concurrent create; shared idempotency; category ledger; shared/different reversal keys; account create; opening balance + equity; held-writer recovery (`attempts ≥ 2`) and exhaustion (`attempts == 3`) under IMMEDIATE.

Under contention in that run, IMMEDIATE typically reduced SQLITE_BUSY lock failures vs DEFERRED for several create/reversal waves; scenario notes still record that IMMEDIATE does not eliminate all busy outcomes under sustained hold/exhaustion.

### Phase 4C.1 / 4C.2 (unit / disposable file)

Same closure session: **38 tests, 241 assertions** (`ImmediateAwareSqlitePdo`, Equity connection infra, service integration on temp SQLite). Flag forced on only inside individual tests; tearDown restores false.

### Phase 2 / Phase 3 (baseline isolation)

| Runner | Result (same closure session) |
| --- | --- |
| Phase 2 file-SQLite workflows | 7 tests, 51 assertions |
| Phase 3 concurrency diagnostics | 15 tests, 667 assertions |

Phase 3 includes diagnostic IMMEDIATE paths that are **not** the production `FinancialDatabaseTransaction` flag (except where Phase 3 financial comparison uses its own test resolver). Prefer Phase 4C.3 for production-path claims.

---

## 5. Known limitations and unverified production conditions

- **Default remains DEFERRED.** Production has not been authorized to enable the flag.
- **Single-writer SQLite model:** WAL helps readers; writers still serialize. Not equivalent to InnoDB row locking.
- **Unverified:** multi-app-server access to one SQLite file; network filesystems; very high request concurrency; MariaDB parity (flag is SQLite-only).
- **Retry observation in scenario 8** uses test-only sleeper instrumentation (`TransientTransactionRetryBackoff::setSleeper`), not production metrics.
- **Symlink-based env-isolation negatives** may skip on hosts without symlink privilege; other unsafe-suffix checks remain.
- **Config cache:** A baked `bootstrap/cache/config.php` can override process env; Phase 4C.3 runners refuse to boot if that cache exists.
- **Foreign SQLite resolvers:** Enabling the flag with a pre-registered non-Equity resolver fails closed at boot.

---

## 6. Prerequisites for any future rollout

Before any enablement authorization:

1. Explicit product/ops decision and change window.
2. Confirm single-node file SQLite (or accept SQLite writer limits).
3. No Laravel config cache pointing at stale connection settings (or deliberate, reviewed cache rebuild).
4. Equity SQLite connector/connection resolvers active (no conflicting `Connection::resolverFor('sqlite')`).
5. Re-run Phase 4C.3 concurrency runner + isolation suite on the target PHP/OS; record results.
6. Backup/restore procedure for the live SQLite file (online backup or quiesced copy—not a live file copy under writers).
7. Monitoring for SQLITE_BUSY / deadlock wrappers and financial write latency.

---

## 7. Future enablement and rollback (not authorized by this document)

> **These are draft procedures only.** Do not execute them without a separate, explicit authorization. This initiative leaves the flag **off**.

### Enablement (future)

1. Ensure prerequisites above.
2. Change `config/equity.php` `financial_immediate` to `true` via a reviewed deploy (still prefer hardcoded config over `.env` unless a later change deliberately adds env wiring).
3. Deploy; smoke-test create/reverse/account/opening flows.
4. Watch busy/lock errors and write latency; keep a rollback window.

### Rollback (future)

1. Set `financial_immediate` back to `false` and deploy.
2. Confirm DEFERRED behavior (no IMMEDIATE begin requirement).
3. No data migration is required for the flag flip itself; investigate any failed writes from the enablement window separately.

---

## 8. Final recommendation

**Leave `equity.sqlite.financial_immediate` disabled (`false`). Close the SQLite concurrency initiative.**

Infrastructure and verification are in place for a future, separately authorized enablement. Shipping with IMMEDIATE off preserves today’s DEFERRED behavior, fail-closed isolation contracts, and avoids unverified production conditions.

---

## Related paths

- Config: `config/equity.php`, `config/database.php`
- Infra: `app/Database/SQLite/*`, `app/Support/FinancialDatabaseTransaction.php`, `app/Providers/DatabaseServiceProvider.php`
- Verification: `tests/Integration/Phase4C3/README.md`, `scripts/phase4c3-sqlite-concurrency-runner.php`
- Isolation contracts: `docs/integration-isolation.md`
