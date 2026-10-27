# Equity Architecture

> **Status:** Phase 4 (Financial Foundation, Accounts, Currencies, and Double-Entry Ledger - Hardened). User-owned financial accounts, reference currencies, the double-entry ledger posting service, ledger-derived account balances, and the append-only audit trail are implemented. User-facing transaction entry UI, budgets, reports, forecasting, and foreign exchange remain deferred to future milestones.

---

## 1. Laravel, Inertia, and React Structure

Equity is a monolithic, full-stack web application built with Laravel 12 on the backend, Inertia.js as the protocol bridge, and React 19 with TypeScript on the frontend. Controllers return Inertia responses that hydrate strongly typed React page components.

### Backend Layers

- `routes/web.php`: Defines standard Laravel web routes protected by the `web` middleware stack, split into public, `guest`, and `auth` route groups (including `/dashboard` and `/accounts`).
- `app/Enums/`: Domain enums (`FinancialAccountType`, `LedgerAccountClassification`, `JournalEntryStatus`).
- `app/Exceptions/`: Domain exceptions (`LedgerIntegrityException`, `IdempotencyConflictException`, `ImmutableRecordException`).
- `app/Support/Money.php`: Immutable value object representing exact integer minor units (`int`), ISO 4217 currency code, and minor-unit exponent with checked arithmetic (`checkedAdd`, `checkedSubtract`).
- `app/DataTransferObjects/`: Immutable DTOs (`PostJournalEntryInput`, `PostingInstruction`, `PostedJournalEntryResult`) for ledger posting workflows.
- `app/Services/`: Domain services (`FinancialAccountService`, `LedgerPostingService`, `AccountBalanceService`, `AuditService`).
- `app/Policies/FinancialAccountPolicy.php`: Enforces strict user ownership authorization for all account operations.
- `app/Http/Controllers/`: `DashboardController`, `FinancialAccountController`, and `Auth/*` controllers.

### Frontend Organization

All frontend code lives under `resources/js/` with TypeScript strict mode enabled and the `@/*` path alias mapped to `resources/js/*`:

- `resources/js/Pages/`: Inertia page components (`Welcome.tsx`, `Dashboard.tsx`, `Accounts/Index.tsx`, and `Auth/*`).
- `resources/js/Layouts/`: `PublicLayout.tsx`, `GuestLayout.tsx`, and `AuthenticatedLayout.tsx`.
- `resources/js/Components/`: Reusable UI primitives (`Button`, `Input`, `Textarea`, `Select`, `Checkbox`, `FormField`, `Card`, `Badge`, `StatusBadge`, `Alert`, `Separator`, `EmptyState`, `PageHeader`, `Skeleton`, `ThemeToggle`).
- `resources/js/Utilities/`: `title.ts`, `theme.ts`, `useTheme.ts`, and `money.ts` (`formatMinorUnits` using `BigInt`).

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
3. **Ledger Accounts (`ledger_accounts`):**
    - Represents a node in the user's double-entry chart of accounts (`Asset`, `Liability`, `Equity`, `Income`, `Expense`).
    - Every `FinancialAccount` has a unique, unambiguous `1:1` `LedgerAccount` (`unique('financial_account_id')`) created atomically in the same database transaction with code `ACCT-{financial_account_id}`.
    - Enforces a composite foreign key `(financial_account_id, user_id) -> financial_accounts(id, user_id)` (`RESTRICT ON DELETE`) so a ledger account can never link to a `FinancialAccount` owned by a different user.
    - System or category ledger accounts (such as `Opening Balances Equity`, `Salary Income`, or `Groceries Expense` in future milestones) have `financial_account_id = null` and `is_system = true`.
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
    - Atomically creates `FinancialAccount`, its `1:1` `LedgerAccount`, and `AuditEvent` inside `DB::transaction()`.
    - In `updateAccount()`, locks both the `FinancialAccount` and its linked `LedgerAccount` with `lockForUpdate()` inside `DB::transaction()` before checking `$ledgerAccount->postings()->exists()`, preventing a race condition between an account currency/type update and a concurrent journal posting.

### Layer 3: Eloquent Model Safeguards

- `FinancialAccount`: Rejects `user_id` changes (`ImmutableRecordException`), rejects `currency_code` changes after postings exist (`ImmutableRecordException`), and blocks `delete()` (`LedgerIntegrityException`).
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
    - **Database-Specific Concurrency Behavior:** On MariaDB (InnoDB), `lockForUpdate()` acquires row-level `X` locks on the `currencies` and `ledger_accounts` rows in ascending `id` order, serializing concurrent postings and account updates per account without blocking unrelated users. On SQLite (used for isolated in-memory tests), write transactions serialize at the database level.

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
