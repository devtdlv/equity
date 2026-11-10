/**
 * Visual-audit mobile screenshot batch (Playwright).
 * Captures authenticated 390×844 light screenshots against the running
 * `php artisan serve --env=visual-audit` instance. Does not load `.env`,
 * migrate, seed, or touch the real database.
 *
 * Usage:
 *   node scripts/visual-audit-playwright-mobile.mjs
 *   node scripts/visual-audit-playwright-mobile.mjs --promote
 *
 * Without --promote: writes only to docs/visual-audit/_diagnostics/staging-390-light/
 * With --promote: after validation, copies Batch files into docs/visual-audit/390/light/
 */
import { chromium } from 'playwright';
import { copyFile, mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const stagingDir = path.join(root, 'docs', 'visual-audit', '_diagnostics', 'staging-390-light');
const finalDir = path.join(root, 'docs', 'visual-audit', '390', 'light');

const BASE_URL = process.env.EQUITY_VISUAL_AUDIT_URL ?? 'http://127.0.0.1:8000';
const DEMO_EMAIL = 'demo@equity.local';
const DEMO_PASSWORD = 'VisualAudit!Demo99';
const VIEWPORT = { width: 390, height: 844 };
const PROMOTE = process.argv.includes('--promote');

/** @typedef {{
 *   id: string,
 *   file: string,
 *   route: string,
 *   heading: string,
 *   promote?: boolean,
 *   scrollTop?: boolean,
 *   prepare?: (page: import('playwright').Page) => Promise<void>,
 *   expectText?: string[],
 * }} Shot */

/** @type {Shot[]} */
const SHOTS = [
    {
        id: '10-dashboard',
        file: '10-dashboard-populated.png',
        route: '/dashboard',
        heading: 'Dashboard',
        promote: true,
        scrollTop: true,
        expectText: ['Financial overview'],
    },
    {
        id: '10b-dashboard-nav',
        file: '10-dashboard-mobile-nav-open.png',
        route: '/dashboard',
        heading: 'Dashboard',
        promote: true,
        scrollTop: true,
        prepare: async (page) => {
            await page.getByRole('button', { name: 'Toggle navigation menu' }).click();
            const mobileNav = page.getByRole('navigation', { name: 'Mobile navigation' });
            await mobileNav.waitFor({ state: 'visible', timeout: 10_000 });
            await mobileNav.getByRole('link', { name: 'Accounts' }).waitFor({
                state: 'visible',
                timeout: 10_000,
            });
        },
        expectText: [],
    },
    {
        id: '11-accounts',
        file: '11-accounts-populated.png',
        route: '/accounts',
        heading: 'Accounts',
        promote: true,
        scrollTop: true,
        expectText: ['Demo PHP Checking'],
    },
    {
        id: '13-transactions',
        file: '13-transactions-populated.png',
        route: '/transactions',
        heading: 'Transactions',
        promote: true,
        scrollTop: true,
        expectText: ['Filter History'],
    },
    {
        id: '13b-transactions-create',
        file: '13b-transactions-create-expanded.png',
        route: '/transactions',
        heading: 'Transactions',
        promote: false,
        scrollTop: true,
        prepare: async (page) => {
            await page.getByRole('button', { name: 'Add transaction' }).click();
            await page.getByRole('heading', { name: 'Record a transaction' }).waitFor({
                state: 'visible',
                timeout: 10_000,
            });
        },
        expectText: ['Record a transaction'],
    },
    {
        id: '15-budgets',
        file: '15-budgets-populated.png',
        route: '/budgets',
        heading: 'Budgets',
        promote: true,
        scrollTop: true,
        prepare: async (page) => {
            const currency = page.locator('#budget-currency');
            await currency.waitFor({ state: 'visible', timeout: 30_000 });
            await page.waitForFunction(() => {
                const code = new URL(window.location.href).searchParams.get('currency_code');
                return Boolean(code);
            });
            // Prefer seeded PHP overview when available; keep default if already PHP.
            const current = await currency.inputValue();
            if (current !== 'PHP') {
                await currency.selectOption('PHP');
            }
            await page.getByText('₱37,500.00').first().waitFor({
                state: 'visible',
                timeout: 30_000,
            });
            await page.waitForTimeout(400);
        },
        expectText: ['Overview', '₱37,500.00'],
    },
    {
        id: '16-savings',
        file: '16-savings-populated.png',
        route: '/savings',
        heading: 'Savings Goals',
        promote: true,
        scrollTop: true,
        expectText: ['Demo Emergency Fund'],
    },
    {
        id: '17-bills',
        file: '17-bills-populated.png',
        route: '/bills',
        heading: 'Bills & Subscriptions',
        promote: true,
        scrollTop: true,
        expectText: ['This month overview'],
    },
    {
        id: '17b-bills-occurrences',
        file: '17b-bills-occurrences.png',
        route: '/bills',
        heading: 'Bills & Subscriptions',
        promote: false,
        scrollTop: false,
        prepare: async (page) => {
            const occurrences = page.getByRole('heading', {
                level: 2,
                name: 'Occurrences',
            });
            await occurrences.waitFor({ state: 'visible', timeout: 30_000 });
            await occurrences.scrollIntoViewIfNeeded();
            await page.waitForTimeout(300);
        },
        expectText: ['Occurrences'],
    },
    {
        id: '19-reports',
        file: '19-reports-month.png',
        route: '/reports',
        heading: 'Financial Reports',
        promote: true,
        scrollTop: true,
        expectText: ['Period summary'],
    },
    {
        id: '21-settings',
        file: '21-settings.png',
        route: '/settings',
        heading: 'Settings',
        promote: true,
        scrollTop: true,
        expectText: ['Profile'],
    },
];

function readPngSize(buffer) {
    if (buffer.length < 24 || buffer.toString('ascii', 1, 4) !== 'PNG') {
        throw new Error('Not a PNG');
    }
    return {
        width: buffer.readUInt32BE(16),
        height: buffer.readUInt32BE(20),
    };
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

async function measureOverflow(page) {
    return page.evaluate(() => {
        const doc = document.documentElement;
        const body = document.body;
        const scrollWidth = Math.max(doc.scrollWidth, body?.scrollWidth ?? 0);
        const clientWidth = doc.clientWidth;
        const overflowing = [];
        for (const el of document.querySelectorAll('body *')) {
            if (!(el instanceof HTMLElement)) {
                continue;
            }
            if (el.scrollWidth > el.clientWidth + 1 && el.clientWidth > 0) {
                const style = window.getComputedStyle(el);
                if (style.overflowX === 'hidden' || style.visibility === 'hidden') {
                    continue;
                }
                if (el.scrollWidth > clientWidth + 2) {
                    overflowing.push({
                        tag: el.tagName.toLowerCase(),
                        id: el.id || null,
                        className: (el.className || '').toString().slice(0, 120),
                        scrollWidth: el.scrollWidth,
                        clientWidth: el.clientWidth,
                    });
                }
            }
        }
        return {
            scrollWidth,
            clientWidth,
            horizontalOverflowPx: Math.max(0, scrollWidth - clientWidth),
            overflowingSample: overflowing.slice(0, 8),
        };
    });
}

async function captureShot(page, shot, consoleErrors) {
    // Close mobile menu if left open from a prior shot.
    const menuOpen = await page.getByRole('navigation', { name: 'Mobile navigation' }).count();
    if (menuOpen > 0 && !shot.id.includes('nav')) {
        const toggle = page.getByRole('button', { name: 'Toggle navigation menu' });
        if ((await toggle.getAttribute('aria-expanded')) === 'true') {
            await toggle.click();
        }
    }

    if (!page.url().includes(shot.route) || shot.id === '10-dashboard') {
        await page.goto(`${BASE_URL}${shot.route}`, { waitUntil: 'networkidle' });
    } else if (shot.id.startsWith('17b') || shot.id.startsWith('13b')) {
        // Stay on current page for supplemental state.
        if (!page.url().includes(shot.route)) {
            await page.goto(`${BASE_URL}${shot.route}`, { waitUntil: 'networkidle' });
        }
    } else {
        await page.goto(`${BASE_URL}${shot.route}`, { waitUntil: 'networkidle' });
    }

    await page.getByRole('heading', { level: 1, name: shot.heading }).waitFor({
        state: 'visible',
        timeout: 30_000,
    });

    if (shot.prepare) {
        await shot.prepare(page);
    }

    if (shot.scrollTop !== false) {
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.waitForTimeout(200);
    }

    for (const text of shot.expectText ?? []) {
        await page.getByText(text, { exact: false }).locator('visible=true').first().waitFor({
            state: 'visible',
            timeout: 30_000,
        });
    }

    const overflow = await measureOverflow(page);

    const metrics = await page.evaluate((expectedHeading) => {
        const desktopSidebar = document.querySelectorAll('[aria-label="Workspace sidebar"]');
        const mobileHeader = document.querySelectorAll('header');
        const mobileNav = document.querySelectorAll('[aria-label="Mobile navigation"]');
        const h1s = [...document.querySelectorAll('h1')].filter(
            (h) => h.textContent?.trim() === expectedHeading,
        );
        const toggle = document.querySelector('[aria-label="Toggle navigation menu"]');
        return {
            innerWidth: window.innerWidth,
            innerHeight: window.innerHeight,
            devicePixelRatio: window.devicePixelRatio,
            desktopSidebarVisible: [...desktopSidebar].some((el) => {
                const style = window.getComputedStyle(el);
                return style.display !== 'none' && style.visibility !== 'hidden';
            }),
            mobileToggleVisible: toggle
                ? window.getComputedStyle(toggle).display !== 'none'
                : false,
            mobileNavOpen: mobileNav.length > 0,
            headerCount: mobileHeader.length,
            headingCount: h1s.length,
            theme: document.documentElement.getAttribute('data-theme'),
            url: location.href,
            scrollY: window.scrollY,
        };
    }, shot.heading);

    const errors = [];
    if (metrics.innerWidth !== VIEWPORT.width || metrics.innerHeight !== VIEWPORT.height) {
        errors.push(
            `viewport ${metrics.innerWidth}x${metrics.innerHeight} (expected ${VIEWPORT.width}x${VIEWPORT.height})`,
        );
    }
    if (metrics.devicePixelRatio !== 1) {
        errors.push(`devicePixelRatio=${metrics.devicePixelRatio}`);
    }
    if (metrics.desktopSidebarVisible) {
        errors.push('desktop sidebar still visible at mobile width');
    }
    if (!metrics.mobileToggleVisible) {
        errors.push('mobile nav toggle not visible');
    }
    if (metrics.headingCount !== 1) {
        errors.push(`headingCount=${metrics.headingCount}`);
    }
    if (metrics.theme !== 'light') {
        errors.push(`theme=${metrics.theme}`);
    }
    if (shot.id.includes('nav') && !metrics.mobileNavOpen) {
        errors.push('expected mobile navigation open');
    }

    const stagingPath = path.join(stagingDir, shot.file);
    await page.screenshot({
        path: stagingPath,
        fullPage: false,
        type: 'png',
    });

    const png = await readFile(stagingPath);
    const size = readPngSize(png);
    if (size.width !== VIEWPORT.width || size.height !== VIEWPORT.height) {
        errors.push(`png ${size.width}x${size.height}`);
    }

    return {
        id: shot.id,
        file: shot.file,
        route: shot.route,
        heading: shot.heading,
        promote: shot.promote !== false,
        stagingPath,
        metrics,
        overflow,
        png: size,
        consoleErrorsAtCapture: [...consoleErrors],
        ok: errors.length === 0,
        errors,
    };
}

async function verifyMobileNavigation(page) {
    const routes = [
        ['/dashboard', 'Dashboard'],
        ['/accounts', 'Accounts'],
        ['/transactions', 'Transactions'],
        ['/budgets', 'Budgets'],
        ['/savings', 'Savings Goals'],
        ['/bills', 'Bills & Subscriptions'],
        ['/reports', 'Financial Reports'],
        ['/settings', 'Settings'],
    ];

    const findings = [];
    await page.goto(`${BASE_URL}/dashboard`, { waitUntil: 'networkidle' });

    for (const [href, heading] of routes) {
        const toggle = page.getByRole('button', { name: 'Toggle navigation menu' });
        if ((await toggle.getAttribute('aria-expanded')) !== 'true') {
            await toggle.click();
        }
        await page.getByRole('navigation', { name: 'Mobile navigation' }).waitFor({
            state: 'visible',
            timeout: 10_000,
        });
        const nav = page.getByRole('navigation', { name: 'Mobile navigation' });
        const label =
            href === '/savings'
                ? 'Savings Goals'
                : href === '/bills'
                  ? 'Bills & Subscriptions'
                  : href === '/reports'
                    ? 'Financial Reports'
                    : heading;
        await nav.getByRole('link', { name: label, exact: true }).click();
        await page.waitForURL(new RegExp(`${href.replace('/', '\\/')}(?:\\?|$)`), {
            timeout: 30_000,
        });
        await page.getByRole('heading', { level: 1, name: heading }).waitFor({
            state: 'visible',
            timeout: 30_000,
        });
        findings.push({ href, heading, ok: true });
    }

    return findings;
}

async function inspectRefinements(page) {
    const notes = {};

    await page.goto(`${BASE_URL}/accounts`, { waitUntil: 'networkidle' });
    notes.accountsCurrencyDefault = await page.locator('#create-currency_code').inputValue();

    await page.goto(`${BASE_URL}/budgets`, { waitUntil: 'networkidle' });
    await page.locator('#budget-currency').waitFor({ state: 'visible', timeout: 30_000 });
    await page.waitForFunction(() =>
        Boolean(new URL(window.location.href).searchParams.get('currency_code')),
    );
    notes.budgetsCurrencyDefault = await page.locator('#budget-currency').inputValue();
    notes.budgetsUrlCurrency = new URL(page.url()).searchParams.get('currency_code');
    await page.locator('#budget-currency').selectOption('USD');
    await page.waitForFunction(
        () => new URL(window.location.href).searchParams.get('currency_code') === 'USD',
    );
    notes.budgetsSwitchToUsd = await page.locator('#budget-currency').inputValue();
    await page.locator('#budget-currency').selectOption('PHP');
    await page.waitForFunction(
        () => new URL(window.location.href).searchParams.get('currency_code') === 'PHP',
    );

    await page.goto(`${BASE_URL}/transactions`, { waitUntil: 'networkidle' });
    notes.transactionsCreateCollapsed =
        (await page.getByRole('heading', { name: 'Record a transaction' }).count()) === 0;
    await page.getByRole('button', { name: 'Add transaction' }).click();
    notes.transactionsCreateExpanded =
        (await page.getByRole('heading', { name: 'Record a transaction' }).count()) > 0;

    await page.goto(`${BASE_URL}/dashboard`, { waitUntil: 'networkidle' });
    notes.dashboardCategoryOverLimit =
        (await page.getByText('Category over limit', { exact: true }).count()) > 0;

    return notes;
}

async function main() {
    await mkdir(stagingDir, { recursive: true });
    await mkdir(finalDir, { recursive: true });

    const browser = await chromium.launch({
        headless: true,
        args: ['--force-device-scale-factor=1'],
    });

    // Keep an exact CSS viewport; avoid Playwright isMobile emulation which can
    // diverge from the configured viewport via meta-viewport handling.
    const context = await browser.newContext({
        viewport: VIEWPORT,
        deviceScaleFactor: 1,
        colorScheme: 'light',
        reducedMotion: 'reduce',
        hasTouch: true,
    });

    const page = await context.newPage();
    await page.addInitScript(() => {
        Object.defineProperty(window, 'devicePixelRatio', {
            get: () => 1,
        });
    });

    /** @type {string[]} */
    const consoleErrors = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') {
            consoleErrors.push(msg.text());
        }
    });
    page.on('pageerror', (error) => {
        consoleErrors.push(error.message);
    });

    await login(page);

    /** @type {Awaited<ReturnType<typeof captureShot>>[]} */
    const results = [];
    for (const shot of SHOTS) {
        process.stdout.write(`capturing ${shot.id}... `);
        const errorsBefore = consoleErrors.length;
        try {
            const result = await captureShot(page, shot, consoleErrors.slice(errorsBefore));
            results.push(result);
            console.log(
                result.ok
                    ? `OK overflow=${result.overflow.horizontalOverflowPx}px`
                    : `FAIL ${result.errors.join('; ')}`,
            );
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            results.push({
                id: shot.id,
                file: shot.file,
                route: shot.route,
                heading: shot.heading,
                promote: shot.promote !== false,
                stagingPath: path.join(stagingDir, shot.file),
                metrics: null,
                overflow: null,
                png: null,
                consoleErrorsAtCapture: [],
                ok: false,
                errors: [message],
            });
            console.log(`FAIL ${message}`);
        }
    }

    process.stdout.write('checking mobile navigation... ');
    let navigation = [];
    try {
        navigation = await verifyMobileNavigation(page);
        console.log(`OK (${navigation.length} routes)`);
    } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        console.log(`FAIL ${message}`);
        navigation = [{ ok: false, error: message }];
    }

    process.stdout.write('checking UI refinements at mobile... ');
    let refinements = {};
    try {
        refinements = await inspectRefinements(page);
        console.log('OK');
    } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        console.log(`FAIL ${message}`);
        refinements = { error: message };
    }

    await browser.close();

    const report = {
        promote: PROMOTE,
        viewport: VIEWPORT,
        consoleErrors,
        navigation,
        refinements,
        results,
    };
    const reportPath = path.join(stagingDir, 'validation-report.json');
    await writeFile(reportPath, JSON.stringify(report, null, 2));

    const failed = results.filter((r) => !r.ok);
    const passed = results.filter((r) => r.ok);

    if (PROMOTE) {
        for (const result of passed) {
            if (!result.promote) {
                continue;
            }
            await copyFile(result.stagingPath, path.join(finalDir, result.file));
            console.log(`promoted ${result.file}`);
        }
    }

    console.log(`passed=${passed.length} failed=${failed.length} report=${reportPath}`);
    if (failed.length > 0) {
        process.exitCode = 1;
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
