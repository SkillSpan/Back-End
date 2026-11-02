# US-COMP-03 — Coverage review against the story

**Question:** are all of US-COMP-03's tasks implemented?
**Answer:** every **Backend — Laravel**, **API** and **Database** task is implemented and tested.
One item is honestly partial (the `invites` task — no invitations table exists yet), and three task
lists belong to other teams (UI/UX design, Frontend, and the frontend-only half of Testing).
**Date:** 2026-11-02 · **Source:** `sprint_4_company.pdf`, §US-COMP-03 (pp. 4–16).

Legend: ✅ done · ⚠️ partial (reason given) · ⬜ not ours / not backend

---

## 1. Dashboard sections

| # | Section | State | Where it is served |
|---|---|---|---|
| 1 | Organization Status — name, verification status, completeness %, missing fields | ✅ | `GET /api/v1/organization/profile` — extended this story with `profile_completeness` + `profile_fields[]`; the dashboard's Section 1 request is exactly this call (`fetchOrganizationStatus`) |
| 2 | Current Tasks | ⚠️ | `GET /company/dashboard` → `tasks[]`, 5 of 6 kinds. `invites` is not emitted — see §5 |
| 3 | Projects Overview — counts per lifecycle state + recent projects with capacity | ✅ | `projectStates` (all 12 statuses, zero-filled) + `recentProjects` (deadline, filled/capacity) |
| 4 | Project & Application Funnel — 7 stages, per project and overall | ✅ | `funnel.stages` + `funnel.byProject`, aggregate only |
| 5 | Notifications — latest, read/unread, link to history | ✅ | `GET /notifications` (paginated, `meta.unread_count`), `GET /notifications/unread-count`, `POST /notifications/read-all` |
| 6 | Quick Links | ✅ | `permissions{can_publish_projects, can_view_projects, can_review_applications, can_manage_profile}` — the exact keys `quickLinks.js` filters on |

Section 1 is a separate endpoint by design: the dashboard page already issues that request for its
own card, so returning the same fields twice in one payload would only create two sources of truth.
The fields it needed did not exist before this story — that is what was added.

## 2. Business rules

| Rule | State | Evidence |
|---|---|---|
| BR-01 approved-org reps only | ✅ | route gates `auth:sanctum → account.active → organization.approved → role:company_admin` |
| BR-02 organization scope | ✅ | organization resolved from the caller's admin pivot, never from a parameter |
| BR-03 server-side figures | ✅ | the whole payload is computed in `CompanyDashboardService` |
| BR-04 approved status dictionary | ✅ | `Project::STATUSES`, all 12 keys, unknown legacy `closed` dropped |
| BR-05 aggregate funnel only | ✅ | no applicant id/name/e-mail in the payload — asserted by test |
| BR-06 task → its screen | ✅ | each row carries `type` + `project_id`; `type` is the key the UI maps to a route |
| BR-07 links only for permitted actions | ✅ | `permissions` block |
| BR-08 notifications per recipient | ✅ | `user_id` scoping in the controller and in `unreadNotificationCount()` |
| BR-09 read state persists | ✅ | `read_at`; `PATCH /notifications/{id}/read` |
| BR-10 no duplicate notifications | ✅ | pre-existing `NotificationService::dispatch()` + `unique(user_id, event_key)` |
| BR-11 pagination with a configurable max | ⚠️ | notifications: `page`/`per_page`, clamped to `company.notifications.max_per_page` (50). Dashboard lists are `LIMIT`-ed summaries, not page-numbered — a page-2 of "recent 5 projects" has no meaning |
| BR-12 no unbounded scans / N+1 | ✅ | 4 aggregates + every list `LIMIT`-ed; two tests (§6) |
| BR-13 text labels, not colour alone | ⬜ | presentation — `TasksCard` renders a `cw-pill` label per state |
| BR-14 empty / loading / error per section | ⬜ | presentation — `SectionState` + per-section `load()` |
| BR-15 English + Arabic RTL | ⬜ | presentation — `useCompanyI18n` |

## 3. Main scenarios

| Scenario | State | Note |
|---|---|---|
| 1 — open dashboard after login | ✅ | login states `redirect_url: /company/dashboard`; endpoint validates session + role + org status |
| 2 — new organization, no projects | ✅ | `counts.projects = 0` makes the UI render its "create your first project" block, and `profile.completeness < 100` adds the "complete your profile" row — both fed by fields this story added |
| 3 — act on a task | ✅ | task row → `type` → route; the target screens are US-MATCH-03 / US-PRJ-01 |
| 4 — review notifications | ✅ | list + unread state + `link` + `PATCH read` + paginated history |
| 5 — unauthorized / expired | ✅ | 401 → session-expired; 403 with a `code`; **audit event written** for both denial paths |
| 6 — partial section failure | ⬜ | presentation — each section loads and retries independently |

## 4. Acceptance criteria

AC-01 ✅ · AC-02 ✅ · AC-03 ✅ · AC-04 ✅ · AC-05 ✅ · AC-06 ⚠️ (no `invites`) · AC-07 ✅ ·
AC-08 ✅ · AC-09 ✅ · AC-10 ✅ · AC-11 ✅ · AC-12 ✅ · AC-13 ✅ · AC-14 ⬜ · AC-15 ⬜ ·
AC-16 ✅ (measured, §6) · AC-17 ⬜

## 5. Task lists

### Backend — Laravel (15/15 implemented, 1 deployment step pending)

| Task | State |
|---|---|
| Company dashboard summary endpoint | ✅ |
| Validate authentication, company role, organization status | ✅ |
| Enforce organization scope on all dashboard queries | ✅ |
| Aggregate project counts by lifecycle state | ✅ |
| Current Tasks queries | ✅ 5 of the 6 listed kinds |
| Configurable deadline-approaching window | ✅ `company.dashboard.deadline_window_days` (7) |
| Funnel counts by project and by status | ✅ |
| Return verification status and profile completeness | ✅ on `GET /organization/profile` (§1) |
| Notification list endpoint | ✅ pre-existing, extended (`unread` alias, `per_page`) |
| Mark-as-read endpoint | ✅ `PATCH` added next to the existing `POST` |
| Idempotent notification creation service | ✅ pre-existing `NotificationService::dispatch()` — nothing to add |
| Pagination with a configurable maximum page size | ✅ clamped to 50 |
| Optimize queries with indexes and eager loading | ⚠️ indexes written, **not applied to alwaysdata yet**; nothing to eager-load (no relations are touched) |
| Audit events for denied access | ✅ `company.dashboard.denied`, `organization.access_denied` |

### Database

| Field | State |
|---|---|
| Notification ID, recipient user ID, event type (`category`), idempotency key (`event_key`), title/body, link target (`link`), read state + timestamp (`read_at`), created timestamp | ✅ all present |
| Organization ID | ✅ `organization_id` (nullable, indexed) — added by `2026_11_02_000002` |
| Related entity type / entity ID | ✅ `entity_type` + `entity_id` (nullable, indexed, exposed as a `morphTo` relation) |

The three new columns are nullable, so every row written before them — and every mentor/student
notification that has no organization — stays valid. `NotificationService::dispatch()` now accepts
`organizationId`, `entityType` and `entityId` as trailing optional arguments, so no existing call
site changed.

Indexes: `projects(organization_id,status)`, `applications(project_id,status)`,
`notifications(user_id,read_at)`, plus `notifications(organization_id)` and
`notifications(entity_type,entity_id)`. Both migrations are guarded (`Schema::hasIndex()` /
`Schema::hasColumn()`), so a re-run is a no-op.
**Applied 2026-11-02** on both hosts — Render on deploy (`migrate --force` in the `Dockerfile`) and
alwaysdata manually (`git pull && php artisan migrate --force && php artisan optimize:clear &&
php artisan config:cache`).

### API

| Endpoint | State |
|---|---|
| `GET /api/v1/company/dashboard` | ✅ |
| `GET /api/v1/notifications` | ✅ |
| `PATCH /api/v1/notifications/{id}/read` | ✅ |
| Auth required / role + org scope / aggregate funnel only / paginated lists / clear error codes | ✅ (`ORGANIZATION_PENDING`, `ORGANIZATION_NOT_APPROVED`, `NOT_ORGANIZATION_ADMIN`, `NO_ORGANIZATION`) |

### Testing (13 of 18 backend-testable bullets)

| Bullet | State |
|---|---|
| Redirect to the dashboard after login | ✅ |
| Access by an approved representative | ✅ |
| Access denied for a learner | ✅ |
| Access denied for a user from another organization, direct API call | ✅ |
| Project counts by lifecycle state | ✅ |
| Current Tasks accuracy | ✅ every task kind + the "full selection → no `roles` row" and "deadline outside the window → no row" cases |
| Funnel aggregate accuracy | ✅ stages sum == total |
| Absence of personal data in funnel payloads | ✅ |
| Notification display | ✅ |
| Mark-as-read and persistence | ✅ |
| Notification history pagination | ✅ incl. the 50 clamp |
| Notification idempotency on retried events | ✅ `test_dispatch_is_idempotent_on_event_key` |
| Empty state for a new organization | ✅ |
| Pagination limits | ✅ |
| Dashboard query performance | ✅ growth test + absolute budget |
| Section-level error handling | ⬜ frontend |
| Responsive and RTL layout | ⬜ frontend |
| Keyboard navigation and text labels | ⬜ frontend |

### UI/UX (12 tasks) and Frontend (13 tasks) — ⬜ other teams

Not touched, by instruction. The frontend drop already contains all thirteen frontend items rendered
from sample data; the only thing it lacks is the one function in
`docs/US-COMP-03_IMPLEMENTATION.md` §7.

## 6. Verification

| | |
|---|---|
| `tests/Feature/Company/CompanyDashboardTest.php` | 23 tests |
| `tests/Feature/Company/CompanyNotificationApiTest.php` | 11 tests |
| Full suite | **1677 tests / 11 350 assertions — all passing** (2 PHPUnit deprecations, pre-existing, not in the touched paths) |
| Pint | PASS — 10 files |
| NFR-PERF-06 | `test_the_query_count_does_not_grow_with_the_number_of_projects` (4 projects vs 24 → identical count) and `test_the_whole_request_costs_a_bounded_number_of_queries` (≤ 20, auth included) |
| NFR-PERF-01 | measured on the live host — see `LOAD_TEST_REPORT.md` |

## 7. What is left, in one line each

1. ~~Apply the migrations~~ — **done 2026-11-02 on both hosts**; `/api/v1/company/dashboard` answers
   401 unauthenticated and 403 `COMPANY_ADMIN_ONLY` with a non-company token.
2. Frontend team: the two-line wiring in `docs/US-COMP-03_IMPLEMENTATION.md` §7.
3. When US-MATCH-04 lands: emit the `invites` task in `CompanyDashboardService::tasks()` — the single
   place that has to change, already marked with a comment.
4. When US-PRJ-01 starts emitting company notifications: pass `organizationId` / `entityType` /
   `entityId` to `NotificationService::dispatch()`. The columns and the service arguments are already
   there; nothing else to add.
5. One manual check nobody can automate: load the dashboard in Postman with a real `company_admin`
   token and eyeball the payload. Every other path (401, 403, cross-org, empty org) is covered by a
   test or a live probe.
