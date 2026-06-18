# Screenshots

Erzeugt automatisch Beispiel-Screenshots des Plugins aus dem Test-Stack.

```bash
cd test-stack/screenshots
./generate.sh                 # Docker direkt
DOCKER="sudo docker" ./generate.sh
```

Das Skript:

1. erzeugt die bereinigte Autoload + faehrt den Test-Stack hoch (`prep` + `compose up`),
2. setzt die User-Prefs (Default-Ansicht = Woche),
3. seedet eine **prall gefuellte Demo-Woche** (`seed.php`): 4 farbige Kalender
   (Arbeit/Privat/Familie/Sport), Ganztags-Mehrtagestermin, Doppel-/Dreifach-
   belegungen, Serientermine und mehrere Termine mit **Fahrtzeit**,
4. installiert Playwright + Chromium (einmalig),
5. macht Screenshots nach `out/` (`shoot.mjs`):
   `calendar-week/-day/-month/-list.png`, `tasks.png`, `contacts.png`.

Voraussetzung: im Repo-Root einmal `composer install`. Output (`out/`) und
`node_modules/` sind ge-gitignored.

Einzelne Schritte erneut: nur neu seeden + schiessen, ohne Stack-Neustart:

```bash
DOCKER="sudo docker" sh -c 'cd .. && docker compose exec -T rc-test-roundcube php' < seed.php
node shoot.mjs
```
