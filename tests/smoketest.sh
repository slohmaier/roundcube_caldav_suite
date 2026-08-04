#!/bin/bash
# Smoketest: verifies Roundcube loads the caldav_suite plugin correctly.
# Konfigurierbar ueber Umgebungsvariablen:
#   RC_URL        Basis-URL des Roundcube (Default: http://127.0.0.1:80)
#   RC_HOST_HEADER  optionaler Host-Header (z.B. "-H Host:mail.example.org")
#   RC_LOGS_CHECK   1 = Plugin-Load-Fehler in Container-Logs pruefen (Default: 0)\n#   RC_CONTAINER    Container-Name fuer den Log-Check (Default: roundcube)
HOST="${RC_URL:-http://127.0.0.1:80}"
HDR="${RC_HOST_HEADER:-}"
LOGS_CHECK="${RC_LOGS_CHECK:-0}"\nCONTAINER="${RC_CONTAINER:-roundcube}"
FAIL=0

check() {
    local desc="$1" url="$2" expect="$3"
    code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 $HDR "$HOST$url" 2>/dev/null)
    if [ "$code" = "$expect" ]; then
        echo "  OK  $desc ($code)"
    else
        echo "  FAIL $desc (got $code, expected $expect)"
        FAIL=$((FAIL+1))
    fi
}

echo "=== CalDAV Suite Smoketest ==="
check "Roundcube loads"          "/"                                                       "200"
check "Plugin JS loads"          "/static.php/plugins/caldav_suite/js/caldav_suite.js"     "200"
check "Plugin CSS loads"         "/static.php/plugins/caldav_suite/skins/elastic/styles/caldav_suite.css" "200"
check "Calendar view JS"        "/static.php/plugins/caldav_suite/js/calendar_view.js"    "200"
check "Task view JS"            "/static.php/plugins/caldav_suite/js/task_view.js"        "200"
check "Event dialog JS"         "/static.php/plugins/caldav_suite/js/event_dialog.js"     "200"
check "A11y JS"                 "/static.php/plugins/caldav_suite/js/a11y.js"             "200"
check "Logo"                    "/static.php/skins/elastic/images/logo-custom.png"        "200"

# Optional: no PHP plugin-load errors in recent container logs (needs RC_LOGS_CHECK=1)
if [ "$LOGS_CHECK" = "1" ]; then
    if ! command -v docker >/dev/null 2>&1; then
        echo "  WARN  docker nicht verfuegbar, Log-Check uebersprungen"
    else
        ERRORS=$(sudo docker logs $CONTAINER --tail 20 2>&1 | grep -c "Failed to load plugin file.*caldav_suite")
        if [ "$ERRORS" -eq 0 ]; then
            echo "  OK  No plugin load errors"
        else
            echo "  FAIL $ERRORS plugin load errors in recent logs"
            FAIL=$((FAIL+1))
        fi
    fi
fi

echo ""
if [ $FAIL -eq 0 ]; then
    echo "ALL PASSED"
else
    echo "$FAIL FAILED"
fi
exit $FAIL
