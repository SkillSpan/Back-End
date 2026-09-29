# SkillSpan Backend

Backend for **SkillSpan**, a skills-readiness platform. Learners register, verify their email, build a student profile, evaluate their skills, and get a readiness score for a target career role. Organizations register with a proof document and gain access after admin approval.

## Stack

- **Laravel 12** (PHP ^8.2)
- **MySQL** database
- **Sanctum token auth** (Bearer tokens, 14-day expiry)
- **FastAPI microservice integration** for readiness calculation — see [INTEGRATION_README.md](INTEGRATION_README.md)
- **Gemini integration stub** (placeholder service for future AI features)

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed   # seeds roles via RolesSeeder
php artisan serve
```

The API is served at `http://localhost:8000` under the `/api/v1` prefix.

## Authentication flow

1. `POST /api/v1/auth/register` — learner registers and receives an OTP by email.
2. `POST /api/v1/auth/verify` — learner confirms the account with the OTP.
3. `POST /api/v1/auth/login` — returns a Sanctum Bearer token (valid 14 days), sent as `Authorization: Bearer <token>`.

Organization accounts (`POST /api/v1/auth/register/organization`) additionally require **admin approval** of their uploaded proof file before they can log in through `/api/v1/auth/login/organization` or access protected organization endpoints.

## Route summary

### Public

| Method | Endpoint | Description |
| --- | --- | --- |
| POST | `/api/v1/auth/register` | Learner registration (multipart form-data) |
| POST | `/api/v1/auth/register/organization` | Organization registration with proof file (max 5MB pdf/jpg/png) |
| POST | `/api/v1/auth/verify` | Email OTP verification |
| POST | `/api/v1/auth/resend-otp` | Resend verification OTP |
| POST | `/api/v1/auth/login` | Learner login |
| POST | `/api/v1/auth/login/google` | Google ID-token login |
| POST | `/api/v1/auth/login/organization` | Organization login (org accounts only) |
| POST | `/api/v1/auth/forgot-password` | Send password-reset OTP |
| POST | `/api/v1/auth/forgot-password/resend` | Resend password-reset OTP |
| POST | `/api/v1/auth/forgot-password/verify` | Verify reset OTP without changing the password |
| POST | `/api/v1/auth/reset-password` | Set new password using the OTP |
| GET | `/up` | Health check |

### Authenticated (`auth:sanctum`, Bearer token)

| Method | Endpoint | Description |
| --- | --- | --- |
| POST | `/api/v1/auth/logout` | Revoke current token |
| POST | `/api/v1/auth/logout-all` | Revoke all tokens |
| POST | `/api/v1/profile` | Create learner profile (learner only) |
| GET | `/api/v1/profile` | Show learner profile |
| PUT | `/api/v1/profile` | Partial profile update |
| POST | `/api/v1/readiness/calculate` | Calculate readiness via FastAPI service (learner only) |
| GET | `/api/v1/readiness/latest` | Latest readiness result (learner only) |
| GET | `/api/v1/organization/profile` | Organization self profile (approved orgs only) |

### Project discovery & matching (learner only)

All of these require `auth:sanctum` + an active account + the `learner` role.

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/api/v1/projects` | Discover available projects. Filters: `search`, `type`, `domain`, `work_mode`, `difficulty`, `organization_id`, `skill_ids[]`, `minimum_level`. Paginated via `page` / `per_page` (default 50, max 50); returns `data` + `meta`. |
| GET | `/api/v1/projects/{project}` | Details of one accessible, available project |
| POST | `/api/v1/projects/{project}/match` | Run project matching via the FastAPI intelligence service and persist the recommendation. Gated on `DATA_SCIENCE_PROJECT_MATCHING_ENABLED`; disabled ⇒ `503`. |
| GET | `/api/v1/projects/{project}/recommendation` | Stored explanation for this learner + project: `project_id`, `score`, `reasons`, `limiting_factors`, `algorithm_version`, `configuration_version`. Reads persisted data only — never recalculates and never calls FastAPI. |
| GET | `/api/v1/recommendations` | The learner's own stored project recommendations, newest first, paginated |

**Discovery vs eligibility.** `GET /api/v1/projects` applies availability (status `open`, unexpired `application_deadline`) and authorization (confidentiality / organization). It does **not** filter by hard eligibility — that is enforced in the matching flow (`ProjectMatchingSnapshotService`) instead, so an ineligible project stays discoverable but cannot be matched. Only `work_mode` and `schedule` eligibility constraints are enforced; `location` and `language` are stored but skipped, because `student_profiles` has no comparable field (`availability` and `preferred_work_type` are the only comparable ones).

**Restricted projects and organization membership.** A `restricted` project is visible only to a learner with an **`active`** row in `organization_members` for the owning organization. `invited` and `removed` memberships grant nothing. A learner may belong to several organizations and sees the restricted projects of all of them. Learners with no membership still see `public` projects normally.

**Filter notes.** `skill_ids[]` is a set — repeated ids are collapsed to distinct ones. `minimum_level` is the per-skill floor for that filter and is **rejected with `422 VALIDATION_ERROR`** when sent without `skill_ids`, rather than being silently ignored.

**Recommendation list.** `GET /api/v1/recommendations` always returns the learner's stored rows (historical recommendations are never deleted), but the embedded `project` payload is attached **only while the learner can still discover that project**. Once a project becomes restricted to another organization, or is closed or past its deadline, `project` becomes `null` and the stored score/reasons/versions remain. The response shape is unchanged.

**Error responses.** `GET /api/v1/projects/{project}/recommendation` checks project access **before** reading anything back, so it returns:

| Situation | Response |
| --- | --- |
| Project does not exist, **or** the learner may not access it | `404 PROJECT_NOT_FOUND` |
| Learner can access the project but has no stored recommendation | `404 RECOMMENDATION_NOT_FOUND` |

The first two cases return an **identical** body, so the endpoint cannot be used to probe for the existence of restricted projects. The third reveals nothing the learner could not already read from the project catalog. A learner who loses access to a project (membership removed, project made restricted to another organization, closed, or past its deadline) stops receiving its score, reasons, limiting factors and version metadata — the stored row is never deleted, only its exposure stops.

Note this is deliberately stricter than `GET /projects/{id}`, which still distinguishes `PROJECT_UNAUTHORIZED` (403) from `PROJECT_NOT_FOUND` (404).

**Pagination.** `GET /api/v1/projects` accepts `page` and `per_page` (`per_page` 1–50, default 50) and returns `meta.current_page`, `meta.last_page`, `meta.per_page` and `meta.total`. Ordering is `created_at DESC, id DESC` — the `id` tie-breaker keeps paging deterministic when projects share a `created_at`.

**Testing note.** The automated tests mock the FastAPI intelligence service (`Http::fake`). They verify Laravel-side workflow behaviour — snapshot creation, response validation, persistence, and error handling — and do **not** constitute a verified live Laravel↔FastAPI integration. A real integration test would require the deployed service and its service token.

### Admin (`auth:sanctum` + admin role)

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/api/v1/admin/organizations` | List organizations |
| GET | `/api/v1/admin/organizations/{id}` | Organization details |
| GET | `/api/v1/admin/organizations/{id}/proof-file` | Download proof file |
| POST | `/api/v1/admin/organizations/{id}/approve` | Approve organization |
| POST | `/api/v1/admin/organizations/{id}/reject` | Reject organization (optional `reason`) |

## Testing

```bash
php artisan test
```

Tests run against an in-memory SQLite database (`:memory:`).

## Admin bootstrap

`POST /api/v1/setup/create-admin` creates an initial admin account but is gated by the **`ADMIN_SETUP_SECRET`** environment variable: the request must include the matching value in the `secret` body field, otherwise it is rejected. The endpoint is also rate-limited.
