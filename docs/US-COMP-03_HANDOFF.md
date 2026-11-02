# US-COMP-03 — handoff for the backend team

**What this is:** everything that changed in the backend for *US-COMP-03 — Company Dashboard &
Overview*, what to know before touching it, and what is deliberately not done.
**Branch:** `feature/authentication` · **Commits:** `d25d7db` (code), `a3c5ed7` (doc correction)
**Status:** deployed and live on both hosts · suite **1687 tests / 11 386 assertions green** · Pint clean

---

## 1. The endpoint

```
GET /api/v1/company/dashboard
Authorization: Bearer <company_admin token>
Accept: application/json
```

Gates, in order: `auth:sanctum` → `account.active` → `organization.approved` → `role:company_admin`.
The organization is resolved from the caller's **own admin membership** inside the controller — never
from a request parameter, so there is no id a caller could swap (BR-02 / AC-13).

| code | when | UI reaction |
|---|---|---|
| `ORGANIZATION_PENDING` | org still under review | → `/company/pending` |
| `ORGANIZATION_NOT_APPROVED` | org rejected | access denied |
| `NOT_ORGANIZATION_ADMIN` | member, not admin | access denied |
| `NO_ORGANIZATION` | account linked to nothing | access denied |
| `COMPANY_ADMIN_ONLY` | (middleware) caller has no `company_admin` role | access denied |

Payload: `counts`, `permissions`, `tasks[]`, `projectStates`, `recentProjects[]`, `funnel`.
Full shapes in `docs/US-COMP-03_IMPLEMENTATION.md` §1–§2; task-by-task coverage against the sprint
story in `docs/US-COMP-03_COVERAGE.md`.

## 2. Files

**New**

| path | what |
|---|---|
| `app/Services/Company/CompanyDashboardService.php` | every figure in the payload. 4 SQL aggregates, no per-project policy call |
| `app/Http/Controllers/Api/Company/CompanyDashboardController.php` | the gate + organization resolution + audit on denial |
| `config/company.php` | deadline window (7 d), list limits, notification page sizes — all env-tunable |
| `database/migrations/2026_11_02_000001_add_company_dashboard_indexes.php` | 3 indexes, `hasIndex`-guarded |
| `database/migrations/2026_11_02_000002_add_context_columns_to_notifications.php` | 3 nullable columns on `notifications` |
| `tests/Feature/Company/CompanyDashboardTest.php` | 23 tests |
| `tests/Feature/Company/CompanyNotificationApiTest.php` | 11 tests |
| `docs/US-COMP-03_IMPLEMENTATION.md`, `docs/US-COMP-03_COVERAGE.md` | contract + coverage review |

**Modified**

| path | change |
|---|---|
| `routes/api.php` | the `company` route group + `PATCH /notifications/{id}/read` |
| `app/Http/Controllers/Api/AuthController.php` | `loginOrganization` returns `redirect_url: "/company/dashboard"` (AC-01) |
| `app/Http/Controllers/Api/NotificationController.php` | `?unread=1` alias, `?per_page=` clamped to 50 |
| `app/Http/Controllers/Api/OrganizationController.php` | `profile_completeness` + `profile_fields[]` on `GET /organization/profile` |
| `app/Http/Middleware/EnsureOrganizationIsApproved.php` | adds `code`, writes an audit event on refusal |
| `app/Models/Notification.php` | new fillable fields, `organization()`, `entity()` (`morphTo`) |
| `app/Services/NotificationService.php` | `dispatch()` takes `organizationId` / `entityType` / `entityId` as **trailing optional** args |

Nothing in the frontend was touched — see §6.

## 3. Database

```sql
-- 000001
projects(organization_id, status)
applications(project_id, status)
notifications(user_id, read_at)

-- 000002
notifications + organization_id, entity_type, entity_id   -- all nullable, both indexed
```

Both migrations are idempotent (`Schema::hasIndex()` / `Schema::hasColumn()`), so a re-run is a no-op
and a database that already has them by hand will not error.

The three notification columns exist because the story's Database task list asks a notification to
carry its organization and the record it is about. Nothing in US-COMP-03 reads them yet — they are
there for US-PRJ-01, which will pass them to `dispatch()`. Rows written before the migration, and
mentor/student notifications that have no organization, stay valid.

## 4. Performance

- project counts = **one** `group by status` (12 keys, zero-filled);
- occupied seats = **one** aggregate, reused by `recentProjects` **and** the `roles` task — no
  per-project `ProjectCapacityPolicy` call;
- funnel = one `group by status` + one count for the expired slice;
- applications-per-project = one aggregate;
- every list is `LIMIT`-ed from `config/company.php`.

Two tests hold the line: `test_the_query_count_does_not_grow_with_the_number_of_projects` (4 projects
vs 24 → **identical** count) and `test_the_whole_request_costs_a_bounded_number_of_queries` (≤ 20,
auth included). If you add a query to the service, the first one will tell you.

## 5. Deploying

**Render** — push to the branch; the `Dockerfile` runs `migrate --force` on boot. Marker:
`/api/v1/company/dashboard` goes **404 → 401**.

**alwaysdata** — no auto-deploy, and `git pull` alone is **not enough**:

```bash
cd ~/www/skillspan && git pull && php artisan migrate --force \
  && php artisan optimize:clear && php artisan config:cache
```

⚠️ **`optimize:clear` is not optional.** A stale routes cache on that host keeps serving the old route
table: `php artisan route:list --path=company` shows the route, and HTTP still answers **404**. That
is exactly what happened on 2026-11-02 — pull fine, migrations fine, endpoint 404 until
`optimize:clear`. `config:clear` does **not** clear it.

⚠️ `git pull` there aborts on `storage/api-docs/api-docs.json` — a **generated** Swagger file that is
tracked in git and rewritten on the host. Back it up, then `git checkout --` that **one path**:

```bash
cp storage/api-docs/api-docs.json ~/api-docs.json.bak
git checkout -- storage/api-docs/api-docs.json
```

Live state after deploy (real probes, no writes): no token → **401**; learner token → **403
`COMPANY_ADMIN_ONLY`**; unknown sibling path → **404**.

## 6. Not done, on purpose

| item | why |
|---|---|
| `invites` task (AC-06, US-MATCH-04) | there is no `invitations` table in this codebase. `CompanyDashboardService::tasks()` carries a comment marking the one place to add it |
| Frontend wiring | other team's scope. `docs/US-COMP-03_IMPLEMENTATION.md` §7 has the two lines they need |
| `/company/applicants`, `/company/projects/new` | US-MATCH-03 / US-PRJ-01 — the dashboard links to them, it does not build them |
| Notification idempotency (BR-10) | already satisfied by `NotificationService::dispatch()` + `unique(user_id, event_key)`; no change was needed |
| Dashboard list pagination (BR-11) | notifications are paginated; the dashboard's own lists are bounded summaries (`LIMIT`), a "page 2 of the recent 5 projects" has no meaning |

## 7. Before you merge anything

- ⚠️ **`git merge` on this checkout has destroyed the local object store once.** On 2026-11-02 a
  `git merge` killed by SIGTERM left `.git/refs/` + `packed-refs` gone and every commit after the last
  pack pruned; the index became unreadable. Recovery took eight steps (documented in the project
  memory, `REFERENCE_engine_gotchas.md` §2b). **Copy the files you touched out of the repo before any
  merge**, and never `git checkout HEAD -- .` while recovering — it eats your own edits.
- The remote branch is `refs/heads/feature/authentication` (lowercase).
- Merge, never rebase.
