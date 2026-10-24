/* Read-only browser QA for the local Al Nadha Filament panel.
 * Usage: QA_ADMIN_EMAIL=... QA_ADMIN_PASSWORD=... node tests/browser/cleaning-dashboard-smoke.cjs
 * Credentials are never written to screenshots or output.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
  const root = process.env.QA_BASE_URL || 'http://127.0.0.1:8000';
  const evidence = path.resolve(__dirname, '../../storage/app/qa/cleaning-dashboard');
  fs.mkdirSync(evidence, { recursive: true });
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.QA_BROWSER_PATH || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar' });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', err => errors.push(`pageerror: ${err.message}`));
  page.on('response', response => {
    if (response.status() >= 500) errors.push(`${response.status()} ${response.url()}`);
  });
  const routes = [
    ['landing', '/'],
    ['login', '/admin/login'],
    ['settings', '/admin/financial-settings'],
    ['room-catalog', '/admin/cleaning-catalog/cleaning-room-types'],
    ['materials', '/admin/cleaning-materials/cleaning-materials'],
    ['equipment', '/admin/cleaning-materials/cleaning-equipment-reservations'],
    ['bookings', '/admin/cleaning-operations/cleaning-bookings'],
  ];
  const results = [];
  try {
    await page.goto(`${root}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 25000 });
    if (process.env.QA_ADMIN_EMAIL && process.env.QA_ADMIN_PASSWORD) {
      const email = page.locator('input[type=email]');
      const password = page.locator('input[type=password]');
      if (await email.count() && await password.count()) {
        await email.fill(process.env.QA_ADMIN_EMAIL);
        await password.fill(process.env.QA_ADMIN_PASSWORD);
        await page.locator('button[type=submit]').first().click();
        await page.waitForLoadState('domcontentloaded');
      }
    }
    for (const [name, route] of routes) {
      try {
        const response = await page.goto(`${root}${route}`, { waitUntil: 'domcontentloaded', timeout: 25000 });
        await page.waitForTimeout(400);
        const body = await page.locator('body').innerText();
        const filename = `${name}.png`;
        await page.screenshot({ path: path.join(evidence, filename), fullPage: false });
        results.push({
          name, route, httpStatus: response?.status(), pageUrl: page.url(),
          redirectedToLogin: route !== '/admin/login' && page.url().includes('/admin/login'),
          title: await page.title(), chars: body.length, screenshot: filename,
        });
      } catch (error) {
        results.push({ name, route, error: String(error).slice(0, 300) });
      }
    }
  } finally {
    fs.writeFileSync(path.join(evidence, 'report.json'), JSON.stringify({ date: new Date().toISOString(), results, errors }, null, 2));
    console.log(JSON.stringify({ results, errors, evidence }, null, 2));
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
