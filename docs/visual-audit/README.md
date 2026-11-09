# Equity Visual Audit

Phase 6 local screenshot preparation. This directory holds capture plans and (later) selected PNGs.

## Safety

- Use only the disposable visual-audit environment.
- Never point visual-audit tooling at the real `.env` or `database/database.sqlite`.
- Prepare the env with the **standalone** script (does not bootstrap Laravel / does not open `.env`):

```bash
php scripts/visual-audit-prepare.php
```

- The prepare script preserves an existing `.env.visual-audit` and never overwrites an existing disposable SQLite file. `--force` is refused (it would replace `APP_KEY` / retarget the database unsafely). Optional `--database=` values must be absolute paths under the dedicated EquityVisualAudit data directory.
- Verify isolation (loads `.env.visual-audit` only via `--env=visual-audit`):

```bash
php artisan equity:visual-audit-verify --env=visual-audit
```

- Migrate and seed **only after operator approval** (not part of Phase 6.2 execution):

```bash
php artisan migrate --env=visual-audit --force
php artisan db:seed --class=Database\\Seeders\\VisualAuditSeeder --env=visual-audit
```

- Serve localhost only (after approval):

```bash
php artisan serve --env=visual-audit --host=127.0.0.1 --port=8000
npm run dev
```

Do **not** use `composer run dev` (it starts a queue worker). Keep `QUEUE_CONNECTION=sync`.

## Demo login (fictional)

| Field | Value |
| ----- | ----- |
| Email | `demo@equity.local` |
| Password | `VisualAudit!Demo99` |

## Capture plan

| Viewport | Theme | Scope |
| -------- | ----- | ----- |
| 1440px | Light (primary) | Full page set in `manifest.md` |
| 390px | Light | Core journey + mobile nav open |
| 1440px | Dark (secondary) | Dashboard, Accounts, Reports, Settings |

Use Cursor browser MCP. Do not install Playwright/Puppeteer for this phase.

PNG files under `1440/` and `390/` are gitignored until explicitly selected for commit.

## Directory layout

```text
docs/visual-audit/
  README.md
  manifest.md
  1440/light/
  1440/dark/
  390/light/
```
