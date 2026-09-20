# CI is green again — there were two problems, not one

You pasted a Pint failure. It was real, but it was also **hiding a second failure**:
`ci.yml` runs Pint *before* PHPUnit, so the red light stopped at the style check and the
5 broken tests behind it never showed up. Fixing only Pint would have moved the red light,
not removed it.

## 1. The Pint failure you saw

`bootstrap/app.php` used `__DIR__ . '/../routes/web.php'` — with spaces around the `.`.
There is no `pint.json` in the repo, so Pint applies its default **`laravel`** preset, whose
`concat_space` rule is `none`: it wants `__DIR__.'/...'`.

> The previous attempt (`c91c75f`, *"fixed:extra space in app.php"*) didn't fix it — it only
> inserted a stray `/**s */` comment between `)` and `->withMiddleware(`. That comment is
> removed too.

## 2. The 5 test failures it was hiding

All five failed identically with **502 instead of 201**. Cause: the test fixture
`successResponse()` still returned the **old `/skill-gap` shape**, while commit `d87040e`
migrated the integration to **`/skill-match v1`**. The tests were never updated to match.

The v1 contract is much stricter — it now requires the normalized-weight breakdown and
`skill_match_score`, and it **cross-checks every skill result against the payload Laravel
actually sent**, re-deriving `achieved_level`, `match_ratio` and `status` itself. So the
fixture can't invent numbers any more; it has to mirror the real thing.

I rewrote the fixture to build the v1 contract and to **derive** `skill_match_score` from
the same levels and weights the validator checks, rather than hardcoding it — a hardcoded
number would silently drift out of agreement with them. For the scenario data this works
out to `79.38`, which is exactly what your existing assertions (`75.6`, `82.0`, `74.0`)
were written against.

**One correction worth knowing:** the test comment claimed the `skill_match` component comes
from a *local* `SkillMatchService` calculation. It doesn't — `ReadinessService::calculate()`
reads `$result['skill_match_score']` from FastAPI. `SkillMatchService` exists but is only
used by `SkillMatchController`. Comment fixed.

**Side finding:** `test_invalid_fastapi_skill_result_is_rejected` was *passing for the wrong
reason* — with the old fixture it tripped the missing-key check, not the unknown-`skill_id`
check it was written for. It now tests what it intends.

## Verification (both CI steps, run locally)

| Step | Result |
| --- | --- |
| `vendor/bin/pint --test` | **PASS** — 274 files |
| `php artisan test` | **235 passed** (886 assertions) |

Committed as `1ef1651` and pushed to `origin/feature/authentication`, so the CI run on
GitHub should go green.

## One thing to be aware of

The **`C:`** checkout (`C:\Users\HP\Documents\GitHub\SkillSpan\...`) — which is what your IDE
has open — is still at `d025485`, far behind. All of today's work, and the previous
proof-file fix, are in the **`E:`** checkout. C: also still carries an uncommitted edit to
`Api/EvidenceController.php`. Worth catching C: up (and deciding on that edit) so the two
don't drift further apart.
