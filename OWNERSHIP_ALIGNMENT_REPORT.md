# Ownership Alignment Report

**Working directory**: `D:\newnn\Back-End` (nothing outside it was touched)
**Branch**: `feature/authentication` @ `34c384b` · **Date**: 2026-09-30

This was a **refinement** of the existing Project Management Workflow. Nothing was rebuilt: the
controller, service, requests, lifecycle constants, migration and tests all already existed and were
edited in place. **No new files, no new endpoints, no migration.**

---

## Files Modified

| Path | Reason |
|---|---|
| `app/Services/Projects/ProjectLifecycleService.php` | Replaced `resolveOrganizationId()` with a full `resolveOwnership()` (company-sponsored + simulation rules); added review separation; a type change now re-runs ownership validation. |
| `app/Http/Requests/StoreProjectRequest.php` | Added the `owner_id` rule (needed for administrator create-on-behalf) and corrected the ownership docblock. No other rule changed. |
| `tests/Feature/Projects/ProjectManagementWorkflowTest.php` | Organization fixtures now build **verified companies** (the new gate requires it); 18 tests added for the new ownership rules (32 → 50). |

Untouched on purpose: `ProjectManagementController`, `UpdateProjectRequest`,
`ProjectReviewDecisionRequest`, `Project` model, the status migration, and every route.

---

## Ownership Rules

### `company_sponsored` = COMPANY OWNED

Validated on **every** write that can set ownership (create, and update when the type changes):

1. `organization_id` must be present — never null.
2. The organization must exist.
3. It must be of type **`company`**.
4. It must be **`verification_status = verified`** — the same gate the organization module already
   applies (a pending/rejected organization's accounts cannot even sign in).
5. `owner_id` must be an **active administrator of that same organization**
   (`organization_members.role_in_org = 'admin'` **and** `status = 'active'`). That pivot is the
   existing, unambiguous "company representative" signal — no new relation was invented for it.

Refused: a null organization · a non-company organization · an unverified organization · an owner
from another organization · an owner who is only a plain member · a non-existent owner.

### `simulation` = SKILLSPAN / AUTHORIZED SIMULATION OWNER

- No company ownership required; `organization_id` may be null (the column already allowed it).
- **Never auto-linked to the creator's organization** — this is the rule that stops an internal
  training project from silently becoming a company project.
- If an `organization_id` *is* supplied it must be one the actor administers (or, for a platform
  administrator, an existing one) — otherwise 403.
- The owner is the creator.

### Type changes

The type stays editable in the editable window (`draft` / `changes_requested`) and is re-validated
when it changes:

- `simulation → company_sponsored` requires a company and a representative. An administrator with no
  company is refused (`PROJECT_ORGANIZATION_REQUIRED`) instead of producing a "company project" that
  names no company.
- `company_sponsored → simulation` preserves the existing owner (an administrator editing someone
  else's project must not inherit it by flipping the type).
- Once the project is `open` / execution has started the type cannot change at all (unchanged rule).

---

## Admin Behavior

**Create on behalf of a company.** A platform administrator creating a `company_sponsored` project
must name **both** the sponsoring organization **and** the company representative who will own it.
Neither is ever defaulted:

- no organization → `PROJECT_ORGANIZATION_REQUIRED`
- no representative → `PROJECT_OWNER_REQUIRED`
- themselves as representative → `PROJECT_OWNER_MUST_BE_COMPANY_REPRESENTATIVE`
- a user outside the company, or a plain member → `PROJECT_OWNER_NOT_ORGANIZATION_REPRESENTATIVE`

So an administrator never silently becomes the owner of a company project.

**Review.** Moderation stays administrator-only (the `admin` middleware plus a service check), and
**review separation is now enforced**: a project's own owner cannot decide its outcome, even when they
also hold the administrator role (`PROJECT_REVIEW_SELF_FORBIDDEN`). Approval still yields `approved`
and never auto-opens or auto-activates.

**Company representative.** Their project always belongs to their own organization and is owned by
them; a supplied `organization_id` or `owner_id` is ignored, so nobody can publish into — or on behalf
of — another organization.

---

## API Changes

**No endpoint was added, renamed or removed.** All seven endpoints keep their exact paths and
methods. The only contract-visible change is one additional **optional** request field:

| Endpoint | Change |
|---|---|
| `POST /api/v1/projects` | accepts optional `owner_id` (only honoured for an administrator creating `company_sponsored`) |
| `PATCH /api/v1/projects/{project}` | accepts `owner_id` / `organization_id`, but honours them **only when the `type` changes**; an ordinary edit ignores them, so ownership cannot be re-pointed behind a cosmetic update |

New stable error codes: `PROJECT_OWNER_REQUIRED` (422), `PROJECT_OWNER_INVALID` (422),
`PROJECT_OWNER_MUST_BE_COMPANY_REPRESENTATIVE` (422),
`PROJECT_OWNER_NOT_ORGANIZATION_REPRESENTATIVE` (422), `PROJECT_ORGANIZATION_NOT_FOUND` (422),
`PROJECT_ORGANIZATION_NOT_A_COMPANY` (422), `PROJECT_ORGANIZATION_NOT_VERIFIED` (422),
`PROJECT_ORGANIZATION_NOT_ADMINISTERED` (403), `PROJECT_REVIEW_SELF_FORBIDDEN` (403).

Response envelope, `ProjectResource` and the success/error shapes are unchanged.

---

## Database Changes

**None. No migration was required.**

The question asked before touching the schema — *do `organization_id` and `owner_id` suffice?* — is
**yes**. `organization_id` was already nullable (so a simulation needs no company) and `owner_id` was
already a required FK to `users`. Both rules are enforced in the service, not by the schema, so no
column, table, index or constraint was added. No destructive migration was written.

The `projects.status` migration from the previous task is **unchanged**.

---

## Tests

| Command | Result |
|---|---|
| `php artisan test --filter=ProjectManagementWorkflowTest` | **50 passed (165 assertions)** — 0 failed, 0 skipped |
| `php artisan test` (full suite) | **928 passed (3289 assertions)** — 0 failed, 0 skipped |
| Project regression set (see below) | **368 passed (1242 assertions)** |
| `php vendor/bin/pint` on the 3 touched files | **PASS** |

Baseline before this task was 910 passed; the delta is exactly the 18 new tests.

**Added (18)** — company-sponsored must name an organization · cannot be sponsored by a non-company ·
cannot be sponsored by an unapproved company · administrator creating on behalf does **not** become
the owner · administrator must name the representative · administrator cannot name themselves ·
administrator cannot assign an unrelated user · administrator cannot assign a plain member ·
authorized actor can create a simulation · simulation is not auto-linked to the creator's organization ·
simulation can name an organization the actor administers · simulation cannot name a foreign
organization · simulation follows the same lifecycle (submit → approve → open) · simulation cannot
become company-sponsored with no company · administrator can convert by naming company + representative ·
representative can convert their own simulation · a representative cannot reassign ownership through an
ordinary update · an administrator cannot review a project they own.

The lifecycle tests requested in §22 (draft→submitted, submitted→approved/changes_requested/rejected,
changes_requested→submitted, approved→open, invalid transitions) and the security tests
(cross-organization update blocked, unauthorized create blocked, unauthorized approval blocked) were
already present from the previous task and still pass.

---

## Regression

- **Matching — untouched.** `ProjectMatchingService`, `ProjectMatchingPayloadBuilder`,
  `ProjectMatchingSnapshotService`, `ProjectMatchingRecommendationService` were **not modified**
  (0 lines changed). All `ProjectMatching*` suites pass (368 tests in the project regression set).
- **FastAPI contract — unchanged.** `algorithm_version` = `project-matching-v1`,
  `configuration_version` = `project-matching-config-v1`. The project payload
  (`id, version, type, domain, difficulty, work_mode, role, schedule, organization_id,
  confidentiality`), required skills (`skill_id, skill_name, minimum_level, is_critical_entry`) and
  the response contract are all as before. The payload builder reads a snapshot, not the live project,
  so the ownership change cannot reach it.
- **Applications — untouched.** `ApplicationController`, `ApplicationService`, `Application` and the
  application status set are unchanged; `ApplicationWorkflowTest` passes.
- **Lifecycle — unchanged.** `pending_review` / `in_progress` are still absent; `closed` is still not
  in `Project::STATUSES`, has no transition and is used by no API.
- **No `.env`, `composer.json`/`composer.lock`, PHP or Laravel version change.**

### ⚠️ MySQL migration — NOT verified locally

MariaDB is **not running** on this machine (`ERROR 2002 ... Can't connect to MySQL server on
'localhost'`), so the previous task's `projects.status` enum `ALTER` has still **not** been executed
against MySQL/MariaDB. It was verified on SQLite only. **MySQL migration not verified locally.** Run
`php artisan migrate` on a MySQL staging database before deploying.

---

## Remaining Decisions

1. **`closed`** — still unresolved and untouched, exactly as required: it remains a legacy enum value,
   is **not** in `Project::STATUSES`, has **no** transition, and is used by **no** API. Needs a product
   decision (drop / fold into `cancelled`|`archived` / promote to a real status).
2. **Can an administrator who is *also* an active representative of the company own its
   `company_sponsored` project?** I enforced the strict reading of §5/§28 — no, the administrator must
   name a different representative. If the intent is only "never *silently* become the owner", this can
   be relaxed to allow an explicit self-assignment.
3. **Must a `company_sponsored` sponsor be `type = 'company'`?** I enforced it, per §8's
   "company-sponsored must be linked to a company". This means a `training_partner`/`university`
   organization cannot sponsor one (it can still create `simulation` projects). Confirm if other
   organization types should be allowed to sponsor.
4. **Must the sponsor be `verification_status = 'verified'`?** I enforced it, mirroring the existing
   organization approval gate. Confirm this is intended for projects (it means a freshly registered,
   still-pending company cannot create its first project).

Carried over and still open from the previous report (unchanged, not re-decided here): `rejected` is
terminal; the editable window is `draft` + `changes_requested`; the submission completeness list;
`open → selection` has no endpoint; the `deliverables` field has no column; no lifecycle notification
emails.

---

## Git

```
 M app/Http/Requests/StoreProjectRequest.php          |  25 +-
 M app/Services/Projects/ProjectLifecycleService.php  | 344 +++++++++++++++++--
 M tests/Feature/Projects/ProjectManagementWorkflowTest.php | 379 ++++++++++++++++++++-
 3 files changed, 709 insertions(+), 39 deletions(-)
```

No commit, no push, no reset, no clean, no restore. Pre-existing changes in the tree were preserved.
