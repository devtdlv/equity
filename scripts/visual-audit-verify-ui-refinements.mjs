/**
 * One-shot verification of Phase 6.7 UI refinements against visual-audit.
 * Does not capture screenshots or mutate data.
 */
import { chromium } from 'playwright';

const BASE_URL = process.env.EQUITY_VISUAL_AUDIT_URL ?? 'http://127.0.0.1:8000';
const DEMO_EMAIL = 'demo@equity.local';
const DEMO_PASSWORD = 'VisualAudit!Demo99';

function pass(msg) {
    console.log(`PASS: ${msg}`);
}
function fail(msg) {
    console.error(`FAIL: ${msg}`);
    process.exitCode = 1;
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
}

async function main() {
    const browser = await chromium.launch({
        headless: true,
        args: ['--force-device-scale-factor=1'],
    });
    const page = await browser.newPage({
        viewport: { width: 1440, height: 900 },
        deviceScaleFactor: 1,
        colorScheme: 'light',
    });

    try {
        await login(page);

        // Dashboard: Category over limit for seeded PHP category overspend.
        await page.goto(`${BASE_URL}/dashboard`, { waitUntil: 'networkidle' });
        const categoryBadge = page.getByText('Category over limit', { exact: true });
        const budgetBadge = page.getByText('Budget over limit', { exact: true });
        if ((await categoryBadge.count()) < 1) {
            fail('Dashboard missing "Category over limit"');
        } else {
            pass('Dashboard shows Category over limit');
        }
        if ((await budgetBadge.count()) > 0) {
            fail('Dashboard unexpectedly shows Budget over limit');
        } else {
            pass('Dashboard does not show Budget over limit');
        }

        // Accounts: create currency defaults from active accounts (PHP before USD).
        await page.goto(`${BASE_URL}/accounts`, { waitUntil: 'networkidle' });
        const accountCurrency = page.locator('#create-currency_code');
        await accountCurrency.waitFor({ state: 'visible', timeout: 30_000 });
        const accountDefault = await accountCurrency.inputValue();
        if (accountDefault !== 'PHP') {
            fail(`Accounts create currency default=${accountDefault}, expected PHP`);
        } else {
            pass('Accounts create currency defaults to PHP');
        }

        // Budgets: defaults from active accounts (PHP) without manual select.
        await page.goto(`${BASE_URL}/budgets`, { waitUntil: 'networkidle' });
        const budgetCurrency = page.locator('#budget-currency');
        await budgetCurrency.waitFor({ state: 'visible', timeout: 30_000 });
        await page.waitForFunction(() => {
            const params = new URL(window.location.href).searchParams;
            return Boolean(params.get('currency_code'));
        });
        const budgetDefault = await budgetCurrency.inputValue();
        const urlCode = new URL(page.url()).searchParams.get('currency_code');
        if (budgetDefault !== 'PHP' || urlCode !== 'PHP') {
            fail(`Budgets default select=${budgetDefault} url=${urlCode}, expected PHP`);
        } else {
            pass('Budgets currency defaults to PHP and syncs URL');
        }

        // Transactions: create form collapsed; history/filters visible full width.
        await page.goto(`${BASE_URL}/transactions`, { waitUntil: 'networkidle' });
        const createHeading = page.getByRole('heading', { name: 'Record a transaction' });
        const historyHeading = page.getByRole('heading', { name: 'Filter History' });
        await historyHeading.waitFor({ state: 'visible', timeout: 30_000 });
        if ((await createHeading.count()) > 0) {
            fail('Transactions create form is expanded by default');
        } else {
            pass('Transactions create form is collapsed by default');
        }
        const addBtn = page.getByRole('button', { name: 'Add transaction' });
        if ((await addBtn.getAttribute('aria-expanded')) !== 'false') {
            fail('Add transaction aria-expanded is not false while collapsed');
        } else {
            pass('Add transaction reports aria-expanded=false');
        }
    } catch (error) {
        fail(error instanceof Error ? error.message : String(error));
    } finally {
        await browser.close();
    }

    if (!process.exitCode) {
        console.log('UI refinement verification passed.');
    }
}

main();
