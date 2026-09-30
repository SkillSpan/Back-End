# Disposable / temporary email protection on registration

**Status:** implemented and verified — 15 new tests, full suite **788 passed (2717 assertions)**,
`pint --test` clean.
**Date:** 2026-09-29

---

## 1. Why

Nothing stopped someone from registering with a throwaway address (`someone@mailinator.com`),
using the account, and abandoning it — leaving unverifiable accounts behind and making the
email-verification (OTP) step meaningless.

The check is **server-side only**. Frontend validation is not a control.

---

## 2. Package

| | |
|---|---|
| Package | `propaganistas/laravel-disposable-email` |
| Version | `^2.5` (resolved to **2.5.2**, released 2026-09-01) |
| Requires | PHP `^8.1` |
| Supports | Laravel / illuminate `^10.0 \| ^11.0 \| ^12.0 \| ^13.0` |
| Rule name | **`indisposable`** |

### Why this one

- **Compatible with this project**: PHP 8.2 and Laravel 12 are both inside the supported range.
- **Actively maintained**: the last three releases are 2.5.2 (2026-09-01), 2.5.1 (2026-08-01) and
  2.5.0 (2026-07-08) — roughly monthly, and the maintainer ships a patch release whenever the
  upstream domain list changes.
- **No hand-maintained domain list in this repo.** The package ships a list of **75,248 domains**
  and pulls updates from the community-maintained
  [`disposable/disposable-email-domains`](https://github.com/disposable/disposable-email-domains)
  list. Adding a hardcoded list here would rot within weeks.
- **Works offline.** If the local storage file is absent the package falls back to the list bundled
  inside `vendor/` — so there is **no network call during validation**. This mattered: registration
  must not start failing because a CDN is unreachable.
- **Does not interfere with other rules.** A value that does not even contain a domain is reported
  as "not disposable" rather than as an error, so it cannot double-report alongside `email`.

Alternatives considered and rejected: `erag/laravel-disposable-email` (smaller list, less active),
and writing a custom rule around a hand-rolled domain list (explicitly ruled out — maintenance
burden, no update path).

---

## 3. Files changed

| File | Change |
|---|---|
| `composer.json` | `+ propaganistas/laravel-disposable-email: ^2.5` (one line; nothing else touched) |
| `composer.lock` | lock entry for the new package only (77 lines added) |
| `config/disposable-email.php` | **new** — published from the package; `include_subdomains` flipped to `true` (see §6) |
| `app/Http/Requests/Auth/RegisterRequest.php` | `indisposable` appended to the `email` rule + explicit message |
| `app/Http/Requests/Auth/RegisterOrganizationRequest.php` | same, on the account `email` only |
| `app/Services/AuthService.php` | `Validator` import + the Google sign-up gate in `loginWithGoogle()` |
| `tests/Feature/Auth/DisposableEmailRegistrationTest.php` | **new** — 15 tests |
| `tests/Feature/Auth/RegistrationTest.php` | fixtures moved off `test.com` (see §8) |

No migration. No schema change. No change to controllers, middleware, routes, or the response
format. The `AuthService` constructor signature is unchanged.

---

## 4. Where the validation happens

### 4.1 The two explicit self-registration endpoints

`app/Http/Requests/Auth/RegisterRequest.php`

```php
'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email', 'indisposable'],
```

`app/Http/Requests/Auth/RegisterOrganizationRequest.php` — identical rule on the account `email`.

`indisposable` is appended **after** `email` and `unique` so that:

- a malformed address still fails on `email` first, and
- an already-registered address still reports the duplicate error it always did.

Both FormRequests already lower-case and trim the address in `prepareForValidation()`, so the rule
sees a normalised value.

### 4.2 The implicit third flow — Google sign-up

`POST /api/v1/auth/login/google` **creates an account** the first time a Google identity is seen
(`AuthService::loginWithGoogle()` → `createUser()`). This is a self-registration path.

It cannot be covered by `GoogleLoginRequest`, because the email address is not client input — it
arrives inside the verified Google ID token. The gate therefore lives in the service, on the
creation branch only, immediately after the terms/privacy check:

```php
if (Validator::make(['email' => $email], ['email' => ['indisposable']])->fails()) {
    DB::rollBack();

    throw ValidationException::withMessages([
        'credential' => 'Disposable or temporary email addresses are not allowed.',
    ]);
}
```

Notes:

- The `indisposable` rule is used **as the predicate**, so the domain list, the whitelist and the
  `include_subdomains` setting stay driven by the same config as the two FormRequest flows.
- The error is reported on **`credential`** — the only field this endpoint accepts, and the key
  every other identity error in that method already uses. The frontend's existing Google error
  handling keeps working.
- `DB::rollBack()` before throwing matches the surrounding code (the method is already inside a
  transaction at that point).

---

## 5. How detection works

1. The rule resolves the domain: `Str::lower(explode('@', $email, 2)[1])`.
   **Detection is therefore case-insensitive**, independently of `prepareForValidation()`.
2. The configured `include_subdomains` decides the match mode:
   - `false` (package default) → exact match only
   - `true` (this project) → the domain, or any subdomain of a listed domain
3. The domain list comes from local storage if present, otherwise from the list bundled in the
   package. It is read once and cached forever in `config('cache.default')` — `file` in this
   project, so it is read once per deployment, not per request.
4. Whitelisted domains are removed from the list at load time.

### Response on rejection

Registration (both self-registration endpoints):

```json
{
  "message": "Disposable or temporary email addresses are not allowed.",
  "errors": {
    "email": ["Disposable or temporary email addresses are not allowed."]
  }
}
```

Google sign-up:

```json
{
  "message": "Disposable or temporary email addresses are not allowed.",
  "errors": {
    "credential": ["Disposable or temporary email addresses are not allowed."]
  }
}
```

HTTP **422** in both cases — the same envelope the project already returns for validation failures
(`message` + `errors.<field>[]`). No new response format was introduced.

---

## 6. Configuration

`config/disposable-email.php` was published so that two things are controllable. Nothing else was
added.

| Key | Value | Why |
|---|---|---|
| `include_subdomains` | **`true`** (package default is `false`) | The default only matches the exact domain, so `someone@inbox.mailinator.com` would slip past a check that catches `someone@mailinator.com`. Disposable providers hand out subdomain inboxes, so the default leaves a real bypass open. There is no false-positive cost: a subdomain is only rejected when its parent is already listed. |
| `whitelist` | `[]` | Escape hatch, left empty. See below. |

Everything else (`sources`, `fetcher`, `storage`, `cache`) is left at the package default.

**Not enabled on purpose:** the optional `mx` parameter (`'email', 'indisposable:mx'`). It adds a
live DNS lookup to every registration to catch domains that rotate their public front-end while
keeping the same mail backend. That is real coverage, but it puts an external network dependency on
the registration path and adds latency. Worth revisiting if disposable signups continue after this
change — it is a one-word change to the rule.

### If a legitimate domain gets blocked

The upstream list is community-maintained and occasionally flags a real domain. Fix it by adding
the domain to `whitelist` in `config/disposable-email.php` — nothing else in the registration flow
needs to change:

```php
'whitelist' => ['legitimate-company.com'],
```

---

## 7. Which flows are protected

| Flow | Endpoint | Protected? |
|---|---|---|
| Individual self-registration | `POST /api/v1/auth/register` | **Yes** |
| Organisation self-registration | `POST /api/v1/auth/register/organization` (account `email`) | **Yes** |
| Google sign-up (account creation branch) | `POST /api/v1/auth/login/google` | **Yes** |
| Google login for an **existing** account | `POST /api/v1/auth/login/google` | **No — by design.** Never re-checked, so no current user can be locked out |
| `organization_contact_email` | org registration | **No — by design.** A public contact address, not the account identity; the account cannot log in until an admin approves the organisation |
| Admin bootstrap | `POST /api/v1/setup/create-admin` | **No — by design.** Admin-vouched, secret-gated, not self-registration |
| Mentor onboarding | `POST /api/v1/setup/create-mentor` | **No — by design.** Same reasoning; the secret holder is the sole onboarding authority |

There is **no separate mentor / reviewer / university self-registration endpoint** in this codebase.
Mentor identity is a `ProfessionalProfile(type=mentor, verification_status=verified)`, created only
through `setup/create-mentor`.

---

## 8. Tests

`tests/Feature/Auth/DisposableEmailRegistrationTest.php` — **15 tests**

| # | Test | Covers |
|---|---|---|
| 1 | `test_individual_registration_succeeds_for_a_normal_email` | valid email still registers (201) |
| 2 | `test_individual_registration_rejects_a_disposable_email` | rejection + nothing persisted + no OTP sent |
| 3 | `test_individual_registration_rejects_a_disposable_domain_in_mixed_case` | case normalisation |
| 4 | `test_individual_registration_rejects_a_subdomain_of_a_disposable_domain` | `include_subdomains` setting |
| 5 | `test_individual_registration_still_rejects_a_malformed_email` | existing `email` rule intact |
| 6 | `test_individual_registration_still_rejects_a_duplicate_email` | existing `unique` rule intact |
| 7 | `test_disposable_rejection_uses_the_existing_validation_response_structure` | 422 envelope unchanged |
| 8 | `test_organization_registration_rejects_a_disposable_email` | org flow; no user, no organisation |
| 9 | `test_organization_registration_still_succeeds_for_a_normal_email` | org flow not broken |
| 10 | `test_organization_registration_reports_both_the_disposable_email_and_the_missing_proof_file` | the rule was **added**, not substituted |
| 11 | `test_organization_contact_email_is_not_subject_to_the_disposable_check` | documents the scope decision |
| 12 | `test_google_signup_rejects_a_disposable_email` | Google create branch |
| 13 | `test_google_signup_still_creates_an_account_for_a_normal_email` | Google flow not broken |
| 14 | `test_google_login_for_an_existing_account_on_a_disposable_domain_is_not_blocked` | regression guard — existing users unaffected |
| 15 | `test_the_indisposable_rule_normalises_domain_case_on_its_own` | isolates the package's own case handling from `prepareForValidation()` |

### ⚠️ `test.com` is on the blocklist

`test.com` **is** a disposable domain. `tests/Feature/Auth/RegistrationTest.php` used it as its
fixture domain, and `test_individual_registration_success` would have started failing with 422.

Those fixtures were moved to `example.com`. The two tests that used `test.com` while expecting a
422 (`duplicate`, `missing_terms`) were still passing — but for the **wrong reason**: the address
was rejected as disposable before the rule they were actually testing was ever reached. Moving the
domain off the blocklist restores what they assert. A note explaining this was added to that file's
docblock so future test authors do not reintroduce it.

This is the only pre-existing test file that had to change. No other test posts to a registration
endpoint — every other `@test.com` in the suite is created with `User::forceCreate()` or logged in,
which never touches these rules.

---

## 9. How to test it

### Automated

```bash
php artisan test tests/Feature/Auth/DisposableEmailRegistrationTest.php
php artisan test tests/Feature/Auth/RegistrationTest.php
php artisan test                       # full suite: 788 passed (2717 assertions)
php vendor/bin/pint --test             # CI runs this on the whole project
```

### Manual — registration

```bash
curl -s -X POST https://back-end-zdip.onrender.com/api/v1/auth/register \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"name":"Test","email":"someone@mailinator.com","password":"password123",
       "password_confirmation":"password123","terms_accepted":true,"privacy_accepted":true}'
# → 422 {"message":"Disposable or temporary email addresses are not allowed.",
#         "errors":{"email":["Disposable or temporary email addresses are not allowed."]}}
```

Then repeat with `someone@gmail.com` → `201`.

Useful domains to try: `mailinator.com`, `yopmail.com`, `guerrillamail.com`, `sharklasers.com`
(all blocked), and `inbox.mailinator.com` (blocked only because `include_subdomains` is on).

### Manual — Google sign-up

`POST /api/v1/auth/login/google` with a real Google ID token whose email sits on a disposable
domain → `422` on `credential`. A token for an already-registered account is unaffected.

### Verified live (not only PHPUnit)

A feature test runs in-process with `RefreshDatabase`; it can pass while the real route, middleware
stack or rule ordering behaves differently. So the change was also proven over real HTTP against a
throwaway SQLite database (`APP_ENV=local`, `CACHE_STORE=array`, `MAIL_MAILER=log`, server on
`127.0.0.1:8899`) — with the checked-in `.env` confirmed to point at live production MySQL *first*,
and the override verified via `config:show database.default` → `sqlite` before anything was migrated.

| Probe | Result |
|---|---|
| `someone@mailinator.com` | `422` — `{"message":"Disposable or temporary email addresses are not allowed.","errors":{"email":[…]}}` |
| `someone@inbox.mailinator.com` | `422` — proves `include_subdomains=true` is load-bearing |
| `Someone@MAILINATOR.COM` | `422` — case-insensitivity through the real pipeline |
| `someone@example.com` | `201` `{success:true,…,data:{status:"pending",requires_verification:true}}` — existing flow intact |
| `not-an-email` | `422` `"The email field must be a valid email address."` — proves rule **order** is preserved |
| org endpoint, disposable **account** email | `422` with **both** `email` and `proof_file` — proves the rule was **added**, not substituted |

Database read-back: `users` held **1** row (only `someone@example.com`) and **0** disposable-domain
rows — the rejection happens before any write.

Note the envelope: `AuthController` validation returns `{message, errors}` with **no `success` key**.
That is pre-existing behaviour, not something this change introduced.

---

## 10. Operations

- **Updating the domain list.** The list is versioned with the package. Keep the dependency current
  (`composer update propaganistas/laravel-disposable-email`) and you inherit the latest list on
  deploy. Alternatively schedule the package's own command to refresh from the CDN:

  ```php
  // routes/console.php
  Schedule::command('disposable:update')->daily();
  ```

  This is **not** currently scheduled. It writes to `storage/framework/disposable_domains.json`,
  which is writable in the Docker image, but it introduces a runtime network dependency that the
  offline bundled list avoids. Bumping the package version is the recommended path.

- **Clearing the cache.** After changing `whitelist` or `include_subdomains`, the list is cached
  forever under the `disposable_email:domains` key — run `php artisan cache:clear`.

---

## 11. Limitations and edge cases

1. **List coverage is never complete.** Newly created throwaway domains are not on any list on day
   one. The optional `mx` mode (§6) is the mitigation; it is deliberately not enabled.
2. **No domain can be permanently trusted.** The upstream list is community-maintained and can
   contain false positives. Use `whitelist` — do not disable the rule.
3. **The rejection message is duplicated in three places** (two FormRequests and the service),
   because there is no `lang/` directory in this project. The idiomatic fix is
   `lang/en/validation.php` with `'indisposable' => '…'`, but **creating that file with only that
   key would replace the framework's entire `validation` array** and break every other validation
   message. Doing it properly means publishing the full framework file first. Left as a follow-up
   rather than taken on silently.
4. **`indisposable` runs a hash lookup against ~75k entries.** Sub-millisecond, and the list is
   cached. In tests `CACHE_STORE=array`, so each test re-reads the bundled 1.4 MB JSON — that is
   why the new suite takes ~7 s. Not a production concern.
5. **The Google check does not apply to `setup/create-mentor`.** By design: the admin is the
   onboarding authority for mentors. If mentor onboarding should also reject disposable addresses,
   that is a one-line addition to the inline validator in `SetupController::createMentor()` — but it
   would make the endpoint refuse an address the admin explicitly vouched for.
6. **Existing accounts are never re-validated.** An account created before this change on a domain
   that is on the list keeps working. That is intentional (no lockouts), and is covered by test 14.
