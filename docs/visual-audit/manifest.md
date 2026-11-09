# Visual audit screenshot manifest

Naming: `{nn}-{page}-{state}.png`  
Paths: `docs/visual-audit/{viewport}/{theme}/`

---

## Batch 1 — 1440 / light (recaptured)

| Field | Value |
| ----- | ----- |
| Status | **valid** — replaced invalid Cursor CDP Batch 1 |
| Date | 2026-11-08 |
| Capture tool | Playwright Chromium (`scripts/visual-audit-playwright-desktop.mjs`) |
| Environment | `visual-audit` disposable SQLite; fictional demo user only |
| Viewport | **1440 × 900** CSS pixels |
| Device scale factor | **1** |
| Zoom | **100%** |
| Theme | Light (`data-theme=light`) |
| `fullPage` | `false` |
| Validation | Staging under `_diagnostics/staging-1440-light/` then promote; PNG IHDR 1440×900; DOM 1 sidebar + 1 page `h1`; expected demo strings present |

| File | Route | Captured state | Status | Visual findings |
| ---- | ----- | -------------- | ------ | --------------- |
| `01-dashboard-populated.png` | `/dashboard` | PHP + USD overview cards; bills badges | OK | **ADDRESSED (pending recapture):** Budget badge copy now distinguishes aggregate vs category overspend. **MINOR:** Bills/savings details mostly below fold. |
| `02-accounts-populated.png` | `/accounts` | Create form + 4 active balances; archived header | OK | **ADDRESSED (pending recapture):** Create currency defaults from active account currencies. **MINOR:** Archived account body mostly below fold. |
| `03-transactions-populated.png` | `/transactions` | Expense form, filters, history (23) with reversal | OK | **ADDRESSED (pending recapture):** Create form collapsed by default; filters/history use full width. **MINOR:** Dense Reverse actions. |
| `04-budgets-populated.png` | `/budgets` | Nov 2026 · PHP selected · overview + Demo Dining | OK | **ADDRESSED (pending recapture):** Currency defaults from active accounts; `currency_code` URL restore. |
| `05-savings-populated.png` | `/savings` | Portfolio counts; Emergency Fund; Travel Fund visible | OK | **MINOR:** Goal detail pane empty until selection; unused horizontal space. |
| `06-bills-populated.png` | `/bills` | Month overview + obligations (top of page) | OK | **MINOR:** Occurrence payment states need scroll (see `06b`). |
| `06b-bills-occurrences.png` | `/bills` | Occurrences scrolled: Paid / Partially paid / Overdue | OK | Supplemental; sidebar may be partially out of frame after scroll-into-view. |
| `07-reports-populated.png` | `/reports` | Nov 2026 PHP summary + cash-flow trend start | OK | **IMPORTANT:** Chart only partly in viewport; USD / categories below fold. |
| `08-settings.png` | `/settings` | Profile demo identity; empty password fields | OK | **MINOR:** Email-verification notice is visually loud. No secrets captured. |

### Capture notes

- Prior Cursor browser MCP/CDP Batch 1 files were tiled/malformed and have been replaced.
- No BLOCKER findings in this recapture.
- Demo identity (`Equity Demo User` / `demo@equity.local`) is fictional visual-audit data.
- Password inputs captured empty.
- Staging artifacts retained in `docs/visual-audit/_diagnostics/staging-1440-light/`.

---

## Planned (not yet captured)

### 1440 / light (remaining)

| # | File | Route / state |
| - | ---- | ------------- |
| 01 | `01-welcome.png` | `/` |
| 02 | `02-login.png` | `/login` |
| 03 | `03-register.png` | `/register` |
| 04 | `04-forgot-password.png` | `/forgot-password` |
| 12 | `12-accounts-archived.png` | `/accounts` (archived section focused) |
| 14 | `14-transactions-transfer-mode.png` | `/transactions` transfer create mode |
| 18 | `18-bills-pay-dialog.png` | `/bills` pay dialog open |
| 20 | `20-reports-custom.png` | `/reports` custom range |

### 1440 / dark (secondary)

| # | File | Route / state |
| - | ---- | ------------- |
| 10 | `10-dashboard-populated.png` | `/dashboard` |
| 11 | `11-accounts-populated.png` | `/accounts` |
| 19 | `19-reports-month.png` | `/reports` |
| 21 | `21-settings.png` | `/settings` |

### 390 / light (mobile — not started)

| # | File | Route / state |
| - | ---- | ------------- |
| 01 | `01-welcome.png` | `/` |
| 02 | `02-login.png` | `/login` |
| 10 | `10-dashboard-populated.png` | `/dashboard` |
| 10b | `10-dashboard-mobile-nav-open.png` | `/dashboard` menu open |
| 11 | `11-accounts-populated.png` | `/accounts` |
| 13 | `13-transactions-populated.png` | `/transactions` |
| 15 | `15-budgets-populated.png` | `/budgets` |
| 16 | `16-savings-populated.png` | `/savings` |
| 17 | `17-bills-populated.png` | `/bills` |
| 19 | `19-reports-month.png` | `/reports` |
| 21 | `21-settings.png` | `/settings` |
