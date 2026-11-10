/**
 * Phase 6.11 verification (Playwright).
 * Measures Reports overflow and Bills Pay discoverability at 390×844 and 1440×900.
 * Writes staging diagnostics only — does not promote or overwrite final screenshots.
 *
 * Prerequisites:
 *   php artisan serve --env=visual-audit --host=127.0.0.1 --port=8000
 *   Built frontend assets available to that server
 *
 * Usage:
 *   node scripts/visual-audit-phase-6-11-verify.mjs
 */
import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const stagingDir = path.join(
    root,
    'docs',
    'visual-audit',
    '_diagnostics',
    'staging-phase-6-11',
);

const BASE_URL = process.env.EQUITY_VISUAL_AUDIT_URL ?? 'http://127.0.0.1:8000';
const DEMO_EMAIL = 'demo@equity.local';
const DEMO_PASSWORD = 'VisualAudit!Demo99';

const ONLY_390 = process.argv.includes('--390');
const VIEWPORTS = [
    { id: '390', width: 390, height: 844 },
    { id: '1440', width: 1440, height: 900 },
].filter((viewport) => !ONLY_390 || viewport.id === '390');

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

async function measureOverflow(page) {
    return page.evaluate(() => {
        const docEl = document.documentElement;
        const body = document.body;
        const scrollWidth = Math.max(docEl.scrollWidth, body?.scrollWidth ?? 0);
        const clientWidth = window.innerWidth;
        const samples = [];
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_ELEMENT);
        let node = walker.nextNode();
        while (node && samples.length < 12) {
            const el = /** @type {HTMLElement} */ (node);
            if (el.scrollWidth > el.clientWidth + 1) {
                samples.push({
                    tag: el.tagName.toLowerCase(),
                    id: el.id || null,
                    className: typeof el.className === 'string' ? el.className.slice(0, 120) : '',
                    scrollWidth: el.scrollWidth,
                    clientWidth: el.clientWidth,
                });
            }
            node = walker.nextNode();
        }
        return {
            innerWidth: window.innerWidth,
            innerHeight: window.innerHeight,
            devicePixelRatio: window.devicePixelRatio,
            scrollWidth,
            clientWidth,
            horizontalOverflowPx: Math.max(0, scrollWidth - clientWidth),
            overflowingSample: samples,
            theme: document.documentElement.getAttribute('data-theme') ?? 'light',
        };
    });
}

async function billsDiscoverability(page, isMobile) {
    await page.goto(`${BASE_URL}/bills`, { waitUntil: 'networkidle' });
    await page.getByRole('heading', { name: 'Bills & Subscriptions' }).waitFor({
        state: 'visible',
        timeout: 30_000,
    });
    await page.getByRole('heading', { name: 'This month overview' }).waitFor({
        state: 'visible',
        timeout: 30_000,
    });

    const shortcut = page.getByTestId('bills-needs-payment');
    const shortcutVisible = await shortcut.isVisible().catch(() => false);
    let payInViewportWithoutDeepScroll = false;
    let firstPayBox = null;

    let remainingCopyOk = null;
    let payButtonUnobstructed = null;

    if (isMobile) {
        if (!shortcutVisible) {
            throw new Error('Expected mobile Needs payment shortcut to be visible at 390px');
        }
        const remainingLine = shortcut.locator('li').first().locator('p').filter({
            hasText: /Due \d{4}-\d{2}-\d{2} · Remaining /,
        });
        await remainingLine.waitFor({ state: 'visible', timeout: 10_000 });
        const remainingText = ((await remainingLine.textContent()) ?? '').replace(/\s+/g, ' ').trim();
        remainingCopyOk = /Due \d{4}-\d{2}-\d{2} · Remaining \S/.test(remainingText);
        if (!remainingCopyOk) {
            throw new Error(`Unexpected Needs payment remaining copy: ${remainingText}`);
        }

        const pay = shortcut.getByRole('button', { name: 'Pay' }).first();
        await pay.waitFor({ state: 'visible', timeout: 10_000 });
        firstPayBox = await pay.boundingBox();
        const amountBox = await remainingLine.boundingBox();
        payInViewportWithoutDeepScroll = Boolean(
            firstPayBox && firstPayBox.y >= 0 && firstPayBox.y < 844,
        );
        // Amount text stays in its flex-1 column; Pay keeps shrink-0 to the right.
        payButtonUnobstructed = Boolean(
            firstPayBox &&
                amountBox &&
                amountBox.x + amountBox.width <= firstPayBox.x + 2,
        );
        await pay.click();
        const payDialog = page.getByRole('dialog');
        await payDialog.getByRole('heading', { name: 'Pay bill' }).waitFor({
            state: 'visible',
            timeout: 10_000,
        });
        await payDialog.getByRole('button', { name: 'Cancel' }).click();
        await payDialog.waitFor({ state: 'hidden', timeout: 10_000 }).catch(() => {});
    } else {
        if (shortcutVisible) {
            throw new Error('Needs payment shortcut should stay hidden at desktop lg+ widths');
        }
        const pay = page.getByRole('button', { name: 'Pay' }).first();
        // Desktop may need scroll to occurrence list; discoverability change is mobile-focused.
        await pay.scrollIntoViewIfNeeded();
        firstPayBox = await pay.boundingBox();
        payInViewportWithoutDeepScroll = Boolean(firstPayBox);
    }

    return {
        shortcutVisible,
        payInViewportWithoutDeepScroll,
        remainingCopyOk,
        payButtonUnobstructed,
        firstPayY: firstPayBox?.y ?? null,
    };
}

async function main() {
    await mkdir(stagingDir, { recursive: true });
    const browser = await chromium.launch({ headless: true });
    /** @type {Record<string, unknown>} */
    const report = {
        baseUrl: BASE_URL,
        capturedAt: new Date().toISOString(),
        viewports: {},
        ok: true,
        errors: [],
    };

    try {
        for (const viewport of VIEWPORTS) {
            const context = await browser.newContext({
                viewport: { width: viewport.width, height: viewport.height },
                deviceScaleFactor: 1,
                colorScheme: 'light',
            });
            const page = await context.newPage();
            const consoleErrors = [];
            page.on('console', (msg) => {
                if (msg.type() === 'error') {
                    consoleErrors.push(msg.text());
                }
            });

            await login(page);

            await page.goto(`${BASE_URL}/reports`, { waitUntil: 'networkidle' });
            await page.getByRole('heading', { name: 'Financial Reports' }).waitFor({
                state: 'visible',
                timeout: 30_000,
            });
            // Allow charts / currency sections to paint before measuring overflow.
            await page.waitForTimeout(500);
            const reportsOverflow = await measureOverflow(page);
            const reportsShot = path.join(
                stagingDir,
                `${viewport.id}-reports-after-fix.png`,
            );
            await page.screenshot({ path: reportsShot, fullPage: false });

            const incomeVisible = await page
                .getByText(/Income/i)
                .first()
                .isVisible();
            const amountSample = await page.locator('[data-amount-minor]').first().textContent();

            const isMobile = viewport.width < 1024;
            const bills = await billsDiscoverability(page, isMobile);
            const billsShot = path.join(stagingDir, `${viewport.id}-bills-after-fix.png`);
            await page.goto(`${BASE_URL}/bills`, { waitUntil: 'networkidle' });
            await page.getByRole('heading', { name: 'Bills & Subscriptions' }).waitFor({
                state: 'visible',
                timeout: 30_000,
            });
            if (isMobile) {
                await page.getByTestId('bills-needs-payment').waitFor({ state: 'visible' });
            }
            await page.screenshot({ path: billsShot, fullPage: false });

            // Mobile nav smoke: open Menu and confirm Reports link still works.
            let mobileNavOk = null;
            if (isMobile) {
                await page.getByRole('button', { name: 'Toggle navigation menu' }).click();
                const mobileNav = page.getByRole('navigation', { name: 'Mobile navigation' });
                await mobileNav.waitFor({ state: 'visible', timeout: 10_000 });
                await mobileNav.getByRole('link', { name: 'Financial Reports' }).click();
                await page.getByRole('heading', { name: 'Financial Reports' }).waitFor({
                    state: 'visible',
                    timeout: 30_000,
                });
                mobileNavOk = true;
            }

            const viewportOk =
                reportsOverflow.horizontalOverflowPx === 0 &&
                (!isMobile ||
                    (bills.payInViewportWithoutDeepScroll &&
                        bills.remainingCopyOk &&
                        bills.payButtonUnobstructed));

            if (!viewportOk) {
                report.ok = false;
                report.errors.push(
                    `${viewport.id}: overflow=${reportsOverflow.horizontalOverflowPx}px billsPayVisible=${bills.payInViewportWithoutDeepScroll} remainingCopyOk=${bills.remainingCopyOk} payUnobstructed=${bills.payButtonUnobstructed}`,
                );
            }

            report.viewports[viewport.id] = {
                reports: {
                    ...reportsOverflow,
                    incomeLabelVisible: incomeVisible,
                    firstAmountText: amountSample,
                    screenshot: reportsShot,
                    consoleErrors,
                },
                bills: {
                    ...bills,
                    screenshot: billsShot,
                },
                mobileNavOk,
                ok: viewportOk,
            };

            await context.close();
        }
    } finally {
        await browser.close();
    }

    const reportPath = path.join(stagingDir, 'validation-report.json');
    await writeFile(reportPath, JSON.stringify(report, null, 2), 'utf8');
    console.log(JSON.stringify(report, null, 2));
    console.log(`Wrote ${reportPath}`);
    if (!report.ok) {
        process.exit(1);
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
