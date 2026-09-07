#!/bin/sh
# Assert the plugin's cache behaviour for a domain, from inside the Vagrant box.
#
#   test/cache-matrix.sh [domain]
#
# Two headers carry the contract:
#   X-Cache         HIT / MISS, emitted by mod_cache when CacheHeader is On.
#                   Absent entirely (reported as NONE) for a request mod_cache
#                   never considers at all, such as a POST.
#   X-Imscp-Bypass  set by the plugin when a request matched a bypass rule.
#
# A bypassed request reads as "MISS + bypass": mod_cache still runs, but the
# request-side Cache-Control forbids serving and the no-cache env forbids
# storing. That pair is what keeps a logged-in visitor's page out of the cache
# while leaving the anonymous entry intact.

DOMAIN=${1:-wpcache.test}
BASE="http://127.0.0.1"
H="Host: $DOMAIN"
fails=0

# Prints "<X-Cache value> <bypass|->".
probe() {
    curl -sS -D - -o /dev/null -H "$H" "$@" | awk '
        BEGIN { IGNORECASE = 1; cache = "NONE"; bypass = "-" }
        /^X-Cache:/        { sub(/\r/, ""); cache = $2 }
        /^X-Imscp-Bypass:/ { bypass = "bypass" }
        END                { print cache, bypass }'
}

# Prints the response status alone, for a request the cache never sees.
status() {
    curl -sS -o /dev/null -w '%{http_code}' -H "$H" "$@"
}

check() {
    want=$1; got=$2; what=$3
    if [ "$got" = "$want" ]; then
        printf 'ok   %-44s %s\n' "$what" "$got"
    else
        printf 'FAIL %-44s want [%s], got [%s]\n' "$what" "$want" "$got"
        fails=$((fails + 1))
    fi
}

# The glob has to expand inside the privileged shell: the cache directory is
# only readable by the web server user, so expanding it as the caller silently
# leaves it unmatched and the "cold" checks then run against a warm cache.
purge() { sudo sh -c "rm -rf /var/cache/apache2/imscp/$DOMAIN/*"; }

echo "== $DOMAIN =="
purge

# WordPress sends no validator and no freshness info on HTML, so these two
# lines only differ because of CacheIgnoreNoLastMod + CacheDefaultExpire.
check "MISS -"      "$(probe "$BASE/hello-world/")" "anon HTML, cold"
check "HIT -"       "$(probe "$BASE/hello-world/")" "anon HTML, warm"

# Pretty permalinks are rewritten to /index.php before the cache key is built,
# so distinct pages must still have distinct cache entries.
check "MISS -"      "$(probe "$BASE/second-post/")" "second pretty permalink is distinct and cold"
check "HIT -"       "$(probe "$BASE/second-post/")" "second pretty permalink is warm"
check "HIT -"       "$(probe "$BASE/hello-world/")" "original permalink still hits"

# WordPress does not Vary on Cookie. Without these rules the cache would both
# store an admin's page and serve the public one back to them.
check "MISS bypass" "$(probe -H 'Cookie: wordpress_logged_in_x=1' "$BASE/hello-world/")" \
    "logged-in cookie bypasses"
check "HIT -"       "$(probe "$BASE/hello-world/")" \
    "anon entry survives the bypass"

check "MISS bypass" "$(probe -H 'Cookie: wp-postpass_x=1' "$BASE/hello-world/")" \
    "password-protected cookie bypasses"
check "MISS bypass" "$(probe -H 'Cookie: comment_author_x=1' "$BASE/hello-world/")" \
    "comment author cookie bypasses"
check "MISS bypass" "$(probe "$BASE/?s=test")" "search query bypasses"

check "HIT -"       "$(probe -H 'Cookie: unrelated=1' "$BASE/hello-world/")" \
    "unrelated cookie still hits"

check "MISS bypass" "$(probe "$BASE/wp-login.php")" "wp-login.php bypasses"
check "MISS bypass" "$(probe "$BASE/wp-admin/")"    "wp-admin bypasses"

# A pretty permalink is a virtual path: mod_rewrite turns it into /index.php
# before the cache runs, so a rule matching on the rewritten URL would miss it
# while still working for wp-admin, which is a real directory.
check "MISS bypass" "$(probe "$BASE/my-account")"   "virtual bypass path bypasses"
check "MISS bypass" "$(probe -H 'Cookie: my_session=1' "$BASE/hello-world/")" \
    "custom bypass cookie bypasses"

# A denied path is refused by an access check, which runs before the cache and
# before the per-directory rewrite. The match is on any part of the path, so the
# doubled-slash form scanners use is refused as well.
check "403" "$(status "$BASE/xmlrpc.php")"  "xmlrpc.php is refused"
check "403" "$(status "$BASE//xmlrpc.php")" "//xmlrpc.php is refused"
check "200" "$(status "$BASE/hello-world/")" "an undenied path is still served"

# Last: a POST invalidates the cached entry for its URL, so anything checked
# after this would see a cold cache.
check "NONE bypass" "$(probe -X POST "$BASE/hello-world/")" "POST bypasses"

echo
if [ "$fails" -eq 0 ]; then
    echo "all checks passed"
else
    echo "$fails check(s) failed"
fi
exit "$fails"
