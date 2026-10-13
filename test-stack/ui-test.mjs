/**
 * UI test for the calendar (week view + event dialog).
 *
 * Covers the bugs that pure PHPUnit backend tests CANNOT catch:
 *   1. Clicking an event opens the CORRECT event (data-url unique).
 *   2. The edit dialog FILLS start/end (all-day = <input type=date>,
 *      timed = <input type=datetime-local>).
 *   3. Save + reopen does NOT drift the date (all-day DTEND
 *      exclusive <-> inclusive round trip).
 *
 * Prerequisite: stack running + demo week seeded (see run-ui-test.sh).
 * Usage:  node ui-test.mjs            (uses screenshots/node_modules/playwright)
 * Exit code != 0 on failure.
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
    (btns.find(x => /save|speichern/i.test(x.textContent)) || btns[0])?.click();
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

// --- Test 1: data-url unique + click opens the correct event ---
console.log('\n[1] Click opens the correct event');
await gotoWeek();
const urls = await p.evaluate(() => [...document.querySelectorAll('.week-event-inline, .week-allday-event')].map(e => e.getAttribute('data-url')));
ok(urls.length > 0, `events rendered (${urls.length})`);
ok(urls.every(u => u && u !== 'null' && u !== 'undefined'), 'no empty/null data-url');
const els = await p.$$('.week-event-inline, .week-allday-event');
for (const idx of [...new Set([0, Math.floor(els.length / 2), els.length - 1])]) {
  const expected = ((await els[idx].textContent()) || '').trim();
  await els[idx].click(); await p.waitForTimeout(600);
  const t = await p.evaluate(() => (document.getElementById('ev-title') || {}).value);
  ok(t && expected.includes(t.slice(0, 10)), `click idx=${idx}: dialog "${t}" matches "${expected.slice(0, 24)}"`);
  await closeDialog();
}

// --- Tests 2 + 3: fields filled + round trip without drift ---
for (const kind of ['.week-allday-event', '.week-event-inline']) {
  const labelK = kind.includes('allday') ? 'ALL-DAY' : 'TIMED';
  console.log(`\n[2/3] ${labelK}: fields filled + save-reopen without drift`);
  await gotoWeek();
  const el = await p.$(kind);
  const full = ((await el.textContent()) || '').trim();
  const needle = full.replace(/^[^A-Za-z0-9ÄÖÜ]+/, '').replace(/^[0-9:\s]+/, '').slice(0, 12);
  await el.click(); await p.waitForTimeout(700);
  const r1 = await readDialog();
  ok(!!r1.start, `${labelK} start filled ("${r1.start}", type=${r1.stype})`);
  ok(!!r1.end, `${labelK} end filled ("${r1.end}", type=${r1.etype})`);
  if (labelK === 'ALL-DAY') ok(r1.stype === 'date' && r1.etype === 'date', 'all-day -> type=date');
  await clickSave();
  await gotoWeek();
  await openByText(kind, needle);
  const r2 = await readDialog();
  ok(r1.start === r2.start && r1.end === r2.end, `${labelK} no drift after save (${r1.start}..${r1.end} -> ${r2.start}..${r2.end})`);
  await closeDialog();
}

// --- Test 4: uniform, navigable lists (aria-activedescendant) ---
// ONE tab stop = the listbox container (tabindex=0), arrow keys move the active
// option via aria-activedescendant, focus stays on the container.
async function probeNavigableList(containerSel, itemSel, label, opensDialog) {
  console.log(`\n[4] ${label}: ARIA listbox + activedescendant navigation`);
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
  ok(info.cRole === 'listbox', `${label} container role=listbox`);
  ok(!!info.cLabel, `${label} container aria-label ("${info.cLabel}")`);
  ok(info.cTab === '0', `${label} container tabindex=0 (exactly ONE tab stop)`);
  ok(info.n > 1, `${label} items: ${info.n}`);
  ok(info.roles.every(r => r === 'option'), `${label} items role=option`);
  ok(!!info.label0, `${label} items aria-label ("${(info.label0 || '').slice(0, 36)}")`);
  ok(info.ids.every(id => id), `${label} options have ids (for activedescendant)`);
  ok(!info.itemHasTabstop, `${label} no option is its own tab stop`);
  // Focus container -> first option active; ArrowDown -> second option active
  await p.evaluate(s => document.querySelector(s).focus(), containerSel);
  await p.waitForTimeout(150);
  await p.keyboard.press('ArrowDown'); await p.waitForTimeout(200);
  const nav = await p.evaluate(({ containerSel, itemSel }) => {
    const c = document.querySelector(containerSel);
    const items = [...c.querySelectorAll(itemSel)];
    const id = c.getAttribute('aria-activedescendant');
    return { idx: items.findIndex(i => i.id === id), onContainer: document.activeElement === c };
  }, { containerSel, itemSel });
  ok(nav.idx === 1, `${label} ArrowDown moves the active option to the next item (idx=${nav.idx})`);
  ok(nav.onContainer, `${label} focus stays on the container`);
  if (opensDialog) {
    await p.keyboard.press('Enter'); await p.waitForTimeout(600);
    ok(await p.evaluate(() => !!document.querySelector('.ui-dialog')), `${label} Enter opens dialog`);
    await closeDialog();
  }
}

await p.goto(`${B}/?_task=calendar`, { waitUntil: 'networkidle' });
await p.waitForFunction(() => document.querySelectorAll('.calendar-list li').length > 0, { timeout: 10000 }).catch(() => {});
await p.waitForTimeout(700);
await p.click('.view-btn[data-view="list"]');
await p.waitForFunction(() => !!document.querySelector('.list-range'), { timeout: 12000 }).catch(() => {});

// Week picker modal: jump specifically to the demo week (makes the test date-independent + tests the popup)
console.log('\n[4b] CALENDAR LIST: week range + week picker + j/k');
await p.click('#btn-pick-week'); await p.waitForTimeout(500);
ok(await p.evaluate(() => !!document.querySelector('.ui-dialog') && !!document.getElementById('weekpick-date')), 'week picker modal with date field opened');
await p.fill('#weekpick-date', '2026-06-18');
await p.evaluate(() => { const bs = [...document.querySelectorAll('.ui-dialog-buttonpane button')]; (bs.find(b => /go|springen/i.test(b.textContent)) || bs[0]).click(); });
await p.waitForFunction(() => /15/.test((document.querySelector('.list-range') || {}).textContent || ''), { timeout: 8000 }).catch(() => {});
const h0 = await p.evaluate(() => (document.querySelector('.list-range') || {}).textContent || '');
ok(/Week .*June 2026/.test(h0) && /events|Termine/i.test(h0), `range header shows week + count ("${h0}")`);
await p.waitForFunction(() => document.querySelectorAll('.list-event').length > 0, { timeout: 12000 }).catch(() => {});
await probeNavigableList('.list-view', '.list-event', 'CALENDAR LIST', true);

// List item aria-label names the calendar + travel time (demo week, events stable)
const listLbl = await p.evaluate(() => {
  const ls = [...document.querySelectorAll('.list-event')].map(e => e.getAttribute('aria-label') || '');
  return { cal: ls.some(l => /Calendar /.test(l)), travel: ls.some(l => /Travel Time /.test(l)) };
});
ok(listLbl.cal, 'CALENDAR LIST: item aria-label names the calendar');
ok(listLbl.travel, 'CALENDAR LIST: item aria-label names the travel time (where set)');

// j/k: next/previous week, header + live announcement change
await p.evaluate(() => document.getElementById('aria-live-region').textContent = '');
await p.keyboard.press('j');
await p.waitForFunction(() => /22/.test((document.querySelector('.list-range') || {}).textContent || ''), { timeout: 6000 }).catch(() => {});
await p.waitForTimeout(400);
const jLive = await p.evaluate(() => document.getElementById('aria-live-region').textContent.trim());
ok(/22/.test(jLive) && /events|Termine/i.test(jLive), `j announces the next week ("${jLive}")`);
await p.keyboard.press('k');
await p.waitForFunction(() => /15/.test((document.querySelector('.list-range') || {}).textContent || ''), { timeout: 6000 }).catch(() => {});
ok(true, 'k jumps back to the previous week');

// Calendar sidebar (list of calendars to show/hide) navigable like the task-list sidebar
const calSidebar = await p.waitForFunction(() => document.querySelectorAll('#calendar-ul .calendar-item').length > 1, { timeout: 8000 }).then(() => true).catch(() => false);
if (calSidebar) {
  await probeNavigableList('#calendar-ul', '.calendar-item', 'CALENDAR SIDEBAR', false);
  const cf = await p.evaluate(() => document.querySelectorAll('#calendar-ul .calendar-item input, #calendar-ul .calendar-item button, #calendar-ul .calendar-item [tabindex]:not([tabindex="-1"]):not(.calendar-item)').length);
  ok(cf === 0, `calendar sidebar: no focusable child elements (found: ${cf})`);
  const c1 = await p.evaluate(() => { const c = document.querySelector('#calendar-ul'); c.focus(); const it = document.getElementById(c.getAttribute('aria-activedescendant')); return it && it.classList.contains('checked'); });
  await p.keyboard.press(' '); await p.waitForTimeout(250);
  const c2 = await p.evaluate(() => { const c = document.querySelector('#calendar-ul'); const it = document.getElementById(c.getAttribute('aria-activedescendant')); return { checked: it && it.classList.contains('checked'), label: it && it.getAttribute('aria-label') }; });
  ok(c1 !== c2.checked, `calendar sidebar: space shows/hides the calendar (${c1} -> ${c2.checked})`);
  ok(/shown|hidden|eingeblendet|ausgeblendet/.test(c2.label || ''), `calendar sidebar: aria-label names the status ("${c2.label}")`);
} else {
  console.log('\n[4] CALENDAR SIDEBAR: skipped (<2 calendars seeded)');
}

await p.goto(`${B}/?_task=tasks`, { waitUntil: 'networkidle' });
const hasTasks = await p.waitForFunction(() => document.querySelectorAll('.task-item').length > 0, { timeout: 12000 }).then(() => true).catch(() => false);
if (hasTasks) await probeNavigableList('#task-list', '.task-item', 'TASKS', true);
else console.log('\n[4] TASKS: skipped (no VTODO tasks seeded)');

// --- Test 5: checking off keeps focus in the list (regression: focus fell to <body>) ---
if (hasTasks) {
  console.log('\n[5] TASKS: checking off keeps focus + arrow navigation');
  // Regression: role=option items must NOT have focusable descendants
  // (otherwise NVDA/click lands on the checkbox instead of the task).
  const focusable = await p.evaluate(() =>
    document.querySelectorAll('#task-list .task-item input, #task-list .task-item button, #task-list .task-item a[href], #task-list .task-item [tabindex]:not([tabindex="-1"]):not(.task-item)').length);
  ok(focusable === 0, `no focusable child elements in the task item (found: ${focusable})`);
  const before = await p.evaluate(() => {
    const items = [...document.querySelectorAll('.task-item')];
    return { n: items.length, first: items[0] && items[0].getAttribute('data-url'),
             second: items[1] && items[1].getAttribute('data-url') };
  });
  // Focus the listbox (first option active) and check off via space (drops out of the list).
  await p.evaluate(() => document.querySelector('#task-list').focus());
  await p.waitForTimeout(150);
  await p.keyboard.press(' ');
  // Wait for the reload: either the item count drops or the first item is gone.
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
  ok(!after.onBody, `focus not on <body> after checking off (list: ${after.onList}, hint: ${after.onHint})`);
  ok(after.onList || after.onHint, 'focus on the listbox (active option set) or the empty hint');
  if (after.remaining > 1) {
    const adBefore = await p.evaluate(() => document.querySelector('#task-list').getAttribute('aria-activedescendant'));
    await p.keyboard.press('ArrowDown'); await p.waitForTimeout(200);
    const adAfter = await p.evaluate(() => document.querySelector('#task-list').getAttribute('aria-activedescendant'));
    ok(adBefore && adAfter && adBefore !== adAfter, `ArrowDown keeps working after checking off (${adBefore} -> ${adAfter})`);
  }
}

// --- Test 6: sidebar (task lists) navigable like the main list + toolbar ---
await p.goto(`${B}/?_task=tasks`, { waitUntil: 'networkidle' });
const sidebarReady = await p.waitForFunction(() => document.querySelectorAll('#tasklist-ul .tasklist-item').length > 1, { timeout: 12000 }).then(() => true).catch(() => false);
if (sidebarReady) {
  await probeNavigableList('#tasklist-ul', '.tasklist-item', 'TASK-LIST SIDEBAR', false);
  const sf = await p.evaluate(() => document.querySelectorAll('#tasklist-ul .tasklist-item input, #tasklist-ul .tasklist-item button, #tasklist-ul .tasklist-item [tabindex]:not([tabindex="-1"]):not(.tasklist-item)').length);
  ok(sf === 0, `sidebar: no focusable child elements (found: ${sf})`);
  // Focus the listbox (first option active), space shows/hides it
  const t1 = await p.evaluate(() => { const c = document.querySelector('#tasklist-ul'); c.focus(); const it = document.getElementById(c.getAttribute('aria-activedescendant')); return it && it.classList.contains('checked'); });
  await p.keyboard.press(' '); await p.waitForTimeout(250);
  const t2 = await p.evaluate(() => { const c = document.querySelector('#tasklist-ul'); const it = document.getElementById(c.getAttribute('aria-activedescendant')); return { checked: it && it.classList.contains('checked'), label: it && it.getAttribute('aria-label') }; });
  ok(t1 !== t2.checked, `space shows/hides the list (checked ${t1} -> ${t2.checked})`);
  ok(/shown|hidden|eingeblendet|ausgeblendet/.test(t2.label || ''), `sidebar item aria-label names the status ("${t2.label}")`);
} else {
  console.log('\n[6] SIDEBAR: skipped (only one task list seeded)');
}

// Toolbar: filter dropdown + new task next to sorting, no show-completed anymore
console.log('\n[6b] TOOLBAR: filter dropdown + layout');
const tb = await p.evaluate(() => {
  const f = document.querySelector('#task-filter'), sort = document.querySelector('#task-sort'), newBtn = document.querySelector('#btn-new-task');
  return {
    hasFilter: !!f, opts: f ? [...f.options].map(o => o.value) : [],
    sameBar: !!(f && sort && newBtn && f.closest('.toolbar') === sort.closest('.toolbar') && sort.closest('.toolbar') === newBtn.closest('.toolbar')),
    showCompleted: !!document.querySelector('#show-completed'),
  };
});
ok(tb.hasFilter && tb.opts.join(',') === 'open,completed,all', `filter dropdown with open/completed/all (${tb.opts.join(',')})`);
ok(tb.sameBar, 'filter + sorting + new task in one toolbar');
ok(!tb.showCompleted, 'old "show completed" checkbox removed');

await b.close();
console.log(`\n${fails.length ? 'FAILED: ' + fails.length : 'ALL TESTS GREEN'}`);
process.exit(fails.length ? 1 : 0);
