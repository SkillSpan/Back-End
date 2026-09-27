# Dynamic Baseline Assessment — Implementation & API Contract

Branch: `feature/backend-dynamic-baseline-assessment`
Repository: **SkillSpan Laravel Backend only** (frontend + Data Science untouched)

This document is the deliverable index for the dynamic baseline assessment
feature: what changed, the final API contracts, Postman examples, migrations,
test commands/results, deployment + rollback, and a manual verification
checklist. Frontend and Data Science changes are listed **separately** at the
end — neither repository was modified.

---

## 1. What the feature does

`POST /baseline-assessments` now requires a `career_role_id`. Given an approved
career role, the backend:

1. resolves the role's required skills, importance weights, critical flags and
   prerequisites;
2. selects active questions mapped to those skills, with a **variable** number
   of questions per skill (bounded by config), and **fails with a clear
   configuration error** if any required skill has no active question;
3. persists an **immutable snapshot** of the role + selected questions + skill
   mappings at creation time;
4. returns the selected questions, options, coverage and metadata — never the
   correct answers;
5. on submit, validates ownership / role / status / question membership / answer
   format / allowed options / duplicates / completeness against the snapshot;
6. forwards role + question metadata to the Data Science intelligence service
   and validates that every returned skill evaluation belongs to the approved
   snapshot;
7. persists `SkillEvidence` + `SkillEvaluation` transactionally with retry
   idempotency, marking the assessment `completed` only after successful
   validation and persistence.

Readiness completeness validation (`ASSESSMENT_INCOMPLETE`) is **unchanged** and
still enforced — see §6.

---

## 2. Final API contracts

Base path: `/api/v1`. Auth: Sanctum bearer token, role `learner`,
`account.active`.

### 2.1 `POST /baseline-assessments` — start

Request:

```json
{ "career_role_id": 3 }
```

`career_role_id` is **required**, integer, must exist, and the role must have
`status = approved`. `assessment_version` is server-controlled and any client
value is ignored.

Success `201`:

```json
{
  "success": true,
  "message": "Baseline assessment started.",
  "data": {
    "id": 12,
    "assessment_type": "baseline",
    "assessment_version": "v1.0",
    "career_role_id": 3,
    "career_role": { "id": 3, "title": "Data Analyst", "slug": "data-analyst", "version": 1 },
    "status": "in_progress",
    "question_count": 4,
    "questions": [
      {
        "item_id": "sql-001",
        "item_type": "single_choice",
        "question_text": "Which SQL clause is used to filter rows returned by a query?",
        "options": ["A", "B", "C", "D"],
        "skill_id": 7,
        "skill_slug": "sql",
        "importance_weight": 0.9,
        "is_critical": true
      }
    ],
    "skill_coverage": {
      "total_questions": 4,
      "skill_count": 2,
      "covered_skill_count": 2,
      "critical_skill_count": 1,
      "skills": [
        {
          "skill_id": 7,
          "skill_slug": "sql",
          "skill_name": "SQL",
          "question_count": 2,
          "importance_weight": 0.9,
          "required_level": 3.0,
          "is_critical": true,
          "prerequisite_slugs": [],
          "covered": true
        }
      ]
    },
    "snapshot_metadata": {
      "career_role_id": 3,
      "career_role_slug": "data-analyst",
      "career_role_title": "Data Analyst",
      "career_role_version": 1,
      "assessment_version": "v1.0",
      "question_count": 4,
      "skill_count": 2,
      "critical_skill_count": 1,
      "selection_strategy": "randomised",
      "selected_at": "2026-09-27T09:31:25+00:00",
      "request_id": "..."
    },
    "progress": [],
    "responses": null,
    "result": null,
    "normalized_skills": null,
    "completed_at": null,
    "created_at": "...",
    "updated_at": "..."
  }
}
```

Errors: `422` validation (`career_role_id`), `422 CAREER_ROLE_NOT_APPROVED`,
`422 CAREER_ROLE_NO_SKILLS`, `422 INSUFFICIENT_QUESTION_COVERAGE`,
`422 INSUFFICIENT_QUESTION_CAPACITY`, `409 ASSESSMENT_ALREADY_EXISTS`,
`404 STUDENT_PROFILE_NOT_FOUND`.

**Duplicate rule (scoped by role):** uniqueness is
`(student_profile_id, assessment_type, assessment_version, career_role_id)`,
so a learner may take one baseline assessment **per career role**. Re-starting
the same role + version returns `409 ASSESSMENT_ALREADY_EXISTS`.

### 2.2 `GET /baseline-assessments/{assessment}` — retrieve

`200` with the same `data` shape as start. `questions[]` never contains
`correct_answer` or `scoring_rule`, and carries the frozen `question_text`,
`item_type` and `options`. Content comes from the **immutable snapshot**, so a
later edit to the live item bank cannot change what this assessment shows.
`question_text` is `null` when no prompt was ever authored for the item — the
backend never fabricates question text; the client falls back to its own copy
keyed by `item_id`. `responses` is `null` while `status = in_progress` (partial
answers are never echoed), and populated once completed. Ownership: another
learner's assessment returns `404 ASSESSMENT_NOT_FOUND`.

### 2.3 `PATCH /baseline-assessments/{assessment}` — save progress

Request: `{ "progress": { ... } }` and/or `{ "responses": [ ... ] }`.
`409 ASSESSMENT_ALREADY_COMPLETED` when the attempt is completed.

### 2.4 `POST /baseline-assessments/{assessment}/submit` — submit

Request:

```json
{
  "responses": [
    { "question_id": "sql-001", "answer": "B" },
    { "question_id": "python-002", "answer": "4" }
  ]
}
```

Success `200`: `data.status = "completed"`, `data.completed_at` set,
`data.normalized_skills` populated.

Validation / error codes (all against the **snapshot**, not the live bank):

| Code | HTTP | Meaning |
|---|---|---|
| `responses` validation error | 422 | `responses` missing/empty; entry missing `question_id`/`answer` |
| `UNAUTHORIZED_QUESTION` | 422 | question is not part of this assessment's snapshot |
| `DUPLICATE_RESPONSE` | 422 | same question answered twice |
| `INVALID_ANSWER_OPTION` | 422 | `single_choice` answer not in the item's options |
| `INCOMPLETE_RESPONSES` | 422 | one or more snapshot questions unanswered |
| `ASSESSMENT_SNAPSHOT_MISSING` | 409 | assessment has no snapshot (cannot submit) |
| `ASSESSMENT_ALREADY_COMPLETED` | 409 | attempt already completed |
| `BASELINE_INTEGRATION_NOT_CONFIGURED` | 503 | Data Science disabled / token missing |
| `INTELLIGENCE_SERVICE_ERROR` | 503 | Data Science 5xx |
| `INTELLIGENCE_INVALID_RESPONSE` | 502 | malformed / empty `skills`, missing `algorithm_version`, bad level or confidence |
| `INTELLIGENCE_SKILL_MISMATCH` | 502 | returned `skill_id` does not match its `slug` |
| `INTELLIGENCE_SKILL_OUT_OF_SCOPE` | 502 | evaluated skill is not in the approved snapshot |

`scale` items intentionally skip the allowed-option check (their `options` are
scale anchors; the intelligence service accepts the raw self-rated value).

Answer/option validation reads the **frozen snapshot** (`item_type` and
`options` captured at start), falling back to the live item only for legacy
snapshots created before content freezing.

**Incomplete evaluations:** a partial `skills` array is persisted **exactly as
returned** — the backend never fabricates an evaluation to fill a gap. An empty
`skills` array is a hard `502`. Any skill left without an evaluation surfaces
later through readiness's existing `ASSESSMENT_INCOMPLETE`.

---

## 3. Data Science contract (Laravel → FastAPI) — REQUIRED CHANGES

The client now sends extra fields on `POST {DATA_SCIENCE_URL}/api/v1/baseline`.
They are **additive** — an unchanged FastAPI service will ignore them and the
old contract still works — but the fields below should be adopted so the
service can scope evaluation to the frozen assessment.

### 3.1 Identifier namespace (resolves the `question_id` / `item_id` mismatch)

There is one identifier and one only, used consistently in all three places:

| Where | Field | Value |
|---|---|---|
| Laravel → client (`questions[]`) | `item_id` | `"sql-001"` |
| Client → Laravel (`responses[]`) | `question_id` | `"sql-001"` |
| Laravel → FastAPI | `question_ids[]` and `responses[].question_id` | `"sql-001"` |

It is the item bank's string `item_id` (e.g. `"sql-001"`), **never** the
numeric primary key. The numeric key stays internal (`baseline_assessment_
items.id`); FastAPI can resolve the numeric id itself via
`GET /api/v1/internal/baseline-items?version=v1.0`, which returns `item_id`
alongside `skill_id`.

Request (added fields marked `←`):

```json
{
  "student_profile_id": 1,
  "user_id": 2,
  "assessment_version": "v1.0",
  "career_role_id": 3,            // ← new (nullable)
  "career_role_version": 1,       // ← new (nullable)
  "question_ids": ["sql-001"],    // ← new (nullable); item_id strings
  "responses": [ { "question_id": "sql-001", "answer": "B" } ]
}
```

**Required Data Science work (do NOT change from this repository):**

1. **Scope evaluation to the submitted question ids.** Only evaluate skills
   reachable from `question_ids`; the backend now hard-rejects (502
   `INTELLIGENCE_SKILL_OUT_OF_SCOPE`) any returned skill whose slug is not in
   the role's approved snapshot. Returning an out-of-scope skill is a hard error.
2. **Honour `career_role_id` / `career_role_version`** so the same response set
   is scored against the frozen role definition, not a live/mutated one.
3. **`skill_id` must agree with `slug`** when supplied. A mismatch is rejected
   as `INTELLIGENCE_SKILL_MISMATCH`. If the service does not want to resolve
   ids, it may omit `skill_id` entirely — the backend resolves the skill from
   `slug`.
4. **Unchanged response shape** — still
   `{ algorithm_version, student_profile_id?, overall_score?, skills: [ { slug, level (0..5), confidence (0..1) } ] }`.
   `algorithm_version` is mandatory. An empty `skills` array is rejected.
5. Item-to-skill mapping and correct answers remain backend-owned — the
   `GET /api/v1/internal/baseline-items?version=v1.0` endpoint is unchanged
   except that its items now also include `question_text`.

## 4. Frontend contract — REQUIRED CHANGES (frontend repo, not modified here)

1. **Start** now **must** send `career_role_id`. Source it from the learner's
   selected/primary career role (or a role picker).
2. **Question source changed**: render `data.questions[]` from the assessment
   response instead of the fixed v1.0 item list. Each question now includes
   `question_text` (may be `null` until content is authored — fall back to the
   client's own copy keyed by `item_id`), `item_type` and `options`.
3. **Submit** must send `question_id` (the `item_id` string) — the previous
   shape plus a `question_id`/`answer` pair. `{ "question": 1 }` is no longer valid.
4. **Coverage/config errors** to surface clearly: `INSUFFICIENT_QUESTION_COVERAGE`
   (show `details.uncovered_skills`), `INSUFFICIENT_QUESTION_CAPACITY`,
   `CAREER_ROLE_NO_SKILLS`, `CAREER_ROLE_NOT_APPROVED`.
5. **Multiple roles**: a learner may run one assessment per career role; offer
   a role picker and expect a distinct assessment per role.
6. **No correct answers are available client-side** — do not expect
   `correct_answer`/`scoring_rule`.
7. `responses` is `null` on `GET` while in progress; keep local draft state for
   resume (the `progress` blob is still persisted via `PATCH`).

---

## 5. Files changed

**Modified**

| File | Change |
|---|---|
| `app/Http/Controllers/Api/BaselineAssessmentController.php` | `start()` takes `career_role_id`; `show/progress/submit` eager-load snapshots; `transform()` returns role, frozen questions (no answers), coverage, metadata; hides `responses` until completed |
| `app/Http/Controllers/Api/Internal/BaselineItemsController.php` | internal items payload now includes `question_text` |
| `app/Http/Requests/StartBaselineAssessmentRequest.php` | requires `career_role_id` (exists + approved) |
| `app/Http/Requests/SubmitBaselineAssessmentRequest.php` | per-response `question_id` + `answer` validation |
| `app/Models/BaselineAssessment.php` | `career_role_id`, `question_count`, `skill_coverage`, `snapshot_metadata`; `careerRole()` + `questionSnapshots()` relations |
| `app/Models/BaselineAssessmentItem.php` | `question_text` fillable |
| `app/Models/BaselineQuestionSnapshot.php` | frozen content: `item_id`, `item_type`, `question_text`, `options` |
| `app/Services/Baseline/BaselineAssessmentService.php` | dynamic `start()` with frozen snapshot persistence; `submit()` snapshot validation + skill_id/slug consistency + out-of-scope check + idempotent persistence |
| `app/Services/Baseline/BaselineDataScienceClient.php` | additive `career_role_id`, `career_role_version`, `question_ids`; documented identifier namespace |
| `app/Exceptions/BaselineAssessmentException.php` | `careerRoleHasNoSkills()`, `insufficientQuestionCoverage()`, `insufficientQuestionCapacity()` factories |
| `config/services.php` | `services.baseline_assessment.*` config block (incl. `max_total_questions`) |
| `database/seeders/BaselineAssessmentItemSeeder.php` | authors real `question_text` for the v1.0 items |
| `tests/Feature/Assessment/BaselineAssessmentTest.php` | rewritten + regressions (coverage, immutability, content, multi-role, DS payload) |
| `tests/Feature/Assessment/BaselineQuestionSelectionServiceTest.php` | selection unit coverage incl. total-cap semantics |
| `tests/Feature/Intelligence/RecalculationHooksTest.php` | baseline-hook test updated to the dynamic flow |
| `tests/Feature/Internal/BaselineItemsSecretTest.php` | internal payload now asserts `question_text` |

**Added**

| File | Purpose |
|---|---|
| `app/Models/BaselineQuestionSnapshot.php` | Immutable snapshot model |
| `app/Services/Baseline/BaselineQuestionSelectionService.php` | Role-based selection, coverage-preserving cap, snapshot validation + frozen-content accessors |
| `database/migrations/2026_09_28_000001_add_role_and_snapshot_to_baseline_assessments.php` | `career_role_id`, `question_count`, `skill_coverage`, `snapshot_metadata` |
| `database/migrations/2026_09_28_000002_create_baseline_question_snapshots_table.php` | Snapshot table |
| `database/migrations/2026_09_28_000003_add_question_text_to_baseline_assessment_items.php` | `question_text` on the item bank |
| `database/migrations/2026_09_28_000004_freeze_content_on_baseline_question_snapshots.php` | frozen `item_id`, `item_type`, `question_text`, `options` on snapshots |
| `database/migrations/2026_09_28_000005_scope_baseline_uniqueness_by_career_role.php` | uniqueness re-scoped to include `career_role_id` |

No frontend or Data Science repository files were touched.

## 6. Migrations

```
php artisan migrate
```

- `2026_09_28_000001_add_role_and_snapshot_to_baseline_assessments`
  adds `career_role_id` (nullable FK → `career_roles`, cascade), `question_count`,
  `skill_coverage` (json), `snapshot_metadata` (json). Column additions are
  guarded by `Schema::hasColumn`, so re-running is safe.
- `2026_09_28_000002_create_baseline_question_snapshots_table`
  creates `baseline_question_snapshots` with a unique
  `(baseline_assessment_id, baseline_assessment_item_id)` index.
- `2026_09_28_000003_add_question_text_to_baseline_assessment_items`
  adds nullable `question_text` to the item bank (nullable on purpose — existing
  items have no authored text and we never invent prompts).
- `2026_09_28_000004_freeze_content_on_baseline_question_snapshots`
  adds nullable `question_text`, `item_type`, `options`, `item_id` to the
  snapshot table so content is immutable.
- `2026_09_28_000005_scope_baseline_uniqueness_by_career_role`
  swaps `baseline_attempt_unique` for `baseline_attempt_role_unique`
  `(student_profile_id, assessment_type, assessment_version, career_role_id)`.

All `down()` methods are implemented and verified:
`php artisan migrate:rollback --step=5` then `php artisan migrate` succeeds.
Note: rolling `2026_09_28_000005` back will fail if a learner already holds two
assessments for the same version under different roles — delete the extras
first, or skip reverting that migration.

## 7. Test commands and actual results

```
php vendor/bin/phpunit tests/Feature/Assessment/BaselineAssessmentTest.php
php vendor/bin/phpunit tests/Feature/Assessment/BaselineQuestionSelectionServiceTest.php
php vendor/bin/phpunit tests/Feature/Internal/BaselineItemsSecretTest.php
php vendor/bin/phpunit
php vendor/bin/pint --test
```

Results (PHP 8.2.12, SQLite in-memory, this branch):

| Command | Result |
|---|---|
| `BaselineAssessmentTest` | **OK (42 tests, 184 assertions)** |
| `BaselineQuestionSelectionServiceTest` | **OK (10 tests, 28 assertions)** |
| `BaselineItemsSecretTest` | **OK (7 tests, 12 assertions)** |
| Full suite `php vendor/bin/phpunit` | **OK (275 tests, 1065 assertions)** |
| `php vendor/bin/pint --test` | **PASS (283 files)** |
| `php artisan migrate:fresh` + `rollback --step=5` + re-apply | all succeeded |

### Pre-existing failures (before this task) — all now resolved

At the start of this task the working tree was **already red**: 10 of 238 tests
failed because the previous agent's partial changes had landed without their
tests being updated:

- 9 × `BaselineAssessmentTest` — `POST /baseline-assessments` now requires
  `career_role_id`, and submit requires `question_id`; the old tests used the
  pre-change payloads.
- 1 × `RecalculationHooksTest::test_baseline_submit_dispatches_event` — same
  cause (old `{ "question": 1 }` response payload).

No test failures remain. There are no new failures.

### Follow-up review pass — defects found and fixed

A later review of the implementation surfaced eight defects. All are fixed and
covered by regression tests:

| # | Defect | Fix |
|---|---|---|
| 1 | The total-question cap trimmed the selection with `take(n)`, silently dropping a required skill's only question while still reporting full coverage. | `capPreservingCoverage()` reserves one question per skill first, then spends spare budget on the critical/heaviest skills; when the cap is below the required-skill count, start returns `422 INSUFFICIENT_QUESTION_CAPACITY` instead of under-covering. |
| 2 | The snapshot froze only the question-to-skill mapping; `question_text`, `item_type` and `options` were read live, so editing the bank changed what a learner saw and which answers validated. | Added frozen `item_id`/`item_type`/`question_text`/`options` columns to `baseline_question_snapshots`; retrieval **and** submission validation read the snapshot (live fallback only for legacy rows). |
| 3 | There was no question-content column at all — clients got `item_id` + `options` with no prompt. | Added nullable `question_text` to the item bank and exposed it on the assessment API and the internal items endpoint. Null until authored; no placeholder text is invented. |
| 4 | Uniqueness was `(student, type, version)`, so a learner could never assess a second career role. | Re-scoped to `(student, type, version, career_role_id)`; the service's existence check matches. |
| 5 | The outbound `question_ids` field carried the internal `item_id` string while the misleading name implied primary keys. | Documented the single identifier namespace (item bank `item_id`, e.g. `"sql-001"`) across client → Laravel → FastAPI; the payload now derives from frozen snapshot ids. |
| 6 | DS validation accepted a `skill_id` that disagreed with its `slug`. | Added `INTELLIGENCE_SKILL_MISMATCH`; explicit empty-`skills` rejection and partial-result persistence with no fabricated evaluations. |
| 7 | Regressions were missing for the above. | Added tests for total-cap coverage, snapshot content immutability, frozen-option validation, `question_text` (present + null), multi-role assessments, DS identifier namespace, skill_id/slug mismatch, missing `algorithm_version` and empty/partial skills. |
| 8 | — | Verified via the full suite + Pint + migration up/down cycle (see §7). |

## 8. Deployment

1. Merge branch `feature/backend-dynamic-baseline-assessment` (not done here —
   no commit/push/merge was performed).
2. `php artisan migrate` (additive, online-safe; nullable columns).
3. Ensure Data Science flag/token are set:
   `DATA_SCIENCE_BASELINE_ENABLED=true`, `DATA_SCIENCE_SERVICE_TOKEN=...`,
   `DATA_SCIENCE_BASELINE_VERSION=v1.0`.
4. Optional tunables (defaults shown): `BASELINE_MIN_QUESTIONS_PER_SKILL=1`,
   `BASELINE_MAX_QUESTIONS_PER_SKILL=3`, `BASELINE_MAX_TOTAL_QUESTIONS=30`,
   `BASELINE_DETERMINISTIC_SELECTION=false`.
5. Confirm every approved career role's required skills have active items in the
   target version, else `INSUFFICIENT_QUESTION_COVERAGE` is returned at start.
6. Check `BASELINE_MAX_TOTAL_QUESTIONS` is `>=` the largest role's required-skill
   count, else starts fail with `INSUFFICIENT_QUESTION_CAPACITY`.
7. Author `question_text` for the item bank if the frontend relies on
   server-supplied prompts (`null` until authored).

### Rollback

1. Roll back code to the previous release.
2. `php artisan migrate:rollback --step=5` (drops the snapshot content columns,
   `question_text`, the snapshot table, the assessment columns, and restores the
   original uniqueness index). **Existing rows keep working** — `career_role_id`
   is nullable, so legacy assessments are unaffected.
   - Caveat: reverting migration `2026_09_28_000005` will fail if a learner
     already holds two assessments for the same version under different roles.
     Remove the extra rows first, or leave that migration applied.
3. Turn `DATA_SCIENCE_BASELINE_ENABLED` off to disable the dynamic path entirely.

## 9. Manual verification checklist

- [ ] Learner with no role sends no `career_role_id` → 422 on `career_role_id`.
- [ ] Learner picks an unapproved role → 422 on `career_role_id`.
- [ ] Learner picks an approved role → 201, `questions[]` present with
      `question_text`/`item_type`/`options`, and no `correct_answer`/`scoring_rule`
      anywhere in the payload.
- [ ] Every required skill appears in `skill_coverage.skills` with `covered: true`.
- [ ] A role whose skill has no active item → 422 `INSUFFICIENT_QUESTION_COVERAGE`
      with `details.uncovered_skills`, and no assessment row created.
- [ ] Set `BASELINE_MAX_TOTAL_QUESTIONS` below a role's required-skill count →
      422 `INSUFFICIENT_QUESTION_CAPACITY`, no assessment row created.
- [ ] With a cap equal to the skill count, start succeeds and **no** required
      skill is missing from `skill_coverage`.
- [ ] `GET` the assessment → same questions as start; edit an item's
      `question_text`/`options`/`item_type` and deactivate it, then `GET` again →
      the frozen text/options/type are unchanged.
- [ ] Submit an answer that was valid at snapshot time but has since been removed
      from the live option list → still accepted.
- [ ] Start assessments for two different roles → two distinct assessments.
- [ ] Re-start the same role + version → 409 `ASSESSMENT_ALREADY_EXISTS`.
- [ ] Submit a question not in the snapshot → 422 `UNAUTHORIZED_QUESTION`.
- [ ] Submit the same question twice → 422 `DUPLICATE_RESPONSE`.
- [ ] Submit an invalid `single_choice` option → 422 `INVALID_ANSWER_OPTION`.
- [ ] Submit a subset of questions → 422 `INCOMPLETE_RESPONSES`, status still
      `in_progress`.
- [ ] Valid submit → 200, `status: completed`, `normalized_skills` present;
      `skill_evidences` + `skill_evaluations` rows exist for each evaluated skill.
- [ ] Re-submit → 409 `ASSESSMENT_ALREADY_COMPLETED`; no duplicate evidence rows.
- [ ] Stop the intelligence service → 503 `INTELLIGENCE_SERVICE_ERROR`; assessment
      stays `in_progress`; no evidence rows written.
- [ ] Make the service return a skill outside the role's snapshot → 502
      `INTELLIGENCE_SKILL_OUT_OF_SCOPE`; nothing persisted.
- [ ] Make the service return a `skill_id` that disagrees with its `slug` → 502
      `INTELLIGENCE_SKILL_MISMATCH`; nothing persisted.
- [ ] Make the service omit `algorithm_version` → 502 `INTELLIGENCE_INVALID_RESPONSE`.
- [ ] Make the service return `skills: []` → 502; **no** fabricated evaluations.
- [ ] Make the service return only one of two required skills → 200, exactly one
      evaluation persisted, python/sql gap left for readiness to report.
- [ ] Inspect the outbound FastAPI payload → `question_ids` and
      `responses[].question_id` are identical `item_id` strings (e.g. `sql-001`).
- [ ] After a successful submit, `POST /readiness/calculate` for a learner with
      all required-skill evaluations no longer returns `ASSESSMENT_INCOMPLETE`.
- [ ] Another learner's `GET`/`PATCH`/`submit` → 404 `ASSESSMENT_NOT_FOUND`.
