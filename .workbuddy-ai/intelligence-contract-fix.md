# Fixing the `intelligence/*` integration break

**Status:** fixed and committed (`49e56b1`). 302 tests pass, Pint clean.
**Scope:** `POST /api/v1/intelligence/calculate` was broken against the deployed Data Science service.

---

## The problem

Laravel's `IntelligenceClient` called three Data Science endpoints:

```
POST /api/v1/intelligence/skill-gap
POST /api/v1/intelligence/readiness
POST /api/v1/intelligence/roadmap
```

**None of them exist.** The deployed service exposes no `/api/v1/intelligence/*` namespace at all.

The failure was invisible in CI: the HTTP fakes answered *whatever URL was requested*, so all 300
tests stayed green while every real calculation 404'd. **A fake cannot catch a wrong URL** — only an
explicit assertion can. That is the single most important lesson here, and it is now guarded by a test.

## What the deployed service actually offers

Verified from the service's own OpenAPI document (`GET /openapi.json`) and a live smoke test — not
from the SRS text, which turned out to describe a namespace that was never built.

| Path | Status |
|---|---|
| `POST /api/v1/skill-gap` | **200** — `algorithm_version=skill-gap-v1` |
| `POST /api/v1/skill-match` | 200 — `skill-match-v1` |
| `POST /api/v1/intelligence/skill-gap` | **404** |
| `POST /api/v1/intelligence/readiness` | **404** |
| `POST /api/v1/intelligence/roadmap` | **404** |
| `POST /api/v1/roadmap` | **404** (roadmap genuinely not deployed) |

**The key discovery: `/api/v1/skill-gap` *is* the "intelligence" endpoint.** Its response carries
the per-skill gaps *and* the readiness block in one body:

```
algorithm_version, base_readiness_score, readiness_score, critical_skill_cap_applied,
critical_skill_readiness_cap, critical_skill_gap_count, critical_skill_ids,
critical_skill_names, total_skills, met_skills, skills_with_gap, skill_results[]
```

…and its `skill_results[]` matches `validateSkillGap` field-for-field. So this was never a missing
feature. It was **a wrong path prefix plus one redundant round-trip**.

## Two flows, two contracts

These were being conflated. They are genuinely different and must stay separate:

| | Composite Readiness | Intelligence |
|---|---|---|
| Entry point | `POST /api/v1/readiness/calculate` | `POST /api/v1/intelligence/calculate` |
| Service call | `/api/v1/skill-match` (`skill-match-v1`) | `/api/v1/skill-gap` (`skill-gap-v1`) |
| Who owns the score | **Laravel** (weights + cap 69.0) | the service returns it (own cap 60.0) |
| Validator | `ReadinessResultResource` | `IntelligenceResponseValidator` |

## Changes

**`config/services.php`** — `skill_gap_path` default → `/api/v1/skill-gap`. Removed `readiness_path`
(no such endpoint). Roadmap path marked unverified.

**`IntelligenceClient`**
- New `toSkillGapRequest()` maps the canonical nested payload onto the deployed **flat** contract,
  sending *only declared fields* — we do not rely on the service ignoring unknown keys.
- `calculateReadiness()` deleted (single caller, no endpoint to call).
- `correlationContext()` reads both payload shapes for structured logging.

**`IntelligenceService`** — one call; `$readiness = $skillGap`; both contracts still validated
independently, so a response satisfying one and not the other is still rejected. Algorithm version
now comes only from the response: the service echoes *its own* version, so Laravel must never record
the value it asked for.

**Docs** — `INTEGRATION_README.md` and `.env.example` corrected; the nested request-body example was
replaced with the real flat one.

## A bug I introduced and caught

Going flat silently broke the client's structured logs — they still read the nested shape, so
`student_profile_id`, `career_role_id` and `career_role_version` became `null,null,null`. **Nothing
failed.** The logs just stopped being useful, which is the worst way for observability to break.

Fixed, and pinned with a `Log::spy()` test. *Lesson: after changing a payload shape, read the logs —
green tests will not tell you.*

## Tests

| | Before | After |
|---|---|---|
| Laravel suite | 300 passed | **302 passed (1104 assertions)** |
| Pint | PASS 291 files | PASS 291 files |

Three new guards, one replaced:
- `test_only_deployed_paths_are_called` — pins the exact URL and asserts no `/intelligence/` path is ever called.
- `test_skill_gap_request_matches_the_deployed_contract` — pins the wire body field-for-field.
- `test_correlation_ids_are_logged_for_the_skill_gap_call` — pins log fidelity.
- `test_versioned_paths_are_used` removed (it asserted the invented contract).

## Verified end to end against the live service

Laravel's **real** payload builder, client and validators were run against the deployed service,
deliberately stopping before persistence (nothing written to the database):

```
role          : #3 Data Analyst (4 skills)
evaluations   : 5 of 4
payload built : OK (nested internal shape)
live response : algorithm_version=skill-gap-v1 total_skills=4 met_skills=0 skills_with_gap=4
validateSkillGap   : PASSED
validateReadiness  : PASSED
```

The contract alignment is proven, not merely unit-tested. (`readiness_score=0` is legitimate —
profile #86 has all four Data Analyst skills evaluated, but every level is below the required level.)

## ⚠️ The remaining blocker is data, not the token

**`DATA_SCIENCE_SERVICE_TOKEN` is not set in `.env`** — true, and `IntelligenceClient` /
`DataScienceClient` fail closed with `503 *_NOT_CONFIGURED` before any network call. But:

> **The deployed service does not enforce auth at all.** `POST /api/v1/{skill-gap,skill-match,baseline}`
> with **no `Authorization` header** returns **422** (validation), never 401.

So the token is a **Laravel-side-only** requirement — the service ignores whatever is sent. **Any
non-empty value unblocks it; there is no need to wait for Data Science to issue one.**

### What actually blocks a working integration

| Blocker | Evidence |
|---|---|
| `primary_career_role_id` is NULL for **all 101** profiles | Omitting `career_role_id` in the request falls back to that null column → 422 `CAREER_ROLE_REQUIRED`. The client must pass it explicitly. |
| Only **9 `SkillEvaluation` rows exist**, all on profile #86 | The other 100 profiles cannot use the feature at all → 422 `ASSESSMENT_INCOMPLETE`. |
| Only 1 of 3 roles is usable by that one profile | #86 covers role #1 (Frontend, 4 skills) and #3 (Data Analyst, 4 skills); role #2 (Backend) is missing skills 9 and 10. |

## 🔒 Security gap

The Data Science service is **publicly callable by anyone**. US-INT-01 §4 assumes a mandatory
service credential and `INTEGRATION_README.md` states the calls are "all authenticated" — neither is
true of the deployment. Either the service should enforce its token, or the SRS/README claim should
be corrected; leaving the mismatch is the worst of both.

## Open items

1. Set `DATA_SCIENCE_SERVICE_TOKEN` to any non-empty value (Laravel-side gate only).
2. Seed `primary_career_role_id` and per-role `SkillEvaluation` coverage, or the feature is
   unreachable for 100 of 101 learners.
3. Decide the auth question above with Data Science.
4. `IntelligencePersistenceService` should derive the critical cap from per-skill `is_critical` +
   `match_ratio` like `ReadinessService` does, rather than trusting the service's flag.
5. Versioning naming: `composite-readiness-v1` (structure) vs `config-v{n}` (values) vs `readiness-v1`.
6. Roadmap: contract unverified — confirm the path and body with Data Science before enabling.
7. 8 commits sit unpushed on `origin/feature/authentication`.
