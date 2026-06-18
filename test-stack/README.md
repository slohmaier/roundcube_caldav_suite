# Test-Stack (Roundcube + Radicale + Greenmail)

Isolierte Docker-Umgebung zum Entwickeln/Debuggen des Plugins gegen einen echten
CalDAV/CardDAV-Server, **ohne Live-Daten**. Mountet dieses Repo direkt als Roundcube-Plugin.

## Schnellstart

Voraussetzung: einmalig im Repo-Root `composer install` (erzeugt `vendor/`).

```bash
cd test-stack
./start.sh                # Autoload bereinigen -> up -> einrichten (alles in einem)
```

Oder manuell (Reihenfolge wichtig — `prep` MUSS vor `up` laufen):

```bash
./prep-runtime-vendor.sh  # bereinigte Composer-Autoload erzeugen (siehe unten)
docker compose up -d
./setup.sh
```

Dann im Browser: <http://127.0.0.1:8099> — Login **test / test**.

> **Warum `prep-runtime-vendor.sh`?** Das Repo zieht via `roundcube/plugin-installer`
> transitiv eine komplette `roundcube/roundcubemail`-Kopie ins `vendor/` (gebraucht von
> den PHPUnit-Tests für die `rcube_*`-Basisklassen). Im laufenden Roundcube kollidieren
> diese Klassen mit dem echten Core → das Plugin lädt nicht. `prep` erzeugt unter
> `vendor-runtime/` eine bereinigte Composer-Autoload (ohne roundcubemail), die der
> Container über das echte `vendor/composer/` legt. Das echte `vendor/` bleibt unberührt,
> PHPUnit nutzt es weiter. Bei einer **echten** Installation (`composer require … ` direkt
> in Roundcube) entsteht diese verschachtelte Kopie nicht — dort ist nichts zu tun.

Radicale direkt: <http://127.0.0.1:5233> (Login test / test).

Alle Ports binden nur auf `127.0.0.1` (kein LAN-Expose).

> Wenn der eigene User nicht in der `docker`-Gruppe ist: `sudo docker compose up -d`
> und `sudo ./setup.sh`.

## Was setup.sh macht

1. Wartet bis Roundcube antwortet.
2. Loggt sich einmal ein → legt den User-Datensatz in der SQLite-DB an.
3. Setzt die **User-Prefs** `caldav_suite_url/username/password`. Wichtig: das Plugin
   liest diese aus den **User-Prefs**, NICHT aus `config.inc.php`. Das Passwort wird
   mit Roundcubes `encrypt()` abgelegt. Erst danach erscheint die CalDAV-Quelle.
4. Legt in Radicale drei Collections an: `kalender/` (VEVENT), `aufgaben/` (VTODO),
   `contacts/` (Adressbuch via extended MKCOL).

## Tests

Unit-Tests laufen ohne den Stack, direkt im Repo-Root:

```bash
composer install            # einmalig (vendor/)
php vendor/bin/phpunit --testsuite unit
```

Integration gegen den laufenden Stack: das Plugin ist read-only unter
`/var/www/html/plugins/caldav_suite` gemountet — Aenderungen am Code greifen nach
`docker compose restart rc-test-roundcube` (PHP-OPcache).

## Aufraeumen

```bash
docker compose down            # Container weg, Radicale-Daten (Volume) bleiben
docker compose down -v         # inkl. Radicale-Daten loeschen (frischer Start)
```

## Stolpersteine (aus der Praxis)

- **OPcache:** nach Code-Aenderung `docker compose restart rc-test-roundcube`.
- **Debug-Logs:** der Webprozess laeuft als `www-data` (uid 33). Eine per
  `docker compose exec` (root) angelegte Logdatei kann www-data nicht beschreiben —
  `@file_put_contents` schlaegt dann still fehl. Logfile vorher `chmod 666` setzen.
- **CalDAV-Quelle fehlt:** dann wurden die User-Prefs nicht gesetzt → `./setup.sh`
  (erneut) laufen lassen, danach in Roundcube neu einloggen.
