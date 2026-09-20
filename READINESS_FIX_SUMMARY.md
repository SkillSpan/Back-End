# Readiness test failures + `artisan serve` — diagnosis and fix

**Branch:** `feature/authentication` @ `eb0b182`
**Environment:** `E:\SkillSpan\Back-End-feature-authentication`
**Result:** `php artisan test` went from **3 failed / 198 passed** to **201 passed (787 assertions)**, 0 failures.
Pint: PASS on every touched file.

---

## 1. The three failing tests

All three failures came from a **single incomplete merge**, not from three separate bugs.

```
eb0b182  Merge branch 'feature/authentication' ...          <- E: HEAD (local)
1f40fe1  feat(readiness): exclude unavailable components and mark result provisional
d025485  Merge branch 'feature/authentication' ...          <- origin/Feature/authentication
0c83d87  feture(Data Since) : add new endpoints for skills
397951b  security: fix IDOR, suspension bypass, OTP enumeration, ...
```

`1f40fe1` was branched from `397951b` — **before** `0c83d87` landed. So its author never saw the
skill-match contract change, and the merge at `eb0b182` combined the two sides without reconciling
the test expectations.

### 1a. `is_provisional` never reached the API (2 of the 3 failures)

`1f40fe1` added `is_provisional` to a **new** resource file, but wrote it to the wrong directory:

| Path | Namespace declared | PSR-4 target for `App\Http\Resources\*` |
|---|---|---|
| `app/Http/Controllers/Resources/ReadinessResultResource.php` | `App\Http\Resources` | ✗ never autoloaded |
| `app/Http/Resources/ReadinessResultResource.php` | `App\Http\Resources` | ✓ what the controller loads |

`composer.json` maps `"App\\": "app/"`, so `ReadinessController`'s
`use App\Http\Resources\ReadinessResultResource;` resolved to the **old** file, which has no
`is_provisional` key. The new file was dead code, and `$response->json('data.is_provisional')`
returned `null` — hence *"Failed asserting that null is true."*

**Fix** — moved the change to the path PSR-4 actually resolves, and removed the dead file:

```diff
  'critical_cap_applied' => (bool) $this->critical_cap_applied,
+ 'is_provisional' => (bool) $this->is_provisional,
  'critical_skill_readiness_cap' => $fastApiResult['critical_skill_readiness_cap'] ?? null,
```

The file now hashes to `ca62eac` — byte-identical to what `1f40fe1` originally wrote, just in the
right place. `app/Http/Controllers/Resources/` (containing only that file) is deleted.

### 1b. The score expectation was stale (1 of the 3 failures)

`0c83d87` **deliberately** changed where the composite's `skill_match` component comes from:

```diff
- $skillMatch = (float) $result['base_readiness_score'];
+ /*
+  * Contract update (Data Science, skill-match-v1): the readiness
+  * composite's skill_match component must come from this
+  * deterministic local calculation, not from FastAPI's
+  * base_readiness_score. ...
+  */
+ $skillMatchResult = $this->skillMatchService->calculate($payload);
+ $skillMatch = (float) $skillMatchResult['skill_match_score'];
```

That commit's only changes to `ReadinessTest.php` were **Pint formatting** — the score expectation
was never updated, so it has been failing on `origin/Feature/authentication` ever since.

For the test fixture (`SQL 2.5/4.0 w.45`, `Python 3.5/4.0 w.30`, `Power BI 4.0/4.0 w.25`):

```
weighted achieved = 2.5*.45 + 3.5*.30 + 4.0*.25 = 3.175
weighted required = 4.0*.45 + 4.0*.30 + 4.0*.25 = 4.000
skill_match_score = 3.175 / 4.000 * 100 = 79.38     (verified via SkillMatchService)
```

So the composite is `79.38*.65 + 10 + 9 + 5 = 75.597` → **75.6**, not 76.0. The three stale
expectations were corrected (the code is the documented contract; the tests were behind it):

| Test | Was | Now | Why |
|---|---|---|---|
| `successful readiness calculation saves result` | `76.0` / db `76.00` | `75.6` / `75.60` | skill_match 79.38, not 80.0 |
| `missing practical experience is provisional not blocked` | `82.5` | `82.0` | `(51.60+9+5)/0.80` |
| `missing baseline assessment is provisional not blocked` | `74.44` | `74.0` | `(51.60+10+5)/0.90` |

Also strengthened the happy-path assertion, because `assertFalse(null)` **passes** and would not have
caught this class of bug:

```php
$this->assertArrayHasKey('is_provisional', $response->json('data'));
$this->assertFalse($response->json('data.is_provisional'));
```

> **If the team actually wants `skill_match` to come from FastAPI's `base_readiness_score`**, that is
> a one-line revert in `ReadinessService::calculate()` plus restoring `76.0 / 82.5 / 74.44`. I kept
> the committed, explicitly documented behaviour rather than reverting a deliberate contract change.

---

## 2. `php artisan serve` — "Failed to listen on 127.0.0.1:8000" (8000 → 8010)

**Not a code problem, and it is not happening now.** Verified in `E:`:

```
INFO  Server running on [http://127.0.0.1:8000].
HTTP 200
```

`Laravel\...\Console\ServeCommand`:

```php
protected function port() { return ($port ?: 8000) + $this->portOffset; }

protected function canTryAnotherPort()
{
    return is_null($this->input->getOption('port')) &&
        ($this->input->getOption('tries') > $this->portOffset);   // --tries defaults to 10
}
```

So when the child `php -S` process cannot bind, Laravel silently walks **8000 → 8010** (11 attempts)
and then gives up. The 11 repeated messages in the log mean the child PHP server failed to bind on
every one of those ports at that moment — the message itself is PHP's built-in server, not Laravel's.

Checks run now:

| Check | Result |
|---|---|
| `netstat -ano` on 8000–8010 | nothing listening |
| `netsh int ipv4 show excludedportrange protocol=tcp` | only 5000, 5357 — 8000–8010 not reserved |
| Raw `stream_socket_server()` on each of 8000–8010 | all bind OK |
| `php artisan serve` (bare, and with `--port`) | binds 8000, serves HTTP 200 |

Most likely causes, in order: stale `php.exe` / `php -S` processes from earlier `serve` runs still
holding the range (11 leftovers = exactly 8000–8010), a watcher/auto-restart tool relaunching the
server fast enough to leave sockets in `TIME_WAIT`, or an antivirus/VPN momentarily blocking binds.

**Diagnose and clear:**

```powershell
netstat -ano | findstr ":800"          # find the owning PIDs
taskkill /F /IM php.exe                # drop stray PHP servers
php artisan serve --port=9000          # explicit port: skips the retry loop, clear error if it fails
php artisan serve --tries=20           # or just allow more retries
```

---

## 3. Unrelated environment note

Every PHP command prints `Module "openssl" is already loaded`, which is a misconfiguration in
`C:\xampp\php\php.ini`:

```
942: extension=openssl
984: extension=php_openssl.dll;     <- trailing ';' is an inline comment, so this still loads
```

Line 984 loads `php_openssl.dll`, which *is* the `openssl` module already loaded on line 942. It is
harmless but noisy. Fix by commenting out line 984 (`;extension=php_openssl.dll`). **Not applied** —
this is a global XAMPP file outside the project, so it is left to you.

---

## Files changed

| File | Change |
|---|---|
| `app/Http/Resources/ReadinessResultResource.php` | added `is_provisional` to the response payload |
| `app/Http/Controllers/Resources/ReadinessResultResource.php` | **deleted** — misplaced, never autoloaded |
| `tests/Feature/Readiness/ReadinessTest.php` | corrected 3 stale expectations, added a key-presence guard, Pint |

---

## ⚠️ Warning: `git rm` on this machine wipes the working tree

While removing the misplaced resource file, `git rm` caused **126 files under `app/` to vanish from
disk** — the same failure mode seen earlier with `database/migrations`. No hooks and no
`.gitattributes` filters are responsible. It was fully recoverable with `git checkout -- app`
(the tree was clean, so nothing was lost), but:

- **Do not run `git rm`** in these checkouts — use plain `rm` and let `git add -A` pick it up.
- **Commit or stash before any index-mutating git command**, so an unexpected wipe is recoverable.
- Untracked, uncommitted work would **not** be recoverable this way.
