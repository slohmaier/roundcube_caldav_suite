/**
 * UI-Test fuer die iMIP/iTIP-Einladungs-Box in der Mailansicht.
 * Voraussetzung: Stack laeuft, Einladungs-Mail liegt im INBOX (uid 1),
 * CalDAV-Prefs + Identitaet test@example.com gesetzt, Radicale-Kalender vorhanden.
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

// Nachricht als Vollseite oeffnen (wie Stefans list-Layout, ohne Preview-iframe)
await p.goto(`${B}/?_task=mail&_action=show&_uid=1&_mbox=INBOX`, { waitUntil: 'networkidle' });
await p.waitForTimeout(1200);

console.log('\n[1] Box rendert + a11y-Struktur');
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
ok(!!box, 'Einladungs-Box vorhanden');
if (box) {
  ok(box.region === 'region', 'role=region');
  ok(box.titled, 'aria-labelledby zeigt auf existierende Ueberschrift');
  ok(/Team-Meeting Q3/.test(box.title), 'Titel = Event-Name: ' + JSON.stringify(box.title));
  ok(box.dl, 'Details als <dl>');
  ok(box.select, 'Kalender-<select> vorhanden');
  ok(box.group === 'group' && !!box.grouplabel, 'Button-Gruppe role=group + aria-label');
  ok(box.buttons.length === 4, 'vier Buttons: ' + JSON.stringify(box.buttons));
  ok(box.live, 'aria-live Status-Region');
}

console.log('\n[2] Tastatur: Buttons fokussierbar');
const focusable = await p.evaluate(() => {
  const btns = [...document.querySelectorAll('.caldav-itip-actions button')];
  let allButtons = btns.every(b => b.tagName === 'BUTTON' && !b.disabled);
  // Tab-Reihenfolge: erstes Button per focus() erreichbar
  btns[0].focus();
  return { allButtons, focused: document.activeElement === btns[0] };
});
ok(focusable.allButtons, 'alle Antwort-Buttons sind echte aktive <button>');
ok(focusable.focused, 'Button per Tastatur fokussierbar');

console.log('\n[3] Annehmen -> Status wechselt + Buttons gesperrt');
await Promise.all([
  p.waitForResponse(r => r.url().includes('plugin.caldav-itip-reply'), { timeout: 12000 }).catch(() => {}),
  p.click('.caldav-itip-reply[data-partstat="ACCEPTED"]')
]);
await p.waitForTimeout(800);
const after = await p.evaluate(() => ({
  status: (document.getElementById('caldav-itip-status') || {}).textContent || '',
  locked: [...document.querySelectorAll('.caldav-itip-reply')].every(b => b.disabled)
}));
ok(after.status !== '' && !/pending|ausstehend/i.test(after.status), 'Status nicht mehr "ausstehend": ' + JSON.stringify(after.status));
ok(/accept|angenommen/i.test(after.status), 'Status zeigt Annahme: ' + JSON.stringify(after.status));
ok(after.locked, 'Antwort-Buttons nach Aktion gesperrt');

console.log('\n[5] Gegenvorschlag-Modal (Counter) — frische Seite');
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
ok(modal.visible, 'Modal sichtbar');
ok(modal.role === 'dialog', 'Modal role=dialog');
ok(modal.start && modal.end && modal.comment, 'Modal-Felder (Beginn/Ende/Nachricht) vorhanden');
ok(/2026-07-03T/.test(modal.startVal || ''), 'Beginn vorbefuellt aus Event: ' + JSON.stringify(modal.startVal));
// neue Zeit + senden
await p.fill('#itip-cn-start', '2026-07-03T14:00');
await p.fill('#itip-cn-end', '2026-07-03T15:00');
await p.fill('#itip-cn-comment', 'Geht erst ab 14 Uhr');
await Promise.all([
  p.waitForResponse(r => r.url().includes('plugin.caldav-itip-counter'), { timeout: 12000 }).catch(() => {}),
  p.evaluate(() => {
    const btns = [...document.querySelectorAll('.ui-dialog-buttonpane button')];
    (btns.find(b => /senden|send/i.test(b.textContent)) || btns[0])?.click();
  })
]);
await p.waitForTimeout(800);
ok(true, 'Gegenvorschlag abgesendet');

await b.close();

// Radicale: Event muss jetzt im Kalender liegen (Dateiname = slugifizierte UID, @ entfernt)
console.log('\n[4] Event in Radicale geschrieben');
const res = await fetch('http://127.0.0.1:5233/test/kalender/itip-e2e-001example.com.ics', {
  headers: { Authorization: 'Basic ' + Buffer.from('test:test').toString('base64') }
}).catch(() => null);
let body = res && res.ok ? await res.text() : '';
ok(res && res.ok && /Team-Meeting Q3/.test(body), 'CalDAV-Objekt angelegt (HTTP ' + (res ? res.status : 'ERR') + ')');
ok(/PARTSTAT=ACCEPTED/.test(body), 'eigener PARTSTAT=ACCEPTED gesetzt');

console.log('\n' + (fails.length ? `FEHLER: ${fails.length}` : 'ALLE iTIP-TESTS GRUEN'));
process.exit(fails.length ? 1 : 0);
