# MIGRATIONS TABLE INCIDENT — second occurrence (2026-10-01)

**Symptom.** Render deploy failed:

```
SQLSTATE[42S01] 1050 Table 'account_verifications' already exists
  database/migrations/2026_01_01_000700_create_account_verifications_table.php:11
INFO  Running migrations.
2026_01_01_000700_create_account_verifications_table ......... 290.48ms FAIL
==> Exited with status 1
```

Because the deploy CMD is `migrate --force && config:cache && (seed &) && serve`, one failed
migration killed the whole deploy.

## Root cause — bookkeeping, not schema

| | count |
|---|---|
| migration files on disk | **108** |
| rows in `migrations` | **11** |

Laravel decides what to run from the `migrations` **table**, not the schema. With 97 rows missing it
believed 97 migrations had never run and began replaying them from the top.

**The schema was NOT destroyed.** `information_schema.TABLES.CREATE_TIME` spanned the app's whole
history (oldest `2026-08-19 08:48`, i.e. the first deploy) — so no `migrate:fresh` / `db:wipe` had
ever run. Only the bookkeeping had been emptied. (This is the same failure mode as the earlier
`password_reset_tokens` incident; see `MIGRATIONS_TABLE_INCIDENT.md`.)

How it happened: **32 of the migrations have a no-op `down()`** (every ALTER). `Migrator::runDown()`
deletes a migration's row only *after* its `down()` succeeds, so a `migrate:reset` removes rows while
leaving the schema completely untouched — a silent, invisible loss.

## The real gaps

A column-level diff (not just tables — ALTER migrations are invisible to a table-level check) found
**6** migrations whose effect was genuinely absent:

| migration | real gap |
|---|---|
| `2026_01_01_001500_create_student_profiles_table` | `education` (see note) |
| `2026_08_20_090000_add_attempts_to_password_reset_tokens_table` | `password_reset_tokens.attempts` |
| `2026_09_30_000010_create_roadmap_action_prerequisites_table` | the table |
| `2026_09_30_000011_add_next_best_action_to_roadmaps_table` | `roadmaps.next_best_action_id` |
| `2026_09_30_000012_add_weekly_availability_hours_to_student_profiles_table` | `student_profiles.weekly_availability_hours` |
| `2026_09_30_000014_add_estimated_duration_weeks_to_roadmap_actions_table` | `roadmap_actions.estimated_duration_weeks` |

Roughly **88** further migrations were already applied and merely unrecorded.

⚠️ **`education` is NOT a real gap.** `2026_08_25_000004_add_academic_fields_to_student_profiles_table`
explicitly **drops** `education` and `specialization` ("university_id / specialization_id replace the
former free-text education / specialization columns, which are dropped here"). Verified on a scratch
DB: a fresh `migrate` creates it then drops it again. So `001500`'s net effect is already realised and
it is safe to record.

## Repair — bookkeeping rows only

`storage/app/_reconcile_migrations.php` (kept, dry-run by default):

1. For every migration not recorded, decide whether its effect is **already present**:
   `Schema::create('t')` → table must exist; `$table->type('c')` → column must exist;
   `dropColumn('c')` → column must be **absent**. Only the `up()` body is analysed.
2. Insert `migrations` rows **only** for those. Nothing is created, altered or dropped.
3. Refuse to record anything whose effect is missing (that is real work, not bookkeeping).
4. An explicit `SUPERSEDED` allowlist covers migrations a *later* migration deliberately undid —
   each entry must name the justification.

Applied results:
- recorded **91** migrations → `migrate:status` showed 102 Ran / 6 Pending
- recorded **1** more (`001500`, superseded) → 5 Pending
- ran the 5 for real: `migrate --force` → **all DONE**

**Final: 108 Ran / 0 Pending, verifier reports 0 gaps, data intact** (5 projects, 5 users, 2 student
profiles, 15 skills).

### Validation that mattered

Before trusting the diff, the script was run against a **scratch SQLite DB migrated from zero** — it
correctly reported 0 pending / 0 gaps. That proved the parser would notice every real difference, so
the 6 items it flagged on the production DB were genuine rather than regex noise.

### Parser false positives worth remembering

- `morphs('x')` / `nullableMorphs('x')` create **`x_type` + `x_id`**, never a bare `x`.
- Method names mentioned in **comments** match unless comments are stripped first.
- The nearest preceding `Schema::table(...)` is the target table — a naive regex attaches columns to
  the wrong table.
- `$table->dropConstrainedForeignId('x')` drops a column; it must not be read as an "add".

## Prevention added

`AppServiceProvider::boot()` now calls **`DB::prohibitDestructiveCommands()`** in production, alongside
the existing `URL::forceScheme('https')`. Verified live: `APP_ENV=production php artisan db:wipe` →
*"This command is prohibited from running in this environment."*

That single line removes the failure mode entirely — the empty-`migrations`-table state can only be
reached through `db:wipe` / `migrate:fresh` / `migrate:refresh` / `migrate:reset` / `migrate:rollback`,
all of which are now refused in production.

`2026_01_01_001500_create_student_profiles_table` was also made **idempotent** (`Schema::hasTable`
guard, then add only the missing column) so a future replay cannot collide on it. Do **not** blanket-guard
migrations that `dropIfExists` + `create` — those would wipe data on replay.
