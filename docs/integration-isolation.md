# Integration Isolation Contract

Offline safety contract for a **future** disposable MariaDB integration environment. This document does not authorize creating, migrating, seeding, querying, or deleting any database.

Layers 1 and 2 are **offline intent checks**. Passing them is not proof of Laravel's actual runtime connection. They do not bootstrap the application, do not read `config/database.php` defaults, and do not connect.

## Reserved identities

| Role | Name | Status |
| :-- | :-- | :-- |
| Protected production/local schema | `equity` | Must **never** be targeted by integration migrate, seed, tests, servers, or cleanup. |
| Proposed disposable schema | `equity_it_6c2_20261101` | Reserved for planning. It does **not** exist by authorization. This milestone does not create it. |
| Dedicated MariaDB username | `equity_it_6c2` | Required exact username for layers 1 and 2. Must not be `root` or `root@host`. |
| Required client host | `127.0.0.1` | Exact match only. See host allowlist below. |

Any other database name, including similar names such as `equity_it_6c2`, is rejected.

## Host allowlist

`DB_HOST` / `--host` must be exactly `127.0.0.1`.

Rejected, including padded variants (leading or trailing whitespace):

- `localhost` (any case) and `*.localhost`
- Wildcard or unspecified bind addresses: `0.0.0.0`, `*`, `::`, `::1`, `[::1]`
- URL-like or path-like values: `://`, `/`, `\`, `@`, or `:` (for example `mysql://127.0.0.1`, `127.0.0.1/equity`, `127.0.0.1:3306`)
- Any other host string, including IPv6 and hostnames

Layer 2 uses the same host contract as layer 1 through the shared validator.

## Safety layers

| Layer | Tool | What it proves | What it cannot prove |
| :-- | :-- | :-- | :-- |
| **1. CLI operator-intent preflight** | `php scripts/integration-preflight.php` | The flags typed on the command line match the isolation contract. Process environment is ignored. | Which database Laravel, Artisan, or Pest would open. Privileges. That the schema exists. |
| **2. Explicit env-file, process-presence, and cache-state validation** | `php scripts/integration-resolved-config.php --env-file=...` | A named non-`.env` file uses the narrow supported format, required identifiers match the contract, dangerous process keys are **absent**, `DB_URL`/`DB_SOCKET` are absent from the file, and `bootstrap/cache/config.php` is not present at the **canonical repository** path. | Laravel's runtime resolved connection after bootstrap, Dotenv merge order, or a config cache baked on another machine. It does not connect. |
| **3. Connected identity/privilege check** | **NOT IMPLEMENTED** | After DBA provisioning and a later approval, a future step may connect with disposable credentials only and confirm `SELECT DATABASE()`, `CURRENT_USER()`, and grants. | N/A until designed and authorized. |
| **4. Explicitly approved database operations** | migrate / seed / Pest / serve / cleanup | Only after layers 1–3, DBA provisioning, and a dedicated approval for that operation. | — |

Passing layers 1 and 2 is **not** proof of Laravel's resolved connection. Application defaults in `config/database.php` / `.env.example` are now **file-based SQLite** (`sqlite` → `database/database.sqlite`) for new installs; that does **not** authorize MariaDB integration work and does **not** change this contract's reserved MariaDB identities. For a future MariaDB integration run you must still supply an explicit non-`.env` env file with `DB_CONNECTION=mariadb` and the reserved disposable schema/user. Process environment, `DB_URL`, unix sockets, host `localhost` vs `127.0.0.1`, and a config cache can select a different target than the flags you typed. Existing production `.env` files are not updated by `.env.example` and must not be assumed to have switched engines.

## Process environment presence rejection (layer 2)

Layer 2 inspects **presence only**. It never uses inherited values as configuration fallbacks and never prints those values.

Fail closed when any of these process keys is present, even if empty:

- `APP_ENV`
- `APP_CONFIG_CACHE`
- `DB_CONNECTION`, `DB_DATABASE`, `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`
- `DB_URL`, `DB_SOCKET`, `DB_UNIX_SOCKET`, `DATABASE_URL`, `MYSQL_PWD`, `DB_DSN`

`APP_ENV` is rejected in the process environment because it is a Laravel environment selector. The other keys are database connection selectors or config-cache remappers (`APP_CONFIG_CACHE` changes Laravel's cached config path).

Layer 1 continues to ignore process environment: it validates CLI flags only. Layer 2 must be run in a shell that does not export those keys. Offline tests spawn an explicitly sanitized child process for positive CLI cases so a developer shell that already contains `DB_*` cannot produce a false pass.

Do not mutate the parent process environment to make a check pass.

## Canonical project-root binding

The project root is the canonical repository root derived from `scripts/integration-resolved-config.php` (`dirname` of the script, then `realpath`). `--project-root` outside that root is always rejected. There is no test-mode flag, environment-variable bypass, `debug_backtrace` gate, or caller-identity exception.

Offline tests exercise synthetic directories by calling internal helpers with explicit path arguments. Those helpers never change CLI behavior. The CLI entry point always binds to the canonical repository root and always rejects inherited process selectors.

Fail closed when `realpath` cannot canonicalize a path, including ambiguous symlinks or junctions where PHP reports failure.

## Config cache checks and limitations

- Layer 2 **always** inspects the canonical repository file `bootstrap/cache/config.php`.
- Internal helpers used by offline tests also inspect a caller-supplied directory's `bootstrap/cache/config.php` when that directory differs from the canonical root. The CLI cannot select that directory.
- Process `APP_CONFIG_CACHE` is rejected regardless of value.
- File-key `APP_CONFIG_CACHE` is rejected.
- These checkers **never delete or clear** a cache file. Remove a cache only through a separately authorized, documented operation.
- `bootstrap/cache/packages.php` and `bootstrap/cache/services.php` are **not** treated as the config cache. Their presence is a remaining limitation: they do not rewrite `DB_*`, but they show Laravel cache files can exist beside a missing `config.php`.
- `is_file` follows some links and cannot classify every broken Windows junction. If PHP cannot see a cache file, layer 2 cannot fail on it.
- Windows `realpath` may fail or collapse junctions differently than Unix. Fail closed on `realpath === false`. Drive-letter case is compared case-insensitively.

Do not open the protected `.env`. Basename `.env` and any path that canonicalizes to `{canonical repository}/.env` are rejected without reading file contents.

## Layer 2 supported env-file format

Deliberately narrow. Unsupported syntax is **FAIL**, not a guess.

Allowed:

- UTF-8 text, no BOM
- Blank lines
- Full-line `#` comments (not indented, not inline)
- Unquoted `KEY=VALUE` lines
- Required keys: `APP_ENV=integration`, `DB_CONNECTION=mariadb`, `DB_DATABASE=equity_it_6c2_20261101`, `DB_HOST=127.0.0.1`, `DB_PORT`, `DB_USERNAME=equity_it_6c2`
- Optional `DB_PASSWORD` (never printed)

Rejected:

- Basename `.env` or any path that resolves to the protected `.env` (the file is not opened)
- Quotes, `export`, interpolation (`$VAR`, `${VAR}`), backticks, `$()`, escapes, spaces around `=`
- Duplicate keys, malformed lines, unexpected keys
- `DB_URL`, `DB_SOCKET`, empty assignments of those keys, other `*_URL` / `*_DSN` / `*_SOCKET` keys
- `APP_CONFIG_CACHE` (alternative cache paths cannot be verified safely)
- Existing canonical `bootstrap/cache/config.php`

Do not create a real `.env.integration` with live credentials in this repository. Tests use synthetic fixtures only.

## Later DBA provisioning and connected verification

Required **later** sequence. None of these steps is authorized by this runbook or by a passing layer 1/2 check:

1. **DBA provisioning** (separate authorization): create dedicated user `equity_it_6c2` and empty schema `equity_it_6c2_20261101` on `127.0.0.1`. Prefer a MariaDB instance that does not contain `equity`. Layer 2/3 tools must not create the schema.
2. Run layer 1 with explicit CLI identifiers.
3. Run layer 2 against a synthetic or operator-held env file that is **not** the protected `.env`, in a process without inherited `DB_*` / `APP_ENV` / `APP_CONFIG_CACHE` selectors, with no canonical config cache file.
4. **Layer 3 connected identity/privilege check** (NOT IMPLEMENTED; separate authorization): connect only with disposable credentials and confirm the session database and user. Must not migrate, seed, or `CREATE DATABASE`.
5. Only after that, separately authorize each of migrate, seed, MariaDB Pest, serve, or cleanup.

## Pest / RefreshDatabase

Default Pest/PHPUnit remains **SQLite `:memory:` only**. That harness is separate from the application's default **file-based** SQLite path (`database/database.sqlite`). `phpunit.xml` forces `APP_ENV=testing`, `DB_CONNECTION=sqlite`, and `DB_DATABASE=:memory:`, and it empties inherited URL/socket/host/user selectors. Matching `<server>` entries overwrite `$_SERVER`. The `:memory:` guard and `phpunit.xml` entries must stay intact; do not retarget tests at the file database or at MariaDB.

### Significance and limits of `force="true"`

Verified against installed PHPUnit **11.5.56** (`vendor/phpunit/phpunit/src/TextUI/Configuration/PhpHandler.php`):

- Default `force` is **false**. Without it, an inherited `DB_DATABASE=equity` is kept and `RefreshDatabase` can run `migrate:fresh` on the protected MariaDB schema.
- `force="true"` on `<env>` overwrites `getenv()` / `putenv()` and `$_ENV` only.
- PHPUnit 11.5 **does not** write `<env>` values into `$_SERVER`. Laravel `Env` reads `$_SERVER` first (vlucas `ServerConstAdapter` before `EnvConstAdapter`), so `<server>` duplicates are required.
- `force="true"` cannot **unset** a key. Empty `DB_URL` / `DB_SOCKET` values are used because Laravel's `ConfigurationUrlParser` skips a falsy `url`. An empty `APP_CONFIG_CACHE` would resolve to the project root; tests instead force a sentinel path that must not exist: `bootstrap/cache/phpunit-config-must-not-exist.php`. Do not create that file. Do not delete a real config cache from these tools.
- Windows may drop empty `putenv` assignments. `$_SERVER` overwrites remain the Laravel-visible neutralization.

### Why config cache and process environment still matter

If `bootstrap/cache/config.php` exists, Laravel can load a baked connection configuration (for example a prior `mariadb` / `equity` / `root` cache, or a file-based SQLite path) and ignore later `env()` values. Forcing `APP_CONFIG_CACHE` to a missing sentinel path makes `is_file(getCachedConfigPath())` false for the default test process. It does **not** prove a future Pest run, a custom cache path created after XML load, or a `phpunit.xml` override from the CLI. The fail-closed guard still requires the resolved test connection to be SQLite `:memory:`, not `database/database.sqlite`.

### MariaDB Pest remains prohibited

Do not point `phpunit.xml` at MariaDB. Do not run Pest against MariaDB, including the reserved disposable name `equity_it_6c2_20261101`, until a later, separately approved integration configuration exists. Layers 1 and 2 do not make `RefreshDatabase` safe.

### Fail-closed SQLite :memory: bootstrap guard

`phpunit.xml` is necessary and not sufficient. Default application tests that extend `tests/TestCase.php` now run `Tests\Support\SqliteMemoryConnectionGuard` **after** Laravel has created the application and loaded configuration, and **before** `setUpTraits()`.

Verified against installed Laravel 12 (`InteractsWithTestCaseLifecycle::setUpTheTestEnvironment`): `refreshApplication()` / `createApplication()` (Kernel bootstrap, config resolved) runs first; then `setUpTraits()` which may call `RefreshDatabase::refreshDatabase()`, `DatabaseMigrations`, `DatabaseTruncation`, or `DatabaseTransactions`. `beforeRefreshingDatabase()` is the first line of `refreshDatabase()` and is earlier than `migrate:fresh`, but it does **not** run for Feature tests that omit `RefreshDatabase` (for example Welcome). Truncation can `migrate:fresh` without that hook. The TestCase hook is therefore `refreshApplication()` after `parent::refreshApplication()`, with a second check at the start of `setUpTraits()`.

Required for pass:

- `database.default` is exactly `sqlite`
- `database.connections.sqlite.driver` is exactly `sqlite`
- `database.connections.sqlite.database` is exactly `:memory:`
- no truthy database URL, unix socket, or `database_url` on that connection
- no `read` / `write` splitting or sticky routing
- test transaction connections are exactly `['sqlite']`, using the `connectionsToTransact()` method when present so test overrides are visible before `setUpTraits()`
- Laravel config is not loaded from cache, and neither the resolved `APP_CONFIG_CACHE` path nor `bootstrap/cache/config.php` exists as a file

The guard inspects the config repository and `is_file()` on cache paths only. It does not open a PDO connection. Failures do not print passwords, URLs, or socket paths.

### What the guard cannot prove without runtime testing

- That a future Pest run actually booted this TestCase (this milestone does not execute Pest).
- That a service provider did not connect during Kernel bootstrap, which happens inside `parent::refreshApplication()` before the guard.
- That a test using a non-TestCase base (plain PHPUnit unit tests) cannot touch a database. Those tests currently do not boot Laravel.
- That CLI flags, a different PHPUnit config, or a later-created cache file cannot change the next process.
- MariaDB identity or privileges. Layer 3 remains unimplemented.

### MariaDB Pest remains prohibited

Do not point `phpunit.xml` at MariaDB. Do not run Pest against MariaDB, including the reserved disposable name `equity_it_6c2_20261101`, until a later, separately approved integration configuration exists. Layers 1 and 2 and this SQLite guard do not make MariaDB `RefreshDatabase` safe.

### Future separately approved verification

After explicit authorization, run the default Pest suite once and confirm it still uses SQLite `:memory:` only. Do not treat a passing local Pest run as permission to create `equity_it_6c2_20261101` or to migrate MariaDB.

Offline checks (do not run Pest):

```bash
php scripts/tests/phpunit-sqlite-env-test.php
php scripts/tests/sqlite-memory-guard-test.php
```

## Separate approvals

Each of the following needs its own explicit authorization. None is granted by this runbook or by a passing layer 1/2 check.

1. Create the disposable schema `equity_it_6c2_20261101`.
2. Run migrations against that schema only.
3. Seed (`CurrencySeeder` only, unless a later prompt says otherwise).
4. Run tests that touch MariaDB (including any Pest/`RefreshDatabase` run).
5. Start a local application server using an integration env.
6. Drop/clean up the disposable schema.

**Explicitly prohibited until separately authorized:** migrations, seeding, MariaDB Pest, and database creation.

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

Unset inherited `DB_*`, `APP_ENV`, and `APP_CONFIG_CACHE` in that shell first. Do not pass `--project-root` unless it is the canonical repository root.

- Exit `0` = that layer's identifiers match the contract.
- Exit `1` = fail closed. Diagnostics never print passwords, tokens, or connection URLs, and never print inherited environment values.
- Never pass `--password`, `--url`, or `--unix-socket`.

Offline tests:

```bash
php scripts/tests/integration-preflight-test.php
php scripts/tests/integration-resolved-config-test.php
php scripts/tests/phpunit-sqlite-env-test.php
php scripts/tests/sqlite-memory-guard-test.php
```
