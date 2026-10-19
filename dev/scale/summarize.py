#!/usr/bin/env python3
"""Summarise one campaign: tick overhead (total minus the fake model), queue lag, queries, memory.

usage: summarize.py metrics.jsonl start.json
Only lines for run ids inside the campaign's [first_id, last_id] are counted (stale queue entries from
earlier runs are ignored). Percentiles are nearest-rank on the sorted sample.
"""
import json, sys

def pct(xs, q):
    xs = sorted(xs)
    return xs[int(q * (len(xs) - 1))] if xs else None

metrics_path, start_path = sys.argv[1], sys.argv[2]
start = json.load(open(start_path))
rows = [json.loads(l) for l in open(metrics_path) if l.startswith('{')]
rows = [r for r in rows if start['first_id'] <= r['run_id'] <= start['last_id']]

def stat(key):
    xs = [r[key] for r in rows if r.get(key) is not None]
    return {'p50': pct(xs, .5), 'p95': pct(xs, .95), 'p99': pct(xs, .99), 'max': max(xs) if xs else None, 'mean': round(sum(xs) / len(xs), 2) if xs else None}

span = (max(r['at'] for r in rows) - min(r['at'] - r['total_ms'] / 1000 for r in rows)) if rows else 0
out = {
    'ticks': len(rows),
    'runs': start['last_id'] - start['first_id'] + 1,
    'ticks_per_run': round(len(rows) / (start['last_id'] - start['first_id'] + 1), 2),
    'tick_overhead_ms': stat('overhead_ms'),
    'tick_total_ms': stat('total_ms'),
    'model_ms': stat('model_ms'),
    'queue_lag_s': stat('lag_s'),
    'queries_per_tick': stat('queries'),
    'mem_peak_mb_max': max(r['mem_peak_mb'] for r in rows) if rows else None,
    'drain_span_s': round(span, 1),
    'ticks_per_s': round(len(rows) / span, 2) if span else None,
}
print(json.dumps(out, indent=2))
