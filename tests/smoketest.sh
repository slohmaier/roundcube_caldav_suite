#!/bin/bash
# Smoketest: verifies Roundcube loads the caldav_suite plugin correctly
HOST="http://127.0.0.1:8280"
HDR="-H Host:mail.home.slohmaier.de"
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

# Check no PHP errors in recent logs
ERRORS=$(sudo docker logs roundcube --tail 20 2>&1 | grep -c "Failed to load plugin file.*caldav_suite")
if [ "$ERRORS" -eq 0 ]; then
    echo "  OK  No plugin load errors"
else
    echo "  FAIL $ERRORS plugin load errors in recent logs"
    FAIL=$((FAIL+1))
fi

echo ""
if [ $FAIL -eq 0 ]; then
    echo "ALL PASSED"
else
    echo "$FAIL FAILED"
fi
exit $FAIL
