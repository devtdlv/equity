/**
 * Focused Playwright verification: Budgets ?currency_code= survives Inertia
 * history (Back/Forward) and refresh. Targets the disposable visual-audit
 * server only. Does not load .env, migrate, seed, or capture screenshots.
 *
 * Prerequisites:
 *   php artisan serve --env=visual-audit --host=127.0.0.1 --port=8000
 *   (optional) npm run dev / built assets already present
 *
 * Usage:
 *   node scripts/visual-audit-budgets-currency-nav.mjs
 */
import { chromium } from 'playwright';

const BASE_URL = process.env.EQUITY_VISUAL_AUDIT_URL ?? 'http://127.0.0.1:8000';
const DEMO_EMAIL = 'demo@equity.local';
const DEMO_PASSWORD = 'VisualAudit!Demo99';

function fail(message) {
    console.error(`FAIL: ${message}`);
    process.exitCode = 1;
}

function pass(message) {
    console.log(`PASS: ${message}`);
}

async function login(page) {
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle' });

    const lightButton = page.getByRole('button', { name: 'Light', exact: true });
    if (await lightButton.count()) {
        await lightButton.click();
    }

    await page.getByLabel('Email address').fill(DEMO_EMAIL);
    await page.getByLabel('Password', { exact: true }).fill(DEMO_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL(/\/dashboard(?:\?|$)/, { timeout: 30_000 });
    await page.waitForLoadState('networkidle');
}

async function waitForBudgetCurrency(page) {
    const currency = page.locator('#budget-currency');
    await currency.waitFor({ state: 'visible', timeout: 30_000 });
    return currency;
}

async function main() {
    console.log(`Budgets currency navigation check against ${BASE_URL}`);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 },
        colorScheme: 'light',
    });
    const page = await context.newPage();

    try {
        const health = await page.request.get(`${BASE_URL}/login`);
        if (!health.ok()) {
            fail(`visual-audit server not reachable at ${BASE_URL} (HTTP ${health.status()})`);
            return;
        }

        await login(page);

        // 1) Initial navigation without currency query → default written into URL.
        await page.goto(`${BASE_URL}/budgets`, { waitUntil: 'networkidle' });
        const currency = await waitForBudgetCurrency(page);
        await page.waitForFunction(() => {
            const params = new URL(window.location.href).searchParams;
            return Boolean(params.get('currency_code'));
        }, null, { timeout: 15_000 });
        const defaultCode = await currency.inputValue();
        const urlAfterDefault = new URL(page.url());
        if (urlAfterDefault.searchParams.get('currency_code') !== defaultCode) {
            fail(
                `initial URL currency ${urlAfterDefault.searchParams.get('currency_code')} !== select ${defaultCode}`,
            );
        } else {
            pass(`initial /budgets wrote currency_code=${defaultCode}`);
        }

        // Pick a non-default currency for history verification.
        const options = await currency.locator('option').evaluateAll((nodes) =>
            nodes.map((node) => node.value).filter(Boolean),
        );
        const alternate = options.find((code) => code !== defaultCode) ?? null;
        if (!alternate) {
            fail('need at least two catalog currencies for navigation verification');
            return;
        }

        // 2) Change currency → URL + axios-backed UI update.
        await currency.selectOption(alternate);
        await page.waitForFunction(
            (code) => new URL(window.location.href).searchParams.get('currency_code') === code,
            alternate,
            { timeout: 15_000 },
        );
        if ((await currency.inputValue()) !== alternate) {
            fail(`select did not stay on ${alternate}`);
        } else {
            pass(`changed currency to ${alternate} and synced URL`);
        }

        // Confirm budget requests follow the selected currency (network).
        const listResponsePromise = page.waitForResponse(
            (response) =>
                response.url().includes('/budgets') &&
                response.request().method() === 'GET' &&
                response.url().includes(`currency_code=${alternate}`),
            { timeout: 15_000 },
        );
        // Re-select to force a reload signal if the first change already completed.
        await currency.selectOption(defaultCode);
        await currency.selectOption(alternate);
        await listResponsePromise;
        pass(`budget list request used currency_code=${alternate}`);

        // 3) Navigate away, then browser Back restores currency.
        await page
            .getByRole('navigation', { name: 'Application navigation' })
            .getByRole('link', { name: 'Accounts' })
            .click();
        await page.waitForURL(/\/accounts/, { timeout: 30_000 });
        await page.goBack();
        await page.waitForURL(/\/budgets/, { timeout: 30_000 });
        await waitForBudgetCurrency(page);
        await page.waitForFunction(
            (code) => new URL(window.location.href).searchParams.get('currency_code') === code,
            alternate,
            { timeout: 15_000 },
        );
        const afterBack = await page.locator('#budget-currency').inputValue();
        if (afterBack !== alternate) {
            fail(`Back restored select=${afterBack}, expected ${alternate}`);
        } else {
            pass(`browser Back restored currency_code=${alternate}`);
        }

        // 4) Forward then Back again.
        await page.goForward();
        await page.waitForURL(/\/accounts/, { timeout: 30_000 });
        await page.goBack();
        await page.waitForURL(/\/budgets/, { timeout: 30_000 });
        await waitForBudgetCurrency(page);
        await page.waitForFunction(
            (code) => new URL(window.location.href).searchParams.get('currency_code') === code,
            alternate,
            { timeout: 15_000 },
        );
        const afterForwardBack = await page.locator('#budget-currency').inputValue();
        if (afterForwardBack !== alternate) {
            fail(`Forward/Back restored select=${afterForwardBack}, expected ${alternate}`);
        } else {
            pass(`browser Forward then Back restored currency_code=${alternate}`);
        }

        // 5) Refresh keeps selected currency.
        await page.reload({ waitUntil: 'networkidle' });
        await waitForBudgetCurrency(page);
        await page.waitForFunction(
            (code) => new URL(window.location.href).searchParams.get('currency_code') === code,
            alternate,
            { timeout: 15_000 },
        );
        const afterReload = await page.locator('#budget-currency').inputValue();
        if (afterReload !== alternate) {
            fail(`refresh restored select=${afterReload}, expected ${alternate}`);
        } else {
            pass(`refresh kept currency_code=${alternate}`);
        }

        // 6) Explicit valid query on cold navigation.
        await page.goto(`${BASE_URL}/budgets?currency_code=${defaultCode}`, {
            waitUntil: 'networkidle',
        });
        await waitForBudgetCurrency(page);
        if ((await page.locator('#budget-currency').inputValue()) !== defaultCode) {
            fail(`explicit query did not select ${defaultCode}`);
        } else {
            pass(`explicit ?currency_code=${defaultCode} honored`);
        }

        // 7) Invalid query falls back (still lands on an active catalog currency).
        await page.goto(`${BASE_URL}/budgets?currency_code=NOTREAL`, {
            waitUntil: 'networkidle',
        });
        await waitForBudgetCurrency(page);
        await page.waitForFunction(() => {
            const code = new URL(window.location.href).searchParams.get('currency_code');
            return Boolean(code) && code !== 'NOTREAL';
        }, null, { timeout: 15_000 });
        const fallback = await page.locator('#budget-currency').inputValue();
        const fallbackUrl = new URL(page.url()).searchParams.get('currency_code');
        if (fallback !== fallbackUrl || fallback === 'NOTREAL') {
            fail(`invalid query fallback failed (select=${fallback}, url=${fallbackUrl})`);
        } else {
            pass(`invalid currency query fell back to ${fallback}`);
        }
    } catch (error) {
        fail(error instanceof Error ? error.message : String(error));
    } finally {
        await browser.close();
    }

    if (process.exitCode && process.exitCode !== 0) {
        console.error('Budgets currency navigation verification failed.');
        return;
    }

    console.log('Budgets currency navigation verification passed.');
}

main();
