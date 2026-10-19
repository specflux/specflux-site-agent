# Scale harness (0.4 S1)

A repeatable scale test for Site Agent 0.4 (background runs) and Belayline 0.5 (audit chain), run
against this repo's own wp-env (port 8895) with WooCommerce. Results and method are written up in
`PROOF-0.4-SCALE.md` (in the planning directory); raw output is committed under `results/`.

Not part of the plugin: `dev/` is in `.distignore`, and nothing here changes product code.

## What it measures

| Measure | How |
|---|---|
| Tick overhead, model excluded | The e2e fake provider answers in zero time. A tiny mu-plugin (`lib/mu-specflux-scale-instrument.php`) times each Action Scheduler tick and the time the fake model spends inside `generateTextResult()`; overhead = tick time - model time. |
| Queue latency | Action Scheduler's due time to the moment the tick starts, idle queue (one run, runner started at once) and under a 1,000-run backlog. |
| Peak memory | `memory_get_peak_usage()` of the WP-CLI runner process, logged per tick. |
| Rows / size per 1,000 runs | `information_schema` before/after a 1,000-run campaign (`sizes`). |
| Runs screen list at 10,000 runs | `listRecent(50)` in-process, the REST route in-process, and real HTTP for the wp-admin page and the REST route (`list`, `measure-http.sh`), plus `EXPLAIN`. |
| Belayline verify at 100,000 rows | `AuditReader::verifyChain()` (`verify`), and the audit screen's own queries (`audit-screen`, `explain-audit.php`). |

## How to run

Needs Docker and `npm ci` done in the repo. The wp-env must already be able to start from
`.wp-env.json` (+ the untracked `.wp-env.override.json` that mounts Belayline).

```sh
dev/scale/scale.sh setup                 # copy the mu-plugin in, finish Action Scheduler's store migration
dev/scale/scale.sh env                   # versions, shas, machine -> results/env.txt
dev/scale/scale.sh seed-content 10000 20000 6   # 10,000 posts, 20,000 WooCommerce orders (6 parallel processes)
dev/scale/scale.sh campaign 1000 single c1-single-1000   # 1,000 real runs, drained by `wp action-scheduler run`
dev/scale/scale.sh sizes sizes-after-c1
dev/scale/scale.sh idle-lag 8            # queue latency on an idle queue
dev/scale/scale.sh fill-runs 10000       # clone finished runs up to 10,000 rows (see below)
dev/scale/scale.sh list list-10000-runs
dev/scale/measure-http.sh 15
dev/scale/scale.sh seed-audit 100000     # top the audit log up to 100,000 rows with Belayline's own append path
dev/scale/scale.sh verify verify-100000 5
dev/scale/scale.sh audit-screen audit-screen-100000 10
dev/scale/scale.sh campaign 500 single c2-single-500-at-scale   # tick cost again at 10k runs + 100k audit rows
dev/scale/scale.sh campaign 10 delayed c3-delayed-10            # multi-tick runs: 10 s model turns, one turn per tick
dev/scale/scale.sh teardown              # instrument off (data stays); then `npx wp-env stop`
```

Seeding takes about 30 minutes on a laptop (orders dominate: WooCommerce's own CRUD, about 13 orders
per second across 6 processes). `scale.sh` refuses to continue below 6 GB of free disk.

## What is real and what is not

- Runs are started with `specflux_site_agent()->start()` and their first tick is queued with the
  plugin's own `BackgroundDriver::schedule()`; every tick is `BackgroundDriver::handle()` run by
  the WP-CLI Action Scheduler runner. Steps, audit rows, Action Scheduler rows are written by the
  plugins.
- The 100,000 audit rows are appended one by one through `WpdbAuditSink::append()` and the result
  verifies as one hash chain.
- `fill-runs` is the one shortcut: it clones already-finished, really-driven runs and their steps
  (new ids, re-stamped dates, goal prefixed `[clone] `) to reach 10,000 rows without driving 9,000
  more. All tick figures come from really-driven runs. The Runs list query reads only the runs
  table, so clones are equivalent for the list measurement.
- WP-CLI-driven latency is the best case: a real site only runs the queue when WP-Cron fires.
- `WP_DEBUG`, `SCRIPT_DEBUG` and `WP_DEBUG_LOG` are on in this wp-env (they come from `.wp-env.json`),
  so absolute numbers are a little pessimistic for production.
