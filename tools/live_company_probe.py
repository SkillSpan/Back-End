"""Live probe of every company-scoped endpoint on the deployed API.

Read-only by construction:
  * every method is first probed with NO token  -> the 401 comes from the auth
    middleware, before any controller runs, so nothing is written;
  * only GETs are repeated with a real token, because a GET cannot mutate;
  * POST/PATCH/DELETE are never sent with a token here.

Verdicts:
  EXISTS   the route answered something other than 404 (401 is fine: it means
           the route is registered and guarded)
  MISSING  the route answered 404 with no token
  GUARD    a GET with a learner token answered 403 COMPANY_ADMIN_ONLY
  OPEN      a GET with a learner token answered 200 (a route learners may use)

Usage: python tools/live_company_probe.py [--json]
"""

from __future__ import annotations

import json
import os
import subprocess
import sys

BASE = os.environ.get("API_BASE", "https://skillspan.alwaysdata.net")
ENV_FILE = os.path.join(os.path.dirname(__file__), "..", "postman_environment_mentor_e2e.json")

# Company scope only. Admin and learner-only routes are deliberately excluded.
ROUTES: list[tuple[str, str]] = [
    ("GET", "/api/v1/company/dashboard"),
    ("GET", "/api/v1/company/projects"),
    ("GET", "/api/v1/company/projects/1"),
    ("GET", "/api/v1/organization/profile"),
    ("GET", "/api/v1/profile/me"),
    ("PATCH", "/api/v1/profile/me"),
    ("POST", "/api/v1/company/profile/documents"),
    ("GET", "/api/v1/company/profile/documents/1"),
    ("POST", "/api/v1/company/profile/logo"),
    ("DELETE", "/api/v1/company/profile/logo"),
    ("GET", "/api/v1/projects"),
    ("POST", "/api/v1/projects"),
    ("GET", "/api/v1/projects/1"),
    ("PATCH", "/api/v1/projects/1"),
    ("POST", "/api/v1/projects/1/clone"),
    ("POST", "/api/v1/projects/1/transitions"),
    ("POST", "/api/v1/projects/1/start"),
    ("POST", "/api/v1/projects/1/submit"),
    ("GET", "/api/v1/projects/1/applications"),
    ("GET", "/api/v1/projects/1/team"),
    ("POST", "/api/v1/projects/1/team/assignments"),
    ("DELETE", "/api/v1/projects/1/team/assignments/1"),
    ("POST", "/api/v1/projects/1/team/invitations"),
    ("GET", "/api/v1/applications"),
    ("GET", "/api/v1/applications/1"),
    ("POST", "/api/v1/applications/1/decision"),
    ("GET", "/api/v1/team-invitations/1"),
    ("POST", "/api/v1/team-invitations/1/response"),
    ("GET", "/api/v1/talent"),
    ("GET", "/api/v1/talent/filters"),
    ("GET", "/api/v1/talent/funnel"),
    ("GET", "/api/v1/talent/1"),
    ("POST", "/api/v1/talent/compare"),
    ("GET", "/api/v1/talent/lists"),
    ("POST", "/api/v1/talent/lists"),
    ("GET", "/api/v1/talent/lists/1"),
    ("PATCH", "/api/v1/talent/lists/1"),
    ("DELETE", "/api/v1/talent/lists/1"),
    ("POST", "/api/v1/talent/lists/1/candidates"),
    ("DELETE", "/api/v1/talent/lists/1/candidates/1"),
    ("GET", "/api/v1/talent/candidates/1/notes"),
    ("POST", "/api/v1/talent/candidates/1/notes"),
    ("POST", "/api/v1/talent/candidates/1/contact"),
    ("GET", "/api/v1/notifications"),
    ("GET", "/api/v1/notifications/unread-count"),
    ("POST", "/api/v1/notifications/read-all"),
    ("POST", "/api/v1/notifications/1/read"),
]


def tokens() -> dict[str, str]:
    try:
        with open(ENV_FILE, encoding="utf-8") as handle:
            data = json.load(handle)
    except OSError:
        return {}
    values = {v["key"]: str(v.get("value") or "") for v in data.get("values", [])}
    return {
        "learner": values.get("learner_token") or values.get("student_token") or "",
        "mentor": values.get("mentor_token") or values.get("token") or "",
    }


def call(method: str, path: str, token: str | None) -> tuple[int, str]:
    cmd = [
        "curl", "-s", "-o", "storage/app/probe_body.json", "-w", "%{http_code}",
        "--noproxy", "*", "--max-time", "25", "-X", method,
        BASE + path,
        "-H", "Accept: application/json",
        "-H", "Content-Type: application/json",
    ]
    if method in ("POST", "PATCH", "PUT"):
        cmd += ["-d", "{}"]
    if token:
        cmd += ["-H", f"Authorization: Bearer {token}"]
    status = subprocess.run(cmd, capture_output=True, text=True).stdout.strip()
    try:
        with open("storage/app/probe_body.json", encoding="utf-8", errors="ignore") as handle:
            body = handle.read()
    except OSError:
        body = ""
    try:
        parsed = json.loads(body)
        code = parsed.get("code") or parsed.get("message") or ""
    except Exception:
        code = ""
    return int(status or 0), str(code)[:38]


def main() -> None:
    tokens_ = tokens()
    rows = []
    for method, path in ROUTES:
        anon_status, anon_code = call(method, path, None)
        token_status = token_code = None
        if method == "GET" and tokens_.get("learner"):
            token_status, token_code = call(method, path, tokens_["learner"])

        if anon_status == 404:
            verdict = "MISSING"
        elif token_status == 403:
            verdict = "GUARD"
        elif token_status == 200:
            verdict = "OPEN"
        elif token_status == 401:
            verdict = "AUTH"
        else:
            verdict = "EXISTS"

        rows.append({
            "method": method, "path": path, "anon": anon_status, "anon_code": anon_code,
            "learner": token_status, "learner_code": token_code, "verdict": verdict,
        })

    if "--json" in sys.argv:
        print(json.dumps(rows, indent=2))
        return

    print(f"{'METHOD':7} {'PATH':48} {'ANON':5} {'LEARNER':8} VERDICT")
    print("-" * 92)
    for row in rows:
        learner = "-" if row["learner"] is None else str(row["learner"])
        print(f"{row['method']:7} {row['path']:48} {row['anon']:<5} {learner:8} {row['verdict']}")

    missing = [r for r in rows if r["verdict"] == "MISSING"]
    print()
    print(f"routes probed : {len(rows)}")
    print(f"missing       : {len(missing)}")
    for row in missing:
        print(f"  {row['method']} {row['path']}")


if __name__ == "__main__":
    main()
