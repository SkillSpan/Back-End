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
