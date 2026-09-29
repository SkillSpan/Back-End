# Baseline Assessment — Data Science Contract Verification (Task B)

**Status: NOT COMPATIBLE — the endpoint does not exist yet.**
This document records what was actually verified, not what was assumed.

## 1. What was inspected

The Data Science repository was located and read directly:

| Path | Contents |
|---|---|
| `D:\Projects\SkillBridge\data-science_data-analysis\app\main.py` | FastAPI app, router registration |
| `D:\Projects\SkillBridge\data-science_data-analysis\app\api\routes\skill_gap.py` | The only business router |
| `D:\Projects\SkillBridge\data-science_data-analysis\app\schemas\skill_gap.py` | Skill-gap request/response models |
| `D:\Projects\SkillBridge\backend\SkillBridge\data-science_data-analysis\` | Second copy — identical |

## 2. Finding

The service registers **exactly one** business router:

```python
app.include_router(skill_gap_router)   # prefix="/api/v1/skill-gap"
```

A repository-wide search for `baseline` (case-insensitive, all `.py` files,
both repository copies) returns **zero matches**. There is:

- no `/api/v1/baseline` route,
- no baseline request/response schema,
- no baseline scoring logic.

The endpoint the Laravel client calls
(`config('services.data_science.baseline.path')`, default `api/v1/baseline`)
**does not exist in the Data Science service.**

> This is the single most important Task B result. Every Laravel-side
> guarantee below was verified against Laravel's own code and tests; the
> Data Science side could not be verified as compatible because it has no
> implementation to compare against.

## 3. What Laravel sends (authoritative — read from code)

Source: `app/Services/Baseline/BaselineDataScienceClient::compute()`.

```
POST {DATA_SCIENCE_URL}/api/v1/baseline
Authorization: Bearer {DATA_SCIENCE_SERVICE_TOKEN}
Content-Type: application/json
X-Request-ID: {request id}

{
  "student_profile_id": 42,          // int, always present
  "user_id": 17,                     // int, always present
  "assessment_version": "v1.0",      // string, always present
  "career_role_id": 3,               // int, present when role-scoped
  "career_role_version": 1,          // int, present when known
  "question_ids": ["sql-001", "python-002"],  // string[] — item bank item_id
  "responses": [
    { "question_id": "sql-001", "answer": "B" }
  ]
}
```

### Identifier namespace (this is the contract's sharpest edge)

`question_ids[]` and every `responses[].question_id` are the **same opaque
string**: the item bank's `item_id` (e.g. `sql-001`) — **never** the numeric
primary key, and never the snapshot row's `id`.

The Laravel API accepts that same string from the learner as
`question_id`, so the three stay aligned end-to-end:

```
learner request  →  snapshot.item_id  →  question_ids[] / responses[].question_id
     "sql-001"         "sql-001"                      "sql-001"
```

If the Data Science service needs the numeric primary key it must resolve
it itself via `GET /api/v1/internal/baseline-items?version=v1.0`.

### Fields the task description mentioned that Laravel does **not** send

The Task B brief listed `questions`, `skill_mappings`, and `responses` as
candidate request fields. Verified against the code:

| Field | Sent? | Notes |
|---|---|---|
| `questions` | **No** | Laravel sends `question_ids` (string ids only). Full question bodies are reachable via the internal items endpoint. |
| `skill_mappings` | **No** | Not sent. The role↔skill mapping is derivable from `career_role_id` + `career_role_version`. |
| `responses` | **Yes** | Array of `{question_id, answer}`. |
| `career_role_id` | **Yes** | Present when the assessment is role-scoped. |
| `assessment_version` | **Yes** | Always present. |

These three omissions are **design decisions, not oversights** — but they
are real differences from the brief and are flagged here rather than
silently papered over. If the Data Science service is built to require
`questions` or `skill_mappings`, Laravel must be changed to send them; that
is a documented pending change (§6).

## 4. What Laravel accepts back (authoritative — read from code)

Validated by `BaselineAssessmentService::normalizedSkills()` and
`extractAlgorithmVersion()`.

```
200 OK
{
  "algorithm_version": "baseline-v1.0",   // REQUIRED, non-empty string
  "skills": [                              // REQUIRED, non-empty array
    {
      "slug": "sql",                       // REQUIRED, must resolve to an active Skill
      "skill_id": 7,                       // optional; if present MUST match slug's id
      "level": 2.5,                        // REQUIRED, numeric, 0..5
      "confidence": 0.7                    // optional (defaults 0.0), numeric, 0..1
    }
  ],
  "student_profile_id": 42,                // ignored by Laravel
  "overall_score": 60                      // ignored by Laravel
}
```

Enforcement, in order (all failures are explicit, never silently repaired):

| Condition | Result |
|---|---|
| `skills` missing / not an array / empty | `502 INTELLIGENCE_INVALID_RESPONSE` |
| entry not an object | `502 INTELLIGENCE_INVALID_RESPONSE` |
| `slug` missing or blank | `502 INTELLIGENCE_INVALID_RESPONSE` |
| `slug` resolves to no active skill | `502 INTELLIGENCE_INVALID_RESPONSE` |
| `skill_id` present but ≠ slug's id | `502 INTELLIGENCE_SKILL_MISMATCH` |
| skill not in the assessment snapshot | `502 INTELLIGENCE_SKILL_OUT_OF_SCOPE` |
| `level` non-numeric or outside 0..5 | `502 INTELLIGENCE_INVALID_RESPONSE` |
| `confidence` non-numeric or outside 0..1 | `502 INTELLIGENCE_INVALID_RESPONSE` |
| `algorithm_version` missing/blank/non-string | `502 INTELLIGENCE_INVALID_RESPONSE` |

### Response naming difference — note for the DS implementer

The brief referred to a `skill_evaluations` array. Laravel **reads
`skills`**, not `skill_evaluations`. If the service emits
`skill_evaluations`, Laravel will reject the response as containing no
skills. Agree on one name before implementation; Laravel's current name is
`skills`.

### Partial results are preserved, not fabricated

A response covering only some of the snapshot's skills is persisted **as
returned**. Laravel never invents an evaluation to fill a gap. Readiness
(`ReadinessService`) then raises its own `ASSESSMENT_INCOMPLETE` for the
skills still missing an evaluation. There is deliberately **no** fallback
to `learner_skills` projected levels.

### Out-of-scope protection

Every returned skill must belong to the assessment's frozen snapshot
(`snapshot.skill_id`). A skill outside it — even a real, active skill — is
rejected with `INTELLIGENCE_SKILL_OUT_OF_SCOPE`. The Data Science service
cannot widen a learner's assessed skill set.

## 5. Error handling on the client side

| Situation | Result |
|---|---|
| integration disabled or URL empty | `503 BASELINE_INTEGRATION_NOT_CONFIGURED` |
| service token missing | `503 BASELINE_INTEGRATION_NOT_CONFIGURED` |
| connection failure / timeout | `503 INTELLIGENCE_SERVICE_UNAVAILABLE` |
| any other transport error | `502 INTELLIGENCE_INTEGRATION_FAILED` |
| HTTP 422 | `422 INTELLIGENCE_VALIDATION_ERROR` (body echoed in `details`) |
| HTTP ≥ 500 | `503 INTELLIGENCE_SERVICE_ERROR` |
| other non-2xx | `502 INTELLIGENCE_UNEXPECTED_STATUS` |
| non-JSON body | `502 INTELLIGENCE_INVALID_RESPONSE` |

All of these leave the assessment `in_progress` and write nothing
(transactional), so the learner can retry.

## 6. Required Data Science changes (actionable)

The Data Science team must implement the endpoint before this feature can
run end-to-end. Minimum work:

1. **Add the route.** `POST /api/v1/baseline` (matching
   `DATA_SCIENCE_BASELINE_PATH`), registered in `app/main.py`.
2. **Model the request** exactly as §3 — including `question_ids` and the
   `item_id`-string namespace for `responses[].question_id`.
3. **Model the response** exactly as §4 — top-level `skills` (not
   `skill_evaluations`), each entry `{slug, skill_id?, level, confidence}`,
   plus a non-empty `algorithm_version`.
4. **Authenticate.** Require `Authorization: Bearer {service token}` and
   echo/accept `X-Request-ID`.
5. **Decide on `questions` / `skill_mappings`.** If the scoring logic needs
   full question bodies or the role↔skill mapping inline (instead of
   resolving them via `career_role_id` / the internal items endpoint),
   confirm this now — Laravel must add those fields, which is a change on
   the Laravel side too.
6. **Agree the `skill_id` policy.** Laravel validates that a supplied
   `skill_id` matches its `slug`. Sending `slug` alone is safest.

## 7. Honest compatibility statement

- Laravel side: **fully implemented, tested (296 tests green), and
  self-consistent.** The internal identifier namespace, validation rules,
  and error taxonomy are all enforced by tests.
- Data Science side: **not implemented.** No baseline route exists.
- Therefore the integration is **NOT verified compatible** and **must not
  be described as working**. The Laravel client is ready to talk to a
  service that implements §3/§4; today no such service exists.

No claim of compatibility is made anywhere in this repository on the basis
of assumption — only on the basis of the code and tests listed above.
