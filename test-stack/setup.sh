#!/usr/bin/env bash
# Richtet den Test-Stack ein, NACHDEM die Container laufen:
#  - wartet auf Roundcube
#  - legt den Test-User an (Login test/test)
#  - setzt die CalDAV/CardDAV-Prefs des Users (das Plugin liest USER-Prefs, nicht config.inc.php)
#  - legt Kalender-, Aufgaben- und Adressbuch-Collections in Radicale an
#
# Benutzung:  docker compose up -d && ./setup.sh
set -euo pipefail
cd "$(dirname "$0")"

BASE="http://127.0.0.1:8099"
RAD="http://127.0.0.1:5233"
RUSER="test"; RPASS="test"

echo ">> Warte auf Roundcube ($BASE) ..."
for i in $(seq 1 60); do
    if curl -sf "$BASE/" -o /dev/null; then break; fi
    sleep 2
    if [ "$i" = 60 ]; then echo "Roundcube nicht erreichbar"; exit 1; fi
done

echo ">> Warte auf Radicale ($RAD) ..."
for i in $(seq 1 60); do
    code="$(curl -s -o /dev/null -w '%{http_code}' "$RAD/" || echo 000)"
    if [ "$code" != "000" ]; then break; fi
    sleep 2
    if [ "$i" = 60 ]; then echo "Radicale nicht erreichbar"; exit 1; fi
done

echo ">> Login (legt den User in der DB an) ..."
J="$(mktemp)"
TOKEN="$(curl -s -c "$J" "$BASE/" | grep -oP 'name="_token"[^>]*value="\K[^"]+' | head -1)"
curl -s -b "$J" -c "$J" -X POST "$BASE/?_task=login&_action=login" \
    --data-urlencode "_token=$TOKEN" \
    --data-urlencode "_user=$RUSER" \
    --data-urlencode "_pass=$RPASS" -o /dev/null
rm -f "$J"

echo ">> CalDAV/CardDAV-Prefs fuer den Test-User setzen ..."
docker compose exec -T rc-test-roundcube php -r '
define("INSTALL_PATH","/var/www/html/");
require_once INSTALL_PATH."program/include/clisetup.php";
$rc = rcmail::get_instance();
$db = $rc->get_dbh();
$res = $db->query("SELECT user_id FROM users WHERE username = ?", "test");
$row = $db->fetch_assoc($res);
if (!$row) { fwrite(STDERR, "Test-User nicht gefunden\n"); exit(1); }
$u = new rcube_user((int) $row["user_id"]);
$u->save_prefs([
    "caldav_suite_url"      => "http://rc-test-radicale:5232",
    "caldav_suite_username" => "test",
    "caldav_suite_password" => $rc->encrypt("test"),
]);
echo "   prefs gesetzt (user_id=".$row["user_id"].")\n";
'

echo ">> Collections in Radicale anlegen ..."
curl -s -u "$RUSER:$RPASS" -X MKCALENDAR "$RAD/$RUSER/kalender/" -o /dev/null -w "   kalender: %{http_code}\n" || true
curl -s -u "$RUSER:$RPASS" -X MKCALENDAR "$RAD/$RUSER/aufgaben/" -o /dev/null -w "   aufgaben: %{http_code}\n" || true
curl -s -u "$RUSER:$RPASS" -X MKCOL "$RAD/$RUSER/contacts/" \
    -H "Content-Type: application/xml" --data '<?xml version="1.0"?>
<create xmlns="DAV:" xmlns:CR="urn:ietf:params:xml:ns:carddav">
 <set><prop>
  <resourcetype><collection/><CR:addressbook/></resourcetype>
  <displayname>Test Contacts</displayname>
 </prop></set>
</create>' -o /dev/null -w "   contacts: %{http_code}\n" || true

echo ""
echo "Fertig. Roundcube: $BASE   Login: $RUSER / $RPASS"
echo "Radicale:          $RAD   (Login: $RUSER / $RPASS)"
echo "PHPUnit (im Repo-Root):  php vendor/bin/phpunit --testsuite unit"
