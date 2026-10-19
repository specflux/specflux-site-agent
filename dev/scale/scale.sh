#!/usr/bin/env bash
# Specflux Site Agent 0.4 + Belayline 0.5 scale harness (stage S1). See README.md.
#
#   dev/scale/scale.sh <command> [args]
#
# Run from anywhere; it always operates on THIS repo's own wp-env (port 8895). Every WP-CLI call goes
# through `wp-env run cli`, so the numbers include real PHP-in-Docker, real MySQL, real Action Scheduler.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
WPENV="$ROOT/node_modules/.bin/wp-env"
PLUGIN_DIR_IN_CONTAINER="wp-content/plugins/specflux-site-agent/dev/scale"
OUT="$HERE/results"
mkdir -p "$OUT"

wp() { (cd "$ROOT" && "$WPENV" run cli wp "$@" 2> >(grep -v '^ℹ\|^✔\|Starting\|sendmail' >&2) ); }
sh_in() { (cd "$ROOT" && "$WPENV" run cli bash -c "$1" 2>/dev/null); }
evalfile() { local f="$1"; shift; wp eval-file "$PLUGIN_DIR_IN_CONTAINER/$f" "$@"; }
free_gb() { df -g / | awk 'NR==2{print $4}'; }
guard_disk() {
  local g; g="$(free_gb)"
  echo "disk free: ${g} GB"
  if [ "$g" -lt 6 ]; then echo "ABORT: less than 6 GB free" >&2; exit 3; fi
}

cmd_setup() {   # install the instrument mu-plugin, finish AS's store migration, turn the instrument on
  (cd "$ROOT" && "$WPENV" start >/dev/null 2>&1)
  sh_in "cp /var/www/html/$PLUGIN_DIR_IN_CONTAINER/lib/mu-specflux-scale-instrument.php /var/www/html/wp-content/mu-plugins/"
  wp option update specflux_scale_instrument 1 >/dev/null
  wp option update specflux_scale_scenario single >/dev/null
  # A fresh env keeps AS on its hybrid store until this one-off action ran; `run --group` refuses before.
  wp action-scheduler run --hooks=action_scheduler/migration_hook >/dev/null
  echo "setup done"
}

cmd_teardown() { # turn the instrument off and remove it (the data stays)
  wp option update specflux_scale_instrument 0 >/dev/null
  sh_in "rm -f /var/www/html/wp-content/mu-plugins/mu-specflux-scale-instrument.php"
}

cmd_env() {     # environment facts -> results/env.txt
  {
    echo "date: $(date -u +%FT%TZ)"
    echo "machine: $(sysctl -n machdep.cpu.brand_string 2>/dev/null || uname -m), $(sysctl -n hw.ncpu) logical CPUs, $(( $(sysctl -n hw.memsize) / 1073741824 )) GB RAM, $(sw_vers -productName) $(sw_vers -productVersion)"
    echo "docker: $(docker info --format 'CPUs={{.NCPU}} Mem={{.MemTotal}} bytes, {{.OperatingSystem}}, server {{.ServerVersion}}')"
    echo "site-agent sha: $(git -C "$ROOT" rev-parse HEAD) ($(git -C "$ROOT" rev-parse --abbrev-ref HEAD))"
    local b1="$ROOT/../belayline-b1"
    echo "belayline sha: $(git -C "$b1" rev-parse HEAD) ($(git -C "$b1" rev-parse --abbrev-ref HEAD))"
    wp eval 'global $wpdb; echo "php ".PHP_VERSION."\nmysql/mariadb ".$wpdb->db_version()." (".$wpdb->get_var("SELECT VERSION()").")\nwordpress ".get_bloginfo("version")."\nwoocommerce ".WC_VERSION." (HPOS: ".get_option("woocommerce_custom_orders_table_enabled").")\naction-scheduler ".ActionScheduler_Versions::instance()->latest_version()."\ngate mode: ".\Specflux\SiteAgent\Plugin::currentGateMode()->value."\nmemory_limit(cli) ".ini_get("memory_limit")."\nWP_DEBUG ".(WP_DEBUG?"on":"off")." / SCRIPT_DEBUG ".(SCRIPT_DEBUG?"on":"off")." / WP_DEBUG_LOG ".(WP_DEBUG_LOG?"on":"off");'
    wp plugin list --fields=name,status,version --format=csv | grep -v inactive
  } | tee "$OUT/env.txt"
}

cmd_seed_content() {  # posts, then orders in parallel slices
  local posts="${1:-10000}" orders="${2:-20000}" par="${3:-6}"
  guard_disk
  evalfile seed-content.php posts "$posts"
  local have step from to
  have="$(wp db query "SELECT COUNT(*) FROM wp_wc_orders WHERE type='shop_order'" --skip-column-names | tr -d '[:space:]')"
  step=$(( (orders - have + par - 1) / par ))
  local pids=()
  for ((k=0; k<par; k++)); do
    from=$(( have + k*step )); to=$(( from + step )); [ "$to" -gt "$orders" ] && to="$orders"
    [ "$from" -ge "$to" ] && continue
    ( evalfile seed-content.php orders "$orders" "$from" "$to" > "$OUT/seed-orders-$k.log" 2>&1 ) &
    pids+=($!)
  done
  for p in "${pids[@]}"; do wait "$p"; done
  tail -n1 "$OUT"/seed-orders-*.log
  guard_disk
}

cmd_seed_audit() { guard_disk; evalfile seed-audit.php "${1:-100000}"; guard_disk; }

# campaign <runs> <scenario: single|delayed> <label>
cmd_campaign() {
  local n="$1" scenario="$2" label="$3"
  wp option update specflux_scale_scenario "$scenario" >/dev/null
  if [ "$scenario" = delayed ]; then wp option update specflux_site_agent_e2e_tick_slice 10 >/dev/null; else wp option delete specflux_site_agent_e2e_tick_slice >/dev/null 2>&1 || true; fi
  local started t0 t1
  started="$(evalfile start-runs.php "$n" "$label" | tail -n1)"
  echo "$started" | tee "$OUT/$label.start.json"
  t0=$(date +%s)
  # The real WP-CLI runner, in batches of 25 (Action Scheduler's own default queue size), until the queue is empty.
  wp action-scheduler run --group=specflux-site-agent --batch-size=25 --batches=0 > "$OUT/$label.runner.log" 2>&1 || true
  # Delayed runs re-queue themselves with a short retry_after; keep draining until nothing is pending.
  local pending
  for _ in $(seq 1 400); do
    pending="$(wp db query "SELECT COUNT(*) FROM wp_actionscheduler_actions WHERE hook='specflux_site_agent_background_tick' AND status='pending'" --skip-column-names | tr -d '[:space:]')"
    [ "$pending" = 0 ] && break
    wp action-scheduler run --group=specflux-site-agent --batch-size=25 --batches=0 >> "$OUT/$label.runner.log" 2>&1 || true
  done
  t1=$(date +%s)
  echo "campaign $label: $n runs drained in $((t1 - t0)) s wall, pending left: ${pending:-?}"
  sh_in "cat /var/www/html/wp-content/uploads/specflux-scale/metrics.jsonl" | grep '^{' > "$OUT/$label.metrics.jsonl"
  wp db query "SELECT status, COUNT(*) FROM wp_specflux_site_agent_runs WHERE id >= $(python3 -c "import json,sys;print(json.load(open('$OUT/$label.start.json'))['first_id'])") GROUP BY status"
  python3 "$HERE/summarize.py" "$OUT/$label.metrics.jsonl" "$OUT/$label.start.json" | tee "$OUT/$label.summary.json"
}

# idle-lag <n>: n times, queue ONE run and immediately start the WP-CLI runner; lag = scheduled time -> tick start.
cmd_idle_lag() {
  local n="${1:-10}" i
  wp option update specflux_scale_scenario single >/dev/null
  evalfile start-runs.php 1 idle-seed | tail -n1 > "$OUT/idle.start.json"   # also cleans the queue + metrics
  wp action-scheduler run --group=specflux-site-agent --batch-size=25 >/dev/null 2>&1 || true
  for ((i=0;i<n;i++)); do
    evalfile start-runs.php 1 idle keep | tail -n1 > "$OUT/idle.last.json"
    wp action-scheduler run --group=specflux-site-agent --batch-size=25 >/dev/null 2>&1 || true
  done
  sh_in "cat /var/www/html/wp-content/uploads/specflux-scale/metrics.jsonl" | grep '^{' > "$OUT/idle.metrics.jsonl"
  python3 "$HERE/summarize.py" "$OUT/idle.metrics.jsonl" <(python3 -c "
import json;a=json.load(open('$OUT/idle.start.json'));b=json.load(open('$OUT/idle.last.json'));print(json.dumps({'first_id':a['first_id'],'last_id':b['last_id']}))") | tee "$OUT/idle.summary.json"
}

cmd_sizes()  { evalfile sizes.php | tee "$OUT/${1:-sizes}.json"; }
cmd_list()   { evalfile measure-list.php "${2:-15}" | tee "$OUT/${1:-list}.json"; }
cmd_verify() { evalfile measure-verify.php "${2:-3}" | tee "$OUT/${1:-verify}.json"; }
cmd_audit_screen() { evalfile measure-audit-screen.php "${2:-10}" | tee "$OUT/${1:-audit-screen}.json"; }
cmd_fill_runs() { guard_disk; evalfile fill-runs.php "$1"; guard_disk; }

"cmd_${1//-/_}" "${@:2}"
