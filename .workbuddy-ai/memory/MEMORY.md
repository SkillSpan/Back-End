# SkillSpan Back-End — project notes

Laravel 12 / PHP 8.2, Sanctum token auth, MySQL in prod (remote, Clever Cloud),
SQLite `:memory:` in tests. Branch `feature/authentication`.
Checkout: `E:\SkillSpan\Back-End-feature-authentication`.

## Three Data Science flows — never conflate them

| Flow | Entry | Laravel client | FastAPI path | Algorithm |
|---|---|---|---|---|
| **Composite Readiness** | `POST /api/v1/readiness/calculate` | `Readiness\ReadinessService` → `DataScienceClient` | `/api/v1/skill-match` | `skill-match-v1` |
| **Intelligence** | `POST /api/v1/intelligence/calculate` | `Intelligence\IntelligenceService` → `IntelligenceClient` | `/api/v1/skill-gap` | `skill-gap-v1` |
| **Baseline** | `POST /api/v1/baseline-assessments/{id}/submit` | `Baseline\BaselineDataScienceClient` | `/api/v1/baseline` | `baseline-v1.0` |

Laravel is the **sole owner of Composite Readiness**: the three local components (Practical
Experience, Assessment Reliability, Profile Completeness) plus FastAPI's Skill Match are
aggregated in Laravel with weights `0.65 / 0.20 / 0.10 / 0.05`, and Laravel applies the
Critical Skill Cap (`69.0`) and assigns the Band. FastAPI never computes the composite and
never receives a composite snapshot. Its own `skill-gap-v1` cap of `60.0` belongs to the
Intelligence flow.

Bands: `foundation_needed` 0–39.99 · `developing` 40–59.99 · `moderate_readiness` 60–74.99 ·
`near_ready` 75–89.99 · `highly_ready` 90–100.

## Versioning — three orthogonal identifiers (ADR-001)

- `composite_algorithm_version` = `composite-readiness-v1` → **structure** (which components,
  aggregation, exclusion/redistribution policy).
- `configuration_version` = `config-v{n}` (active `AlgorithmConfiguration` row) → **numbers**
  (weights, cap, band thresholds).
- `algorithm_version` = the FastAPI response's own value → **one component's algorithm**.

Rule: a weight/cap/band tweak bumps `config-*` only; a structural change bumps
`composite-readiness-*`. Decisions live in `docs/adr/` — read the ADR before changing this
area; they carry verified corrections against the deployed service.

## Deployment topology — THREE Render services, env vars are PER-SERVICE

| Service | Runtime | Owns |
|---|---|---|
| `back-end-zdip` | Laravel / Docker | `APP_KEY`, `DB_*`, `MAIL_*`, `GEMINI_API_KEY`, `DATA_SCIENCE_SERVICE_*`, `CACHE_STORE`, `INTERNAL_BASELINE_ITEMS_SECRET` |
| `skillspan-intelligence` | FastAPI / Python+uvicorn | `DATA_SCIENCE_SERVICE_TOKEN`, `BACKEND_BASE_URL`, `BASELINE_INTERNAL_SECRET`, `BASELINE_MAPPING_TIMEOUT_SECONDS` |
| frontend | Node/Vite | `VITE_API_BASE_URL` |

**When told "I set the variable", ask WHICH service.** Editing the Laravel service does
nothing for the FastAPI gate. Identify the service by runtime and start command, not by name
similarity. A pasted env list containing `APP_KEY`/`DB_HOST`/`MAIL_MAILER`/`GEMINI_API_KEY`
is the *Laravel* service — zero of those exist in the DS repo.

## The DS service's auth gate (corrected 2026-09-25)

It **does** enforce auth. `app/main.py` has a `service_token_middleware`
(`@app.middleware("http")`) guarding **every** `/api/v1/*` path: it reads
`DATA_SCIENCE_SERVICE_TOKEN` and, if blank, returns
`503 {"detail":"Data Science service authentication is not configured."}` **before routing,
before validation, before any endpoint logging**. `GET /health` sits outside `/api/v1/` and
stays `200`, so the platform health check passes while every real call fails.

**Status codes are the diagnostic.** `503` = the service's own secret is unset (fires with
*and* without an `Authorization` header, even with an empty `{}` body). `401` = token
mismatch. `422` = auth OK, payload wrong — **this is the success signal for an auth fix**.
`502` = upstream contract failure.

Our token is 64 chars, passed **raw** — never `Bearer xxx`, since `Http::withToken()`
prepends the scheme itself and their code compares only the part after the scheme.

## Baseline-flow contract details that bite

- **The FastAPI `responses` field is an ARRAY, not an object**:
  `[{"item_id":"sql-001","answer":"B"}]`, both members `string`. A PHP associative array like
  `['q1'=>'a']` satisfies `['required','array']` and then `json_encode`s to `{"q1":"a"}` — an
  object — which their schema correctly rejects with 422. `SubmitBaselineAssessmentRequest`
  now validates `['required','array','list','min:1']` plus per-entry `item_id`/`answer`.
  **This defect was always ours; their schema was right.**
- **The internal-secret VARIABLE name differs per side.** FastAPI reads
  `BASELINE_INTERNAL_SECRET`; Laravel reads `INTERNAL_BASELINE_ITEMS_SECRET`
  (`config/services.php:259`). The **header** agrees (`X-Internal-Secret`); the variable name
  does not. Unaligned ⇒ `GET /api/v1/internal/baseline-items` returns `401` and
  `/api/v1/baseline` fails *after* the auth gate. Both sides now accept either name.
  Verified live: `200` with the correct secret, `401` without.
- `BaselineDataScienceClient` maps any upstream 5xx to our
  `503 INTELLIGENCE_SERVICE_ERROR` / "The intelligence service failed to process the request."

## `CACHE_STORE` — the intermittent-503 root cause

`config/cache.php` defaulted to `database` while `.env` points at a **remote** MySQL. Every
`throttle:*` request then does read + write + lock over the network:
**742 ms per cache op vs 26 ms for `file` (×28)**. That is ~3.4 s added latency on **every**
throttled route — all of `/api/auth/*`, `/setup/create-admin`, `/api/v1/internal/baseline-items`.
It broke the DS integration silently: the mapping endpoint took 3.7–5.6 s against FastAPI's
8 s `BASELINE_MAPPING_TIMEOUT_SECONDS`, so jitter produced an **intermittent** upstream `503`
`{"detail":"Backend baseline mapping service returned an unexpected status."}`.

**Fixed at the code level** — `config/cache.php` now falls back to `file` —
**not** by `export`ing in the Dockerfile: a `config:cache`d value **wins over a later env
var** (verified), so an exported var would become unchangeable from the dashboard without a
redeploy. Same reasoning applies to `config/services.php` (`env(..., 60)`).

- `.env.example` had **no `CACHE_STORE` at all**, which is why it silently regressed. It now
  documents `CACHE_STORE=file` with the measurements.
- `DATA_SCIENCE_SERVICE_TIMEOUT` = **60** (was 20 → first call after idle could
  `503 DATA_SCIENCE_UNAVAILABLE`). A Render free-tier cold start measures 24.7–33.8 s.

## Diagnosing latency — measure before theorising

```bash
curl -s -o /dev/null -w 'conn=%{time_connect} tls=%{time_appconnect} ttfb=%{time_starttransfer}\n' <url>
```
`conn`/`tls` fast but `ttfb` slow ⇒ the server, not the wire. Then a differential against a
sibling route on the same host (unthrottled `/up` 0.34 s vs throttled mapping 3.7 s) isolates
the cause to one variable. Also: **check existing indexes before proposing a new one** — a
plausible "missing index" theory was wrong, `baseline_assessment_items` already had
`unique(['assessment_version','item_id'])`.

## Conventions that bite if ignored

- **Migrations must be additive AND idempotent** (`if (! Schema::hasColumn(...))` in both
  `up()` and `down()`). A re-run deploy already broke one migration here.
- **Every calculation writes a `decision_snapshots` row first** (status `pending`), marked
  `succeeded`/`failed` around the FastAPI call. Nothing persists unless the response passed
  validation. Historical results are append-only.
- **Never fabricate** a version, config or score — missing active config = explicit 422
  `INTELLIGENCE_CONFIGURATION_INVALID`.
- `phpunit.xml` pins SQLite `:memory:`; keep it that way so the suite can never touch a live DB.

## Reading snapshots — the two are not interchangeable

`DecisionSnapshot.snapshot` = audit (flow, payload, `fastapi_result`, version triple,
`component_versions`). `ReadinessResult.snapshot` = the replayable one (adds `components`,
`missing_component_policy`, `formula.effective_weights`, `critical_skill_rule`). Reading
`effective_weights` off the decision snapshot silently yields `null`.

`critical_skill_gap_count` counts a critical skill only when `match_ratio < minimum_match`
(`0.50`) — not "a critical skill that isn't fully met". A `partial` critical skill at ratio
0.625 is **not** a gap, so the `69.0` cap is correctly not applied. `0` is often right.

**Verify integrations live, not only with fakes.** `phpunit.xml` leaves `DATA_SCIENCE_*`
alone, so a temp test skipping `Http::fake()` really calls the deployed service. Copy seeding
from `ReadinessTest::createScenario()` (its helpers are `private`, so it can't be
subclassed). Delete the temp test afterwards.

## Git: nested refs are destroyed by every commit

In this checkout `git commit` on `refs/heads/feature/authentication` prints success then
deletes the ref **directory** `feature/`, not just the file. Recover with:

```bash
mkdir -p .git/refs/heads/feature          # FIRST — the dir itself is gone
printf '<sha>\n' > .git/refs/heads/feature/authentication
```

`git reflog` returns nothing here, but `tail .git/logs/HEAD` has the new SHA + subject.
**Do not** reach for `git cat-file --batch-all-objects` — it walks 43k vendor objects and
hangs for minutes. `git push` can silently no-op; use system git
(`/c/Program Files/Git/cmd/git.exe`), redirect to a temp log, check `$?`, then prove with
`git ls-remote`.

## Verify a change with all three

```bash
php -l <changed files> ; php artisan test ; php vendor/bin/pint --test <files>
```

Report before/after test+assertion counts. Pint's `reg.exe` blacklist block is harmless.
Current baseline: **307 passed (1137 assertions)**.
