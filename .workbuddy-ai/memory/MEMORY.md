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
- The deployed DS service does **not** enforce auth; `DATA_SCIENCE_SERVICE_TOKEN` is a
  Laravel-side-only requirement and is currently unset locally (503 before any network call).

## Verify a change with all three

```bash
php -l <changed files> ; php artisan test ; php vendor/bin/pint --test <files>
```

Report the before/after test+assertion counts. Pint's `reg.exe` blacklist block is harmless.
