# Screenshots

Automatically creates example screenshots of the plugin from the test stack.

```bash
cd test-stack/screenshots
./generate.sh                 # Docker directly
DOCKER="sudo docker" ./generate.sh
```

The script:

1. creates the cleaned autoload + brings up the test stack (`prep` + `compose up`),
2. sets the user prefs (default view = week, language = English),
3. seeds a **rich demo week** (`seed.php`): 4 colored calendars
   (Work/Private/Family/Sport), a multi-day all-day event, double/triple
   overlaps, recurring events and several events with **travel time**,
4. installs Playwright + Chromium (once),
5. takes screenshots into `out/` (`shoot.mjs`):
   `calendar-week/-day/-month/-list.png`, `tasks.png`, `contacts.png`.

Prerequisite: run `composer install` once in the repo root. Output (`out/`) and
`node_modules/` are gitignored.

Re-run single steps: just re-seed + shoot, without restarting the stack:

```bash
DOCKER="sudo docker" sh -c 'cd .. && docker compose exec -T rc-test-roundcube php' < seed.php
node shoot.mjs
```
