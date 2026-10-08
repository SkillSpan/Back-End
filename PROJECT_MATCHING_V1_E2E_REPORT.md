# Project Matching v1 — Live E2E against Production FastAPI

**Date:** 2026-10-06 · **Result:** ✅ **PASS** · **Code changes required:** **none**

Executed through Laravel itself (`POST /api/v1/projects/{id}/match`), not by calling FastAPI
directly. **No `Http::fake()` anywhere.** The only `Http::` hooks used are
`globalRequestMiddleware` / `globalResponseMiddleware`, which *observe* the real request and response;
they never substitute one.

---

## ENVIRONMENT

| | |
|---|---|
| **DB** | `skillspan_pm_e2e` @ `127.0.0.1:3306` — **local MariaDB 10.4.32 (XAMPP)**, 73 tables, freshly migrated |
| **APP_ENV** | `local` (for this run) |
| **PROJECT_MATCHING_ENABLED** | `true` |
| **FastAPI URL** | `https://skillspan-intelligence.onrender.com` |
| **FastAPI path** | `/api/v1/project-matching` |
| **token** | **CONFIGURED** |
| **timeout** | 60 s |

⚠️ **Production DB was never touched.** The shared remote MySQL (`…clever-cloud.com`) was never
connected to. `DB_*` was overridden per command, and both scripts carry a hard guard that refuses to
run unless the connected database is exactly `skillspan_pm_e2e`. `.env` was **not modified** —
`DATA_SCIENCE_PROJECT_MATCHING_ENABLED` was passed as a per-command environment variable instead.

## FIXTURE

Isolated, deterministic, created from scratch in the local DB.

| | |
|---|---|
| **learner_id** | `2` (`pm.e2e.learner@example.com`, `status=active`, role `learner`) |
| **student_profile_id** | `1` |
| **project_id** | `1` (`PM E2E — Eligible Project`, `status=open`, `confidentiality=public`, `application_deadline=+30d`, `end_date=+120d`) |
| **project_version** | `3` |
| **required_skills** | `skill_id=1` min `3.0` **critical** · `skill_id=2` min `2.0` non-critical |
| learner skill evaluations | skill 1 → `4.0` (≥ 3.0 ✓) · skill 2 → `3.5` (≥ 2.0 ✓) |
| algorithm configuration | id `1`, `status=active` |

Edge-case fixture: **project_id `2`** (`version=1`) with a **critical** required skill `3`
(min `4.0`) for which the learner has **no evaluation at all**.

## WIRE CONTRACT — **PASS**

Captured from the **raw outbound bytes** (1028 B), Authorization header redacted.
URL: `https://skillspan-intelligence.onrender.com/api/v1/project-matching` · method `POST`.

Top-level keys sent — **8/8 required present, 0 missing, 0 extra**:

```
request_id, algorithm_version, configuration_version, project_version,
learner, project, required_skills, validation
```

| Field | Value on the wire |
|---|---|
| `request_id` | `cd34e49d-26ba-4f7c-bd56-1b94584b72f9` |
| `algorithm_version` | `project-matching-v1` |
| `configuration_version` | `project-matching-config-v1` |
| `project_version` | `3` |

```json
{
  "request_id": "cd34e49d-26ba-4f7c-bd56-1b94584b72f9",
  "algorithm_version": "project-matching-v1",
  "configuration_version": "project-matching-config-v1",
  "project_version": 3,
  "learner": {
    "user_id": 2,
    "student_profile_id": 1,
    "current_skills": [
      { "skill_id": 1, "level": 4,   "required_level": 3, "meets_requirement": true },
      { "skill_id": 2, "level": 3.5, "required_level": 2, "meets_requirement": true }
    ],
    "availability": "full_time",
    "preferred_work_type": "remote"
  },
  "project": {
    "id": 1, "version": 3, "type": "simulation", "domain": "Software Engineering",
    "difficulty": 0.6, "work_mode": "remote", "role": "Backend Developer",
    "schedule": "flexible", "organization_id": null, "confidentiality": "public"
  },
  "required_skills": [
    { "skill_id": 1, "skill_name": "PM E2E Critical Skill",  "minimum_level": 3, "is_critical_entry": true },
    { "skill_id": 2, "skill_name": "PM E2E Secondary Skill", "minimum_level": 2, "is_critical_entry": false }
  ],
  "validation": {
    "eligibility_state": "eligible",
    "validation_state": "validated",
    "constraints": [], "skill_gaps": [], "eligibility_reasons": [],
    "authorization": "authorized"
  }
}
```

## FASTAPI — HTTP **200**

## LARAVEL ENDPOINT — HTTP **200**

## MATCH RESULT

| | |
|---|---|
| score | **55** (in range 0..100 ✓) |
| matching_state | `scored` |
| eligibility_state | `eligible` |
| algorithm_version | `project-matching-v1` |
| configuration_version | `project-matching-config-v1` |

## FACTORS

| factor | value |
|---|---|
| skill_compatibility | 100 |
| learning_value | 0 |
| career_relevance | 50 |
| interest_match | 50 |
| availability_fit | 50 |

Also present: `weighted_contributions` ✓ · `explanation` ✓ · `limiting_factors` ✓ · `skill_results` ✓

## PERSISTENCE

| | |
|---|---|
| **snapshot** | **PASS** — id `2`, `status=validated`, project `1`, student_profile `1`, `project_version=3`, `algorithm_version=project-matching-v1`, `configuration_version=project-matching-config-v1`, `request_id` identical to the one sent |
| **recommendation** | **PASS** — id `1`, `user_id=2`, `candidate_id=1`, `type=project`, `score=55.00`, `algorithm_version=project-matching-v1`, `configuration_version=project-matching-config-v1`, `project_version=3`, `matching_state=scored`, `eligibility_state=eligible` |

## READBACK

| | |
|---|---|
| **recommendations list** | **PASS** — `GET /api/v1/recommendations` → `200`, 1 row, contains project `1` |
| **project recommendation** | **PASS** — `GET /api/v1/projects/1/recommendation` → `200` (keys: `project_id`, `score`, `reasons`, `limiting_factors`, `algorithm_version`, `configuration_version`) |

## EDGE CASE

Learner matched against project `2`, whose critical skill `3` (min `4.0`) has no evaluation.

| | |
|---|---|
| **critical-skill ineligible** | **PASS** — `422 PROJECT_MATCH_INELIGIBLE` |
| **FastAPI not called** | **PASS** — 0 outbound requests captured |
| **no recommendation persisted** | **PASS** — 0 new snapshots, 0 new recommendations |

```json
{ "code": "PROJECT_MATCH_INELIGIBLE",
  "details": { "reasons": ["One or more critical required skills are missing or below the required level."] } }
```

The "FastAPI not called" claim is proven by counting the **real** outbound requests through the live
HTTP client during that call: **zero**. Eligibility rejects before a snapshot is even created, and the
network call happens strictly after snapshot creation — so no snapshot row and no outbound request are
the same fact, observed two independent ways.

## TEST SUITE

| check | result |
|---|---|
| `php artisan test` | **1146 passed (4085 assertions)** — 72.71 s |
| `vendor/bin/pint --test` | **PASS** — 463 files |
| `git diff --check` | **clean** |
| `git status --short` | *(empty — working tree clean)* |

**`phpunit.xml` was NOT modified — and that was verified, not assumed.** The analyst's condition was
"only if the tests are actually affected". I ran the suite twice:

- **RUN A** — as-is: **1146 passed / 4085 assertions**
- **RUN B** — with `DATA_SCIENCE_PROJECT_MATCHING_ENABLED=true` forced into the environment
  (simulating exactly the leak scenario): **1146 passed / 4085 assertions** — *identical*

So the flag does not alter which code path the suite exercises, and adding
`<env name="DATA_SCIENCE_PROJECT_MATCHING_ENABLED" value="false"/>` is **not warranted**. Note also
that the flag was never written to `.env` at all, so there is no leak vector to begin with.

## FINAL STATUS

# ✅ PASS

---

## Notes for the DS team

**1. Cold start.** The Render service was cold on the first raw probe: **34.96 s** for the call to
return. That is well inside the 60 s timeout, and the recorded E2E run completed on **attempt 1** with
`HTTP 200` — **no retry was needed**. The retry-once-on-cold-start path is implemented and was
exercised, but not triggered.

**2. One failure in the middle of the run was in MY harness, not in the app.** The first E2E attempt
returned `502 INTELLIGENCE_CALCULATION_FAILED` after ~1 ms. Root cause: `globalRequestMiddleware`
receives a **`GuzzleHttp\Psr7\Request`**, not an `Illuminate\Http\Client\Request`; my type hint threw a
`TypeError` *inside the send path*, so the observation code broke the very call it was observing. This
is a genuine Laravel 12 gotcha worth knowing, but it is **not** a defect in `IntelligenceClient` — a
raw call with no middleware returned `200` on the first try. Fixed by reading the request generically
and capturing the raw body instead.

**3. No code changes were required to achieve this E2E.** The harness lives in gitignored scratch
files (`storage/app/_pm_e2e_fixture.php`, `_pm_e2e_run.php`, `_pm_e2e_result.json`,
`_pm_e2e_state.json`), so nothing can be committed by accident. **Nothing was committed, pushed or
deployed.**

**4. Optional follow-up — matching the Roadmap E2E pattern.** The Roadmap E2E (`cfaf09e`) committed a
permanent `database/seeders/E2eRoadmapFixtureSeeder.php` plus the phpunit pin. This E2E deliberately
did **not** touch the repo. If you want the project-matching fixture to be reproducible in-repo the
same way, say so and I will add an equivalent idempotent
`database/seeders/E2eProjectMatchingFixtureSeeder.php` — **and will send the diff for review first**,
per the instruction not to commit without approval.

**5. Local environment side effect.** XAMPP's MariaDB was **not running** before this task and is
**now running** on `127.0.0.1:3306`; the throwaway database `skillspan_pm_e2e` was created inside it.
Other local databases (`aug`, `iug`, `laravel`, `project`, `skill_bridge`, `skillbridge`, `skillspan`,
`un_paletine`, …) were never touched. Stop the server with `C:\xampp\mysql_stop.bat` when you no longer
need it.
