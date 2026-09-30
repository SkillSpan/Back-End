# SkillSpan Back-End — notes

<<<<<<< HEAD
Laravel 12 / PHP 8.2, Sanctum. Checkout **`E:\SkillSpan\Back-End-feature-authentication`**, branch
`feature/authentication`. Test baseline **935 passed / 3311 assertions** (`php artisan test`, SQLite
`:memory:`). Detailed references live beside this file — load them on demand:

| Load this | When |
|---|---|
| `REFERENCE_admin_and_lifecycle.md` | admin review panel, org approval, project lifecycle/ownership |
| `REFERENCE_data_science_and_deploy.md` | readiness/intelligence/baseline, Render deploys, branch topology |
| `REFERENCE_us_match_02_rules.md` | US-MATCH-02 work — settled decisions, do not re-litigate |

⚠️ `.env` here is **local**: `APP_ENV=local`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, **but
`DB_DATABASE` is the production DB name**. Never run a query or destructive command without first
`php artisan config:show database.default`. Exporting `DB_CONNECTION=sqlite` beats `.env` (Dotenv
`safeLoad` is immutable), and `phpunit.xml` sets `<env>` **without `force`**, so exported vars also
repoint the suite. **SQLite path must be a Windows path** (`E:/…/x.sqlite`) — a `/e/…` MSYS path dies
with `SQLiteDatabaseDoesNotExistException`.
=======
Laravel 12 / PHP 8.2, Sanctum. Checkout **`D:\newnn\Back-End`**. Branches: `feature/authentication`,
`feature/backend-intelligence-roadmap-fixes`. Baseline **959 passed / 3441 assertions**
(`php artisan test`, SQLite `:memory:`, ~3 min). Pint clean (422 files).

⚠️ `.env` is **local**: `APP_ENV=local`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`,
`DB_DATABASE=skillspan_integration_test`, `MAIL_MAILER=log`. Other checkouts/deploys differ — never
assume. Exporting `DB_CONNECTION=sqlite` beats `.env` (Dotenv `safeLoad` is immutable); `phpunit.xml`
sets `<env>` **without `force`**, so exported vars also repoint the suite at MySQL. Local MariaDB
(XAMPP `127.0.0.1:3306`, root, no password, `/c/xampp/mysql/bin/mysql.exe`) is **often not running** —
check before promising a MySQL verification. `Dockerfile:65` runs `migrate --force` on boot, so
migrations land before new code serves.
>>>>>>> 5dfd98696b51fb3f0f6dbfbc88d2244960b36712

Project-root docs hold the detail: `API_ENDPOINTS_REFERENCE.md` +
`SkillSpan_API_Collection.postman_collection.json` · `FRONTEND_API_HANDOFF.md` (generated contract;
`..._REVIEW.md` audits it) · `US-MATCH-02_APPLICATION_WORKFLOW_REPORT.md` ·
`AUTH_REGISTRATION_FLOWS.md` · `PROJECTS_FEATURE_ANALYSIS.md` · `MIGRATIONS_TABLE_INCIDENT.md` ·
<<<<<<< HEAD
`PROOF_FILES_STORAGE_FIX.md` · `ADMIN_PANEL_LOGIN_SUMMARY.md` · `EVIDENCE_ADD_API.md` + `.pdf`.

## Who owns what

`git log --author` is useless — sessions commit as `Merge Bot <merge@local>`. Attribute by file
creation (`git log --diff-filter=A --name-only`). **Ahmed Saqallah (`AhmedSaqllah`) = the whole
Authentication module** (routes/api.php, AuthController, AuthService, `Http/Requests/Auth/*`,
SetupController, both OrganizationControllers, the approval/OTP/reset notifications + mail, auth
migrations). **Qasem AL Dam** = matching, baseline, intelligence, profile, reference, discovery.
=======
`PROOF_FILES_STORAGE_FIX.md` · `ADMIN_PANEL_LOGIN_SUMMARY.md` · `DISPOSABLE_EMAIL_PROTECTION.md`.

**Ownership**: `git log --author` is useless (sessions commit as `Merge Bot <merge@local>`); attribute
by file creation (`git log --diff-filter=A --name-only`). Ahmed Saqallah (`AhmedSaqllah`) = the whole
Authentication module (routes/api.php, AuthController/AuthService, `Http/Requests/Auth/*`,
SetupController, both OrganizationControllers, approval/OTP/reset notifications + mail, auth
migrations). Qasem AL Dam = matching, baseline, intelligence, profile, reference, discovery.
>>>>>>> 5dfd98696b51fb3f0f6dbfbc88d2244960b36712

## Architecture

- Sanctum. `User.status` active/suspended/pending/deleted + soft deletes. Roles via `user_role` pivot
  (+optional `organization_id`): learner, company_admin, university_admin, admin.
  `Permission`/`role_permission` seeded but **NOT wired** — all gating is role-slug middleware:
  `role`, `admin`, `mentor`, `organization.approved`, `account.active`.
- Controller → Service → FormRequest → JsonResource. Exceptions carry `status`/`codeName`/`details`;
  X-Request-ID correlation.
- **No "mentor" role — mentor identity IS `ProfessionalProfile`** (type=mentor + `verification_status`).
- Notifications: `NotificationService` is the only *in-app* dispatch gateway (never
  `Notification::create()`); `notification_preferences` is opt-out (absent row = **enabled**).
  Mail-channel review notices go through `Notifiable::notify()` directly.
- Audit: `audit_events` via the `AuditsActions` trait. Mentor↔student: `mentor_student_connections`,
  `conversations`, `messages` (chatbot endpoint **forces** `message_type=chatbot`).

## Conventions that bite

- Migrations **additive + idempotent** (`Schema::hasColumn` guards) — SQLite cannot validate MySQL DDL.
- **A deploy dies on `migrate` when the `migrations` table is emptied.** CMD is
  `migrate --force && config:cache && (seed &) && serve`, so one failed migration fails the deploy
  (`1050 Table 'password_reset_tokens' already exists`, from `0001_01_01_000000_create_users_table.php`
  — that file creates only `password_reset_tokens` + `sessions`; `users` comes from
  `2026_01_01_000000_create_users_table.php`). **32/100 migrations have a no-op `down()`** (every
  ALTER), so `migrate:reset` deletes rows without dropping tables. Diagnose + repair:
  `storage/app/_diag_applied.php`, `storage/app/_fix_migrations_table.php`. Post-mortem:
  `MIGRATIONS_TABLE_INCIDENT.md`.
- **Never send two edits to the same file in one message** — the second silently reverts the first.
  `Write` overwrites wholesale, so it is safe.
- **Columns kept out of `$fillable` must be written explicitly** — via `create()`/`updateOrCreate()`
<<<<<<< HEAD
  they drop **silently**. Three: `ProfessionalProfile::verification_status` (gates
  `EnsureUserIsMentor`), `Organization::verification_status` (gates **login**), `Application::active_key`.
- **A validated field with no column and no `$fillable` entry is dropped silently too.** A `verify()`
  rule proves the input is *acceptable*, never that anything *stores* it. Fourth instance found:
  `EvidenceController` validated `description` while `skill_evidences` had no such column — fixed
  2026-09-30 by `2026_09_30_000002_add_description_to_skill_evidences_table`. When adding validation
  for a new input, grep the model's `$fillable` and the migration in the same pass.
=======
  they drop **silently**: `ProfessionalProfile::verification_status` (gates `EnsureUserIsMentor`),
  `Organization::verification_status` (gates **login**), `Application::active_key`.
>>>>>>> 5dfd98696b51fb3f0f6dbfbc88d2244960b36712
- **Before the FIRST write to a model, diff `$fillable` against NOT NULL columns with no DB default** —
  a miss dies with `NOT NULL constraint failed: …`, not a mass-assignment error.
- **Look users up with `User::withTrashed()` before any insert** — a soft-deleted row still owns the
  unique index on `users.email`.
- **Laravel 12**: `getJson($uri, $headers, $options)` but `postJson`/`patchJson` are `($uri, $data, $headers)`.
- **New list endpoints paginate** (`per_page` 15, clamp 100, `meta`).
- **Envelopes are NOT uniform — never assume `success` exists.** Dominant `{success, message, data,
  request_id}`, but Assistant/Intelligence/Readiness/SkillMatch return a bare JsonResource; Sanctum
  401 is `{message}`; `AuthController` validation is `{message, errors}` with no `success`.
- **Normalise payloads RECURSIVELY before hashing** — sort every map at every depth, keep lists
  ordered (`array_is_list()`), and guard-test that different payloads are still detected.
- **⚠️ `test.com` IS disposable.** The `indisposable` rule guards self-registration, so `test.com` →
  422 and silently makes "expects 422" tests pass for the wrong reason. Use `example.com` in fixtures.
- **⚠️ A declared dependency can be absent from `vendor/`.** `propaganistas/laravel-disposable-email`
  was in `composer.json` + `composer.lock` but not installed → 21 Auth failures. After any pull, run
  `composer install` **before** believing a test result.
- **Secret-gated bootstrap endpoints** (`ADMIN_SETUP_SECRET`/`MENTOR_SETUP_SECRET`) use `hash_equals()`
  and are unauthenticated by design. Probe without side effects: POST with no `email` → 422 = secret OK.
- Verify: `php -l <files> ; php artisan test ; php vendor/bin/pint --test <files>`.
- **A regression test that writes the DB column by hand does not cover the write path.** When the bug
  class is "the service drops the field", assert through the *real endpoint*, and prove the test can
  fail by temporarily breaking the mapping.

## MySQL vs SQLite — the gap that broke a deploy

SQLite cannot validate MySQL DDL. Three defects passed a green SQLite suite and failed only on MariaDB:
1. **Dropping an index an FK depends on** → `SQLSTATE 1553`. Create a plain index on the FK column
   first; in `down()` re-add the composite *before* dropping the plain one.
2. **Eager-loading a column that does not exist** (`organization:id,title` when it is `name`) — Laravel
   **skips a `belongsTo` eager load whose collected FKs are all NULL**, so fixtures never trigger it.
3. **PDO returns MySQL `decimal` as a STRING** (`"3.50"` vs SQLite's float) — cast decimals to `float`.

<<<<<<< HEAD
A local MariaDB is available — XAMPP `127.0.0.1:3306`, user `root`, empty password, client
`/c/xampp/mysql/bin/mysql.exe`. Use a **throwaway** DB; **never touch** the other databases on that
server. A failed migration auto-commits its DDL and leaves the migration unrecorded — the idempotent
guards are what make the next run safe.

**Pasting a long message can clobber a project file.** After any large paste, check `git status`.
`git show HEAD:<path> > <path>` restores without touching refs.

**When a fix "isn't working" on a deployed environment, check the branch topology before the code.**
`origin/main` is what deploys; `git fetch` then `git rev-list --left-right --count origin/<b>...HEAD`.
See `REFERENCE_data_science_and_deploy.md`.
=======
A failed migration auto-commits its DDL and leaves the migration unrecorded — the idempotent guards
are what make the next run safe. **Pasting a long message can clobber a project file**: after any
large paste check `git status`; `git show HEAD:<path> > <path>` restores without touching refs.

## Data Science — three flows, never conflate

- **Composite Readiness** `POST /readiness/calculate` → `ReadinessService` → `DataScienceClient` →
  `/skill-match` (`skill-match-v1`). Laravel owns it: local components (Practical Experience,
  Assessment Reliability, Profile Completeness) + FastAPI Skill Match, weights `0.65/0.20/0.10/0.05`,
  Critical Skill Cap `69.0`. Bands: 0-39.99 foundation_needed, 40-59.99 developing, 60-74.99
  moderate_readiness, 75-89.99 near_ready, 90-100 highly_ready.
- **Intelligence** `POST /intelligence/calculate` → `IntelligenceService` → `IntelligenceClient` →
  `/skill-gap` (`skill-gap-v1`). The same response carries the readiness block — **the deployed service
  has no standalone readiness endpoint**, so Laravel must not call one.
- **Baseline** `POST /baseline-assessments/{id}/submit` → `BaselineDataScienceClient` → `/baseline`
  (`baseline-v1.0`).

**ADR-001**: `composite_algorithm_version` = structure (`composite-readiness-v1`);
`configuration_version` = numbers (`config-v{n}`); `algorithm_version` = FastAPI's own value. Every
calculation writes a `decision_snapshots` row first (pending → succeeded/failed); history is
append-only. Never fabricate versions/configs/scores — a missing active config = explicit 422.

**Two flows write SUCCEEDED `decision_snapshots`**, discriminated by the indexed `flow` column
(`intelligence` | `readiness_legacy`) written at creation by
`DecisionSnapshotService::createPendingSnapshot()`. `GET /intelligence/latest` selects through
`DecisionSnapshot::scopeIntelligenceFlow()` (NULL counts as intelligence = pre-column rows). Readiness
never has a roadmap, so without this a readiness calculation shadowed the learner's roadmap.

**Deployment — 3 Render services, env vars PER-SERVICE**: `back-end-zdip` (Laravel/Docker),
`skillspan-intelligence` (FastAPI/uvicorn), frontend (Node/Vite). "I set the variable" → **ask WHICH
service.** DS auth gate: `service_token_middleware` guards `/api/v1/*` — 503 secret unset, 401
mismatch, **422 auth OK + bad payload (success signal)**, 502 upstream contract failure. Token 64
chars, raw (never `Bearer xxx`).

**Roadmap is feature-flagged OFF** (`DATA_SCIENCE_ROADMAP_ENABLED=false`): the deployed service exposes
no roadmap endpoint in any form, so `roadmap_path` is UNVERIFIED and inert;
`IntelligenceClient::generateRoadmap()` refuses with 503 while the flag is off. Do not enable it or
invent a contract without confirming one with Data Science.

## Admin organization review + Projects (condensed — see the dated logs / repo docs)

**Admin org review (ADM-01/03, PROF-02)**: one workflow, two doors — `Api\Admin\OrganizationController`
(list/show/approve/reject/proof-download) with `Web\AdminOrganizationController` **extending** it and
overriding only `proofFileRouteName()` (session cookie vs bearer token). Status vocabulary is
`pending | verified | rejected` — there is **no `approved` value** ("approved" in requirements means
`verified`); the column is `organizations.description`. Mail recipients are the org's own admin user
**accounts** (`organization_members` `role_in_org='admin'` **and** `status='active'`), i.e. the login
address, **not** `contact_email`. Approval **refuses an org with no `certificate` proof file** (422) by
design — symptom of that is "approval does nothing and no email". Both transitions are claimed with a
conditional `UPDATE … WHERE verification_status='pending'` so a double-click cannot send two emails.

**Projects (Pilot)**: lifecycle = `draft, submitted, changes_requested, approved, rejected, open,
selection, active, under_review, completed, cancelled, archived`; `pending_review`/`in_progress` were
renamed to `submitted`/`active` by `2026_09_30_000001_unify_projects_status_lifecycle` (⚠️ **never run
on MySQL**; ⚠️ **`closed` is still in the enum, undecided**). Lifecycle lives on the model
(`Project::STATUS_*`, `STATUSES`, `EDITABLE_STATUSES`, `TRANSITIONS`, `VERSIONED_FIELDS`,
`canTransitionTo()`, `isEditable()`), same shape as `Application`. ⚠️ **`role` middleware takes ONE
slug** — a comma list goes verbatim to `hasRole()` and always fails; multi-role rules belong in
`ProjectLifecycleService`. `projects.version` bumps only when a `VERSIONED_FIELDS` value changes —
exactly the FastAPI project-snapshot fields; matching reads **snapshots**. `company_sponsored` is
COMPANY OWNED (org must be `type='company'` + `verification_status='verified'`, owner an active org
admin); `simulation` is internal with a null `organization_id`. An unrecognised `type` throws
`PROJECT_TYPE_INVALID` (422), never falls through to simulation. The project owner cannot review their
own project (`PROJECT_REVIEW_SELF_FORBIDDEN`). ⚠️ `Project::make()` fixtures never hit the DB, so enum
changes escape them; ⚠️ `Organization::verification_status` is **not mass-assignable** — use
`Organization::forceCreate([...])` or every create test hits `PROJECT_ORGANIZATION_NOT_VERIFIED`.
>>>>>>> 5dfd98696b51fb3f0f6dbfbc88d2244960b36712
