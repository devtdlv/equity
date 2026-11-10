# Local setup and operations

Detailed install, database, scheduler, and quality-check notes for Equity. For a concise overview, see the root [`README.md`](../README.md).

## Prerequisites

- PHP 8.2+ with `pdo_sqlite`, `mbstring`, `openssl`, and `xml` (`pdo_mysql` only if you opt into MySQL/MariaDB)
- Composer 2.x
- Node.js 20+ (22/24 recommended) and npm
- Optional: MariaDB 10.6+ / 11.x or MySQL 8.x when `DB_CONNECTION` is `mariadb` or `mysql`

## Installation

Never overwrite an existing `.env` or `database/database.sqlite`. This document does not read or modify a real environment file on disk.

### First-time checkout

```bash
composer install
npm install
```

Create `.env` only if it is missing, then generate an application key **only for that new file**:

```bash
# Unix / macOS / Git Bash (create only if absent; never overwrite)
if [ ! -f .env ]; then
  cp .env.example .env
  php artisan key:generate
fi
```

```powershell
# Windows PowerShell (create only if absent; never overwrite)
if (-not (Test-Path -Path .env)) {
  Copy-Item .env.example .env
  php artisan key:generate
}
```

If `.env` already exists, skip both the copy and `key:generate` unless you intentionally want a new `APP_KEY` (that invalidates existing sessions and encrypted values).

### Existing checkout

```bash
composer install
npm install
```

Leave the existing `.env` in place. Changes to `.env.example` do not update an existing `.env`. Do not copy `.env.example` over `.env`.

### SQLite database file

Default path when `DB_CONNECTION=sqlite` and `DB_DATABASE` is unset: `database/database.sqlite`.

```bash
# Unix / macOS / Git Bash (create only if missing)
[ -f database/database.sqlite ] || touch database/database.sqlite
```

```powershell
# Windows PowerShell (create only if absent; never overwrite)
if (-not (Test-Path -Path database/database.sqlite)) {
  New-Item -ItemType File -Path database/database.sqlite | Out-Null
}
```

Laravel will not create this file automatically. Do not commit the database file or its WAL/SHM sidecars (gitignored).

### Migrations and currency seed

Review `database/migrations/`, then apply against the configured database:

```bash
php artisan migrate
php artisan db:seed --class=CurrencySeeder
```

These steps are manual. Presence of migration files in source is not proof they have been applied to any database.

### Frontend

**Built assets** (production-like local serve):

```bash
npm run build
php artisan serve
```

**Development** with Vite (Laravel and Vite together, or in separate terminals):

```bash
composer run dev
```

```bash
# or separately:
php artisan serve
npm run dev
```

## Database notes

### Default: file-based SQLite

- `DB_CONNECTION=sqlite` (see `.env.example` and `config/database.php`)
- File: `database/database.sqlite` when `DB_DATABASE` is omitted
- Foreign keys enabled by default (`DB_FOREIGN_KEYS`)
- Pragmas: `busy_timeout` 5000 ms and `journal_mode=WAL` (overridable)

Automated backend tests use isolated SQLite `:memory:` (`phpunit.xml` + `SqliteMemoryConnectionGuard`) and do **not** use `database/database.sqlite`.

**Limits:** writers are serialized at the database level. Prefer a single-node deployment for the SQLite file; do not share one file across hosts over a network filesystem. See [`sqlite-concurrency-readiness.md`](sqlite-concurrency-readiness.md).

**Backup:** prefer SQLite’s online backup API, or quiesce writers before copying. Do not blindly copy a live `database.sqlite` (or `-wal`/`-shm`) while writes are active.

**PostgreSQL:** not supported for Equity’s complete schema.

### Optional: MySQL or MariaDB

```env
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=equity
DB_USERNAME=your_user
DB_PASSWORD=your_password
```

Use `DB_CONNECTION=mysql` for MySQL with the same host/database/user variables.

### Migration inventory

The repository currently includes these migration files under `database/migrations/` (inventory of source files only):

| File | Purpose |
| ---- | ------- |
| `0001_01_01_000000_create_users_table.php` | `users`, `password_reset_tokens`, `sessions` |
| `0001_01_01_000001_create_cache_table.php` | `cache`, `cache_locks` |
| `0001_01_01_000002_create_jobs_table.php` | `jobs`, `job_batches`, `failed_jobs` |
| `2026_10_27_000001_create_currencies_table.php` | ISO 4217 `currencies` |
| `2026_10_27_000002_create_financial_accounts_table.php` | `financial_accounts` |
| `2026_10_27_000003_create_ledger_accounts_table.php` | `ledger_accounts` (1:1 link) |
| `2026_10_27_000004_create_journal_entries_table.php` | `journal_entries` + idempotency uniqueness |
| `2026_10_27_000005_create_journal_postings_table.php` | `journal_postings` (integer minor units) |
| `2026_10_27_000006_create_audit_events_table.php` | append-only `audit_events` |
| `2026_10_27_000007_create_categories_table.php` | `categories` |
| `2026_10_27_000008_add_category_id_to_ledger_accounts_table.php` | optional `category_id` |
| `2026_10_27_000009_create_financial_transactions_table.php` | `financial_transactions` |
| `2026_10_27_000010_add_reversal_columns_to_financial_transactions_table.php` | reversal linkage |
| `2026_10_29_000011_create_budgets_tables.php` | `budgets`, `budget_lines` |
| `2026_10_30_000012_create_recurring_obligations_tables.php` | bills tables |
| `2026_10_31_000013_create_savings_goals_tables.php` | savings tables |
| `2026_10_31_000014_add_create_request_fingerprint_to_savings_goals_table.php` | savings create fingerprint |
| `2026_11_02_000015_add_opening_journal_entry_to_financial_accounts_table.php` | opening balance link |
| `2026_11_03_000016_add_create_idempotency_to_financial_accounts_table.php` | account create idempotency |

Financial integrity guarantees (CHECK constraints, append-only triggers, foreign keys, idempotency unique indexes) remain part of these migrations.

## Local email (password reset)

Default mailer in `.env.example` is `MAIL_MAILER=log`:

1. Ensure `MAIL_MAILER=log` in `.env`.
2. Submit a reset request at `/forgot-password`.
3. Open `storage/logs/laravel.log` and copy the `/reset-password/{token}?email=...` URL.

## Bills occurrence horizon and scheduler

Active recurring obligations keep a rolling **three-month UTC** occurrence horizon. Generating occurrences only creates schedule rows; it never posts expenses or payment links. Viewing the Bills page does **not** refresh the horizon.

```bash
php artisan equity:recurring-refresh
```

Optional `--batch-size` (default `100`, max `500`). Paused and cancelled obligations are skipped. The command is idempotent via `RecurringObligationService::generateOccurrences()` and `UNIQUE(obligation_id, active_slot_key)`.

Laravel registers the command daily at **02:15 UTC** with `withoutOverlapping(60)`. Production still needs an external cron that runs the scheduler every minute:

```cron
* * * * * cd /path/to/equity && php artisan schedule:run >> /dev/null 2>&1
```

Cache / locking uses the configured cache store (`CACHE_STORE`, default `database`). A shared cache is required for overlap prevention across multiple app servers. Redis is not required.

## Testing and code quality

```bash
php artisan test
php artisan test tests/Feature/Finance
npx vitest run
vendor/bin/pint --test
npx tsc --noEmit
npx eslint .
npx prettier --check .
```

## Visual audit (isolated screenshots)

Screenshot workflow docs: [`visual-audit/README.md`](visual-audit/README.md).

```bash
php scripts/visual-audit-prepare.php
php artisan equity:visual-audit-verify --env=visual-audit
```

Do not open the real `.env` or repository SQLite from those scripts. README gallery images under [`screenshots/`](screenshots/) are copies of validated visual-audit captures that use non-production fixture data.

## Release readiness

Production hardening, smoke journey, and regression notes: [`release-readiness.md`](release-readiness.md).
