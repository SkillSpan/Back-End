# SkillSpan Laravel ↔ FastAPI Integration (US-INT-01)

## Architecture

`Frontend -> Laravel -> FastAPI -> Laravel -> Database -> Frontend`

The frontend never calls FastAPI directly. Laravel remains the source of truth
for users, roles, skills, evaluations, permissions, decision snapshots, and
all persistence. FastAPI is stateless regarding authoritative learner data: it
receives a validated payload, computes intelligence, and returns a result that
Laravel validates before anything is stored.

## Laravel endpoints

Legacy (backward compatible, unchanged contract):

- `POST /api/v1/readiness/calculate` — readiness via the skill-gap flow
- `GET  /api/v1/readiness/latest` — latest persisted readiness result

New (US-INT-01 atomic decision: skill gap + readiness + roadmap):

- `POST /api/v1/intelligence/calculate` — full decision; 201, or nothing persisted
- `GET  /api/v1/intelligence/latest` — latest successful decision for the learner

All endpoints require `Authorization: Bearer <Sanctum token>` and the learner
role. Every response carries the `X-Request-ID` correlation header.

### Calculate request

```json
{
  "career_role_id": 3
}
```

If `career_role_id` is omitted, Laravel uses `student_profiles.primary_career_role_id`.
The role must exist (404 `CAREER_ROLE_NOT_FOUND`), be `approved` (422
`CAREER_ROLE_NOT_APPROVED`), and have required skills (422 `CAREER_ROLE_NO_SKILLS`).

## FastAPI contract (deployed paths)

Laravel calls — POST JSON, authenticated (see below):

- `POST {DATA_SCIENCE_SERVICE_URL}/api/v1/skill-gap` — per-skill gaps **and**
  the readiness block in one response
- `POST {DATA_SCIENCE_SERVICE_URL}/api/v1/roadmap` (gated by
  `DATA_SCIENCE_ROADMAP_ENABLED`)

There is **no `/api/v1/intelligence/*` namespace** in the deployed service, and
**no standalone readiness endpoint**: the readiness block (`readiness_score`,
`base_readiness_score`, `critical_skill_cap_applied`, `critical_skill_gap_count`,
`critical_skill_names`, …) arrives inside the `/api/v1/skill-gap` response, so a
calculation is a single round-trip rather than two.

These paths were verified against the service's own OpenAPI document
(`GET {DATA_SCIENCE_SERVICE_URL}/openapi.json`), not against the SRS text.
The roadmap endpoint is the exception: **it is not deployed in any form**, so
its path is unverified and enabling `DATA_SCIENCE_ROADMAP_ENABLED` will fail
until Data Science publishes it.

The separate readiness flow (`POST /api/v1/readiness/calculate`) does **not**
use these paths. It calls `/api/v1/skill-match` (`skill-match-v1`) via
`skill_match_path` and computes the Composite Readiness and the Critical Cap in
Laravel — Laravel owns both, so that flow never asks the service for a
readiness score. Any path can be overridden by environment variable without
code changes.

### Request body contract

The body is FLAT, matching the deployed `SkillGapRequest`. The response must
echo the identity fields exactly (mismatch = 502
`INTELLIGENCE_RESPONSE_MISMATCH`, nothing stored):

```json
{
  "student_profile_id": 1,
  "career_role_id": 3,
  "career_role_version": 1,
  "user_id": 8,
  "target_role": "Data Analyst",
  "skills": [
    {
      "skill_id": 5, "skill_name": "SQL",
      "current_level": 2.5, "required_level": 4.0,
      "importance_weight": 0.45, "is_critical": true
    }
  ]
}
```

Only the fields the contract declares are sent. Laravel's richer internal
payload — availability, per-skill `confidence`, the `evidence` summary, and
`prerequisite_skill_ids` — is kept for the decision snapshot and the response
validator, and is deliberately **not** transmitted, because the service is not
relied upon to ignore unknown keys.

Skill levels are on the project-standard `0..5` scale; invalid values are
rejected before the call (422 `INTELLIGENCE_VALIDATION_ERROR`). No PII, no
tokens, no evidence contents are ever sent.

### Response requirements

Common: echo `student_profile_id`, `career_role_id`, `career_role_version`, and
a non-empty `algorithm_version`. Note the service owns its algorithm version and
echoes its own — Laravel records what came back, never what it asked for.

Skill gap: `skill_results[]` with exactly one complete entry per request skill —
`skill_id`, `current_level`, `required_level`, `importance_weight`, `is_critical`,
`gap`, `status` — echoing the request values. Unknown or duplicate skills,
negative gaps, or mismatched levels are rejected (502).

Readiness (same response as the skill gap): `readiness_score` and
`base_readiness_score` in `0..100`, and consistent `total_skills` /
`met_skills` / `skills_with_gap` counts. Scores outside 0..100 are rejected
(502). The critical-cap fields (`critical_skill_cap_applied`,
`critical_skill_gap_count`, `critical_skill_names`) are validated **when
present but never required** — the cap is Laravel's decision, so the service
is not obliged to report it.

Roadmap: `roadmap_version` (int ≥ 1), `status`, `phases[]` with `actions[]`
(`action_id` unique, `action_type`, `title`, optional `target_skill_id` /
`prerequisite_skill_ids` referencing request skills only, `priority_score` in
0..1, positive effort estimates). An invalid or unknown reference rejects the
whole decision (502) — no partial persistence. The roadmap contract is
unverified: no endpoint is deployed, so this is the intended shape rather than
a confirmed one.

## Service authentication

Laravel → FastAPI calls send:

```
Authorization: Bearer {DATA_SCIENCE_SERVICE_TOKEN}
X-Request-ID: {correlation id}
Accept: application/json
Content-Type: application/json
```

The token is a dedicated service credential — never a learner Sanctum token,
never exposed to the frontend. It is MANDATORY on every Laravel → FastAPI
intelligence request: when it is missing, Laravel fails locally with 503
`INTELLIGENCE_NOT_CONFIGURED` (legacy readiness flow:
`DATA_SCIENCE_NOT_CONFIGURED`) before any network call — FastAPI is never
called unauthenticated.

## Request ID

One ID per calculation flow. If the incoming request carries `X-Request-ID`,
it is reused; otherwise Laravel generates `Str::uuid()`. The same ID is:
returned to the frontend in the `X-Request-ID` response header, sent to every
FastAPI call, written to structured logs, and stored on the decision snapshot
(`request_id`).

## Decision snapshots

Every calculation first persists a `decision_snapshots` row (status `pending`)
capturing the validated input state BEFORE any FastAPI call: role id/version,
required skills with levels/weights/criticality/prerequisites, learner skill
state, confidence, evidence summary, availability, and the resolved
algorithm/configuration versions. On success the snapshot is marked `succeeded`
inside the same transaction that persists readiness + skill gaps (+ roadmap);
on failure it is marked `failed` — an audit record of the attempt, with no
fabricated results anywhere.

## Algorithm & configuration versions

- `algorithm_version` — from the validated FastAPI response. The configured
  `DATA_SCIENCE_ALGORITHM_VERSION` is only a payload hint/fallback; the stored
  value is what the service actually reported.
- `configuration_version` — from the active `algorithm_configurations` row
  (highest version with `status=active`), stored as `config-v{version}`. If no
  active configuration exists, the calculation fails explicitly with 422
  `INTELLIGENCE_CONFIGURATION_INVALID` — a default is never fabricated. Seed
  the initial configuration with `AlgorithmConfigurationSeeder` (included in
  `DatabaseSeeder`).

## Historical versioning

Decisions are immutable and append-only:

- `readiness_results` — every successful calculation inserts a new row linked
  to its decision snapshot. Old rows are never updated.
- `skill_gap_results` — one row per (decision, skill), never updated.
- `roadmaps` — a new roadmap supersedes the previous one for the same
  (learner, role) via an explicit `active -> superseded` status transition; the
  old roadmap and its actions remain fully accessible.
- **Roadmap versioning is Laravel-owned**: the persisted `roadmaps.version` is
  always the highest existing version for that (learner, role) plus one, so
  historical roadmaps can never collapse onto the same version. The
  `roadmap_version` value returned by FastAPI remains part of the response
  contract and is validated by the response validator, but it is NOT used as
  the persisted version — a stateless service cannot know the learner's
  history.

A failed calculation never overwrites a previous successful result.

## Learner skill state policy

The validated learner skill state is built strictly from the latest
`skill_evaluations` row per required role skill — current level AND confidence
come only from that evaluation. There is deliberately NO fallback to the
`learner_skills` projection: if even ONE required skill lacks an evaluation,
the calculation fails with 422 `ASSESSMENT_INCOMPLETE` (listing the missing
skill ids) before any FastAPI call and before the decision snapshot is
created. An incomplete skill state is never sent to the intelligence service.

## Error behavior

| Condition | HTTP | Code |
|---|---|---|
| Missing learner profile | 422 | `STUDENT_PROFILE_NOT_FOUND` |
| Role does not exist | 404 | `CAREER_ROLE_NOT_FOUND` |
| Role not approved / no skills | 422 | `CAREER_ROLE_NOT_APPROVED` / `CAREER_ROLE_NO_SKILLS` |
| Missing skill evaluations | 422 | `ASSESSMENT_INCOMPLETE` |
| No active algorithm configuration | 422 | `INTELLIGENCE_CONFIGURATION_INVALID` |
| FastAPI 422 | 422 | `INTELLIGENCE_VALIDATION_ERROR` |
| FastAPI 5xx / timeout / connection failure | 503 | `INTELLIGENCE_UNAVAILABLE` |
| Service URL not configured / roadmap disabled | 503 | `INTELLIGENCE_NOT_CONFIGURED` |
| Invalid JSON / malformed response / invalid scores / identity mismatch | 502 | `INTELLIGENCE_INVALID_RESPONSE` / `INTELLIGENCE_RESPONSE_MISMATCH` |
| Unexpected internal failure | 500 | `INTELLIGENCE_CALCULATION_FAILED` |

The legacy readiness flow keeps its historical `DATA_SCIENCE_*` code names.

## Recalculation hooks

An approved skill-data change dispatches `SkillDataChanged`, handled by the
queued `RecalculateIntelligence` listener (`afterCommit`; failures are logged
and never break the original request):

1. Baseline assessment submit (`baseline_assessment_submit`)
2. Admin evidence review (`evidence_review`)
3. Project evaluation — integration hook only; the evaluation module owns the flow
4. Career-role change (`career_role_change`) — also opens a `career_goal_history`
   entry and closes the previous one

## Environment variables

```dotenv
DATA_SCIENCE_SERVICE_URL=http://127.0.0.1:8001
DATA_SCIENCE_SERVICE_TIMEOUT=10
DATA_SCIENCE_SERVICE_TOKEN=   # REQUIRED — see Service authentication
DATA_SCIENCE_API_VERSION=v1
DATA_SCIENCE_SKILL_GAP_PATH=/api/v1/skill-gap
DATA_SCIENCE_SKILL_MATCH_PATH=/api/v1/skill-match
DATA_SCIENCE_ROADMAP_PATH=/api/v1/roadmap   # unverified — no endpoint deployed
DATA_SCIENCE_ROADMAP_ENABLED=false
DATA_SCIENCE_ALGORITHM_VERSION=skill-gap-v1
```

## FastAPI local service

Run from the Data Science repository:

```powershell
python -m venv .venv
.\.venv\Scripts\activate
python -m pip install -r requirements.txt
uvicorn app.main:app --reload --host 127.0.0.1 --port 8001
```

The Data Science service must implement the three versioned intelligence
endpoints above (service token verification + `X-Request-ID` logging
recommended on their side). Until the roadmap endpoint is deployed, keep
`DATA_SCIENCE_ROADMAP_ENABLED=false` — Laravel then omits the roadmap leg
entirely instead of fabricating one.

## End-to-end check

1. Start the Laravel app and seed (`php artisan migrate:fresh --seed` seeds
   the active algorithm configuration).
2. Start the FastAPI service on port `8001`.
3. Log in through Laravel and copy the Sanctum token.
4. Give the learner an approved career role with required skills and a latest
   `skill_evaluations` row per required skill (e.g. via baseline assessment).
5. Call `POST /api/v1/intelligence/calculate`.
6. Verify rows in `decision_snapshots`, `readiness_results`, and
   `skill_gap_results`.
7. Call `GET /api/v1/intelligence/latest` and verify the saved decision.
