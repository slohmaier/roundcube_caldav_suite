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

// --- Test 4: Einheitliche, navigierbare Listen (aria-activedescendant) ---
// EIN Tab-Stopp = der Listbox-Container (tabindex=0), Pfeiltasten bewegen die aktive
// Option via aria-activedescendant, der Fokus bleibt auf dem Container.
async function probeNavigableList(containerSel, itemSel, label, opensDialog) {
  console.log(`\n[4] ${label}: ARIA-Listbox + activedescendant-Navigation`);
  const info = await p.evaluate(({ containerSel, itemSel }) => {
    const c = document.querySelector(containerSel);
    const items = c ? [...c.querySelectorAll(itemSel)] : [];
    return {
      cRole: c && c.getAttribute('role'), cLabel: c && c.getAttribute('aria-label'),
      cTab: c && c.getAttribute('tabindex'), n: items.length,
      roles: items.slice(0, 3).map(i => i.getAttribute('role')),
      label0: items[0] && (items[0].getAttribute('aria-label') || ''),
      ids: items.slice(0, 2).map(i => i.id),
      itemHasTabstop: items.some(i => i.getAttribute('tabindex') === '0'),
    };
  }, { containerSel, itemSel });
  ok(info.cRole === 'listbox', `${label} Container role=listbox`);
  ok(!!info.cLabel, `${label} Container aria-label ("${info.cLabel}")`);
  ok(info.cTab === '0', `${label} Container tabindex=0 (genau EIN Tab-Stopp)`);
  ok(info.n > 1, `${label} Items: ${info.n}`);
  ok(info.roles.every(r => r === 'option'), `${label} Items role=option`);
  ok(!!info.label0, `${label} Items aria-label ("${(info.label0 || '').slice(0, 36)}")`);
  ok(info.ids.every(id => id), `${label} Optionen haben ids (fuer activedescendant)`);
  ok(!info.itemHasTabstop, `${label} Keine Option ist eigener Tab-Stopp`);
  // Container fokussieren -> erste Option aktiv; ArrowDown -> zweite Option aktiv
  await p.evaluate(s => document.querySelector(s).focus(), containerSel);
  await p.waitForTimeout(150);
  await p.keyboard.press('ArrowDown'); await p.waitForTimeout(200);
  const nav = await p.evaluate(({ containerSel, itemSel }) => {
    const c = document.querySelector(containerSel);
    const items = [...c.querySelectorAll(itemSel)];
    const id = c.getAttribute('aria-activedescendant');
    return { idx: items.findIndex(i => i.id === id), onContainer: document.activeElement === c };
  }, { containerSel, itemSel });
  ok(nav.idx === 1, `${label} ArrowDown bewegt aktive Option zum naechsten Item (idx=${nav.idx})`);
  ok(nav.onContainer, `${label} Fokus bleibt auf dem Container`);
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

// Kalender-Sidebar (Liste der Kalender zum Ein-/Ausblenden) navigierbar wie Aufgabenlisten-Sidebar
const calSidebar = await p.waitForFunction(() => document.querySelectorAll('#calendar-ul .calendar-item').length > 1, { timeout: 8000 }).then(() => true).catch(() => false);
if (calSidebar) {
  await probeNavigableList('#calendar-ul', '.calendar-item', 'KALENDER-SIDEBAR', false);
  const cf = await p.evaluate(() => document.querySelectorAll('#calendar-ul .calendar-item input, #calendar-ul .calendar-item button, #calendar-ul .calendar-item [tabindex]:not([tabindex="-1"]):not(.calendar-item)').length);
  ok(cf === 0, `Kalender-Sidebar: keine fokussierbaren Kind-Elemente (gefunden: ${cf})`);
  const c1 = await p.evaluate(() => { const c = document.querySelector('#calendar-ul'); c.focus(); const it = document.getElementById(c.getAttribute('aria-activedescendant')); return it && it.classList.contains('checked'); });
  await p.keyboard.press(' '); await p.waitForTimeout(250);
  const c2 = await p.evaluate(() => { const c = document.querySelector('#calendar-ul'); const it = document.getElementById(c.getAttribute('aria-activedescendant')); return { checked: it && it.classList.contains('checked'), label: it && it.getAttribute('aria-label') }; });
  ok(c1 !== c2.checked, `Kalender-Sidebar: Leertaste blendet Kalender ein/aus (${c1} -> ${c2.checked})`);
  ok(/eingeblendet|ausgeblendet/.test(c2.label || ''), `Kalender-Sidebar: aria-label nennt Status ("${c2.label}")`);
} else {
  console.log('\n[4] KALENDER-SIDEBAR: uebersprungen (<2 Kalender geseedet)');
}

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
  // Listbox fokussieren (erste Option aktiv) und per Leertaste abhaken (faellt aus der Liste).
  await p.evaluate(() => document.querySelector('#task-list').focus());
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
    const a = document.activeElement, list = document.querySelector('#task-list');
    const ad = list && list.getAttribute('aria-activedescendant');
    return { onBody: a === document.body || a === document.documentElement,
             onList: !!(list && a === list && ad && document.getElementById(ad)),
             onHint: !!(a && a.classList && a.classList.contains('hint')),
             remaining: document.querySelectorAll('.task-item').length };
  });
  ok(!after.onBody, `Fokus nicht auf <body> nach Abhaken (Liste: ${after.onList}, Hint: ${after.onHint})`);
  ok(after.onList || after.onHint, 'Fokus auf der Listbox (aktive Option gesetzt) oder Leer-Hinweis');
  if (after.remaining > 1) {
    const adBefore = await p.evaluate(() => document.querySelector('#task-list').getAttribute('aria-activedescendant'));
    await p.keyboard.press('ArrowDown'); await p.waitForTimeout(200);
    const adAfter = await p.evaluate(() => document.querySelector('#task-list').getAttribute('aria-activedescendant'));
    ok(adBefore && adAfter && adBefore !== adAfter, `ArrowDown funktioniert nach dem Abhaken weiter (${adBefore} -> ${adAfter})`);
  }
}

// --- Test 6: Sidebar (Aufgabenlisten) navigierbar wie die Hauptliste + Toolbar ---
await p.goto(`${B}/?_task=tasks`, { waitUntil: 'networkidle' });
const sidebarReady = await p.waitForFunction(() => document.querySelectorAll('#tasklist-ul .tasklist-item').length > 1, { timeout: 12000 }).then(() => true).catch(() => false);
if (sidebarReady) {
  await probeNavigableList('#tasklist-ul', '.tasklist-item', 'AUFGABENLISTEN-SIDEBAR', false);
  const sf = await p.evaluate(() => document.querySelectorAll('#tasklist-ul .tasklist-item input, #tasklist-ul .tasklist-item button, #tasklist-ul .tasklist-item [tabindex]:not([tabindex="-1"]):not(.tasklist-item)').length);
  ok(sf === 0, `Sidebar: keine fokussierbaren Kind-Elemente (gefunden: ${sf})`);
  // Listbox fokussieren (erste Option aktiv), Leertaste blendet sie ein/aus
  const t1 = await p.evaluate(() => { const c = document.querySelector('#tasklist-ul'); c.focus(); const it = document.getElementById(c.getAttribute('aria-activedescendant')); return it && it.classList.contains('checked'); });
  await p.keyboard.press(' '); await p.waitForTimeout(250);
  const t2 = await p.evaluate(() => { const c = document.querySelector('#tasklist-ul'); const it = document.getElementById(c.getAttribute('aria-activedescendant')); return { checked: it && it.classList.contains('checked'), label: it && it.getAttribute('aria-label') }; });
  ok(t1 !== t2.checked, `Leertaste blendet Liste ein/aus (checked ${t1} -> ${t2.checked})`);
  ok(/eingeblendet|ausgeblendet/.test(t2.label || ''), `Sidebar-Item aria-label nennt Status ("${t2.label}")`);
} else {
  console.log('\n[6] SIDEBAR: uebersprungen (nur eine Aufgabenliste geseedet)');
}

// Toolbar: Filter-Dropdown + Neue-Aufgabe neben Sortierung, kein show-completed mehr
console.log('\n[6b] TOOLBAR: Filter-Dropdown + Layout');
const tb = await p.evaluate(() => {
  const f = document.querySelector('#task-filter'), sort = document.querySelector('#task-sort'), newBtn = document.querySelector('#btn-new-task');
  return {
    hasFilter: !!f, opts: f ? [...f.options].map(o => o.value) : [],
    sameBar: !!(f && sort && newBtn && f.closest('.toolbar') === sort.closest('.toolbar') && sort.closest('.toolbar') === newBtn.closest('.toolbar')),
    showCompleted: !!document.querySelector('#show-completed'),
  };
});
ok(tb.hasFilter && tb.opts.join(',') === 'open,completed,all', `Filter-Dropdown mit Offen/Erledigt/Alle (${tb.opts.join(',')})`);
ok(tb.sameBar, 'Filter + Sortierung + Neue Aufgabe in einer Toolbar');
ok(!tb.showCompleted, 'Alte "Erledigte anzeigen"-Checkbox entfernt');

await b.close();
console.log(`\n${fails.length ? 'FEHLGESCHLAGEN: ' + fails.length : 'ALLE TESTS GRUEN'}`);
process.exit(fails.length ? 1 : 0);
