# Intelligence Handoff — Verification of Three Analyst Findings

**Date:** 2026-10-12
**Scope:** the three items raised against the Data Science handoff report, before
completing the learner Pilot matching validation.
**Branch:** `feature/authentication` (the branch `back-end-zdip` deploys — a push ships to production).
**Backend only.** No frontend, no Data Science service change.

**Verdict summary**

| # | Finding | Verdict | Action |
|---|---|---|---|
| 1 | `IntelligenceClient` ignores the configured 60 s timeout | **CONFIRMED** — the config branch was unreachable dead code | Fixed |
| 2 | `interests` never reach the matching payload | **CONFIRMED** — and *both* ends were broken | Fixed |
| 3 | Queued recalculation may never execute | **CONFIRMED** — plus the `afterCommit` guarantee was also not working | Fixed + 1 human check |

---

## 1. `IntelligenceClient` timeout — CONFIRMED

### What was true

`config/services.php:53` was already correct:

```php
'timeout' => (int) env('DATA_SCIENCE_SERVICE_TIMEOUT', 60),
```

with a comment explaining that the Data Science service idles out after ~15 minutes on Render's
free tier and a cold start measures **24.7–33.8 s**, so a lower timeout produces a false
`INTELLIGENCE_UNAVAILABLE` on the first call after every idle period.

But `app/Services/Intelligence/IntelligenceClient.php` never read it:

```php
public function __construct(
    private readonly ?string $serviceToken = null,
    private readonly ?string $baseUrl = null,
    int $timeout = 10,                       // <-- the literal default won
) {
    $this->timeout = $timeout > 0
        ? $timeout
        : (int) config('services.data_science.timeout', 10);   // <-- unreachable
}
```

Because `10 > 0` is always true, the `config(...)` branch was **dead code**. Every
`new IntelligenceClient` call therefore used **10 s**, not the configured 60 s.

`app/Services/Projects/ProjectMatchingService.php:45` is exactly such a call:

```php
$this->client = $client ?? new IntelligenceClient;   // 10 s
```

The same applied to the DI path: `IntelligenceService` receives `IntelligenceClient` from the
container, and the container supplies the declared default — also 10 s. So **every** intelligence
call in the app (skill-gap, roadmap, project matching) ran on a 10 s budget, not just project
matching.

### One extra defect the finding did not mention

`.env.example:141` shipped `DATA_SCIENCE_SERVICE_TIMEOUT=10` — the same trap, and it contradicts
the documented 60 s default in `config/services.php`. Anyone provisioning an environment from the
example would have re-created the false-outage bug.

### Fix

- `IntelligenceClient::__construct()` now takes `?int $timeout = null` and resolves
  `($timeout !== null && $timeout > 0) ? $timeout : (int) config('services.data_science.timeout', 60)`.
  An explicit positive override still wins; the fallback matches `config/services.php`.
- `.env.example` now sets `60` with the cold-start rationale inline.

### Effect

A cold start that measures 24.7–33.8 s now completes instead of failing at 10 s. This is a real
production behaviour change, not a cosmetic one.

### Tests

`tests/Feature/Intelligence/IntelligenceClientTimeoutTest.php` — 6 tests: default client reads the
config, follows a reconfigured value, works through the container, an explicit timeout still wins,
a non-positive timeout falls back, and a drift guard on the 60 s literal in `config/services.php`.

---

## 2. Project Matching `interests` — CONFIRMED (both ends were broken)

### What was true

The finding said `ProjectMatchingSnapshotService` does not copy `StudentProfile::interests`. That is
correct — but there was a **second** break on the consuming side, so fixing only the snapshot would
have changed nothing.

`app/Services/Projects/ProjectMatchingPayloadBuilder.php:70` read the key from the **top level** of
the snapshot:

```php
if (isset($data['interests']) && $data['interests'] !== null) {
    $context['interests'] = $data['interests'];      // $data['interests'], not $learner['interests']
}
```

while `ProjectMatchingSnapshotService::buildSnapshot()` wrote nothing named `interests` — neither at
the top level nor inside the learner block:

```php
'learner' => [
    'user_id' => (int) $learner->id,
    'student_profile_id' => (int) $studentProfile->id,
    'availability' => $studentProfile->availability,
    'preferred_work_type' => $studentProfile->preferred_work_type,
    // no 'interests'
],
```

So `interest_match` could never score the learner's real interests. The data itself was always
present and healthy: `student_profiles.interests` is a `json` column, `StudentProfile` casts it to
`array` and marks it fillable, and `StoreProfileRequest` / `UpdateProfileRequest` validate
`interests` as `array` of `string|max:100`.

### Fix

- The snapshot's `learner` block now carries `'interests' => $studentProfile->interests`, alongside
  the other learner-owned attributes (`availability`, `preferred_work_type`).
- The builder now reads `$learner['interests']` instead of the top-level key.

The two ends are consistent again, and the payload key emitted to FastAPI is unchanged
(`learner.interests`), so **the FastAPI contract is untouched**.

### Backwards compatibility

The `isset()` guard is retained, so snapshots already persisted in production without an `interests`
key still build a valid payload — the key is simply omitted. No migration, no backfill.

### Tests

`tests/Feature/Projects/ProjectMatchingPayloadBuilderTest.php` — 2 new tests:
`test_learner_interests_are_included_when_present` asserts the snapshot **and** the payload both
carry `['web development', 'data science']`; `test_learner_interests_are_omitted_when_absent` pins
the absent case.

---

## 3. Queue execution — CONFIRMED, and `afterCommit` was also not working

This item had two independent defects. The reported one is real; the second was hiding behind it.

### 3a. The `afterCommit` guarantee was silently absent

`app/Listeners/RecalculateIntelligence.php` declared only `ShouldQueue` and expressed after-commit
intent as a **method**:

```php
class RecalculateIntelligence implements ShouldQueue
{
    public function afterCommit(): bool { return true; }   // <-- never read by Laravel
```

Laravel does not look for a method. In
`vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php`:

```php
// line 711-713
if ($listener instanceof ShouldQueueAfterCommit) {
    $job->afterCommit = true;
} else {
    $job->afterCommit = property_exists($listener, 'afterCommit') ? $listener->afterCommit : null;
}
```

`property_exists()` is **false** for a method, and the class is not
`ShouldHandleEventsAfterCommit` either, so line 602
(`($listener->afterCommit ?? null) || $listener instanceof ShouldHandleEventsAfterCommit`) also
resolved to null. **The flag was null in both paths** — the job was not deferred to after-commit at
all, despite the class docblock promising it.

**Fix:** `implements ShouldQueue, ShouldQueueAfterCommit` (importing
`Illuminate\Contracts\Queue\ShouldQueueAfterCommit`), and the dead method was deleted so it cannot
be reintroduced. A comment now records why.

### 3b. `QUEUE_CONNECTION` — the reported risk

The relevant facts, all verifiable in the repo:

| Where | Value |
|---|---|
| `.env` (local, git-ignored) | `QUEUE_CONNECTION=sync` |
| `.env.example` | was `database` → now `sync` |
| `config/queue.php:16` | was `env('QUEUE_CONNECTION', 'database')` → now `'sync'` |
| `Dockerfile` `CMD` | `migrate --force` → `config:cache` → background seeder → `serve` |
| `queue:work` anywhere in the image | **no** |
| `render.yaml` | **absent** — the Dockerfile is the deploy mechanism |

Two consequences:

1. `.env` is **git-ignored**, so Render's container has no `.env` file. The effective value is
   whatever the **dashboard** sets; if the dashboard defines nothing, the `config/queue.php`
   fallback applies. Before this change that fallback was `database`, which with no worker means
   every queued job is written to the `jobs` table and **never processed** — a silent failure.
2. The Dockerfile runs `config:cache` on every boot, so the value is **baked at container start**.
   Changing a dashboard variable has no effect until a **Manual Deploy**.

**Fix:** the shipped fallback is now `sync`. `RecalculateIntelligence` is the **only** class in
`app/` implementing `ShouldQueue`, so running it inline is cheap and correct, and the correct state
now holds even if the dashboard defines nothing. `.env.example` was aligned for the same reason.

This also matches the team's established answer to the same problem: commits `346c5c3` /
`0f9b659` ("send auth emails synchronously instead of queueing them **without a worker**") and
`c533f86` ("make the queued intelligence recalculation reachable") all point the same way.

### ⚠️ The one thing that still needs a human

The **Render dashboard value cannot be read from the repository**, so it remains unconfirmed:

> Render → the `back-end-zdip` service → **Environment** → `QUEUE_CONNECTION`.
> If it says `database`, the env var **wins over the new fallback** and the jobs still never run.
> Change it to `sync`, then hit **Manual Deploy** (because of `config:cache`).
> If it is already `sync`, or absent, nothing further is needed.

Either state is now safe *except* an explicit `database` in the dashboard.

### Tests

`tests/Feature/Intelligence/QueueConfigurationTest.php` — 5 tests: the listener is queued, defers
through the `ShouldQueueAfterCommit` interface, has no dead `afterCommit()` method or property, the
**dispatcher actually stamps `afterCommit === true`** on the pushed `CallQueuedListener`, and a
drift guard on the `sync` fallback.

---

## Files changed

| File | Change |
|---|---|
| `app/Services/Intelligence/IntelligenceClient.php` | timeout now resolves from config; explicit override still wins |
| `app/Services/Projects/ProjectMatchingSnapshotService.php` | snapshot `learner` block carries `interests` |
| `app/Services/Projects/ProjectMatchingPayloadBuilder.php` | reads `$learner['interests']` |
| `app/Listeners/RecalculateIntelligence.php` | `implements ShouldQueue, ShouldQueueAfterCommit`; dead method removed |
| `config/queue.php` | fallback `database` → `sync`, with rationale |
| `.env.example` | `QUEUE_CONNECTION=sync`; `DATA_SCIENCE_SERVICE_TIMEOUT=60` |
| `tests/Feature/Intelligence/IntelligenceClientTimeoutTest.php` | **new** — 6 tests |
| `tests/Feature/Intelligence/QueueConfigurationTest.php` | **new** — 5 tests |
| `tests/Feature/Projects/ProjectMatchingPayloadBuilderTest.php` | +2 interests tests |

No route, no middleware, no migration, no OpenAPI description and no response shape changed, so
there is **no public deploy marker** for this work — that is expected, not a gap. Verification is
the test suite plus the read-only production probes below.

## Verification

- Targeted: 37 passed / 83 assertions across the four affected test files.
- Full suite: **1593 passed / 10883 assertions** (baseline before this work was 1580 / 10866 — the
  delta is exactly the 13 tests added here).
- `vendor/bin/pint --test`: clean on all 8 touched files.
- Production (read-only, guest requests only): `/docs` → 200, `/api/v1/projects` → 401 with
  `Accept: application/json`.

## Status

All three findings are resolved in code. The only open item is the human check of
`QUEUE_CONNECTION` in the Render dashboard (§3b). Once that reads `sync` — or is absent — the
learner Pilot matching validation can proceed.
