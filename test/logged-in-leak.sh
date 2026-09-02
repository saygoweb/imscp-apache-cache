#!/bin/sh
# The safety property, checked against a real WordPress session rather than a
# made-up cookie: a signed-in visitor's page must never be stored, and must
# never be handed to the next anonymous visitor.
#
#   test/logged-in-leak.sh [domain] [wp-user] [wp-password]

DOMAIN=${1:-wpcache.test}
WPUSER=${2:-wpadmin}
WPPASS=${3:-'Passw0rd!123'}
BASE="http://127.0.0.1"
H="Host: $DOMAIN"
URL="$BASE/hello-world/"
JAR=$(mktemp)
fails=0

trap 'rm -f "$JAR" "$JAR.in" "$JAR.anon"' EXIT

curl -sS -c "$JAR" -b "$JAR" -H "$H" -o /dev/null \
    --data-urlencode "log=$WPUSER" --data-urlencode "pwd=$WPPASS" \
    -d 'wp-submit=Log+In&testcookie=1' "$BASE/wp-login.php"

if ! grep -q 'wordpress_logged_in_' "$JAR"; then
    echo "FAIL could not sign in as $WPUSER; nothing was tested"
    exit 1
fi

sudo sh -c "rm -rf /var/cache/apache2/imscp/$DOMAIN/*"

curl -sS -b "$JAR" -H "$H" "$URL" -o "$JAR.in"
curl -sS         -H "$H" "$URL" -o "$JAR.anon"

# The admin bar is the clearest marker of a page rendered for a signed-in user.
if grep -q 'id="wpadminbar"' "$JAR.in"; then
    echo "ok   signed-in response really is the signed-in page"
else
    echo "FAIL signed-in response has no admin bar; the test proves nothing"
    fails=$((fails + 1))
fi

if grep -q 'id="wpadminbar"' "$JAR.anon"; then
    echo "FAIL signed-in page leaked to the next anonymous visitor"
    fails=$((fails + 1))
else
    echo "ok   anonymous visitor gets no trace of the signed-in page"
fi

echo
[ "$fails" -eq 0 ] && echo "all checks passed" || echo "$fails check(s) failed"
exit "$fails"
