# Equity

Personal finance, clearly understood.

> **Development Status:** Equity is currently under active development (Milestone 2, Phase 2: Secure Session Authentication). The application foundation and session-based authentication are implemented. Financial accounts, transactions, budgets, forecasting, and the double-entry ledger have not been implemented yet.

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

## Authentication Features Implemented (Phase 2)

- **Registration (`GET /register`, `POST /register`):**
    - Full name, normalized lowercase email address, password, and password confirmation.
    - Strong password rules (minimum 12 characters with uppercase, lowercase, numbers, and symbols).
    - Generic duplicate-email validation messaging (`Unable to complete registration with the provided details.`) and rate limiting.
    - Automatic sign-in and session ID regeneration on completion.
- **Login (`GET /login`, `POST /login`):**
    - Session-based authentication with optional "Remember me" persistence.
    - Rate limiting (`5` attempts per minute keyed by normalized email and IP address).
    - Generic invalid-credentials messaging that never discloses whether an email is registered.
    - Session ID regeneration and open-redirect protection on intended URL redirects.
- **Logout (`POST /logout`):**
    - `POST`-only logout route that logs out of the `web` guard, invalidates the session, regenerates the CSRF token, and redirects to the public welcome page (`/`).
- **Password Reset (`GET /forgot-password`, `POST /forgot-password`, `GET /reset-password/{token}`, `POST /reset-password`):**
    - Uses Laravel's native password broker (`password_reset_tokens` table).
    - Non-enumerating reset-link confirmation messaging and `password-reset` rate limiting (`5` requests per minute).
    - Validates reset tokens, enforces strong password rules, hashes the updated password, rotates the remember token, and invalidates the used reset token.
- **Authenticated Application Shell (`GET /dashboard`):**
    - Protected dashboard placeholder (`"Your financial overview is coming together."`) wrapped in `AuthenticatedLayout` with Equity branding, navigation, signed-in user identity, and a `POST /logout` action.

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

4. Configure your local MariaDB database (`DB_DATABASE=equity`) in `.env` and run the core migrations manually when ready:

```bash
php artisan migrate
```

5. Build frontend assets or start the development servers:

```bash
npm run build
```

## Database Setup & Required Migrations

Equity is configured for MariaDB via `.env` (`DB_CONNECTION=mariadb`, `DB_DATABASE=equity`).

The authentication foundation uses Laravel's default core migration files located in `database/migrations/`:

- `0001_01_01_000000_create_users_table.php` - Creates `users`, `password_reset_tokens`, and `sessions` tables.
- `0001_01_01_000001_create_cache_table.php` - Creates `cache` and `cache_locks` tables.
- `0001_01_01_000002_create_jobs_table.php` - Creates `jobs`, `job_batches`, and `failed_jobs` tables.

> **Note:** Automated backend tests run against an isolated in-memory SQLite database (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` in `phpunit.xml`) and do not require a running MariaDB instance.

## Local Email Testing (Password Reset)

For local development, Equity uses Laravel's `log` mail driver (`MAIL_MAILER=log` in `.env.example`):

1. Ensure `MAIL_MAILER=log` is set in `.env`.
2. Submit a password reset request at `/forgot-password`.
3. Open `storage/logs/laravel.log` to inspect the generated reset email and copy the `/reset-password/{token}?email=...` URL into your browser.

No external email service is required for local development.

## Required Environment Variables

Configure the following variables in `.env`:

| Variable         | Default / Example  | Description                                                                    |
| :--------------- | :----------------- | :----------------------------------------------------------------------------- |
| `APP_NAME`       | `Equity`           | Application display name                                                       |
| `APP_ENV`        | `local`            | Environment name (`local`, `testing`, `production`)                            |
| `APP_KEY`        | _(generated)_      | Laravel encryption key (`php artisan key:generate`)                            |
| `APP_DEBUG`      | `true`             | Debug mode toggle                                                              |
| `APP_URL`        | `http://localhost` | Base URL for the application                                                   |
| `DB_CONNECTION`  | `mariadb`          | Primary database driver                                                        |
| `DB_HOST`        | `127.0.0.1`        | MariaDB host                                                                   |
| `DB_PORT`        | `3306`             | MariaDB port                                                                   |
| `DB_DATABASE`    | `equity`           | Local MariaDB database name                                                    |
| `DB_USERNAME`    | `root`             | Database user (do not hardcode credentials in source control)                  |
| `DB_PASSWORD`    | _(empty)_          | Database password                                                              |
| `SESSION_DRIVER` | `file`             | Session driver (`file` or `database` once migrations are run)                  |
| `CACHE_STORE`    | `file`             | Cache store (`file` or `database` once migrations are run)                     |
| `MAIL_MAILER`    | `log`              | Mail driver (`log` writes password reset emails to `storage/logs/laravel.log`) |
| `VITE_APP_NAME`  | `"${APP_NAME}"`    | Application name exposed to Vite and Inertia                                   |

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

Run only the authentication and dashboard backend feature tests:

```bash
php artisan test tests/Feature/Auth tests/Feature/DashboardTest.php
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

### Implemented (Phases 1 & 2)

- Standalone Laravel 12 application configured for `Equity` and MariaDB (`DB_DATABASE=equity`)
- Inertia.js server and React 19 + TypeScript client integration
- Tailwind CSS v4 Vite setup with foundational design tokens
- Public welcome route (`GET /`) rendering the `Welcome` Inertia React page
- Session-based registration, login, `POST` logout, and password reset workflows
- Protected `GET /dashboard` route and responsive `AuthenticatedLayout` shell
- Automated backend tests (Pest) and frontend tests (Vitest + React Testing Library)
- Code quality tooling (Laravel Pint, ESLint, Prettier, TypeScript strict mode)
- Architecture, session security, ownership, and money-handling documentation in `docs/architecture.md`

### Not Yet Implemented (Planned for Future Phases)

- Complete design system component library
- Financial accounts and institutions
- Transactions and categorization
- Double-entry ledger and journal postings
- Budgets and cash-flow forecasting
