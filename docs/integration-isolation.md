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

Passing layers 1 and 2 is **not** proof of Laravel's resolved connection. `config/database.php` still defaults `mariadb` / `equity` / `root`. Process environment, `DB_URL`, unix sockets, host `localhost` vs `127.0.0.1`, and a config cache can select a different target than the flags you typed.

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
```
