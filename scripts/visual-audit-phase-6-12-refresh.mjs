/**
 * Phase 6.12: refresh Reports + Bills screenshots after a4e4cd8.
 * Captures four shots (1440 + 390) into staging, validates, optionally promotes.
 *
 * Usage:
 *   node scripts/visual-audit-phase-6-12-refresh.mjs
 *   node scripts/visual-audit-phase-6-12-refresh.mjs --promote
 *
 * Prerequisites:
 *   php artisan serve --env=visual-audit --host=127.0.0.1 --port=8000
 */
import { chromium } from 'playwright';
import { copyFile, mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const stagingDir = path.join(
    root,
    'docs',
    'visual-audit',
    '_diagnostics',
    'staging-phase-6-12',
);

const BASE_URL = process.env.EQUITY_VISUAL_AUDIT_URL ?? 'http://127.0.0.1:8000';
const DEMO_EMAIL = 'demo@equity.local';
const DEMO_PASSWORD = 'VisualAudit!Demo99';
const PROMOTE = process.argv.includes('--promote');

/** @type {Array<{
 *   id: string,
 *   file: string,
 *   finalDir: string,
 *   route: string,
 *   heading: string,
 *   width: number,
 *   height: number,
 *   isMobile: boolean,
 * }>} */
const SHOTS = [
    {
        id: '1440-bills',
        file: '06-bills-populated.png',
        finalDir: path.join(root, 'docs', 'visual-audit', '1440', 'light'),
        route: '/bills',
        heading: 'Bills & Subscriptions',
        width: 1440,
        height: 900,
        isMobile: false,
    },
    {
        id: '1440-reports',
        file: '07-reports-populated.png',
        finalDir: path.join(root, 'docs', 'visual-audit', '1440', 'light'),
        route: '/reports',
        heading: 'Financial Reports',
        width: 1440,
        height: 900,
        isMobile: false,
    },
    {
        id: '390-bills',
        file: '17-bills-populated.png',
        finalDir: path.join(root, 'docs', 'visual-audit', '390', 'light'),
        route: '/bills',
        heading: 'Bills & Subscriptions',
        width: 390,
        height: 844,
        isMobile: true,
    },
    {
        id: '390-reports',
        file: '19-reports-month.png',
        finalDir: path.join(root, 'docs', 'visual-audit', '390', 'light'),
        route: '/reports',
        heading: 'Financial Reports',
        width: 390,
        height: 844,
        isMobile: true,
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
        const docEl = document.documentElement;
        const body = document.body;
        const scrollWidth = Math.max(docEl.scrollWidth, body?.scrollWidth ?? 0);
        const clientWidth = window.innerWidth;
        return {
            innerWidth: window.innerWidth,
            innerHeight: window.innerHeight,
            devicePixelRatio: window.devicePixelRatio,
            scrollWidth,
            clientWidth,
            horizontalOverflowPx: Math.max(0, scrollWidth - clientWidth),
            theme: document.documentElement.getAttribute('data-theme') ?? 'light',
            headingText:
                [...document.querySelectorAll('h1')]
                    .map((node) => node.textContent?.trim() ?? '')
                    .find(Boolean) ?? null,
            headerCount: document.querySelectorAll('header').length,
            h1Count: document.querySelectorAll('h1').length,
        };
    });
}

async function validateBills(page, isMobile) {
    const shortcut = page.getByTestId('bills-needs-payment');
    const shortcutVisible = await shortcut.isVisible().catch(() => false);

    if (!isMobile) {
        return {
            shortcutVisible,
            shortcutHiddenOnDesktop: !shortcutVisible,
            payInViewport: null,
            payUnobstructed: null,
            remainingCopyOk: null,
            firstPayY: null,
        };
    }

    if (!shortcutVisible) {
        throw new Error('Mobile Needs payment shortcut not visible');
    }

    await page.getByRole('heading', { name: 'Needs payment' }).waitFor({
        state: 'visible',
        timeout: 10_000,
    });

    const remainingLine = shortcut.locator('li').first().locator('p').filter({
        hasText: /Due \d{4}-\d{2}-\d{2} · Remaining /,
    });
    await remainingLine.waitFor({ state: 'visible', timeout: 10_000 });
    const remainingText = ((await remainingLine.textContent()) ?? '').replace(/\s+/g, ' ').trim();
    const remainingCopyOk = /Due \d{4}-\d{2}-\d{2} · Remaining \S/.test(remainingText);

    const pay = shortcut.getByRole('button', { name: 'Pay' }).first();
    await pay.waitFor({ state: 'visible', timeout: 10_000 });
    const payBox = await pay.boundingBox();
    const amountBox = await remainingLine.boundingBox();
    const payInViewport = Boolean(payBox && payBox.y >= 0 && payBox.y < 844);
    const payUnobstructed = Boolean(
        payBox && amountBox && amountBox.x + amountBox.width <= payBox.x + 2,
    );

    return {
        shortcutVisible,
        shortcutHiddenOnDesktop: null,
        payInViewport,
        payUnobstructed,
        remainingCopyOk,
        firstPayY: payBox?.y ?? null,
        remainingText,
    };
}

async function validateReports(page) {
    await page.getByText('Period summary', { exact: false }).first().waitFor({
        state: 'visible',
        timeout: 30_000,
    });
    const amount = page.locator('[data-amount-minor]').first();
    await amount.waitFor({ state: 'visible', timeout: 30_000 });
    const amountText = ((await amount.textContent()) ?? '').trim();
    const amountBox = await amount.boundingBox();
    const fullyVisible = Boolean(
        amountText.length > 0 &&
            amountBox &&
            amountBox.x >= 0 &&
            amountBox.x + amountBox.width <= page.viewportSize().width + 1,
    );

    return {
        amountText,
        fullyVisible,
        amountBox,
    };
}

async function captureOne(browser, shot) {
    const context = await browser.newContext({
        viewport: { width: shot.width, height: shot.height },
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

    const errors = [];
    const consoleErrors = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') {
            consoleErrors.push(msg.text());
        }
    });

    await login(page);
    await page.goto(`${BASE_URL}${shot.route}`, { waitUntil: 'networkidle' });
    await page.getByRole('heading', { level: 1, name: shot.heading }).waitFor({
        state: 'visible',
        timeout: 30_000,
    });

    if (shot.route === '/bills') {
        await page.getByRole('heading', { name: 'This month overview' }).waitFor({
            state: 'visible',
            timeout: 30_000,
        });
    }

    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(400);

    const metrics = await measureOverflow(page);
    if (metrics.innerWidth !== shot.width || metrics.innerHeight !== shot.height) {
        errors.push(`viewport ${metrics.innerWidth}x${metrics.innerHeight}`);
    }
    if (metrics.devicePixelRatio !== 1) {
        errors.push(`dpr=${metrics.devicePixelRatio}`);
    }
    if (metrics.theme !== 'light') {
        errors.push(`theme=${metrics.theme}`);
    }
    if (metrics.headingText !== shot.heading) {
        errors.push(`heading=${metrics.headingText}`);
    }
    if (metrics.h1Count !== 1) {
        errors.push(`h1Count=${metrics.h1Count}`);
    }
    if (metrics.horizontalOverflowPx !== 0) {
        errors.push(`overflow=${metrics.horizontalOverflowPx}px`);
    }

    /** @type {Record<string, unknown>} */
    let pageChecks = {};
    if (shot.route === '/bills') {
        pageChecks = await validateBills(page, shot.isMobile);
        if (shot.isMobile) {
            if (!pageChecks.payInViewport) {
                errors.push('mobile Pay not in first viewport');
            }
            if (!pageChecks.payUnobstructed) {
                errors.push('mobile Pay obstructed');
            }
            if (!pageChecks.remainingCopyOk) {
                errors.push('remaining copy missing spaces');
            }
        } else if (!pageChecks.shortcutHiddenOnDesktop) {
            errors.push('desktop still shows Needs payment shortcut');
        }
    } else {
        pageChecks = await validateReports(page);
        if (!pageChecks.fullyVisible) {
            errors.push(`reports amount not fully visible (${pageChecks.amountText})`);
        }
        if (!String(pageChecks.amountText ?? '').includes('₱') && !String(pageChecks.amountText ?? '').includes('$')) {
            errors.push(`unexpected amount text: ${pageChecks.amountText}`);
        }
    }

    const stagingPath = path.join(stagingDir, `${shot.width}-${shot.file}`);
    await page.screenshot({ path: stagingPath, fullPage: false, type: 'png' });
    const png = readPngSize(await readFile(stagingPath));
    if (png.width !== shot.width || png.height !== shot.height) {
        errors.push(`png ${png.width}x${png.height}`);
    }

    // Heuristic: duplicated/tiled rendering often yields multiple identical h1 or headers.
    if (metrics.headerCount > 2) {
        errors.push(`headerCount=${metrics.headerCount} (possible tiled shell)`);
    }

    await context.close();

    return {
        id: shot.id,
        file: shot.file,
        finalDir: shot.finalDir,
        route: shot.route,
        stagingPath,
        metrics,
        pageChecks,
        png,
        consoleErrors,
        ok: errors.length === 0 && consoleErrors.length === 0,
        errors: [...errors, ...consoleErrors.map((text) => `console: ${text}`)],
    };
}

async function main() {
    await mkdir(stagingDir, { recursive: true });

    const browser = await chromium.launch({
        headless: true,
        args: ['--force-device-scale-factor=1'],
    });

    /** @type {Awaited<ReturnType<typeof captureOne>>[]} */
    const results = [];
    try {
        for (const shot of SHOTS) {
            process.stdout.write(`capturing ${shot.id}… `);
            try {
                const result = await captureOne(browser, shot);
                results.push(result);
                console.log(result.ok ? 'OK' : `FAIL ${result.errors.join('; ')}`);
            } catch (error) {
                const message = error instanceof Error ? error.message : String(error);
                results.push({
                    id: shot.id,
                    file: shot.file,
                    finalDir: shot.finalDir,
                    route: shot.route,
                    stagingPath: path.join(stagingDir, `${shot.width}-${shot.file}`),
                    metrics: null,
                    pageChecks: null,
                    png: null,
                    consoleErrors: [],
                    ok: false,
                    errors: [message],
                });
                console.log(`FAIL ${message}`);
            }
        }
    } finally {
        await browser.close();
    }

    const report = {
        phase: '6.12',
        promote: PROMOTE,
        capturedAt: new Date().toISOString(),
        results,
        ok: results.every((result) => result.ok),
    };
    const reportPath = path.join(stagingDir, 'validation-report.json');
    await writeFile(reportPath, JSON.stringify(report, null, 2));

    if (PROMOTE) {
        const failed = results.filter((result) => !result.ok);
        if (failed.length > 0) {
            console.error('Refusing promote: validation failures remain.');
            process.exitCode = 1;
            console.log(`report=${reportPath}`);
            return;
        }
        for (const result of results) {
            await mkdir(result.finalDir, { recursive: true });
            await copyFile(result.stagingPath, path.join(result.finalDir, result.file));
            console.log(`promoted ${path.join(result.finalDir, result.file)}`);
        }
    }

    console.log(
        `passed=${results.filter((r) => r.ok).length} failed=${results.filter((r) => !r.ok).length} report=${reportPath}`,
    );
    if (!report.ok) {
        process.exitCode = 1;
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
