# Render Environment Variables — audit

**Service audited:** the Laravel backend (`back-end-zdip`), Dockerised, `Dockerfile` at repo root.
**Date:** 2026-10-06 · **Method:** every key in the Render dashboard compared against
(a) `grep env(` across `config/`, and (b) `.env.example`.

**Verdict: the list is ~90 % right, but there is one genuinely dangerous omission
(`FILESYSTEM_DISK`), one behaviour-changing omission (`DATA_SCIENCE_ROADMAP_ENABLED`), and four
dead variables that should be deleted so nobody wastes time on them.**

---

## 1. 🔴 Missing and DANGEROUS — add this

| Key | Why it matters |
|---|---|
| **`FILESYSTEM_DISK`** | Not set → `config/filesystems.php:16` defaults to **`local`**, which on Render resolves to `storage/app/private` **inside the container**. That filesystem has **no persistent volume**, so every deploy wipes runtime uploads: proof documents disappear while their database rows survive, and the admin panel is left linking to files that no longer exist. |

**The app is already built for this.** `AuthService::uploadProofFile()` (`app/Services/AuthService.php:532`)
calls `$file->store('proofs/'.$organization->id)` with **no disk argument**, and its comment says this is
deliberate — "lets `FILESYSTEM_DISK=s3` move uploads to storage that actually persists, without touching
this code again". The read paths (`Storage::exists()`, `Storage::response()`, the `available` flag in the
admin API) all use the default disk too. So **one variable really does control all of it.**

### Possible values — only three exist

| Value | Resolves to | Persists across deploys? |
|---|---|---|
| `local` (the current default) | `storage/app/private` inside the container | ❌ **no** |
| `public` | `storage/app/public` inside the container | ❌ **no** — same container FS. Also needs `php artisan storage:link`. |
| `s3` | any S3-compatible bucket | ✅ yes |

### ⚠️ `s3` will NOT work as-is — the driver package is missing

`league/flysystem-aws-s3-v3` is **not installed**: it is absent from `composer.json`'s `require` block
and absent from `vendor/league/` (which holds only `flysystem`, `flysystem-local`, `commonmark`,
`config`, `mime-type-detection`, `uri`, `uri-interfaces`). The only two mentions in `composer.lock` are
inside `laravel/framework`'s own `require-dev` and `suggest` sections — i.e. a *suggestion*, not a
dependency of this app.

**Setting `FILESYSTEM_DISK=s3` today would break uploads** with a missing-driver-class error, not move
them. To use `s3` you must first:

```bash
composer require league/flysystem-aws-s3-v3:"^3.0" --with-all-dependencies
```

…and commit `composer.json` + `composer.lock` — the `Dockerfile` runs `composer install --no-dev`, so it
must be in `require` (not `require-dev`) to reach the container.

Then set the `AWS_*` block:

```
AWS_ACCESS_KEY_ID  AWS_SECRET_ACCESS_KEY  AWS_DEFAULT_REGION  AWS_BUCKET
AWS_ENDPOINT       AWS_USE_PATH_STYLE_ENDPOINT
```

Any S3-compatible provider works (AWS S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces, Supabase
Storage, MinIO) — `AWS_ENDPOINT` + `AWS_USE_PATH_STYLE_ENDPOINT=true` is what points at a non-AWS one.

### 🟢 Cheaper alternative that needs no code change: a Render Disk

Attach a **persistent disk** to the service mounted at `/var/www/html/storage/app/private`, and leave
`FILESYSTEM_DISK` as `local`. The container filesystem is then backed by a volume, so uploads survive
deploys — zero composer change, zero code change. (Render Disks require a paid instance type.)

### 🟡 Or accept it explicitly

Set `FILESYSTEM_DISK=local` anyway, purely as documentation, and rely on the admin API's `available`
field — it already reports `false` for a row whose file is gone, so the frontend can degrade gracefully.

### ⚠️ Switching later does not migrate the past

Uploads made while `local` was active live inside an old container and are **already gone**. Their
database rows keep pointing at paths that will not resolve on the new disk, so they stay
`available=false`. Only new uploads benefit.

**Whatever you choose: redeploy afterwards** — `config:cache` bakes the value at container start (§5a).


## 2. 🟠 Missing and behaviour-changing — decide deliberately

| Key | Default if absent | Effect |
|---|---|---|
| **`DATA_SCIENCE_ROADMAP_ENABLED`** | `false` | `POST /api/v1/roadmap` stays inert and returns **`503 INTELLIGENCE_NOT_CONFIGURED`**. Set it to `true` only once the FastAPI roadmap endpoint is actually deployed — enabling it early turns a clean 503 into a real outbound failure. |
| **`LOG_LEVEL`** (+ `LOG_CHANNEL`) | `debug` | Production would write debug-level logs — noisy and it can expose request detail in the log stream. Prefer `warning` or `error`. |

## 3. ⚫ Dead variables on THIS service — delete them

These are set on the Laravel service but **no Laravel code reads them**. They are either the
FastAPI service's names or frontend build-time names. Leaving them is not harmful, but they cost
debugging time — someone will eventually "fix" one of them and wonder why nothing changed.

| Key | Why it does nothing here |
|---|---|
| `MAIL_ENCRYPTION` | **Laravel 12 renamed this to `MAIL_SCHEME`** (`config/mail.php:42` reads `env('MAIL_SCHEME')`). `MAIL_ENCRYPTION` is not referenced anywhere in `config/` or `app/`. → Replace with `MAIL_SCHEME` (e.g. `smtps`) or delete. |
| `BASELINE_INTERNAL_SECRET` | Laravel reads **`INTERNAL_BASELINE_ITEMS_SECRET`** (`config/services.php:309`). `BASELINE_INTERNAL_SECRET` is the **FastAPI** service's variable name (`baseline_mapping_client.py`). ⚠️ Both exist here, so if you also set it on FastAPI, make sure the two **values are identical** — a mismatch makes `GET /api/v1/internal/baseline-items` return 401 and blocks the baseline mapping. |
| `CEREBRAS_API_KEY` | Not present anywhere in this repository. These are the assistant service's LLM-provider keys (its failover across providers) — they belong on the **FastAPI** service, not here. |
| `GROQ_API_KEY` | Same as above. |
| `VITE_API_BASE_URL` | A **build-time** Vite variable. Laravel does not read it at runtime; the frontend bakes it in when it builds. Harmless here, but it is not doing what it looks like it is doing. |

## 4. ✅ Present and correct

`APP_KEY` · `APP_ENV` · `APP_DEBUG` · `APP_URL` · `DB_CONNECTION` · `DB_HOST` · `DB_PORT` ·
`DB_DATABASE` · `DB_USERNAME` · `DB_PASSWORD` · `CACHE_STORE` · `QUEUE_CONNECTION` ·
`SESSION_DRIVER` · `SESSION_SECURE_COOKIE` · `MAIL_MAILER` · `MAIL_HOST` · `MAIL_PORT` ·
`MAIL_USERNAME` · `MAIL_PASSWORD` · `MAIL_FROM_ADDRESS` · `MAIL_FROM_NAME` · `CORS_ALLOWED_ORIGINS` ·
`ADMIN_SETUP_SECRET` · `MENTOR_SETUP_SECRET` · `INTERNAL_BASELINE_ITEMS_SECRET` ·
`GOOGLE_CLIENT_ID` · `GEMINI_API_KEY` · `ASSISTANT_ENABLED` · `ASSISTANT_APPROVAL_REFERENCE` ·
`ASSISTANT_SERVICE_URL` · `ASSISTANT_SERVICE_PATH` · `ASSISTANT_SERVICE_TIMEOUT` ·
`ASSISTANT_SERVICE_TOKEN` · `DATA_SCIENCE_SERVICE_URL` · `DATA_SCIENCE_SERVICE_TIMEOUT` ·
`DATA_SCIENCE_SERVICE_TOKEN` · `DATA_SCIENCE_PROJECT_MATCHING_ENABLED` ·
`DATA_SCIENCE_PROJECT_MATCHING_PATH` · `DATA_SCIENCE_BASELINE_ENABLED`

**Values to confirm by eye** (they are masked in the dashboard, so this audit cannot verify them):

- `APP_DEBUG` must be **`false`** in production.
- `APP_ENV` must be **`production`**.
- `CACHE_STORE` should be **`file`** (or `redis`) — **not** `database`. With the remote MySQL, a
  `database` cache makes every `throttle:*` request do a DB round trip; measured at ~3.4 s per
  throttled request, which pushed the internal baseline-items endpoint past the DS service's 8 s
  mapping timeout and produced intermittent 503s.
- `ASSISTANT_ENABLED` must be `true` **and** `ASSISTANT_APPROVAL_REFERENCE` non-empty, or the
  assistant fails closed (SRS v1.1 §12.5 governance gate) — both are required together.
- `SESSION_SECURE_COOKIE` should be `true` (the app forces HTTPS URLs in production).

## 5. ⚠️ Two operational traps this config interacts with

**(a) `php artisan config:cache` runs on every boot** (`Dockerfile:66`). A cached config is
**baked at container start**, so **editing an env var in the Render dashboard has no effect until
the service is redeployed**. If you change a variable and nothing happens, that is why — hit
"Manual Deploy".

**(b) There is no queue worker in this image.** The `Dockerfile` `CMD` runs `migrate`, `config:cache`,
a background seeder, and `php artisan serve` — and nothing else. There is no
`php artisan queue:work` anywhere.

- If `QUEUE_CONNECTION=database` → every queued job **silently never runs**. The intelligence
  recalculation listener (`SkillDataChanged`) is queued, so recalculations would simply stop.
- If `QUEUE_CONNECTION=sync` → jobs run inline during the request. Correct behaviour, slower
  response times.

Either set `QUEUE_CONNECTION=sync`, or add a second Render service (a Background Worker) running
`php artisan queue:work`. The current combination — `database` with no worker — is the one state
that is wrong in both directions.

## 6. Optional keys (safe to omit)

These have sane defaults and were deliberately left out; add them only to tune behaviour:

`SANCTUM_STATEFUL_DOMAINS` (only needed for cookie-based SPA auth; Bearer tokens do not use it) ·
`SANCTUM_TOKEN_EXPIRATION_MINUTES` (default 20160 = 14 days) · `DATA_SCIENCE_API_VERSION` ·
`DATA_SCIENCE_SKILL_GAP_PATH` · `DATA_SCIENCE_SKILL_MATCH_PATH` · `DATA_SCIENCE_ROADMAP_PATH` ·
`DATA_SCIENCE_BASELINE_PATH` · `DATA_SCIENCE_BASELINE_VERSION` · `DATA_SCIENCE_ALGORITHM_VERSION` ·
`GEMINI_BASE_URL` · `GEMINI_REQUEST_TIMEOUT` · `OTP_LIFETIME_MINUTES` · `OTP_MAX_ATTEMPTS` ·
`OTP_RESEND_INTERVAL` · `PASSWORD_RESET_LIFETIME_MINUTES` · `PASSWORD_RESET_RESEND_INTERVAL` ·
`COMMUNICATION_*` · `BASELINE_MIN/MAX_*` · `APP_LOCALE`

All of the `DATA_SCIENCE_*` paths above default to the values already verified against the
deployed FastAPI OpenAPI document, so omitting them is correct.

---

## TL;DR

1. **Add `FILESYSTEM_DISK`** — but **not** `s3` unless you first
   `composer require league/flysystem-aws-s3-v3`; the driver is not installed. A Render Disk mounted at
   `storage/app/private` fixes it with no code change at all.
2. **Decide on `DATA_SCIENCE_ROADMAP_ENABLED`** — currently silently off.
3. **Delete `MAIL_ENCRYPTION`, `BASELINE_INTERNAL_SECRET`, `CEREBRAS_API_KEY`, `GROQ_API_KEY`,
   `VITE_API_BASE_URL`** — none are read by Laravel.
4. **Check `QUEUE_CONNECTION`** — if it is `database`, queued work is not running (no worker).
5. Remember: **changing a variable needs a redeploy** because of `config:cache`.
