# `php artisan serve` → "Failed to listen on 127.0.0.1:8000 (reason: ?)"

## 1. What those 11 lines actually mean

Two separate things are happening.

**Laravel is retrying, not looping forever.** `Illuminate\Foundation\Console\ServeCommand`:

```php
protected function port() { return ($port ?: 8000) + $this->portOffset; }

protected function canTryAnotherPort()
{
    return is_null($this->input->getOption('port')) &&
        ($this->input->getOption('tries') > $this->portOffset);   // --tries defaults to 10
}
```

Each time the child PHP server fails to start, Laravel bumps `portOffset` and tries `8000 + offset`.
With the default `--tries=10` that is exactly **11 attempts: 8000 … 8010** — then it gives up and
returns to the prompt. So you are seeing *one* failure, retried 11 times.

**The message is PHP's, not Laravel's.** `php -S` prints it from `sapi/cli/php_cli_server.c`:

```c
php_cli_server_logf(PHP_CLI_SERVER_LOG_ERROR,
    "Failed to listen on %s:%d (reason: %s)",
    host, port, errstr ? ZSTR_VAL(errstr) : "?");
```

## 2. Why the reason is `?`

**That `?` is a literal string in PHP's source** — the fallback used when `errstr` is `NULL`, i.e.
when PHP has **no error string at all**. It is not a real Windows error message.

That matters, because a *normal* failure always produces a real message. Verified on your machine
with `C:\xampp\php\php.exe` (8.2.12):

| Situation | What PHP prints |
|---|---|
| Port inside a Windows **excluded range** (tested 5000, 5357) | `(reason: An attempt was made to access a socket in a way forbidden by its access permissions)` |
| **Unresolvable host** | `(reason: php_network_getaddresses: getaddrinfo for … failed: No such host is known.)` |
| **Address not on this machine** (10.255.255.1) | `(reason: The requested address is not valid in its context)` |
| Port **already in use** | binds anyway — PHP sets `SO_REUSEADDR`, and on Windows that allows a second bind to the same port |
| Normal case (127.0.0.1:8000) | `PHP 8.2.12 Development Server (http://127.0.0.1:8000) started` |

So `?` is **not** "port already in use" and **not** a reserved port range. Internally it means
`bind()` failed while `WSAGetLastError()` came back as **0** — the failure was not reported through
the normal Winsock error path. That is the signature of something *outside* the socket stack
denying the bind (a filter driver, security/network shim, or a sandboxed process context) rather
than an ordinary conflict.

## 3. What I checked on this machine

| Check | Result |
|---|---|
| Listeners on 8000–8020 | **none** |
| `php.exe` processes running | **none** |
| Windows excluded port ranges (tcp) | only `5000`, `5357` — 8000–8010 not covered |
| Dynamic port range | 49152 + 16384 — does not cover 8000–8010 |
| Third-party Winsock providers (LSP) | none — only Microsoft `mswsock.dll` |
| Antivirus / VPN / Docker / WSL | none installed or running |
| Other PHP installs | none — only `C:\xampp\php\php.exe` |
| Binding 8000–8010 | **all 11 bind successfully** |
| `php artisan serve` | **works** — `Server running on [http://127.0.0.1:8000]`, HTTP 200 |

**The one unusual thing found: Laravel Herd is installed and running.**

```
C:\Program Files\Herd
C:\Users\HP\.config\herd
HerdHelper.exe    PID 4956    (running as a service)
hosts:  127.0.0.1 database.herd.test
```

Herd manages local networking and ports, so it is the most plausible third-party candidate — but it
is **not** blocking anything at the moment. This means the failure is **intermittent**: it was
happening when you ran it, and it is not happening now.

Your shell history also shows many repeated `php artisan serve` attempts in a row, which is worth
noting: on Windows, killing the parent `php artisan serve` does **not** kill the child `php -S`.
Repeated interrupted attempts can leave orphans behind. (None are running now — I checked.)

## 4. What to do

### Right now
Just retry — it is working:

```powershell
php artisan serve
```

### If it fails again, get the real error instead of `?`

```powershell
php "C:\Users\HP\Documents\GitHub\SkillSpan\Back-End-feature-authentication\.workbuddy-ai\port-probe.php"
```

That script tries all 11 ports and prints `errno` / `errstr` for each. **If it reports
`errno=0` alongside a failed bind, the "nothing set the socket error" theory is confirmed.**

Also useful:

```powershell
netstat -ano | findstr ":800"                                   # anything holding the ports?
netsh int ipv4 show excludedportrange protocol=tcp              # reserved ranges
taskkill /F /IM php.exe                                         # clear orphaned servers
```

### Bypass the retry loop and get a clearer error

```powershell
php artisan serve --port=9000
```

Passing `--port` explicitly disables `canTryAnotherPort()`, so you get **one** attempt and one
message instead of 11.

### If Herd is the culprit

Quit Herd from the system tray (or stop the `HerdHelper.exe` service, which needs admin), then
retry `php artisan serve`. If the failure disappears, Herd is interfering.

### If it keeps happening across all ports

The Winsock stack may need rebuilding (run as Administrator, then **reboot**):

```powershell
netsh winsock reset
```

## 5. Summary

- The 8000→8010 sweep is Laravel retrying 11 times (`--tries=10`), not a loop.
- `(reason: ?)` is PHP's literal "I have no error string" placeholder — so this is **not** a normal
  port conflict or reserved range, both of which produce descriptive messages (verified).
- Everything on the machine is clean and binding works, so the block is environmental and
  intermittent. Laravel Herd (`HerdHelper.exe`) is the main third-party suspect.
- Use `port-probe.php` the next time it fails to capture the real `errno`.
