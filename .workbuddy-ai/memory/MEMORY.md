# SkillSpan Back-End — project notes

Laravel 12 / PHP 8.2, Sanctum token auth, MySQL in prod, SQLite `:memory:` in tests.
Branch under work: `feature/authentication`. Checkout: `E:\SkillSpan\Back-End-feature-authentication`.

## Two Data Science flows — never conflate them

| Flow | Entry | Service class / client | FastAPI path | Service algorithm |
|---|---|---|---|---|
| **Composite Readiness** | `POST /api/v1/readiness/calculate` | `Readiness\ReadinessService` → `DataScienceClient` | `/api/v1/skill-match` | `skill-match-v1` |
| **Intelligence** | `POST /api/v1/intelligence/calculate` | `Intelligence\IntelligenceService` → `IntelligenceClient` | `/api/v1/skill-gap` | `skill-gap-v1` |

Laravel is the **sole owner/orchestrator of Composite Readiness**: the three local components
(Practical Experience, Assessment Reliability, Profile Completeness) plus FastAPI's Skill Match
are aggregated in Laravel with weights `0.65 / 0.20 / 0.10 / 0.05`, and Laravel applies the
Critical Skill Cap (`69.0`) and assigns the Band. FastAPI never computes the composite and never
receives a composite snapshot. Its own `skill-gap-v1` cap of `60.0` belongs to the other flow.

Bands: `foundation_needed` 0–39.99 · `developing` 40–59.99 · `moderate_readiness` 60–74.99 ·
`near_ready` 75–89.99 · `highly_ready` 90–100.

## Versioning — three orthogonal identifiers (ADR-001)

- `composite_algorithm_version` = `composite-readiness-v1` → **structure** (which components,
  aggregation, exclusion/redistribution policy).
- `configuration_version` = `config-v{n}` (active `AlgorithmConfiguration` row) → **numbers**
  (weights, cap, band thresholds).
- `algorithm_version` = the FastAPI response's own value → **one component's algorithm**.

Rule: a weight/cap/band tweak bumps `config-*` only; a structural change bumps
`composite-readiness-*`. Decisions and rationale live in `docs/adr/` — read the ADR before
changing this area; they carry verified corrections against the deployed service.

## Conventions that bite if ignored

- **Migrations must be additive AND idempotent** (`if (! Schema::hasColumn(...))` in both `up()`
  and `down()`). A re-run deploy already broke one migration in this repo.
- **Every calculation writes a `decision_snapshots` row first** (status `pending`), marked
  `succeeded`/`failed` around the FastAPI call. Nothing is persisted unless the response passed
  validation. Historical results are append-only.
- **Never fabricate** a version, config or score — missing active config = explicit 422
  `INTELLIGENCE_CONFIGURATION_INVALID`.
- `phpunit.xml` pins SQLite `:memory:`; keep it that way so the suite can never touch a live DB.
- **The deployed DS service DOES enforce auth** (corrected 2026-09-25). An earlier note here
  said it did not — that was wrong, and the belief cost hours. Their `app/main.py` has a
  `service_token_middleware` (`@app.middleware("http")`) that guards **every** `/api/v1/*` path:
  it reads `DATA_SCIENCE_SERVICE_TOKEN`, and if that is blank it returns
  `503 {"detail": "Data Science service authentication is not configured."}` **before routing,
  before validation, before any endpoint logging**. `GET /health` sits outside `/api/v1/` and
  stays `200`, so the platform health check passes while every real call fails.
  **Diagnostic tell: `503` with *and* without an `Authorization` header, and even with an empty
  `{}` body. A wrong token is `401`; a wrong payload is `422`; unconditional `503` means the
  service's own secret is unset.** Our local token **is** set (64 chars, raw — never
  `Bearer xxx`, since `withToken()` prepends the scheme itself, and their code compares only the
  part after the scheme). `APP_DEBUG=false`.
- **There are THREE Render services, and env vars are per-service.** `back-end-zdip` (Laravel:
  `APP_KEY`, `DB_*`, `MAIL_*`, `GEMINI_API_KEY`, `DATA_SCIENCE_SERVICE_*`),
  `skillspan-intelligence` (FastAPI — Python/uvicorn runtime, `DATA_SCIENCE_SERVICE_TOKEN`,
  `BACKEND_BASE_URL`, `BASELINE_INTERNAL_SECRET`, `BASELINE_MAPPING_TIMEOUT_SECONDS`), and a
  frontend (`VITE_API_BASE_URL`). **When told "I set the variable", ask WHICH service.** Editing
  the Laravel service does nothing for the FastAPI gate. Identify the service by its runtime and
  start command, not by name similarity.
- `DATA_SCIENCE_SERVICE_TIMEOUT=20` is **shorter than a Render cold start (measured 24.7–33.8 s)**,
  so the first call after idle can 503 `DATA_SCIENCE_UNAVAILABLE` even when the service is
  healthy — raise it, or ping `/health` to keep the instance warm.
- **Their internal-secret variable name differs from ours.** They read
  `BASELINE_INTERNAL_SECRET` (`baseline_mapping_client.py:33`); Laravel reads
  `INTERNAL_BASELINE_ITEMS_SECRET` (`config/services.php:259`). The **header** name agrees
  (`X-Internal-Secret`) — the **variable** name does not. Unaligned ⇒ `GET /api/v1/internal/baseline-items`
  returns 401 and `/api/v1/baseline` fails *after* the auth gate.
- **The FastAPI `responses` field is an ARRAY, not an object**:
  `[{ "item_id": "sql-001", "answer": "B" }]`, both members `string`. A PHP associative array
  like `['q1' => 'a']` satisfies `['required','array']` and then `json_encode`s to
  `{"q1":"a"}` — an object — which their schema correctly rejects with 422.
  `SubmitBaselineAssessmentRequest` now validates `['required','array','list','min:1']` plus
  per-entry `item_id`/`answer`. **The defect was always ours; their schema was right.**
- **There are TWO snapshots per calculation, and §5.1 replay data is on only one of them.**
  `DecisionSnapshot.snapshot` = audit (flow, payload, `fastapi_result`, version triple,
  `component_versions`). `ReadinessResult.snapshot` = the replayable one (adds `components`,
  `missing_component_policy`, `formula.effective_weights`, `critical_skill_rule`). Reading
  `effective_weights` off the decision snapshot silently yields `null`.
- **`critical_skill_gap_count` counts a critical skill only when `match_ratio < minimum_match`
  (`0.50`)** — not "a critical skill that isn't fully met". A `partial` critical skill at
  ratio 0.625 is **not** a gap, so the `69.0` cap is correctly not applied. `0` is often right.
- **Verify integrations live, not only with fakes.** `phpunit.xml` leaves `DATA_SCIENCE_*` alone,
  so a temporary test that skips `Http::fake()` really calls the deployed service. Seeding must be
  copied from `ReadinessTest::createScenario()` (its helpers are `private`, so it can't be
  subclassed). Delete the temp test afterwards.

## Verify a change with all three

```bash
php -l <changed files> ; php artisan test ; php vendor/bin/pint --test <files>
```

Report the before/after test+assertion counts. Pint's `reg.exe` blacklist block is harmless.
