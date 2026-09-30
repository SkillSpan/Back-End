# Final Review — Project Management vs. the latest Product decision

**Scope**: review only. Nothing was rebuilt.
**Working directory**: `D:\newnn\Back-End` · **Branch**: `feature/authentication` @ `f8c6e19`

## Verdict

The ownership and authorization behaviour **already matches** the latest Product decision.
The review found **no real ownership or authorization bug**, so nothing was redesigned or
restructured. Two things were done, both minimal and additive:

1. **A coverage gap on the update path was closed** — ownership is re-resolved when a project's
   `type` changes, and that path had only one happy-path test. 5 adversarial tests were added
   (all passed, i.e. the behaviour was already correct).
2. **One latent defect in the ownership decision point was hardened** — an unrecognised `type`
   used to fall through to the *simulation* rules (no company, creator as owner) instead of being
   refused. Unreachable through the API today; fixed because that function is the single place
   ownership is decided.

---

## Files Changed

| Path | Change | Why |
|---|---|---|
| `app/Services/Projects/ProjectLifecycleService.php` | **+16 lines**, additive only — `resolveOwnership()` now rejects an unknown `type` with `PROJECT_TYPE_INVALID` (422) instead of treating it as a simulation | Ownership hardening at the one decision point. `resolveCompanySponsoredOwnership` / `resolveSimulationOwnership` / all authorization helpers are unchanged. |
| `tests/Feature/Projects/ProjectManagementWorkflowTest.php` | **+142 lines** — 6 new tests | Close the update-path ownership coverage gap + prove the new guard. |

**No other file was touched.** `git status` shows exactly these two; `git diff --stat` is
`2 files changed, 158 insertions(+)` — purely additive, **zero deletions**.

Deliberately **not** modified: `ProjectManagementController`, `StoreProjectRequest`,
`UpdateProjectRequest`, `ProjectReviewDecisionRequest`, `Project` model, routes, the status
migration, and every test other than the workflow file.

---

## Exact Changes

### 1. Ownership decision point — unknown type refused

`ProjectLifecycleService::resolveOwnership()` previously ended with:

```php
if ($type === self::TYPE_COMPANY_SPONSORED) { ...company rules... }

return $this->resolveSimulationOwnership(...);   // ← anything else became a simulation
```

Now an explicit `simulation` check sits in between, and anything else throws
`PROJECT_TYPE_INVALID` (422, `details.type`). The FormRequest already restricts `type` to the two
allowed values, so this changes **nothing** for API callers — it protects the ownership function
itself, where defaulting to the weakest rules was the wrong default.

### 2. Six new tests

| Test | What it proves |
|---|---|
| `test_a_type_change_cannot_assign_a_representative_of_another_company` | On update, a `simulation → company_sponsored` conversion naming a representative of a *different* company is refused (`PROJECT_OWNER_NOT_ORGANIZATION_REPRESENTATIVE`) and the project stays a simulation |
| `test_a_type_change_cannot_assign_the_administrator_as_the_owner` | An administrator cannot become the owner of a company project through a type change either |
| `test_a_type_change_cannot_target_an_unapproved_company` | A pending company cannot be targeted by a conversion |
| `test_a_representative_cannot_link_a_type_change_to_another_company` | A representative's own organization wins over a supplied one on update, exactly as on create |
| `test_converting_to_simulation_preserves_the_existing_owner` | An administrator converting someone else's project to a simulation does not inherit it |
| `test_an_unknown_project_type_is_refused_by_the_ownership_resolver` | The new guard (asserted against the service directly, since the API cannot reach it) |

---

## Review findings — rule by rule

| Requirement | Status | Evidence |
|---|---|---|
| `company_sponsored` must be linked to a real organization/company | ✅ | `assertCompanySponsor()`: must exist, `type='company'`, `verification_status='verified'` |
| `owner_id` must be a company representative of the **same** organization | ✅ | `assertRepresentsOrganization()`: active admin membership (`role_in_org='admin'` + `status='active'`) |
| `company_admin` creates for their own organization | ✅ | The non-admin branch derives org **and** owner from the actor's own membership; a supplied `organization_id`/`owner_id` is ignored |
| Admin can create on behalf of a company but does **not** become owner automatically | ✅ | The admin branch requires an explicit `owner_id`; no fallback exists |
| Admin must name organization + representative | ✅ | `PROJECT_ORGANIZATION_REQUIRED` / `PROJECT_OWNER_REQUIRED` |
| Owner from a different organization is prevented | ✅ | Create **and** update paths tested |
| Admin cannot name themselves owner of a company-sponsored project | ✅ | `PROJECT_OWNER_MUST_BE_COMPANY_REPRESENTATIVE` (create and update) |
| `simulation` can be SkillSpan-internal | ✅ | `organization_id` null is allowed; the column was already nullable |
| `simulation` is not auto-linked to a company | ✅ | `resolveSimulationOwnership()` never derives the organization |
| `simulation` is not turned into `company_sponsored` by having an `organization_id` | ✅ | `type` is stored explicitly and is never inferred; a grep of `app/` confirms nothing branches on `organization_id` to decide the type |
| `simulation` keeps the correct owner | ✅ | Owner = creator on create; **preserved** on a type change |
| `closed` not deleted, not used, no decision taken | ✅ | Still an enum value only; not in `Project::STATUSES`, no `TRANSITIONS` entry, no API reference |
| No new migration | ✅ | `organization_id` + `owner_id` sufficed — **no migration created** |
| Controller consumes only validated input | ✅ | Every call site uses `$request->validated()`; no `->all()` / `->input()` |
| IDOR | ✅ | Cross-organization update, submit, open and owner assignment all refused; controller never binds a model the service does not re-authorize |

---

## Tests

| Command | Result |
|---|---|
| `php artisan test --filter=ProjectManagementWorkflowTest` | **56 passed (183 assertions)** — 0 failed, 0 skipped |
| `php artisan test` (full suite) | **934 passed (3307 assertions)** — 0 failed, 0 skipped |
| `php vendor/bin/pint` (2 touched files) | **PASS** (no changes needed) |

Baseline before this review: 50 tests in the workflow file / 928 in the suite. The delta is exactly
the 6 new tests. No pre-existing test regressed.

---

## FastAPI / Matching / Applications — unchanged

Verified with `git diff --name-only` filtered on `Matching|Application|Intelligence|DataScience`:
**no file matched — nothing in those areas was modified.**

- **Matching**: `ProjectMatchingService`, `ProjectMatchingPayloadBuilder`,
  `ProjectMatchingSnapshotService`, `ProjectMatchingRecommendationService` — untouched (0 lines).
- **FastAPI contract**: `project-matching-v1` and `project-matching-config-v1` are still declared
  exactly once, in `ProjectMatchingSnapshotService:98-99`, unchanged. Payload and response contracts
  untouched.
- **Applications**: `ApplicationController`, `ApplicationService`, `Application`, the application
  status set and its transitions — untouched.
- **Lifecycle / authentication / response format**: unchanged. No `closed`, no `pending_review`, no
  `in_progress`.

---

## Remaining blockers

**None blocking.** No unresolved ownership or authorization defect was found.

Two **observations** were noted during the review but deliberately **not** changed, because each is a
product decision rather than a bug:

1. **Ownership drift when a representative is removed from the organization.** A company
   representative who is later removed from `organization_members` keeps full control of the
   company's project — `assertMayManage()` checks the stored `owner_id`, not current membership. The
   project is not orphaned (an administrator can still manage it), and there is no ownership-transfer
   endpoint by design, so "should a removed representative lose access immediately?" is a product
   call, not a code fix.
2. **`organization_id` / `owner_id` are silently ignored on an ordinary update.** They are honoured
   only on create-on-behalf or when the `type` changes, so a client sending them with a cosmetic
   `PATCH` gets `200` with no ownership change. This is the safe behaviour (it is what prevents
   ownership re-pointing) but it is silent; returning a validation notice instead would be a contract
   change.

Minor, non-blocking, unchanged: `projects.version` is bumped whenever a `required_skills` payload is
sent even if the skills are identical; and the version bump is a separate `save()` outside the
transaction.

Also carried over unchanged: **the MySQL migration is still unverified locally** — MariaDB is not
running on this machine (`ERROR 2002`), so the `projects.status` enum `ALTER` has only ever been
verified on SQLite. Run `php artisan migrate` on a MySQL staging database before deploying.

---

## Git

```
 M app/Services/Projects/ProjectLifecycleService.php         |  16 +++
 M tests/Feature/Projects/ProjectManagementWorkflowTest.php  | 142 +++++++++++++++++++++
 2 files changed, 158 insertions(+)
```

No `reset`, `clean`, `restore`, `commit` or `push`. Nothing outside `D:\newnn\Back-End` was touched.
