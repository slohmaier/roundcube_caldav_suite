# roundcube_caldav_suite

## Overview

Roundcube plugin for calendars, tasks (CalDAV), contacts (CardDAV) and meeting
invitations (iMIP/iTIP). It connects as a client to an external CalDAV server
(Radicale, Baikal, Nextcloud, etc.). No own server, no Kolab, no heavyweight
dependencies.

## Audience

- Self-hosters with an existing CalDAV server
- Roundcube users who want calendars and tasks in webmail
- Accessible usage (screen reader, keyboard)

## Features

### Calendar

- CalDAV discovery: PROPFIND on the base URL automatically finds all calendars
- Show multiple calendars at once (different colors)
- Show/hide calendars via checkbox; **visibility is stored per user**
- Create, edit, delete events
- All-day events and timed events
- Recurring events (RRULE)
- Drag & drop to move (grid view only)
- Quick add: clicking a time slot creates a new event
- **Interaction:** click selects, Enter / double-click / edit icon opens the editor,
  space toggles multi-selection (Ctrl+click)

### Tasks

- VTODO support: create, edit, check off, delete tasks
- Multiple task lists (discovered via CalDAV); **visibility stored per user**
- Due date, priority, notes
- **Due-date indicator** (calendar icon, red when overdue) in the list
- **Details (note/location) expand on selection**, screen reader reads them out
- Sorting: by due date, priority, creation date
- Show/hide completed tasks
- **Interaction:** click selects, Enter / edit icon opens the editor, space checks off

### Views (like iOS Calendar)

- **Month view** (default): grid with days, events as colored dots/bars
- **Week view**: 7-day grid with a time axis
- **Day view**: single day with a time axis
- **List view / agenda**: chronological list, **infinite scrolling** (large
  initial window one month back/forward, loads more on scroll), **days as their own
  navigable items** (Enter/double-click = new event on that day)
- Fast switching between views (toolbar buttons, keyboard shortcuts)
- View preference is stored per user

### Settings

- CalDAV server URL (one base URL, calendars are discovered automatically)
- Username + password (separate from the IMAP login, since these often differ)
- Default view (month/week/day/list)
- Default calendar for new events
- First day of week (Monday/Sunday)
- Time format (24h/12h)
- Location-search provider (Photon / Nominatim / Google Places) + Google API key

### Accessibility

- List view as a fully accessible alternative
- Semantic HTML: lists, tables with headers, landmarks
- All actions reachable via keyboard
- ARIA labels and live regions for dynamic updates
- No canvas, no mandatory drag (everything also available via dialogs)
- Skip links for navigation
- Screen-reader announcements on view change
- Focus management in dialogs
- High contrast: color is never the only distinguishing feature
- **Skip link** "Skip to calendar" when tabbing
- **Loading spinner** over the content + periodic "Loading..." announcement via aria-live
- Lists use Roundcube's native `.listing` semantics (role=listbox/option)

### Roundcube integration

- Two menu items in the sidebar: "Calendar" and "Tasks"
- Mail integration: import `.ics` attachments directly into the calendar
- Meeting invitations (iTip): accept/decline/tentative directly from the mail
- Contact linking: attendee emails are matched against CardDAV contacts

## Technology

### Backend (PHP)

- sabre/dav client for CalDAV communication (PROPFIND, REPORT, PUT, DELETE)
- sabre/vobject for iCalendar parsing (VEVENT, VTODO, VTIMEZONE)
- Roundcube plugin API (rcube_plugin)
- Caching: CalDAV responses are cached in the Roundcube DB (configurable TTL)
- No own CalDAV server, no LDAP, no Memcached

### Frontend (JavaScript)

- Vanilla JS or a lightweight framework (no React/Vue/Angular)
- CSS grid for the calendar grid
- Responsive: desktop + tablet + mobile
- Elastic skin compatible (Roundcube default skin)
- Dark mode support

### Dependencies

- PHP >= 8.1
- Roundcube >= 1.6
- sabre/dav (Composer)
- sabre/vobject (Composer)
- No further external dependencies
- No libkolab, no Kolab server, no LDAP

## File structure

```
roundcube_caldav_suite/
  caldav_suite.php              # main plugin class
  config.inc.php.dist           # default configuration
  composer.json                 # dependencies (sabre/dav, sabre/vobject)

  lib/
    CalDAVClient.php            # CalDAV discovery + CRUD
    CalendarBackend.php         # VEVENT logic
    TaskBackend.php             # VTODO logic
    Cache.php                   # DB caching layer

  skins/
    elastic/
      templates/
        calendar.html           # calendar view template
        tasklist.html           # tasks view template
      styles/
        caldav_suite.css        # styles for all views

  localization/
    de_DE.inc                   # German
    en_US.inc                   # English

  js/
    caldav_suite.js             # main logic
    calendar_view.js            # calendar grid + view switching
    task_view.js                # task list
    event_dialog.js             # create/edit event dialog
    task_dialog.js              # create/edit task dialog
    a11y.js                     # accessibility helpers
```

## CalDAV protocol

### Discovery
1. PROPFIND on the base URL with `current-user-principal`
2. PROPFIND on the principal with `calendar-home-set`
3. PROPFIND on the calendar home: all collections with resourcetype `calendar`
4. For each collection: check `supported-calendar-component-set` (VEVENT vs VTODO)

### Sync
- `calendar-query` REPORT for events in a time range
- `sync-collection` REPORT for incremental updates (if the server supports it)
- Fallback: full PROPFIND + individual GET requests

### Writing
- PUT for new/changed events/todos
- DELETE for removal
- If-Match header for conflict detection (ETags)

## Configuration (config.inc.php.dist)

```php
// CalDAV server
$config['caldav_suite_url'] = '';           // e.g. https://radicale.example.com/user/
$config['caldav_suite_username'] = '';      // CalDAV username
$config['caldav_suite_password'] = '';      // CalDAV password (stored encrypted)

// View
$config['caldav_suite_default_view'] = 'month';  // month|week|day|list
$config['caldav_suite_first_day'] = 1;            // 0=Sunday, 1=Monday
$config['caldav_suite_time_format'] = '24';       // 24|12

// Cache
$config['caldav_suite_cache_ttl'] = 300;          // seconds

// Calendar
$config['caldav_suite_default_calendar'] = '';     // ID of the default calendar
$config['caldav_suite_colors'] = array();          // calendar ID => color
```

## Scope

What this plugin is NOT / does not do:
- Not a CalDAV server (it uses an external one)
- Not a Kolab plugin fork (written completely from scratch)
- No LDAP/Active Directory for user management
- No federation
- No email sending for invitations (uses Roundcube's SMTP)
- No resource management (rooms, projectors, etc.)

## License

AGPLv3 (compatible with Roundcube)

## Tested CalDAV servers

- Radicale
- Baikal
- Nextcloud (CalDAV endpoint)
- SOGo (CalDAV endpoint)
- iCloud (CalDAV endpoint)
- Google Calendar (CalDAV endpoint)

## Testing

### Test strategy

Three levels: unit tests, integration tests, E2E tests.

### Unit tests (PHPUnit)

Test the PHP classes in isolation, without a CalDAV server or Roundcube.

- `CalDAVClient`: XML parsing of PROPFIND/REPORT responses, URL normalization, header handling
- `CalendarBackend`: VEVENT parsing, RRULE expansion, timezone conversion, time range filtering
- `TaskBackend`: VTODO parsing, status mapping, priority sorting
- `Cache`: TTL logic, invalidation, DB queries

Mocking: the sabre/dav HTTP client is mocked, CalDAV responses as XML fixtures.

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

### Integration tests (PHPUnit + Radicale)

Test the CalDAV communication against a real CalDAV server.

- Docker Compose starts a Radicale container for the tests
- Tests create/read/change/delete real events and todos
- Test the discovery flow: base URL -> principal -> calendar home -> collections
- Test the sync token flow: initial sync -> change -> incremental sync
- Conflict detection: ETag-based If-Match
- Timezone handling: create and read events in different timezones

```
tests/
  Integration/
    DiscoveryTest.php
    EventCRUDTest.php
    TodoCRUDTest.php
    SyncTest.php
    TimezoneTest.php
  docker-compose.test.yml      # Radicale for tests
  radicale-test-config/        # Radicale config for tests
```

### E2E tests (Playwright or Cypress)

Test the complete UI in the browser against Roundcube + Radicale.

- Log in to Roundcube
- Open the calendar view, switch views
- Create, edit, delete events
- Create, check off, delete tasks
- Show/hide calendars
- Change settings (CalDAV URL, credentials)

```
tests/
  E2E/
    calendar.spec.js
    tasks.spec.js
    settings.spec.js
  docker-compose.e2e.yml       # Roundcube + Radicale + DB for E2E
```

### Accessibility tests

- axe-core integration in E2E tests (automated WCAG checks)
- Keyboard navigation tests: tab order, Enter/space activation, Escape close
- Screen reader assertions: ARIA labels, live regions, announcements
- Focus tests: focus after dialog open/close, after view change

### CI (GitHub Actions)

```yaml
# .github/workflows/test.yml
- PHP unit tests: PHP 8.1, 8.2, 8.3, 8.4
- Integration tests: PHP 8.1 + Radicale Docker
- E2E tests: Playwright + Roundcube Docker + Radicale Docker
- Accessibility: axe-core scan
- Code style: PHP-CS-Fixer
- Static analysis: PHPStan level 6
```

### Local development

```bash
# Start everything (Roundcube + Radicale + DB)
docker compose -f docker-compose.dev.yml up -d

# Unit tests
composer test

# Integration tests (needs a running Radicale)
composer test:integration

# E2E tests
npx playwright test

# Everything
composer test:all
```

### Regenerating the example screenshots

`test-stack/screenshots/generate.sh` automatically creates screenshots
(month/week/day/list, tasks, contacts) from a demo stack with Playwright:

```bash
cd test-stack/screenshots
DOCKER="sudo docker" ./generate.sh
```

The PNGs land in `test-stack/screenshots/out/`. **For the repo**, after generating
copy `out/*.png` to `docs/` and commit (`.gitignore` covers `out/`; the repo
screenshots live under `docs/`).

## Supported fields (as of 2026-06-18)

What the plugin writes AND reads back (round trip tested, unit + against a real
Radicale). Fields that are not handled are NOT lost on edit — see "fetch-merge".

### Contacts (vCard 3.0)

- **Recipient autocomplete:** `register_carddav_autocomplete()` (startup hook, task=mail) adds the CardDAV address book IDs to `autocomplete_addressbooks` at runtime (discovery cached once per session, only non-empty) -> contacts appear in the To: search. Roundcube's autocomplete otherwise iterates only configured source IDs, and the `caldav_<md5>` IDs are dynamic.

- Name: FN + N (last, first, middle, prefix, suffix — all 5 N parts)
- Nickname (NICKNAME), job title (TITLE)
- Organization + department (structured ORG: org;department)
- Email with subtype (EMAIL;TYPE=HOME/WORK/...) — multiple, per subtype
- Phone with subtype (TEL;TYPE=HOME/WORK/CELL/...)
- Structured address (ADR;TYPE=...: street/locality/region/zipcode/country), multiple
- Website (URL), instant messaging (IMPP; subtype via X-SERVICE-TYPE)
- Birthday (BDAY), anniversary (ANNIVERSARY) — date normalized to YYYY-MM-DD
- Notes (NOTE)
- Company contacts: empty FN falls back to ORG (sorting)
- coltypes extended -> the Roundcube form shows all these fields

### Events (VEVENT, iCal RFC 5545)

- Title (SUMMARY), start/end (DTSTART/DTEND), all-day (VALUE=DATE, end exclusive +1)
- Timezone via TZID; DST correct (summer +02:00 / winter +01:00 for Europe/Berlin tested)
- Location (LOCATION), description (DESCRIPTION), URL, STATUS, TRANSP, CLASS
- **Recurrence (RRULE)** — read + write + preserved on edit
- Categories (CATEGORIES), reminder (VALARM/TRIGGER)
- Apple travel time: X-APPLE-TRAVEL-ADVISORY-BEHAVIOR (auto) / X-APPLE-TRAVEL-DURATION (minutes)
- Geo location: X-APPLE-STRUCTURED-LOCATION + GEO (Apple Maps travel time)

### Tasks (VTODO)

- Title, description, location, URL
- Due (DUE), start (DTSTART) — with timezone
- Priority (high=1/medium=5/low=9/none), progress (PERCENT-COMPLETE)
- Status/completed (STATUS + COMPLETED), categories (CATEGORIES), recurrence (RRULE)
- Check off (toggle): fetches the VTODO, flips only the status, preserves all other fields

### Fetch-merge edit (important)

Contacts modify the existing vCard in place; events/tasks fetch the existing object
on edit (`CalDAVClient::getObject`) and merge only the passed fields
(`updateICalEvent` / `updateICalTodo`). This preserves fields the form does not know
about (RRULE, ATTENDEE, ORGANIZER, SEQUENCE, EXDATE, RELATED-TO, custom X- props)
instead of losing them on save. Verified: a title edit of a recurring event keeps the RRULE.

### Not yet separate form fields

ATTENDEE/ORGANIZER (invitations/iTip), EXDATE/RECURRENCE-ID exceptions, multiple
VALARMs, PHOTO upload — but they are preserved on edit thanks to fetch-merge, just
not editable via the UI.

### Tests

`php vendor/bin/phpunit --testsuite unit` — 149 unit tests (backends, ContactCard,
CalendarObject, CardDAVAddressbook with a mocked client, recurrence, timezones/DST,
fetch-merge, all fields, special-character round trips). Integration against a real
Radicale (local Docker test stack) verified.

## iMIP / iTIP (calendar invitations in mails)

`lib/ITip.php` parses `text/calendar` parts and builds REPLY/COUNTER (sabre/vobject),
sends them as an iMIP mail via Roundcube SMTP (`rcube::deliver_message` + `Mail_mime`).

- **Hooks:** `message_load` (detect an invitation in the loaded mail) + `template_object_messagebody`
  (render the box above the mail text). **Actions** `plugin.caldav-itip-{reply,counter}` MUST be
  registered BEFORE `register_task()`, otherwise "No handler found".
- **UI:** `render_itip_box()` -> `role=region` + `<dl>` + calendar `<select>` + button group
  (accept/tentative/decline/propose) + aria-live. `js/itip.js` wires it up; the propose modal
  via `caldav_suite.dialog()`. The box carries `data-msg-uid/-mbox/-mime-id`; the action handler
  reloads the part server-side (does not trust any client ICS).
- **Accept/tentative:** event with own `PARTSTAT` into the chosen calendar (`buildStoredEvent`)
  + REPLY. **Decline:** REPLY only. **Propose:** COUNTER with a new time.
- **PITFALL (PEAR):** the plugin bundles (transitively via `roundcube/plugin-installer`) its own
  PEAR copy and prepends it to the include_path via `vendor/composer/include_paths.php`.
  Loading `Mail_mime` otherwise fails with `Cannot redeclare _PEAR_call_destructors`. `ITip::send`
  briefly removes the plugin PEAR paths from the include_path while sending.
- **PITFALL (composer autoload / class shadowing):** `roundcube/plugin-installer` transitively
  pulls `roundcube/roundcubemail` into `vendor/`; its classmap entries are registered via prepend
  BEFORE Roundcube's autoloader and shadow the real core classes (concretely: the old
  `rcmail_sendmail` copy set `html_encoding=8bit` for the HTML part -> HTML went out as ONE long
  line -> strict mail servers rejected it with `501 line too long`; same for the PEAR duplicates).
  Fix in `composer.json`: `"exclude-from-classmap": ["/vendor/roundcube/roundcubemail/",
  "/vendor/pear/"]` (paths relative to the plugin root, incl. `vendor/`) plus moving
  `roundcube/plugin-installer` to `require-dev` (composer only executes plugins from the root
  project). `tests/bootstrap.php` loads the vendored core classes on demand for the unit tests
  (`phpunit.xml` -> `bootstrap="tests/bootstrap.php"`). Afterwards run `composer dump-autoload`.
- **Test:** `test-stack/run-itip-test.sh` (greenmail SMTP/IMAP, Playwright `itip-test.mjs`):
  box render + a11y, accept -> CalDAV event + REPLY in greenmail, counter modal -> COUNTER mail.
