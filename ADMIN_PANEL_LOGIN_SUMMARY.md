# Admin Panel — Fix "ما قدرنا نجيب الطلبات" + Email/Password Login

Branch `feature/authentication` · checkout `E:\SkillSpan\Back-End-feature-authentication`

---

## 1. Why the page said "ما قدرنا نجيب الطلبات"

That sentence is written literally in the view — `resources/views/admin/organizations.blade.php`,
inside the `catch` of `loadOrganizations()`:

```js
setStatusLine('ما قدرنا نجيب الطلبات: ' + e.message, true);
```

So the message was not a crash in the markup. The page itself loaded fine — I served it and got
`HTTP 200`, and `BASE_URL` correctly resolved to the current origin, so it was never a CORS or
Blade-rendering problem. **The request the page makes is what failed:**

```
GET /api/v1/admin/organizations
→ HTTP 401  {"message":"Unauthenticated."}
```

The panel authenticated by asking the operator to paste a **Sanctum Bearer token** into a text box
(`Admin Token` → `localStorage`). Without a valid token that endpoint can only ever answer 401, and
the page then renders exactly the sentence you saw:

> **ما قدرنا نجيب الطلبات: Unauthenticated.**

This was not a bug in the view — the authentication model was unusable. That is also why the
second half of your request (a real email + password login) is the actual fix.

**The data was never missing.** The app connects to the remote MySQL database fine:

| | |
|---|---|
| Users | 122 |
| Organizations | **27** (14 pending · 10 verified · 3 rejected) |
| Admin accounts | 1 — `ahmedsaqallah22@gmail.com` (active) |

---

## 2. What changed

The panel now signs in with **email + password** and runs on a normal web session. The Bearer-token
box is gone.

### New files

| File | Purpose |
|---|---|
| `app/Http/Controllers/Web/AdminAuthController.php` | `showLoginForm` / `login` / `logout` |
| `resources/views/admin/login.blade.php` | Arabic RTL login form, matching the panel's design |
| `app/Http/Controllers/Web/AdminOrganizationController.php` | Session-authenticated twin of the admin API |
| `tests/Feature/Admin/AdminPanelAuthTest.php` | 18 regression tests |

### Modified files

| File | Change |
|---|---|
| `routes/web.php` | Login/logout routes; the panel and its JSON endpoints behind `auth, account.active, admin` |
| `resources/views/admin/organizations.blade.php` | Token bar → session bar; session-based fetches with CSRF |
| `app/Http/Controllers/Api/Admin/OrganizationController.php` | Extracted an overridable `proofFileRouteName()` |
| `app/Http/Middleware/EnsureAccountIsActive.php` | Made web-aware so a suspension ends an open session |

### The login rules

`login()` deliberately refuses more than a wrong password:

- **Wrong password** → back to the form with an error.
- **Correct password but not an admin** → refused *and* the session is dropped. A learner knowing
  their own password must not walk into the review panel.
- **Suspended / soft-deleted admin** → refused.
- **5 failed attempts per email+IP** → locked out for 60 seconds.

On success the session id is regenerated (defeats session fixation).

### Routes

| Method | URL | Notes |
|---|---|---|
| `GET` | `/admin/login` | named `login`, so guests are redirected here |
| `POST` | `/admin/login` | the form |
| `POST` | `/admin/logout` | ends the session |
| `GET` | `/admin/organizations` | the panel |
| `GET` | `/admin/api/organizations` | list (supports `?status=`) |
| `GET` | `/admin/api/organizations/{id}` | detail |
| `POST` | `/admin/api/organizations/{id}/approve` | approve |
| `POST` | `/admin/api/organizations/{id}/reject` | reject |
| `GET` | `/admin/organizations/{id}/proof-file` | proof document |

**The API is untouched.** `/api/v1/admin/*` and its `admin.organizations.proof-file` route name
still work exactly as before for token-based clients — the web panel just uses its own
session-authenticated routes. No review logic was duplicated: the web controller *extends* the API
controller and overrides only the route name used to build proof-document links.

---

## 3. Two latent bugs fixed along the way

1. **Broken proof-document link.** `route()` returns an *absolute* URL, but the view did
   `BASE_URL + org.proof_file.download_url`, producing
   `http://127.0.0.1:8000http://127.0.0.1:8000/api/...`. The URL is now used directly.

2. **A suspension did not end an open session.** The panel is session-based now, so a session
   created before a suspension would have kept working until it expired (~2 hours). `account.active`
   previously only returned JSON, so it could not be used on a web route — it is now web-aware:
   browser requests are signed out and redirected to the login page, while API clients still get the
   machine-readable `403 {"code":"ACCOUNT_DISABLED"}`.

---

## 4. Verification

```
php artisan test   →  219 passed (837 assertions)      (baseline was 201, no regressions)
Pint               →  PASS on all 6 touched PHP files
```

Live checks against a running server:

| Check | Result |
|---|---|
| `GET /admin/organizations` as a guest | `302 → /admin/login` |
| `GET /admin/login` | `200`, form with CSRF + email + password |
| `GET /admin/api/organizations` as a guest | `401` |
| Full cookie + CSRF round-trip, wrong password | `302` back with *"do not match our records"* — **not** 419, so CSRF and session wiring are correct |

### Full end-to-end login, verified live

Because your `.env` points at the **live** database, this was run against a **throwaway SQLite
database** so production was never touched (confirmed afterwards: still 122 users / 1 admin /
27 organizations, unchanged):

```bash
export DB_CONNECTION=sqlite DB_DATABASE=".../storage/verify.sqlite"
php artisan migrate --force && php artisan serve --port=8124
```

| Step | Result |
|---|---|
| `GET /admin/login` | `200`, CSRF token issued |
| `POST /admin/login`, correct credentials | `302` → panel |
| `GET /admin/organizations` | `200` — session bar shows the signed-in email |
| `GET /admin/api/organizations` | **`200` + organizations** ← *the request that used to return 401* |
| `?status=pending` filter | `200`, filtered correctly |
| `POST /admin/api/organizations/{id}/approve` | `200`, persisted, `verified_by` recorded |
| Proof document link | resolves to `/admin/organizations/{id}/proof-file` and returns `200 application/pdf` |
| `POST /admin/logout` | `302`; panel afterwards `302`; API afterwards `401` |

Two refusals are **intended behaviour, not bugs**:

- Approving an organization with no proof document → *"This organization has no proof document to
  review."* (the ADM rule: never mark something verified without evidence). Approval succeeded once
  a proof file was attached.
- Rejecting an already-reviewed organization → `422`.


---

## 5. How to use it

```bash
php artisan serve
```

Open <http://127.0.0.1:8000/admin/organizations> → you are redirected to the login page → sign in
with the admin account → the 27 organizations load, with working status tabs, detail expansion,
approve/reject, and proof-document links.

**If you don't have the password** for `ahmedsaqallah22@gmail.com`, create a fresh admin with the
existing setup endpoint (the secret is already in your `.env` as `ADMIN_SETUP_SECRET`):

```bash
curl -X POST http://127.0.0.1:8000/api/v1/setup/create-admin \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"secret":"sb-secret-92kd83","name":"Admin","email":"you@example.com","password":"a-strong-password"}'
```

---

## 6. Commit state

Everything is committed:

- **`2812c3f`** *"feature: add acoute editinh"* — the two web controllers, both views, the API
  controller tweak and `routes/web.php`.
- **`423c844`** *"add CRS"* — `EnsureAccountIsActive.php`, the route update, the test file and this
  summary.

Working tree is clean. HEAD is `423c844`.


---

## 7. Two things worth knowing

- **`.env` is correctly git-ignored — there is nothing to rotate here.** `.env` is listed in
  `.gitignore` and has never been committed (`git log --all -- .env` returns nothing).
  `ADMIN_SETUP_SECRET` exists only in the local `.env`; the repository contains just the empty
  `ADMIN_SETUP_SECRET=` placeholder in `.env.example`. *(An earlier version of this note claimed
  the secret was committed in `.env` — that was wrong.)* Rotating it is still worthwhile if the
  value has ever been shared outside your machine, since it creates admin accounts.
- **`APP_ENV=production` and `APP_DEBUG=true` locally.** That combination means verbose error pages
  locally; more importantly, if those values ever reach the deployed Render service, stack traces
  (and the secrets in them) become public. Worth checking the Render environment variables.
