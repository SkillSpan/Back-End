# SkillSpan Back-End — notes

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

## Where the detail lives (project root)

`US-MATCH-02_APPLICATION_WORKFLOW_REPORT.md` · `API_ENDPOINTS_REFERENCE.md` +
`SkillSpan_API_Collection.postman_collection.json` · `FRONTEND_API_HANDOFF.md` (generated contract;
`FRONTEND_API_HANDOFF_REVIEW.md` audits it) · `DISPOSABLE_EMAIL_PROTECTION.md` ·
`AUTH_REGISTRATION_FLOWS.md` · `PROJECTS_FEATURE_ANALYSIS.md` · `MIGRATIONS_TABLE_INCIDENT.md` ·
`PROOF_FILES_STORAGE_FIX.md` · `ADMIN_PANEL_LOGIN_SUMMARY.md` · `EVIDENCE_ADD_API.md` + `.pdf`.

## Who owns what

`git log --author` is useless — sessions commit as `Merge Bot <merge@local>`. Attribute by file
creation (`git log --diff-filter=A --name-only`). **Ahmed Saqallah (`AhmedSaqllah`) = the whole
Authentication module** (routes/api.php, AuthController, AuthService, `Http/Requests/Auth/*`,
SetupController, both OrganizationControllers, the approval/OTP/reset notifications + mail, auth
migrations). **Qasem AL Dam** = matching, baseline, intelligence, profile, reference, discovery.

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
  they drop **silently**. Three: `ProfessionalProfile::verification_status` (gates
  `EnsureUserIsMentor`), `Organization::verification_status` (gates **login**), `Application::active_key`.
- **A validated field with no column and no `$fillable` entry is dropped silently too.** A `verify()`
  rule proves the input is *acceptable*, never that anything *stores* it. Fourth instance found:
  `EvidenceController` validated `description` while `skill_evidences` had no such column — fixed
  2026-09-30 by `2026_09_30_000002_add_description_to_skill_evidences_table`. When adding validation
  for a new input, grep the model's `$fillable` and the migration in the same pass.
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

A local MariaDB is available — XAMPP `127.0.0.1:3306`, user `root`, empty password, client
`/c/xampp/mysql/bin/mysql.exe`. Use a **throwaway** DB; **never touch** the other databases on that
server. A failed migration auto-commits its DDL and leaves the migration unrecorded — the idempotent
guards are what make the next run safe.

**Pasting a long message can clobber a project file.** After any large paste, check `git status`.
`git show HEAD:<path> > <path>` restores without touching refs.

**When a fix "isn't working" on a deployed environment, check the branch topology before the code.**
`origin/main` is what deploys; `git fetch` then `git rev-list --left-right --count origin/<b>...HEAD`.
See `REFERENCE_data_science_and_deploy.md`.
