# SkillSpan Back-End — project notes

Laravel 12 / PHP 8.2, Sanctum token auth, MySQL in prod (remote, Clever Cloud),
SQLite `:memory:` in tests. Branch `feature/authentication`.
Checkout: `E:\SkillSpan\Back-End-feature-authentication`.

## Architecture overview

- **Auth**: Sanctum tokens. `User` has `status` enum (active/suspended/pending/deleted),
  soft deletes, roles via `user_role` pivot (with optional `organization_id`).
- **Roles** (RolesSeeder): `learner`, `company_admin`, `university_admin`, `admin`.
- **RBAC**: `Permission` + `role_permission` tables seeded but NOT wired to any
  authorization check yet — vocabulary only. All gating is via role-slug middleware.
- **Middleware aliases** (`bootstrap/app.php`): `role` → EnsureUserHasRole,
  `admin` → EnsureUserIsAdmin, `mentor` → EnsureUserIsMentor,
  `organization.approved` → EnsureOrganizationIsApproved,
  `account.active` → EnsureAccountIsActive.
- **Pattern**: Controller (thin) → Service (orchestration) → FormRequest (validation).
  API Resources (JsonResource) for presentation. Custom exceptions with `status`,
  `codeName`, `details` (e.g. ReadinessException). X-Request-ID correlation.
- **Professional profiles** table has `type` enum: `mentor`, `reviewer`,
  `partner_representative` + `verification_status` (pending/verified/rejected).
  No "mentor" role exists in roles table — mentor is a professional_profile type.
- **Project** model: has `status` enum (draft/pending_review/open/closed/in_progress/
  completed/archived), `owner_id` (FK users), `organization_id` (FK, nullable).
  Has `eligibilityConstraints` (location/language/schedule/work_mode).
  Has `applications` (submitted/shortlisted/accepted/rejected/waitlisted/withdrawn)
  with unique `[project_id, applicant_id]`.
  Has `teams` → `team_members` (active/completed/removed).
- **Notifications** table: `user_id`, `category`, `channel`, `title`, `body`,
  `link`, `event_key` (unique per user), `read_at`.
  **Notification preferences** table: per-user per-category per-channel enable/disable.
- **Audit events** table: `actor_id`, `action`, `entity_type`, `entity_id`,
  `before`/`after` (json), `purpose`, `request_id`, `ip_address`, `user_agent`,
  `occurred_at`. Now actively used via `AuditsActions` trait — connection creation,
  status changes, conversation creation, and message sending all write audit rows.
- **Mentor-student communication system** (new): `mentor_student_connections`
  (mentor_id, student_id, project_id, status pending/active/disconnected/archived,
  unique [mentor_id, student_id, project_id]), `conversations` (connection FK,
  status active/archived/closed, retention_expires_at), `messages` (conversation FK,
  sender_id, body, message_type text/system/chatbot, metadata json, read_at, read_by).
  Services: `MentorStudentService`, `ConversationService`. Middleware: `mentor` alias.
  Routes under `/api/v1/mentor/*` and `/api/v1/conversations/*`.
- **NotificationService** (new): single dispatch gateway. `dispatch()` honours
  `notification_preferences` (opt-out: absent row = enabled) and is idempotent on
  `event_key`. All service notification creation MUST go through it — never call
  `Notification::create()` directly or preferences are bypassed.
  Notification API: `/api/v1/notifications` (list, unread-count, read-all,
  preferences, {id}/read).
- **Chatbot endpoints**: `POST|GET /api/v1/conversations/{id}/chatbot/messages`.
  Forces `message_type=chatbot`, audit action `chatbot.message.sent`. The request
  deliberately rejects a client-supplied `message_type` to prevent spoofing.
- **`Notification` model** now includes `read_at` in `$fillable` (it was missing,
  which silently broke single-row mark-as-read).
- **StudentProfile** has `visibility` enum (public/organization_only/private),
  `consent_given` bool. User→studentProfile is hasOne.

## Three Data Science flows — never conflate them

| Flow | Entry | Laravel client | FastAPI path | Algorithm |
|---|---|---|---|---|
| **Composite Readiness** | `POST /api/v1/readiness/calculate` | `Readiness\ReadinessService` → `DataScienceClient` | `/api/v1/skill-match` | `skill-match-v1` |
| **Intelligence** | `POST /api/v1/intelligence/calculate` | `Intelligence\IntelligenceService` → `IntelligenceClient` | `/api/v1/skill-gap` | `skill-gap-v1` |
| **Baseline** | `POST /api/v1/baseline-assessments/{id}/submit` | `Baseline\BaselineDataScienceClient` | `/api/v1/baseline` | `baseline-v1.0` |

Laravel is sole owner of Composite Readiness: local components (Practical Experience,
Assessment Reliability, Profile Completeness) + FastAPI Skill Match aggregated with
weights `0.65/0.20/0.10/0.05`. Critical Skill Cap `69.0`. Bands: foundation_needed
0-39.99, developing 40-59.99, moderate_readiness 60-74.99, near_ready 75-89.99,
highly_ready 90-100.

## Versioning (ADR-001)

- `composite_algorithm_version` = `composite-readiness-v1` → structure.
- `configuration_version` = `config-v{n}` → numbers (weights, cap, bands).
- `algorithm_version` = FastAPI response value → one component's algorithm.

## Deployment — THREE Render services, env vars PER-SERVICE

| Service | Runtime | Key env vars |
|---|---|---|
| `back-end-zdip` | Laravel/Docker | `APP_KEY`, `DB_*`, `MAIL_*`, `GEMINI_API_KEY`, `DATA_SCIENCE_SERVICE_*`, `CACHE_STORE`, `INTERNAL_BASELINE_ITEMS_SECRET` |
| `skillspan-intelligence` | FastAPI/uvicorn | `DATA_SCIENCE_SERVICE_TOKEN`, `BACKEND_BASE_URL`, `BASELINE_INTERNAL_SECRET`, `BASELINE_MAPPING_TIMEOUT_SECONDS` |
| frontend | Node/Vite | `VITE_API_BASE_URL` |

**When told "I set the variable", ask WHICH service.**

## DS service auth gate

`app/main.py` has `service_token_middleware` guarding every `/api/v1/*` path.
503 = secret unset. 401 = token mismatch. 422 = auth OK, payload wrong (success signal).
502 = upstream contract failure. Token is 64 chars, passed raw (never `Bearer xxx`).

## Conventions that bite

- **Migrations must be additive AND idempotent** (`if (! Schema::hasColumn(...))`).
- **Every calculation writes a `decision_snapshots` row first** (status pending),
  marked succeeded/failed. Historical results are append-only.
- **Never fabricate** versions/configs/scores. Missing active config = explicit 422.
- `phpunit.xml` pins SQLite `:memory:`. Keep it that way.
- **Never send two edits to the same file in one message** — second silently reverts first.
- **Laravel 12 `getJson($uri, $headers, $options)`** — headers are 2nd param, NOT 3rd.
  `postJson`/`patchJson` take `($uri, $data, $headers)` — different signature.
- Verify changes with: `php -l <files> ; php artisan test ; php vendor/bin/pint --test <files>`
- Current test baseline: **419 passed (1444 assertions)**.

## Git gotcha

`git commit` on this checkout can delete the nested ref directory. Recover:
```bash
mkdir -p .git/refs/heads/feature
printf '<sha>\n' > .git/refs/heads/feature/authentication
```
Use system git (`/c/Program Files/Git/cmd/git.exe`) for pushes.
