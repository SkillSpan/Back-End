# Production deploy failure — empty `migrations` table

**Date:** 2026-09-29 · **Status:** fixed and verified · **Data loss:** none

## Symptom

Every Render deploy failed at the first step:

```
INFO  Running migrations.
0001_01_01_000000_create_users_table ......................... 285.41ms FAIL
SQLSTATE[42S01]: Base table or view already exists: 1050
Table 'password_reset_tokens' already exists
==> Exited with status 1
```

## Root cause

The production `migrations` table was **empty (0 rows)** while **all 71 application tables
existed**. The Dockerfile `CMD` runs `php artisan migrate --force` on every deploy, so Laravel saw
zero recorded migrations, treated all 100 as pending, and tried to replay them from scratch. The
very first statement it attempted was

```php
// database/migrations/0001_01_01_000000_create_users_table.php
Schema::create('password_reset_tokens', function (Blueprint $table) { ... });
```

and that table was already there. Because `migrate` is the first command in a `&&` chain, a failed
migration fails the entire deploy.

> Note this file does **not** create `users`. `users` comes from the custom
> `2026_01_01_000000_create_users_table.php`; commit `47d3aa4` stripped the duplicate
> `Schema::create('users')` out of the Laravel-default migration, which is why
> `password_reset_tokens` is the first thing it tries to create.

## Evidence gathered

| Check | Result |
|---|---|
| Tables the migrations would create | 71 |
| Tables actually present | 72 (= 71 + `migrations` itself) |
| Tables missing | **0** |
| Migrations whose effect is present (table **and** column level) | **100 / 100** |
| Migrations whose effect is missing | **0** |

So the schema was completely correct — only the bookkeeping was gone.

Two further findings pinned down *what kind* of event caused it:

- **`migrations` has existed since 2026-08-19 08:48** (it is the *oldest* table in the database),
  and other tables were created progressively by successful deploys up to **2026-09-29 13:54**.
  Nothing was dropped and re-created. A `migrate:fresh` / `db:wipe` / schema reload would have
  reset every table's creation time — so **no wipe or reset ran**. The rows were removed by a
  plain `DELETE` (DML). A `TRUNCATE` would also have reset the table's creation time, so it was
  not that either.
- **32 of the 100 migrations have an effectively no-op `down()`** — every single ALTER migration
  (they add columns and never remove them). This matters: `migrate:reset` deletes a migration's row
  *after* its `down()` runs, so for those 32 it deletes the row while leaving the table and columns
  completely intact. It is therefore possible to empty part of the table without visibly breaking
  the schema.

Nothing in the project's own code reads or writes the `migrations` table (checked `app/`,
`database/seeders/`, `routes/`, `config/`, `storage/app/`, `.github/`), and `DatabaseSeeder` only
calls reference-data seeders. The deletion was therefore an **external/manual action** — a DB
client, a Render shell, or a stray command — most likely an attempt to "reset migration state"
while debugging. It cannot be attributed to a specific command from the database alone.

## The fix

`storage/app/_fix_migrations_table.php` records the migrations that are already applied:

```bash
php storage/app/_fix_migrations_table.php            # dry run — prints the SQL, writes nothing
php storage/app/_fix_migrations_table.php --apply    # performs the repair
```

It refuses to run if the `migrations` table is not empty, runs inside a transaction (pure DML, so
the rollback is real), and only inserts `(migration, batch)` rows — it creates, alters and drops
nothing.

**Result:** 100 rows written, all in batch 1.

**Verification** (`php artisan migrate:status` against the real production connection):

```
pending migrations: 0
2026_09_29_000005_add_hide_to_feedback_events_enum ....... [1] Ran
```

The next deploy's `migrate --force` reports *"Nothing to migrate"* and proceeds normally.

`storage/app/_diag_applied.php` is the read-only companion that produced the 100/100 table above —
run it whenever you need to know whether the database matches the migration files.

## Prevention (recommended, NOT yet applied)

1. **Forbid destructive commands in production.** `AppServiceProvider::boot()` currently has no
   guard, so `db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset` and `migrate:rollback`
   will all happily run against the live database:

   ```php
   if ($this->app->environment('production')) {
       DB::prohibitDestructiveCommands();
   }
   ```

2. **Never run an artisan command in this repo before exporting the SQLite overrides** — the
   checked-in `.env` points at the live Clever Cloud database. See
   `REFERENCE_engine_gotchas.md`.

3. **Consider decoupling the deploy from the migration result** if a migration failure should not
   take the service down, though failing loudly is usually the right default.

## Open follow-up: reference data is empty

The deploy runs `php artisan db:seed --force` in the background on every deploy, yet production
currently has **0 rows** in `countries`, `universities`, `career_roles`, `skills` and
`project_roles` (while `users`=5, `organizations`=2, `roles`=3). Since the deploy has been failing
at `migrate`, the seeder has not been running — but it also ran on the deploy that succeeded at
13:54 today, so the seeder may be failing silently. Its output is redirected:

```
db:seed --force > /var/log/seed.log 2>&1 &
```

Worth reading `/var/log/seed.log` inside a Render shell after the next successful deploy, and
re-running `php artisan db:seed --force` if the reference tables are still empty.
