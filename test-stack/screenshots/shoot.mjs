// Logs into the test stack and creates screenshots of the calendar
// (week/day/month/list) as well as tasks and contacts.
//   node shoot.mjs      (stack must be running + seeded, see generate.sh)
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const BASE = process.env.BASE || 'http://127.0.0.1:8099';
const OUT = join(dirname(fileURLToPath(import.meta.url)), 'out');
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
const page = await ctx.newPage();
const done = [];

async function snap(name) {
  const path = join(OUT, name);
  await page.screenshot({ path, fullPage: true });
  done.push(name);
  console.log('  ✓', name);
}

// --- Login ---
await page.goto(`${BASE}/?_task=login`, { waitUntil: 'networkidle' });
await page.fill('#rcmloginuser', 'test');
await page.fill('#rcmloginpwd', 'test');
await Promise.all([
  page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
  page.click('#rcmloginsubmit, button[type=submit]'),
]);

// --- Calendar in all views ---
async function calendar(view, file) {
  await page.goto(`${BASE}/?_task=calendar`, { waitUntil: 'networkidle' });
  const btn = `.view-btn[data-view="${view}"]`;
  await page.waitForSelector(btn, { timeout: 15000 }).catch(() => {});
  await page.click(btn).catch(() => {});
  // Events are loaded + rendered via AJAX
  await page.waitForTimeout(3000);
  await snap(file);
}

await calendar('week', 'calendar-week.png');
await calendar('day', 'calendar-day.png');
await calendar('month', 'calendar-month.png');
await calendar('list', 'calendar-list.png');

// --- Tasks ---
await page.goto(`${BASE}/?_task=tasks`, { waitUntil: 'networkidle' }).catch(() => {});
await page.waitForSelector('.task-item', { timeout: 12000 }).catch(() => {});
await page.waitForTimeout(2000);
await snap('tasks.png');

// --- Contacts (select the CardDAV source so the list is populated) ---
await page.goto(`${BASE}/?_task=addressbook`, { waitUntil: 'networkidle' }).catch(() => {});
await page.waitForSelector('#directorylist', { timeout: 12000 }).catch(() => {});
await page.waitForTimeout(1000);
// Click the address book entry (display name from seed.php: "Contacts")
await page.evaluate(() => {
  const items = [...document.querySelectorAll('#directorylist li')];
  const hit = items.find(li => /contacts/i.test(li.textContent || ''));
  const link = hit && hit.querySelector('a');
  if (link) link.click();
}).catch(() => {});
await page.waitForSelector('#contacts-table tbody tr, .listing tr, .contact-list tr', { timeout: 10000 }).catch(() => {});
await page.waitForTimeout(1500);
await snap('contacts.png');

await browser.close();
console.log(`\n${done.length} screenshots in ${OUT}`);
