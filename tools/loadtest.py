"""Staged closed-loop load test against the alwaysdata deployment.

Each worker thread holds ONE keep-alive HTTPS connection and loops requests for the
duration of the stage, so `concurrency` == number of simultaneous "browser sessions".
Only read-only endpoints are hit; nothing is written and the assistant endpoint is
never called (it is a billed LLM round trip with its own 20/min throttle).

Usage:
    python tools/loadtest.py [--stages 5,10,20,30] [--seconds 15]
"""

import argparse
import http.client
import json
import ssl
import statistics
import sys
import threading
import time

HOST = "skillspan.alwaysdata.net"

# Weighted mix of cheap read endpoints (path, weight).
ENDPOINTS = [
    ("/api/v1/projects", 5),
    ("/api/v1/career-roles", 2),
    ("/api/v1/notifications/unread-count", 3),
]

CTX = ssl.create_default_context()


def pick(i):
    total = sum(w for _, w in ENDPOINTS)
    n = i % total
    for path, weight in ENDPOINTS:
        if n < weight:
            return path
        n -= weight
    return ENDPOINTS[0][0]

class Worker(threading.Thread):
    def __init__(self, token, deadline, results, lock):
        super().__init__(daemon=True)
        self.token = token
        self.deadline = deadline
        self.results = results
        self.lock = lock
        self.conn = None
        self.i = 0

    def connect(self):
        if self.conn:
            try:
                self.conn.close()
            except Exception:
                pass
        self.conn = http.client.HTTPSConnection(HOST, timeout=30, context=CTX)

    def run(self):
        while time.time() < self.deadline:
            path = pick(self.i)
            self.i += 1
            started = time.time()
            status = 0
            err = None
            try:
                if self.conn is None:
                    self.connect()
                self.conn.request(
                    "GET",
                    path,
                    headers={
                        "Authorization": "Bearer " + self.token,
                        "Accept": "application/json",
                        "Connection": "keep-alive",
                    },
                )
                resp = self.conn.getresponse()
                resp.read()
                status = resp.status
            except Exception as exc:  # noqa: BLE001 - recorded, not raised
                err = type(exc).__name__
                self.conn = None
            elapsed = (time.time() - started) * 1000.0
            with self.lock:
                self.results.append((path, status, elapsed, err))


def pct(values, p):
    if not values:
        return 0.0
    ordered = sorted(values)
    k = min(len(ordered) - 1, int(round((p / 100.0) * (len(ordered) - 1))))
    return ordered[k]


def run_stage(concurrency, seconds, token):
    results = []
    lock = threading.Lock()
    started_at = time.time()
    deadline = started_at + seconds
    workers = [Worker(token, deadline, results, lock) for _ in range(concurrency)]
    for w in workers:
        w.start()
    for w in workers:
        w.join(timeout=seconds + 60)

    latencies = [r[2] for r in results]
    ok = [r for r in results if 200 <= r[1] < 300]
    bad = [r for r in results if not (200 <= r[1] < 300)]
    elapsed = max(0.001, time.time() - started_at)
    return {
        "concurrency": concurrency,
        "requests": len(results),
        "ok": len(ok),
        "errors": len(bad),
        "error_rate": round(100.0 * len(bad) / len(results), 2) if results else 0.0,
        "rps": round(len(ok) / elapsed, 2),
        "p50": round(pct(latencies, 50), 1),
        "p95": round(pct(latencies, 95), 1),
        "p99": round(pct(latencies, 99), 1),
        "max": round(max(latencies), 1) if latencies else 0.0,
        "statuses": sorted({r[1] for r in bad}),
        "errors_seen": sorted({r[3] for r in bad if r[3]}),
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--stages", default="5,10,20,30")
    parser.add_argument("--seconds", type=int, default=15)
    parser.add_argument("--token", required=True)
    parser.add_argument("--paths", default=None, help="comma list to override the mix")
    args = parser.parse_args()

    global ENDPOINTS
    if args.paths:
        ENDPOINTS = [(p.strip(), 1) for p in args.paths.split(",") if p.strip()]

    stages = [int(x) for x in args.stages.split(",")]
    print(f"target={HOST} stages={stages} seconds/stage={args.seconds}")
    print(f"{'conc':>5} {'reqs':>6} {'ok':>6} {'err%':>6} {'rps':>7} {'p50':>8} {'p95':>8} {'p99':>8} {'max':>8}")
    rows = []
    for c in stages:
        row = run_stage(c, args.seconds, args.token)
        rows.append(row)
        print(
            f"{row['concurrency']:>5} {row['requests']:>6} {row['ok']:>6} {row['error_rate']:>6} "
            f"{row['rps']:>7} {row['p50']:>8} {row['p95']:>8} {row['p99']:>8} {row['max']:>8}"
        )
        if row["statuses"] or row["errors_seen"]:
            print(f"      statuses={row['statuses']} exceptions={row['errors_seen']}")
        if row["error_rate"] > 5 or row["p95"] > 5000:
            print("      STOP: error rate > 5% or p95 > 5s at this stage")
            break

    print(json.dumps(rows, indent=2))


if __name__ == "__main__":
    main()
