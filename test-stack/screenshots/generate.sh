#!/usr/bin/env bash
# Erzeugt Beispiel-Screenshots des Plugins:
#   1. faehrt den Test-Stack hoch
#   2. setzt User-Prefs (Default-Ansicht = Woche)
#   3. seedet eine prall gefuellte Demo-Woche (mehrere Kalender, Ganztags,
#      Doppelbelegungen, Serien, Fahrtzeit)
#   4. installiert Playwright + Chromium (falls noetig)
#   5. macht Screenshots -> out/
#
# Benutzung:        ./generate.sh
# Docker via sudo:  DOCKER="sudo docker" ./generate.sh
set -euo pipefail
cd "$(dirname "$0")"

DOCKER="${DOCKER:-docker}"
BASE="http://127.0.0.1:8099"
RAD="http://127.0.0.1:5233"
dc() { ( cd .. && $DOCKER compose "$@" ); }

echo ">> Bereinigte Autoload erzeugen + Stack hochfahren ..."
( cd .. && ./prep-runtime-vendor.sh )
dc up -d

echo ">> Warte auf Roundcube + Radicale ..."
for i in $(seq 1 60); do curl -sf "$BASE/" -o /dev/null && break; sleep 2; done
for i in $(seq 1 60); do [ "$(curl -s -o /dev/null -w '%{http_code}' "$RAD/")" != "000" ] && break; sleep 2; done

echo ">> User anlegen + Prefs (Default-Ansicht Woche) ..."
J="$(mktemp)"
TOKEN="$(curl -s -c "$J" "$BASE/" | grep -oP 'name="_token"[^>]*value="\K[^"]+' | head -1)"
curl -s -b "$J" -c "$J" -X POST "$BASE/?_task=login&_action=login" \
  --data-urlencode "_token=$TOKEN" --data-urlencode "_user=test" --data-urlencode "_pass=test" -o /dev/null
rm -f "$J"
dc exec -T rc-test-roundcube php -r '
define("INSTALL_PATH","/var/www/html/"); require_once INSTALL_PATH."program/include/clisetup.php";
$rc=rcmail::get_instance(); $db=$rc->get_dbh();
$row=$db->fetch_assoc($db->query("SELECT user_id FROM users WHERE username=?","test"));
$u=new rcube_user((int)$row["user_id"]);
$u->save_prefs([
  "caldav_suite_url"=>"http://rc-test-radicale:5232","caldav_suite_username"=>"test",
  "caldav_suite_password"=>$rc->encrypt("test"),"caldav_suite_default_view"=>"week",
]); echo "prefs ok\n";'

echo ">> Demo-Woche seeden ..."
dc exec -T rc-test-roundcube php < seed.php

echo ">> Playwright + Chromium bereitstellen ..."
[ -d node_modules/playwright ] || npm install --no-audit --no-fund
# Browser-Binary (ohne Root). System-Libs ggf.: sudo npx playwright install-deps chromium
npx playwright install chromium

echo ">> Screenshots erzeugen ..."
node shoot.mjs

echo ""
echo "Fertig. Screenshots in: $(pwd)/out/"
