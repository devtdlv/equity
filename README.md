# Equity

Personal finance, clearly understood.

> **Development Status:** Equity is currently under active development (Milestone 1, Phase 1: Application Foundation). Only the application foundation and welcome page are implemented at this stage. Financial accounts, transactions, budgets, authentication, forecasting, and the double-entry ledger have not been implemented yet.

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

## Local Setup Instructions

### Prerequisites

- PHP 8.2 or newer with `pdo_mysql`, `pdo_sqlite`, `mbstring`, `openssl`, and `xml` extensions
- Composer 2.x
- Node.js 20+ (Node.js 22/24 recommended) and npm
- MariaDB 10.6+ or 11.x (required when running database migrations in future phases)

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

4. Build frontend assets or start the development servers:

```bash
npm run build
```

## Required Environment Variables

Configure the following variables in `.env`:

| Variable         | Default / Example  | Description                                                   |
| :--------------- | :----------------- | :------------------------------------------------------------ |
| `APP_NAME`       | `Equity`           | Application display name                                      |
| `APP_ENV`        | `local`            | Environment name (`local`, `testing`, `production`)           |
| `APP_KEY`        | _(generated)_      | Laravel encryption key (`php artisan key:generate`)           |
| `APP_DEBUG`      | `true`             | Debug mode toggle                                             |
| `APP_URL`        | `http://localhost` | Base URL for the application                                  |
| `DB_CONNECTION`  | `mariadb`          | Primary database driver                                       |
| `DB_HOST`        | `127.0.0.1`        | MariaDB host                                                  |
| `DB_PORT`        | `3306`             | MariaDB port                                                  |
| `DB_DATABASE`    | `equity`           | Local MariaDB database name                                   |
| `DB_USERNAME`    | `root`             | Database user (do not hardcode credentials in source control) |
| `DB_PASSWORD`    | _(empty)_          | Database password                                             |
| `SESSION_DRIVER` | `file`             | Session driver (`file` in Phase 1 before database migrations) |
| `CACHE_STORE`    | `file`             | Cache store (`file` in Phase 1 before database migrations)    |
| `VITE_APP_NAME`  | `"${APP_NAME}"`    | Application name exposed to Vite and Inertia                  |

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

Run backend tests (Pest with SQLite in-memory):

```bash
php artisan test
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

### Implemented (Phase 1: Application Foundation)

- Standalone Laravel 12 application configured for `Equity` and MariaDB (`DB_DATABASE=equity`)
- Inertia.js server and React 19 + TypeScript client integration
- Tailwind CSS v4 Vite setup with foundational design tokens
- Public welcome route (`GET /`) rendering the `Welcome` Inertia React page
- Frontend directory structure (`Pages`, `Components`, `Layouts`, `Types`, `Utilities`)
- Automated backend tests (Pest) and frontend tests (Vitest + React Testing Library)
- Code quality tooling (Laravel Pint, ESLint, Prettier, TypeScript strict mode)
- Architecture and money-handling documentation in `docs/architecture.md`

### Not Yet Implemented (Planned for Future Phases)

- User authentication and authorization
- Financial accounts and institutions
- Transactions and categorization
- Double-entry ledger and journal postings
- Budgets and cash-flow forecasting
