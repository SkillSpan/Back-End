"""Read-only live sweep of the company screens with a REAL company account.

No writes at all: only GETs, and detail routes are probed with ids that the
list responses themselves returned, so nothing is created or changed.

    python tools/live_company_readonly.py --email a@b.c --password '...'
    python tools/live_company_readonly.py --base https://host --email ... --password ...

Prints one line per screen: what the company actually sees, live.
"""

from __future__ import annotations

import json
import subprocess
import sys

BASE = "https://skillspan.alwaysdata.net"
BODY = "storage/app/ro_body.json"


def http(method: str, path: str, token: str | None = None, body=None) -> tuple[int, dict]:
    cmd = [
        "curl", "-s", "-o", BODY, "-w", "%{http_code}", "--noproxy", "*", "--max-time", "40",
        "-X", method, BASE + path,
        "-H", "Accept: application/json", "-H", "Content-Type: application/json",
    ]
    if body is not None:
        cmd += ["-d", json.dumps(body)]
    if token:
        cmd += ["-H", f"Authorization: Bearer {token}"]
    status = subprocess.run(cmd, capture_output=True, text=True).stdout.strip()
    try:
        with open(BODY, encoding="utf-8", errors="ignore") as handle:
            return int(status or 0), json.load(handle)
    except Exception:
        return int(status or 0), {}


def rows(payload: dict) -> list:
    data = payload.get("data")
    if isinstance(data, list):
        return data
    if isinstance(data, dict):
        for key in ("data", "items", "projects", "students", "lists", "applications", "notifications"):
            if isinstance(data.get(key), list):
                return data[key]
    return []


def main() -> None:
    args = sys.argv[1:]
    global BASE
    if "--base" in args:
        BASE = args[args.index("--base") + 1]
    email = args[args.index("--email") + 1] if "--email" in args else ""
    password = args[args.index("--password") + 1] if "--password" in args else ""

    status, payload = http("POST", "/api/v1/auth/login/organization", None, {"email": email, "password": password})
    data = payload.get("data") or {}
    token = data.get("token") or ""
    user = data.get("user") or {}
    orgs = data.get("organizations") or user.get("organizations") or []
    print(f"login                HTTP {status}  user={user.get('id')} org={[(o.get('id'), o.get('verification_status')) for o in orgs]}")
    if not token:
        sys.exit("no token")
    print()

    checks: list[tuple[str, int, str]] = []

    def get(label: str, path: str) -> dict:
        status, payload = http("GET", path, token)
        detail = ""
        if status == 200:
            found = rows(payload)
            if found:
                detail = f"{len(found)} rows"
            elif isinstance(payload.get("data"), dict):
                keys = [k for k in payload["data"].keys()][:6]
                detail = f"keys={keys}"
        else:
            detail = json.dumps(payload)[:110]
        checks.append((label, status, detail))
        print(f"{label:<34} HTTP {status:<4} {detail}")
        return payload

    get("dashboard", "/api/v1/company/dashboard")
    get("organization profile", "/api/v1/organization/profile")
    get("my profile", "/api/v1/profile/me")
    get("company projects", "/api/v1/company/projects")
    projects = get("projects", "/api/v1/projects?per_page=5")
    get("applications", "/api/v1/applications?per_page=5")
    get("talent search", "/api/v1/talent?per_page=5")
    get("talent filters", "/api/v1/talent/filters")
    get("talent funnel", "/api/v1/talent/funnel")
    get("talent lists", "/api/v1/talent/lists")
    get("notifications", "/api/v1/notifications?per_page=5")
    get("unread count", "/api/v1/notifications/unread-count")

    # Detail routes, using ids the lists just returned (still read-only).
    project_ids = [p.get("id") for p in rows(projects)][:2]
    for pid in project_ids:
        get(f"project {pid} detail", f"/api/v1/projects/{pid}")
        get(f"project {pid} team", f"/api/v1/projects/{pid}/team")
        get(f"project {pid} applications", f"/api/v1/projects/{pid}/applications")

    print()
    bad = [c for c in checks if c[1] != 200]
    print(f"screens: {len(checks)}  ok: {len(checks) - len(bad)}  not-200: {len(bad)}")
    for label, status, detail in bad:
        print(f"  {label}: HTTP {status} {detail}")


if __name__ == "__main__":
    main()
