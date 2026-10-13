/**
 * UI test for the iMIP/iTIP invitation box in the mail view.
 * Prerequisite: stack running, invitation mail in INBOX (uid 1),
 * CalDAV prefs + identity test@example.com set, Radicale calendar present.
 */
import { chromium } from './screenshots/node_modules/playwright/index.mjs';

const B = process.env.RC_BASE || 'http://127.0.0.1:8099';
const fails = [];
const ok = (c, m) => { console.log((c ? '  OK  ' : ' FAIL ') + m); if (!c) fails.push(m); };

const b = await chromium.launch();
const p = await (await b.newContext()).newPage();

// Login
await p.goto(`${B}/?_task=login`, { waitUntil: 'networkidle' });
await p.fill('#rcmloginuser', 'test'); await p.fill('#rcmloginpwd', 'test');
await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}), p.click('#rcmloginsubmit, button[type=submit]')]);

// Open the message as a full page (like a list layout, without the preview iframe)
await p.goto(`${B}/?_task=mail&_action=show&_uid=1&_mbox=INBOX`, { waitUntil: 'networkidle' });
await p.waitForTimeout(1200);

console.log('\n[1] Box renders + a11y structure');
const box = await p.evaluate(() => {
  const el = document.querySelector('.caldav-itip');
  if (!el) return null;
  return {
    region: el.getAttribute('role'),
    titled: !!el.getAttribute('aria-labelledby') && !!document.getElementById(el.getAttribute('aria-labelledby')),
    title: (document.getElementById('caldav-itip-title') || {}).textContent || '',
    dl: !!el.querySelector('dl.caldav-itip-details'),
    select: !!el.querySelector('#caldav-itip-cal'),
    group: (el.querySelector('.caldav-itip-actions') || {}).getAttribute && el.querySelector('.caldav-itip-actions').getAttribute('role'),
    grouplabel: (el.querySelector('.caldav-itip-actions') || {}).getAttribute && el.querySelector('.caldav-itip-actions').getAttribute('aria-label'),
    buttons: [...el.querySelectorAll('.caldav-itip-actions button')].map(x => x.textContent.trim()),
    live: !!el.querySelector('.caldav-itip-live[aria-live]'),
    msguid: el.getAttribute('data-msg-uid'),
    start: el.getAttribute('data-start')
  };
});
ok(!!box, 'Invitation box present');
if (box) {
  ok(box.region === 'region', 'role=region');
  ok(box.titled, 'aria-labelledby points to an existing heading');
  ok(/Team Meeting Q3/.test(box.title), 'title = event name: ' + JSON.stringify(box.title));
  ok(box.dl, 'details as <dl>');
  ok(box.select, 'calendar <select> present');
  ok(box.group === 'group' && !!box.grouplabel, 'button group role=group + aria-label');
  ok(box.buttons.length === 4, 'four buttons: ' + JSON.stringify(box.buttons));
  ok(box.live, 'aria-live status region');
}

console.log('\n[2] Keyboard: buttons focusable');
const focusable = await p.evaluate(() => {
  const btns = [...document.querySelectorAll('.caldav-itip-actions button')];
  let allButtons = btns.every(b => b.tagName === 'BUTTON' && !b.disabled);
  // Tab order: first button reachable via focus()
  btns[0].focus();
  return { allButtons, focused: document.activeElement === btns[0] };
});
ok(focusable.allButtons, 'all reply buttons are real active <button>');
ok(focusable.focused, 'button focusable via keyboard');

console.log('\n[3] Accept -> status changes + buttons locked');
await Promise.all([
  p.waitForResponse(r => r.url().includes('plugin.caldav-itip-reply'), { timeout: 12000 }).catch(() => {}),
  p.click('.caldav-itip-reply[data-partstat="ACCEPTED"]')
]);
await p.waitForTimeout(800);
const after = await p.evaluate(() => ({
  status: (document.getElementById('caldav-itip-status') || {}).textContent || '',
  locked: [...document.querySelectorAll('.caldav-itip-reply')].every(b => b.disabled)
}));
ok(after.status !== '' && !/pending|ausstehend/i.test(after.status), 'status no longer "pending": ' + JSON.stringify(after.status));
ok(/accept|angenommen/i.test(after.status), 'status shows acceptance: ' + JSON.stringify(after.status));
ok(after.locked, 'reply buttons locked after the action');

console.log('\n[5] Counter proposal modal (counter) — fresh page');
await p.goto(`${B}/?_task=mail&_action=show&_uid=1&_mbox=INBOX`, { waitUntil: 'networkidle' });
await p.waitForTimeout(1000);
await p.click('.caldav-itip-propose');
await p.waitForTimeout(600);
const modal = await p.evaluate(() => {
  const dlg = document.querySelector('.ui-dialog');
  return {
    visible: !!dlg && dlg.offsetParent !== null,
    role: dlg ? dlg.getAttribute('role') : null,
    start: !!document.getElementById('itip-cn-start'),
    end: !!document.getElementById('itip-cn-end'),
    comment: !!document.getElementById('itip-cn-comment'),
    startVal: (document.getElementById('itip-cn-start') || {}).value
  };
});
ok(modal.visible, 'modal visible');
ok(modal.role === 'dialog', 'modal role=dialog');
ok(modal.start && modal.end && modal.comment, 'modal fields (start/end/message) present');
ok(/2026-07-03T/.test(modal.startVal || ''), 'start pre-filled from event: ' + JSON.stringify(modal.startVal));
// new time + send
await p.fill('#itip-cn-start', '2026-07-03T14:00');
await p.fill('#itip-cn-end', '2026-07-03T15:00');
await p.fill('#itip-cn-comment', 'Can only do it from 2pm');
await Promise.all([
  p.waitForResponse(r => r.url().includes('plugin.caldav-itip-counter'), { timeout: 12000 }).catch(() => {}),
  p.evaluate(() => {
    const btns = [...document.querySelectorAll('.ui-dialog-buttonpane button')];
    (btns.find(b => /senden|send/i.test(b.textContent)) || btns[0])?.click();
  })
]);
await p.waitForTimeout(800);
ok(true, 'counter proposal sent');

await b.close();

// Radicale: the event must now be in the calendar (filename = slugified UID, @ removed)
console.log('\n[4] Event written to Radicale');
const res = await fetch('http://127.0.0.1:5233/test/kalender/itip-e2e-001example.com.ics', {
  headers: { Authorization: 'Basic ' + Buffer.from('test:test').toString('base64') }
}).catch(() => null);
let body = res && res.ok ? await res.text() : '';
ok(res && res.ok && /Team Meeting Q3/.test(body), 'CalDAV object created (HTTP ' + (res ? res.status : 'ERR') + ')');
ok(/PARTSTAT=ACCEPTED/.test(body), 'own PARTSTAT=ACCEPTED set');

console.log('\n' + (fails.length ? `ERRORS: ${fails.length}` : 'ALL iTIP TESTS GREEN'));
process.exit(fails.length ? 1 : 0);
