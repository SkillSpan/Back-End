# ADR-001 — Composite Readiness ownership & algorithm versioning

**Status:** **Accepted — implemented (Laravel side)** · 2026-09-25
**Date:** 2026-09-24 · accepted and implemented 2026-09-25
**Scope:** `Back-End-feature-authentication` (Laravel) and `chatbot` (FastAPI)
**Supersedes:** nothing. **Superseded by:** nothing.

---

## 0. ⚠️ Correction — verified against the deployed service

§5.3 (V1/V2/V3) was written against a **stale local snapshot** of
`E:/SkillSpan/data-science-service` — an extracted copy dated Aug 20, not a git checkout, whose
only route is `skill_gap`. That is not the deployed service. Re-checked against the deployed
service's own OpenAPI, `https://skillspan-intelligence.onrender.com/openapi.json` (HTTP 200,
36.9 KB, "SkillSpan Intelligence Service" v0.1.0):

```
assessment-reliability   baseline   career-role-configuration/validate
practical-experience     profile-completeness   skill-confidence/calculate
skill-gap                skill-match            health   welcome
```

| Item | ADR claim | Verified reality |
|---|---|---|
| **V2** | "the endpoint is `/api/v1/skill-gap`, not `/api/v1/skill-match`; a caller following the contract gets a 404" | **Wrong.** `POST /api/v1/skill-match` is deployed (tag `Skill Match`, `SkillMatchRequest` → `SkillMatchResponse`). No caller gets a 404. |
| **V3** | "`skill-match-v1` appears nowhere in either service" | **Wrong.** The deployed spec's `algorithm_version` default is `skill-match-v1`. |
| **V1** | "FastAPI still computes a composite score — the exact thing §2 forbids" | **Misleading.** `skill-gap-v1` is a *separate, independently versioned* algorithm with its own cap (60.0) and its own tests. It is not the Composite, and Laravel never calls it for Readiness. Data Science has stated it will not change. It does not conflict with Laravel's 69.0. |

What the deployed service *does* show, and the ADR missed: there is **no `/api/v1/intelligence/*`
namespace**. `App\Services\Intelligence\IntelligenceClient` calls
`/api/v1/intelligence/{skill-gap,readiness,roadmap}` — **none of which exist** — and that code is
reachable through `POST /api/v1/intelligence/calculate`. **That**, not V2, is the live integration
break.

### §5.2b — resolved (implemented 2026-09-24)

The double-ownership in §5.2b was real, and Laravel is now the single owner. `ReadinessResultResource`
reads `critical_skill_names`, the cap value and the count from `snapshot.critical_skill_rule`
(written by `ReadinessService`), and the count is derived from that same array so it cannot drift
from the names. The Skill Match copy is no longer served.
`IntelligenceResponseValidator::validateReadiness()` no longer *requires* the cap metadata — it
validates it whenever present — and `IntelligencePersistenceService` tolerates its absence.

Regression test: `test_a_capped_result_reports_the_cap_and_the_offending_skills`, which also pins
that `fastapi_result` genuinely carries none of those keys.

### Sign-off checklist — revised

- [x] **§5.2b** — single owner chosen: **Laravel** (implemented)
- [x] §3.2 naming split accepted — `composite-readiness-v1`
- [x] ~~**V2** — route name reconciled~~ **withdrawn: the route already matches the contract**
- [x] ~~**V3** — agreed identifier emitted~~ **withdrawn: `skill-match-v1` is already emitted**
- [x] **V1** — resolved, no change: `skill-gap-v1` stays published as an independent,
      independently-versioned algorithm (Data Science's position). Laravel's Composite never calls
      it, so its 60.0 cap cannot conflict with Laravel's 69.0.
- [x] ~~**NEW** — `intelligence/*`~~ — resolved in `49e56b1`: Laravel's intelligence flow now calls
      the deployed unprefixed `/api/v1/skill-gap`, not the non-existent `/api/v1/intelligence/*`.
- [x] `readiness-v1` alias removed from `config/readiness.php`
- [x] `composite_algorithm_version` column added to `readiness_results`
- [ ] Historical `configuration_version` rows reconciled — **forward-only**: rows written before the
      split keep their original value; the two meanings are separated for every row written from
      now on. Back-filling is impossible (§4).
- [x] §5.1 effective-weights + excluded-set + per-component-version persistence implemented
- [x] `config/services.php` fallback documented as a payload hint only, never the persisted value

---

## 1. Context

Composite Readiness is produced from four components. One of them — Skill Match — is
computed by FastAPI (`POST /api/v1/skill-match`). The other three are computed in
Laravel. The aggregation, the Critical Skill Cap, and the Band assignment are Laravel's.

The team has now agreed the ownership boundary. The remaining question was how to
**version** the composite logic so that a historical score can be explained later.

---

## 2. Decision — ownership

**Laravel is the sole owner and orchestrator of Composite Readiness.**

| Responsibility | Owner |
|---|---|
| Skill Match component | **FastAPI** (`POST /api/v1/skill-match`) |
| Practical Experience component | Laravel |
| Assessment Reliability component | Laravel |
| Profile Completeness component | Laravel |
| Component aggregation | **Laravel** |
| Missing-component exclusion + weight redistribution | **Laravel** |
| Critical Skill policy and the 69.0 cap | **Laravel** |
| Band assignment | **Laravel** |
| `is_provisional` determination | **Laravel** |

**FastAPI does not compute the composite, does not receive a composite snapshot, and
does not need any change on this path.** This matches the code as it already stands:
`ReadinessService::calculate()` performs all of the aggregation client-side and takes
only `skill_match_score` plus per-skill results from FastAPI.

---

## 3. Decision — versioning

### 3.1 The three axes are orthogonal and must not share a value

The repository already contains a naming collision that this ADR resolves. Today:

| Where | Value | Meaning |
|---|---|---|
| `config/readiness.php` → `configuration_version` | `readiness-v1` | The Laravel readiness **formula/weights** version |
| `ReadinessService.php:129` → `configuration_version` | `config-v{n}` | The active **`AlgorithmConfiguration` row** version |
| `ReadinessService.php:145` → `algorithm_version` | from FastAPI response | FastAPI's **Skill Match algorithm** version |
| `config/services.php:78` → `algorithm_version` fallback | `skill-gap-v1` | Compatibility fallback |

Two different things are both called `configuration_version`, and the stored value
(`config-v{n}`) is **not** the declared formula version (`readiness-v1`). Storing
`composite-readiness-v1` as a third value in the same field would compound this.

### 3.2 Proposed naming split — structure vs. numbers

| Identifier | Represents | Changes when | Stored on |
|---|---|---|---|
| `composite-readiness-v1` | **Structure.** Which components exist, how they are weighted, how unavailable components are excluded and weights redistributed, the Critical Skill rule, the Banding rule. | A component is added/removed, or the aggregation or redistribution rule changes. | New column `composite_algorithm_version` (see §4) |
| `config-v{n}` | **Numbers.** The active `AlgorithmConfiguration` row: weight values, `critical_skill.minimum_match`, `critical_skill.cap`, band thresholds. | Any numeric value is tuned. | `configuration_version` (existing) |
| `skill-match-v1` | **FastAPI's Skill Match algorithm** — one component, not the composite. | The FastAPI skill-matching algorithm changes. | `algorithm_version` (existing) |
| `readiness-v1` | Deprecated alias for the composite structure. **Recommend removing** to avoid three names for two concepts. | — | — |

**Worked example — the question that motivated this ADR.** Tuning a weight from
`0.65` to `0.60`:

- Structure unchanged → `composite-readiness-v1` **stays**.
- Numbers changed → `configuration_version` becomes `config-v{n+1}`.
- **Result:** a historical row reads `composite-readiness-v1` + `config-v{n}` and can be
  replayed exactly. Under the alternative (weights *inside* the composite version) it
  would need `composite-readiness-v2`, which would also swallow every unrelated numeric
  tweak and make the version string carry no information about *structure*.

### 3.3 Why `skill-match-v1` stays separate

Agreed and confirmed: `skill-match-v1` versions **one component's algorithm**. The
composite version must not absorb it, because the composite can stay at `v1` while the
Skill Match algorithm advances — and a historical composite cannot be explained without
knowing which Skill Match version fed it (§5).

---

## 4. Decision — exposure

**No new field in the public response.** `composite-readiness-v1` is persisted in audit
/ configuration metadata; it may be surfaced later as a dedicated
`composite_algorithm_version` field.

This is the correct call, and the reason is asymmetry:

- **Adding a field later is safe** — an additive, non-breaking change.
- **Back-filling a version later is impossible** — you cannot retroactively determine
  which composite version produced a historical score.

So the *recording* must start now and the *exposing* may wait. The two are independent.

---

## 5. Consequences

### 5.1 What must be recorded for a score to be replayable

The version string alone is **insufficient**, because the policy redistributes weights
over available components — the effective weights differ from the nominal ones. To
replay a historical composite, persist all of:

1. `composite_algorithm_version` — structure version
2. `configuration_version` — `config-v{n}`, pinning the numeric values
3. `algorithm_version` — the Skill Match version that supplied the component
4. Each component's own version (the three Laravel components already declare
   `*-v1` in `config/readiness.php`)
5. **Which components were available**, i.e. `excluded_components`
6. **The effective weights actually applied** after redistribution

Items 5 and 6 matter most: they are what make the difference between "this score was
produced under `composite-readiness-v1`" (a claim) and "this score is reproducible"
(a fact). `is_provisional` is already stored, and equals
`excluded_components !== []` — so it covers item 5's *existence*, but not the
identity of the excluded set unless that is stored too.

### 5.2 `is_provisional` is an outcome, not a policy

Worth stating precisely in the docs, because it is easy to conflate: the **policy** is
"when a score becomes provisional" (any component unavailable); `is_provisional` is
the **result** of applying it. The policy belongs to `composite-readiness-v1`; the
boolean belongs to the row.

### 5.2b 🔴 VERIFIED — the Laravel `expected_fields` validates fields Laravel itself computes

Found while checking whether `critical_skill_gap_count` is Laravel-side, as stated in
the team's message of 2026-09-24.

Two places disagree about who **produces** these two values:

| Where | What it implies | Line |
|---|---|---|
| `ReadinessService.php:276–288` | Laravel **loops `skill_results`** and builds `criticalSkillNames` itself | computes it |
| `ReadinessService.php:410` | persists Laravel's own `criticalSkillNames` into the snapshot | stores it |
| `IntelligenceResponseValidator.php:221–222` | `critical_skill_gap_count` / `critical_skill_names` are in **`expected_fields`** — i.e. asserted to come **from FastAPI** | expects it from FastAPI |
| `IntelligenceResponseValidator.php:285–299` | rejects the response if they are missing or inconsistent | enforces it |
| `ReadinessResultResource.php:29–30` | reads them from `snapshot.fastapi_result`, **not** from Laravel's row | serves the FastAPI copy |

**So the API serves the *Skill Match service's* copy of these values, while the
composite math and the persisted snapshot use *Laravel's* copy.** These are computed
independently by two services from the same `skill_results`.

That is the definition of double-ownership, and it is silent: if the two implementations
ever disagree — a different `match_ratio` rounding, a different `is_critical`
interpretation, a different threshold — the learner's **displayed** critical-skill
warning would not match the **cap decision** that actually changed their score.

The team's message states these are "بتتحسب محليًا بـ Laravel". The code confirms
Laravel computes them. But the response serving path contradicts that. **One of the two
must be made authoritative and the other removed**, and the `expected_fields` contract
either shrinks or stays, accordingly.

Note this is *not* the same defect as **V1** (the duplicate cap), though both are
symptoms of one cause: the Skill Match service was built as a **readiness calculator**
and is being used as a **component calculator**. Every field that survives from the
former role is a place the two can silently diverge.

### 5.3 🔴 VERIFIED — three live violations of the contract as agreed

Investigated against the actual source on 2026-09-24. All three are in
`E:/SkillSpan/data-science-service`, and all three contradict "Laravel owns the
composite".

#### V1 — FastAPI still computes a composite score (the exact thing §2 forbids)

`app/services/skill_gap_service.py`:

```python
ALGORITHM_VERSION = "skill-gap-v1"
CRITICAL_SKILL_MAJOR_GAP_THRESHOLD = 2.0
CRITICAL_SKILL_READINESS_CAP = 60.0        # ← line 6
```

and at lines 73–82 it applies a critical-skill cap and returns a readiness score.
Laravel also applies a cap — `readiness.critical_skill.cap = 69.0`
(`config/readiness.php`). So **two caps exist, with different values (60.0 vs 69.0),
in two services**, and it is not documented which one wins. This is precisely the
double-ownership the agreement was meant to end. Note the component's own declared
version in `config/readiness.php` (`practical-experience-v1`,
`assessment-reliability-v1`, `profile-completeness-v1`) — nothing declares
`skill-gap-v1` as *the* composite.

#### V2 — the endpoint is `/api/v1/skill-gap`, not `/api/v1/skill-match`

`app/api/routes/skill_gap.py:8` — `prefix="/api/v1/skill-gap"`. The agreed contract
names `POST /api/v1/skill-match`. Either the agreement or the route must change; as
it stands, a caller following the contract gets a 404.

#### V3 — the version emitted is `skill-gap-v1`, and `skill-match-v1` is never stored

`config/services.php:78` sets the fallback to
`DATA_SCIENCE_ALGORITHM_VERSION ?? 'skill-gap-v1'`, and the service emits
`skill-gap-v1`. **`skill-match-v1` appears nowhere in either service.** Meanwhile
`ReadinessService.php:145` passes `'skill-match-v1'` as the fallback default when
building the pending decision snapshot — but the value actually persisted comes from
`$result['algorithm_version']` (line 305), i.e. `skill-gap-v1`.

So: the audit intent says one thing, the stored value says another, and the name the
team just agreed (`skill-match-v1`) is not produced by anything.

#### What this changes about §5.3's original ask

The original verification item was "confirm the endpoint emits its version". It does —
but the answers surfaced two further problems (V1, V2). **This is no longer a
one-line check; it is three decisions.** Recommend resolving in this order:

1. Delete the cap and the readiness-score computation from the FastAPI service, or
   explicitly document that its value is advisory and unused by Laravel. (**V1** —
   highest risk, because a silent second cap can change a learner's score.)
2. Reconcile the route name against the contract. (**V2**)
3. Emit the agreed identifier (`skill-match-v1`) or amend the agreement. (**V3**)

#### Note on the fallback pattern

`config/services.php:78` calls the version a "compatibility fallback ... used only when
the service response carries no algorithm_version metadata". Because the field is
`required` in the response schema (`app/schemas/skill_gap.py:143`), the fallback is
currently unreachable — which means a *real* regression (the field disappearing) would
silently start writing a constant rather than failing. Worth making the fallback loud
(log a warning when it is used) or removing it now that the field is guaranteed.

---

## 6. Out of scope

- The FastAPI RAG work and its missing corpus (tracked separately).
- The §12.5 learner-data decision (tracked separately).
- Band threshold values — they live in `config-v{n}`, not here.

---

## 7. Sign-off checklist

- [x] §3.2 naming split accepted — `composite-readiness-v1` agreed by both teams
- [x] `readiness-v1` alias removed from `config/readiness.php`
- [x] `composite_algorithm_version` column added to `readiness_results`
- [ ] Historical `configuration_version` rows reconciled — forward-only (see §0)
- [x] §5.1 effective-weights + excluded-set persistence implemented
- [x] **V1** — no change required: `skill-gap-v1` is independent and is never called by the Composite
- [x] **V2** — withdrawn; the route already matches the contract
- [x] **V3** — withdrawn; `skill-match-v1` is already emitted and stored
- [x] **§5.2b** — Laravel is the single owner of `critical_skill_gap_count` / `critical_skill_names`
- [x] `config/services.php` fallback documented as a hint, never the persisted value

---

## 8. Addendum — the `v1` semantics question, answered

The team asked only one open question: *what should the composite version be called?*
That question is now answered (`composite-readiness-v1`, agreed by both sides). This
addendum records what the name must be defined to **mean**, because the answer decides
whether it stays useful.

**Recommendation: `composite-readiness-v1` must version the *structure* only, and the
numeric values must stay in `configuration_version`.**

Rationale, stated as the test the team should apply:

> **The version must change if and only if a previously-computed score would now compute
> differently *for reasons other than a configuration change*.**

Under the team's proposed definition — where `composite-readiness-v1` *includes* the
weights, the cap value, and the band thresholds — tuning a single weight from `0.65` to
`0.60` bumps the composite to `v2`. But `v2` then means "one number moved", not "the
composite works differently", and the identifier stops telling a reader anything about
structure. The name would have exactly one useful lifecycle: `v1`, then forever `v2`.

Under the split definition:

| Change | `composite-readiness-*` | `config-*` |
|---|---|---|
| Tune a weight (`0.65 → 0.60`) | `v1` (unchanged) | `v{n+1}` |
| Move the cap (`69.0 → 65.0`) | `v1` (unchanged) | `v{n+1}` |
| Shift a band boundary | `v1` (unchanged) | `v{n+1}` |
| Add a 5th component | **`v2`** | — |
| Change how unavailable components are excluded / weights redistributed | **`v2`** | — |
| Change the critical-skill *rule* (not its threshold) | **`v2`** | — |

Both identifiers are stored, so nothing is lost — a historical row still carries
`composite-readiness-v1` + `config-v3` and replays exactly.

**If the team prefers the simpler proposal as written** (everything inside
`composite-readiness-v1`), that is defensible and requires no extra column — but then
`configuration_version` becomes redundant for composite purposes, and the two should be
explicitly merged rather than left as two half-overlapping version fields. What must be
avoided is the current state: two fields whose contents overlap and whose precedence is
undocumented.

### 8.1 Correction to the team's message

> *"لو بدكم اسم/نسخة مستقلة تحدد صيغة الدمج نفسها ... اقترحوا اسم ونعتمده سوا"*

This was accurate when written. It is now superseded: a name was proposed and adopted,
so the only remaining question is the one above — the **scope** of what the name covers,
not its spelling.

---

## 9. Implementation record — 2026-09-25

Both teams agreed the naming split, and the Laravel-side items are now in the code. No
FastAPI runtime change was required.

| File | Change |
|---|---|
| `config/readiness.php` | Removed the dead `configuration_version => 'readiness-v1'` alias. Added `composite_algorithm_version => 'composite-readiness-v1'` and a `skill_match.algorithm_version` component hint. |
| `database/migrations/2026_09_25_000000_add_composite_algorithm_version_to_readiness_results_table.php` | **New.** Idempotent, nullable `composite_algorithm_version` column on `readiness_results`. |
| `app/Models/ReadinessResult.php` | `composite_algorithm_version` added to `$fillable`. |
| `app/Services/Readiness/ReadinessService.php` | Resolves the composite version from config; persists it on the row and on both the pending and succeeded decision snapshots. Records §5.1 replay data: `formula.effective_weights` (post-redistribution), `missing_component_policy.excluded_components`, and `component_versions` per component. The pending-snapshot `algorithm_version` placeholder now comes from `readiness.skill_match.algorithm_version` instead of the shared `services.data_science.algorithm_version` hint, which defaults to `skill-gap-v1` — a different flow's algorithm. |
| `config/services.php` | The `algorithm_version` key is documented as a payload hint / pending placeholder only, never the persisted value. |
| `tests/Feature/Readiness/ReadinessTest.php` | +2 tests: the composite version is recorded and distinct from the other two identifiers; a provisional result records the effective weights, the excluded set and every component version. |

### What the three stored identifiers now mean

For a single composite result row:

```
composite_algorithm_version = composite-readiness-v1   # structure  (ADR-001 §3.2)
configuration_version       = config-v{n}              # numbers    (AlgorithmConfiguration row)
algorithm_version           = skill-match-v1           # FastAPI's Skill Match component
```

Tuning a weight, the cap or a band boundary bumps `config-*` only. Adding a component or
changing the exclusion/redistribution policy bumps `composite-readiness-*`.

### Deliberately NOT done

- **No public response field.** `composite_algorithm_version` is persisted in audit
  metadata only (§4). Exposing it is additive and can happen at any time; recording it
  could not wait.
- **No back-fill of historical `configuration_version` rows.** They keep their original
  value; the split applies to rows written from now on.
- **No change to `skill-gap-v1`.** It remains a published, independently versioned
  algorithm. Laravel's Composite never calls it.

### Verification

`php artisan test` — full suite green, including the two new regression tests.
