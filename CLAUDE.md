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
