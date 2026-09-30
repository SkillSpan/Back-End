# Implementation Report — SkillSpan Pilot: Project Management Workflow

**Working directory**: `D:\newnn\Back-End` (all commands run from here; nothing outside was touched)
**Branch**: `feature/authentication` @ `4d5021e` · **Date**: 2026-09-30

## 0. Audit result (what existed before any edit)

**EXISTING (preserved, not rebuilt)**
- `Project` model + `projects` migration, `project_required_skills`, `project_eligibility_constraints`,
  `project_roles`, `applications`, `project_teams`, `project_team_members`, `project_milestones`,
  `project_matching_snapshots`, `rubric_id`, `version`, `approved_by`, `approved_at`.
- `ProjectController` — **learner-facing catalog only** (`index`, `show`). Read-only; untouched.
- `ApplicationController` + `ApplicationService` + `Application` (full lifecycle, duplicate-active
  prevention, capacity, eligibility, idempotency, audit, notifications). Untouched.
- Matching: `ProjectMatchingService`, `ProjectMatchingPayloadBuilder`, `ProjectMatchingSnapshotService`,
  `ProjectMatchingRecommendationService`. Untouched.
- `ProjectAccessService`, `ProjectAvailabilityService`, `ProjectEligibilityService`,
  `ProjectCapacityPolicy`, `ProjectResource`. Untouched.
- Auth: Sanctum + role middleware (`role`, `admin`, `account.active`, `organization.approved`).
  **No Policies/Gates directory exists** — the established pattern is middleware + explicit checks in
  the service. Followed.

**MISSING (this is what was built)** — every write-side endpoint: create, update, submit, approve,
request changes, reject, open, plus the lifecycle/transition rules themselves.

**CONFLICT FOUND** — `projects.status` enum was `draft, pending_review, open, closed, in_progress,
completed, archived`, i.e. it contained `pending_review` and `in_progress` (forbidden as names) and
the undecided `closed`. Analysis of every usage is in §4.

---

## 1. Files Changed

| Path | What changed | Why |
|---|---|---|
| `app/Models/Project.php` | Added `STATUS_*` constants, `STATUSES`, `EDITABLE_STATUSES`, `TRANSITIONS`, `VERSIONED_FIELDS`, `canTransitionTo()`, `isEditable()` | Single source of truth for status, mirroring the existing `Application` model convention (constants + `TRANSITIONS` map). Fixes requirement 18: migration/model/service/validation/tests now read one list. |
| `app/Services/MentorStudentService.php` | `in_array($project->status, ['open', 'in_progress'])` → `[Project::STATUS_OPEN, Project::STATUS_ACTIVE]` | `in_progress` is no longer a project status; without this, mentor↔student connections would silently break for `active` projects. |
| `routes/api.php` | Added the `ProjectManagementController` import + 7 routes in two groups | The workflow endpoints. |
| `tests/Feature/Projects/ProjectAvailabilityServiceTest.php` | `pending_review` → `Project::STATUS_SUBMITTED`; `in_progress` → `Project::STATUS_ACTIVE` (2 tests renamed) | The old names no longer exist. The `closed` test was **left untouched** (see §9). |
| `app/Http/Controllers/Api/Admin/OrganizationController.php` | *(carried over)* | Work from the previous task in this session; left exactly as-is, not reverted. |
| `tests/Feature/Admin/AdminOrganizationTest.php` | *(carried over)* | Same. |

## 2. Files Created

| Path | Purpose |
|---|---|
| `database/migrations/2026_09_30_000001_unify_projects_status_lifecycle.php` | Unifies `projects.status` on the official lifecycle. |
| `app/Exceptions/ProjectException.php` | Domain failure type (`status` + stable `codeName` + `details`), same shape as `ApplicationException`. |
| `app/Services/Projects/ProjectLifecycleService.php` | All workflow orchestration: authorization, transition validation, completeness checks, version bump, audit rows. |
| `app/Http/Controllers/Api/ProjectManagementController.php` | The 7 endpoints; maps `ProjectException` onto the shared error envelope. |
| `app/Http/Requests/StoreProjectRequest.php` | Creation validation (shape/ranges) + the shared `VALIDATION_ERROR` envelope. |
| `app/Http/Requests/UpdateProjectRequest.php` | Extends the above; top-level rules become optional for partial updates. |
| `app/Http/Requests/ProjectReviewDecisionRequest.php` | `reason` validation for approve / request-changes / reject. |
| `tests/Feature/Projects/ProjectManagementWorkflowTest.php` | 32 tests covering the whole workflow. |

## 3. Files NOT Changed (deliberately)

- **Matching** — `ProjectMatchingService`, `ProjectMatchingPayloadBuilder`, `ProjectMatchingSnapshotService`,
  `ProjectMatchingRecommendationService`, `ProjectMatchingController`: **zero changes**.
- **Applications** — `ApplicationController`, `ApplicationService`, `Application`, `StoreApplicationRequest`,
  `ApplicationResource`: **zero changes**. The application contract and lifecycle are untouched.
- **FastAPI integration** — no client, payload or version constant was modified.
- **`ProjectController`** (learner catalog) and **`ProjectResource`**: untouched. Management responses
  reuse the same resource, so the project JSON contract does not fork.
- **`ProjectAccessService` / `ProjectAvailabilityService` / `ProjectEligibilityService` / `ProjectCapacityPolicy`**: untouched.
- **Next-phase models/migrations** (`ProjectTeam`, `ProjectTeamMember`, `ProjectMilestone`, `Submission`,
  `Evaluation`): **not deleted, not rebuilt, not extended**. Workspace / milestones / submission /
  evaluation / professional-record update were **not implemented** — out of Pilot scope.
- No `.env`, no `composer.json`/`composer.lock`, no package versions, no PHP/Laravel version.

## 4. Database Changes

**One migration. No new columns, no new tables, no new indexes, no new foreign keys.**

`projects.status` enum:

| | |
|---|---|
| BEFORE | `draft, pending_review, open, closed, in_progress, completed, archived` |
| AFTER | `draft, submitted, changes_requested, approved, rejected, open, selection, active, under_review, completed, cancelled, archived, closed` |

**Data conversion** — run *before* the `ALTER`, because MySQL coerces an enum value that is no longer
in the list to `''`, which would silently blank every affected row:

- `pending_review` → `submitted`
- `in_progress` → `active`

`closed` is **preserved in the enum and not written by any code path** (see §9).
`draft`, `open`, `completed`, `archived` are retained unchanged, so no row loses its meaning.

**Not destructive**: every pre-existing value is either kept or explicitly converted first.
`down()` is documented as lossy (the official lifecycle has values the old enum had no slot for) and
maps each back to its closest original equivalent rather than blanking rows.

⚠️ **Not verified against MySQL locally** — the local MariaDB (XAMPP) was not running, and SQLite
cannot validate MySQL DDL. The enum values were confirmed on SQLite
(`check ("status" in ('draft','submitted',…,'closed'))`). **Verify `php artisan migrate` on a MySQL
staging database before deploying.**

## 5. API Endpoints Added

All responses keep the existing convention: success `{success, message, data, request_id}` (data = the
existing `ProjectResource`), errors `{code, message, request_id, details?}`.

| METHOD | URL | AUTHORIZATION | REQUEST | RESPONSE | STATUS TRANSITION |
|---|---|---|---|---|---|
| POST | `/api/v1/projects` | `auth:sanctum` + `account.active`; service requires `admin` \| `company_admin` \| `university_admin` | `type` (req), `title` (req), `description`, `objectives`, `learning_outcomes[]`, `difficulty`, `work_mode`, `role`, `schedule`, `capacity`, `min_team_size`, `start_date`, `end_date`, `application_deadline`, `confidentiality`, `required_skills[]{skill_id,minimum_level,is_critical_entry}`, `roles[]{title,description,is_active}`, `eligibility_constraints[]{constraint_type,value}` | `201` + project | → `draft` (always) |
| PATCH | `/api/v1/projects/{project}` | `auth:sanctum` + `account.active`; service requires owner or `admin` | same fields, all optional | `200` + project | none (only in `draft`/`changes_requested`) |
| POST | `/api/v1/projects/{project}/submit` | owner or `admin` | — | `200` + project | `draft` \| `changes_requested` → `submitted` |
| POST | `/api/v1/projects/{project}/approve` | `admin` middleware **and** service check | `reason?` | `200` + project | `submitted` → `approved` |
| POST | `/api/v1/projects/{project}/request-changes` | `admin` middleware **and** service check | `reason` (**required**) | `200` + project | `submitted` → `changes_requested` |
| POST | `/api/v1/projects/{project}/reject` | `admin` middleware **and** service check | `reason?` | `200` + project | `submitted` → `rejected` |
| POST | `/api/v1/projects/{project}/open` | owner or `admin` | — | `200` + project | `approved` → `open` |

Stable error codes: `PROJECT_CREATE_FORBIDDEN` (403), `PROJECT_NOT_OWNED` (403), `PROJECT_REVIEW_FORBIDDEN` (403),
`PROJECT_ORGANIZATION_REQUIRED` (403), `PROJECT_NOT_FOUND` (404), `PROJECT_INVALID_TRANSITION` (422),
`PROJECT_NOT_EDITABLE` (422), `PROJECT_INCOMPLETE` (422, `details.missing[]`), `PROJECT_INVALID_DATES` (422),
`PROJECT_REASON_REQUIRED` (422), `PROJECT_STATUS_CONFLICT` (409), `VALIDATION_ERROR` (422).

**Reasons are stored in `audit_events`**, not in a new column — the `projects` table has no
decision-reason column and the Pilot did not ask for one, so no schema was invented (the same choice
`ApplicationService::withdraw()` already made).

## 6. Lifecycle

Implemented and enforced:

```
draft ──submit──▶ submitted ──approve──▶ approved ──open──▶ open ──▶ selection
                     │  │
        request-changes│  │reject
                     ▼  ▼
        changes_requested  rejected
              │
              └──submit──▶ submitted
```

Declared in `Project::TRANSITIONS` but **not exposed as an endpoint**: `open → selection`.
It is declared so the lifecycle is complete for the Pilot's shortlist/accept phase (section 14), but
the required endpoint list stops at `open`, and shortlisting already happens through the existing
application decisions. `selection` is a *project* status and stays separate from the *application*
statuses (`shortlisted`, `accepted`) — the two are never conflated.

`rejected` has **no outgoing transition**: the Pilot has no agreed resubmission-after-rejection path
(the fix-and-resubmit path is `changes_requested`), and inventing one would be a product decision.
Flagged in §9.

Approval yields `approved` — it never auto-activates and never auto-opens.

## 7. FastAPI Compatibility

- `algorithm_version` = **`project-matching-v1`** — unchanged.
- `configuration_version` = **`project-matching-config-v1`** — unchanged.
- **Payload structure unchanged.** `ProjectMatchingPayloadBuilder` reads a validated
  `ProjectMatchingSnapshot`, not the live project, so it is insulated from the status change. Its
  `project` block (`id, version, type, domain, difficulty, work_mode, role, schedule,
  organization_id, confidentiality`), `required_skills` (`skill_id, skill_name, minimum_level,
  is_critical_entry`), `validation` and `eligibility` are all untouched.
- `project_version` is **preserved and now meaningful**: `Project::VERSIONED_FIELDS` is exactly the set
  of fields that feed the snapshot, and `version` is bumped only when one of them (or the required
  skills) actually changes. Descriptive edits (title/description/objectives/learning outcomes) do not
  churn it, so an application's pinned `project_version` stays comparable.
- No new field was added to the payload and no field was renamed.

## 8. Tests

| Command | Result |
|---|---|
| `php artisan test --filter=ProjectManagementWorkflowTest` | **32 passed (106 assertions)** |
| `php artisan test` (full suite) | **910 passed (3230 assertions)** — 0 failed, 0 skipped (exit code 0) |
| `php vendor/bin/pint` (12 changed/created files) | **PASS** |

Baseline before this task was 878 passed; the delta is exactly the 32 new tests.

**New tests** (`ProjectManagementWorkflowTest`): authorized company rep can create a draft · learner
cannot create · creation ignores a supplied `status` and `organization_id` · creation validates type ·
creation rejects an end date before the start date · owner can update a draft · another user cannot
update someone else's project · a project from another organization cannot be updated · type cannot
change once open · type can still change in draft · version bumped only on a matching-field change ·
complete draft can be submitted · incomplete project cannot be submitted and keeps its status ·
another user cannot submit · admin can approve (and it does **not** activate/open) · owner cannot
approve their own project · learner cannot approve · draft cannot be approved · admin can request
changes and the reason is recorded in the audit row · request-changes without a reason is refused ·
owner cannot request changes · changes_requested → edit → resubmit · admin can reject · non-admin
cannot reject · approved can be opened · draft/submitted/rejected cannot be opened · outsider cannot
open · opened project is discoverable and accepts applications · approved-but-unopened rejects
applications · draft is not discoverable.

**Regression**: the existing `ProjectMatching*` suites (8 files), `ApplicationWorkflowTest`,
`ProjectCatalogTest`, `ProjectDetailsEnrichmentTest`, `ProjectAccessControlTest`,
`ProjectEligibilityServiceTest` all pass unchanged.

## 9. Remaining Blocker — the `closed` decision (REQUIRED)

**A decision is needed from the team; this implementation deliberately does not take it.**

`closed` was a value in the original `projects.status` enum. The official Pilot lifecycle does **not**
contain it. Analysis of every occurrence:

- `database/migrations/2026_01_01_002700_create_projects_table.php:30` — the enum declaration.
- **Nothing else.** No controller, service, model, policy, request, resource, scope, seeder, factory,
  test or query in the project reads or writes `projects.status = 'closed'`. (The other `closed`
  hits in the codebase belong to other tables — `conversations.status` — or are prose in comments.)

**What was done (the smallest possible change):** `closed` was **kept in the enum** and no code path
writes it. It is not "added" (it already existed) and not "removed".

**Why the decision is needed:** removing it means deciding that no project will ever need that state;
folding it into `cancelled`/`archived` means deciding which one; promoting it to a real status means
deciding its transitions. Each is a product decision, so the migration preserves the value and the
report raises it.

**Also requiring confirmation** (I made a defensible choice rather than stopping, since the code could
not be written without one — please confirm):

1. **`rejected` is terminal.** If the SRS allows resubmission after rejection, a transition must be added.
2. **Editable window = `draft` + `changes_requested`.** A project under review (`submitted`) or live
   (`open`+) cannot be edited. This is also what makes "type is immutable once execution starts" hold.
3. **Submission completeness** requires: valid `type`, `title`, `description`, `objectives`,
   `capacity >= 1`, ≥1 required skill, ≥1 role, `start_date`, `end_date` (≥ start), and
   `application_deadline` (≤ start). `work_mode`, `difficulty` and `domain` are validated but not required.
4. **`open → selection` has no endpoint** (declared transition only).
5. **`VERSIONED_FIELDS`** = `type, domain, difficulty, work_mode, role, schedule, confidentiality`
   (+ required skills). Derived from the FastAPI snapshot, not invented.
6. **`deliverables`** (listed in the SRS field list) has **no column** in the existing schema.
   No column was invented — scope is covered by `description`/`objectives`. Confirm if a real
   `deliverables` field is required.
7. **No notifications were added** for project lifecycle decisions (only audit rows). The Pilot did not
   require them; say the word if the owner should be emailed on approve/changes/reject.

## 10. Git Diff Summary

```
 M app/Http/Controllers/Api/Admin/OrganizationController.php   |  54 ++++++-   (carried over)
 M app/Models/Project.php                                      | 143 +++++++++++++
 M app/Services/MentorStudentService.php                       |   2 +-
 M routes/api.php                                              |  49 +++++++
 M tests/Feature/Admin/AdminOrganizationTest.php               |  40 ++++++   (carried over)
 M tests/Feature/Projects/ProjectAvailabilityServiceTest.php    |   8 +-
 6 files changed, 287 insertions(+), 9 deletions(-)

New (untracked): database/migrations/2026_09_30_000001_unify_projects_status_lifecycle.php
                 app/Exceptions/ProjectException.php
                 app/Services/Projects/ProjectLifecycleService.php
                 app/Http/Controllers/Api/ProjectManagementController.php
                 app/Http/Requests/StoreProjectRequest.php
                 app/Http/Requests/UpdateProjectRequest.php
                 app/Http/Requests/ProjectReviewDecisionRequest.php
                 tests/Feature/Projects/ProjectManagementWorkflowTest.php
```

No commit was made (not requested). No `git reset`/`clean`/`checkout --`/`restore` was run; the
pre-existing uncommitted work in the tree was preserved.
