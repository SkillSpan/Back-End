# US-COMP-03 — Company Dashboard & Overview

**Status:** backend complete and tested. **Frontend is out of scope** — the contract and the one
function they need to change are in §7.
**Date:** 2026-11-02

---

## 1. Endpoint

```
GET /api/v1/company/dashboard
Authorization: Bearer <company_admin token>
Accept: application/json
```

Gates (in this order): `auth:sanctum` → `account.active` → `organization.approved` → `role:company_admin`.
The organization is resolved from the caller's **own admin membership**, never from a request
parameter — there is no id to tamper with (BR-02 / AC-13).

### 403 codes (the frontend branches on these, not on prose)

| code | meaning | UI |
|---|---|---|
| `ORGANIZATION_PENDING` | org still under review | → `/company/pending` |
| `ORGANIZATION_NOT_APPROVED` | org rejected | → access denied |
| `NOT_ORGANIZATION_ADMIN` | member, not admin | → access denied |
| `NO_ORGANIZATION` | account linked to nothing | → access denied |

### Response

```json
{
  "success": true,
  "message": "Company dashboard retrieved successfully.",
  "data": {
    "counts": { "projects": 12, "applicationsPending": 12, "unreadNotifications": 3 },
    "permissions": {
      "can_publish_projects": true, "can_view_projects": true,
      "can_review_applications": true, "can_manage_profile": true
    },
    "tasks": [ … see §2 … ],
    "projectStates": { "draft": 1, "submitted": 1, "changes_requested": 1, "approved": 0,
                       "open": 2, "selection": 1, "active": 2, "under_review": 0,
                       "completed": 3, "rejected": 0, "cancelled": 0, "archived": 1 },
    "recentProjects": [
      { "id": 1, "name": "…", "kind": "real|simulated", "track": "UX/UI",
        "state": "selection", "deadline": { "date": "2026-10-25", "closed": true },
        "filled": 3, "capacity": 5 }
    ],
    "funnel": {
      "total": 35,
      "stages": { "submitted": 12, "shortlisted": 5, "waitlisted": 2, "accepted": 6,
                  "rejected": 7, "withdrawn": 2, "expired": 1 },
      "byProject": [ { "name": "…", "count": 18 } ]
    }
  }
}
```

`projectStates` always contains **all 12** lifecycle statuses, zeros included (BR-04) — the UI can
render a stable grid instead of filling gaps itself.

## 2. Current tasks (`tasks[]`)

| type | fields | when |
|---|---|---|
| `review` | `count`, `projects[{name,count}]` | applications in `submitted` / `shortlisted` |
| `revise` | `project`, `project_id` | one row per project in `changes_requested` |
| `deadline` | `days`, `project`, `date`, `applications` | `open` + deadline within the window |
| `roles` | `open`, `filled`, `total`, `project` | `selection` with seats left |
| `draft` | `count`, `project`, `date` | one row per draft project |
| `invites` | — | **not emitted** — see §5 |

Each `type` is the key the UI maps to the screen the action happens on (BR-06 / AC-07).

## 3. The two supporting endpoints

Both already existed; three contract gaps were closed.

| endpoint | change |
|---|---|
| `POST /api/v1/auth/login/organization` | now also returns `redirect_url: "/company/dashboard"` (AC-01) — same convention as the password-reset and logout responses |
| `GET /api/v1/notifications` | accepts `?unread=1` (alias of `unread_only`) and `?per_page=` (clamped to 50, BR-11); still returns `meta.unread_count` |
| `PATCH /api/v1/notifications/{id}/read` | **new verb** on the existing handler (POST kept) — the dashboard sends PATCH |
| `GET /api/v1/organization/profile` | now returns `profile_completeness` (0–100) and `profile_fields[{key,done}]` (AC-04 / PROF-05) |

`profile_fields` keys are `info`, `contact`, `address`, `size`, `website`, `description` — each one
maps to an existing `company.ws.field.*` label. Only columns that exist on `organizations` are
counted, so a company can actually reach 100 %.

## 4. Performance (BR-12 / NFR-PERF-06)

- project counts = **one** `group by status`;
- occupied seats = **one** aggregate, reused by recent projects *and* the `roles` task (no per-project
  `ProjectCapacityPolicy` call);
- funnel = one `group by status` + one count for the expired slice;
- applications-per-project = one aggregate (the deadline task's "N applications");
- every list is `LIMIT`-ed from `config/company.php`.

New indexes (`2026_11_02_000001_add_company_dashboard_indexes`): `projects(organization_id,status)`,
`applications(project_id,status)`, `notifications(user_id,read_at)`.
`2026_11_02_000002_add_context_columns_to_notifications` adds `organization_id`, `entity_type` and
`entity_id` to `notifications` (nullable, indexed) and `NotificationService::dispatch()` takes them as
trailing optional arguments — the Database task list asks a notification to know its organization and
the record it is about. Both migrations are guarded with `Schema::hasIndex` / `Schema::hasColumn`, so
re-running is a no-op. **Applied 2026-11-02 on both hosts.**

On alwaysdata a `git pull` alone is not enough — a stale routes cache keeps serving the old route
table (`route:list` sees the new route, HTTP still 404s). The full sequence there is:

```bash
cd ~/www/skillspan && git pull && php artisan migrate --force \
  && php artisan optimize:clear && php artisan config:cache
```

## 5. Deliberately not done

| item | why |
|---|---|
| `invites` task (AC-06, US-MATCH-04) | there is no `invitations` table in this codebase yet; emitting a fake row would be worse than an honest gap. One place to change when US-MATCH-04 lands. |
| Notification idempotency (BR-10) | already satisfied — `NotificationService::dispatch()` is idempotent on `event_key` with a `unique(user_id, event_key)` index. No change needed. |
| Frontend screens behind the tasks | `/company/applicants`, `/company/projects/new` belong to US-MATCH-03 / US-PRJ-01. The dashboard links to them; it does not implement them. |

## 6. Verification

- `tests/Feature/Company/CompanyDashboardTest.php` — 23 tests / 111 assertions
- `tests/Feature/Company/CompanyNotificationApiTest.php` — 11 tests / 49 assertions
- Full suite: **1677 tests / 11 350 assertions green**; Pint clean on 14 touched files.

Covered: access (admin / learner / non-admin / pending / rejected / unauthenticated), cross-org
isolation including the admin-of-A-member-of-B case, counts per state, every task type, funnel
arithmetic (stages never exceed the total), `expired` derivation, no applicant identity in the
funnel, empty organization, unread count scoping, PATCH read persistence, unread alias, per_page
clamp, profile completeness at 33 % and 100 %, the login `redirect_url`, two denial audit events,
and two query-cost guards (no growth with row count; a bounded absolute budget).

Task-by-task coverage against the story: **`docs/US-COMP-03_COVERAGE.md`**.

## 7. Handoff to the frontend team

This story was implemented **backend-only** — the frontend is a separate team's scope. The
frontend drop already contains the whole dashboard rendered from a sample payload, so the only
thing left on their side is one function:

```js
// src/components/company/dashboard/companyDashboardApi.js
export async function fetchDashboardSummary() {
  const body = unwrapApiData(await getCompanyDashboard()) || {};
  return {
    counts: body.counts || EMPTY_SECTIONS.counts,
    tasks: Array.isArray(body.tasks) ? body.tasks : [],
    notifications: [],                                   // section 5 has its own endpoint
    projectStates: body.projectStates || {},
    recentProjects: Array.isArray(body.recentProjects) ? body.recentProjects : [],
    funnel: body.funnel || EMPTY_SECTIONS.funnel,
    permissions: body.permissions,
  };
}
```

plus the client call in `src/api.js`:

```js
export const getCompanyDashboard = () => request('/api/v1/company/dashboard', { withAuth: true });
```

Everything else they already read — `organization.profile_completeness`, `org.profile_fields`,
`PATCH .../read`, `?unread=1`, `?per_page=`, the 403 `code`s — now matches what the API actually
returns (§1–§3).
