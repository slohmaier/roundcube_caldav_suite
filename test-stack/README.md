# Test Stack (Roundcube + Radicale + Greenmail)

Isolated Docker environment for developing/debugging the plugin against a real
CalDAV/CardDAV server, **without live data**. Mounts this repo directly as a Roundcube plugin.

## Quick start

Prerequisite: run `composer install` once in the repo root (creates `vendor/`).

```bash
cd test-stack
./start.sh                # clean autoload -> up -> set up (all in one)
```

Or manually (order matters — `prep` MUST run before `up`):

```bash
./prep-runtime-vendor.sh  # create the cleaned composer autoload (see below)
docker compose up -d
./setup.sh
```

Then in the browser: <http://127.0.0.1:8099> — login **test / test**.

> **Why `prep-runtime-vendor.sh`?** Via `roundcube/plugin-installer` the repo transitively
> pulls a complete `roundcube/roundcubemail` copy into `vendor/` (needed by the PHPUnit
> tests for the `rcube_*` base classes). In the running Roundcube these classes collide
> with the real core -> the plugin does not load. `prep` creates a cleaned composer
> autoload under `vendor-runtime/` (without roundcubemail), which the container overlays
> over the real `vendor/composer/`. The real `vendor/` stays untouched; PHPUnit keeps
> using it. A **real** installation (`composer require ...` directly into Roundcube) does
> not create this nested copy — nothing to do there.

Radicale directly: <http://127.0.0.1:5233> (login test / test).

All ports bind only to `127.0.0.1` (no LAN exposure).

> If your user is not in the `docker` group: `sudo docker compose up -d`
> and `sudo ./setup.sh`.

## What setup.sh does

1. Waits until Roundcube responds.
2. Logs in once -> creates the user record in the SQLite DB.
3. Sets the **user prefs** `caldav_suite_url/username/password`. Important: the plugin
   reads these from the **user prefs**, NOT from `config.inc.php`. The password is stored
   with Roundcube's `encrypt()`. Only after this does the CalDAV source appear.
4. Creates three collections in Radicale: `kalender/` (VEVENT), `aufgaben/` (VTODO),
   `contacts/` (address book via extended MKCOL).

## Tests

Unit tests run without the stack, directly in the repo root:

```bash
composer install            # once (vendor/)
php vendor/bin/phpunit --testsuite unit
```

Integration against the running stack: the plugin is mounted read-only under
`/var/www/html/plugins/caldav_suite` — code changes take effect after
`docker compose restart rc-test-roundcube` (PHP OPcache).

## Cleanup

```bash
docker compose down            # containers gone, Radicale data (volume) stays
docker compose down -v         # including Radicale data (fresh start)
```

## Pitfalls (from practice)

- **OPcache:** after a code change run `docker compose restart rc-test-roundcube`.
- **Debug logs:** the web process runs as `www-data` (uid 33). A log file created via
  `docker compose exec` (root) cannot be written by www-data —
  `@file_put_contents` then fails silently. Set the log file to `chmod 666` beforehand.
- **CalDAV source missing:** then the user prefs were not set -> run `./setup.sh`
  (again), then log out and back in in Roundcube.
