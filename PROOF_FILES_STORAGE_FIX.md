# Why the Proof Images Don't Display — and the Fix

Branch `feature/authentication` · checkout `E:\SkillSpan\Back-End-feature-authentication`

---

## The short version

**The files are gone.** All 27 of them. The database still holds a row for every proof
document, but **not one of those files exists on the server's disk any more**:

```
disk root = .../storage/app/private

  org#1    image/jpeg  proofs/1/suH24XHMfrz501CCunDJ21s1TIKWfHAqkJSv4Phu.jpg   MISSING
  org#2    image/jpeg  proofs/2/EFqWlg2PSeGoOUp9c6D057f6ojhgUy9I7VKi3dRE.jpg   MISSING
  ...
  org#27   image/png   proofs/27/eg0Ix9fQdZsC6gCgIygaPKfeXkjf00JSFDY97dvj.png  MISSING

found: 0 | missing: 27   (of 27)
```

So this was never a display bug. The panel builds the link correctly and the download
route is correct — it just has nothing to serve.

## Why they vanished

Uploaded proofs are written to Laravel's **`local`** disk, which resolves to
`storage/app/private` **inside the application container**:

```php
// app/Services/AuthService.php
$path = $file->store('proofs/'.$organization->id, 'local');
```

Your `Dockerfile` is a plain `php:8.2-cli` image, and there is no persistent volume
mounted. On Render that means:

- every **deploy** builds a fresh image — anything written at runtime is discarded;
- the free tier also **restarts / spins down** the container, with the same effect.

The database is external (Clever Cloud MySQL), so the **rows survived** while the
**files did not**. You pushed roughly five commits today, so this happened repeatedly.

**The uploads themselves are unrecoverable.** Only the paths remain. Anyone whose
registration proof was lost has to upload it again.

## The code fix (done)

Three places hardcoded the `local` disk, which made the storage location impossible to
change without editing code. They now follow the **configured default disk**, so
`FILESYSTEM_DISK` controls where uploads live:

| File | Before | After |
|---|---|---|
| `app/Services/AuthService.php` | `->store('proofs/'.$id, 'local')` | `->store('proofs/'.$id)` |
| `app/Http/Controllers/Api/Admin/OrganizationController.php` | `Storage::disk('local')->exists(...)` | `Storage::exists(...)` |
| `app/Http/Controllers/Api/Admin/OrganizationController.php` | `Storage::disk('local')->response(...)` | `Storage::response(...)` |

`EvidenceController` already used the default disk, so evidence uploads move along with
the proofs — which is what you want, since they had the same problem.

Guarded by a new test, `test_proof_download_follows_the_configured_default_disk`, which
points the default at a disk that is deliberately **not** `local` and asserts the download
still works. That fails if anything re-hardcodes the disk.

## What you still have to do

The code change only makes the destination configurable. **You have to point it somewhere
that persists.** Pick one:

### Option A — object storage (recommended, works on the free tier)

`config/filesystems.php` already defines an `s3` disk, and it is driven entirely by env
vars, so no further code is needed. Add to the **Render environment** (and locally if you
want to match):

```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=auto
AWS_BUCKET=skillspan-proofs
AWS_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=true
```

Works with AWS S3, **Cloudflare R2** (10 GB free, no egress fees), Backblaze B2, DigitalOcean
Spaces or MinIO. For R2 set `AWS_DEFAULT_REGION=auto` and keep the path-style flag on.

Then, once, on the bucket: make sure the container can write to the `proofs/` prefix.

### Option B — a Render persistent disk

Keeps `FILESYSTEM_DISK=local` and needs no credentials, but a persistent disk requires a
**paid** Render instance type. Mount it at `/var/www/html/storage/app/private` and the
existing code works untouched.

## Verifying afterwards

1. Register a test organization with a proof image, or approve one that has one.
2. In the panel, open the organization and click **فتح الملف** — it should render the image.
3. Confirm the row's file actually exists:

```bash
php artisan tinker --execute="\$f=\App\Models\UploadedFile::latest()->first();
echo \$f->path.' -> '.(\Illuminate\Support\Facades\Storage::exists(\$f->path)?'EXISTS':'MISSING');"
```

4. Then **redeploy** and check again. That is the real test — it is what was destroying
   the files before.

## Two related notes

- **`Organization::proofFile()` has a misleading docblock.** It says proof files "are not
  linked to the organization directly, so we resolve them through the organization's admin
  member", but the code does `$this->files()->where('type','certificate')` — a direct
  `morphMany` on the organization. The comment describes something the code does not do.
  The code is correct (all 27 rows carry `fileable_type = App\Models\Organization`); the
  comment should be corrected or deleted.
- **`APP_DEBUG=true` alongside `APP_ENV=production`** on Render would put stack traces — and
  whatever secrets appear in them — on public error pages. Worth confirming that pair in the
  Render environment variables.
