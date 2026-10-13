# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-10-13

First tagged release. The plugin has been developed and tested against Radicale,
and is used in production against a Radicale server.

### Added

- **Calendar** — month/week/day/list views, multiple colored calendars with
  per-user visibility, create/edit/delete events, recurrence (RRULE), reminders
  (VALARM), categories, all-day events, DST-correct time zones, Apple travel time
  and structured location. List view with infinite scrolling.
- **Tasks (VTODO)** — create/edit/complete/delete, priority, due and start dates,
  percent-complete, categories, recurrence, per-task due-date indicator and
  expandable details.
- **Contacts (CardDAV)** — integrates as a Roundcube address book with full vCard 3.0
  field coverage (name parts, nickname, job title, organization/department,
  email/phone/address/URL/IM subtypes, birthday, anniversary, notes). Contacts appear
  in the recipient autocomplete.
- **Meeting invitations (iMIP/iTIP)** — accept / tentative / decline and
  "propose new time" (COUNTER) directly from the mail view; writes the event to the
  calendar and sends an iTIP reply to the organizer.
- **Auto-discovery** — finds all calendars, task lists and address books from a single
  CalDAV base URL.
- **Location search** — geocoding via Photon, Nominatim or Google Places (New), with
  optional browser-geolocation bias. Google runs through a server-side proxy so the
  API key never reaches the browser.
- **Accessibility** — full keyboard navigation, screen-reader support, ARIA labels,
  semantic HTML, a fully accessible list/agenda view, skip-to-calendar link, loading
  spinner with periodic screen-reader announcement, and native Roundcube list semantics.

### Changed

- Non-destructive edits: editing an object only touches the changed fields; unknown
  properties (RRULE, ATTENDEE/ORGANIZER, EXDATE, custom `X-` props) are preserved.
- Discovery is cached once per session; event queries run in parallel via `curl_multi`.

### Fixed

- Bundled `roundcube/roundcubemail` (transitive via `roundcube/plugin-installer`) no
  longer shadows Roundcube core classes; `composer.json` excludes those paths from the
  classmap and the installer is a dev-only dependency.
- Preemptive Basic auth to avoid server-side 401 retry storms against Radicale.
- Tolerate failed discovery PROPFINDs.
- Event dialog: recompute duration so a start change keeps a valid end; generate a UUID
  for new events (Radicale previously returned 400 on an empty UID).
- Recipient autocomplete: invalidate cached address book IDs when the CalDAV URL changes.

[1.1.0]: https://github.com/slohmaier/roundcube_caldav_suite/releases/tag/v1.1.0
