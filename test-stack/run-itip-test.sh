#!/usr/bin/env bash
# Brings up the test stack, sets up a calendar invitation and runs the
# iMIP/iTIP UI test (invitation box: accept/decline/counter proposal).
#   ./run-itip-test.sh                 (Docker direkt)
#   DOCKER="sudo docker" ./run-itip-test.sh
set -euo pipefail
cd "$(dirname "$0")"
DOCKER="${DOCKER:-docker}"
BASE="http://127.0.0.1:8099"
RAD="http://127.0.0.1:5233"

./prep-runtime-vendor.sh
$DOCKER compose up -d
echo ">> Waiting for Roundcube ..."
for i in $(seq 1 60); do curl -sf "$BASE/" -o /dev/null && break; sleep 2; done

echo ">> Login (create user) ..."
J="$(mktemp)"
TOKEN="$(curl -s -c "$J" "$BASE/" | grep -oP 'name="_token"[^>]*value="\K[^"]+' | head -1)"
curl -s -b "$J" -c "$J" -X POST "$BASE/?_task=login&_action=login" \
  --data-urlencode "_token=$TOKEN" --data-urlencode "_user=test" --data-urlencode "_pass=test" -o /dev/null
rm -f "$J"

echo ">> CalDAV prefs + identity test@example.com ..."
$DOCKER compose exec -T rc-test-roundcube php -r '
define("INSTALL_PATH","/var/www/html/"); require_once INSTALL_PATH."program/include/clisetup.php";
$rc=rcmail::get_instance(); $db=$rc->get_dbh();
$r=$db->fetch_assoc($db->query("SELECT user_id FROM users WHERE username=?","test"));
if($r){ $uid=(int)$r["user_id"]; $u=new rcube_user($uid);
  $u->save_prefs(["caldav_suite_url"=>"http://rc-test-radicale:5232","caldav_suite_username"=>"test","caldav_suite_password"=>$rc->encrypt("test")]);
  $db->query("UPDATE identities SET email=? WHERE user_id=? AND standard=1","test@example.com",$uid);
  echo "ok\n"; }
'

echo ">> Radicale calendar ..."
curl -s -o /dev/null -u test:test -X MKCALENDAR "$RAD/test/kalender/" -H "Content-Type: application/xml" --data '<?xml version="1.0"?>
<C:mkcalendar xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><D:set><D:prop>
 <D:displayname>Calendar</D:displayname>
 <C:supported-calendar-component-set><C:comp name="VEVENT"/></C:supported-calendar-component-set>
</D:prop></D:set></C:mkcalendar>' || true

echo ">> Deliver invitation mail via IMAP ..."
python3 - <<'PY'
import imaplib, time
ics=("BEGIN:VCALENDAR\r\nPRODID:-//Example//EN\r\nVERSION:2.0\r\nMETHOD:REQUEST\r\n"
"BEGIN:VEVENT\r\nUID:itip-e2e-001@example.com\r\nSEQUENCE:0\r\nDTSTAMP:20260701T090000Z\r\n"
"DTSTART:20260703T100000Z\r\nDTEND:20260703T110000Z\r\nSUMMARY:Team Meeting Q3\r\nLOCATION:Meeting room 2\r\n"
"ORGANIZER;CN=Anna Organizer:mailto:organizer@example.com\r\n"
"ATTENDEE;CN=Test User;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:test@example.com\r\n"
"ATTENDEE;CN=Bob;PARTSTAT=NEEDS-ACTION:mailto:bob@example.com\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n")
eml=("From: Anna Organizer <organizer@example.com>\r\nTo: test@example.com\r\n"
"Subject: Invitation: Team Meeting Q3\r\nMIME-Version: 1.0\r\n"
'Content-Type: multipart/mixed; boundary="bnd42"\r\n\r\n'
"--bnd42\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nYou are invited.\r\n\r\n"
"--bnd42\r\nContent-Type: text/calendar; charset=UTF-8; method=REQUEST\r\nContent-Transfer-Encoding: 7bit\r\n\r\n"
+ics+"\r\n--bnd42--\r\n")
M=imaplib.IMAP4('127.0.0.1',3143); M.login('test','test')
M.append('INBOX',None,imaplib.Time2Internaldate(time.time()),eml.encode()); M.logout()
print("invite delivered")
PY

echo ">> Clear OPcache (restart) ..."
$DOCKER compose restart rc-test-roundcube >/dev/null 2>&1
sleep 5

echo ">> iTIP-UI-Test ..."
node itip-test.mjs
