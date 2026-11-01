# Integration Isolation Contract

Offline safety contract for a **future** disposable MariaDB integration environment. This document does not authorize creating, migrating, seeding, querying, or deleting any database.

## Reserved identities

| Role | Name | Status |
| :-- | :-- | :-- |
| Protected production/local schema | `equity` | Must **never** be targeted by integration migrate, seed, tests, servers, or cleanup. |
| Proposed disposable schema | `equity_it_6c2_20261101` | Reserved for planning. It does **not** exist by authorization. This milestone does not create it. |
| Dedicated MariaDB username | `equity_it_6c2` | Required exact username for layers 1 and 2. Must not be `root` or `root@host`. |

Any other database name, including similar names such as `equity_it_6c2`, is rejected.

## Safety layers

| Layer | Tool | What it proves | What it cannot prove |
| :-- | :-- | :-- | :-- |
| **1. CLI operator-intent preflight** | `php scripts/integration-preflight.php` | The flags typed on the command line match the isolation contract. | Which database Laravel, Artisan, or Pest would open. Privileges. That the schema exists. |
| **2. Explicit env-file and cache-state validation** | `php scripts/integration-resolved-config.php --env-file=...` | A named non-`.env` file uses the narrow supported format, required identifiers match the contract, `DB_URL`/`DB_SOCKET` are absent, and `bootstrap/cache/config.php` is not present under the project root. | Laravel's runtime resolved connection after bootstrap, Dotenv inheritance, or `config:cache` baked on another machine. It does not connect. |
| **3. Connected identity/privilege check** | **NOT IMPLEMENTED** | After a later approval, a future step may connect with disposable credentials only and confirm `SELECT DATABASE()`, `CURRENT_USER()`, and grants. | N/A until designed and authorized. |
| **4. Explicitly approved database operations** | migrate / seed / Pest / serve / cleanup | Only after layers 1–3 and a dedicated approval for that operation. | — |

Passing layers 1 and 2 is **not** proof of Laravel's resolved connection. `config/database.php` still defaults `mariadb` / `equity` / `root`. Process environment, `DB_URL`, unix sockets, host `localhost` vs `127.0.0.1`, and a config cache can select a different target than the flags you typed.

## Layer 2 supported env-file format

Deliberately narrow. Unsupported syntax is **FAIL**, not a guess.

Allowed:

- UTF-8 text, no BOM
- Blank lines
- Full-line `#` comments (not indented, not inline)
- Unquoted `KEY=VALUE` lines
- Required keys: `APP_ENV=integration`, `DB_CONNECTION=mariadb`, `DB_DATABASE=equity_it_6c2_20261101`, `DB_HOST`, `DB_PORT`, `DB_USERNAME=equity_it_6c2`
- Optional `DB_PASSWORD` (never printed)

Rejected:

- Basename `.env` or any path that resolves to the protected `.env` (the file is not opened)
- Quotes, `export`, interpolation (`$VAR`, `${VAR}`), backticks, `$()`, escapes, spaces around `=`
- Duplicate keys, malformed lines, unexpected keys
- `DB_URL`, `DB_SOCKET`, empty assignments of those keys, other `*_URL` / `*_DSN` / `*_SOCKET` keys
- `APP_CONFIG_CACHE` (alternative cache paths cannot be verified safely)
- Existing `bootstrap/cache/config.php` under `--project-root` (default: repository root)

Do not create a real `.env.integration` with live credentials in this repository. Tests use synthetic fixtures only.

## Pest / RefreshDatabase

Existing Pest Feature tests use `RefreshDatabase`, which runs `migrate:fresh` on **whatever connection PHPUnit resolved**. `phpunit.xml` defaults to SQLite `:memory:` but does **not** set `force="true"`. If `DB_DATABASE=equity` is already in the process environment, PHPUnit may keep it and wipe the protected schema.

Do not point `phpunit.xml` at MariaDB. Do not run Pest against MariaDB until a later, separately approved integration config exists. Layers 1 and 2 do not make `RefreshDatabase` safe.

## Separate approvals

Each of the following needs its own explicit authorization. None is granted by this runbook or by a passing layer 1/2 check.

1. Create the disposable schema `equity_it_6c2_20261101`.
2. Run migrations against that schema only.
3. Seed (`CurrencySeeder` only, unless a later prompt says otherwise).
4. Run tests that touch MariaDB (including any Pest/`RefreshDatabase` run).
5. Start a local application server using an integration env.
6. Drop/clean up the disposable schema.

Do not perform cleanup against `equity`. Do not restore backups onto either schema without a later prompt.

## Usage

Layer 1:

```bash
php scripts/integration-preflight.php --database=equity_it_6c2_20261101 --driver=mariadb --app-env=integration --host=127.0.0.1 --port=3306 --username=equity_it_6c2
```

Layer 2:

```bash
php scripts/integration-resolved-config.php --env-file=path/to/synthetic.env.integration
```

- Exit `0` = that layer's identifiers match the contract.
- Exit `1` = fail closed. Diagnostics never print passwords, tokens, or connection URLs.
- Never pass `--password`, `--url`, or `--unix-socket`.

Offline tests:

```bash
php scripts/tests/integration-preflight-test.php
php scripts/tests/integration-resolved-config-test.php
```
