// Loggt sich in den Test-Stack ein und erzeugt Screenshots des Kalenders
// (Woche/Tag/Monat/Liste) sowie Aufgaben und Kontakte.
//   node shoot.mjs      (Stack muss laufen + geseedet sein, siehe generate.sh)
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

// --- Kalender in allen Ansichten ---
async function calendar(view, file) {
  await page.goto(`${BASE}/?_task=calendar`, { waitUntil: 'networkidle' });
  const btn = `.view-btn[data-view="${view}"]`;
  await page.waitForSelector(btn, { timeout: 15000 }).catch(() => {});
  await page.click(btn).catch(() => {});
  // Events werden per AJAX nachgeladen + gerendert
  await page.waitForTimeout(3000);
  await snap(file);
}

await calendar('week', 'calendar-week.png');
await calendar('day', 'calendar-day.png');
await calendar('month', 'calendar-month.png');
await calendar('list', 'calendar-list.png');

// --- Aufgaben ---
await page.goto(`${BASE}/?_task=tasks`, { waitUntil: 'networkidle' }).catch(() => {});
await page.waitForTimeout(2000);
await snap('tasks.png');

// --- Kontakte ---
await page.goto(`${BASE}/?_task=addressbook`, { waitUntil: 'networkidle' }).catch(() => {});
await page.waitForTimeout(2000);
await snap('contacts.png');

await browser.close();
console.log(`\n${done.length} Screenshots in ${OUT}`);
