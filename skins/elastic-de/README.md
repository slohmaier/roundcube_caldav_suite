# elastic-de skin override — Accessibility (Screen Reader)

Site-Skin `elastic-de` (extends `elastic`). Enthält den Accessibility-Fix für die
**Kontaktliste** (CardDAV/caldav_suite) unter NVDA.

## Problem

In der Kontaktliste sprang NVDA mit Pfeil-runter nicht zum nächsten Kontakt
("toolbar button unavailable" / nur "Textrahmen"); man musste manuell mit
NVDA+Leertaste (CapsLock+Space) in den Fokus-Modus schalten. Die **Mailliste**
funktionierte dagegen automatisch.

## Ursache

Roundcube rendert die Kontaktliste als ARIA-Listbox (`role="listbox"`, Zeilen
`role="option"`), legt den DOM-Fokus aber auf eine `<td>`-Zelle statt per
`aria-activedescendant` auf die aktive Option. NVDA findet keine aktive Option und
bleibt im Browse-Modus → Pfeile greifen ins Leere. Zusätzlich war die
Kontakt-Tabelle **namenlos** (kein `aria-labelledby`) → NVDA las nur "Textrahmen".
Die Mailliste ist dagegen eine schlichte Tabelle **mit** `aria-labelledby` und
einem Scroller mit `tabindex="-1"`.

## Fix (templates/addressbook.html)

Kontaktliste an die funktionierende Mailliste angeglichen:
- `role="listbox"` an der Tabelle entfernt (schlichte Tabelle wie `#messagelist`)
- `aria-labelledby="aria-label-contactslist"` an die Tabelle (Tabelle benannt)
- Scroller-Div: `id="contacts-content"` + `tabindex="-1"` (wie `#messagelist-content`)

## WICHTIG: Verzeichnis-Permissions

Roundcube läuft als `www-data`. Liegt der Skin außerhalb des Images (Bind-Mount)
und sind Unterverzeichnisse `750 root:root`, kann `www-data` sie **nicht betreten**
und Roundcube lädt **still** das Stock-Template — jede Override-Änderung verpufft
wirkungslos und ohne Fehlermeldung. Nach jeder Änderung sicherstellen:

    chmod -R a+rX <skin-verzeichnis>

Gegenprobe im Container:

    su -s /bin/sh www-data -c 'test -r .../skins/elastic-de/templates/addressbook.html && echo OK'
