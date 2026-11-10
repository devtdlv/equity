# Visual audit screenshot manifest

Naming: `{nn}-{page}-{state}.png`  
Paths: `docs/visual-audit/{viewport}/{theme}/`

---

## Batch 1 — 1440 / light (recaptured)

| Field | Value |
| ----- | ----- |
| Status | **valid** — Phase 6.9 refresh after desktop UI refinements |
| Date | 2026-11-10 |
| Capture tool | Playwright Chromium (`scripts/visual-audit-playwright-desktop.mjs`) |
| Environment | `visual-audit` disposable SQLite; fictional demo user only |
| Viewport | **1440 × 900** CSS pixels |
| Device scale factor | **1** |
| Zoom | **100%** |
| Theme | Light (`data-theme=light`) |
| `fullPage` | `false` |
| Validation | Staging under `_diagnostics/staging-1440-light/` then promote; PNG IHDR 1440×900; DOM 1 sidebar + 1 page `h1`; expected demo strings present; UI refinement checks passed |

| File | Route | Captured state | Status | Visual findings |
| ---- | ----- | -------------- | ------ | --------------- |
| `01-dashboard-populated.png` | `/dashboard` | PHP + USD overview; **Category over limit** on PHP | OK | Refinement verified. **MINOR:** Bills/savings details mostly below fold. |
| `02-accounts-populated.png` | `/accounts` | Create form defaults to **PHP**; active balances | OK | Refinement verified. **MINOR:** Archived account body mostly below fold. |
| `03-transactions-populated.png` | `/transactions` | Create form **collapsed**; filters/history full width | OK | Refinement verified. **MINOR:** Dense Reverse actions. |
| `04-budgets-populated.png` | `/budgets` | Defaults to **PHP** (`currency_code`); overview + Demo Dining | OK | Refinement verified. |
| `05-savings-populated.png` | `/savings` | Portfolio counts; Emergency Fund; Travel Fund visible | OK | **MINOR:** Goal detail pane empty until selection; unused horizontal space. |
| `06-bills-populated.png` | `/bills` | Month overview + obligations (top of page) | OK | **Phase 6.12 refresh.** Mobile Needs payment shortcut hidden on desktop. **MINOR:** Occurrence Pay rows still need scroll (see `06b`). |
| `06b-bills-occurrences.png` | `/bills` | Occurrences scrolled: Paid / Partially paid / Overdue | OK | Supplemental diagnostic only (not Batch 1 promote set). |
| `07-reports-populated.png` | `/reports` | Nov 2026 PHP summary + cash-flow trend | OK | **Phase 6.12 refresh.** Amounts fully visible; overflow 0. **MINOR:** Category breakdown / USD still below fold. |
| `08-settings.png` | `/settings` | Profile demo identity; empty password fields | OK | **MINOR:** Email-verification notice is visually loud. No secrets captured. |

### Capture notes

- Phase 6.9 recapture after currency defaults, transactions collapse, and dashboard warning copy.
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

### 390 / light (remaining guest / extras)

| # | File | Route / state |
| - | ---- | ------------- |
| 01 | `01-welcome.png` | `/` |
| 02 | `02-login.png` | `/login` |

---

## Batch A — 390 / light (mobile, captured 2026-11-10)

| Field | Value |
| ----- | ----- |
| Status | **valid** — staging → promote for eight authenticated pages (+ nav-open supplemental) |
| Capture tool | Playwright Chromium (`scripts/visual-audit-playwright-mobile.mjs`) |
| Environment | `visual-audit` disposable SQLite; fictional demo user only |
| Viewport | **390 × 844** CSS pixels · DPR **1** · zoom 100% · light · `fullPage: false` |
| Validation | `_diagnostics/staging-390-light/validation-report.json`; PNG IHDR 390×844; mobile Menu visible; desktop sidebar hidden; console errors none |

| File | Route | Captured state | Status | Visual findings |
| ---- | ----- | -------------- | ------ | --------------- |
| `10-dashboard-populated.png` | `/dashboard` | PHP card; Category over limit | OK | Usable. Content continues below fold. |
| `10-dashboard-mobile-nav-open.png` | `/dashboard` | Menu open; all 8 links + logout | OK | Nav works; Close / Log out / theme toggle visible. |
| `11-accounts-populated.png` | `/accounts` | Create form; currency **PHP** | OK | Form dominates first viewport; balances below fold. |
| `13-transactions-populated.png` | `/transactions` | Create collapsed; filters | OK | History below fold. Action pair is tight. |
| `15-budgets-populated.png` | `/budgets` | **PHP** default; overview cards | OK | Category rows below fold. |
| `16-savings-populated.png` | `/savings` | Portfolio stats stacked | OK | Goal list below fold. |
| `17-bills-populated.png` | `/bills` | Overview + **Needs payment** shortcut | OK | **Phase 6.12 refresh.** Pay visible/unobstructed in first viewport. |
| `19-reports-month.png` | `/reports` | Filters + PHP period summary | OK | **Phase 6.12 refresh.** Overflow 0; `₱65,500.00` fully visible. |
| `21-settings.png` | `/settings` | Profile form | OK | Email-verification notice still loud. |

Diagnostics (not promoted): `13b-transactions-create-expanded.png`, `17b-bills-occurrences.png` under `_diagnostics/staging-390-light/`.

### Capture notes

- Mobile nav verified across all eight routes (Menu → link → matching `h1`).
- Phase 6.7 refinements hold: Accounts/Budgets PHP default; Budgets URL sync + USD switch; Transactions create collapsed/expanded; Dashboard “Category over limit”.
- Phase 6.12 refreshed Bills + Reports after a4e4cd8 mobile fixes (see below).
- No dark-theme capture in Batch A.

---

## Phase 6.12 — Bills + Reports refresh (after a4e4cd8)

| Field | Value |
| ----- | ----- |
| Status | **valid** — staging → promote for four targeted shots |
| Date | 2026-11-11 |
| Commit basis | `a4e4cd8` (mobile reports/bills usability) |
| Capture tool | Playwright Chromium (`scripts/visual-audit-phase-6-12-refresh.mjs`) |
| Environment | `visual-audit` disposable SQLite; fictional demo user only |
| Viewports | **1440 × 900** and **390 × 844** · DPR **1** · light · `fullPage: false` |
| Validation | `_diagnostics/staging-phase-6-12/validation-report.json`; PNG IHDR match; overflow 0; Reports amounts fully visible; mobile Needs payment + Pay visible/unobstructed; desktop shortcut hidden |

| File | Viewport | Route | Status | Findings |
| ---- | -------- | ----- | ------ | -------- |
| `1440/light/06-bills-populated.png` | 1440×900 | `/bills` | OK | Desktop overview + obligations; Needs payment hidden. |
| `1440/light/07-reports-populated.png` | 1440×900 | `/reports` | OK | PHP summary + cash-flow trend; `₱65,500.00` fully visible; overflow 0. |
| `390/light/17-bills-populated.png` | 390×844 | `/bills` | OK | Needs payment + Pay in first viewport; remaining copy spaced. |
| `390/light/19-reports-month.png` | 390×844 | `/reports` | OK | Income/Expenses amounts fully visible; overflow 0. |

### Remaining visual issues (post-6.12)

- **MINOR:** Reports category breakdown / USD sections remain below the fold at both viewports.
- **MINOR:** Desktop Bills occurrence Pay actions still require scroll past obligations (`06b` diagnostic).
- **MINOR:** Settings email-verification notice still visually loud (unchanged; not in this refresh).
- **MINOR:** Transactions mobile header action pair still tight (unchanged; not in this refresh).