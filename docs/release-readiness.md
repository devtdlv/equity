# Equity MVP Release Readiness

> **Assessment date:** 2026-11-06
> **Scope:** MVP Stages 1–5 (opening balances, bill pay-and-post, financial reports, settings & profile, release readiness)
> **Recommendation:** **GO** for a correctly hardened single-node production deployment (see blockers below).

This document records Stage 5 evidence. It does not perform deployment. Operators must never treat an unchanged `.env.example` as a production `.env`.

---

## 1. Test evidence

Isolated disposable infrastructure only (`phpunit.xml` forces SQLite `:memory:`, array session/cache). Integration Phase 2/3/4C suites are separate configs and were not required for this MVP gate.

| Suite | Result | Exact counts |
| :---- | :----- | :----------- |
| Pest (Unit + Feature via `phpunit.xml`) | Pass | **549 passed**, **1 skipped**, **2 warnings**, **0 failed** (4471 assertions) |
| Vitest (`npm test` / `vitest run`) | Pass | **173 passed**, **0 failed** (19 files) |
| TypeScript (`tsc --noEmit`) | Pass | Exit code **0** |
| Production asset build (`npm run build`) | Pass | Exit code **0** (Vite production build) |

### Pest skip / warnings (not silent failures)

- **1 skipped:** Phase 3 workdir cleanup symlink case — environment could not create a file symlink (Windows permission/capability).
- **2 warnings:** Phase 4C.3 environment isolation symlink cases — `symlink(): Permission denied` in this environment. Related assertions still ran where possible; these are infrastructure-isolation harness warnings, not application product failures.

No product Feature/Unit tests failed. Failures were not skipped.

### Coverage exercised (automated)

Authentication, registration, login, logout, password reset; Settings profile/password/`auth.session` invalidation; financial accounts and opening balances; income/expense/transfer/reversals; budgets; recurring bills / link / pay-and-post; savings earmarks; dashboard summaries; financial reports and filters; ownership and multi-currency isolation.

---

## 2. Security and financial integrity findings

### PASS (application)

| Area | Notes |
| ---- | ----- |
| Auth boundaries | Guest vs `['auth', 'auth.session']` groups; policies + `user_id` scoping |
| CSRF | Default Laravel `web` stack; logout regenerates CSRF token |
| Settings / sessions | Current password for email/password changes; `logoutOtherDevices` + regenerate; stale `password_hash_web` rejected |
| Transaction atomicity | Financial writes via `FinancialDatabaseTransaction`; pay-and-post rolls back on link failure |
| Money precision | Integer minor units; checked arithmetic; no float ledger math |
| Idempotency | UUID keys + fingerprints; conflict vs replay separation |
| Currency isolation | Same-currency transfers; per-currency reports; no FX |
| Concurrency | Existing lock ordering and retries; `equity.sqlite.financial_immediate` remains **false** |
| Disclosure | Inertia shares only `id` / `name` / `email`; passwords hidden |

### Classified issues

#### BLOCKER (must resolve before calling production “live”)

| ID | Finding | Status |
| -- | ------- | ------ |
| B1 | Deploying with an unhardened env (`APP_KEY` empty, `APP_DEBUG=true`, `APP_ENV=local`) is unsafe | **Ops requirement** — documented in `.env.example` and this runbook. No application code defect. |

#### IMPORTANT (release may proceed with documented limitations)

| ID | Finding | Status |
| -- | ------- | ------ |
| I1 | Bills horizon needs external cron → `php artisan schedule:run` | Documented; not optional for healthy bills UX over time |
| I2 | Open self-service registration; email addresses are not verified | MVP product choice; restrict registration if deploying publicly without invite controls |
| I3 | Password-reset email requires real `MAIL_*` (example uses `log`) | Documented |
| I4 | File session/cache + single SQLite file are single-node oriented | Documented; do not multi-host one SQLite file |
| I5 | Email change does not call `logoutOtherDevices` (password change does) | Accepted MVP limitation |

#### DEFERRED (outside MVP)

| ID | Item |
| -- | ---- |
| D1 | Cash-flow forecasting |
| D2 | Foreign-exchange conversion |
| D3 | Mandatory email verification |
| D4 | Enabling `equity.sqlite.financial_immediate` |
| D5 | Async queue workers / Redis as a hard requirement |
| D6 | CSV export for reports |
| D7 | Uniform 403 vs 404 for cross-tenant IDs |

---

## 3. Production prerequisites

- PHP 8.2+ with extensions required by Laravel (including `pdo_sqlite` or MySQL/MariaDB driver)
- Composer 2.x dependencies installed (`composer install --no-dev` for production)
- Node.js 20+ for building assets (`npm ci` / `npm install`, then `npm run build`)
- Writable storage and bootstrap cache directories
- A generated `APP_KEY`
- Database: file SQLite **or** MySQL/MariaDB (PostgreSQL is not supported for the full schema)
- HTTPS-terminated `APP_URL`
- Cron (or equivalent) invoking the scheduler every minute
- Optional: SMTP or other mailer when password-reset delivery is required

### Required secrets / settings

| Setting | Requirement |
| ------- | ----------- |
| `APP_KEY` | Required; generate with `php artisan key:generate` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | Public origin |
| `DB_*` | Valid connection; create SQLite file before migrate if using SQLite |
| `LOG_LEVEL` | Prefer `warning` or `error` |
| `MAIL_*` | Real mailer if reset emails must leave the host |
| Session/cache | File OK for single node; database/redis for multi-instance |

---

## 4. Safe deployment sequence

1. Install PHP and Node dependencies on the build host.
2. Copy `.env.example` → `.env` on the target host **only as a template**, then harden (never leave debug on).
3. Generate `APP_KEY` if missing.
4. Ensure the SQLite file exists (if used) **without overwriting** an existing database.
5. Review pending migrations; take a backup first (section 5).
6. Run `php artisan migrate --force` only against the intended database.
7. Seed reference currencies if needed: `php artisan db:seed --class=CurrencySeeder` (idempotent reference data; no demo money).
8. Build assets: `npm run build`; deploy `public/build` with the app.
9. Set web root to `public/`; ensure `/up` health route is reachable.
10. Install scheduler cron.
11. Run the manual smoke checklist (section 7).

Do **not** run migrations or seeders against shared/production databases from developer workstations as part of routine coding.

---

## 5. Backup, restore, and rollback

### Backup (SQLite)

- Prefer the SQLite online backup API or a quiesced copy.
- Do **not** copy a live `database.sqlite` together with `-wal`/`-shm` sidecars while writes are active.
- Retain application `.env` (secrets) backups separately with restricted access.

### Backup (MySQL/MariaDB)

- Use `mysqldump` / managed snapshots consistent with InnoDB practices.

### Restore

1. Stop or drain writers.
2. Restore database file or import dump.
3. Restore matching application revision and built assets if schema changed.
4. Verify `php artisan migrate:status` and smoke tests.

### Rollback considerations

- Prefer forward-fix migrations; test `down()` only in disposable environments.
- Posted journals, audit events, and many financial rows are append-only — “undo” in product terms is **reversal**, not DELETE.
- Rolling back application code that expects newer columns without rolling back schema will break; keep code and schema paired.
- `equity.sqlite.financial_immediate` must remain **false** unless a separately approved enablement is in effect.

---

## 6. Post-deployment smoke tests (automated-adjacent)

| Check | Expected |
| ----- | -------- |
| `GET /up` | Healthy |
| `GET /login`, `GET /register` | 200 for guests |
| Authenticated `GET /dashboard` | 200; redirects guests to login |
| `php artisan schedule:list` | Shows `equity:recurring-refresh` |
| Asset URLs from `public/build/manifest.json` | Load without 404 |
| Log file / stderr | No repeated 500s after smoke journey |

---

## 7. Manual end-to-end MVP smoke checklist

Perform as one user in UTC-aware dates. Record pass/fail.

| Step | Action | Expected outcome | Reconciliation check |
| ---- | ------ | ---------------- | -------------------- |
| 1 | Register | Lands on dashboard; session authenticated | User row exists; password not stored plaintext |
| 2 | Logout → Login | Session regenerates; dashboard loads | Intended URL open-redirects rejected |
| 3 | Create checking account (PHP) with opening balance | Account appears; balance matches opening | Ledger-derived balance = opening minor units |
| 4 | Create USD account | Separate currency section | No cross-currency totals mixed |
| 5 | Post income + expense + transfer | Transactions list; balances update | Assets change by income − expense; transfer moves within currency only |
| 6 | Reverse one expense | Original marked reversed; opposite posting | Net effect of pair is zero; cannot double-reverse |
| 7 | Create budget for current UTC month | Allocations save | Spent vs allocated matches expenses in categories |
| 8 | Create bill; Pay remaining | Occurrence paid/partial; expense linked | Pay cannot exceed remaining; atomic with journal |
| 9 | Create savings goal; contribute earmark | Progress updates | **Account ledger balance unchanged** by earmark |
| 10 | Dashboard | Shows cash-flow / bills / savings widgets | Income/expense match month rules (active only) |
| 11 | Reports monthly + custom range | Charts and category totals | Σ categories = expenses; trend Σ = summary for custom |
| 12 | Settings: rename only | Saves without current password | Name updates in shell |
| 13 | Settings: change email with password | Requires current password | Normalized lowercase email |
| 14 | Settings: change password | Stays logged in here; other device session dies on next request | Can log in with new password only |
| 15 | Logout | Guest; POST-only logout | Session invalidated |

---

## 8. Known limitations (MVP)

- No email verification; changing email updates sign-in immediately.
- No FX; multi-currency is isolated per currency.
- Forecasting and FX conversion remain planned.
- Savings allocations are earmarks, not transfers.
- Reports have no CSV export.
- SQLite is single-writer; default queue is `sync`; example mailer is `log`.
- SQLite `BEGIN IMMEDIATE` for financial writes stays disabled.
- Bills occurrence horizon depends on scheduler cron.

---

## 9. Final recommendation

**GO** — Equity MVP is release-ready for a **single-node**, correctly configured deployment after operators satisfy **B1** (hardened production environment) and accept the IMPORTANT limitations (especially **I1** scheduler and **I2** open registration / unverified email).

**NO-GO** if any of the following remain true at cutover:

- `APP_DEBUG=true` or missing `APP_KEY` in production
- Shared multi-host writes against one SQLite file
- Expectation that password-reset email works without configuring mail
- Expectation that bills horizons refresh without cron

---

## 10. Related documents

- `README.md` — setup, status, scheduler notes
- `docs/architecture.md` — domain and Stage 1–4 design
- `docs/sqlite-concurrency-readiness.md` — IMMEDIATE remains off
- `docs/integration-isolation.md` — disposable integration harness rules
