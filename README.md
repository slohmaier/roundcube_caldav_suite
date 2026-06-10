# roundcube_caldav_suite

CalDAV Calendar & Tasks plugin for Roundcube Webmail.

Connects to **any CalDAV server** (Radicale, Baikal, Nextcloud, iCloud, Google) as a client. No Kolab, no heavyweight dependencies.

## Features

- **Calendar**: Month/Week/Day/List views, multiple calendars with colors, create/edit/delete events
- **Tasks**: Todo lists from CalDAV, create/complete/delete, priorities, due dates
- **Auto-Discovery**: Finds all calendars and task lists from a single CalDAV URL
- **Accessible**: Full keyboard navigation, screen reader support, ARIA labels, semantic HTML
- **Lightweight**: Only sabre/dav + sabre/vobject as dependencies

## Requirements

- PHP >= 8.1
- Roundcube >= 1.6
- A CalDAV server (Radicale, Baikal, Nextcloud, etc.)

## Installation

```bash
cd /path/to/roundcube
composer require slohmaier/roundcube_caldav_suite
```

Add `caldav_suite` to `$config['plugins']` in your Roundcube config.

## Configuration

After installation, go to **Settings → CalDAV Suite** in Roundcube and enter:

- CalDAV Server URL (e.g. `https://radicale.example.com/user/`)
- Username & Password

The plugin will automatically discover all calendars and task lists.

## License

AGPL-3.0-or-later
