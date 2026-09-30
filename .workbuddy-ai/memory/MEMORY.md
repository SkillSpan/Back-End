# SkillSpan Back-End — notes

Laravel 12 / PHP 8.2, Sanctum, MySQL prod (Clever Cloud, `APP_ENV=production`), SQLite `:memory:`
in tests. Branch `feature/authentication`. Checkout `E:\SkillSpan\Back-End-feature-authentication`.

## Where the detail lives — read the matching file first

- `US-MATCH-02_APPLICATION_WORKFLOW_REPORT.md` / `REFERENCE_us_match_02_rules.md` — all US-MATCH-02
  detail, error codes, and the counter-intuitive rules.
- `API_ENDPOINTS_REFERENCE.md` + `SkillSpan_API_Collection.postman_collection.json` — every endpoint,
  Arabic docs, variables.
- `FRONTEND_API_HANDOFF.md` — **the sendable frontend contract**: envelopes, error shapes,
  pagination, and a route table **generated** from the route dump (regenerate with
  `storage/app/gen_handoff.py` — never hand-edit it). Shipped as `SkillSpan_API_Handoff_AR.pdf` +
  `FRONTEND_API_HANDOFF.html` via `storage/app/md2pdf.py` (Markdown → RTL HTML → headless Chrome →
  pymupdf). `FRONTEND_API_HANDOFF_REVIEW.md` audits the colleague's PDF that prompted it.
- `DISPOSABLE_EMAIL_PROTECTION.md` — the `indisposable` rule, protected flows, config, `test.com` trap.
- `AUTH_REGISTRATION_FLOWS.md` — the 5 account-creation flows, create-mentor password-reset runbook,
  and the Laravel concepts behind them. Learner-facing: point the user here instead of re-explaining.
- `PROJECTS_FEATURE_ANALYSIS.md` — project tables, services, lifecycle, implemented-vs-designed gap.
- `REFERENCE_engine_gotchas.md` — MySQL-vs-SQLite DDL post-mortem, local MariaDB recipe, and the
  **git ref-deletion gotcha (read before ANY git write)**.
- `REFERENCE_data_science_and_deployment.md` — the 3 DS flows, ADR-001 versioning, the 3 Render
  services + per-service env vars, DS auth-gate codes.
- Dated `YYYY-MM-DD.md` logs in this folder.

## Who owns what (2026-09-29)

`git log --author` is useless here — every session commit is `Merge Bot <merge@local>`. Attribute by
**file creation**: `git log --diff-filter=A --name-only`.

- **The user (Ahmed Saqallah, `AhmedSaqllah`) = the whole Authentication module**: `routes/api.php`,
  `AuthController`, `AuthService`, all 8 `Http/Requests/Auth/*`, `SetupController`,
  `Admin/OrganizationController`, `OrganizationController`, the `EnsureOrganizationIsApproved` +
  `EnsureUserIsAdmin` middlewares, org/OTP/password-reset notifications + mail, `RolesSeeder`, auth
  config/migrations.
- **US-MATCH-02** (applications + recommendation feedback) — his task, our code.
- **Qasem AL Dam** — project matching (Task 10), baseline, intelligence, profile, reference,
  discovery/access hardening.

## Architecture

- Sanctum. `User.status` active/suspended/pending/deleted + soft deletes. Roles via `user_role` pivot
  (+optional `organization_id`): learner, company_admin, university_admin, admin.
  `Permission`/`role_permission` seeded but **NOT wired** — all gating is role-slug middleware:
  `role`, `admin`, `mentor`, `organization.approved`, `account.active`.
- Controller → Service → FormRequest → JsonResource. Errors = `code`+`message`+`request_id`;
  lists = `meta`. Exceptions carry `status`/`codeName`/`details`.
- **No "mentor" role exists — mentor identity IS `ProfessionalProfile`** (type=mentor +
  `verification_status`). Also reviewer/partner_representative.
- Notifications: `NotificationService` is the ONLY dispatch gateway — never `Notification::create()`.
  `notification_preferences` is opt-out (absent row = **enabled**). Audit: `audit_events` via the
  `AuditsActions` trait.
- Mentor↔student: `mentor_student_connections` (unique mentor+student+project), `conversations`,
  `messages`. The chatbot endpoint **forces** `message_type=chatbot`.

## Conventions that bite

- Migrations **additive + idempotent** (`Schema::hasColumn` guards) — SQLite cannot validate MySQL DDL.
- **Never send two edits to the same file in one message** — the second silently reverts the first.
  `Write` overwrites wholesale, so it is safe.
- **Checked-in `.env` = LIVE production MySQL.** Before `migrate`/`seed`/`serve`/`tinker` export
  `DB_CONNECTION=sqlite DB_DATABASE=<file>` — exported vars beat `.env` (Dotenv `safeLoad` is
  immutable). `phpunit.xml` sets `<env>` **without `force`**, so the same trick points the suite at MySQL.
- **Columns kept out of `$fillable` must be written explicitly** — via `create()`/`updateOrCreate()`
  they drop **silently**. Three: `ProfessionalProfile::verification_status` (gates
  `EnsureUserIsMentor`), `Organization::verification_status` (gates **login** — a pending/rejected org
  makes `AuthController` reject active members), `Application::active_key` (derived).
- **Before the FIRST write to a model, diff `$fillable` against NOT NULL columns with no DB default** —
  a miss dies with `NOT NULL constraint failed: …`, not a mass-assignment error.
- **Look users up with `User::withTrashed()` before any insert** — a soft-deleted row still owns the
  unique index on `users.email`; a scoped lookup returns null and the insert dies 500, not a clean 422.
- **Laravel 12**: `getJson($uri, $headers, $options)` but `postJson`/`patchJson` are
  `($uri, $data, $headers)`.
- **New list endpoints paginate** (`per_page` default 15, clamped to 100, plus `meta`).
  `career-roles` nests the paginator inside `data` (items at `data.data`, no `meta`).
- **Response envelopes are NOT uniform — never assume `success` exists.** Dominant shape
  `{success, message, data, request_id}` (19/23 controllers), but `Assistant`, `Intelligence`,
  `Readiness`, `SkillMatch` return a bare JsonResource; Sanctum's 401 is `{message}`; `AuthController`
  validation is `{message, errors}` with **no `success`** while `SetupController`'s is
  `{success, message, errors}`. Verified live.
- **⚠️ `test.com` IS a disposable domain.** The `indisposable` rule guards the three
  self-registration flows, so `test.com` in a registration payload returns 422 — and it silently
  turned two "expects 422" tests into passes for the wrong reason. Use `example.com` in registration
  fixtures. Blocked: `mailinator.com`, `yopmail.com`, `guerrillamail.com`, `test.com`.
  Safe: `example.com`, `gmail.com`, `company.com`.
- **Normalise payloads RECURSIVELY before hashing/fingerprinting** — sort every map at every depth,
  keep lists ordered (`array_is_list()`), and guard-test that different payloads are still detected.
- **Secret-gated bootstrap endpoints** (`ADMIN_SETUP_SECRET` / `MENTOR_SETUP_SECRET`) use
  `hash_equals()` and are unauthenticated by design. Probe without side effects: POST the secret with
  **no `email`** → `422 "The email field is required."` = secret passed; `403 "Invalid setup secret."`
  = wrong; `403 "…is disabled…"` = env unset. Both set on Render (`back-end-zdip`), verified
  2026-09-29. `create-mentor` **registers** a mentor and doubles as the admin's password-reset path.
- Verify: `php -l <files> ; php artisan test ; php vendor/bin/pint --test <files>`. Baseline **773**.
