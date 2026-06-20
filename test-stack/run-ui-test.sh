#!/usr/bin/env bash
# Faehrt den Test-Stack hoch, seedet die Demo-Woche und laeuft den UI-Test.
#   ./run-ui-test.sh                 (Docker direkt)
#   DOCKER="sudo docker" ./run-ui-test.sh
set -euo pipefail
cd "$(dirname "$0")"
DOCKER="${DOCKER:-docker}"
BASE="http://127.0.0.1:8099"

./prep-runtime-vendor.sh
$DOCKER compose up -d

echo ">> Warte auf Roundcube ..."
for i in $(seq 1 60); do curl -sf "$BASE/" -o /dev/null && break; sleep 2; done

echo ">> Login (User anlegen) ..."
J="$(mktemp)"
TOKEN="$(curl -s -c "$J" "$BASE/" | grep -oP 'name="_token"[^>]*value="\K[^"]+' | head -1)"
curl -s -b "$J" -c "$J" -X POST "$BASE/?_task=login&_action=login" \
  --data-urlencode "_token=$TOKEN" --data-urlencode "_user=test" --data-urlencode "_pass=test" -o /dev/null
rm -f "$J"

echo ">> CalDAV-Prefs setzen ..."
$DOCKER compose exec -T rc-test-roundcube php -r '
define("INSTALL_PATH","/var/www/html/"); require_once INSTALL_PATH."program/include/clisetup.php";
$rc=rcmail::get_instance(); $db=$rc->get_dbh();
$r=$db->fetch_assoc($db->query("SELECT user_id FROM users WHERE username=?","test"));
if($r){ $u=new rcube_user((int)$r["user_id"]); $u->save_prefs(["caldav_suite_url"=>"http://rc-test-radicale:5232","caldav_suite_username"=>"test","caldav_suite_password"=>$rc->encrypt("test")]); echo "prefs ok\n"; }
'

echo ">> Demo-Woche seeden ..."
$DOCKER compose exec -T rc-test-roundcube php < screenshots/seed.php

echo ">> OPcache klaeren (restart) ..."
$DOCKER compose restart rc-test-roundcube >/dev/null 2>&1
sleep 5

echo ">> UI-Test ..."
node ui-test.mjs
