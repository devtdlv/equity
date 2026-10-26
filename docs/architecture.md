# Equity Architecture

> **Status:** Phase 3 (Premium Design System and Responsive Application Shell). Sections describing financial domains, ledger mechanics, and money-handling rules document architectural requirements for future phases and are not yet implemented in application code.

---

## 1. Laravel, Inertia, and React Structure

Equity is a monolithic, full-stack web application built with Laravel 12 on the backend, Inertia.js as the protocol bridge, and React 19 with TypeScript on the frontend. Rather than maintaining a separate REST or GraphQL API for the primary web interface, controllers return Inertia responses that hydrate strongly typed React page components.

### Backend Layers

- `routes/web.php`: Defines standard Laravel web routes protected by the `web` middleware stack (session state, CSRF protection, and `HandleInertiaRequests`), split into public, `guest`, and `auth` route groups.
- `app/Http/Middleware/HandleInertiaRequests.php`: Configures the root Blade view (`resources/views/app.blade.php`), asset versioning, and minimal shared props (`app`, `auth.user`, and `flash.status`) passed to Inertia responses.
- `app/Http/Controllers/Auth/`: Contains focused controllers for registration (`RegisteredUserController`), session authentication (`AuthenticatedSessionController`), password reset link requests (`PasswordResetLinkController`), and password updates (`NewPasswordController`).
- `app/Http/Requests/Auth/LoginRequest.php`: Encapsulates login validation, email normalization, rate limiting, and credential verification.
- `app/Http/Controllers/DashboardController.php`: Renders the authenticated `Dashboard` placeholder view.
- `config/database.php`: Configures MariaDB (`DB_CONNECTION=mariadb`, `DB_DATABASE=equity`) as the default application database connection, while automated backend tests run isolated against in-memory SQLite (`phpunit.xml`).
- `config/inertia.php`: Configures Inertia page resolution paths and component existence verification during testing.

### Frontend Organization

All frontend code lives under `resources/js/` with TypeScript strict mode enabled and the `@/*` path alias mapped to `resources/js/*`:

- `resources/js/app.tsx`: Client entry point that initializes the theme system (`initializeTheme`), boots Inertia (`createInertiaApp`), and mounts React 19 (`createRoot`).
- `resources/js/bootstrap.ts`: Configures HTTP defaults (`X-Requested-With` and credentials) for session and CSRF integration.
- `resources/js/Pages/`: Inertia page components mapped directly to backend `Inertia::render()` calls (`Welcome.tsx`, `Dashboard.tsx`, `Auth/Register.tsx`, `Auth/Login.tsx`, `Auth/ForgotPassword.tsx`, and `Auth/ResetPassword.tsx`).
- `resources/js/Layouts/`: Structural page wrappers (`PublicLayout.tsx` for public pages, `GuestLayout.tsx` for authentication forms, and `AuthenticatedLayout.tsx` for the authenticated workspace shell).
- `resources/js/Components/`: Reusable, typed UI primitives (`Button.tsx`, `Input.tsx`, `Textarea.tsx`, `Select.tsx`, `Checkbox.tsx`, `FormField.tsx`, `Card.tsx`, `Badge.tsx`, `StatusBadge.tsx`, `Alert.tsx`, `Separator.tsx`, `EmptyState.tsx`, `PageHeader.tsx`, `Skeleton.tsx`, and `ThemeToggle.tsx`).
- `resources/js/Types/`: Shared TypeScript interfaces (`AuthenticatedUser`, `AuthContext`, `SharedPageProps`, and page prop contracts).
- `resources/js/Utilities/`: Pure helper functions and hooks (`title.ts`, `theme.ts`, and `useTheme.ts`).
- `resources/css/app.css`: Tailwind CSS v4 entry point defining semantic CSS variables (`--eq-*`) and `@theme inline` tokens.

---

## 2. Design System, Theme Strategy, and Application Shell

### Design Tokens & Typography

Equity uses a calm, high-contrast financial palette built on semantic CSS variables in `resources/css/app.css`:

- **Typography:** `Inter` (`--font-sans`) for UI and headings with OpenType legibility features (`cv02`, `cv03`, `cv04`, `cv11`), paired with `JetBrains Mono` (`--font-mono`) for badges, status labels, and code identifiers. A `.tabular-nums` utility (`font-variant-numeric: tabular-nums`) is provided for future financial figures.
- **Surface Tokens:** `surface` (page background), `surface-elevated` (cards, sidebars, headers), `surface-muted` (recessed panels, table headers, subtle groupings), and `surface-subtle` (skeletons and active states).
- **Typography & Border Tokens:** `ink`, `ink-secondary`, `ink-muted`, `ink-inverse`, `border-subtle`, and `border-strong`.
- **Semantic Accents:** Primary teal accent (`accent`, `accent-strong`, `accent-subtle`, `accent-border`, `accent-foreground`) plus semantic feedback tokens (`positive`, `warning`, `negative` and their `-subtle` / `-border` counterparts) that adapt between `:root` (light) and `.dark` (dark).

### Theme Architecture (`Light`, `Dark`, `System`)

1. **Pre-Hydration Boot Script (`resources/views/app.blade.php`):**
    - Runs synchronously in `<head>` before first paint, reading `equity-theme-preference` from `localStorage` (falling back to `'system'`) and checking `window.matchMedia('(prefers-color-scheme: dark)')`.
    - Toggles the `.dark` class, `data-theme`, `data-theme-preference`, and `style.colorScheme` on `document.documentElement` to prevent a flash of unstyled or mismatched theme content.
    - Wrapped in `try / catch` so restricted environments without `localStorage` or `matchMedia` never break page rendering.
2. **Client Runtime (`resources/js/Utilities/theme.ts` & `useTheme.ts`):**
    - Centralizes reading, validating, persisting, and applying theme preferences.
    - Dispatches a custom DOM event (`equity:theme-change`) when a user switches modes and listens to `matchMedia` change events when `system` mode is active, keeping all mounted `ThemeToggle` controls synchronized across layouts.

### Responsive Application Shell (`AuthenticatedLayout`)

- **Desktop (`lg` and above):** Renders a full-height, fixed-width (`w-72`) `<aside>` sidebar containing the Equity wordmark, the active `Dashboard` navigation item, non-interactive upcoming module indicators (`Planned`), the `ThemeToggle`, user identity card, and `POST /logout` button. The main content column fills the remaining width without horizontal overflow.
- **Mobile & Tablet (below `lg`):** Renders a compact top header with an accessible menu toggle (`aria-expanded`, `aria-controls="mobile-navigation-menu"`). The collapsible mobile navigation panel provides keyboard-accessible links, `ThemeToggle`, user details, and logout, closing automatically on `Escape` or Inertia route transitions.

### Accessibility Conventions

- Semantic landmarks (`<header>`, `<nav>` with distinct `aria-label` attributes, `<main>`, `<aside>`, `<footer>`) and sequential heading hierarchy (`<h1>` page title followed by `<h2>` section headings).
- Form controls (`FormField`, `Input`, `Textarea`, `Select`, `Checkbox`) associate labels via `htmlFor`/`id`, bind hints and error messages via `aria-describedby`, set `aria-invalid="true"` when errors exist, and announce validation messages with `role="alert"`.
- Interactive elements use visible keyboard focus rings (`focus-visible:ring-2 focus-visible:ring-focus-ring/40`) and respect `prefers-reduced-motion: reduce`.
- Status and feedback components (`Alert`, `Badge`) pair color with explicit text prefixes or labels rather than relying on color alone.

---

## 3. Authentication Flow & Session Security

Equity uses Laravel's built-in stateful `web` session guard and password broker rather than stateless tokens (JWT), OAuth, or external identity providers.

### Authentication Lifecycle

1. **Registration (`GET /register`, `POST /register`):**
    - Validates `name`, normalized lowercase `email`, and `password` (confirmed, minimum 12 characters with mixed case, letters, numbers, and symbols configured via `Password::defaults()` in `AppServiceProvider`).
    - Uses a non-enumerating error message if the email constraint fails (`Unable to complete registration with the provided details.`).
    - Hashes the password via the `User` model's `'password' => 'hashed'` cast, dispatches the `Registered` event, signs the user in via `Auth::login($user)`, regenerates the session ID (`$request->session()->regenerate()`), and redirects to `/dashboard`.
2. **Login (`GET /login`, `POST /login`):**
    - Handled by `LoginRequest` and `AuthenticatedSessionController`.
    - Applies rate limiting of 5 attempts per minute keyed by normalized email and client IP address.
    - Returns a generic `auth.failed` validation message on invalid credentials so an attacker cannot distinguish between an unregistered email address and an incorrect password.
    - Regenerates the session ID upon successful authentication to prevent session fixation.
    - Validates any stored `url.intended` value (`resolveSafeIntendedUrl`) to ensure redirects only target internal paths on the application host, preventing open-redirect vulnerabilities.
3. **Logout (`POST /logout`):**
    - Exclusively accepts `POST` requests with CSRF verification (`GET /logout` is rejected with `405 Method Not Allowed`).
    - Logs out the user via `Auth::guard('web')->logout()`, invalidates the entire server session (`$request->session()->invalidate()`), rotates the CSRF token (`$request->session()->regenerateToken()`), and redirects to the public welcome route (`/`).
4. **Password Reset (`GET /forgot-password`, `POST /forgot-password`, `GET /reset-password/{token}`, `POST /reset-password`):**
    - Uses Laravel's `Password::broker()` backed by the `password_reset_tokens` table (60-minute token expiration, 60-second broker throttle, plus route-level `throttle:password-reset` rate limiting of 5 requests per minute).
    - `PasswordResetLinkController` always returns the same confirmation message (`If an account matches that email address, we have sent a password reset link.`) regardless of whether the email exists in the `users` table.
    - `NewPasswordController` verifies the token, enforces `Password::defaults()`, updates the user's password hash, rotates `remember_token`, dispatches `PasswordReset`, and deletes the consumed reset token so it cannot be reused.

### Session & Shared Data Security Controls

- **CSRF Protection:** All state-changing requests (`POST`, `PUT`, `PATCH`, `DELETE`) are protected by Laravel's `VerifyCsrfToken` middleware in the `web` group.
- **Cookie Hardening:** Session cookies default to `http_only = true` and `same_site = 'lax'` (`config/session.php`).
- **Minimal Shared Props:** `HandleInertiaRequests` explicitly projects `auth.user` to `{ id, name, email }` (or `null` for guests). Sensitive attributes (`password`, `remember_token`, reset tokens, timestamps) are never serialized into Inertia page payloads.

---

## 4. User Ownership Boundaries & Future Financial Authorization Principles

In upcoming milestones, Equity will store sensitive personal financial records. All domain models, queries, and mutations must enforce strict user ownership boundaries:

1. **Explicit Ownership Scoping:**
    - Every user-owned financial entity (accounts, transactions, journal entries, budgets, categories) must belong to an authenticated `User` (or an explicitly authorized ownership context) via a non-nullable foreign key and Eloquent relationship.
2. **Never Trust Client-Submitted User Identifiers:**
    - Controllers, form requests, and domain actions must never accept `user_id` or owner identifiers from request payloads, query parameters, or hidden form inputs.
    - Ownership must always be derived on the server from `$request->user()` (for example, `$request->user()->accounts()->create(...)`).
3. **Scoped Queries and Route Model Binding:**
    - All read, update, and delete queries must be scoped to the authenticated user (or authorized via Laravel Policies) so that sequential or guessed resource IDs cannot be accessed across user boundaries.
4. **Single-User Foundation First:**
    - Equity starts with direct user ownership (`user_id`) rather than speculative multi-tenant workspace hierarchies until multi-user sharing is explicitly required.

---

## 5. Planned Financial Domain Boundaries

As Equity evolves beyond Phase 3, domain logic will be organized into clear bounded contexts so that accounting rules remain isolated from presentation concerns:

1. **Identity and Ownership Context (Implemented Foundation)**
    - User authentication, session management, and user-scoped data ownership boundaries.
2. **Accounts and Commodities (Future)**
    - Asset, liability, equity, income, and expense accounts, along with explicit currency definitions.
3. **Ledger and Journal Engine (Future)**
    - Immutable journal entries, balanced debit/credit postings, and account balance derivation.
4. **Transactions and Categorization (Future)**
    - User-facing cash-flow events (inflows, outflows, transfers) that compile down to balanced journal entries.
5. **Budgets and Cash-Flow Planning (Future)**
    - Period allocations, spending envelopes, and forward-looking cash-flow projections derived from ledger state.

---

## 6. Future Ledger Architecture

When ledger capabilities are introduced in future milestones, Equity will separate user-facing transaction workflows from the underlying accounting ledger:

- **Chart of Accounts:** Every financial container (checking account, savings account, credit card, spending category, opening balance) maps to a typed ledger account (`Asset`, `Liability`, `Equity`, `Income`, `Expense`).
- **Journal Entries (`journal_entries`):** Represent a discrete, timestamped financial event with metadata (description, effective date, posted timestamp, actor/source reference, and correlation identifiers).
- **Journal Postings (`journal_postings`):** Each journal entry consists of two or more line items targeting specific accounts. The sum of all postings within a single currency for a posted journal entry must always equal zero (or total debits must equal total credits).
- **Corrections via Reversals:** Posted ledger entries represent historical facts. Corrections or voided transactions must be recorded via explicit reversing or adjusting journal entries rather than destructive in-place updates.

---

## 7. Money-Handling Principles

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
