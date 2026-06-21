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

echo ">> VTODO-Aufgaben seeden ..."
RAD="http://127.0.0.1:5233"; RU="test:test"
curl -s -o /dev/null -u "$RU" -X MKCALENDAR "$RAD/test/aufgaben/" \
  -H "Content-Type: application/xml" --data '<?xml version="1.0"?>
<C:mkcalendar xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><D:set><D:prop>
 <D:displayname>Aufgaben</D:displayname>
 <C:supported-calendar-component-set><C:comp name="VTODO"/></C:supported-calendar-component-set>
</D:prop></D:set></C:mkcalendar>' || true
i=0
for t in "Steuererklaerung abgeben|20260625|1" "Reifen wechseln|20260622|5" "Geschenk besorgen|20260621|9" "Arzt anrufen||" "Rechnung zahlen|20260618|1"; do
  i=$((i+1)); IFS='|' read -r SUM DUE PRIO <<< "$t"
  DUELINE=""; [ -n "$DUE" ] && DUELINE="DUE;VALUE=DATE:$DUE\n"
  PRIOLINE=""; [ -n "$PRIO" ] && PRIOLINE="PRIORITY:$PRIO\n"
  printf "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//test//EN\nBEGIN:VTODO\nUID:uitodo$i\nSUMMARY:$SUM\n${DUELINE}${PRIOLINE}STATUS:NEEDS-ACTION\nEND:VTODO\nEND:VCALENDAR\n" \
    | curl -s -o /dev/null -u "$RU" -X PUT "$RAD/test/aufgaben/uitodo$i.ics" -H "Content-Type: text/calendar" --data-binary @- || true
done
# Zweite Aufgabenliste (Sidebar-Navigationstest braucht >1 Liste)
curl -s -o /dev/null -u "$RU" -X MKCALENDAR "$RAD/test/erinnerungen/" \
  -H "Content-Type: application/xml" --data '<?xml version="1.0"?>
<C:mkcalendar xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><D:set><D:prop>
 <D:displayname>Erinnerungen</D:displayname>
 <C:supported-calendar-component-set><C:comp name="VTODO"/></C:supported-calendar-component-set>
</D:prop></D:set></C:mkcalendar>' || true
printf "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//test//EN\nBEGIN:VTODO\nUID:uirem1\nSUMMARY:Zahnarzttermin\nDUE;VALUE=DATE:20260701\nSTATUS:NEEDS-ACTION\nEND:VTODO\nEND:VCALENDAR\n" \
  | curl -s -o /dev/null -u "$RU" -X PUT "$RAD/test/erinnerungen/uirem1.ics" -H "Content-Type: text/calendar" --data-binary @- || true

echo ">> OPcache klaeren (restart) ..."
$DOCKER compose restart rc-test-roundcube >/dev/null 2>&1
sleep 5

echo ">> UI-Test ..."
node ui-test.mjs
