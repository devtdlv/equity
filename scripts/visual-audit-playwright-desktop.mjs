/**
 * Visual-audit desktop screenshot batch (Playwright).
 * Captures authenticated 1440×900 light screenshots against the running
 * `php artisan serve --env=visual-audit` instance. Does not load `.env`,
 * migrate, seed, or touch the real database.
 *
 * Usage:
 *   node scripts/visual-audit-playwright-desktop.mjs
 *   node scripts/visual-audit-playwright-desktop.mjs --promote
 *
 * Without --promote: writes only to docs/visual-audit/_diagnostics/staging-1440-light/
 * With --promote: after validation, copies into docs/visual-audit/1440/light/
 */
import { chromium } from 'playwright';
import { copyFile, mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const stagingDir = path.join(root, 'docs', 'visual-audit', '_diagnostics', 'staging-1440-light');
const finalDir = path.join(root, 'docs', 'visual-audit', '1440', 'light');

const BASE_URL = process.env.EQUITY_VISUAL_AUDIT_URL ?? 'http://127.0.0.1:8000';
const DEMO_EMAIL = 'demo@equity.local';
const DEMO_PASSWORD = 'VisualAudit!Demo99';
const VIEWPORT = { width: 1440, height: 900 };
const PROMOTE = process.argv.includes('--promote');

/** @typedef {{
 *   id: string,
 *   file: string,
 *   route: string,
 *   heading: string,
 *   scrollTop?: boolean,
 *   prepare?: (page: import('playwright').Page) => Promise<void>,
 *   expectText?: string[],
 * }} Shot */

/** @type {Shot[]} */
const SHOTS = [
    {
        id: '01-dashboard',
        file: '01-dashboard-populated.png',
        route: '/dashboard',
        heading: 'Dashboard',
        scrollTop: true,
        expectText: ['Financial overview', '₱259,401.00', '$5,880.00'],
    },
    {
        id: '02-accounts',
        file: '02-accounts-populated.png',
        route: '/accounts',
        heading: 'Accounts',
        scrollTop: true,
        expectText: ['Demo PHP Checking', '₱254,551.00', 'Demo USD Checking'],
    },
    {
        id: '03-transactions',
        file: '03-transactions-populated.png',
        route: '/transactions',
        heading: 'Transactions',
        scrollTop: true,
        expectText: ['Transaction History', 'Demo current paycheck'],
    },
    {
        id: '04-budgets',
        file: '04-budgets-populated.png',
        route: '/budgets',
        heading: 'Budgets',
        scrollTop: true,
        prepare: async (page) => {
            const currency = page.locator('#budget-currency');
            await currency.waitFor({ state: 'visible', timeout: 30_000 });
            await currency.selectOption('PHP');
            await page.getByText('₱37,500.00').first().waitFor({
                state: 'visible',
                timeout: 30_000,
            });
            // Allow overview cards to settle after async load.
            await page.waitForTimeout(500);
        },
        expectText: ['Overview', '₱37,500.00', 'Demo Dining'],
    },
    {
        id: '05-savings',
        file: '05-savings-populated.png',
        route: '/savings',
        heading: 'Savings Goals',
        scrollTop: true,
        expectText: ['Demo Emergency Fund', '₱50,000.00', 'Demo Travel Fund'],
    },
    {
        id: '06-bills',
        file: '06-bills-populated.png',
        route: '/bills',
        heading: 'Bills & Subscriptions',
        scrollTop: true,
        expectText: ['This month overview', 'Demo Storage Locker'],
    },
    {
        id: '06b-bills-occurrences',
        file: '06b-bills-occurrences.png',
        route: '/bills',
        heading: 'Bills & Subscriptions',
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
        expectText: ['Occurrences', 'Partially paid', 'Overdue'],
    },
    {
        id: '07-reports',
        file: '07-reports-populated.png',
        route: '/reports',
        heading: 'Financial Reports',
        scrollTop: true,
        expectText: ['Period summary', '₱65,500.00', 'Cash-flow trend'],
    },
    {
        id: '08-settings',
        file: '08-settings.png',
        route: '/settings',
        heading: 'Settings',
        scrollTop: true,
        expectText: ['Profile', 'Equity Demo User', 'demo@equity.local'],
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

async function captureShot(page, shot) {
    if (!page.url().includes(shot.route)) {
        await page.goto(`${BASE_URL}${shot.route}`, { waitUntil: 'networkidle' });
    } else if (shot.id.startsWith('06b')) {
        // Already on bills; do not reload so scrolled state is intentional.
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
        await page.getByText(text, { exact: false }).first().waitFor({
            state: 'visible',
            timeout: 30_000,
        });
    }

    const metrics = await page.evaluate((expectedHeading) => {
        const asides = document.querySelectorAll('[aria-label="Workspace sidebar"]');
        const h1s = [...document.querySelectorAll('h1')].filter(
            (h) => h.textContent?.trim() === expectedHeading,
        );
        return {
            innerWidth: window.innerWidth,
            innerHeight: window.innerHeight,
            devicePixelRatio: window.devicePixelRatio,
            sidebarCount: asides.length,
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
    if (metrics.sidebarCount !== 1) {
        errors.push(`sidebarCount=${metrics.sidebarCount}`);
    }
    if (metrics.headingCount !== 1) {
        errors.push(`headingCount=${metrics.headingCount}`);
    }
    if (metrics.theme !== 'light') {
        errors.push(`theme=${metrics.theme}`);
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
        stagingPath,
        metrics,
        png: size,
        ok: errors.length === 0,
        errors,
    };
}

async function main() {
    await mkdir(stagingDir, { recursive: true });
    await mkdir(finalDir, { recursive: true });

    const browser = await chromium.launch({
        headless: true,
        args: ['--force-device-scale-factor=1'],
    });

    const context = await browser.newContext({
        viewport: VIEWPORT,
        deviceScaleFactor: 1,
        colorScheme: 'light',
        reducedMotion: 'reduce',
    });

    const page = await context.newPage();
    await page.addInitScript(() => {
        Object.defineProperty(window, 'devicePixelRatio', {
            get: () => 1,
        });
    });

    await login(page);

    /** @type {Awaited<ReturnType<typeof captureShot>>[]} */
    const results = [];
    for (const shot of SHOTS) {
        process.stdout.write(`capturing ${shot.id}… `);
        try {
            const result = await captureShot(page, shot);
            results.push(result);
            console.log(result.ok ? 'OK' : `FAIL ${result.errors.join('; ')}`);
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            results.push({
                id: shot.id,
                file: shot.file,
                route: shot.route,
                stagingPath: path.join(stagingDir, shot.file),
                metrics: null,
                png: null,
                ok: false,
                errors: [message],
            });
            console.log(`FAIL ${message}`);
        }
    }

    await browser.close();

    const reportPath = path.join(stagingDir, 'validation-report.json');
    await writeFile(reportPath, JSON.stringify({ promote: PROMOTE, results }, null, 2));

    const failed = results.filter((r) => !r.ok);
    const passed = results.filter((r) => r.ok);

    if (PROMOTE) {
        for (const result of passed) {
            // Only promote the eight Batch 1 filenames; keep 06b as diagnostics/extra.
            const isBatch1 = !result.file.startsWith('06b-');
            if (!isBatch1) {
                continue;
            }
            await copyFile(result.stagingPath, path.join(finalDir, result.file));
            console.log(`promoted ${result.file}`);
        }
        if (failed.some((f) => !f.file.startsWith('06b-'))) {
            console.error('Some Batch 1 shots failed; originals for those files were preserved.');
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
