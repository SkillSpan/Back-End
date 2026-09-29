# SkillSpan Back-End — notes

Laravel 12 / PHP 8.2, Sanctum, MySQL prod (remote Clever Cloud), SQLite `:memory:` in tests.
Branch `feature/authentication`. Checkout `E:\SkillSpan\Back-End-feature-authentication`.
Per-task narratives → dated daily logs in this folder. US-MATCH-02 detail + error codes →
`US-MATCH-02_APPLICATION_WORKFLOW_REPORT.md` in the project root.

## Architecture

- **Auth**: Sanctum. `User.status` (active/suspended/pending/deleted), soft deletes, roles
  via `user_role` pivot (+ optional `organization_id`): learner, company_admin,
  university_admin, admin. `Permission`/`role_permission` are seeded but NOT wired —
  vocabulary only; all gating is role-slug middleware (`role`, `admin`, `mentor`,
  `organization.approved`, `account.active`).
- **Pattern**: thin Controller → Service → FormRequest → JsonResource; exceptions carry
  `status`/`codeName`/`details`; X-Request-ID correlation.
- **ProfessionalProfile**: mentor/reviewer/partner_representative + `verification_status`.
  No "mentor" role exists — mentor identity IS the profile.
- **StudentProfile**: `visibility`, `consent_given`, `specialization_id`,
  `primary_career_role_id`.
- **Project**: status enum (draft…archived), `owner_id`, `organization_id`, `capacity`,
  `eligibilityConstraints`, `applications`, `teams`→`team_members`.
- **Notifications**: `event_key` unique per user, `read_at`; `notification_preferences`
  opt-out (absent row = enabled). **`NotificationService` is the only dispatch gateway** —
  never `Notification::create()` directly.
- **Audit**: `audit_events`, written via the `AuditsActions` trait.
- **Mentor↔student**: `mentor_student_connections` (unique mentor+student+project),
  `conversations`, `messages` (text/system/chatbot). `/api/v1/mentor/*`,
  `/api/v1/conversations/*`; the chatbot endpoint forces `message_type=chatbot` and rejects
  a client-supplied one.

## Data Science — three flows, never conflate

- **Composite Readiness** — `POST /readiness/calculate` → `ReadinessService` →
  `DataScienceClient` → FastAPI `/skill-match`, algorithm `skill-match-v1`.
- **Intelligence** — `POST /intelligence/calculate` → `IntelligenceService` →
  `IntelligenceClient` → FastAPI `/skill-gap`, algorithm `skill-gap-v1`.
- **Baseline** — `POST /baseline-assessments/{id}/submit` → `BaselineDataScienceClient` →
  FastAPI `/baseline`, algorithm `baseline-v1.0`.

Laravel owns Composite Readiness: local components (Practical Experience, Assessment
Reliability, Profile Completeness) + FastAPI Skill Match, weights `0.65/0.20/0.10/0.05`,
Critical Skill Cap `69.0`. Bands: foundation_needed 0-39.99, developing 40-59.99,
moderate_readiness 60-74.99, near_ready 75-89.99, highly_ready 90-100.
**ADR-001**: `composite_algorithm_version` = structure (`composite-readiness-v1`);
`configuration_version` = numbers (`config-v{n}`); `algorithm_version` = FastAPI's own value.

## Deployment — 3 Render services, env vars PER-SERVICE

| Service | Runtime | Key vars |
|---|---|---|
| `back-end-zdip` | Laravel/Docker | `APP_KEY`, `DB_*`, `MAIL_*`, `GEMINI_API_KEY`, `DATA_SCIENCE_SERVICE_*`, `CACHE_STORE`, `INTERNAL_BASELINE_ITEMS_SECRET` |
| `skillspan-intelligence` | FastAPI/uvicorn | `DATA_SCIENCE_SERVICE_TOKEN`, `BACKEND_BASE_URL`, `BASELINE_INTERNAL_SECRET`, `BASELINE_MAPPING_TIMEOUT_SECONDS` |
| frontend | Node/Vite | `VITE_API_BASE_URL` |

"I set the variable" → **ask WHICH service.**
**DS auth gate**: `service_token_middleware` guards `/api/v1/*` — 503 secret unset,
401 mismatch, **422 auth OK + bad payload (success signal)**, 502 upstream contract failure.
Token 64 chars, raw (never `Bearer xxx`).

## Conventions that bite

- Migrations **additive + idempotent** (`Schema::hasColumn` guards).
- Every calculation writes a `decision_snapshots` row first (pending → succeeded/failed);
  history is append-only. Never fabricate versions/configs/scores; a missing active config =
  explicit 422.
- **Never send two edits to the same file in one message** — the second silently reverts the
  first. `Write` overwrites wholesale, so it is safe.
- **Checked-in `.env` = LIVE Clever Cloud MySQL with `APP_ENV=production`.** Before any
  `migrate`/`seed`/`serve`/`tinker` export `DB_CONNECTION=sqlite DB_DATABASE=<file>` —
  exported vars win over `.env` (Dotenv `safeLoad` is immutable).
- **Columns kept out of `$fillable` must be written explicitly**, never via
  `create([...])`/`updateOrCreate` — they drop silently and the symptom shows up somewhere
  else. Three: `ProfessionalProfile::verification_status` (gates `EnsureUserIsMentor`; tests
  use `forceCreate` on purpose), `Organization::verification_status` (gates **login** — a
  pending/rejected org makes `AuthController` reject active members with "Your organization
  is still pending approval"), `Application::active_key` (derived).
- **Before the FIRST write to a model, diff `$fillable` against NOT NULL columns with no DB
  default** — a missing entry dies with `NOT NULL constraint failed: …`, not a
  mass-assignment error. Bit twice: `Notification::read_at`, `FeedbackEvent::occurred_at`.
- **Look users up with `User::withTrashed()` before any insert** — a soft-deleted row still
  owns the unique index on `users.email`; a scoped lookup returns null and the insert dies
  500 instead of a clean 422.
- **Laravel 12**: `getJson($uri, $headers, $options)` but `postJson`/`patchJson` are
  `($uri, $data, $headers)`.
- **New list endpoints paginate** (`per_page` default 15, clamped to 100, plus `meta`) — the
  `RecommendationController`/`NotificationService::list` contract. Non-breaking while `data`
  stays the array.
- **Normalise payloads RECURSIVELY before hashing/fingerprinting** — sorting only the top
  level makes a retry with reordered nested maps look like a different body. Sort every map
  at every depth, keep lists ordered (`array_is_list()`), and guard-test that genuinely
  different payloads are still detected.
- **`POST /api/v1/setup/create-mentor` REGISTERS the mentor** (not promote-only),
  secret-gated (`MENTOR_SETUP_SECRET`), no OTP by design — the user is the sole onboarding
  authority. Creates the user `active` + `email_verified_at=now()` if unknown, activates a
  `pending` one, 422 on `suspended`/`deleted`. Returns a fresh password once
  (`data.generated_password`); never echoes a caller-supplied one; new users get **no role**.
  It doubles as the admin's **password-reset path**: on an EXISTING user a supplied
  `password` is applied (`password_reset: true`); omitting it leaves the old one untouched —
  because the OTP flow mails the code to the mentor's inbox, which the admin may not control.
- Verify: `php -l <files> ; php artisan test ; php vendor/bin/pint --test <files>`.
  Baseline **761 passed (2633 assertions)**.

## US-MATCH-02 — applications & recommendation feedback

**Spec premises FALSE here:** no `project_roles` table (only `projects.role` free-text +
`project_team_members.project_role`); **no project↔specialization/career-role link**
(specialization reaches matching only as `target_career_role` in the FastAPI payload — a
scoring input, never an authorization rule); **`difficulty` has NO thresholds anywhere** (a
matching input like career role; no hard gate without a real threshold policy).

**User-confirmed business rules — do not re-litigate:**
- **Discovery = OPEN CATALOG**, not pre-assignment. Relevance is technical: **target career
  role + required skills + learner skill level + difficulty**. Academic specialization is
  **NOT** a targeting mechanism (no `projects.specialization_id`, no pivot, no gate — an
  earlier attempt was reverted).
- **`capacity` = actual project seats**, NOT a cap on discovery/applications, and never a
  career-role/skill eligibility mechanism. `projects.capacity` is the source of truth;
  `NULL` = unlimited.
- **Which statuses consume a seat is NOT approved.** Placeholder
  `Application::CAPACITY_STATUSES = [accepted]`. Never hard-code `count(status='accepted')`
  elsewhere — one swappable seam: `config/project_application.php` + `ProjectCapacityPolicy`
  (`consumingStatuses`, `rejectsSubmissionWhenFull`, `seatsTaken/Remaining`, `isFull`,
  `check()`, `blocksNewApplication()`). `reject_submission_when_full=true` is an **INFERRED
  default**, not a business statement.
- `project_roles` + `applications.project_role_id` stay, **no role-level capacity**.
- `unique(project_id, applicant_id, active_key)` stays → re-application after a
  terminal/withdrawn application is allowed.
- Capacity/difficulty may not become a hard gate until an explicit rule exists — put such
  rules in `AlgorithmConfiguration` so they inherit ADR-001 `configuration_version`.

**`active_key` pattern**: `1` for active statuses, `NULL` for terminal. NULLs never collide
on MySQL or SQLite, so a partial unique works without partial indexes. Derived in an
`Application::booted()` `saving` hook so it cannot drift from `status`. Reusable for any
"one active X per Y" rule that must coexist with soft history.

**`submit()` order — do not reorder:** idempotent replay → availability → **client references
(role + recommendation)** → duplicate → capacity → eligibility → persist/audit/notify.
Replay is FIRST so a retry returns the original result even if the project filled up since;
201 vs 200 comes from `wasRecentlyCreated`, which is why `submit()` returns the in-memory
model and never `fresh()`. **Both client references validate together at step 3, before any
business-state check** — a bad reference must not be masked by a duplicate or capacity error
that is also true; getting this wrong passed PHPUnit and only failed live. Duplicate precedes
eligibility because eligibility also contains a duplicate rule.

**PHPUnit structurally cannot catch sequential-state bugs** — that defect passed 50 feature
tests, because each test starts from a clean DB and never has an *already applied* learner
reaching the recommendation branch. For ordered workflows, prove it with a sequential live
run, not only per-scenario tests.

Endpoints (6): learner `POST /projects/{project}/applications`, `GET /applications`,
`POST /applications/{application}/withdraw`, `POST /recommendations/{recommendation}/feedback`;
owner `GET /projects/{project}/applications`,
`PATCH /projects/{project}/applications/{application}` — the owner routes are deliberately
**not** `role:learner`, because any authenticated user may own a project; ownership is checked
in the service.

Never implemented on purpose: `difficulty` as a gate, the recommendation suppression window,
the `start_date` application rule, a withdrawal-reason column (the reason lives only in the
audit row). Test baseline grew 434 → 683 → 761.

## Git gotcha — READ BEFORE ANY GIT WRITE

This checkout deletes the **nested** ref `refs/heads/feature/authentication` (the `feature/`
directory goes away) on ANY git *write* op — **including `git add`**, not only commit/reset.
`core.logAllRefUpdates=false`, so **no reflog** — you must know the SHA.
- **`git add` alone deletes it.** The next `git commit` then dies with *"your current branch
  'feature/authentication' does not have any commits yet"* and nothing is committed (the index
  survives untouched). **Restore the ref first, then commit.**
- `git commit` succeeds, then the ref is deleted again → capture the SHA from
  `[feature/authentication <sha>]` and restore it.
- `git reset` deletes the ref FIRST, leaving the repo reporting "no commits yet". **Never run
  `git reset` here.** Objects survive; only the ref is lost.
- **Before every commit here, check `git diff --cached --name-only`.** A stray staged file is
  easy to miss, because `git status --short` renders staged `M ` and unstaged ` M` almost
  identically. `.workbuddy-ai/` is gitignored but a few of its files are tracked from before
  the ignore rule, so agent notes can slip into a commit.
- To unstage: `git read-tree HEAD` (does not touch refs).
- `git update-ref` reports success but does NOT persist — use `mkdir -p` + `printf`.

Recovery: `mkdir -p .git/refs/heads/feature` → `printf '<40-char-sha>\n' >
.git/refs/heads/feature/authentication` → `git log --oneline -3`. Find the SHA via
`git rev-parse <short-sha>`, else `git fsck --no-reflogs --unreachable`. **Verify after EVERY
git write:** `ls .git/refs/heads/feature/authentication`. Use system git
(`/c/Program Files/Git/cmd/git.exe`) for pushes.
