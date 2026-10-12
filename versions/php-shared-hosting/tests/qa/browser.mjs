// Real-browser QA (headless Chromium via playwright-core): installs a fresh,
// uninstalled copy through the wizard exactly as a person would, then checks
// rendering, JavaScript behaviour (modals, live totals, double-click guard),
// Content-Security-Policy violations and the phone-width layout.
//
//   QA_BASE=http://127.0.0.1:8916/lpsystem QA_DB_NAME=... QA_DB_USER=... QA_DB_PASS=... \
//   QA_ADMIN_PASSWORD=... QA_SHOTS=/tmp/shots QA_OUT=results-browser.json \
//   CHROME=/path/to/chrome node tests/qa/browser.mjs
//
// Needs the playwright-core npm package (no browser download; pass CHROME).

import { chromium } from 'playwright-core';
import fs from 'node:fs';
import path from 'node:path';

const env = (k) => { if (!process.env[k]) throw new Error(`Missing env ${k}`); return process.env[k]; };
const BASE = env('QA_BASE').replace(/\/$/, '');
const SHOTS = env('QA_SHOTS');
fs.mkdirSync(SHOTS, { recursive: true });
const rows = [];
const check = (id, name, ok, detail = '') => {
  rows.push({ layout: 'browser', section: 'Browser', id, name, status: ok ? 'PASS' : 'FAIL', detail: ok ? '' : String(detail) });
  console.log(`  ${ok ? 'PASS' : 'FAIL'} ${id.padEnd(6)} ${name}${ok ? '' : '  -- ' + detail}`);
};
const shot = (page, name) => page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: true });
const png = path.join(SHOTS, 'qa-proof.png');
fs.writeFileSync(png, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'));

const browser = await chromium.launch({ executablePath: env('CHROME') });
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
const problems = [];
// 4xx "Failed to load resource" console lines come from deliberate validation
// failures (e.g. the wrong database password step); 5xx are caught separately.
const expected4xx = (t) => /status of 4\d\d/.test(t);
page.on('console', (m) => { if (m.type() === 'error' && !expected4xx(m.text())) problems.push(`console: ${m.text()}`); });
page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
page.on('response', (r) => { if (r.status() >= 500) problems.push(`HTTP ${r.status()} ${r.url()}`); });

try {
  // ---- Installer ----
  await page.goto(`${BASE}/`);
  check('W1', 'Visiting the site opens the installer', page.url().endsWith('/install/'), page.url());
  const brand = await page.$eval('.btn-primary', (el) => getComputedStyle(el).backgroundColor);
  check('W2', 'Stylesheet applied on the installer (brand button colour)', brand === 'rgb(220, 38, 38)', brand);
  await shot(page, '01-installer-welcome');
  await page.click('text=Start Installation');
  await page.waitForSelector('text=Requirements Check');
  check('W3', 'Requirements all pass', (await page.$$('.badge-red')).length === 0);
  await shot(page, '02-installer-requirements');
  await page.click('button:has-text("Continue")');
  await page.fill('#db_host', '127.0.0.1');
  await page.fill('#db_name', env('QA_DB_NAME'));
  await page.fill('#db_user', env('QA_DB_USER'));
  await page.fill('#db_pass', 'definitely-wrong');
  await page.click('button:has-text("Test Connection")');
  check('W4', 'Wrong DB password shows a readable error', (await page.textContent('.alert-error')).includes('username or password is incorrect'));
  await shot(page, '03-installer-db-error');
  await page.fill('#db_pass', env('QA_DB_PASS'));
  await page.click('button:has-text("Test Connection")');
  await page.waitForSelector('text=Create Database Tables');
  await page.click('button:has-text("Create Tables")');
  await page.waitForSelector('#admin_name');
  await page.fill('#admin_name', 'LP System QA Administrator');
  await page.fill('#admin_email', 'qa-admin@example.test');
  await page.fill('#admin_password', env('QA_ADMIN_PASSWORD'));
  await page.fill('#admin_password_confirm', env('QA_ADMIN_PASSWORD'));
  await page.click('button:has-text("Continue")');
  await page.waitForSelector('#timezone');
  check('W5', 'Timezone defaults to Asia/Manila', (await page.$eval('#timezone', (s) => s.value)) === 'Asia/Manila');
  await page.click('button:has-text("Finish Installation")');
  await page.waitForSelector('[data-install-complete]');
  await shot(page, '04-installer-complete');
  check('W6', 'Installation completes in the browser', true);

  // ---- Sign in ----
  await page.click('text=Go to Sign In');
  await page.fill('#email', 'qa-admin@example.test');
  await page.fill('#password', env('QA_ADMIN_PASSWORD'));
  await page.click('button:has-text("Sign in")');
  await page.waitForSelector('[data-kpi="outstanding"]');
  const sidebar = await page.$eval('.sidebar', (el) => el.getBoundingClientRect().width);
  check('W7', 'Dashboard renders with the sidebar', sidebar > 150, sidebar);
  await shot(page, '05-dashboard');

  // ---- Customer (double-click on submit) ----
  await page.goto(`${BASE}/customers/new`);
  await page.fill('#name', 'QA Demo Printing Customer');
  await page.fill('#company_name', 'QA Test Contact');
  await page.fill('#email', 'qa-customer@example.test');
  await page.fill('#contact_number', '09990000000');
  await page.dblclick('button:has-text("Create Customer")');
  await page.waitForSelector('h1:has-text("QA Demo Printing Customer")');
  await page.goto(`${BASE}/customers?q=QA+Demo`);
  const custRows = await page.$$eval('table.data-table tbody tr', (r) => r.filter((x) => x.textContent.includes('QA Demo Printing Customer')).length);
  check('W8', 'Double-clicking "Create Customer" creates one customer', custRows === 1, custRows);

  // ---- Quotation form: live totals ----
  await page.click('text=CUS-');
  await page.click('a:has-text("+ Quotation")');
  await page.fill('input[name="description[]"]', 'QA Test Tarpaulin Printing');
  await page.fill('input[name="qty[]"]', '10');
  await page.fill('input[name="unit_price[]"]', '500');
  await page.selectOption('select[name="discount_type"]', 'FIXED');
  await page.fill('#discount_value', '100');
  await page.fill('#tax_pct', '12');
  const preview = await page.textContent('#preview-total');
  check('W9', 'Live preview: 10 × ₱500 − ₱100 + 12% = 5,488.00', preview.trim() === '5,488.00', preview);
  await page.click('#add-line-item');
  check('W10', '"+ Add Line Item" adds a row', (await page.$$('#line-items-table tbody tr')).length === 2);
  await page.click('#line-items-table tbody tr:nth-child(2) .li-remove');
  check('W11', 'Remove (✕) deletes the extra row', (await page.$$('#line-items-table tbody tr')).length === 1);
  await shot(page, '06-quotation-form');
  await page.click('button:has-text("Create Quotation")');
  await page.waitForSelector('[data-total="total"]');
  check('W12', 'Saved quotation shows ₱5,488.00 (server total)', (await page.textContent('[data-total="total"]')).includes('₱5,488.00'));

  // ---- Approve modal ----
  await page.click('button:has-text("Approve")');
  check('W13', 'Approve opens a modal dialog', await page.isVisible('#approve-modal'));
  await page.keyboard.press('Escape');
  check('W14', 'Escape closes the modal', !(await page.isVisible('#approve-modal')));
  await page.click('button:has-text("Approve")');
  await page.fill('#approve-modal-field', 'QA: customer approved by phone');
  await page.click('#approve-modal button:has-text("Approve & Create Order")');
  await page.waitForSelector('h1:has-text("ORD-")');
  const orderPath = new URL(page.url()).pathname.replace(new URL(BASE).pathname, '');
  await shot(page, '07-order');

  // ---- Record Payment modal ----
  await page.click('button:has-text("Record Payment")');
  await page.fill('#pay-amount', '1000');
  const bal = await page.textContent('[data-balance-preview]');
  check('W15', 'Payment modal previews the balance after payment (₱4,488.00)', bal.includes('4,488.00'), bal);
  await page.selectOption('#pay-method', 'BANK_TRANSFER');
  await page.fill('#pay-ref', 'QA-PAYMENT-001');
  await page.setInputFiles('#pay-proof', png);
  await shot(page, '08-record-payment');
  await page.click('#record-payment-modal button:has-text("Record Payment")');
  await page.waitForSelector('text=QA-PAYMENT-001');
  check('W16', 'Payment with proof recorded as PENDING', (await page.textContent('table:has-text("QA-PAYMENT-001")')).includes('PENDING'));
  await page.click('button:has-text("Confirm")');
  await page.waitForSelector('.alert-success');
  check('W17', 'Payment confirmed from the order page', (await page.textContent('.alert-success')).includes('Payment confirmed'));

  // ---- Phone width ----
  const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  await phone.addCookies(await ctx.cookies());
  const m = await phone.newPage();
  m.on('console', (x) => { if (x.type() === 'error' && !expected4xx(x.text())) problems.push(`mobile console: ${x.text()}`); });
  for (const [p, name] of [['/dashboard', '09-mobile-dashboard'], ['/orders', '10-mobile-orders'], ['/quotations/new', '11-mobile-quotation-form'], [orderPath, '11b-mobile-order'], ['/customers', '11c-mobile-customers'], ['/admin/users', '11d-mobile-users']]) {
    await m.goto(`${BASE}${p}`);
    const over = await m.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    const offenders = over > 0 ? await m.evaluate(() => [...document.querySelectorAll('body *')]
      .filter((el) => el.getBoundingClientRect().right > document.documentElement.clientWidth + 1)
      .slice(0, 5).map((el) => el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).replace(/\s+/g, '.') : ''))) : [];
    check('W18', `No horizontal page scroll at 390px: ${p}`, over <= 0, `${over}px ${offenders.join(', ')}`);
    await shot(m, name);
  }
  await m.goto(`${BASE}/dashboard`);
  await m.click('.mobile-nav-toggle');
  check('W19', 'Mobile menu opens', await m.isVisible('.mobile-nav-drawer.open .panel'));
  await shot(m, '12-mobile-menu');
  await phone.close();

  check('W20', 'No JavaScript errors, CSP violations or 5xx responses', problems.length === 0, problems.join(' | '));
} catch (e) {
  check('ABORT', 'Browser run aborted', false, e.message);
  await shot(page, 'zz-abort').catch(() => {});
} finally {
  await browser.close();
  const counts = rows.reduce((a, r) => ({ ...a, [r.status]: (a[r.status] || 0) + 1 }), {});
  fs.writeFileSync(env('QA_OUT'), JSON.stringify({ layout: 'browser', base: BASE, rows, counts }, null, 2));
  console.log(`\n[browser] PASS ${counts.PASS || 0}  FAIL ${counts.FAIL || 0}`);
  process.exitCode = counts.FAIL ? 1 : 0;
}
