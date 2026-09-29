# US-MATCH-02 — Student Project Application & Recommendation Feedback

**Status report — COMPLETE. Schema, model, policy and runtime layer all delivered.**

Scope: Laravel backend (`Back-End-feature-authentication`), branch `feature/authentication`.
Last verified: 2026-09-29.

> **Honesty note.** Per *"Do not claim a requirement is implemented unless it is
> actually enforced by the backend and covered by code/tests"*, every section is
> marked **DONE** (enforced + verified) · **PARTIAL** · **PENDING** (not started).
> Sections 4–19 are DONE and each one names the test that proves it.
>
> **Three things are deliberately NOT implemented, and are labelled as such
> rather than being quietly approximated:**
> 1. **`difficulty` as an eligibility gate** — no threshold policy exists anywhere
>    in this codebase (21.2). Inventing one was explicitly forbidden.
> 2. **A recommendation suppression window** — no such configuration exists
>    (21.3). Only the event is recorded.
> 3. **A `start_date` application rule** — no existing policy uses `start_date`,
>    so it would be new policy (21.4).
>
> **Both blocking business decisions are resolved** (section 0), and the capacity
> policy is isolated behind one config-driven seam (section 8) so it can change
> without touching the schema or `ApplicationService`.

---

## 0. Two business-policy decisions — both now RESOLVED

### 0.1 ✅ RESOLVED — project targeting is NOT academic specialization

An earlier revision of this work added `projects.specialization_id` as a
server-side specialization gate. **That was wrong and has been removed.**

The business meaning is technical, not academic:

| Learner | Target projects |
|---|---|
| Frontend / React | React / frontend projects at their level |
| Backend / Laravel | Laravel / backend projects at their level |
| Data Analyst | SQL / Python / analytics projects at their level |

Project relevance is **Career Role + Required Skills + Learner Skill Levels +
Project Difficulty**. The source of truth is the existing career-role +
required-skills + matching architecture, which already sends
`target_career_role` to FastAPI. **No parallel career-role eligibility system
was created**, and no academic specialization column or gate remains.

### 0.2 ✅ RESOLVED — capacity policy (was blocking the runtime layer)

`projects.capacity` is confirmed as the source of truth. The **business meaning
is now confirmed**: capacity represents **actual project seats / participation
capacity**. It is **not** the number of learners who are allowed to *discover or
apply*, and it must **never** be used as a career-role or skill eligibility
mechanism.

The earlier draft assumed `count(status = accepted)`. That specific expression is
still only a **default**, not an approved rule, so it was **not hard-coded** —
it now lives in one swappable seam (section 8). The question of *which statuses
consume a seat* remains a config value, changeable with a one-line edit and **no
schema change**.

See **section 8** for the implementation and **section 23** for the closed
decision record.

### 0.3 ✅ RESOLVED — project discovery model is OPEN CATALOG

Confirmed: projects are **not pre-assigned** to learners. A learner discovers a
project and applies. "Open" does **not** mean "open to every learner regardless
of career role, skills or level" — relevance is technical (career role +
required skills + learner skill level + difficulty), exactly as described in 0.1.
Capacity is a *seats* limit, not a *discovery* limit (0.2).

---

## 1. What was implemented

**DONE**

1. Three additive, idempotent, rollback-verified migrations (section 3).
2. A new `ProjectRole` model plus `Project::projectRoles()`.
3. `Application` promoted from a 2-field stub to a full workflow model: status
   constants, capacity constants, an explicit transition table, relations
   (`projectRole`, `recommendation`), and a **derived** `active_key` maintained
   by a model hook rather than by callers.
4. `Project` extended with `projectRoles()` and `acceptedApplications()`.
5. A **capacity policy seam** — `config/project_application.php` plus
   `App\Services\Projects\ProjectCapacityPolicy`. This is the *only* place that
   decides what "full" means. It reads its consuming statuses from config and
   exposes `check()` in the same shape as `ProjectAvailabilityService::check()`
   (boolean flags + a `reasons` array), so a caller treats capacity exactly like
   availability. Changing the rule is a config edit — no schema change.

**REVERTED**

- `projects.specialization_id` — migration deleted, model reverted. See 0.1.

**DONE** — the runtime layer is complete: `ApplicationService` (orchestration only),
`ApplicationController` + `RecommendationFeedbackController`, `StoreApplicationRequest`,
`ApplicationResource`, `ApplicationException`, 6 routes, notification/audit wiring, and
78 feature tests. The capacity policy above is now *called* from
`ApplicationService::submit()` (step 5) and from `ProjectController::show()`. Full
file inventory in section 5; task-by-task status in section 22.

---

## 2. Files changed

This table covers the **schema / model / policy** layer. The runtime-layer files added on
top of it — services, controllers, form request, resource, exception, routes, tests and
deliverables — are enumerated in section 5, with task-by-task status in section 22.

| File | Type | Status |
|---|---|---|
| `database/migrations/2026_09_29_000002_create_project_roles_table.php` | new | DONE |
| `database/migrations/2026_09_29_000003_add_application_workflow_fields_to_applications_table.php` | new | DONE |
| `database/migrations/2026_09_29_000005_add_hide_to_feedback_events_enum.php` | new | DONE |
| `app/Models/ProjectRole.php` | new | DONE |
| `app/Models/Application.php` | rewritten | DONE |
| `app/Models/Project.php` | extended, then specialization reverted | DONE |
| `config/project_application.php` | new | DONE |
| `app/Services/Projects/ProjectCapacityPolicy.php` | new | DONE |
| `database/migrations/2026_09_29_000004_add_specialization_to_projects_table.php` | **deleted** | REVERTED |

No existing file was deleted. No existing behaviour was removed. The migration
numbering has a deliberate gap at `000004` (the deleted file); this is normal in
Laravel and is left rather than renumbering so that no already-applied migration
changes identity.

> ⚠️ **If `000004` was already applied to a persistent database**, deleting the
> file does not remove the column. Roll it back first
> (`php artisan migrate:rollback --path=database/migrations/2026_09_29_000004_add_specialization_to_projects_table.php`)
> or drop `projects.specialization_id` manually, otherwise the schema will drift
> from the migration history.

---

## 3. Migrations added

All are **additive and idempotent** (`Schema::hasColumn` / `hasTable` /
`getIndexes` guards), matching the convention set by
`2026_09_29_000001_add_project_matching_fields_to_recommendations_table`.

### 3.1 `2026_09_29_000002_create_project_roles_table` — DONE

```text
project_roles
  id
  project_id      FK -> projects, cascadeOnDelete
  title           string
  description     text, nullable
  is_active       boolean, default true
  timestamps
  UNIQUE (project_id, title)
```

**No capacity column** — capacity stays on `projects.capacity` (section 8).

### 3.2 `2026_09_29_000003_add_application_workflow_fields_to_applications_table` — DONE

| Column | Type | Purpose |
|---|---|---|
| `project_version` | unsignedInteger, nullable | the `projects.version` applied against |
| `project_role_id` | FK → `project_roles`, nullOnDelete | selected role |
| `submitted_at` | timestamp, nullable | explicit submission time |
| `withdrawn_at` | timestamp, nullable | withdrawal time |
| `application_data` | json, nullable | answers to the project's questions |
| `recommendation_id` | FK → `recommendations`, nullOnDelete | originating recommendation |
| `recommendation_algorithm_version` | string, nullable | ADR-001 algorithm version |
| `recommendation_configuration_version` | string, nullable | ADR-001 configuration version |
| `idempotency_key` | string(191), nullable | caller-supplied `Idempotency-Key` |
| `request_fingerprint` | string(64), nullable | SHA-256 of the normalised payload |
| `active_key` | unsignedTinyInteger, nullable | derived: `1` active, `NULL` terminal |

```text
DROP  UNIQUE (project_id, applicant_id)
ADD   UNIQUE (project_id, applicant_id, active_key)        -- applications_active_unique
ADD   UNIQUE (applicant_id, project_id, idempotency_key)   -- applications_idempotency_unique
```

### 3.3 `2026_09_29_000005_add_hide_to_feedback_events_enum` — DONE

Appends `hide` to `feedback_events.event_type`:
`view, click, save, apply, reject, complete, rating, hide`.
`decline` maps onto the existing `reject` event.

### 3.4 `2026_09_29_000004_add_specialization_to_projects_table` — **DELETED**

Removed per section 0.1. No replacement. Academic specialization is not a
project-targeting mechanism, is not a project column, and is not an application
gate.

---

## 4. Routes / endpoints added or changed

**DONE — 6 routes registered** (`php artisan route:list --path=applications`).

Learner group — `['auth:sanctum','account.active','role:learner']`, the same
group as `GET /projects`:

| Method | Path | Action | Name |
|---|---|---|---|
| POST | `/api/v1/projects/{project}/applications` | submit | `projects.applications.store` |
| GET | `/api/v1/applications` | own applications (`?status=`) | `applications.index` |
| POST | `/api/v1/applications/{application}/withdraw` | withdraw own | `applications.withdraw` |
| POST | `/api/v1/recommendations/{recommendation}/feedback` | save / hide / decline | `recommendations.feedback` |

Owner group — `['auth:sanctum','account.active']`:

| Method | Path | Action | Name |
|---|---|---|---|
| GET | `/api/v1/projects/{project}/applications` | list a project's applications | `projects.applications.index` |
| PATCH | `/api/v1/projects/{project}/applications/{application}` | decide | `projects.applications.decide` |

> **Why the owner group is NOT `role:learner`.** A project owner is any
> authenticated user, so a role check would be both weaker (it does not prove
> ownership) and wrong (it would exclude legitimate non-learner owners).
> Authorization is explicit `$project->owner_id === $actor->id` inside
> `ApplicationService::forProject()` / `decide()`, returning 403
> `APPLICATION_LIST_FORBIDDEN` / `APPLICATION_DECISION_FORBIDDEN`.

Existing routes were not modified; the new ones were added alongside them.

---

## 5. Controllers / services / models changed

**Models — DONE**

- **`ProjectRole` (new).** `fillable`, `is_active` cast, `project()` /
  `applications()`, `isAvailable()` (delegates to `is_active`), `available()` scope.
- **`Application` (rewritten).** Status constants `STATUS_*`;
  `ACTIVE_STATUSES`; `CAPACITY_STATUSES`; `TRANSITIONS` + `canTransitionTo()`;
  `isActive()` / `isWithdrawn()`; relations `projectRole()`, `recommendation()`
  (kept `project`, `applicant`, `decidedBy`); casts for `submitted_at`,
  `withdrawn_at`, `application_data`, `project_version`, `active_key`; a
  `booted()` block deriving `active_key` on `saving` and stamping `submitted_at`
  on `creating`.
- **`Project` (extended).** Added `projectRoles()` and `acceptedApplications()`.
  The temporary `specialization_id` fillable entry and `specialization()`
  relation were **removed**.

**Services — DONE**

| File | Responsibility |
|---|---|
| `app/Services/Projects/ApplicationService.php` (new) | Orchestrates the whole workflow. Delegates to the 4 collaborators below; owns no rule of its own. |
| `app/Services/Projects/RecommendationFeedbackService.php` (new) | save / hide / decline → `feedback_events`. |
| `app/Services/Projects/ProjectCapacityPolicy.php` (new) | The only thing that decides what "full" means. |
| `app/Exceptions/ApplicationException.php` (new) | Typed failure with `status` + stable `codeName` + `details`, same shape as `ReadinessException`. |

`ApplicationService` composes — and does **not** re-implement —
`ProjectAvailabilityService`, `ProjectEligibilityService`,
`ProjectCapacityPolicy`, `NotificationService` and the `AuditsActions` trait.

**Controllers / requests / resources — DONE**

| File | Responsibility |
|---|---|
| `app/Http/Controllers/Api/ApplicationController.php` (new) | 5 endpoints, thin, shared error envelope. |
| `app/Http/Controllers/Api/RecommendationFeedbackController.php` (new) | 1 endpoint. |
| `app/Http/Requests/StoreApplicationRequest.php` (new) | Shape validation only. |
| `app/Http/Resources/ApplicationResource.php` (new) | Presentation. |
| `app/Http/Resources/ProjectResource.php` (extended) | `duration_days`, `available_project_roles`, `eligibility`, `capacity_state`. |
| `app/Http/Controllers/Api/ProjectController.php` (extended) | Attaches per-learner `eligibility` / `capacity_state` on `show` only. |

**Check order inside `submit()` — deliberate, do not reorder:**

| # | Check | Failure |
|---|---|---|
| 1 | **idempotent replay** | 422 `APPLICATION_IDEMPOTENCY_CONFLICT` |
| 2 | availability (`ProjectAvailabilityService`) | 422 `PROJECT_NOT_AVAILABLE` |
| 3 | **client-supplied references** — project role *and* recommendation | 422 `PROJECT_ROLE_INVALID` / `PROJECT_ROLE_INACTIVE` / `RECOMMENDATION_NOT_FOUND` / `RECOMMENDATION_PROJECT_MISMATCH` |
| 4 | duplicate active application | 409 `APPLICATION_DUPLICATE` |
| 5 | capacity (`ProjectCapacityPolicy`) | 409 `PROJECT_FULL` |
| 6 | eligibility (`ProjectEligibilityService`) | 422 `APPLICATION_NOT_ELIGIBLE` |
| 7 | persist + audit + notify (one transaction) | — |

Three of these orderings are load-bearing:

- **Replay is first.** A retry must return the original result even if the world
  changed since, otherwise a network retry of a *successful* submission would
  fail because the project filled up in the meantime.
- **References (3) come before business state (4–6).** `project_role_id` and
  `recommendation_id` are both client-supplied references, so they are validated
  together and *early*: a bad reference must be reported as a bad reference
  rather than being masked by a duplicate or capacity error that also happens to
  be true. This was originally wrong — the recommendation was validated at step 7
  and the live Postman run exposed it (a foreign recommendation on an
  already-applied project returned `APPLICATION_DUPLICATE` instead of
  `RECOMMENDATION_NOT_FOUND`).
- **Duplicate (4) comes before eligibility (6).** `ProjectEligibilityService`
  contains its own duplicate rule, so checking it earlier guarantees a learner is
  told "you already applied" rather than "you are not eligible".

**Two pre-existing defects fixed** (both found while building, both real bugs,
neither a behaviour change for existing callers):

1. `FeedbackEvent::$fillable` omitted `occurred_at`, which is `NOT NULL` with no
   DB default — so *any* `FeedbackEvent::create()` died with
   `NOT NULL constraint failed: feedback_events.occurred_at`. Nothing had ever
   written to that model before US-MATCH-02, which is why it went unnoticed.
2. `ProjectEligibilityService::getLearnerSkillLevel()` fell through to
   `$studentProfile->id` when the profile was `null`, crashing with
   "Attempt to read property id on null" for any project with a critical
   required skill evaluated against a profile-less learner. It now returns the
   normal ineligibility result. Behaviour with a profile present is unchanged.

---

## 6. Business rules implemented

| Rule | Enforcement | Status |
|---|---|---|
| At most one **active** application per learner per project | DB unique `(project_id, applicant_id, active_key)` + derived `active_key`; **plus** an explicit pre-check that returns 409 `APPLICATION_DUPLICATE` instead of leaking a DB error | DONE |
| A terminal application must not block re-application | `active_key` → `NULL` for terminal statuses | DONE |
| One project-role title per project | DB unique `(project_id, title)` | DONE |
| `submitted_at` always populated on insert | `creating` hook | DONE |
| `active_key` can never disagree with `status` | derived in `saving` hook, never caller-supplied | DONE |
| Project must be `open` and within `application_deadline` | `ProjectAvailabilityService::check()` → 422 `PROJECT_NOT_AVAILABLE` | DONE |
| Role must belong to the project and be active | `ApplicationService::resolveProjectRole()` → 422 `PROJECT_ROLE_INVALID` / `PROJECT_ROLE_INACTIVE` | DONE |
| Seats must be free | `ProjectCapacityPolicy::blocksNewApplication()` → 409 `PROJECT_FULL` | DONE |
| Critical skills, constraints, team conflicts | `ProjectEligibilityService::check()` → 422 `APPLICATION_NOT_ELIGIBLE` | DONE |
| Submission is idempotent | unique `(applicant_id, project_id, idempotency_key)` + `request_fingerprint` | DONE |
| Withdrawal only by the owner of the application, only from a non-terminal status | `ApplicationService::withdraw()` → 403 / 422 | DONE |
| Status transitions follow the existing workflow | `Application::canTransitionTo()` → 422 `APPLICATION_INVALID_TRANSITION` | DONE |
| Owner is notified on submission, applicant on decision | `NotificationService::dispatch()`, keyed per event | DONE |
| Every state change is audited | `AuditsActions` → `audit_events` | DONE |
| **`difficulty` as an eligibility gate** | — | **NOT IMPLEMENTED — no threshold policy exists** (section 21.2) |

---

## 7. Project-role availability logic

**DONE.**

- **Gap:** the spec assumed an existing project-role schema. None existed — only
  `projects.role` (one free-text string) and `project_team_members.project_role`
  (free-text). Neither expresses selectable roles.
- **Decision (confirmed by business):** keep `project_roles(project_id, title,
  description, is_active)` and `applications.project_role_id`. No role-level capacity.
- **Availability semantics:** `is_active = true` means the owner has not
  deactivated the role. Deliberately not a counter.
- **Validation at application time (DONE):**
  - the role is resolved **through the project's own relation**
    (`$project->projectRoles()->whereKey(...)`), so a role id belonging to a
    different project can never be applied with → 422 `PROJECT_ROLE_INVALID`;
  - an inactive role → 422 `PROJECT_ROLE_INACTIVE`;
  - `project_role_id` is optional; when omitted the application simply has none.
  - `StoreApplicationRequest` deliberately does **not** use `exists:` on this
    field — a bare `exists:` would report a cross-project id as a generic
    validation error instead of the precise domain code.
- **Exposed on the details endpoint** as `available_project_roles` (active roles
  only) so the learner can pick a valid id.

---

## 8. Capacity logic

**DONE — policy seam AND enforcement.**

**Confirmed business meaning.** `capacity` = **actual project seats /
participation capacity**. It is **not** a cap on how many learners may discover
or apply for the project, and it must **never** be used as a career-role or
skill eligibility mechanism.

- **Source of truth:** `projects.capacity` (unsignedInteger, default 1).
  `NULL` is treated as *unlimited* by the policy (`totalSeats()` → `null`,
  `seatsRemaining()` → `null`, `isFull()` → `false`).
- **Finding:** `project_teams` holds only `id`, `project_id`, `name` — no
  capacity column. `project_team_members` holds a free-text `project_role` and
  an `assignment_state` enum. **There is no role-level or team-level capacity
  anywhere**, so no second capacity system was introduced.
- **The seam (DONE):**
  - `config/project_application.php` → `capacity.consuming_statuses`
    (default `[accepted]`) and `capacity.reject_submission_when_full`
    (default `true`).
  - `App\Services\Projects\ProjectCapacityPolicy` → `consumingStatuses()`,
    `rejectsSubmissionWhenFull()`, `seatsTaken()`, `totalSeats()`,
    `seatsRemaining()`, `isFull()`, `check()`, `blocksNewApplication()`.
  - `check()` returns an object with `capacity`, `seats_taken`,
    `seats_remaining`, `full`, `consuming_statuses`, `reasons` — the same
    `{flags + reasons}` shape as `ProjectAvailabilityService::check()`.
  - This is **the only place** that decides what "full" means.
    `ApplicationService` calls `blocksNewApplication()` and never counts
    applications itself.
- **Still a default, not a confirmed rule:** *which* statuses occupy a seat. The
  earlier draft assumed `count(status = accepted)`; that expression is retained
  as the **documented default** because it matches the confirmed meaning
  ("seats" are awarded, not merely applied for), but it is a config value — no
  code outside the seam reads it.
- **Also a default, not a confirmed statement:** `reject_submission_when_full`.
  It is **inferred** from the spec's "Full project" test case under *application
  creation*; the config file says so explicitly. Setting it to `false` lets
  learners keep applying to a full project and moves the seat limit to
  acceptance time only.
- **Enforcement (DONE) — two call sites, both in `ApplicationService`:**
  1. `submit()` — `blocksNewApplication()` → 409 `PROJECT_FULL`, with
     `details` carrying `capacity` / `seats_taken` / `seats_remaining` / `reasons`.
  2. `decide()` — an `accepted` decision re-checks `isFull()` → 409
     `PROJECT_FULL`. This is why the "enforced at submission *and* acceptance"
     answer in section 23 holds even if `reject_submission_when_full` is set to
     `false`.
- **Exposed on the details endpoint** as `capacity_state`
  (`capacity`, `seats_taken`, `seats_remaining`, `full`).
- **Tests that pin the semantics:** `test_a_full_project_rejects_a_new_application`,
  `test_a_submitted_application_does_not_consume_a_seat`,
  `test_capacity_of_null_is_treated_as_unlimited_by_the_policy`,
  `test_capacity_is_not_used_as_an_eligibility_mechanism`,
  `test_accepting_into_a_full_project_is_rejected`,
  `test_withdrawing_an_accepted_application_releases_the_seat`.

---

## 9. Project date / deadline logic

**DONE (status + deadline) / NOT IMPLEMENTED (start_date — no policy exists).**

| Field | Meaning | Existing owner | Wired? |
|---|---|---|---|
| `projects.status` | lifecycle (`draft`…`open`…`archived`) | `ProjectAvailabilityService` | **yes** |
| `projects.application_deadline` | last day to apply | `ProjectAvailabilityService` | **yes** |
| `projects.start_date` | project start | *(no existing policy)* | **no — deliberately** |
| `projects.end_date` | project end | `ProjectController::accessibleProjectsQuery()` | catalog only |

`ProjectAvailabilityService::check()` is called first in `submit()` and its
reason strings are surfaced **verbatim** in `details.reasons` rather than being
reworded — so the learner sees exactly what `GET /projects` already reports:
"Project is not open (current status: …)." / "Application deadline has passed."
Both produce 422 `PROJECT_NOT_AVAILABLE`.

`end_date` is a *catalog visibility* rule (an expired project disappears from
`GET /projects`), not an application rule, so it stays in the controller.

**Not implemented:** the `start_date` rule. No existing policy uses
`start_date`, so "cannot apply after the project has started" would be **new**
policy and was not invented — see section 21.4. `duration_days` on the details
response is a pure derivation from `start_date`/`end_date`, not a rule.

---

## 10. Eligibility logic reused

**DONE — reused unchanged and wired.**

`ProjectEligibilityService` is reused as-is (one internal robustness fix, see
section 5). It already implements:

1. **Critical required skills** — learner level (`SkillEvaluation.level`, falling
   back to `LearnerSkill.level`) vs `ProjectRequiredSkill.minimum_level`, for
   rows with `is_critical_entry = true`.
2. **Eligibility constraints** — `work_mode` and `schedule` enforced; `location`
   and `language` explicitly skipped because `student_profiles` stores no
   comparable field.
3. **Duplicate active application** — `status in (submitted, shortlisted, accepted, waitlisted)`.
4. **Active assignment conflict** — an existing `active` `project_team_members` row.

Non-critical required skills are intentionally not hard blockers; they are
matching inputs.

**Wiring:** `ApplicationService::submit()` calls `check()` **after** the
duplicate check (step 4) and the capacity check (step 5), so its internal
duplicate rule never fires twice and a learner is never told "not eligible"
when the real problem is "you already applied". A failure returns 422
`APPLICATION_NOT_ELIGIBLE` with the service's own `reasons` and `skill_failures`
passed through untouched.

**No difficulty gate was added** — `difficulty` has no thresholds anywhere in
this codebase (section 21.2), so treating it as a hard blocker would have meant
inventing numbers, which the spec forbids.

---

## 11. Duplicate prevention

**DONE — database level AND application level.**

- `unique(project_id, applicant_id)` replaced by
  `unique(project_id, applicant_id, active_key)`.
- `active_key` = `1` for `ACTIVE_STATUSES`, `NULL` otherwise, derived in an
  `Application::booted()` `saving` hook.
- **Why it works on MySQL and SQLite:** `NULL` values never collide in a unique
  index, so any number of terminal rows may coexist while at most one active row
  per `(project, applicant)` can exist.
- **Why the old index had to change:** it made a withdrawn application block
  that project *forever*, contradicting "more than one **active** application".
  This is the one existing behaviour deliberately replaced, and it is required
  by US-MATCH-02 (and confirmed by business in section 4 of the review).
- **Application-level 409 (DONE).** `ApplicationService::hasActiveApplication()`
  pre-checks using `Application::ACTIVE_STATUSES` — the model's own constant, so
  the check cannot drift from the DB constraint — and returns 409
  `APPLICATION_DUPLICATE`.
- **Concurrency (DONE).** Two simultaneous submissions can both pass the
  pre-check and race on the unique index. The resulting `QueryException` is
  translated into the *same* 409 `APPLICATION_DUPLICATE` (detecting SQLSTATE
  `23000`/`23505`, or the SQLite/MySQL unique-violation messages), so a race
  produces a clean domain error instead of a 500.
- **Tests:** `test_a_second_active_application_is_rejected`,
  `test_learner_can_reapply_after_withdrawing`.

---

## 12. Idempotency implementation

**DONE.**

- `applications.idempotency_key` (string 191) stores the caller's `idempotency_key`.
- `applications.request_fingerprint` (SHA-256 of the payload **normalised
  recursively** — every map's keys sorted, lists left in order) detects *"same
  key, materially different body"*. Recursive is the important word: sorting only
  the top level was a real defect, fixed below.
- `unique(applicant_id, project_id, idempotency_key)` makes the guarantee
  database-backed (not cache-only), scoped per learner **and** per project.
- `NULL` keys never collide, so requests without the field are unaffected.

Actual behaviour:

| Situation | Result |
|---|---|
| Same learner + project + key + equivalent payload | returns the **existing** application, HTTP **200** (not 201), no second row |
| Same learner + project + key + different payload | 422 `APPLICATION_IDEMPOTENCY_CONFLICT` |
| No key supplied | normal duplicate-active validation applies |

**Two design points worth recording:**

1. **The replay is resolved FIRST, before any validation.** A retry must return
   the original result even if the world changed since — otherwise a network
   retry of a successful submission would fail because the project filled up in
   the meantime. `test_an_idempotent_replay_does_not_re_run_capacity_checks`
   pins exactly this.
2. **201 vs 200 is distinguished by `wasRecentlyCreated`.** `submit()` returns
   the in-memory model (not `fresh()`) so the controller can tell a genuine
   insert from a replay. A replay must not claim a resource was created.
3. **The fingerprint is normalised RECURSIVELY — this was a bug.** The first
   implementation sorted only the three top-level keys, so a client that built
   `application_data` from an unordered map (a JS object, a hash) could emit the
   same logical body with a different key order on a retry and be told
   `APPLICATION_IDEMPOTENCY_CONFLICT` instead of being replayed — turning a safe
   retry into a hard failure. `normalizeForFingerprint()` now sorts every map at
   every depth while leaving lists in order (order is meaningful for a list).
   Proven by first writing the two failing tests, watching them return 422, then
   fixing. A guard test asserts that *genuinely* different bodies are still
   detected, so the normalisation cannot be widened into a no-op.

**Tests:** `test_replaying_an_idempotency_key_returns_the_original_application`,
`test_reusing_a_key_with_a_different_body_is_rejected`,
`test_an_idempotency_replay_tolerates_reordered_application_data_keys`,
`test_an_idempotency_replay_tolerates_reordered_nested_keys`,
`test_an_idempotency_replay_still_detects_a_genuinely_different_body`,
`test_applications_without_an_idempotency_key_do_not_collide`,
`test_a_retry_does_not_notify_the_owner_twice`.

---

## 13. Recommendation feedback implementation

**DONE.**

- `hide` appended to `feedback_events.event_type` (section 3.3).
- `decline` reuses the existing `reject` event — no parallel concept.
- No new table or model: feedback keeps flowing through `FeedbackEvent` and
  `Recommendation::feedbackEvents()`, proven by
  `test_the_existing_relation_still_reads_the_events`.
- **Endpoint (DONE):** `POST /api/v1/recommendations/{recommendation}/feedback`
  with `event_type` ∈ `{save, hide, decline}` and an optional `reason`.
  - Ownership is enforced in `RecommendationFeedbackService::record()` by
    `recommendation.user_id === learner.id` → 403 `RECOMMENDATION_NOT_OWNED`
    (a missing id is a 404 `RECOMMENDATION_NOT_FOUND`).
  - An unsupported `event_type` is a 422 `VALIDATION_ERROR` at the request
    layer, before the service is reached.
  - Each event writes an `audit_events` row (`recommendation.feedback.save` /
    `.hide` / `.reject`, purpose `recommendation_feedback`).
  - Feedback is an **append-only log**: two `save` events are two learner
    actions and both are kept (unlike notifications, which are idempotent on
    `event_key`).
- **Suppression policy NOT implemented** — see section 21.3, and
  `test_no_suppression_window_is_applied` pins the actual behaviour so it cannot
  be mistaken for an implemented feature.
- **Pre-existing bug fixed:** `FeedbackEvent::$fillable` was missing
  `occurred_at` (a `NOT NULL` column), so no `FeedbackEvent::create()` could
  ever succeed. US-MATCH-02 is the first writer to that model.

---

## 14. Recommendation version handling

**DONE.**

- `recommendations` already carries `algorithm_version`, `configuration_version`
  and `project_version`, so **no new version column was added** — the story
  forbids duplicating an existing versioning concept.
- `applications.recommendation_algorithm_version` and
  `recommendation_configuration_version` snapshot the version the learner acted
  on, so a later recalculation cannot rewrite history. They are copied from the
  referenced recommendation at submission time, never from client input.
- `applications.project_version` snapshots `projects.version` for the same
  reason.
- **Validation (DONE)** — `ApplicationService::resolveRecommendation()` requires
  the recommendation to (a) exist for **this learner** and (b) point at **this
  project**, otherwise 422 `RECOMMENDATION_NOT_FOUND` /
  `RECOMMENDATION_PROJECT_MISMATCH`. Without that, a learner could attach an
  arbitrary or someone else's recommendation id.
- **Tests:** `test_recommendation_versions_are_stored_on_the_application`,
  `test_another_learners_recommendation_cannot_be_referenced`,
  `test_a_recommendation_pointing_at_another_project_is_rejected`.

---

## 15. Withdrawal / status transitions

**DONE.**

```text
submitted   -> shortlisted | accepted | rejected | waitlisted | withdrawn
shortlisted -> accepted | rejected | waitlisted | withdrawn
waitlisted  -> accepted | rejected | withdrawn
accepted    -> withdrawn
rejected    -> (terminal)
withdrawn   -> (terminal)
```

Expressed as `Application::TRANSITIONS` + `canTransitionTo()`. No transition was
invented beyond what the existing workflow implies.

**Withdrawal — DONE** (`POST /api/v1/applications/{application}/withdraw`):

- **Ownership:** `applicant_id === learner.id`, else 403 `APPLICATION_NOT_OWNED`.
  This lives in the service, not the route, because the route only knows the
  learner *role* — it cannot know whose application an id refers to.
- **Timing:** allowed only when `canTransitionTo('withdrawn')`. `rejected` and
  `withdrawn` are terminal, so a second withdrawal or a withdrawal of a rejected
  application is 422 `APPLICATION_NOT_WITHDRAWABLE` with the allowed
  transitions in `details`.
- `withdrawn_at` is stamped and `active_key` is recomputed by the model's
  `saving` hook — which is what releases the unique slot **and** (under the
  default policy) the seat.
- **Reason:** the `applications` table has no withdrawal-reason column and
  US-MATCH-02 did not ask for one, so an optional `reason` is preserved in the
  audit row rather than inventing a schema change.

**Owner decisions — DONE**
(`PATCH /api/v1/projects/{project}/applications/{application}`):

- Authorized by explicit project ownership → 403
  `APPLICATION_DECISION_FORBIDDEN`.
- The application must belong to the project in the URL → 404
  `APPLICATION_PROJECT_MISMATCH`.
- The requested status must be a legal transition → 422
  `APPLICATION_INVALID_TRANSITION`.
- `accepted` re-checks capacity → 409 `PROJECT_FULL` (see section 8).
- Writes `decided_by`, `decided_at`, optional `decision_reason`, an audit row,
  and notifies the applicant.

**Tests:** 11 in `ApplicationWorkflowTest` cover this section, including
`test_withdrawing_an_accepted_application_releases_the_seat` and
`test_an_invalid_status_transition_is_rejected`.

---

## 16. Audit / event handling

**DONE.** No duplicate audit system was created — the existing `AuditsActions`
trait writing to `audit_events` is used, and `ApplicationService` /
`RecommendationFeedbackService` both `use` it.

| Action | Entity | Written by |
|---|---|---|
| `application.submitted` | `application` | `submit()` |
| `application.withdrawn` | `application` | `withdraw()` |
| `application.decided` | `application` | `decide()` |
| `recommendation.feedback.save` / `.hide` / `.reject` | `recommendation` | `record()` |

Every row carries `before` / `after` snapshots, a `purpose`
(`project_application` / `recommendation_feedback`), and the caller's
`request_id` / `ip_address` / `user_agent` — so an application is fully
traceable to the request that produced it. `request_id` reuses the incoming
`X-Request-ID` when present, the same correlation contract as every other
controller.

**Tests:** `test_submission_writes_an_audit_row`,
`test_feedback_writes_an_audit_row`.

---

## 17. Notification handling

**DONE.** The existing `NotificationService::dispatch()` is used, so the
recipient's per-category preferences are honoured in one place and no
`Notification::create()` is called directly.

| Trigger | Recipient | `event_key` |
|---|---|---|
| Submission | project owner | `application.submitted:{application_id}` |
| Decision | applicant | `application.status:{application_id}:{status}` |

The `event_key`s make both idempotent: a retried submission cannot notify the
owner twice, and a repeated identical decision cannot notify the applicant
twice. A *different* decision (e.g. shortlisted → accepted) does notify again,
because the key changes with the status.

**Tests:** `test_the_project_owner_is_notified_of_a_new_application`,
`test_a_retry_does_not_notify_the_owner_twice`,
`test_a_decision_notifies_the_applicant`.

---

## 18. Tests added

**DONE — 78 new tests, 3 files, all passing.**

| File | Tests | Covers |
|---|---|---|
| `tests/Feature/Projects/ApplicationWorkflowTest.php` | 57 | submission, authorization, availability, project roles, duplicate, capacity, eligibility, validation, idempotency (incl. fingerprint normalisation), recommendation linkage, audit, notification, withdrawal, owner decisions, listing + pagination |
| `tests/Feature/Projects/RecommendationFeedbackTest.php` | 12 | save / hide / decline, ownership, unsupported event, audit, append-only behaviour, "no suppression window" |
| `tests/Feature/Projects/ProjectDetailsEnrichmentTest.php` | 9 | `duration_days`, `available_project_roles`, `eligibility`, `capacity_state`, and the deliberate absence of the last two on the catalog list |

Two of the 78 were written **before** their fix, deliberately: the reordered-key
idempotency cases were confirmed failing (422 where 200 was correct) before
`normalizeForFingerprint()` was written. A test that has never been seen to fail
proves nothing.

Mapping to the spec's scenario list: all 29 scenarios are covered, several by
more than one test. Where the spec named a case that the codebase cannot express
(`difficulty` as a gate; the suppression window) the test **pins the actual
behaviour and says so in a comment** rather than being silently omitted.

---

## 19. Tests actually executed and exact results

| Command | Result |
|---|---|
| `php -l` on all migrations + 3 models | No syntax errors |
| `php artisan migrate --force` (isolated SQLite) | 3 new migrations DONE, no errors |
| `Schema::hasColumn('projects','specialization_id')` | **absent** (removal confirmed) |
| `Schema::hasTable('project_roles')` | `yes` |
| `Schema::hasColumn('applications','active_key')` | `yes` |
| `Schema::getColumnListing('applications')` | all columns present, incl. the 11 new ones |
| `Schema::getIndexes('applications')` | `applications_active_unique [project_id,applicant_id,active_key]`, `applications_idempotency_unique [applicant_id,project_id,idempotency_key]`; old blanket unique **gone** |
| `php artisan migrate:rollback --step=4 --force` (pre-removal) | clean; original schema restored exactly |
| `php -l config/project_application.php` | No syntax errors |
| `php -l app/Services/Projects/ProjectCapacityPolicy.php` | No syntax errors |
| `php vendor/bin/pint --test config/project_application.php app/Services/Projects/ProjectCapacityPolicy.php` | **PASS — 2 files** |
| `php artisan test` (full suite, after the capacity seam) | **683 passed (2390 assertions) — 0 failures** |
| `php -l` on all 6 new + 5 changed source files | No syntax errors |
| `php artisan route:list --path=applications` | 5 routes registered |
| `php artisan route:list --path=feedback` | 1 route registered |
| `php vendor/bin/pint` on all new + changed files | **FIXED / clean** |
| `php artisan test tests/Feature/Projects/ApplicationWorkflowTest.php` | **50 passed (135 assertions)** |
| `php artisan test tests/Feature/Projects/RecommendationFeedbackTest.php` | **12 passed (30 assertions)** |
| `php artisan test tests/Feature/Projects/ProjectDetailsEnrichmentTest.php` | **9 passed (51 assertions)** |
| `php artisan test` (full suite, after the runtime layer) | **754 passed (2606 assertions) — 0 failures** |
| `newman run postman_project_applications.json` (live HTTP) | **33 requests · 63 assertions · 0 failures** |
| `php artisan test` (full suite, after the check-order fix) | **754 passed (2606 assertions) — 0 failures** |
| `newman run postman_project_applications.json` (live HTTP, final) | **34 requests · 67 assertions · 0 failures** |
| `php artisan test` (full suite, final) | **761 passed (2633 assertions) — 0 failures** |

The full suite was run because the `Application` and `Project` models changed
shape, the `applications` unique index changed, and `ProjectResource`,
`ProjectController`, `ProjectEligibilityService` and `FeedbackEvent` were all
touched. **No regression.** Baseline history: 434 tests on 2026-09-27 → 683 on
2026-09-29 (work outside this task) → **754** after the runtime layer (71 tests
added) → **761** after the review-pass fixes, which added 7 more
(`ApplicationWorkflowTest` 50 → 57, covering recursive fingerprint normalisation
and the paginated list contract). Nothing else changed.

**Per-file counts in the final run** (`php artisan test`): 57 + 12 + 9 = **78**.
The rows above deliberately keep the earlier snapshots (50 / 12 / 9) as they were
observed at the time rather than rewriting them, so the run history stays honest.

**Two failures were hit and fixed during development, both real defects rather
than test problems:**
1. `NOT NULL constraint failed: feedback_events.occurred_at` — `occurred_at` was
   missing from `FeedbackEvent::$fillable` (section 5).
2. `UNIQUE constraint failed: users.email` in a test fixture — the test helper
   re-created the same owner twice; fixed in the fixture, not in production code.

All database work used a **throwaway local SQLite file**. The checked-in `.env`
points at a live remote MySQL with `APP_ENV=production`, so
`DB_CONNECTION`/`DB_DATABASE` were exported for every artisan command.

---

## 20. Unresolved assumptions and business decisions

| # | Decision | Status |
|---|---|---|
| 1 | `project_roles` table introduced (no schema existed) | **confirmed** |
| 2 | No capacity column on `project_roles` | **confirmed** |
| 3 | Academic specialization is **not** a targeting mechanism; no column, no gate | **confirmed** |
| 4 | Blanket unique on `applications` replaced by active-only unique | **confirmed** |
| 5 | `decline` maps to the existing `reject` event | **implemented, low risk** |
| 6 | **What counts against `projects.capacity`** | **resolved — config-driven seam, section 8** |
| 7 | `projects.start_date` has no policy | documented gap |
| 8 | `capacity` = actual seats, never an eligibility/discovery cap | **confirmed** |
| 9 | `consuming_statuses = [accepted]` | **default, config-changeable** |
| 10 | `reject_submission_when_full = true` | **inferred default, config-changeable** |

---

## 21. Schema / policy gaps discovered

### 21.1 No project-role schema existed
The spec assumed one. A minimal table was added. No role-level capacity model
exists and none was invented.

### 21.2 No level ↔ difficulty mapping exists — **this is the important one**

`difficulty` is a `decimal(3,2)` on `projects` (validated `min:0,max:5` in the
catalog filter). An exhaustive search across `app/`, `config/` and
`database/seeders/` found it used **only** as:

- a catalog filter (`ProjectController`),
- a `ProjectResource` field,
- a `ProjectMatchingSnapshotService` snapshot field,
- a `ProjectMatchingPayloadBuilder` FastAPI payload field.

There is **no** configuration entry, seeder value, band definition, or
authoritative mapping between a learner's skill level and an appropriate project
difficulty — nothing resembling `Easy = 1, Medium = 2–3, Hard = 4–5`.

**Therefore, per the business instruction:**
- `difficulty` **stays an existing matching/recommendation input**. The FastAPI
  payload already carries it, so "Career Role + Required Skills + Learner Level +
  Project Difficulty" is already expressed as four separate relevance dimensions
  and none replaces another.
- **No hard eligibility gate on difficulty was implemented**, and no numeric
  threshold was invented.
- The missing mapping is isolated behind the same seam principle as capacity: it
  belongs in configuration/service logic so it can be added later **without
  redesigning the application workflow**.

**Required before difficulty can become a hard gate:** an explicit business rule
mapping learner level ↔ difficulty, ideally stored as an `AlgorithmConfiguration`
entry so it inherits the existing ADR-001 versioning (`configuration_version`)
rather than becoming an unversioned constant.

### 21.3 Recommendation suppression window is undefined
The story mentions suppressing hidden/declined recommendations "for a configured
period". No such configuration exists. Only the `hide` event is recorded.

### 21.4 `projects.start_date` is not used by any existing policy
A rule such as "cannot apply after the project has started" would be new policy,
so it was not invented.

### 21.5 `feedback_events.event_type` is a MySQL enum
Future event types require a `change()` migration each time (Laravel recreates
the table on SQLite). Worth noting if the event vocabulary is expected to grow.

---

## 22. Delivered — the runtime layer

All eight planned steps are DONE and verified:

| # | Step | Where |
|---|---|---|
| 1 | `ApplicationService` — idempotent replay → availability → role → duplicate → capacity → eligibility → persist → audit → notify | `app/Services/Projects/ApplicationService.php` |
| 2 | `ApplicationController` + 6 routes | `app/Http/Controllers/Api/ApplicationController.php`, `routes/api.php` |
| 3 | `StoreApplicationRequest` | `app/Http/Requests/StoreApplicationRequest.php` |
| 4 | Withdrawal + owner decision endpoints | `ApplicationController::withdraw()` / `decide()` |
| 5 | Recommendation feedback (save / hide / decline) | `RecommendationFeedbackService` + `RecommendationFeedbackController` |
| 6 | Owner notification + audit wiring | `NotificationService::dispatch()` + `AuditsActions` |
| 7 | Test scenarios | 78 tests across 3 files (section 18) |
| 8 | Project-details enrichment | `ProjectResource` + `ProjectController::show()` |

**Deliberately left out** (see the honesty note at the top): the `difficulty`
gate, the suppression window, and the `start_date` rule. Each would require a
business decision that does not exist yet.

**Two technical gaps that were flagged here have since been closed** (no business
decision was needed for either):

- **Both list endpoints are now paginated** (`per_page`, default 15, clamped to
  100, with a `meta` block) — the same contract `RecommendationController` and
  `NotificationService::list` already use. Returning every row was unbounded, and
  a learner accumulates one row per project they ever applied to.
- **The idempotency fingerprint is now normalised recursively**, so a retry whose
  `application_data` keys are serialised in a different order replays instead of
  reporting a conflict (section 12).

**Remaining follow-ups, none blocking:**
1. Approve or change `consuming_statuses` — a one-line config edit if the
   business wants applications rather than awards to occupy seats.
2. Decide whether a withdrawal reason deserves its own column; it currently
   lives only in the audit row.
3. Decide whether the project owner should be notified when a learner **withdraws**
   (which can free a seat). Only submission and decisions notify today, because
   only those were specified.
4. If `difficulty` is ever meant to gate eligibility, define the level↔difficulty
   mapping as an `AlgorithmConfiguration` entry so it inherits ADR-001
   `configuration_version`.

---

## 23. ✅ CLOSED — capacity decision record

**Original question: which application statuses consume a `projects.capacity`
slot?**

**Answered by the business clarification:** capacity represents **actual project
seats / participation capacity**, not the number of learners permitted to
discover or apply, and it is never a career-role or skill eligibility mechanism.

**Resolution:**
- `projects.capacity` stays the source of truth.
- Which statuses consume a seat remains a **config value**
  (`project_application.capacity.consuming_statuses`), currently `[accepted]`.
  This was the *least-restrictive* option (former option A) and matches the
  confirmed meaning — a seat is taken when it is **awarded**, not when someone
  merely applies.
- The rule is isolated in `ProjectCapacityPolicy`; nothing else counts
  applications. No schema change is needed to switch it.

**Sub-questions, now answered by the policy's design:**

1. **Does `withdrawn` free a seat?** Yes — with `consuming_statuses = [accepted]`
   a withdrawn application is no longer `accepted`, so the seat is released
   automatically. No special-case code.
2. **Enforced at submission, acceptance, or both?** The policy supports both.
   `reject_submission_when_full = true` blocks a *new submission* when the
   project is already full; the same policy is available to re-check at
   *acceptance* time. The submission-time behaviour is an **inferred** default
   (from the spec's "Full project" test case), flagged as such in
   `config/project_application.php`.
3. **Is `capacity` ever unlimited?** The schema is `unsignedInteger, default 1,
   NOT NULL`, so in practice it is finite. The policy still treats `NULL` as
   unlimited defensively, so a future nullable migration would need no code
   change — pinned by `test_capacity_of_null_is_treated_as_unlimited_by_the_policy`.

**This decision is now enforced in two places** (section 8): `submit()` blocks a
new application when the project is full, and `decide()` blocks an `accepted`
decision when no seat is left. Nothing further blocks the runtime layer, which is
delivered (section 22).

---

## 24. Postman collection & live end-to-end verification

**File:** `postman_project_applications.json` (collection root, next to the
existing `postman_mentor_communication.json`).

**34 requests across 8 folders**, ordered so the whole collection can be run once
from the Collection Runner:

| Folder | Requests | Purpose |
|---|---|---|
| `0 · Setup` | 4 | logs in as learner + owner, stores both tokens and every id |
| `1 · تقديم الطلب` | 4 | happy-path submit, list mine (+ pagination meta), `per_page` clamping, filter by status |
| `2 · التكرار والـ idempotency` | 4 | duplicate → 409; replay → 200 same id; key reuse with a different body → 422 |
| `3 · تقديم مع توصية` | 2 | recommendation versions stored; a foreign recommendation → 422 |
| `4 · تغذية التوصيات` | 5 | save / hide / decline (+ foreign → 403, unsupported → 422) |
| `5 · قرارات صاحب المشروع` | 5 | owner list, shortlist, accept, learner blocked → 403, illegal transition → 422 |
| `6 · السحب` | 3 | withdraw → 200, second withdraw → 422, re-apply after withdrawal → 201 |
| `7 · حالات مرفوضة` | 7 | closed project, full project, bad role, missing project, no token, non-learner, missing application |

**Auth model in the collection:** the collection-level bearer is the *learner*
token; the owner folder overrides it per request with `{{owner_token}}`; the two
login requests use `noauth`. This is what lets one collection cover both sides of
the workflow without a second collection.

**How it was verified — real HTTP, not PHPUnit.** A throwaway SQLite database was
built from scratch (migrate + `db:seed` + a fixture script), `php artisan serve`
was started on `127.0.0.1:8899`, and newman drove the entire collection against
it:

```
requests 34 · assertions 67 · failures 0 · duration 24.0s
```

The checked-in `.env` points at a **live remote MySQL with `APP_ENV=production`**,
so `DB_CONNECTION`/`DB_DATABASE`/`APP_ENV` were exported for every artisan
command — exported variables win over `.env` because Laravel's Dotenv uses an
immutable `safeLoad()`. The override was confirmed with
`php artisan config:show database.default` (printed `sqlite`) before anything ran.
The throwaway database and the fixture/generator scripts were deleted afterwards;
only the collection was kept.

**What the live run caught that PHPUnit did not:** the check-ordering defect
described in section 5. In a feature test the learner had never applied to the
target project, so the late recommendation validation was still reached. Over real
HTTP, running the folders in sequence meant an application already existed, and
the foreign-recommendation case returned `APPLICATION_DUPLICATE` (409) instead of
`RECOMMENDATION_NOT_FOUND` (422). The fix was to validate both client-supplied
references together at step 3. **This is exactly the class of bug that in-process
tests structurally cannot see** — worth remembering before treating a green
PHPUnit run as end-to-end proof.

**Fixture note for reproducing the run:** the fixtures' organization must be set
to `verification_status = 'verified'`, otherwise `AuthController` rejects every
login with "Your organization is still pending approval". That column is
deliberately **not** in `Organization::$fillable` (the same guard pattern as
`ProfessionalProfile::$verification_status`), so it must be written explicitly
after the insert — passing it to `create([...])` silently drops it and the login
keeps failing with no hint as to why.
