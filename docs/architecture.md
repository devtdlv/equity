# Equity Architecture

> **Status:** Through Milestone 5.5 plus MVP Stages 1–5. Session authentication, Settings & Profile, the design system, reference currencies, the double-entry ledger, ledger-derived account balances, the append-only audit trail, financial accounts, transactions, budgets, Bills & Subscriptions, Savings Goals, and Financial Reports are implemented. Stage 5 release-readiness evidence is in `docs/release-readiness.md`. Cash-flow forecasting and foreign-exchange conversion remain planned.

---

## 0. Database configuration defaults

- **New local and self-hosted installs** default to persistent, file-based SQLite: `config/database.php` falls back to `DB_CONNECTION=sqlite` and `database_path('database.sqlite')` (`database/database.sqlite`) when `DB_DATABASE` is unset. Foreign keys remain enabled by default. The SQLite connection sets `busy_timeout` to 5000 ms and `journal_mode` to `wal` (Laravel `SQLiteConnector` pragmas).
- **First-run (operator steps, not automatic at boot):** create `database/database.sqlite` if missing, then run `php artisan migrate` (and seeders as documented). The application does not create the SQLite file or run migrations on startup.
- **`.env.example` vs `.env`:** Updating `.env.example` does not change an existing `.env`. Existing production or local deployments keep their configured engine and must not be assumed to switch silently.
- **Optional MySQL/MariaDB:** set `DB_CONNECTION` to `mysql` or `mariadb` with the usual `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` variables.
- **PostgreSQL:** Not supported for Equity's complete schema. Some domain migrations reject `pgsql`.
- **PHPUnit / Pest:** Remain on SQLite `:memory:` via `phpunit.xml` and `Tests\Support\SqliteMemoryConnectionGuard`. That isolated harness is independent of the file-based default and must stay on `:memory:`.
- **Concurrency and deployment:** SQLite serializes writers at the database level. WAL helps concurrent readers with one writer; it is not equivalent to MariaDB InnoDB row-level locking. Prefer single-node use of one SQLite file.
- **Backup:** Prefer SQLite online backup or a quiesced database. Do not blindly copy a live database file (or `-wal`/`-shm` sidecars) while writes are active.
- **Integrity:** Ledger CHECK constraints, append-only triggers, foreign keys, and idempotency unique indexes are unchanged by this default-connection configuration.

---

## 1. Laravel, Inertia, and React Structure

Equity is a monolithic, full-stack web application built with Laravel 12 on the backend, Inertia.js as the protocol bridge, and React 19 with TypeScript on the frontend. Controllers return Inertia responses that hydrate strongly typed React page components.

### Backend Layers

- `routes/web.php`: Defines standard Laravel web routes protected by the `web` middleware stack, split into public, `guest`, and `auth` route groups (including `/dashboard`, `/settings`, `/accounts`, `/transactions`, `/budgets`, `/bills`, `/savings`, and `/reports`).
- `app/Enums/`: Domain enums (`FinancialAccountType`, `LedgerAccountClassification`, `JournalEntryStatus`).
- `app/Exceptions/`: Domain exceptions (`LedgerIntegrityException`, `InvalidMonetaryAmountException`, `InactiveCurrencyException`, `IdempotencyConflictException`, `ImmutableRecordException`).
- `app/Support/Money.php`: Immutable value object representing exact integer minor units (`int`), ISO 4217 currency code, and minor-unit exponent with checked arithmetic (`checkedAdd`, `checkedSubtract`).
- `app/DataTransferObjects/`: Immutable DTOs (`PostJournalEntryInput`, `PostingInstruction`, `PostedJournalEntryResult`, `PostOpeningBalanceInput`, `PostedOpeningBalanceResult`) for ledger posting workflows.
- `app/Services/`: Domain services (`FinancialAccountService`, `OpeningEquityAccountService`, `OpeningBalanceService`, `LedgerPostingService`, `AccountBalanceService`, `AuditService`, plus transaction, budget, recurring-obligation, and savings-goal services).
- `app/Policies/`: Ownership authorization for financial accounts, transactions, budgets, recurring obligations, and savings goals.
- `app/Http/Controllers/`: `DashboardController`, `FinancialAccountController`, transaction, budget, bills, savings-goal, and `Auth/*` controllers. `DashboardController` renders a navigation-first overview from shared Inertia props and does not query financial totals.

### Frontend Organization

All frontend code lives under `resources/js/` with TypeScript strict mode enabled and the `@/*` path alias mapped to `resources/js/*`:

- `resources/js/Pages/`: Inertia page components (`Welcome.tsx`, `Dashboard.tsx`, `Settings/Index.tsx`, `Accounts/Index.tsx`, `Transactions/Index.tsx`, `Budgets/Index.tsx`, `Bills/Index.tsx`, `SavingsGoals/Index.tsx`, `Reports/Index.tsx`, and `Auth/*`).
- `resources/js/Layouts/`: `PublicLayout.tsx`, `GuestLayout.tsx`, and `AuthenticatedLayout.tsx`.
- `resources/js/Components/`: Reusable UI primitives (`Button`, `Input`, `Textarea`, `Select`, `Checkbox`, `FormField`, `Card`, `Badge`, `StatusBadge`, `Alert`, `Separator`, `EmptyState`, `PageHeader`, `Skeleton`, `ThemeToggle`) plus module-specific dialogs.
- `resources/js/Utilities/`: `title.ts`, `theme.ts`, `useTheme.ts`, `money.ts` (`formatMinorUnits` using `BigInt`), and savings money helpers.

---

## 2. Financial Entities, Chart of Accounts, and Relationships

Equity separates user-visible accounts from the underlying double-entry accounting ledger:

1. **Currencies (`currencies`):**
    - Reference table keyed by 3-letter ISO 4217 `code` (`PHP`, `USD`, `AUD`, `EUR`, `GBP`, `JPY`), storing `name`, `symbol`, `minor_unit_exponent` (`2` for cent-based currencies, `0` for `JPY`), and `is_active`.
2. **User-Visible Financial Accounts (`financial_accounts`):**
    - Represents an account a user interacts with in the interface (`Checking`, `Savings`, `Cash`, `Credit Card`, `Other`).
    - Belongs to a `User` (`user_id`) and a `Currency` (`currency_code`).
    - Enforces a composite unique index on `(id, user_id)` so child `ledger_accounts` rows can verify owner consistency at the database foreign-key level.
    - Never stores a mutable balance column.
    - Uses an archive/restore lifecycle (`is_archived`, `archived_at`) rather than destructive deletion.
    - May point at one opening journal via nullable `opening_journal_entry_id`. A composite foreign key `(opening_journal_entry_id, user_id, currency_code) -> journal_entries(id, user_id, currency_code)` (`RESTRICT ON DELETE`) keeps owner and currency aligned when the pointer is set. `UNIQUE (opening_journal_entry_id)` prevents two accounts from sharing one journal; it does not independently prevent multiple opening journals for one account. Unreferenced opening journals and later pointer changes are not blocked by this schema. `NULL` is the representation for legacy accounts and for a zero starting amount (no journal).
    - `OpeningBalanceService` records a non-zero opening through `LedgerPostingService` (description `Starting balance`, no `financial_transactions` row). Lock order is financial_accounts, then currencies, then ledger_accounts. A zero amount is a no-op. When the pointer is already set, the pointed journal is loaded first: a different idempotency key is rejected without posting; the same key looks up the existing `EQ-OPEN-{CCY}` ledger account read-only (it does not call `OpeningEquityAccountService::resolveOrCreate`), compares `computeRequestFingerprint()`, and must not create another journal or provision opening equity. Ordinary writers of that account still lock `financial_accounts` first.
3. **Ledger Accounts (`ledger_accounts`):**
    - Represents a node in the user's double-entry chart of accounts (`Asset`, `Liability`, `Equity`, `Income`, `Expense`).
    - Every `FinancialAccount` has a unique, unambiguous `1:1` `LedgerAccount` (`unique('financial_account_id')`) created atomically in the same database transaction with code `ACCT-{financial_account_id}`. Optional create-time starting amounts are posted in that same transaction through `OpeningBalanceService` after the financial account and ledger exist.
    - Enforces a composite foreign key `(financial_account_id, user_id) -> financial_accounts(id, user_id)` (`RESTRICT ON DELETE`) so a ledger account can never link to a `FinancialAccount` owned by a different user.
    - System or category ledger accounts have `financial_account_id = null`. `OpeningEquityAccountService` resolves or creates one Opening Balances Equity row per user and currency (`EQ-OPEN-{CCY}`, classification `equity`, `is_system = true`, no financial account or category). Callers already in a transaction must use the same connection and lock currencies before ledger accounts. Duplicate-key collisions are not recovered inside that transaction; they propagate so the owner can roll back. `UNIQUE (user_id, code)` is the final safeguard. The service does not post journal entries. Category income/expense ledger accounts are provisioned separately when transactions are recorded.
4. **Journal Entries (`journal_entries`):**
    - Represents an atomic, timestamped financial event owned by a `User` in a single `currency_code`, with `idempotency_key`, `request_fingerprint` (64-char SHA-256 hex digest), `entry_date`, `description`, optional `external_reference`, `status` (`posted`), and `posted_at`.
5. **Journal Postings (`journal_postings`):**
    - Individual debit or credit lines belonging to a `JournalEntry` and targeting a `LedgerAccount`.
    - Stores `debit_amount_minor` and `credit_amount_minor` as 64-bit integers (`BIGINT`).
6. **Audit Events (`audit_events`):**
    - Append-only record of financial mutations capturing `user_id` (actor), `action`, `auditable_type`, `auditable_id`, sanitized JSON `metadata`, and `created_at`.

### Chart of Accounts & Normal Balance Conventions

| Classification | Normal Balance | Increases With | Decreases With | User-Visible Account Mapping                                      |
| :------------- | :------------- | :------------- | :------------- | :---------------------------------------------------------------- |
| `Asset`        | Debit          | Debit (`+`)    | Credit (`-`)   | `Checking`, `Savings`, `Cash`, `Other`                            |
| `Liability`    | Credit         | Credit (`+`)   | Debit (`-`)    | `Credit Card`                                                     |
| `Equity`       | Credit         | Credit (`+`)   | Debit (`-`)    | System / Opening Balance accounts (`financial_account_id = null`) |
| `Income`       | Credit         | Credit (`+`)   | Debit (`-`)    | Future income category ledger accounts                            |
| `Expense`      | Debit          | Debit (`+`)    | Credit (`-`)   | Future expense category ledger accounts                           |

---

## 3. Currency Handling and Exact Monetary Precision

1. **Signed 64-Bit Integer Minor Units:**
    - All posted amounts are stored as integer minor units (`BIGINT`) scaled by the currency's ISO 4217 `minor_unit_exponent` ($10^{\text{exponent}}$).
    - For example, `₱2,500.00` in `PHP` (`exponent = 2`) is stored as `250000`, whereas `¥15,800` in `JPY` (`exponent = 0`) is stored as `15800`.
2. **Zero Binary Floating-Point Arithmetic:**
    - Binary floating-point types (`float` in PHP, `number` float division in JavaScript) are never used for financial storage, summation, or formatting.
    - `App\Support\Money` in PHP and `formatMinorUnits` in TypeScript (`resources/js/Utilities/money.ts`) format values using integer division and remainder (`intdiv` / `%` and `BigInt`).
3. **Bounds & Overflow Protection:**
    - `Money::MAX_MINOR_AMOUNT` is set to $10^{18}$ (`1_000_000_000_000_000_000`), well inside signed 64-bit integer limits (`9.22 × 10^18`), and `Money::checkedAdd` / `Money::checkedSubtract` throw `LedgerIntegrityException` before an integer overflow or underflow can occur.
4. **Decimal string parsing (`App\Support\MoneyInputParser`):**
    - `parseToMinorUnits` and `parseNonNegativeToMinorUnits` accept an unsigned canonical decimal (digits, optional `.` with at most `exponent` fractional digits; no fractional part when `exponent` is 0). Plus signs, commas, exponent notation, and internal whitespace are rejected. Leading/trailing ASCII whitespace is trimmed. The positive parser rejects zero; the non-negative parser allows it.
    - `parseSignedToMinorUnits` uses the same canonical decimal after an optional leading ASCII minus. Zero and negative zero become integer `0`. Magnitude is still bounded by `±Money::MAX_MINOR_AMOUNT`. Unsigned callers are unchanged.

---

## 4. Defense-in-Depth Enforcement Layers

Equity separates financial invariants across four explicit layers so that bugs in any single layer cannot corrupt ledger state:

### Layer 1: Database-Enforced Constraints

- **Referential Integrity & Delete Protection:** All foreign keys across `financial_accounts`, `ledger_accounts`, `journal_entries`, `journal_postings`, and `audit_events` use `RESTRICT ON DELETE`.
- **Cross-User Account Mapping Prevention:** `financial_accounts` defines `UNIQUE (id, user_id)`, and `ledger_accounts` defines a composite foreign key `FOREIGN KEY (financial_account_id, user_id) REFERENCES financial_accounts(id, user_id)`. When `financial_account_id` is non-null, the database guarantees that both records belong to the same `user_id`.
- **Row-Level Posting `CHECK` Constraint (`journal_postings`):**
  Both MariaDB (`ALTER TABLE ... ADD CONSTRAINT chk_journal_postings_amounts CHECK (...)`) and SQLite (`CHECK (...)` in `CREATE TABLE`) enforce on every `journal_postings` row:
    - `debit_amount_minor >= 0 AND credit_amount_minor >= 0`
    - `debit_amount_minor <= 1000000000000000000 AND credit_amount_minor <= 1000000000000000000`
    - `((debit_amount_minor > 0 AND credit_amount_minor = 0) OR (credit_amount_minor > 0 AND debit_amount_minor = 0))`
    > **Why Row-Level `CHECK` Constraints Are Not Enough Alone:** SQL `CHECK` constraints evaluate a single row in isolation. They guarantee that each individual posting line has a valid non-negative debit or credit within bounds, but they **cannot** verify across multiple rows that a journal entry has at least two lines or that $\sum \text{debits} = \sum \text{credits}$. Multi-row balance invariants are enforced by `LedgerPostingService` inside an atomic database transaction.
- **Posting Ownership & Currency Trigger (`trg_journal_postings_validate_insert`):**
  A `BEFORE INSERT` trigger on `journal_postings` verifies that the target `ledger_accounts` row has the same `user_id` and `currency_code` as the parent `journal_entries` row, without requiring denormalized `user_id` or `currency_code` columns on `journal_postings`.
- **Idempotency Uniqueness:** `journal_entries` enforces `UNIQUE (user_id, idempotency_key)`.
- **Immutability Triggers:** `BEFORE UPDATE` and `BEFORE DELETE` triggers on `journal_entries`, `journal_postings`, and `audit_events` abort any attempt to update or delete committed ledger or audit rows, even via raw query-builder statements.

### Layer 2: Application-Service Invariants

- **`LedgerPostingService::post(PostJournalEntryInput $input)`:**
    - Validates that the journal entry contains at least two `PostingInstruction` lines referencing at least two distinct ledger accounts.
    - Verifies that $\sum \text{debit\_amount\_minor} = \sum \text{credit\_amount\_minor} > 0$ using `Money::checkedAdd()` to prevent 64-bit integer overflow.
    - Opens a `DB::transaction()` and acquires `SELECT ... FOR UPDATE` row locks (`lockForUpdate()`) on the `Currency` row and all referenced `LedgerAccount` rows in deterministic ascending `id` order before inserting `JournalEntry` and `JournalPosting` records. This prevents deadlocks and guarantees that account ownership, currency, or active currency status cannot change between validation and posting insertion.
    - Computes the deterministic SHA-256 `request_fingerprint` and enforces idempotency conflict detection (`IdempotencyConflictException`).
    - Wraps the creation of `JournalEntry`, all `JournalPosting` records, and the `journal_entry.posted` `AuditEvent` in a single `DB::transaction()`.
- **`FinancialAccountService`:**
    - Atomically creates `FinancialAccount`, its `1:1` `LedgerAccount`, `account.created` `AuditEvent`, and an optional opening journal inside one `DB::transaction()` (a savepoint when a caller already owns a transaction). HTTP create idempotency is enforced with durable `idempotency_key` + `create_request_fingerprint` on `financial_accounts` and `UNIQUE (user_id, idempotency_key)` (`financial_accounts_user_id_idempotency_key_unique`). Matching retries return the existing account without a second audit, ledger row, or opening journal; conflicting fingerprints throw `IdempotencyConflictException`. Concurrent duplicate inserts recover via `IdempotencyUniqueConstraint` after the unique violation. Currency is read without a row lock, then the financial account and ledger are inserted, then the currency row is locked and revalidated as active. The starting amount is parsed against the locked currency's `minor_unit_exponent` so posting uses metadata valid at lock time. `OpeningBalanceService` joins that transaction for a non-zero `starting_balance` (UTC today, opening journal idempotency key `opening-balance:account:{id}`, independent of the HTTP create key). Omitted, empty, and signed-zero amounts skip opening posting. Replay may `lockForUpdate` the existing account by `(user_id, idempotency_key)`; a brand-new insert holds the new row until commit, and `OpeningBalanceService` locks it when posting. The account ledger is never locked before currency. Transient retries (`TransientTransactionFailure` only) run only when this service owns the outermost transaction. Amount syntax errors surface as `InvalidMonetaryAmountException`; inactive currency as `InactiveCurrencyException`. Other ledger integrity failures remain server errors.
    - In `updateAccount()`, locks the `FinancialAccount` first, then `currencies` when the currency is changing, then the linked `LedgerAccount`, before checking `$ledgerAccount->postings()->exists()`, preventing a race between an account currency/type update and a concurrent journal posting and avoiding currency-before-financial-account inversion.
- **Create-account UI (`resources/js/Pages/Accounts/Index.tsx`):**
    - Create-form currency defaults to the alphabetically first currency used by the authenticated user's active (non-archived) accounts that also appear in the active catalog; with no eligible account currencies it falls back to the first active catalog currency. The default applies only when initializing the create form (no user-preferences schema).
    - The create form exposes an optional **Opening Balance** field bound to `starting_balance` as a string. Empty input omits opening posting via the existing backend optional-field contract; non-empty values are submitted as decimal strings (for example `1000.50`, `-500.25`, `0`, or JPY `1000`) on the single `POST /accounts` request. React never converts the amount with `Number`/`parseFloat`, never computes minor units, and never posts a separate opening journal.
    - Each create attempt includes a client-generated UUID `idempotency_key`. Successful creates rotate the key; payload conflicts surface on `idempotency_key` with a **Start new attempt** control that issues a fresh UUID. Matching retries are handled by the backend as successful replays (Inertia flash distinguishes first create vs replay).
    - Client-side checks use `validateOptionalSignedDecimalInput` against the selected currency's `minor_unit_exponent` from page currency metadata (no hardcoded precision). Changing currency keeps the typed amount and revalidates; excess fractional digits for the new currency surface a field error without auto-truncating. Backend `MoneyInputParser` / `StoreFinancialAccountRequest` remain authoritative. Account list and detail balances continue to render only `AccountBalanceService` ledger-derived payloads (`balance.formatted` / `amount_minor`), not a stored balance column or frontend sum.
- **Existing-account opening balance (MVP Stage 1):**
    - Eligible accounts (not archived, no `opening_journal_entry_id`, no ledger postings) expose `can_post_opening_balance` on the Accounts index and an edit-panel **Set opening balance** form.
    - `POST /accounts/{account}/opening-balance` (`accounts.opening-balance.store`) accepts `opening_balance` as a decimal string. `FinancialAccountService::postOpeningBalanceForExistingAccount` parses with `MoneyInputParser`, rejects blank/zero amounts, posts via `OpeningBalanceService` using **UTC today** and idempotency key `opening-balance:account:{id}`, and never amends an already-posted opening.
    - Authorization uses `FinancialAccountPolicy::postOpeningBalance` (owner only). Duplicate, archived, and post-activity attempts surface as `opening_balance` validation errors. Matching retries flash as already recorded.
- **Bill pay-and-post (MVP Stage 2):**
    - `RecurringPaymentService::payAndPost(PayRecurringBillInput)` locks obligation → occurrence, then the selected financial account. It reuses `FinancialTransactionService::createTransaction` (expense) and `linkPayment` inside one outer `FinancialDatabaseTransaction` so a link failure rolls back the expense.
    - Amount must be positive and ≤ remaining (reversal-aware) balance; overpayment is blocked on this path only. Remaining is computed with `lockForUpdate` on payment links and related expense/reversal rows so InnoDB REPEATABLE READ cannot miss concurrently committed links after the unlocked occurrence pre-read. Link-only payment linking is unchanged and may still overpay.
    - Client UUID `idempotency_key` is the journal create key: matching retries replay the expense and return an existing link without double-posting; if the link was unlinked, replay re-checks locked remaining before re-linking. Conflicting payloads throw `IdempotencyConflictException`.
    - HTTP: `POST /recurring-occurrences/{occurrence}/pay` (`recurring-occurrences.pay`) via `PayRecurringBillRequest` + `RecurringOccurrencePolicy::payBill`. Bills UI **Pay** defaults amount to remaining and lists active same-currency owned accounts.
- **Financial Reports (MVP Stage 3):**
    - Read-only `FinancialReportService::build(FinancialReportQuery)` powers `GET /reports` (`reports.index`). Filters: `mode=month|custom`, `month=YYYY-MM`, `start_date`/`end_date`, optional `financial_account_id`.
    - Cash-flow and category aggregates use `FinancialTransaction::active()` and income/expense types only (transfers excluded), grouped per `currency_code` with `MoneyJson` minor-unit strings. No FX conversion, posting, or mutation.
    - Monthly mode includes a six-month cash-flow trend ending at the selected month; custom mode buckets intersecting calendar months (capped). Category spend includes share basis points via `Money::checkedMulDivFloor`. Optional account history lists active transactions for an owned account (capped) and reuses `AccountBalanceService` for current balances.
    - UI charts are SVG/CSS (no new chart library). CSV export remains deferred.
- **Settings & Profile (MVP Stage 4):**
    - Authenticated `SettingsController` powers `GET /settings` (`settings.edit`), `PATCH /settings/profile` (`settings.profile.update`), and `PUT /settings/password` (`settings.password.update`).
    - Profile updates allow name-only changes without the current password. Changing email requires `current_password`, normalizes email to lowercase, enforces uniqueness with a safe error message, and clears `email_verified_at` without introducing mandatory email verification.
    - Password updates require the current password, confirmation, and `Password::defaults()`. On success the session is regenerated and `Auth::logoutOtherDevices` rehashes so other devices fail `auth.session` (`AuthenticateSession`) on their next authenticated request. The authenticated route group uses `['auth', 'auth.session']`. Profile and password writes share the `throttle:settings` limiter (6/min by user id + IP).
    - No preferences schema or additional shared auth props beyond `id` / `name` / `email`.
- **Release readiness (MVP Stage 5):**
    - No new product modules. Stage 5 records full regression evidence, production hardening requirements, deployment/recovery guidance, and a manual smoke checklist in `docs/release-readiness.md`.
    - SQLite `equity.sqlite.financial_immediate` remains disabled; concurrency initiative stays closed unless a concrete production blocker appears.
- **Visual audit (Phase 6 prep):**
    - Disposable `.env.visual-audit` + outside-repo SQLite via `scripts/visual-audit-prepare.php` and `equity:visual-audit-verify`.
    - `VisualAuditSeeder` is guard-railed and is **not** called from `DatabaseSeeder`. Capture plan under `docs/visual-audit/`.
- **`OpeningBalanceService::postForAccount(PostOpeningBalanceInput $input)`:**
    - Locks `financial_accounts` first, then `currencies`, then ledger accounts. Opening equity is resolved after the currency lock. `LedgerPostingService` then locks ledger accounts by ascending `id`. The account ledger is never locked before the currency.
    - A zero amount is a no-op: no journal, no postings, no opening-equity create, pointer stays `NULL`, and no `account.opening_posted` audit event.
    - A non-zero amount posts through `LedgerPostingService` with description `Starting balance` and does not create `financial_transactions`. `opening_journal_entry_id` is assigned directly on the locked row in the same transaction. If `LedgerPostingService` returns an existing journal that is not this account's pointed opening, the attempt is rejected and rolled back.
    - When `opening_journal_entry_id` is already set, that journal is loaded first. A different idempotency key is rejected without calling `LedgerPostingService::post`. The same key locates the existing `EQ-OPEN-{CCY}` ledger account with a read-only query (missing or invalid equity fails closed; nothing is created or repaired), compares `computeRequestFingerprint()`, and returns the pointed journal without creating another journal.
    - If an outer transaction already exists, insert failures propagate. When this service owns the transaction, only `TransientTransactionFailure` is retried.

### Layer 3: Eloquent Model Safeguards

- `FinancialAccount`: Rejects `user_id`, `idempotency_key`, and `create_request_fingerprint` changes (`ImmutableRecordException`), rejects `currency_code` changes after postings exist (`ImmutableRecordException`), and blocks `delete()` (`LedgerIntegrityException`).
- `LedgerAccount`: On `saving`, verifies that any linked `FinancialAccount` exists and matches `user_id`, `currency_code`, and `classification` (`LedgerIntegrityException`). On `updating`, blocks changes to `user_id` or `financial_account_id` (`ImmutableRecordException`), and blocks `delete()` (`LedgerIntegrityException`).
- `JournalEntry`, `JournalPosting`, and `AuditEvent`: Throw `ImmutableRecordException` on Eloquent `updating` and `deleting` events.

### Layer 4: Operational & Database Privilege Safeguards

- While Eloquent model hooks and database triggers block updates and deletes through normal application and query-builder paths, a database superuser or migration user with `DROP TRIGGER`, `ALTER`, or direct administrative privileges could bypass or drop triggers.
- In production deployments, the application's runtime MariaDB user should be granted only `SELECT, INSERT` on `journal_entries`, `journal_postings`, and `audit_events` (withholding `UPDATE, DELETE, DROP, ALTER, TRIGGER`), reserving schema/trigger privileges for a separate migration role.

---

## 5. Safe Idempotency, Request Fingerprinting, and Concurrency

### Deterministic Request Fingerprint

Every `JournalEntry` stores both a caller-supplied `idempotency_key` (`VARCHAR(128)`) and a deterministic `request_fingerprint` (`CHAR(64)` SHA-256 hex digest).

`LedgerPostingService::computeRequestFingerprint()` normalizes and hashes the complete financial meaning of the request:

- `user_id` (`int`)
- `currency_code` (uppercase trimmed ISO 4217 code)
- `entry_date` (`YYYY-MM-DD`)
- `description` (trimmed string)
- `external_reference` (trimmed string or `null`)
- `postings`: Each posting line (`ledger_account_id`, `debit_amount_minor`, `credit_amount_minor`) is canonicalized by sorting on `(ledger_account_id ASC, debit_amount_minor ASC, credit_amount_minor ASC)` before JSON encoding and SHA-256 hashing.
    - **Posting Order Significance:** Because double-entry journal postings represent a set of debit and credit adjustments within a single atomic entry, canonicalizing line order ensures that retrying the exact same set of postings produces the same fingerprint regardless of array ordering, while any change to an account ID, debit amount, credit amount, date, currency, description, or reference produces a different fingerprint.

### Retry & Conflict Resolution

1. **Same `(user_id, idempotency_key)` + Identical `request_fingerprint`:**
    - Returns `PostedJournalEntryResult(journalEntry: $existingEntry, wasRecentlyCreated: false)` without creating duplicate postings or duplicate audit events.
2. **Same `(user_id, idempotency_key)` + Different `request_fingerprint`:**
    - Throws `App\Exceptions\IdempotencyConflictException` (`extends LedgerIntegrityException`) so callers are immediately alerted that an idempotency key was reused for a different financial payload.
3. **Concurrent Insert Race Handling & Scoped Constraint Detection:**
    - If two concurrent requests with the same `(user_id, idempotency_key)` pass the initial read check simultaneously, the database `UNIQUE (user_id, idempotency_key)` constraint (`journal_entries_user_id_idempotency_key_unique`) rejects the second insert.
    - `LedgerPostingService::isJournalEntryIdempotencyConstraintViolation()` inspects the `QueryException` to verify both that it is a unique constraint error (`SQLSTATE 23505`, MariaDB/MySQL error `1062`, or SQLite `19`/`2067`) **and** that the error specifically references `journal_entries_user_id_idempotency_key_unique` or `journal_entries.user_id, journal_entries.idempotency_key`.
    - Unrelated unique constraint failures (for example, on `ledger_accounts`) or other database errors are never misclassified as idempotency retries and are rethrown immediately.
    - **Database-Specific Concurrency Behavior:** On MariaDB/MySQL (InnoDB), `lockForUpdate()` acquires row-level `X` locks on the `currencies` and `ledger_accounts` rows in ascending `id` order, serializing concurrent postings and account updates per account without blocking unrelated users. On SQLite (default file-based installs and isolated `:memory:` tests), write transactions serialize at the database level; this is not equivalent to InnoDB row-level locking.
4. **Financial account HTTP create (`POST /accounts`):**
    - Uses the same conflict model on `financial_accounts` with `UNIQUE (user_id, idempotency_key)` and durable `create_request_fingerprint` over normalized create fields (`name`, `type`, `currency_code`, `institution`, `description`, and `starting_amount_minor`). Optional blank institution/description become `null`. Opening amounts are parsed against the selected currency's `minor_unit_exponent` before hashing so blank/zero openings and equivalent decimal spellings (`100`, `100.0`, `100.00`) collide to the same fingerprint. Keys are scoped per authenticated user and do not collide with journal or opening-balance keys. Opening journals remain keyed as `opening-balance:account:{id}` inside the create transaction.

---

## 6. Synthetic Posting & Balance Derivation Example

Suppose a user creates two `USD` financial accounts (`Main Checking` -> `Asset` ledger account `ACCT-1`, and `Everyday Credit Card` -> `Liability` ledger account `ACCT-2`) and has system ledger accounts for `Salary Income` (`Income`) and `Groceries Expense` (`Expense`):

1. **Receive $2,500.00 (`250000` cents) Salary into Main Checking:**
    - Debit `ACCT-1 (Main Checking, Asset)`: `250000`
    - Credit `INC-SALARY (Salary Income, Income)`: `250000`
2. **Spend $125.50 (`12550` cents) on Groceries using Everyday Credit Card:**
    - Debit `EXP-GROCERIES (Groceries Expense, Expense)`: `12550`
    - Credit `ACCT-2 (Everyday Credit Card, Liability)`: `12550`
3. **Pay $50.00 (`5000` cents) toward Everyday Credit Card from Main Checking:**
    - Debit `ACCT-2 (Everyday Credit Card, Liability)`: `5000`
    - Credit `ACCT-1 (Main Checking, Asset)`: `5000`

**Derived Balances (`AccountBalanceService`):**

- `Main Checking` (`Asset`, debit-normal: $\text{debits} - \text{credits}$):
  $$250000 - 5000 = 245000 \text{ minor units } (\$2,450.00)$$
- `Everyday Credit Card` (`Liability`, credit-normal: $\text{credits} - \text{debits}$):
  $$12550 - 5000 = 7550 \text{ minor units owed } (\$75.50)$$

---

## 7. Workspace Modules and Planned Work

The authenticated shell links to implemented modules. The Dashboard is a navigation-first overview of those destinations. It does not fetch module APIs or display balances, counts, or monetary totals.

| Module | Route | Role in the product |
| :----- | :---- | :------------------ |
| Dashboard | `/dashboard` | Workspace overview with per-currency position, cash flow, and budget summaries. Budget badges distinguish aggregate overspend (`Budget over limit`) from category-only overspend (`Category over limit`) without changing `is_over_limit` accounting. |
| Accounts | `/accounts` | User-visible financial accounts; balances come from posted ledger activity |
| Transactions | `/transactions` | Income, expenses, transfers, and reversals that post to the ledger. Create form is collapsed by default; **Add transaction** expands it and successful create collapses it again. |
| Budgets | `/budgets` | Monthly expense targets by category, kept per currency. UI currency defaults from active account currencies (same rules as Accounts create). Selected currency is kept in `?currency_code=` via Inertia `router.replace` (client-side, `preserveState`/`preserveScroll`) so Back/Forward/refresh restore it; Inertia seeds `account_currency_codes`. |
| Bills & Subscriptions | `/bills` | Recurring obligations and occurrence tracking. **Pay** (`POST /recurring-occurrences/{occurrence}/pay`) posts an expense via `FinancialTransactionService::createTransaction` and links it via `RecurringPaymentService::linkPayment` inside one `FinancialDatabaseTransaction` (client UUID idempotency; amount ≤ remaining). **Link payment** still associates an existing expense without posting. |
| Savings Goals | `/savings` | Goal management and allocations. Allocations are earmarks and do not move ledger or account balances. |
| Financial Reports | `/reports` | Read-only income/expense summaries, cash-flow trends, category spending, and account history. Monthly or custom UTC ranges; per-currency totals with no FX conversion. |
| Settings | `/settings` | Authenticated profile (name/email) and password management. Email is not verified in this MVP. |
| Forecasting & FX | none | Planned. Cash-flow forecasting and foreign-exchange conversion are not implemented. |

Migration files for these domains exist under `database/migrations/`. Files in source are not evidence that they have been applied to any production or local database (SQLite file or MariaDB/MySQL).
