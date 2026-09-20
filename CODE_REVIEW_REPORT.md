# Security Code Review — SkillSpan Back-End

**Repository:** `SkillSpan/Back-End` (configured remote: `https://github.com/SkillSpan/Back-End.git`)
**Branch:** `feature/authentication`
**Commit:** `6e948d64925d7ba5905b968cf752048a339fa477`
**Working tree:** clean at review time
**Review type:** Phase 1 — analysis and report only. **No application code was modified.**
**Stack:** PHP `^8.2`, Laravel `12.64.0`, Sanctum `4.3.3`, google/apiclient `2.19.4`, PHPUnit `11.5.56`

---

## 1. Scope and method

Every issue below was confirmed by reading the actual source at the stated line numbers, not inferred
from the task description. Verification steps performed:

- Direct reads of all controllers, services, middleware, models, routes, config and `.env.example` listed in scope.
- `php -l` parse check across every `.php` file in `app/`, `routes/`, `config/`, `bootstrap/`, `database/` — one parse error found, reproduced below.
- `git ls-files` sweep for committed secrets; `.gitignore` inspection.
- Static inventory of the test suite (22 files, 160 `test_*` methods) and grep for coverage of each finding.

**Not performed (deliberately):** no application execution, no `artisan`/PHPUnit run, no database or cache
mutation, no `.env`, log or SQLite content inspection, no broad vendor search. Findings marked
*"requires confirmation"* are the only ones where runtime behaviour was not exercised.

> **Remote verification limitation.** The GitHub API returns **404** for `SkillSpan/Back-End` (both
> `list_branches` and `get_commit` for the HEAD SHA). This means either the repository is private and the
> connected token lacks access, or the owner/name differs from what the local remote records. **All findings
> therefore describe the local checkout at `6e948d6`, not a verified latest GitHub state.**

---

## 2. Severity summary

| # | Severity | Finding | File |
|---|----------|---------|------|
| 1 | **Critical / Blocking** | Invalid PHP — evidence controller cannot be parsed or loaded | `EvidenceController.php` |
| 2 | **High** | Broken access control (IDOR) — any user can write any learner's skill records | `SkillsController.php` |
| 3 | **High** | Suspension bypass — OTP verification silently reactivates suspended accounts | `AuthService.php` |
| 4 | **High** | Suspension not enforced on already-issued Sanctum tokens | Middleware + routes |
| 5 | **Medium** | Organization admin check evaluated against the wrong organization | `OrganizationController.php` |
| 6 | **Medium** | Registration response contradicts behaviour (`requires_verification` vs. no OTP sent) | `AuthController.php` / `AuthService.php` |
| 7 | **Medium** | Account-existence enumeration via OTP resend responses | `AuthController.php` |
| 8 | **Medium** | Transaction left open when Google login is rejected mid-transaction | `AuthService.php` |
| 9 | **Medium** | Client-controlled, unvalidated `calculated_at` written to skill records | `SkillsController.php` |
| 10 | **Medium** | `source_contributions` double-encoded by `array` cast | `SkillsController.php` |
| 11 | **Low** | Non-constant-time comparison of the internal shared secret | `Internal/BaselineItemsController.php` |
| 12 | **Low** | CI does not run on feature branches or PRs targeting `develop` | `.github/workflows/ci.yml` |

**Totals:** 1 Critical (Blocking) · 3 High · 6 Medium · 2 Low.

---

## 3. Findings

### [CRITICAL / BLOCKING] Invalid PHP — evidence endpoints cannot load

**File:** `app/Http/Controllers/Api/EvidenceController.php:265-283` (parse error at line 276)

**Issue:** The `review()` method opens `return response()->json([` and then, *before* the array is closed,
inserts a second statement (`$this->skillEvaluationService->recalculate(...)`) followed by a **second**
`return response()->json([...])`. The first array literal is never terminated. Independently, the file
uses two classes it never imports:

- `SkillEvaluationService` — used at line 14 in the constructor signature, not imported (imports end at line 8).
- `Skill` — used at line 131 (`Skill::find($skillId)`), not imported.

```php
return response()->json([
    'success' => true,
    'message' => 'Evidence ' . strtolower($request->verification_status) . ' successfully.',
/*
 * Recalculate skill level and confidence after
 * the evidence verification status changes.
 */
$this->skillEvaluationService->recalculate(   // <-- statement inside the array
    $evidence->studentProfile,
    $evidence->skill
);

return response()->json([                      // <-- second return inside the array
    ...
]);
```

**Verification:** `php -l app/Http/Controllers/Api/EvidenceController.php` →
`PHP Parse error: syntax error, unexpected token ";", expecting "]" ... on line 276`.
A parse sweep of the entire application found this as the **only** syntax error, so the impact is isolated
to this controller rather than a repo-wide build break.

**Attack scenario:** Not directly exploitable — it is an availability defect. Because PHP parses a file
before executing it, **every** route handled by this controller (`POST /api/v1/evidence`,
`GET /api/v1/evidence`, `GET /api/v1/evidence/{id}`, `PUT /api/v1/evidence/{id}/review`) fails at load
time. In a default Laravel 12 configuration this surfaces as an unhandled `ParseError`/500, which both
breaks the evidence-submission feature outright and, if `APP_DEBUG=true` in any deployed environment,
discloses full file paths and source excerpts. Note the evidence-submission path is also a documented
input to skill-level calculation, so this defect silently blocks the whole evidence → skill pipeline.

**Fix:** Remove the incomplete first `return` block entirely (lines 265-267 and the stray statement at
272-275), keep the single well-formed return at 277-283, and restore the two missing imports after line 5:

```php
use App\Models\Skill;
use App\Services\Skills\SkillEvaluationService;
```

Then re-run `vendor/bin/pint --test` and the test suite. A regression test for `PUT /api/v1/evidence/{id}/review`
must exist before this is considered closed — see §5.

**Estimated change:** ~1 file, ~8 lines removed / 2 lines added.

---

### [HIGH] Broken access control (IDOR) — any authenticated user can modify any learner's skill records

**File:** `app/Http/Controllers/Api/SkillsController.php:80-111` (store) and `117-145` (update)
**Route config:** `routes/api.php:75-80`

**Issue:** Both write endpoints are protected by authentication only, and neither verifies that the caller
owns the record being written.

- `store()` (line 83) validates `learner_id` with `exists:users,id` — it accepts **any** user id — then
  (lines 93-104) performs `LearnerSkill::updateOrCreate(['learner_id' => $request->learner_id, ...])`.
- `update()` (line 119) does `LearnerSkill::findOrFail($id)` and then writes client-supplied `level`,
  `confidence_score` and provenance fields, with no ownership or role check anywhere in the method.
- `routes/api.php:75` wraps the whole skills group in `->middleware('auth:sanctum')` only. Contrast with
  the evidence group at `routes/api.php:83-91`, which adds `role:learner` / `role:admin` per route.

Note that the **read** path was hardened — `matrix()` at lines 42-74 explicitly resolves
`$user->id` for non-admins and documents the previous leak — but the equivalent fix was never applied to
the write paths. The read-side fix therefore gives a false impression that this endpoint is safe.

**Attack scenario:** A logged-in learner with a valid Sanctum token calls
`POST /api/v1/skills/matrix` with `{"learner_id": <victim_id>, "skill_id": 12, "level": 5, "confidence_score": 100}`.
The victim's skill level and confidence are overwritten (or created) and `calculated_at` is refreshed, so
the tampering looks like a legitimate recalculation. A second call to `PUT /api/v1/skills/matrix/{id}`
allows the same on any existing row by iterating ids. The attacker can inflate a competitor's or deflate
their own displayed competencies, and can forge `algorithm_version` / `configuration_version` /
`source_contributions` so the altered values appear to be algorithm output. This is a direct integrity
compromise of the platform's core asset — the verified skill matrix — with no trace of who wrote it.

**Fix:** Derive the target learner from the authenticated principal rather than trusting the payload, and
restrict cross-learner writes to administrators:

```php
$user = $request->user();
$isAdmin = $user->hasRole('admin');

// Non-admins may only ever write their own row.
$targetLearnerId = $isAdmin
    ? (int) $request->input('learner_id')
    : $user->id;

abort_if(! $isAdmin && (int) $request->input('learner_id') !== $user->id, 403);
```

For `update()`, load the row scoped to the caller before mutating:

```php
$query = LearnerSkill::query();
if (! $request->user()->hasRole('admin')) {
    $query->where('learner_id', $request->user()->id);
}
$learnerSkill = $query->findOrFail($id);   // 404 for other users' rows — no existence disclosure
```

Prefer an explicit policy (`LearnerSkillPolicy`) over inline role checks so the rule is enforced
consistently as more write endpoints appear. Also consider adding `->whereNull('deleted_at')`-equivalent
handling, since `exists:users,id` does not apply the soft-delete scope and therefore accepts ids of
soft-deleted users.

**Estimated change:** ~1 controller file, ~20-30 lines; plus 1 new policy class and ~4 tests.

---

### [HIGH] Suspension bypass — OTP verification silently reactivates a suspended account

**File:** `app/Services/AuthService.php:487-551` (`verifyOtp`), `553-565` (`resendOtp`)
**Related:** `app/Http/Controllers/Api/AuthController.php:106-144`

**Issue:** `verifyOtp()` locates the user by email with no account-state check, and on a correct OTP
unconditionally writes the account back to active:

```php
// AuthService.php:538-541
$user->forceFill([
    'email_verified_at' => now(),
    'status' => 'active',        // <-- no check on the prior status
])->save();
```

The only guards on the challenge are `decision = 'pending'`, expiry (line 508) and the attempt cap
(line 516). `resendOtp()` (lines 553-565) likewise invalidates previous challenges and issues a new one
for **any** user object it is given, with no status check — and `AuthController::resendOtp()` (line 110)
looks the user up by email without filtering on status either.

The `status` column is a real, intended lifecycle field —
`database/migrations/2026_01_01_000000_create_users_table.php:19` defines
`enum('status', ['active', 'suspended', 'pending', 'deleted'])` — so `suspended` is a state the system
means to enforce. Login *is* gated (`AuthController.php:244-248` requires `status === 'active'`), but that
gate is bypassed by this path.

**Attack scenario:** An administrator suspends a user for abuse. The user (who still controls their
mailbox) calls `POST /api/v1/auth/resend-otp` with their email — throttled at 10/min by
`routes/api.php:22` but otherwise unrestricted — receives a fresh OTP, and calls `POST /api/v1/auth/verify`
with it. `verifyOtp()` sets `status = 'active'`, and the user then logs in normally with their existing
password. The administrative action is silently undone by a public, unauthenticated endpoint. The same
endpoint also allows an already-verified account to be re-verified at will.

**Fix:** Separate *mailbox ownership* from *account standing*. Email verification should assert the
pending→active transition only, and must never clear a suspension:

```php
// In verifyOtp(), before consuming the challenge:
if ($user->status === 'suspended' || $user->status === 'deleted') {
    DB::rollBack();

    return false;   // identical outcome to a wrong OTP — no state disclosure
}

// ...and on success, do not blindly force status:
if ($user->status === 'pending') {
    $user->status = 'active';
}
$user->email_verified_at = now();
$user->save();
```

Mirror the same guard at the top of `resendOtp()` and in `AuthController::resendOtp()` so a suspended
account is never issued a new challenge. Make the refusal indistinguishable from the existing
"invalid or expired code" response so the endpoint does not become an account-state oracle.

**Estimated change:** ~2 files, ~15-20 lines; ~4 tests (suspended user cannot verify, cannot resend, cannot
be reactivated; pending user still activates).

---

### [HIGH] Suspension is not enforced on already-issued Sanctum tokens

**File:** `app/Http/Middleware/EnsureUserHasRole.php:25-33`, `app/Http/Middleware/EnsureUserIsAdmin.php:19`,
`app/Http/Middleware/EnsureOrganizationIsApproved.php:22-24`
**Related:** `routes/api.php:75-101` (routes guarded by `auth:sanctum` alone)

**Issue:** The two role middlewares check only soft deletion and role membership:

```php
// EnsureUserHasRole.php:27
if ($user->trashed()) { /* 403 */ }
// EnsureUserIsAdmin.php:19
if (! $user || $user->trashed() || ! $user->hasRole('admin')) { /* 403 */ }
```

Neither inspects `$user->status`. Sanctum's token guard authenticates purely from
`personal_access_tokens` and has no notion of application account standing, so no layer rejects a token
belonging to a suspended user. Worse, several route groups have **no** role middleware at all — for
example the skills group (`routes/api.php:75`) and `GET /api/v1/evidence/{id}`
(`routes/api.php:88`) sit behind `auth:sanctum` only, so for those routes not even the `trashed()` check
runs.

**Attack scenario:** A user is suspended while holding a valid token (tokens are long-lived — the
configured expiration is 14 days, `config/sanctum.php:53`). Their in-flight token continues to
authenticate: they can read and write the skill matrix, read evidence records, and call
`GET /api/v1/organization/profile`. Suspension therefore only blocks *new* logins, not the session that
was already open, and the account remains fully functional for up to two weeks. Combined with finding #3,
the user can also simply reactivate the account outright.

**Fix:** Enforce account standing centrally rather than per-controller — a single guard covers every
current and future route, which per-route checks cannot:

```php
// app/Http/Middleware/EnsureAccountIsActive.php
public function handle(Request $request, Closure $next): Response
{
    $user = $request->user();

    if ($user && ($user->trashed() || $user->status !== 'active')) {
        $user->tokens()->delete();      // revoke immediately — suspension must end the session

        return response()->json([
            'code' => 'ACCOUNT_DISABLED',
            'message' => 'This account is no longer active.',
        ], 403);
    }

    return $next($request);
}
```

Register it in `bootstrap/app.php` and append it to every `auth:sanctum` group (or apply it globally to
authenticated routes). Additionally, when an administrator suspends a user, revoke their tokens and mark
`auth_sessions.revoked_at` in the same transaction — `AuthSession` already exists and is maintained on
login/logout, so this extends an existing pattern rather than inventing one.

**Estimated change:** 1 new middleware, ~5 route groups touched, ~1 admin-action hook; ~4 tests.

---

### [MEDIUM] Organization admin check is evaluated against the wrong organization

**File:** `app/Http/Controllers/Api/OrganizationController.php:23-41`

**Issue:** The controller resolves the organization to return, then independently checks admin rights —
but the two queries are not linked:

```php
$organization = $request->user()->organizations()->first();   // line 23

$isAdmin = $request->user()->organizations()                  // line 39
    ->wherePivot('role_in_org', 'admin')
    ->exists();                                               // <-- any linked organization
```

`$organization` is "the first linked organization", while `$isAdmin` is "administers *some* linked
organization". Nothing constrains them to the same row, and neither query filters on the membership
`status` pivot column — so a membership marked `removed` still satisfies the admin check.

**Attack scenario:** The schema permits multiple memberships per user
(`User::organizations()` at `app/Models/User.php:48-53` is a `belongsToMany` over
`organization_members`, with `role_in_org` and `status` pivots). Consider a user who is a plain `member`
of organization A but an `admin` of organization B. They call `GET /api/v1/organization/profile`; the
`first()` call returns organization A, the `exists()` call is satisfied by organization B, and the endpoint
discloses organization A's contact email, phone, website, address, country, city and industry to a
non-admin of A. A user whose admin membership was set to `removed` retains the same access. The
`organization.approved` middleware (`EnsureOrganizationIsApproved.php`) gates on approval status only and
does not distinguish which organization is being read.

**Fix:** Make the returned organization and the permission check the same row, and require an active
membership:

```php
$organization = $request->user()->organizations()
    ->wherePivot('role_in_org', 'admin')
    ->wherePivot('status', 'active')
    ->first();

if (! $organization) {
    return response()->json([
        'success' => false,
        'message' => 'Only an organization admin can view this profile.',
    ], 403);
}
```

If the product intends a user to administer several organizations, replace the implicit `first()` with an
explicit target (`/organization/{organization}/profile`) and authorize that specific id — never rely on
"first" plus a separate existence check. Apply the same fix to the other `->organizations()->first()`
calls that gate behaviour: `AuthController.php:253`, `AuthController.php:280`,
`AuthService.php:309`, and `EnsureOrganizationIsApproved.php:22`.

**Estimated change:** ~1 controller, ~8 lines; ~3 tests (multi-org member/admin, removed admin,
non-admin member).

---

### [MEDIUM] Registration response contradicts behaviour — `requires_verification: true` but no OTP is sent

**File:** `app/Http/Controllers/Api/AuthController.php:60-77` and `app/Services/AuthService.php:63-94`

**Issue:** `registerOrganization()` in the service deliberately skips the OTP step and marks the account
verified immediately:

```php
// AuthService.php:79-82
$user->forceFill([
    'email_verified_at' => now(),
    'status' => 'active',
])->save();
```

The surrounding comment (lines 73-78) documents this as an intentional decision. However the controller
that returns the API response tells the client the opposite:

```php
// AuthController.php:66
'message' => '... Your proof document will be reviewed and you need to verify your email.',
// AuthController.php:71
'requires_verification' => true,
// AuthController.php:72-74
'resend_available_at' => now()->addSeconds(...)->toIso8601String(),
```

**Attack scenario:** Not a direct exploit, but a functional and trust defect with a security-adjacent
consequence. A frontend that trusts `requires_verification: true` routes the newly registered organization
admin into an OTP screen for which **no code was ever issued** — `resend_available_at` even tells it when
it may request one. The user is locked out of an onboarding flow that cannot complete, and the first
support action is likely to be a manual account fix. Separately, this is a security *design* concern worth
an explicit decision: mailbox ownership is asserted without proof for organization accounts, so the only
barrier is the manual proof-document review. If that review ever approves an organization whose contact
email was mistyped or is attacker-controlled, the account is already active and verified.

**Fix:** Align the contract with the behaviour — either

1. **Keep the OTP-free flow (current behaviour):** return `'requires_verification' => false` and remove
   `resend_available_at`, with a message stating that access awaits organization approval; or
2. **Restore verification for organizations:** drop the `forceFill` block so the account stays `pending`
   until the emailed OTP is confirmed, matching the individual-registration flow.

Option 2 is the stronger choice, since it establishes mailbox ownership independently of the manual
document review. Whichever is chosen, add an assertion so the response and the service cannot drift apart
again, and make the decision explicit in the SRS.

**Estimated change:** ~2 files, ~6-10 lines; ~2 tests.

---

### [MEDIUM] Account-existence enumeration via OTP resend responses

**File:** `app/Http/Controllers/Api/AuthController.php:106-144`
**Related:** `app/Http/Requests/Auth/ResendOtpRequest.php:16-21`

**Issue:** The endpoint is explicitly written to be neutral for unknown addresses — `ResendOtpRequest`
omits `exists:users,email` with a comment explaining that this is to avoid leaking which emails are
registered, and the controller returns a generic success message at lines 112-117. But the three branches
are externally distinguishable:

| Condition | Status | Body |
|---|---|---|
| Unknown email | 200 | `success: true`, generic message, **no `data` key** |
| Known email, outside cooldown | 200 | `success: true`, different message, `data.resend_available_at` |
| Known email, inside cooldown | **429** | `success: false`, `data.retry_after` |

The 200-vs-429 split is the sharpest signal: the `RateLimiter` key is derived from the **user id**
(`AuthController.php:119`, `'resend-otp:'.$user->id`), which only exists once the email has been resolved
to a real account. An unknown email therefore can never return 429, no matter how many requests are sent.
The differing success messages and the presence/absence of `data` give a second, independent signal.

**Attack scenario:** An attacker iterates a list of candidate addresses against
`POST /api/v1/auth/resend-otp`. A 429 response, or a 200 carrying `data.resend_available_at`, confirms the
address belongs to a registered account; a bare 200 without `data` indicates it does not. The route
throttle of 10/min (`routes/api.php:22`) limits throughput but not the technique — the attacker simply
paces requests, and each request yields one bit of high-value information. The result is a validated list
of registered emails, which is directly useful for credential-stuffing and phishing, and is especially
sensitive here because organization accounts are tied to identifiable institutions.

**Fix:** Make the response shape and status identical across all three branches. Key the rate limiter on
the *request* rather than the resolved user, so cooldown behaviour cannot depend on account existence:

```php
$key = 'resend-otp:'.hash('sha256', strtolower(trim($request->input('email'))).'|'.$request->ip());
```

Then return the same 200 payload — same message, same `data.resend_available_at` — regardless of whether
the account exists. When the address is unknown, skip the send but still report the cooldown window.
Apply the same treatment to the password-reset resend path (`PasswordResetTest` already asserts neutrality
for unknown emails there; the OTP path lacks the equivalent test).

**Estimated change:** ~1 controller, ~15 lines; ~2 tests (unknown vs. known response parity, including the
cooldown case).

---

### [MEDIUM] Transaction left open when Google login is rejected mid-transaction

**File:** `app/Services/AuthService.php:147` (begin), `187` (call), `315-324` (throw), `255-256` (catch)

**Issue:** `loginWithGoogle()` opens a transaction at line 147, then calls
`$this->assertOrganizationIsApproved($user)` at line 187. That helper throws `ValidationException` for a
pending (line 315-318) or rejected (line 321-324) organization. The catch chain at the bottom of the
method handles this case first and rethrows **without** rolling back:

```php
} catch (ValidationException $e) {
    throw $e;                 // <-- no DB::rollBack()
} catch (Throwable $e) {
    DB::rollBack();
    ...
}
```

Every other rejection path inside the transaction does roll back explicitly (lines 167, 180, 208), which
makes this an inconsistency rather than a deliberate choice — the `ValidationException` branch was written
to avoid double-logging the exception, and the rollback was lost with it.

**Attack scenario:** Not directly exploitable. The concrete risk is connection-state corruption under
connection reuse: on a persistent connection (PHP-FPM, Octane, queue workers, or a pooled driver) the
uncommitted transaction is returned to the pool still open, so a later, unrelated request can run inside
it and be silently committed or discarded with it. The reachable trigger is a login attempt by a
pending/rejected organization admin via the Google flow, which is an ordinary user action, not an attack.
It also means any writes performed earlier in the transaction are held open for the request duration.

**Fix:** Restructure so rollback cannot be skipped — a closure-based transaction makes the guarantee
structural rather than dependent on remembering it in every catch branch:

```php
return DB::transaction(function () use (...) {
    // ... all logic; throw ValidationException freely
});
```

`DB::transaction()` rolls back on any exception, including `ValidationException`. If the existing
try/catch structure is kept, add `DB::rollBack()` to the `ValidationException` branch at line 255-256.

**Estimated change:** ~1 method refactor, ~10-20 lines; ~1 test (pending organization via Google login
leaves no open transaction and creates no rows).

---

### [MEDIUM] Client-controlled, unvalidated `calculated_at` written to skill records

**File:** `app/Http/Controllers/Api/SkillsController.php:130-138`

**Issue:** The `update()` validation rules (lines 121-128) cover `level`, `confidence_score`,
`source_type`, `algorithm_version`, `configuration_version` and `source_contributions` — but **not**
`calculated_at`. The subsequent write pulls `calculated_at` from the raw request:

```php
$learnerSkill->update($request->only([
    'level', 'confidence_score', 'source_type', 'algorithm_version',
    'configuration_version', 'source_contributions',
    'calculated_at',            // <-- not in $request->validate(), not a validated field
]));
```

`$request->only()` reads from the full input bag, not from validated data, so this field bypasses
validation entirely. `calculated_at` is also listed in `LearnerSkill::$fillable`
(`app/Models/LearnerSkill.php:19`), so the assignment succeeds.

**Attack scenario:** A caller sends `calculated_at: "2000-01-01T00:00:00Z"` alongside a modified `level`.
The stored provenance timestamp no longer reflects when the value was computed, so any downstream logic or
audit that orders or filters by `calculated_at` can be manipulated — for example making a freshly forged
score appear stale (and therefore ignorable) or making an old score appear current. It also allows the
field to be set to an arbitrary string that fails to cast, producing inconsistent rows. This compounds
finding #2: even after ownership is enforced, a learner can still misrepresent when their own scores were
computed.

**Fix:** Never write a server-managed timestamp from client input. Set it server-side and remove it from
the accepted payload:

```php
$learnerSkill->update([
    ...$request->safe()->only([
        'level', 'confidence_score', 'source_type',
        'algorithm_version', 'configuration_version', 'source_contributions',
    ]),
    'calculated_at' => now(),
]);
```

Prefer `$request->validated()` / `$request->safe()->only()` over `$request->only()` throughout, so a
newly added request field can never be silently mass-assigned before a rule exists for it.

**Estimated change:** ~1 controller, ~6 lines; ~1 test.

---

### [MEDIUM] `source_contributions` double-encoded by the `array` cast

**File:** `app/Http/Controllers/Api/SkillsController.php:90` (rule) and `101` / `127` / `136` (writes)
**Related:** `app/Models/LearnerSkill.php:22-25`

**Issue:** The request validates `source_contributions` as a **JSON string** (`'sometimes|required|json'`,
line 90), while the model casts it to `array` (`'source_contributions' => 'array'`). Eloquent's `array`
cast JSON-encodes the value on write. Assigning an already-encoded string therefore encodes it a second
time: the payload `{"a":1}` is persisted as the JSON *string* `"{\"a\":1}"` rather than as a JSON object.
Reading the attribute back decodes one layer and yields a string, not the array callers expect.

**Attack scenario:** No security impact. This is a data-integrity defect: the stored representation does
not match the column's intent, so consumers that expect an object receive a string, and any query or
comparison on `source_contributions` behaves inconsistently depending on which write path created the row.
Because the read path and the write path disagree, the bug is easy to miss until a consumer fails.

**Fix:** Accept a structured payload rather than a string, and let the cast own the encoding:

```php
'source_contributions' => ['sometimes', 'array'],
```

and pass `$request->validated('source_contributions')` directly. If a JSON-string input must be tolerated
for backwards compatibility, decode it once before assignment
(`json_decode($value, true, 512, JSON_THROW_ON_ERROR)`) instead of handing the raw string to the cast.

> **Requires confirmation:** I have not executed this path. The behaviour follows deterministically from
> Eloquent's `array` cast, but confirm with a focused test that asserts the round-tripped value is an
> array — the test doubles as the regression guard.

**Estimated change:** ~1 controller, ~3 lines; ~1 test.

---

### [LOW] Non-constant-time comparison of the internal shared secret

**File:** `app/Http/Controllers/Api/Internal/BaselineItemsController.php:25`

**Issue:** The service-to-service credential is compared with `!==`:

```php
if (blank($expectedSecret) || $request->header('X-Internal-Secret') !== $expectedSecret) {
```

String comparison short-circuits on the first differing byte, so response timing varies with the length of
the matching prefix. The endpoint also returns `correct_answer` for every item (line 45), making it a
high-value target. Positively, the check fails closed when the secret is unset (`blank()`), and the route
is throttled at 60/min (`routes/api.php:119`). Compare with the constant-time check already used in
`SetupController.php:37` for `ADMIN_SETUP_SECRET` — the codebase already knows the correct pattern.

**Attack scenario:** A network-adjacent attacker measures response times to recover the secret
byte-by-byte. Practical exploitation is limited by network jitter, the 60/min throttle and the secret's
entropy; this is a hardening item, not a live break.

**Fix:** Use the same primitive as `SetupController`:

```php
$provided = (string) $request->header('X-Internal-Secret');

if (blank($expectedSecret) || ! hash_equals($expectedSecret, $provided)) {
```

Consider additionally restricting the route to internal network ranges and rotating the secret.

**Estimated change:** ~1 file, 1 line.

---

### [LOW] CI does not run on feature branches or PRs targeting `develop`

**File:** `.github/workflows/ci.yml:3-7`

**Issue:** The workflow triggers on `push` to `main` and `pull_request` targeting `main` only. Work pushed
to `feature/authentication` — the branch under review — or to `develop` never runs Pint or PHPUnit. The
cached refs show `origin/develop` and a local `testapi` branch diverging from `main`, so these are active
lines of development.

**Attack scenario:** Not exploitable. It is a process gap with a direct security consequence: this is
precisely how the finding #1 parse error reaches a shared branch — a `php -l` check or the existing test
suite would have caught it immediately, but neither runs on this branch.

**Fix:** Broaden the triggers and add a parse gate:

```yaml
on:
  push:
    branches: [main, develop, 'feature/**']
  pull_request:
    branches: [main, develop]
```

Add an early `find app routes config database -name '*.php' -exec php -l {} \;` step (or
`composer validate` plus `vendor/bin/pint --test`) so syntax errors fail the build before tests run.
Consider a coverage threshold and a static-analysis job as follow-ups.

**Estimated change:** ~1 file, ~8 lines.

---

## 4. Checked and found NOT to be issues

Recording these so the review is not re-litigated, and so genuinely mitigated areas are not re-flagged:

- **CORS** — `config/cors.php:24` reads allowed origins from `CORS_ALLOWED_ORIGINS` and never uses `*`;
  `supports_credentials` is `true`, which would be rejected by browsers alongside a wildcard anyway. The
  file's own comment documents this. No issue.
- **Secrets in the repository** — `git ls-files` shows only `.env.example` tracked; `.env` and variants are
  gitignored (`.gitignore:3-5`). `.env.example` contains no populated credentials. No issue.
- **`SetupController` secret handling** — fails closed when unset (`SetupController.php:30-35`), uses
  `hash_equals` (line 37), throttled at 5/min, and covered by tests including a brute-force test. Good.
- **Password hashing** — `User::$casts` applies `'password' => 'hashed'` (`app/Models/User.php:38`) and
  `password`/`remember_token` are in `$hidden` (lines 28-31). `Hash::check` is used for verification
  throughout. No md5/sha1/plaintext anywhere.
- **Password reset token lifecycle** — tokens are hashed at rest, time-limited, single-use
  (`consumed_at` set on success, `AuthService.php:746-748`), share an attempt counter between verify and
  reset, and a successful reset revokes all Sanctum tokens and marks `auth_sessions.revoked_at`
  (lines 740-744). Solid.
- **Login throttling** — per-email+IP limiter with a 429 and `Retry-After` header
  (`AuthController.php:213-230`), plus a route-level `throttle:20,1`. Covered by `LoginThrottleTest`.
- **Baseline assessment authorization** — every route resolves the model from the authenticated user's
  profile and returns 404 (not 403) for non-owners (`BaselineAssessmentController.php:60, 81, 115, 143-147`),
  avoiding existence disclosure. Good.
- **Profile endpoints** — no `{user}` route parameter exists; the profile is always resolved from
  `$request->user()` (`ProfileController.php:45, 99, 120`). No IDOR.
- **File upload validation** — organization proof files are restricted by mime type and size
  (`RegisterOrganizationRequest.php:51`). Reasonable.
- **Mass assignment on `User`** — `$fillable` (lines 18-26) excludes `status`, `google_id` and
  `email_verified_at`, and privileged paths use `forceFill`/`forceCreate` deliberately. No user-facing
  privilege escalation via mass assignment.
- **Sanctum token expiry** — configured at 14 days (`config/sanctum.php:53`) rather than non-expiring.
- **`LearnerSkill` mass assignment** — `$fillable` is broad, but the exploitable consequence is
  finding #2/#9, not a separate issue.

---

## 5. Test coverage gaps

Inventory: **22 test files, 160 `test_*` methods**. Coverage is good for auth flows and weak exactly where
the findings are:

| Gap | Detail |
|---|---|
| **No evidence tests at all** | There is no `tests/Feature/Evidence*` file. The controller that fails to parse (finding #1) has zero tests, which is why it shipped. |
| **Skill writes untested** | `tests/Feature/Skills/SkillsMatrixTest.php` covers only `GET` (lines 71-193). No test posts or puts a skill record, so the IDOR (finding #2) is invisible to the suite. |
| **No suspension tests** | A grep for `suspended` across `tests/` returns nothing. Findings #3 and #4 are entirely uncovered. |
| **Resend neutrality incomplete** | `VerificationTest.php:144-157` asserts neutrality for an *unknown* email but never compares it against the *known*-email response or the cooldown case, so finding #7 passes unnoticed. |
| **Organization membership edge cases** | `OrganizationProfileTest.php:44-88` covers a verified admin and an account with no organization — but not a non-admin member, a multi-organization user, or a `removed` membership (finding #5). |
| **Google transaction** | `GoogleLoginTest.php:48-54` stubs token verification for flow tests; no test asserts the rejection paths leave the transaction closed (finding #8). |

Recommended additions, in priority order: evidence endpoint tests (store/index/show/review), skill write
authorization tests (self allowed, other-user denied, admin allowed), suspension tests, resend response
parity, multi-organization authorization, and a transaction-state assertion for the Google rejection path.

---

## 6. Input needed before this review is complete

Two items require your decision, per the "ask rather than guess" rule:

1. **Branch/repository identity — blocking my remote verification.** The GitHub API returns 404 for
   `SkillSpan/Back-End`, and for commit `6e948d6`. Is this branch in that repository? If the repository is
   private under a different owner/name, or the connected GitHub account needs access granted, please
   confirm — otherwise everything here describes the local checkout only, and I cannot tell you whether
   the same defects exist on the current remote branch.
2. **Organization registration intent (finding #6).** Was removing the OTP step from organization
   registration a deliberate product decision (as the code comment at `AuthService.php:73-78` claims), or
   should organization accounts verify their email like individuals? The fix differs depending on the
   answer.

**Also missing:** the original task message was truncated mid-sentence at *"Approximate number of"*, and
the **Phase 2 instructions never arrived**. I have included per-finding change estimates on the assumption
that the truncated clause asked for implementation size, but please confirm — and send the Phase 2 steps
before I proceed to any code changes.

---

## 7. Phase status

**Phase 1 — analysis and report: COMPLETE.** No application file was modified. The only file written is
this report. Verified via `git status` that the working tree contains no changes to application code.

**Phase 2 — not started.** Awaiting the missing instructions and the two confirmations in §6.

---

### Summary

**12 issues: 1 Critical/Blocking, 3 High, 6 Medium, 2 Low.** The critical item (a controller that cannot
be parsed) is a two-line-plus-imports fix and is almost certainly why the branch is failing. The three
High findings are all genuine authorization/lifecycle defects, not theoretical: cross-user writes to the
skill matrix, suspension that can be self-reversed through a public endpoint, and suspension that is never
enforced against an already-issued token. **I need your input on the branch identity and the organization
registration intent before finishing — and the Phase 2 instructions are still missing.**
