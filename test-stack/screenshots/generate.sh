#!/usr/bin/env bash
# Generates example screenshots of the plugin:
#   1. brings up the test stack
#   2. sets user prefs (default view = week, language = English)
#   3. seeds a rich demo week (several calendars, all-day, overlaps, series, travel time)
#   4. installs Playwright + Chromium (if needed)
#   5. takes screenshots -> out/
#
# Usage:            ./generate.sh
# Docker via sudo:  DOCKER="sudo docker" ./generate.sh
set -euo pipefail
cd "$(dirname "$0")"

DOCKER="${DOCKER:-docker}"
BASE="http://127.0.0.1:8099"
RAD="http://127.0.0.1:5233"
dc() { ( cd .. && $DOCKER compose "$@" ); }

echo ">> Create cleaned autoload + bring up the stack ..."
( cd .. && ./prep-runtime-vendor.sh )
dc up -d

echo ">> Wait for Roundcube + Radicale ..."
for i in $(seq 1 60); do curl -sf "$BASE/" -o /dev/null && break; sleep 2; done
for i in $(seq 1 60); do [ "$(curl -s -o /dev/null -w '%{http_code}' "$RAD/")" != "000" ] && break; sleep 2; done

echo ">> Create user + prefs (default view week, language English) ..."
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
  "language"=>"en_US",
]); echo "prefs ok\n";'

echo ">> Seed demo week ..."
dc exec -T rc-test-roundcube php < seed.php

echo ">> Provide Playwright + Chromium ..."
[ -d node_modules/playwright ] || npm install --no-audit --no-fund
# Browser binary (without root). System libs if needed: sudo npx playwright install-deps chromium
npx playwright install chromium

echo ">> Take screenshots ..."
node shoot.mjs

echo ""
echo "Done. Screenshots in: $(pwd)/out/"
