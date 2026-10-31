# Equity

Personal finance, clearly understood.

> **Development Status:** Equity is under active development. Through Milestone 5.4, the application includes session authentication, the design system, reference currencies, the double-entry ledger, append-only audit trail, financial accounts (`/accounts`), transactions, budgets, and Bills & Subscriptions (recurring obligations with a rolling three-month UTC occurrence horizon). Reporting charts, cash-flow forecasting, and foreign-exchange conversion remain planned.

## Project Overview

Equity is a personal finance and cash-flow management web application designed to help individuals track their accounts, understand their spending, and plan their financial future in one place.

Repository: [https://github.com/devtdlv/equity](https://github.com/devtdlv/equity)

## Technology Stack

- **Backend:** PHP 8.2+, Laravel 12
- **Frontend:** React 19, TypeScript 5 (strict mode)
- **Full-Stack Bridge:** Inertia.js (`inertiajs/inertia-laravel` v3, `@inertiajs/react` v3)
- **Styling & Bundling:** Tailwind CSS v4 (`@tailwindcss/vite`), Vite 6
- **Database:** MariaDB (local/production configuration), SQLite in-memory (automated backend tests)
- **Testing:** Pest 3 (backend feature and unit tests), Vitest 3 + React Testing Library + jsdom (frontend tests)
- **Code Quality:** Laravel Pint, ESLint 9, Prettier 3, TypeScript compiler (`tsc --noEmit`)

## Financial Foundation & Account Management (Phase 4)

- **Reference Currencies (`currencies` table & `CurrencySeeder`):**
    - Normalized ISO 4217 reference table storing `code`, `name`, `symbol`, `minor_unit_exponent`, and `is_active`.
    - Seeds `PHP` (exponent `2`), `USD` (`2`), `AUD` (`2`), `EUR` (`2`), `GBP` (`2`), and `JPY` (`0`) without any demo user accounts or fake balances.
- **Exact Monetary Precision (`App\Support\Money` & `resources/js/Utilities/money.ts`):**
    - All monetary amounts are stored and calculated as signed 64-bit integer minor units (`BIGINT`) with checked arithmetic (`Money::checkedAdd`, `Money::checkedSubtract`) and safe bounds (`Money::MAX_MINOR_AMOUNT = 10^18`).
    - Formatting uses integer division and remainder (`intdiv` / `%` in PHP, `BigInt` in TypeScript) so binary floating-point arithmetic is never used.
- **User-Visible Financial Accounts & 1:1 Ledger Accounts (`/accounts`):**
    - Supports `Checking`, `Savings`, `Cash`, `Credit Card`, and `Other` account types.
    - Creating a `FinancialAccount` atomically creates its linked 1:1 `LedgerAccount` (`Asset` classification for checking, savings, cash, and other; `Liability` classification for credit cards) inside a database transaction.
    - Supports viewing, creating, editing metadata, archiving, and restoring accounts. Destructive account deletion is blocked in favor of archiving, and an account's currency and asset/liability classification become immutable once ledger postings exist.
- **Double-Entry Ledger Engine (`LedgerPostingService` & `AccountBalanceService`):**
    - Enforces balanced journal entries (`total_debits === total_credits > 0`), single-currency consistency, user ownership isolation, concurrency-safe idempotency with SHA-256 request fingerprinting (`unique(['user_id', 'idempotency_key'])`, `request_fingerprint`, and `IdempotencyConflictException`), and posted-entry immutability via both Eloquent hooks and database triggers.
    - Derives account balances directly from posted `journal_postings` via aggregated SQL queries without storing mutable balance columns.
- **Append-Only Audit Trail (`AuditService` & `audit_events` table):**
    - Records `account.created`, `account.updated`, `account.archived`, `account.restored`, and `journal_entry.posted` events with automatic redaction of sensitive keys and model-level immutability.

## Design System & Application Shell (Phase 3)

- **Visual Identity & Typography:**
    - Clean sans-serif typography (`Inter`) paired with `JetBrains Mono` for badges, identifiers, and tabular numeric alignment (`.tabular-nums`).
    - Semantic CSS variables (`--eq-*`) mapped to Tailwind CSS v4 tokens (`@theme inline`) for light and dark palettes.
- **Theme Switching (`Light`, `Dark`, `System`):**
    - Persists user preference in `localStorage` (`equity-theme-preference`) with automatic `prefers-color-scheme: dark` resolution in `system` mode.
- **Responsive Application Shell (`AuthenticatedLayout`):**
    - Fixed-width desktop sidebar (`lg:w-72`) and collapsible mobile navigation featuring working links for `Dashboard` (`/dashboard`) and `Accounts` (`/accounts`), while keeping `Transactions`, `Budgets`, and `Ledger & Reports` clearly labeled as `Planned`.

## Authentication Features Implemented (Phase 2)

- **Registration (`GET /register`, `POST /register`)**
- **Login (`GET /login`, `POST /login`)**
- **Logout (`POST /logout`)**
- **Password Reset (`GET /forgot-password`, `POST /forgot-password`, `GET /reset-password/{token}`, `POST /reset-password`)**
- **Authenticated Dashboard (`GET /dashboard`)**

## Local Setup Instructions

### Prerequisites

- PHP 8.2 or newer with `pdo_mysql`, `pdo_sqlite`, `mbstring`, `openssl`, and `xml` extensions
- Composer 2.x
- Node.js 20+ (Node.js 22/24 recommended) and npm
- MariaDB 10.6+ or 11.x

### Installation

1. Install PHP dependencies:

```bash
composer install
```

2. Install frontend dependencies:

```bash
npm install
```

3. Copy the environment configuration and generate an application key:

```bash
cp .env.example .env
php artisan key:generate
```

4. Review the migration files in `database/migrations/`, then run migrations and seed reference currencies manually against your local MariaDB database:

```bash
php artisan migrate
php artisan db:seed --class=CurrencySeeder
```

5. Build frontend assets or start the development servers:

```bash
npm run build
```

## Database Setup & Required Migrations

Equity is configured for MariaDB via `.env` (`DB_CONNECTION=mariadb`, `DB_DATABASE=equity`).

### Core & Financial Domain Migrations (`database/migrations/`)

- `0001_01_01_000000_create_users_table.php` - Creates `users`, `password_reset_tokens`, and `sessions` tables.
- `0001_01_01_000001_create_cache_table.php` - Creates `cache` and `cache_locks` tables.
- `0001_01_01_000002_create_jobs_table.php` - Creates `jobs`, `job_batches`, and `failed_jobs` tables.
- `2026_10_27_000001_create_currencies_table.php` - Creates the ISO 4217 `currencies` reference table.
- `2026_10_27_000002_create_financial_accounts_table.php` - Creates user-owned `financial_accounts`.
- `2026_10_27_000003_create_ledger_accounts_table.php` - Creates `ledger_accounts` with a unique `1:1` nullable link to `financial_accounts` and unique `(user_id, code)` constraint.
- `2026_10_27_000004_create_journal_entries_table.php` - Creates immutable `journal_entries` with a unique `(user_id, idempotency_key)` constraint.
- `2026_10_27_000005_create_journal_postings_table.php` - Creates immutable `journal_postings` storing signed 64-bit integer minor units (`debit_amount_minor`, `credit_amount_minor`).
- `2026_10_27_000006_create_audit_events_table.php` - Creates the append-only `audit_events` log.

> **Note:** Automated backend tests run against an isolated in-memory SQLite database (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` in `phpunit.xml`) and do not require a running MariaDB instance.

## Local Email Testing (Password Reset)

For local development, Equity uses Laravel's `log` mail driver (`MAIL_MAILER=log` in `.env.example`):

1. Ensure `MAIL_MAILER=log` is set in `.env`.
2. Submit a password reset request at `/forgot-password`.
3. Open `storage/logs/laravel.log` to inspect the generated reset email and copy the `/reset-password/{token}?email=...` URL into your browser.

## Development Commands

Start the Laravel server and Vite development server in separate terminals:

```bash
php artisan serve
npm run dev
```

Or run the combined development script:

```bash
composer run dev
```

Build production frontend assets:

```bash
npm run build
```

## Testing & Code Quality Commands

Run all backend tests (Pest with SQLite in-memory):

```bash
php artisan test
```

Run only the financial domain backend feature tests:

```bash
php artisan test tests/Feature/Finance
```

Run frontend unit and component tests (Vitest + React Testing Library):

```bash
npx vitest run
```

Run PHP code style validation (Laravel Pint):

```bash
vendor/bin/pint --test
```

Run TypeScript type checking:

```bash
npx tsc --noEmit
```

Run ESLint checks:

```bash
npx eslint .
```

Run Prettier formatting checks:

```bash
npx prettier --check .
```

## Current Implementation Status

### Implemented (through Milestone 5.4)

- Standalone Laravel 12 application configured for `Equity` and MariaDB (`DB_DATABASE=equity`)
- Session-based registration, login, `POST` logout, and password reset workflows
- Tailwind CSS v4 design system, reusable UI primitives, Light/Dark/System theme switching, and responsive application shell
- Normalized ISO 4217 currency reference table (`PHP`, `USD`, `AUD`, `EUR`, `GBP`, `JPY`) and integer minor-unit `Money` value object
- User-owned financial account management (`/accounts`) with create, edit, archive, and restore workflows
- Double-entry ledger schema (`ledger_accounts`, `journal_entries`, `journal_postings`), `LedgerPostingService` with concurrency-safe idempotency, and `AccountBalanceService` for ledger-derived balances
- Append-only `AuditService` and `audit_events` table
- Categories, transactions, budgets, and Bills & Subscriptions (`/bills`) with recurring obligations, occurrences, and payment linking (linking does not post expenses or move money)

### Intentionally Deferred (Planned for Future Phases)

- Financial reports, charts, and cash-flow forecasting
- Multi-currency foreign-exchange conversion entries

## Bills occurrence horizon and scheduler

Active recurring obligations keep a rolling **three-month UTC** occurrence horizon. Generating occurrences only creates schedule rows; it never posts expenses, creates journal entries, or creates payment links. Viewing the Bills page does **not** refresh the horizon.

### Artisan command

```bash
php artisan equity:recurring-refresh
```

Optional `--batch-size` (default `100`, max `500`) controls how many active obligations are loaded per chunk. Paused and cancelled obligations are skipped. The command is idempotent: reruns and catch-up after downtime reuse `RecurringObligationService::generateOccurrences()` and the `UNIQUE(obligation_id, active_slot_key)` safeguard, so historical paid, skipped, and cancelled slots are preserved.

### Daily schedule (application code)

Laravel registers the command once per day at **02:15 UTC** with `withoutOverlapping(60)`. Registration alone does not run the job. Production still needs an external cron (or equivalent) that invokes the scheduler every minute:

```cron
* * * * * cd /path/to/equity && php artisan schedule:run >> /dev/null 2>&1
```

Do not assume cron is already installed or running in any environment.

### Cache / locking assumptions

- Scheduler `withoutOverlapping` and the command’s cache lock use the application’s configured cache store (`CACHE_STORE`, default `database`).
- A shared cache store is required for overlap prevention across multiple app servers. This project does not require Redis.
- Manual concurrent runs of `equity:recurring-refresh` are rejected while the command lock is held.

### Monitoring and downtime

- Watch command exit codes: nonzero means at least one active obligation failed to refresh; others may still have succeeded.
- Application logs emit a warning per failed `obligation_id` without user financial payloads.
- After scheduler downtime, rerun `php artisan equity:recurring-refresh` (or wait for the next scheduled run). Missing slots through the current UTC horizon are backfilled safely; duplicates are not created.
