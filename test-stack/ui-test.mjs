/**
 * UI-Test fuer den Kalender (Wochenansicht + Event-Dialog).
 *
 * Deckt die Bugs ab, die reine PHPUnit-Backend-Tests NICHT fangen koennen:
 *   1. Klick auf ein Event oeffnet das KORREKTE Event (data-url eindeutig).
 *   2. Der Edit-Dialog FUELLT Beginn/Ende (All-Day = <input type=date>,
 *      Timed = <input type=datetime-local>).
 *   3. Speichern + Wieder-Oeffnen driftet das Datum NICHT (All-Day-DTEND
 *      exklusiv <-> inklusiv Round-Trip).
 *
 * Voraussetzung: Stack laeuft + Demo-Woche geseedet (siehe run-ui-test.sh).
 * Aufruf:  node ui-test.mjs            (nutzt screenshots/node_modules/playwright)
 * Exit-Code != 0 bei Fehlschlag.
 */
import { chromium } from './screenshots/node_modules/playwright/index.mjs';

const B = process.env.RC_BASE || 'http://127.0.0.1:8099';
const fails = [];
const ok = (cond, msg) => { console.log((cond ? '  OK  ' : ' FAIL ') + msg); if (!cond) fails.push(msg); };

const b = await chromium.launch();
const p = await (await b.newContext()).newPage();

async function gotoWeek() {
  await p.goto(`${B}/?_task=calendar`, { waitUntil: 'networkidle' });
  await p.waitForFunction(() => document.querySelectorAll('.calendar-list li').length > 0, { timeout: 10000 }).catch(() => {});
  await p.waitForTimeout(800);
  await p.click('.view-btn[data-view="week"]');
  await p.waitForFunction(() => document.querySelectorAll('.week-event-inline, .week-allday-event').length > 0, { timeout: 12000 }).catch(() => {});
  await p.waitForTimeout(700);
}
const readDialog = () => p.evaluate(() => {
  const g = id => { const e = document.getElementById(id); return e ? { type: e.type, val: e.value, checked: e.checked } : null; };
  return { title: (document.getElementById('ev-title') || {}).value, allday: (g('ev-allday') || {}).checked,
           start: (g('ev-start') || {}).val, end: (g('ev-end') || {}).val, stype: (g('ev-start') || {}).type, etype: (g('ev-end') || {}).type };
});
async function closeDialog() {
  await p.evaluate(() => { document.querySelectorAll('.ui-dialog-titlebar-close,[title=Close]').forEach(x => x.click()); });
  await p.keyboard.press('Escape').catch(() => {});
  await p.waitForTimeout(400);
}
async function clickSave() {
  await p.evaluate(() => {
    const btns = [...document.querySelectorAll('.ui-dialog-buttonpane button, .ui-dialog .ui-button')];
    (btns.find(x => /speichern|save/i.test(x.textContent)) || btns[0])?.click();
  });
  await p.waitForTimeout(1500);
}
async function openByText(sel, needle) {
  for (const e of await p.$$(sel)) { if (((await e.textContent()) || '').includes(needle)) { await e.click(); await p.waitForTimeout(700); return true; } }
  return false;
}

// Login
await p.goto(`${B}/?_task=login`, { waitUntil: 'networkidle' });
await p.fill('#rcmloginuser', 'test'); await p.fill('#rcmloginpwd', 'test');
await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}), p.click('#rcmloginsubmit, button[type=submit]')]);

// --- Test 1: data-url eindeutig + Klick oeffnet korrektes Event ---
console.log('\n[1] Klick oeffnet korrektes Event');
await gotoWeek();
const urls = await p.evaluate(() => [...document.querySelectorAll('.week-event-inline, .week-allday-event')].map(e => e.getAttribute('data-url')));
ok(urls.length > 0, `Events gerendert (${urls.length})`);
ok(urls.every(u => u && u !== 'null' && u !== 'undefined'), 'keine leeren/null data-url');
const els = await p.$$('.week-event-inline, .week-allday-event');
for (const idx of [...new Set([0, Math.floor(els.length / 2), els.length - 1])]) {
  const expected = ((await els[idx].textContent()) || '').trim();
  await els[idx].click(); await p.waitForTimeout(600);
  const t = await p.evaluate(() => (document.getElementById('ev-title') || {}).value);
  ok(t && expected.includes(t.slice(0, 10)), `Klick idx=${idx}: Dialog "${t}" passt zu "${expected.slice(0, 24)}"`);
  await closeDialog();
}

// --- Test 2 + 3: Felder gefuellt + Round-Trip ohne Drift ---
for (const kind of ['.week-allday-event', '.week-event-inline']) {
  const labelK = kind.includes('allday') ? 'ALL-DAY' : 'TIMED';
  console.log(`\n[2/3] ${labelK}: Felder gefuellt + Save-Reopen ohne Drift`);
  await gotoWeek();
  const el = await p.$(kind);
  const full = ((await el.textContent()) || '').trim();
  const needle = full.replace(/^[^A-Za-z0-9ÄÖÜ]+/, '').replace(/^[0-9:\s]+/, '').slice(0, 12);
  await el.click(); await p.waitForTimeout(700);
  const r1 = await readDialog();
  ok(!!r1.start, `${labelK} Beginn gefuellt ("${r1.start}", type=${r1.stype})`);
  ok(!!r1.end, `${labelK} Ende gefuellt ("${r1.end}", type=${r1.etype})`);
  if (labelK === 'ALL-DAY') ok(r1.stype === 'date' && r1.etype === 'date', 'All-Day -> type=date');
  await clickSave();
  await gotoWeek();
  await openByText(kind, needle);
  const r2 = await readDialog();
  ok(r1.start === r2.start && r1.end === r2.end, `${labelK} kein Drift nach Save (${r1.start}..${r1.end} -> ${r2.start}..${r2.end})`);
  await closeDialog();
}

// --- Test 4: Einheitliche, navigierbare Listen (Kalender-Liste + Aufgaben) ---
async function probeNavigableList(containerSel, itemSel, label, opensDialog) {
  console.log(`\n[4] ${label}: ARIA-Listbox + Pfeil-Navigation`);
  const info = await p.evaluate(({ containerSel, itemSel }) => {
    const c = document.querySelector(containerSel);
    const items = c ? [...c.querySelectorAll(itemSel)] : [];
    return {
      cRole: c && c.getAttribute('role'), cLabel: c && c.getAttribute('aria-label'), n: items.length,
      roles: items.slice(0, 3).map(i => i.getAttribute('role')),
      label0: items[0] && (items[0].getAttribute('aria-label') || ''),
      ti0: items[0] && items[0].getAttribute('tabindex'), ti1: items[1] && items[1].getAttribute('tabindex'),
    };
  }, { containerSel, itemSel });
  ok(info.cRole === 'listbox', `${label} Container role=listbox`);
  ok(!!info.cLabel, `${label} Container aria-label ("${info.cLabel}")`);
  ok(info.n > 1, `${label} Items: ${info.n}`);
  ok(info.roles.every(r => r === 'option'), `${label} Items role=option`);
  ok(!!info.label0, `${label} Items aria-label ("${(info.label0 || '').slice(0, 36)}")`);
  ok(info.ti0 === '0' && info.ti1 === '-1', `${label} Roving-Tabindex`);
  await p.evaluate(s => document.querySelector(s).focus(), `${containerSel} ${itemSel}`);
  await p.waitForTimeout(150);
  await p.keyboard.press('ArrowDown'); await p.waitForTimeout(200);
  const idx = await p.evaluate(itemSel => [...document.querySelectorAll(itemSel)].indexOf(document.activeElement), `${containerSel} ${itemSel}`);
  ok(idx === 1, `${label} ArrowDown bewegt Fokus zum naechsten Item`);
  if (opensDialog) {
    await p.keyboard.press('Enter'); await p.waitForTimeout(600);
    ok(await p.evaluate(() => !!document.querySelector('.ui-dialog')), `${label} Enter oeffnet Dialog`);
    await closeDialog();
  }
}

await p.goto(`${B}/?_task=calendar`, { waitUntil: 'networkidle' });
await p.waitForFunction(() => document.querySelectorAll('.calendar-list li').length > 0, { timeout: 10000 }).catch(() => {});
await p.waitForTimeout(700);
await p.click('.view-btn[data-view="list"]');
await p.waitForFunction(() => document.querySelectorAll('.list-event').length > 0, { timeout: 12000 }).catch(() => {});
await probeNavigableList('.list-view', '.list-event', 'KALENDER-LISTE', true);

await p.goto(`${B}/?_task=tasks`, { waitUntil: 'networkidle' });
const hasTasks = await p.waitForFunction(() => document.querySelectorAll('.task-item').length > 0, { timeout: 12000 }).then(() => true).catch(() => false);
if (hasTasks) await probeNavigableList('#task-list', '.task-item', 'AUFGABEN', true);
else console.log('\n[4] AUFGABEN: uebersprungen (keine VTODO-Aufgaben geseedet)');

// --- Test 5: Abhaken behaelt den Fokus in der Liste (Regression: Fokus fiel auf <body>) ---
if (hasTasks) {
  console.log('\n[5] AUFGABEN: Abhaken behaelt Fokus + Pfeil-Navigation');
  // Regression: role=option-Items duerfen KEINE fokussierbaren Nachfahren haben
  // (sonst landet NVDA/Klick auf der Checkbox statt auf der Aufgabe).
  const focusable = await p.evaluate(() =>
    document.querySelectorAll('#task-list .task-item input, #task-list .task-item button, #task-list .task-item a[href], #task-list .task-item [tabindex]:not([tabindex="-1"]):not(.task-item)').length);
  ok(focusable === 0, `Keine fokussierbaren Kind-Elemente im Aufgaben-Item (gefunden: ${focusable})`);
  const before = await p.evaluate(() => {
    const items = [...document.querySelectorAll('.task-item')];
    return { n: items.length, first: items[0] && items[0].getAttribute('data-url'),
             second: items[1] && items[1].getAttribute('data-url') };
  });
  // Erstes Item fokussieren und per Leertaste abhaken (erledigt -> faellt aus der Liste).
  await p.evaluate(() => document.querySelector('#task-list .task-item').focus());
  await p.waitForTimeout(150);
  await p.keyboard.press(' ');
  // Auf Reload warten: entweder Item-Anzahl sinkt oder das erste Item ist weg.
  await p.waitForFunction(
    (b) => { const it = [...document.querySelectorAll('.task-item')];
             return it.length !== b.n || !(it[0] && it[0].getAttribute('data-url') === b.first); },
    before, { timeout: 8000 }
  ).catch(() => {});
  await p.waitForTimeout(300);
  const after = await p.evaluate(() => {
    const a = document.activeElement;
    return { onBody: a === document.body || a === document.documentElement,
             onTask: !!(a && a.closest && a.closest('.task-item')),
             onHint: !!(a && a.classList && a.classList.contains('hint')),
             remaining: document.querySelectorAll('.task-item').length };
  });
  ok(!after.onBody, `Fokus nicht auf <body> nach Abhaken (Task: ${after.onTask}, Hint: ${after.onHint})`);
  ok(after.onTask || after.onHint, 'Fokus liegt auf Nachbar-Aufgabe oder Leer-Hinweis');
  if (after.remaining > 1) {
    await p.keyboard.press('ArrowDown'); await p.waitForTimeout(200);
    const moved = await p.evaluate(() => {
      const items = [...document.querySelectorAll('.task-item')];
      return items.indexOf(document.activeElement) > 0;
    });
    ok(moved, 'ArrowDown funktioniert nach dem Abhaken weiter');
  }
}

await b.close();
console.log(`\n${fails.length ? 'FEHLGESCHLAGEN: ' + fails.length : 'ALLE TESTS GRUEN'}`);
process.exit(fails.length ? 1 : 0);
