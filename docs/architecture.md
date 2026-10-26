# Equity Architecture

> **Status:** Phase 1 (Application Foundation). Sections describing financial domains, ledger mechanics, and money-handling rules document architectural requirements for future phases and are not yet implemented in application code.

---

## 1. Laravel, Inertia, and React Structure

Equity is a monolithic, full-stack web application built with Laravel 12 on the backend, Inertia.js as the protocol bridge, and React 19 with TypeScript on the frontend. Rather than maintaining a separate REST or GraphQL API for the primary web interface, controllers return Inertia responses that hydrate strongly typed React page components.

### Backend Layers

- `routes/web.php`: Defines standard Laravel web routes protected by the `web` middleware stack (session state, CSRF protection, and `HandleInertiaRequests`).
- `app/Http/Middleware/HandleInertiaRequests.php`: Configures the root Blade view (`resources/views/app.blade.php`), asset versioning, and shared props passed to every Inertia response.
- `config/database.php`: Configures MariaDB (`DB_CONNECTION=mariadb`, `DB_DATABASE=equity`) as the default application database connection, while automated backend tests run isolated against in-memory SQLite (`phpunit.xml`).
- `config/inertia.php`: Configures Inertia page resolution paths and component existence verification during testing.

### Frontend Organization

All frontend code lives under `resources/js/` with TypeScript strict mode enabled and the `@/*` path alias mapped to `resources/js/*`:

- `resources/js/app.tsx`: Client entry point that initializes Inertia (`createInertiaApp`) and mounts React 19 (`createRoot`).
- `resources/js/bootstrap.ts`: Configures HTTP defaults (`X-Requested-With` and credentials) for session and CSRF integration.
- `resources/js/Pages/`: Inertia page components mapped directly to backend `Inertia::render()` calls (for example, `Pages/Welcome.tsx`).
- `resources/js/Layouts/`: Structural page wrappers that provide consistent landmarks and document metadata (for example, `Layouts/PublicLayout.tsx`).
- `resources/js/Components/`: Reusable, focused UI primitives (for example, `Components/StatusBadge.tsx`).
- `resources/js/Types/`: Shared TypeScript interfaces and page prop contracts.
- `resources/js/Utilities/`: Pure helper functions with dedicated unit tests (for example, `Utilities/title.ts`).
- `resources/css/app.css`: Tailwind CSS v4 entry point defining foundational surface, ink, border, and accent design tokens via `@theme`.

---

## 2. Planned Financial Domain Boundaries

As Equity evolves beyond Phase 1, domain logic will be organized into clear bounded contexts so that accounting rules remain isolated from presentation concerns:

1. **Identity and Household Context (Future)**
    - User authentication, workspace or household ownership, and access control boundaries.
2. **Accounts and Commodities (Future)**
    - Asset, liability, equity, income, and expense accounts, along with explicit currency definitions.
3. **Ledger and Journal Engine (Future)**
    - Immutable journal entries, balanced debit/credit postings, and account balance derivation.
4. **Transactions and Categorization (Future)**
    - User-facing cash-flow events (inflows, outflows, transfers) that compile down to balanced journal entries.
5. **Budgets and Cash-Flow Planning (Future)**
    - Period allocations, spending envelopes, and forward-looking cash-flow projections derived from ledger state.

---

## 3. Future Ledger Architecture

When ledger capabilities are introduced in future milestones, Equity will separate user-facing transaction workflows from the underlying accounting ledger:

- **Chart of Accounts:** Every financial container (checking account, savings account, credit card, spending category, opening balance) maps to a typed ledger account (`Asset`, `Liability`, `Equity`, `Income`, `Expense`).
- **Journal Entries (`journal_entries`):** Represent a discrete, timestamped financial event with metadata (description, effective date, posted timestamp, actor/source reference, and correlation identifiers).
- **Journal Postings (`journal_postings`):** Each journal entry consists of two or more line items targeting specific accounts. The sum of all postings within a single currency for a posted journal entry must always equal zero (or total debits must equal total credits).
- **Corrections via Reversals:** Posted ledger entries represent historical facts. Corrections or voided transactions must be recorded via explicit reversing or adjusting journal entries rather than destructive in-place updates.

---

## 4. Money-Handling Principles

All future financial features in Equity must adhere to the following seven core principles:

### 1. Never Use Binary Floating-Point Arithmetic for Financial Amounts

IEEE 754 floating-point types (`float` in PHP, `number` float operations in JavaScript, `FLOAT`/`DOUBLE` in SQL) cannot represent base-10 fractions such as `0.10` or `0.01` exactly. Rounding drift in floating-point arithmetic is unacceptable in financial software. Binary floats must never be used to store, calculate, or aggregate money.

### 2. Use Exact Money Representations

- **Database Storage:** Store monetary amounts as exact integer minor units (`BIGINT` representing cents/minor units according to the currency's exponent) or fixed-precision `DECIMAL` columns with explicit scale, paired with an ISO 4217 currency code.
- **Backend Domain Logic:** Represent money using a dedicated immutable Value Object backed by integer minor units or arbitrary-precision decimal arithmetic (`brick/math`), preventing accidental mixing of raw numbers and monetary values.
- **Frontend Serialization:** Transmit monetary values to the frontend as structured objects containing an exact integer minor-unit string/number and currency code, formatting for display only at the presentation boundary.

### 3. Design Financial Operations Around Database Transactions

Every operation that records, modifies, or reconciles financial state must execute inside an atomic database transaction (`DB::transaction()`). If any validation check, balance invariant, or posting insertion fails, the entire unit of work must roll back cleanly so partial financial state is never persisted.

### 4. Prefer Balanced Double-Entry Journal Postings

Financial movements should be modeled as balanced double-entry postings rather than isolated single-column balance increments. Every inflow, outflow, or transfer moves value between accounts (for example, crediting a Checking Asset account and debiting a Groceries Expense account), guaranteeing that every dollar is accounted for and that the ledger remains self-consistent.

### 5. Maintain an Auditable Financial History

Every financial record must preserve a complete audit trail, including creation timestamps, effective dates, user or system origin, and links between original entries and any subsequent adjustments. Balance figures at any point in time should be reproducible from the sequence of recorded ledger postings.

### 6. Treat Currencies Explicitly

A monetary amount is meaningless without its currency. Every account, posting, and money value object must explicitly carry an ISO 4217 currency code (for example, `USD`, `EUR`, `GBP`). Arithmetic operations (addition, subtraction, comparison) must reject mismatched currencies unless an explicit, recorded foreign-exchange conversion entry is applied.

### 7. Avoid Silent Mutation of Posted Ledger Entries

Once a journal entry is committed/posted to the ledger, its monetary postings and target accounts must not be silently overwritten. If a user edits or deletes a transaction that has already been posted, the system must record an auditable reversal or adjustment entry so historical integrity and reconciliation trails remain intact.
