# Proof files: are they working? — **No, not one of them.**

You asked for a simple test on the proof files. Here is the answer, and what was
done about it.

## The test result

```
php artisan admin:check-proofs

  28 organizations · 28 with a proof document · 0 present · 28 missing
```

**Every one of the 28 proof documents has a database row but no file on disk.**

## Why

The app was storing uploads on the `local` disk, which resolves to
`storage/app/private` — on the **container's own filesystem**. The container runs
`php:8.2-cli` with **no persistent volume**, so every deploy wipes it. The database
is external (Clever Cloud), so the rows survive while the files do not. That is why
uploads *looked* fine: the record was saved, the file was already gone.

The most recent proof (row #28, a PDF) is brand new — so uploads are still being
made and still being destroyed.

> **The 28 lost files are not recoverable.** This work only stops the next deploy
> from destroying more.

## What was changed

| Change | Why |
| --- | --- |
| Upload no longer hardcodes the `local` disk | So `FILESYSTEM_DISK=s3` + credentials fixes it with no code change |
| Proof payload now includes `available` | Lets the panel tell *"never uploaded"* apart from *"row outlived the file"* |
| Panel shows **three** states, not two | "Lost on the server" is now amber with the cause spelled out, instead of a link to a 404 |
| Added `description` to the detail payload | The expanded card had no description for any organization — a missing key, not a rendering bug |
| New: `admin:check-proofs` | The diagnostic that produced the table above |
| New: `admin:check-login` | Explains why an account can or cannot sign into the admin panel |

## Verification

- `php artisan test` → **235 passed (886 assertions)**
- Pint clean on all touched files
- Committed as **`b9f8d6c`** and pushed to `origin/feature/authentication`

## What you need to do

The panel will now *say* the files are missing rather than showing a broken link —
but to actually keep new uploads you must either:

1. **Set `FILESYSTEM_DISK=s3`** plus the `AWS_*` (or R2) credentials in Render's
   environment, **or**
2. **Mount a persistent disk** on Render (paid plan).

Without one of these, the next deploy wipes the next upload too.

## Two things worth knowing

- **You have two checkouts of this repo.** `E:\SkillSpan\...` (where this work
  landed) is strictly ahead of `C:\Users\HP\Documents\GitHub\SkillSpan\...`, which is
  stale and has its own uncommitted edit to `Api/EvidenceController.php`. Worth
  consolidating to avoid pushing from the wrong one.
- **`git push` from the managed git fails silently** (killed, no output) because that
  install ships no HTTPS transport helper. Use the system Git:
  `"/c/Program Files/Git/cmd/git" push origin feature/authentication`.
