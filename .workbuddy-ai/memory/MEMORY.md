# SkillSpan Back-End — notes

Laravel 12 / PHP 8.2, Sanctum. Checkout **`D:\newnn\Back-End`** (the old `E:\SkillSpan\Back-End-feature-authentication`
copy is gone). Branch `feature/authentication`. Test baseline **869 passed / 3099 assertions**
(`php artisan test`, SQLite `:memory:`).

⚠️ This checkout's `.env` is **local**: `APP_ENV=local`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`,
`DB_DATABASE=skillspan_integration_test`, `MAIL_MAILER=log`. Other checkouts/deploys differ — never
assume. Exporting `DB_CONNECTION=sqlite` beats `.env` (Dotenv `safeLoad` is immutable); `phpunit.xml`
sets `<env>` **without `force`**, so exported vars also repoint the suite at MySQL.

## Where the detail lives (project root)

`US-MATCH-02_APPLICATION_WORKFLOW_REPORT.md` · `API_ENDPOINTS_REFERENCE.md` +
`SkillSpan_API_Collection.postman_collection.json` · `FRONTEND_API_HANDOFF.md` (generated contract;
`FRONTEND_API_HANDOFF_REVIEW.md` audits it) · `DISPOSABLE_EMAIL_PROTECTION.md` ·
`AUTH_REGISTRATION_FLOWS.md` · `PROJECTS_FEATURE_ANALYSIS.md` · `MIGRATIONS_TABLE_INCIDENT.md` ·
`PROOF_FILES_STORAGE_FIX.md` · `ADMIN_PANEL_LOGIN_SUMMARY.md`. Dated logs in this folder.
(The `REFERENCE_*.md` files and `storage/app/gen_handoff.py` referenced by older notes do **not**
exist here — their surviving content is folded into this file.)

## Who owns what

`git log --author` is useless — sessions commit as `Merge Bot <merge@local>`. Attribute by file
creation (`git log --diff-filter=A --name-only`). **Ahmed Saqallah (`AhmedSaqllah`) = the whole
Authentication module** (routes/api.php, AuthController, AuthService, `Http/Requests/Auth/*`,
SetupController, both OrganizationControllers, the approval/OTP/reset notifications + mail, auth
migrations). **Qasem AL Dam** = matching, baseline, intelligence, profile, reference, discovery.
US-MATCH-02 = Ahmed's task, our code.

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
  Mail-channel review notices go through `Notifiable::notify()` directly (see below).
- Audit: `audit_events` via the `AuditsActions` trait. Mentor↔student: `mentor_student_connections`,
  `conversations`, `messages` (chatbot endpoint **forces** `message_type=chatbot`).

## Conventions that bite

- Migrations **additive + idempotent** (`Schema::hasColumn` guards) — SQLite cannot validate MySQL DDL.
- **Never send two edits to the same file in one message** — the second silently reverts the first.
  `Write` overwrites wholesale, so it is safe.
- **Columns kept out of `$fillable` must be written explicitly** — via `create()`/`updateOrCreate()`
  they drop **silently**. Three: `ProfessionalProfile::verification_status` (gates
  `EnsureUserIsMentor`), `Organization::verification_status` (gates **login**), `Application::active_key`.
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
- **⚠️ `test.com` IS disposable.** The `indisposable` rule guards the self-registration flows, so
  `test.com` → 422 and silently makes "expects 422" tests pass for the wrong reason. Use
  `example.com` in registration fixtures.
- **⚠️ A declared dependency can be absent from `vendor/`.** `propaganistas/laravel-disposable-email`
  was in `composer.json` + `composer.lock` but not installed → `validateIndisposable does not exist`
  and **21 failures** across the Auth suites. After any pull, run `composer install` **before**
  believing a test result.
- **Secret-gated bootstrap endpoints** (`ADMIN_SETUP_SECRET`/`MENTOR_SETUP_SECRET`) use `hash_equals()`
  and are unauthenticated by design. Probe without side effects: POST with no `email` → 422 = secret OK.
- Verify: `php -l <files> ; php artisan test ; php vendor/bin/pint --test <files>`.

## MySQL vs SQLite — the gap that broke a deploy

SQLite cannot validate MySQL DDL. Three defects passed a green SQLite suite and failed only on MariaDB:
1. **Dropping an index an FK depends on** → `SQLSTATE 1553`. Create a plain index on the FK column
   first; in `down()` re-add the composite *before* dropping the plain one.
2. **Eager-loading a column that does not exist** (`organization:id,title` when it is `name`) — Laravel
   **skips a `belongsTo` eager load whose collected FKs are all NULL**, so fixtures never trigger it.
3. **PDO returns MySQL `decimal` as a STRING** (`"3.50"` vs SQLite's float) — cast decimals to `float`.

A local MariaDB is available — XAMPP `127.0.0.1:3306`, user `root`, empty password, client
`/c/xampp/mysql/bin/mysql.exe`. Use a **throwaway** DB to verify migrations/schema-coupled queries;
**never touch** the other databases on that server. A failed migration auto-commits its DDL and leaves
the migration unrecorded — the idempotent guards are what make the next run safe.

**Pasting a long message can clobber a project file.** After any large paste, check `git status`.
`git show HEAD:<path> > <path>` restores without touching refs.

## Data Science — three flows, never conflate

- **Composite Readiness** `POST /readiness/calculate` → `ReadinessService` → `DataScienceClient` →
  FastAPI `/skill-match` (`skill-match-v1`). Laravel owns it: local components (Practical Experience,
  Assessment Reliability, Profile Completeness) + FastAPI Skill Match, weights `0.65/0.20/0.10/0.05`,
  Critical Skill Cap `69.0`. Bands: 0-39.99 foundation_needed, 40-59.99 developing, 60-74.99
  moderate_readiness, 75-89.99 near_ready, 90-100 highly_ready.
- **Intelligence** `POST /intelligence/calculate` → `IntelligenceClient` → `/skill-gap` (`skill-gap-v1`).
- **Baseline** `POST /baseline-assessments/{id}/submit` → `BaselineDataScienceClient` → `/baseline`
  (`baseline-v1.0`).

**ADR-001**: `composite_algorithm_version` = structure (`composite-readiness-v1`);
`configuration_version` = numbers (`config-v{n}`); `algorithm_version` = FastAPI's own value.
Every calculation writes a `decision_snapshots` row first (pending → succeeded/failed); history is
append-only. Never fabricate versions/configs/scores — a missing active config = explicit 422.

**Deployment — 3 Render services, env vars PER-SERVICE**: `back-end-zdip` (Laravel/Docker),
`skillspan-intelligence` (FastAPI/uvicorn), frontend (Node/Vite). "I set the variable" → **ask WHICH
service.** DS auth gate: `service_token_middleware` guards `/api/v1/*` — 503 secret unset, 401
mismatch, **422 auth OK + bad payload (success signal)**, 502 upstream contract failure. Token 64
chars, raw (never `Bearer xxx`).

## Admin organization review (ADM-01 / ADM-03 / PROF-02)

- **One workflow, two doors**: `Api\Admin\OrganizationController` holds list/show/approve/reject/
  proof-download; `Web\AdminOrganizationController` **extends** it, overriding only
  `proofFileRouteName()` (session cookie vs bearer token). Routes: `/api/v1/admin/organizations/*`
  and the session panel `/admin/api/organizations/*` + `/admin/organizations` (Blade).
- **Vocabulary**: status is `pending | verified | rejected` — there is no `approved` value; "approved"
  in requirements means `verified`. Column is `organizations.description` (wired end-to-end:
  `organization_description` in `RegisterOrganizationRequest` → `AuthService::createOrganization`
  → `description`).
- **Recipients = the organization's own admin user ACCOUNTS** (`organization_members` pivot
  `role_in_org='admin'` **and** `status='active'`), so mail goes to the login address, **not** to
  `contact_email`. Notifications: `OrganizationApprovedNotification` / `OrganizationRejectedNotification`
  (mail channel, views `emails.organization-approved|rejected`).
- **Approve refuses an organization with no `certificate` proof file** (422) — by design, so "verified"
  is never granted without evidence. Symptom to recognise: approval "does nothing" and no email.
- **Both transitions are claimed with a conditional `UPDATE … WHERE verification_status='pending'`**
  so a double-click/retry cannot send two emails; the loser gets the same 422 as a sequential repeat.
- A lost proof upload is reported as `proof_file.available=false`, not as a missing file.

## Project lifecycle (Pilot)

- **Official lifecycle** = `draft, submitted, changes_requested, approved, rejected, open, selection,
  active, under_review, completed, cancelled, archived`. The old enum's `pending_review`/`in_progress`
  are **renamed** (`→ submitted` / `→ active`) by `2026_09_30_000001_unify_projects_status_lifecycle`.
  ⚠️ **`closed` is still in the enum, undecided** — nothing but the declaration references it; do not
  remove or use it without a product decision.
- **Enum ALTER order matters**: MySQL coerces a value no longer in an enum to `''`, so a rename must
  run the `UPDATE`s **before** `->change()`, or every affected row is silently blanked.
- **Lifecycle lives on the model**, not in an enum class: `Project::STATUS_*`, `STATUSES`,
  `EDITABLE_STATUSES`, `TRANSITIONS`, `VERSIONED_FIELDS`, `canTransitionTo()`, `isEditable()` —
  the same shape `Application` already used.
- **`role` middleware takes ONE slug** (`EnsureUserHasRole(..., string $role)`). A comma list is passed
  verbatim to `hasRole()` and always fails. Multi-role rules therefore live in the service
  (`ProjectLifecycleService`), matching ApplicationService's owner-side precedent.
- Workflow: `ProjectLifecycleService` + `ProjectManagementController` (`POST /projects`,
  `PATCH /projects/{id}`, `…/submit|approve|request-changes|reject|open`). Editable only in
  `draft`/`changes_requested`; approval yields `approved` (never auto-open/activate); `rejected` is
  terminal; decision reasons go in the `audit_events` row (no reason column exists).
- `projects.version` is bumped only when a `VERSIONED_FIELDS` value (or required skills) changes —
  those are exactly the fields in the FastAPI project snapshot. Matching reads **snapshots**, not live
  projects, so the lifecycle change cannot move the payload contract.
- ⚠️ **Fixtures using `Project::make()` never hit the DB**, so an enum/check-constraint change will not
  be caught by them. Use persisted models when the constraint is the thing under test.
