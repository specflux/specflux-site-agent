#!/usr/bin/env bash
# Real HTTP timings against the wp-env site (port 8895) as the admin user: the Runs wp-admin page
# (server render of the screen shell) and GET /runs?limit=50 over REST with a cookie + nonce.
#   dev/scale/measure-http.sh [repeats]
set -euo pipefail
BASE="${BASE_URL:-http://localhost:8895}"
N="${1:-10}"
JAR="$(mktemp)"; trap 'rm -f "$JAR"' EXIT
curl -s -c "$JAR" -b "$JAR" -o /dev/null -d "log=admin&pwd=password&wp-submit=Log+In&testcookie=1" -H 'Cookie: wordpress_test_cookie=WP%20Cookie%20check' "$BASE/wp-login.php"
NONCE="$(curl -s -b "$JAR" "$BASE/wp-admin/admin-ajax.php?action=rest-nonce")"
[ -n "$NONCE" ] || { echo "login failed" >&2; exit 1; }
t() { curl -s -b "$JAR" -o /dev/null -w '%{time_total} %{http_code} %{size_download}\n' "$@"; }
stats() { sort -n | awk '{a[NR]=$1*1000} END{printf "p50=%.0f ms p95=%.0f ms max=%.0f ms n=%d\n", a[int((NR-1)*0.5)+1], a[int((NR-1)*0.95)+1], a[NR], NR}'; }
echo "== GET /wp-admin/admin.php?page=specflux-site-agent-runs"
t "$BASE/wp-admin/admin.php?page=specflux-site-agent-runs" >/dev/null   # warm
for _ in $(seq 1 "$N"); do t "$BASE/wp-admin/admin.php?page=specflux-site-agent-runs"; done | tee /tmp/scale-http-page.txt | awk '{print}' | head -1
awk '{print $1}' /tmp/scale-http-page.txt | stats
awk 'NR==1{print "status/bytes: " $2 " " $3}' /tmp/scale-http-page.txt
echo "== GET /wp-json/specflux-site-agent/v1/runs?limit=50"
t -H "X-WP-Nonce: $NONCE" "$BASE/index.php?rest_route=/specflux-site-agent/v1/runs&limit=50" >/dev/null
for _ in $(seq 1 "$N"); do t -H "X-WP-Nonce: $NONCE" "$BASE/index.php?rest_route=/specflux-site-agent/v1/runs&limit=50"; done > /tmp/scale-http-rest.txt
awk '{print $1}' /tmp/scale-http-rest.txt | stats
awk 'NR==1{print "status/bytes: " $2 " " $3}' /tmp/scale-http-rest.txt
echo "== control: GET /wp-admin/options-general.php (a core page, no Site Agent involvement)"
t "$BASE/wp-admin/options-general.php" >/dev/null
for _ in $(seq 1 "$N"); do t "$BASE/wp-admin/options-general.php"; done | awk '{print $1}' | stats
echo "== GET Belayline audit Calls screen (admin.php?page=belayline-activity&section=calls); chain-status cache cleared first, then warm"
(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && ./node_modules/.bin/wp-env run cli wp transient delete belayline_chain_status >/dev/null 2>&1) || true
echo -n "cold (cache miss, full verify): "; t "$BASE/wp-admin/admin.php?page=belayline-activity&section=calls"
for _ in $(seq 1 "$N"); do t "$BASE/wp-admin/admin.php?page=belayline-activity&section=calls"; done | awk '{print $1}' | { echo -n "warm: "; stats; }
