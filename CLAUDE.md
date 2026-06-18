# roundcube_caldav_suite

## Überblick

Roundcube-Plugin für Kalender und Aufgaben via CalDAV. Verbindet sich als Client auf einen externen CalDAV-Server (Radicale, Baikal, Nextcloud etc.). Kein eigener Server, kein Kolab, keine Schwergewicht-Dependencies.

## Zielgruppe

- Selbsthoster mit bestehendem CalDAV-Server
- Roundcube-User die Kalender und Aufgaben im Webmail wollen
- Barrierefreie Nutzung (Screen Reader, Tastatur)

## Features

### Kalender

- CalDAV Discovery: PROPFIND auf Base-URL findet automatisch alle Kalender
- Mehrere Kalender gleichzeitig anzeigen (verschiedene Farben)
- Kalender ein/ausblenden per Checkbox
- Termine erstellen, bearbeiten, löschen
- Ganztägige Events und zeitgebundene Events
- Wiederkehrende Termine (RRULE) anzeigen
- Drag & Drop zum Verschieben (nur in Rasteransicht)
- Quick-Add: Klick auf Zeitslot erstellt neuen Termin

### Aufgaben

- VTODO-Support: Aufgaben erstellen, bearbeiten, abhaken, löschen
- Mehrere Aufgabenlisten (aus CalDAV entdeckt)
- Fälligkeitsdatum, Priorität, Notizen
- Sortierung: nach Fälligkeit, Priorität, Erstelldatum
- Erledigte Aufgaben ein/ausblenden

### Ansichten (wie iOS Kalender)

- **Monatsansicht** (Default): Raster mit Tagen, Events als farbige Punkte/Balken
- **Wochenansicht**: 7-Tage-Raster mit Zeitachse
- **Tagesansicht**: Einzelner Tag mit Zeitachse
- **Listenansicht / Agenda**: Chronologische Liste aller Termine (barrierefrei)
- Schnelles Umschalten zwischen Ansichten (Toolbar-Buttons, Keyboard Shortcuts)
- Ansichts-Präferenz wird pro User gespeichert

### Einstellungen

- CalDAV Server-URL (eine Base-URL, Kalender werden automatisch entdeckt)
- Benutzername + Passwort (getrennt vom IMAP-Login, da oft unterschiedlich)
- Standard-Ansicht wählen (Monat/Woche/Tag/Liste)
- Standard-Kalender für neue Termine
- Erster Wochentag (Montag/Sonntag)
- Zeitformat (24h/12h)

### Barrierefreiheit

- Listenansicht als vollständig barrierefreie Alternative
- Semantisches HTML: Listen, Tabellen mit Headers, Landmarks
- Alle Aktionen per Tastatur erreichbar
- ARIA-Labels und Live-Regions für dynamische Updates
- Kein Canvas, kein Drag-Pflicht (alles auch per Dialog bedienbar)
- Skip-Links zum Navigieren
- Screen Reader Announcements bei Ansichtswechsel
- Fokus-Management bei Dialogen
- Hoher Kontrast: Farben nie als einziges Unterscheidungsmerkmal

### Roundcube-Integration

- Zwei Menüpunkte in der Sidebar: "Kalender" und "Aufgaben"
- Mail-Integration: .ics-Anhänge direkt in Kalender importieren
- Meeting-Einladungen (iTip): Accept/Decline/Tentative direkt aus der Mail
- Kontakt-Verknüpfung: Teilnehmer-Emails werden mit CardDAV-Kontakten abgeglichen

## Technologie

### Backend (PHP)

- sabre/dav-client für CalDAV-Kommunikation (PROPFIND, REPORT, PUT, DELETE)
- sabre/vobject für iCalendar-Parsing (VEVENT, VTODO, VTIMEZONE)
- Roundcube Plugin API (rcube_plugin)
- Caching: CalDAV-Responses werden in Roundcube-DB gecached (konfigurierbare TTL)
- Kein eigener CalDAV-Server, kein LDAP, kein Memcached

### Frontend (JavaScript)

- Vanilla JS oder leichtgewichtiges Framework (kein React/Vue/Angular)
- CSS-Grid für Kalenderraster
- Responsive: Desktop + Tablet + Mobile
- Elastic Skin kompatibel (Roundcube Standard-Skin)
- Dark Mode Support

### Abhängigkeiten

- PHP >= 8.1
- Roundcube >= 1.6
- sabre/dav (Composer)
- sabre/vobject (Composer)
- Keine weiteren externen Abhängigkeiten
- Kein libkolab, kein Kolab-Server, kein LDAP

## Dateistruktur

```
roundcube_caldav_suite/
  caldav_suite.php              # Plugin-Hauptklasse
  config.inc.php.dist           # Default-Konfiguration
  composer.json                 # Dependencies (sabre/dav, sabre/vobject)
  
  lib/
    CalDAVClient.php            # CalDAV Discovery + CRUD
    CalendarBackend.php         # VEVENT-Logik
    TaskBackend.php             # VTODO-Logik
    Cache.php                   # DB-Caching Layer
  
  skins/
    elastic/
      templates/
        calendar.html           # Kalender-View Template
        tasklist.html           # Aufgaben-View Template
        settings.html           # Einstellungs-Seite
      styles/
        caldav_suite.css        # Styles für alle Views
  
  localization/
    de_DE.inc                   # Deutsch
    en_US.inc                   # English
  
  js/
    caldav_suite.js             # Hauptlogik
    calendar_view.js            # Kalenderraster + Ansichtswechsel
    task_view.js                # Aufgabenliste
    event_dialog.js             # Termin erstellen/bearbeiten Dialog
    task_dialog.js              # Aufgabe erstellen/bearbeiten Dialog
    a11y.js                     # Barrierefreiheits-Helfer
  
  SQL/
    postgres.initial.sql        # DB-Schema für Cache + User-Prefs
    mysql.initial.sql
    sqlite.initial.sql
```

## CalDAV-Protokoll

### Discovery
1. PROPFIND auf Base-URL mit `current-user-principal`
2. PROPFIND auf Principal mit `calendar-home-set`
3. PROPFIND auf Calendar-Home: alle Collections mit resourcetype `calendar`
4. Für jede Collection: `supported-calendar-component-set` prüfen (VEVENT vs VTODO)

### Sync
- `calendar-query` REPORT für Events in einem Zeitraum
- `sync-collection` REPORT für inkrementelle Updates (falls Server unterstützt)
- Fallback: Full PROPFIND + einzelne GET-Requests

### Schreiben
- PUT für neue/geänderte Events/Todos
- DELETE für Löschen
- If-Match Header für Konflikterkennung (ETags)

## Konfiguration (config.inc.php.dist)

```php
// CalDAV Server
$config['caldav_suite_url'] = '';           // z.B. https://radicale.home.slohmaier.de/stefan/
$config['caldav_suite_username'] = '';      // CalDAV Username
$config['caldav_suite_password'] = '';      // CalDAV Passwort (verschlüsselt gespeichert)

// Ansicht
$config['caldav_suite_default_view'] = 'month';  // month|week|day|list
$config['caldav_suite_first_day'] = 1;            // 0=Sonntag, 1=Montag
$config['caldav_suite_time_format'] = '24';       // 24|12

// Cache
$config['caldav_suite_cache_ttl'] = 300;          // Sekunden

// Kalender
$config['caldav_suite_default_calendar'] = '';     // ID des Standard-Kalenders
$config['caldav_suite_colors'] = array();          // Kalender-ID => Farbe
```

## Abgrenzung

Was dieses Plugin NICHT ist/macht:
- Kein CalDAV-Server (nutzt einen externen)
- Kein Kolab-Plugin-Fork (komplett neu geschrieben)
- Kein LDAP/ActiveDirectory für User-Verwaltung
- Keine Federation
- Kein Email-Versand für Einladungen (nutzt Roundcube's SMTP)
- Keine Ressourcen-Verwaltung (Räume, Beamer etc.)

## Lizenz

AGPLv3 (kompatibel mit Roundcube)

## Getestete CalDAV-Server

- Radicale
- Baikal
- Nextcloud (CalDAV-Endpoint)
- SOGo (CalDAV-Endpoint)
- iCloud (CalDAV-Endpoint)
- Google Calendar (CalDAV-Endpoint)

## Testing

### Test-Strategie

Drei Ebenen: Unit Tests, Integration Tests, E2E Tests.

### Unit Tests (PHPUnit)

Testen die PHP-Klassen isoliert ohne CalDAV-Server oder Roundcube.

- `CalDAVClient`: XML-Parsing von PROPFIND/REPORT-Responses, URL-Normalisierung, Header-Handling
- `CalendarBackend`: VEVENT-Parsing, RRULE-Expansion, Timezone-Konvertierung, Zeitraum-Filterung
- `TaskBackend`: VTODO-Parsing, Status-Mapping, Priorität-Sortierung
- `Cache`: TTL-Logik, Invalidierung, DB-Queries

Mocking: sabre/dav HTTP-Client wird gemockt, CalDAV-Responses als XML-Fixtures.

```
tests/
  Unit/
    CalDAVClientTest.php
    CalendarBackendTest.php
    TaskBackendTest.php
    CacheTest.php
  fixtures/
    propfind_discovery.xml
    calendar_query_events.xml
    calendar_query_todos.xml
    sync_collection.xml
    single_event.ics
    recurring_event.ics
    todo_with_due.ics
```

### Integration Tests (PHPUnit + Radicale)

Testen die CalDAV-Kommunikation gegen einen echten CalDAV-Server.

- Docker Compose startet einen Radicale-Container für Tests
- Tests erstellen/lesen/ändern/löschen echte Events und Todos
- Discovery-Flow testen: Base-URL → Principal → Calendar-Home → Collections
- Sync-Token-Flow testen: Initial-Sync → Änderung → Incremental-Sync
- Konflikterkennung: ETag-basiertes If-Match testen
- Timezone-Handling: Events in verschiedenen Zeitzonen erstellen und lesen

```
tests/
  Integration/
    DiscoveryTest.php
    EventCRUDTest.php
    TodoCRUDTest.php
    SyncTest.php
    TimezoneTest.php
  docker-compose.test.yml      # Radicale für Tests
  radicale-test-config/        # Radicale Config für Tests
```

### E2E Tests (Playwright oder Cypress)

Testen die komplette UI im Browser gegen Roundcube + Radicale.

- Login in Roundcube
- Kalender-Ansicht öffnen, Ansichten wechseln
- Termin erstellen, bearbeiten, löschen
- Aufgabe erstellen, abhaken, löschen
- Kalender ein/ausblenden
- Einstellungen ändern (CalDAV-URL, Credentials)

```
tests/
  E2E/
    calendar.spec.js
    tasks.spec.js
    settings.spec.js
  docker-compose.e2e.yml       # Roundcube + Radicale + DB für E2E
```

### Barrierefreiheits-Tests

- axe-core Integration in E2E-Tests (automatische WCAG-Checks)
- Keyboard-Navigation-Tests: Tab-Reihenfolge, Enter/Space-Aktivierung, Escape-Schließen
- Screen Reader Assertions: ARIA-Labels, Live-Regions, Announcements
- Fokus-Tests: Fokus nach Dialog-Öffnen/Schließen, nach Ansichtswechsel

### CI (GitHub Actions)

```yaml
# .github/workflows/test.yml
- PHP Unit Tests: PHP 8.1, 8.2, 8.3, 8.4
- Integration Tests: PHP 8.1 + Radicale Docker
- E2E Tests: Playwright + Roundcube Docker + Radicale Docker
- Accessibility: axe-core Scan
- Code Style: PHP-CS-Fixer
- Static Analysis: PHPStan Level 6
```

### Lokale Entwicklung

```bash
# Alles starten (Roundcube + Radicale + DB)
docker compose -f docker-compose.dev.yml up -d

# Unit Tests
composer test

# Integration Tests (braucht laufenden Radicale)
composer test:integration

# E2E Tests
npx playwright test

# Alles
composer test:all
```

## Manuelles Debug-Test-System (rc-test) — KONKRET, funktioniert

Isolierte Docker-Umgebung um Plugin-Bugs zu debuggen OHNE Live-Daten anzufassen.
Liegt auf polo unter `/home/stefan/rc-test/`. So wird es aufgesetzt:

### Aufbau

`docker-compose.yml` mit drei Services in einem eigenen Netz `rctest`:

- **rc-test-radicale** (`tomsquest/docker-radicale`) — CardDAV/CalDAV-Server,
  `127.0.0.1:5233:5232`. Mounts: `./radicale-config:/config:ro`, `./radicale-data:/data`.
- **rc-test-imap** (`greenmail/standalone`) — Dummy-IMAP/SMTP damit Roundcube startet,
  `127.0.0.1:3143:3143`, `GREENMAIL_OPTS=-Dgreenmail.setup.test.all -Dgreenmail.auth.disabled`.
- **rc-test-roundcube** (`roundcube/roundcubemail:latest`) — `127.0.0.1:8099:80`,
  `ROUNDCUBEMAIL_DB_TYPE=sqlite`, `DEFAULT_HOST=rc-test-imap`, `DEFAULT_PORT=3143`,
  `PLUGINS=caldav_suite`, `SKIN=elastic`. **Mount: `./caldav_suite:/var/www/html/plugins/caldav_suite`**
  (die zu testende Arbeitskopie des Plugins — hier rein editieren).

### Radicale-Setup (Testkontakt)

- `radicale-config/config`: `[auth] type=htpasswd htpasswd_encryption=plain`,
  `[storage] filesystem_folder=/data/collections`, `[rights] type=owner_only`.
- `radicale-config/users`: `test:test`.
- **`chmod -R a+rX radicale-config`** — sonst „Permission denied: /config/config" (Container-User).
- Testkontakt: `radicale-data/collections/collection-root/test/contacts/.Radicale.props`
  = `{"tag": "VADDRESSBOOK", "D:displayname": "Test Contacts"}` + eine `.vcf` (BEGIN:VCARD…).

### Caldav-Quelle erscheint nur mit USER-PREFS (nicht config.inc.php!)

Das Plugin liest URL/User/Passwort aus **`$this->rc->user->get_prefs()`**, NICHT aus
`config.inc.php`. Passwort wird `$rcmail->decrypt()`-t. Darum nach dem ersten Login
(Browser oder curl, `test`/`test`) die Prefs für den Test-User per PHP setzen:

```bash
docker compose exec -T rc-test-roundcube php <<'PHP'
<?php
define('INSTALL_PATH','/var/www/html/');
require_once INSTALL_PATH.'program/include/clisetup.php';
$rc=rcmail::get_instance();
$u=new rcube_user(1);              // user_id des Test-Logins
$u->save_prefs([
  'caldav_suite_url'      => 'http://rc-test-radicale:5232',  // Container-Name, internes Netz
  'caldav_suite_username' => 'test',
  'caldav_suite_password' => $rc->encrypt('test'),            // MUSS verschlüsselt sein
]);
PHP
```

Danach taucht die Quelle `caldav_<md5(bookUrl)>` im Adressbuch auf.

### Save via curl treiben (Bug reproduzieren)

```bash
J=/tmp/c.txt; B=http://127.0.0.1:8099
TOKEN=$(curl -s -c $J "$B/" | grep -oP 'name="_token"[^>]*value="\K[^"]+')
curl -s -b $J -c $J -X POST "$B/?_task=login&_action=login" \
  --data-urlencode "_token=$TOKEN" --data-urlencode "_user=test" --data-urlencode "_pass=test" -o /dev/null
SRC=$(curl -s -b $J "$B/?_task=addressbook" | grep -oE 'caldav_[a-f0-9]{32}' | head -1)
RT=$(curl -s -b $J "$B/?_task=addressbook" | grep -oP '"request_token":"\K[^"]+' | head -1)
curl -s -b $J -H "X-Requested-With: XMLHttpRequest" "$B/?_task=addressbook&_action=save" \
  --data-urlencode "_token=$RT" --data-urlencode "_source=$SRC" --data-urlencode "_cid=<md5(url)>" \
  --data-urlencode "_name=Max Testmann" --data-urlencode "_firstname=Max" --data-urlencode "_surname=Testmann" \
  --data-urlencode "_email[]=neu@example.com" --data-urlencode "_subtype_email[]=home" \
  --data-urlencode "_phone[]=+49 89 1" --data-urlencode "_subtype_phone[]=home" \
  --data-urlencode "_organization=Firma" --data-urlencode "_framed=1"
# Ergebnis prüfen: radicale-data/.../contacts/*.vcf ansehen
```

`_cid` = `md5($contact_url)` (Record-ID). Mehrere `_email[]`+`_subtype_email[]`-Paare
für Multi-Value.

### STOLPERSTEINE (haben Stunden gekostet)

1. **Debug-Logs world-writable machen.** Der Webprozess läuft als `www-data` (uid 33).
   Eine per `docker exec` (root) erzeugte Logdatei kann www-data NICHT beschreiben →
   `@file_put_contents()` schlägt **still** fehl und man denkt der Code laufe nicht.
   Fix: `touch /tmp/x.log && chmod 666 /tmp/x.log` IM Container, dann reinschreiben.
2. **OPcache.** Nach jeder PHP-Änderung `docker compose restart rc-test-roundcube`
   (sonst läuft alter Bytecode trotz geänderter Datei).
3. **Gebündelte Roundcube im Plugin.** `vendor/roundcube/roundcubemail/` (Dev-Artefakt,
   ~23 MB) bringt eine eigene `autoload_classmap.php` mit, die Core-Klassen (z.B.
   `rcmail_action_contacts_save`) auf die vendored Kopie mappt. War HIER nicht die
   Bug-Ursache, aber Lärm/Verwechslungsgefahr — **gehört nicht ins deploybare Plugin**
   (mit `composer install --no-dev` bauen, roundcubemail ausschließen).

### Gefundener Bug + Fix (2026-06-18) — Kontakt-Editieren

Roundcube liefert `update()`/`insert()` Multi-Value-Felder mit **Subtype-Keys**:
`save_data['email:home']`, `['email:work']`, `['phone:cell']` usw. — NICHT `['email']`.
Der alte Code las nur `$save_data['email']` → Key fehlte → `remove('EMAIL')` + nichts
neu → **Email/Telefon beim Speichern verworfen/gewiped**. Fix in
`lib/CardDAVAddressbook.php`: gemeinsamer `applySaveData()` + `collectSubtyped()`, der
alle `<col>` und `<col>:<subtype>`-Keys einsammelt und als vCard-`TYPE` setzt (FN/N/
EMAIL/TEL/ORG). Danach `readonly=false` wieder gesetzt (war als Notbremse `true`).
Verifiziert im rc-test-Stack: HOME+WORK-Email, Telefon, Org persistieren + round-trippen.

## Unterstützte Felder (Stand 2026-06-18)

Was das Plugin schreibt UND zurueckliest (Round-Trip getestet, Unit + gegen echtes
Radicale). Nicht behandelte Felder gehen beim Edit NICHT verloren — siehe "Fetch-Merge".

### Kontakte (vCard 3.0)

- Name: FN + N (Nachname, Vorname, middlename, prefix, suffix — alle 5 N-Teile)
- Spitzname (NICKNAME), Jobtitel (TITLE)
- Organisation + Abteilung (strukturiertes ORG: org;department)
- E-Mail mit Subtype (EMAIL;TYPE=HOME/WORK/...) — mehrere, je Subtype
- Telefon mit Subtype (TEL;TYPE=HOME/WORK/CELL/...)
- Adresse strukturiert (ADR;TYPE=...: street/locality/region/zipcode/country), mehrere
- Webseite (URL), Instant Messaging (IMPP; Subtype via X-SERVICE-TYPE)
- Geburtstag (BDAY), Jahrestag (ANNIVERSARY) — Datums-Normalisierung auf YYYY-MM-DD
- Notizen (NOTE)
- Firmenkontakte: leeres FN faellt auf ORG zurueck (Sortierung)
- coltypes erweitert -> Roundcube-Formular zeigt all diese Felder

### Termine (VEVENT, iCal RFC 5545)

- Titel (SUMMARY), Start/Ende (DTSTART/DTEND), Ganztags (VALUE=DATE, Ende exklusiv +1)
- Zeitzone via TZID; DST korrekt (Sommer +02:00 / Winter +01:00 fuer Europe/Berlin getestet)
- Ort (LOCATION), Beschreibung (DESCRIPTION), URL, STATUS, TRANSP, CLASS
- **Recurrence (RRULE)** — Lesen + Schreiben + beim Edit erhalten
- Kategorien (CATEGORIES), Erinnerung (VALARM/TRIGGER)
- Apple-Fahrtzeit: X-APPLE-TRAVEL-ADVISORY-BEHAVIOR (auto) / X-APPLE-TRAVEL-DURATION (Minuten)
- Geo-Location: X-APPLE-STRUCTURED-LOCATION + GEO (Apple-Maps-Fahrtzeit)

### Aufgaben (VTODO)

- Titel, Beschreibung, Ort, URL
- Faelligkeit (DUE), Start (DTSTART) — mit Zeitzone
- Prioritaet (high=1/medium=5/low=9/none), Fortschritt (PERCENT-COMPLETE)
- Status/Erledigt (STATUS + COMPLETED), Kategorien (CATEGORIES), Recurrence (RRULE)
- Abhaken (Toggle): holt VTODO, flippt nur Status, erhaelt alle anderen Felder

### Fetch-Merge-Edit (wichtig)

Kontakte modifizieren die bestehende vCard in-place; Events/Tasks holen beim Edit das
bestehende Objekt (`CalDAVClient::getObject`) und mergen nur die uebergebenen Felder
(`updateICalEvent` / `updateICalTodo`). So bleiben Felder, die das Formular nicht kennt
(RRULE, ATTENDEE, ORGANIZER, SEQUENCE, EXDATE, RELATED-TO, eigene X-Props), beim
Speichern **erhalten** statt verloren zu gehen. Verifiziert: Titel-Edit eines Serien-
termins behaelt die RRULE.

### Noch NICHT als eigene Formularfelder

ATTENDEE/ORGANIZER (Einladungen/iTip), EXDATE/RECURRENCE-ID-Ausnahmen, mehrere VALARMs,
PHOTO-Upload — werden beim Edit aber dank Fetch-Merge erhalten, nur nicht via UI editiert.

### Tests

`php vendor/bin/phpunit --testsuite unit` — 149 Unit-Tests (Backends, ContactCard,
CalendarObject, CardDAVAddressbook mit gemocktem Client, Recurrence, Zeitzonen/DST,
Fetch-Merge, alle Felder, Sonderzeichen-Roundtrip). Integration gegen echtes Radicale
im rc-test-Stack verifiziert.
