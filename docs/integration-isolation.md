# Integration Isolation Contract

Offline safety contract for a **future** disposable MariaDB integration environment. This document does not authorize creating, migrating, seeding, querying, or deleting any database.

## Reserved identities

| Role | Name | Status |
| :-- | :-- | :-- |
| Protected production/local schema | `equity` | Must **never** be targeted by integration migrate, seed, tests, servers, or cleanup. |
| Proposed disposable schema | `equity_it_6c2_20261101` | Reserved for planning. It does **not** exist by authorization. Milestone 6C.2 does not create it. |

Any other database name, including similar names such as `equity_it_6c2`, is rejected by the offline preflight.

## Required isolation

- Use a **separate integration environment file**. Do not reuse the protected `.env`.
- Use a **dedicated MariaDB account** whose privileges are limited to `equity_it_6c2_20261101`. That account must not be `root` and must not have rights on `equity`.
- Before any future database operation, operators must explicitly supply and verify **host**, **port**, **database**, **username**, **APP_ENV**, and **driver**. Passwords stay in the integration env file and are never passed to or printed by the offline preflight.
- Default Laravel / `.env.example` values (`DB_DATABASE=equity`, `APP_ENV=local`, implied port `3306`, username `root`) are unsafe for this work and must not be relied on.

## Offline check versus future connected check

| Check | What it proves | What it cannot prove |
| :-- | :-- | :-- |
| **Offline preflight** (`php scripts/integration-preflight.php`) | The flags you typed match the isolation contract: exact disposable name, `mariadb` driver, `APP_ENV=integration`, explicit host/port/username, and no protected/blank values. | Which database Laravel would actually open after `.env` loading, config caching (`config:cache`), or framework defaults. It does not talk to MariaDB and cannot inspect privileges. |
| **Connected / resolved-target verification** (not implemented) | After a **separate approval**, a future step may connect and confirm `SELECT DATABASE()`, account privileges, and the resolved Laravel connection name. | N/A until designed and authorized. |

The offline preflight **never** reads `.env`, `config/database.php`, or process environment variables. Passing that script is not permission to migrate.

## Separate approvals

Each of the following needs its own explicit authorization. None is granted by this runbook.

1. Create the disposable schema `equity_it_6c2_20261101`.
2. Run migrations against that schema only.
3. Seed (`CurrencySeeder` only, unless a later prompt says otherwise).
4. Run tests that touch MariaDB.
5. Start a local application server using the integration env.
6. Drop/clean up the disposable schema.

Do not perform cleanup against `equity`. Do not restore backups onto either schema without a later prompt.

## Pest / RefreshDatabase warning

Existing Pest Feature tests use `RefreshDatabase` and `phpunit.xml` (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`). That trait migrates and wipes **whatever connection PHPUnit is using**.

**Do not** point `phpunit.xml` or a MariaDB-backed Pest run at `equity`. Before any MariaDB-backed test run:

- Audit every `uses(RefreshDatabase::class)` caller.
- Use a dedicated integration PHPUnit config that can only resolve to `equity_it_6c2_20261101`.
- Keep the default `phpunit.xml` on SQLite `:memory:`.
- Obtain a separate approval for that test run.

## Offline preflight usage

From the repository root, pass **all** identifiers on the command line:

```bash
php scripts/integration-preflight.php --database=equity_it_6c2_20261101 --driver=mariadb --app-env=integration --host=127.0.0.1 --port=3306 --username=equity_it_6c2
```

- Exit `0` = identifiers match the contract.
- Exit `1` = fail closed. Read the `FAIL:` lines. No secret values are printed.
- Do not add `--password` or connection URLs. Those flags are rejected.

Isolated offline tests (no application bootstrap, no database):

```bash
php scripts/tests/integration-preflight-test.php
```
