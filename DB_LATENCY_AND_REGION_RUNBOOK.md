# Database latency — evidence and migration runbook

**Status:** measurement complete, nothing changed. Every number below was read from the live
database on 2026-10-19; no writes were made.

**TL;DR** — The database is in **Paris**. Opening a connection costs **351 ms** and every query after
that costs **127 ms**. That is the whole "the site is slow" story for any endpoint that touches the
database. The cheapest fix is almost certainly **not** migrating the database — it is checking which
region the Render service runs in and, if it is not Frankfurt, moving the *service*. See
[Decision tree](#decision-tree).

---

## 1. What was measured

### The database

| Fact | Value |
|---|---|
| Engine | MySQL **8.0.22-13** |
| Host | `b6aducvvb7pz4ebha27u-mysql.services.clever-cloud.com` → `91.208.207.108` |
| Location | **Paris, Île-de-France, FR** (AS213394 Clever Cloud SAS) |
| Tables | **77** (37 of them empty) |
| Size (data + index) | **7,392 KB ≈ 7.2 MB** |
| Largest table | `universities` — **10,153 rows / 3,104 KB** (42% of the whole database) |
| `slow_queries` | **0** |
| `max_connections` | 2000 |
| `Threads_connected` / `Threads_running` | 267 / **3** |
| Server uptime at measurement | 72,152 s (≈ 20 h) |

**`slow_queries = 0` is the important line.** The queries are not slow. The distance is.

### The round trip

Measured with `SELECT 1` (no work at all) through the application's own PDO connection:

| Measurement | Cost |
|---|---|
| First query on a fresh connection (TCP + TLS + auth + query) | **478 ms** |
| Same connection, queries 2–6 | **124–130 ms** each |
| → **connect cost alone** | **351 ms** |
| → **steady-state round trip** | **127 ms** |
| After `DB::purge()`, the next query reconnects | **443 ms** |

There are no persistent connections configured, and the image runs `artisan serve` with
`PHP_CLI_SERVER_WORKERS=4`, so **every request opens a new connection** and pays the 351 ms before it
does any useful work.

### The arithmetic that explains every slow endpoint

| Queries in a request | Cost as-is | Same-region (≈2 ms connect, ≈1 ms RTT) |
|---|---|---|
| 1 | 478 ms | 3 ms |
| 5 | **986 ms** | 7 ms |
| 20 | **2,891 ms** | 22 ms |

The `/reference/specializations` endpoint measured **1.29 s** for a single query before it was cached.
One query is 478 ms, and the framework boot and JSON serialisation account for the rest. The numbers
line up.

### The dump (proves the migration is small)

| Measurement | Value |
|---|---|
| Schema only (`--no-data`) | 99,464 bytes, **77 `CREATE TABLE`** |
| Full dump with data, `--routines --triggers` | **1.6 MB in 88 s**, 40 `INSERT` statements |
| Tool used | `/c/xampp/mysql/bin/mysqldump.exe` — MariaDB **10.4.32**, works against MySQL 8.0 |

**88 seconds to dump 1.6 MB** is itself a symptom: the dump makes one `SELECT *` per table, and 77
tables × ~127 ms of round trip is already ~10 s before any row data is transferred.

> The MariaDB client is fine here — `--no-tablespaces` is required (MySQL 8 rejects the dump without
> it when the user lacks `PROCESS`), and the 8.0-only `utf8mb4_0900_ai_ci` collation is emitted
> verbatim. If the target database is also MySQL 8, the dump restores cleanly.

---

### What the post-fix numbers say about the Render region

After the binding fix (`7374186`) the same endpoint gives a clean controlled experiment, because the
only difference between the two cases is the number of database round trips:

| Case | DB round trips | Measured |
|---|---|---|
| `/up` and any cache hit | **0** | 0.31–0.35 s |
| `/reference/countries/{id}/universities` — cache miss | **2 queries + 1 connect** | **2.04 s** |
| Same endpoint **before** the fix — cache hit | **1 query + 1 connect** | **1.33 s** |

Subtracting the no-database baseline (≈0.33 s) leaves `connect + 2 queries = 1.71 s` and
`connect + 1 query = 1.00 s`. Therefore:

> **One query round trip from the Render service ≈ 0.71 s.**
> **One connection ≈ 0.29 s.**
> (Check: 0.33 + 0.29 + 2×0.71 = 2.04 s — the measured value exactly.)

The same `SELECT 1` measured from Amman against the same Paris database costs **0.127 s**. The Render
service is therefore paying **5.6×** what a home connection in Jordan pays for the same packet pair.

**Conclusion: the service is not in Frankfurt.** Frankfurt→Paris is a ~5–10 ms hop; nothing in Europe
produces 710 ms for a single query. The service is almost certainly in the US or Singapore.

This also settles the Lever B question: a persistent connection would save the **0.29 s** connect,
while colocating would save **0.69 s on every single query**. Colocation wins by an order of magnitude
on any endpoint that makes more than one query, and it does not carry the transaction-leak risk.

---

## 2. Decision tree

Do these in order. **Stop as soon as one applies.**

### A. Is the Render service in Frankfurt?

This is the first thing to check and it costs nothing. Open the service in the Render dashboard — the
region is shown on the service page.

- **Not Frankfurt** (e.g. Oregon / Ohio / Singapore) → **[Lever A](#lever-a--move-the-service-not-the-data)**.
  This is the single biggest win available, it needs **no data migration at all**, and it is a
  redeploy rather than a database move.
- **Already Frankfurt** → the 127 ms you are paying is Paris↔Frankfurt plus the public-internet path.
  Go to **[Lever B](#lever-b--persistent-connections-remove-the-351-ms-connect)** or
  **[Lever C](#lever-c--move-the-database)**.

### B. How many queries does a typical page make?

Laravel's debug bar is off in production, but you can count them from the code path, or temporarily
run `DB::listen()` in a local request. If a hot page makes more than ~5 queries, Lever C matters more
than Lever B.

---

## Lever A — move the service, not the data

**Cost:** one new Render service + a redeploy. **Data at risk:** none. **Reversible:** yes.

A Render service's region is fixed at creation — it cannot be changed in place. The procedure is to
create a replacement service in Frankfurt and move traffic to it.

1. In Render, create a **new Web Service** from the same repo and branch (`feature/authentication`),
   with **Region: Frankfurt**. Copy every environment variable from the current service — including
   `SESSION_DRIVER=file` and `QUEUE_CONNECTION=sync`.
2. Let it deploy and confirm it boots: `GET /up` returns 200.
3. Measure before cutting over — from the new service's own URL:
   ```bash
   curl -s --compressed -w '%{http_code} %{time_total}s\n' -o /dev/null \
     https://<new-service>.onrender.com/api/v1/reference/specializations
   ```
   A cached endpoint should be **≈0.30 s** (framework floor) and a *uncached, DB-backed* endpoint
   should drop from ~1.3 s to well under 0.5 s. If it does not, the new service is not in Frankfurt —
   stop and re-check.
4. Point the custom domain (and the frontend's API base URL) at the new service.
5. Keep the old service for a day, then delete it. **Two free services running 24/7 exceed the
   750 h/month workspace budget** — do not leave both awake.

> ⚠️ The old service and the new one share the same database, so nothing needs to be migrated. The
> only thing that changes is where the PHP runs.

---

## Lever B — persistent connections (remove the 351 ms connect)

**Cost:** one config change. **Data at risk:** none. **Risk:** moderate — see the caveat.

If the service really is already in Frankfurt and you still want the connect cost gone, a persistent
PDO connection lets the `artisan serve` worker reuse an open connection across requests.

In `config/database.php`, inside the `mysql` connection array:

```php
'options' => extension_loaded('pdo_mysql') ? array_filter([
    PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
    PDO::ATTR_PERSISTENT   => (bool) env('DB_PERSISTENT', false),
]) : [],
```

Then set `DB_PERSISTENT=true` in the dashboard and **Manual Deploy** (`config:cache` runs on boot, so
an env change without a deploy does nothing).

> ⚠️ **The caveat, and why this is not the first thing to try.** A persistent connection outlives the
> request. If any code path opens a transaction and never commits or rolls back, the next request
> inherits an open transaction on that socket — and it will look like unrelated writes silently
> vanishing. The same applies to session variables (`SET time_zone`, `SET SESSION sql_mode`).
> Laravel's own docs call persistent connections "problematic with some setups" for this reason.
>
> Before enabling it, confirm nothing in `app/` leaves a transaction open: every `DB::beginTransaction()`
> must be paired, and `DB::transaction(...)` should be preferred. Grep for `beginTransaction` first.
>
> **Revert:** set `DB_PERSISTENT=false` (or remove it) and Manual Deploy. No data is touched.

---

## Lever C — move the database

Only worth it if Lever A is impossible **and** the service is far from Paris.

### Why "move it to Render" is not an option

**Render does not offer managed MySQL.** It offers Postgres, Redis and Key Value. So the target has
to be either:

- a **third-party MySQL in the service's region** — pick one that publishes a free or cheap tier and
  a region near the app, and confirm both before committing (plan names and regions change often), or
- **Render Postgres**, which means converting the schema — far more work than this is worth, and it
  changes every raw query and collation assumption in the codebase.

### Prerequisites

- [ ] The target MySQL exists, is reachable, and its **version is 8.0.x** (matching `8.0.22-13`).
- [ ] Its user can `CREATE`, `ALTER`, `INSERT`, `DROP`, `CREATE TRIGGER`, `CREATE ROUTINE`.
- [ ] Its **default collation matches** — check with
      `SELECT @@character_set_database, @@collation_database;` on both. A mismatch shows up later as
      "Illegal mix of collations" on a join, not at restore time.
- [ ] Its **max_allowed_packet** is at least 64 MB (the `universities` insert is the largest single
      statement).
- [ ] You have the dashboard open and can do a **Manual Deploy**.
- [ ] You have accepted a **~5 minute window** in which writes to the old database are lost.

### 1. Take the dump (read-only on the source)

```bash
cd "E:/SkillSpan/Back-End-feature-authentication"

H=$(grep '^DB_HOST=' .env | cut -d= -f2- | tr -d '"')
P=$(grep '^DB_PORT=' .env | cut -d= -f2- | tr -d '"')
U=$(grep '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '"')
W=$(grep '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"')
D=$(grep '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '"')

MYSQL_PWD="$W" /c/xampp/mysql/bin/mysqldump.exe \
  -h "$H" -P "${P:-3306}" -u "$U" \
  --single-transaction --no-tablespaces --skip-comments \
  --routines --triggers --default-character-set=utf8mb4 \
  "$D" > backup_$(date +%Y%m%d_%H%M).sql
```

`--single-transaction` gives a consistent snapshot without locking the tables (InnoDB). Expect
**~1.6 MB and ~90 seconds**. Confirm the exit code is 0 and the file is non-empty before continuing.

### 2. Restore into the target

```bash
MYSQL_PWD="$NEW_PW" /c/xampp/mysql/bin/mysql.exe \
  -h "$NEW_HOST" -P 3306 -u "$NEW_USER" "$NEW_DB" < backup_YYYYMMDD_HHMM.sql
```

### 3. Verify the restore *before* switching the application

```bash
MYSQL_PWD="$NEW_PW" /c/xampp/mysql/bin/mysql.exe -h "$NEW_HOST" -u "$NEW_USER" "$NEW_DB" -e "
  SELECT COUNT(*) AS tables_present FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();
  SELECT COUNT(*) AS universities FROM universities;
  SELECT COUNT(*) AS migrations  FROM migrations;
  SELECT COUNT(*) AS skills        FROM skills;
  SELECT COUNT(*) AS users         FROM users;
"
```

Expected, from the source database: **77 tables**, **10,153 universities**, **212 skills**,
**7 projects**. `migrations` must match the source too — if it does not, the next deploy's
`migrate --force` will re-run migrations against a schema that already has them.

### 4. Cut over

1. In the Render dashboard, change `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
2. **Manual Deploy.** Without it, `config:cache` keeps serving the old values.
3. Wait for the deploy (~3 min) and watch the logs — the CMD runs `migrate --force` on boot, which
   must report "Nothing to migrate".

### 5. Verify

```bash
curl -s --compressed -w '\n%{http_code} %{time_total}s\n' -o /dev/null \
  https://back-end-zdip.onrender.com/up
curl -s --compressed -w '\n%{http_code} %{time_total}s\n' -o /dev/null \
  https://back-end-zdip.onrender.com/api/v1/reference/countries
```

Then, on the dashboard **Events** tab, confirm the deploy is the one you triggered.

Because the reference endpoints are now cached in the file store, **the first request after a deploy
is a cache miss**. Measure the second and third request, not the first.

### 6. Rollback

Revert the five `DB_*` variables in the dashboard and Manual Deploy. **The old database was never
written to**, so rollback is a 3-minute deploy with no data loss — but any rows written *after* the
cutover exist only in the new database. If that matters, take a second dump from the new database
before rolling back.

---

## 3. What is *not* worth doing

- **The 267 idle connections are not a bottleneck.** `max_connections` is 2000 and
  `Threads_running` is 3. They are almost certainly leftovers from test runs that hit the remote
  database (the suite reaches it whenever `DB_CONNECTION` is not overridden). They cost nothing while
  idle, but they are a reminder: always run the suite with
  `DB_CONNECTION=sqlite DB_DATABASE=:memory:`.
- **Indexes will not help.** `slow_queries` is 0. Every query is already fast; the time is spent on
  the wire.
- **Query caching beyond the reference lists** buys little. The remaining cost is per-query round
  trips, and only colocation (Lever A/C) or connection reuse (Lever B) removes those.

## 4. The one thing that is a product decision, not a performance fix

`universities` is **10,153 rows / 3,104 KB — 42% of the entire database** — and the endpoint returns
**541 KB of JSON** for a dropdown. A learner registering from one country does not need the
universities of every country in the world: `/reference/countries/{country}/universities` already
exists and returns only that country's list.

Trimming the seed data, or making the onboarding form use the per-country endpoint, would remove the
largest payload in the API. That changes behaviour, so it is a product call, not a performance patch.
