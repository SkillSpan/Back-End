"""End-to-end run of the company journey: login -> project -> application -> team -> talent.

Drives real HTTP, in order, the way a company representative actually uses the product.
Run it against a throwaway database (see the skill `laravel-endpoint-live-verification`):

    export DB_CONNECTION=sqlite DB_DATABASE=.../tmp_e2e.sqlite APP_ENV=local \
           CACHE_STORE=array MAIL_MAILER=log
    php artisan serve --host=127.0.0.1 --port=8899      # run_in_background

    python tools/company_e2e.py --base http://127.0.0.1:8899

Writes are refused unless the target is loopback, so a mistyped --base cannot
mutate the shared production database.
"""

from __future__ import annotations

import json
import subprocess
import sys
from urllib.parse import urlparse

BASE = "http://127.0.0.1:8899"
BODY_FILE = "storage/app/e2e_body.json"

COMPANY = ("e2e-company@example.test", "Password123!")
ADMIN = ("e2e-admin@example.test", "Password123!")
LEARNER = ("e2e-learner@example.test", "Password123!")

results: list[tuple[str, bool, str]] = []


def http(method: str, path: str, token: str | None = None, body=None) -> tuple[int, dict]:
    cmd = [
        "curl", "-s", "-o", BODY_FILE, "-w", "%{http_code}", "--noproxy", "*",
        "--max-time", "40", "-X", method, BASE + path,
        "-H", "Accept: application/json",
        "-H", "Content-Type: application/json",
    ]
    if body is not None:
        cmd += ["-d", json.dumps(body)]
    if token:
        cmd += ["-H", f"Authorization: Bearer {token}"]
    status = subprocess.run(cmd, capture_output=True, text=True).stdout.strip()
    try:
        with open(BODY_FILE, encoding="utf-8", errors="ignore") as handle:
            payload = json.load(handle)
    except Exception:
        payload = {}
    return int(status or 0), payload


def record(name: str, ok: bool, note: str) -> None:
    results.append((name, ok, note))
    print(f"{'PASS' if ok else 'FAIL'}  {name:<46} {note}")


def check(name: str, status: int, payload: dict, expect: tuple[int, ...], extra: str = "") -> bool:
    ok = status in expect
    detail = f"HTTP {status}"
    if not ok:
        detail += " | " + json.dumps(payload)[:180]
    elif extra:
        detail += f" | {extra}"
    record(name, ok, detail)
    return ok


def login(path: str, email: str, password: str) -> str:
    status, payload = http("POST", path, body={"email": email, "password": password})
    token = (payload.get("data") or {}).get("token") or payload.get("token") or ""
    check(f"login {email}", status, payload, (200,), f"token={'yes' if token else 'NO'}")
    return token


def main() -> None:
    host = urlparse(BASE).hostname or ""
    if host not in ("127.0.0.1", "localhost"):
        sys.exit("refusing to run: this script writes, and the target is not loopback")

    company = login("/api/v1/auth/login/organization", *COMPANY)
    admin = login("/api/v1/auth/login", *ADMIN)
    learner = login("/api/v1/auth/login", *LEARNER)
    if not company:
        print("\nno company token — stopping")
        return

    # --- dashboard -------------------------------------------------------
    status, payload = http("GET", "/api/v1/company/dashboard", company)
    check("dashboard", status, payload, (200,))

    status, payload = http("GET", "/api/v1/organization/profile", company)
    check("organization profile", status, payload, (200,))

    # --- create a project ------------------------------------------------
    draft = {
        "type": "company_sponsored",
        "title": "E2E Frontend Project",
        "description": "Created by the end-to-end run.",
        "objectives": "Prove the company journey works.",
        "difficulty": 2,
        "work_mode": "remote",
        "career_role_id": 1,
        "capacity": 3,
        "start_date": "2026-12-01",
        "end_date": "2027-02-01",
        "application_deadline": "2026-11-20",
        "confidentiality": "public",
        # A project cannot be submitted without at least one mapped skill and one
        # project role (PROJECT_INCOMPLETE otherwise). Skill 10 is mapped to
        # career role 1 in the seeded fixture.
        "required_skills": [{"skill_id": 10, "minimum_required_level": 2}],
        "roles": [{"title": "Frontend Developer", "description": "E2E role"}],
    }
    status, payload = http("POST", "/api/v1/projects", company, draft)
    project_id = (payload.get("data") or {}).get("id")
    check("create project", status, payload, (200, 201), f"id={project_id}")
    if not project_id:
        return

    status, payload = http("PATCH", f"/api/v1/projects/{project_id}", company, {"title": "E2E Frontend Project v2"})
    check("edit project", status, payload, (200,))

    status, payload = http("GET", "/api/v1/company/projects", company)
    listed = [p.get("id") for p in (payload.get("data") or [])]
    check("company projects list", status, payload, (200,), f"ids={listed[:5]}")

    status, payload = http("GET", f"/api/v1/company/projects/{project_id}", company)
    check("company project detail", status, payload, (200,))

    # --- publish ---------------------------------------------------------
    status, payload = http("POST", f"/api/v1/projects/{project_id}/submit", company)
    check("submit project", status, payload, (200,), f"status={(payload.get('data') or {}).get('status')}")

    status, payload = http("POST", f"/api/v1/projects/{project_id}/approve", admin)
    check("admin approves", status, payload, (200,), f"status={(payload.get('data') or {}).get('status')}")

    status, payload = http("POST", f"/api/v1/projects/{project_id}/open", company)
    if status not in (200,):
        status, payload = http("POST", f"/api/v1/projects/{project_id}/open", admin)
    check("open project", status, payload, (200,), f"status={(payload.get('data') or {}).get('status')}")

    # --- the learner applies --------------------------------------------
    status, payload = http("POST", f"/api/v1/projects/{project_id}/applications", learner, {})
    application_id = (payload.get("data") or {}).get("id")
    check("learner applies", status, payload, (200, 201), f"id={application_id}")

    status, payload = http("GET", f"/api/v1/projects/{project_id}/applications", company)
    check("company sees applications", status, payload, (200,))

    if application_id:
        status, payload = http("GET", f"/api/v1/applications/{application_id}", company)
        check("application detail", status, payload, (200,))

        status, payload = http("POST", f"/api/v1/applications/{application_id}/decision", company, {"status": "accepted"})
        check("accept application", status, payload, (200,), f"status={(payload.get('data') or {}).get('status')}")

        # --- team --------------------------------------------------------
        status, payload = http("GET", f"/api/v1/projects/{project_id}/team", company)
        check("team view", status, payload, (200,))

        status, payload = http(
            "POST", f"/api/v1/projects/{project_id}/team/assignments", company,
            {"members": [{"application_id": application_id}]},
        )
        check("assign to team", status, payload, (200, 201))

        status, payload = http("POST", f"/api/v1/projects/{project_id}/start", company)
        check("start project", status, payload, (200,), f"status={(payload.get('data') or {}).get('status')}")

        status, payload = http("POST", f"/api/v1/projects/{project_id}/transitions", company, {"to": "completed"})
        check("complete project", status, payload, (200,), f"status={(payload.get('data') or {}).get('status')}")

        status, payload = http("POST", f"/api/v1/projects/{project_id}/transitions", company, {"to": "archived"})
        check("archive project", status, payload, (200,), f"status={(payload.get('data') or {}).get('status')}")

    # --- clone -----------------------------------------------------------
    status, payload = http("POST", f"/api/v1/projects/{project_id}/clone", company, {"title": "E2E Clone"})
    check("clone project", status, payload, (200, 201), f"id={(payload.get('data') or {}).get('id')}")

    # --- talent ----------------------------------------------------------
    status, payload = http("GET", "/api/v1/talent?per_page=5", company)
    check("talent search", status, payload, (200,))
    rows = payload.get("data") or []
    student_id = (rows[0] or {}).get("id") if rows else None
    print(f"      discoverable students: {[r.get('id') for r in rows][:5]} -> using {student_id}")

    status, payload = http("GET", "/api/v1/talent/filters", company)
    check("talent filters", status, payload, (200,))

    status, payload = http("POST", "/api/v1/talent/lists", company, {"name": "E2E shortlist"})
    list_id = (payload.get("data") or {}).get("id")
    check("create talent list", status, payload, (200, 201), f"id={list_id}")

    if list_id:
        status, payload = http("POST", f"/api/v1/talent/lists/{list_id}/candidates", company, {"student_id": student_id})
        check("add candidate to list", status, payload, (200, 201))

        status, payload = http("GET", f"/api/v1/talent/lists/{list_id}", company)
        check("read talent list", status, payload, (200,))

        status, payload = http("PATCH", f"/api/v1/talent/lists/{list_id}", company, {"name": "E2E shortlist v2"})
        check("rename talent list", status, payload, (200,))

        status, payload = http("DELETE", f"/api/v1/talent/lists/{list_id}/candidates/{student_id}", company)
        check("remove candidate", status, payload, (200, 204))

        status, payload = http("DELETE", f"/api/v1/talent/lists/{list_id}", company)
        check("delete talent list", status, payload, (200, 204))

    if student_id:
        pair = [r.get("id") for r in rows][:2]
        status, payload = http("POST", "/api/v1/talent/compare", company, {"student_ids": pair})
        check("compare candidates", status, payload, (200, 201), f"ids={pair}")

        status, payload = http("POST", f"/api/v1/talent/candidates/{student_id}/notes", company, {"body": "E2E note", "note": "E2E note"})
        check("add talent note", status, payload, (200, 201))

        status, payload = http("GET", f"/api/v1/talent/candidates/{student_id}/notes", company)
        check("read talent notes", status, payload, (200,))

        status, payload = http("POST", f"/api/v1/talent/candidates/{student_id}/contact", company, {"message": "Hello from E2E"})
        check("contact request", status, payload, (200, 201))

    status, payload = http("GET", "/api/v1/talent/funnel", company)
    check("talent funnel", status, payload, (200,), json.dumps(payload.get("data"))[:60])

    # --- notifications ---------------------------------------------------
    status, payload = http("GET", "/api/v1/notifications", company)
    check("notifications", status, payload, (200,))
    status, payload = http("GET", "/api/v1/notifications/unread-count", company)
    check("unread count", status, payload, (200,))

    # --- summary ---------------------------------------------------------
    passed = sum(1 for _, ok, _ in results if ok)
    print()
    print(f"steps: {len(results)}  passed: {passed}  failed: {len(results) - passed}")
    for name, ok, note in results:
        if not ok:
            print(f"  FAILED  {name}: {note}")


if __name__ == "__main__":
    args = sys.argv[1:]
    if "--base" in args:
        BASE = args[args.index("--base") + 1]
    main()
