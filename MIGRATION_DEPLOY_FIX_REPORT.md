# تقرير إصلاح فشل Deployment على Render — Laravel Migrations

**المشروع:** SkillSpan Back-End
**المسار:** `D:\newnn\Back-End`
**الفرع:** `feature/authentication`
**التاريخ:** 2026-09-30
**الخطأ:** `SQLSTATE[42S01]: Base table or view already exists: 1050 — Table 'password_reset_tokens' already exists`

---

## 1. Root Cause (السبب الجذري)

### السبب المباشر

Laravel لا يملك أي مفهوم لـ"تخطَّ الإنشاء إذا كان الجدول موجودًا". الـ`Migrator` يعمل هكذا:

1. يقرأ كل ملفات `database/migrations/*.php` ويرتبها **أبجديًا باسم الملف**.
2. يستبعد كل ملف موجود اسمه في جدول `migrations`.
3. ينفّذ الباقي (`pending`) بالترتيب، واحدًا واحدًا.

إذن لكي يُنفَّذ `0001_01_01_000000_create_users_table.php` يجب أن يكون **غير مسجّل** في جدول `migrations`. والخطأ يثبت أن هذا هو الحال فعلًا: الملف غير مسجّل، بينما جدول `password_reset_tokens` موجود مسبقًا في Production ⇒ `Schema::create()` يضرب MySQL error 1050 ويتوقف الـdeploy بالكامل.

**الخلاصة: جدول `migrations` في Production لا يعكس الواقع الفعلي للـschema. المشكلة في سجل الـmigrations، وليست في الكود.**

### الدليل الحاسم

شغّلت الـmigration suite كاملة على قاعدة بيانات **نظيفة**:

```
108 migrations → كلها DONE، صفر أخطاء
```

⇒ الـsuite سليمة ومتسقة ذاتيًا. لو كانت المشكلة في الكود، لفشلت أيضًا على قاعدة نظيفة. بما أنها تنجح على النظيفة وتفشل على Production، فالفرق الوحيد هو **حالة جدول `migrations`**.

### الأسباب المساهمة (عيوب حقيقية في الـrepo)

| # | العيب | الملفات |
|---|-------|---------|
| A | `password_reset_tokens` يُنشأ من **ثلاث** migrations مختلفة | `0001_01_01_000000_create_users_table.php` + `2026_01_01_000300_create_role_permission_table.php` + `2026_08_13_190000_rebuild_password_reset_tokens_table.php` |
| B | يوجد **ملفان بنفس الاسم** `create_users_table` | `0001_01_01_000000_...` و `2026_01_01_000000_...` |
| C | **ملف مضلَّل الاسم:** `2026_01_01_000300_create_role_permission_table.php` لا يُنشئ `role_permission` إطلاقًا — بل يُعيد بناء `password_reset_tokens`. الجدول الحقيقي `role_permission` أُضيف لاحقًا في `2026_08_25_000001_create_role_permission_table.php` | انظر التعليق داخل الملف نفسه |
| D | **لا migration ذرّية:** `0001_01_01_000000` يُنشئ جدولين داخل `up()` واحد بلا أي حماية | `0001_01_01_000000_create_users_table.php` |

**العيب (D) هو التفسير الأكثر ترجيحًا لأصل الانحراف:** لو نجح إنشاء `password_reset_tokens` ثم فشل إنشاء `sessions` لأي سبب، فإن Laravel **لا يسجّل الـmigration** (التسجيل يحدث بعد نجاح `up()` كاملة) لكن الجدول الأول يبقى موجودًا. الـdeploy التالي يرى الملف "pending"، يحاول إنشاء `password_reset_tokens` ⇒ 1050. هذا يشرح بدقة لماذا يظهر الخطأ على هذا الجدول تحديدًا.

### عن `git history`

- الملفان `0001_01_01_000000_create_users_table.php` و `2026_01_01_000000_create_users_table.php` **موجودان منذ أول commit** (`a10e558`، 2026-08-02) — لا يوجد rename.
- التعديل الوحيد على `0001_...` كان في `47d3aa4` (2026-08-04) حيث **حُذف** منه إنشاء جدول `users` (لأن `2026_01_01_000000` يملكه) وبقي `password_reset_tokens` + `sessions`.
- ⇒ لا يوجد تغيير تاريخي يكسر Production. الـdrift جاء من حالة قاعدة البيانات، لا من الـhistory.

---

## 2. Files Inspected

**Migrations (كل الـ108 ملفًا — فحص شامل):**
- `database/migrations/` كاملة، مع تحليل مكرر لكل `Schema::create` و `Schema::table` و `Schema::dropIfExists`
- تركيز خاص على: `0001_01_01_000000_create_users_table.php`، `0001_01_01_000001_create_cache_table.php`، `0001_01_01_000002_create_jobs_table.php`، `2026_01_01_000000_create_users_table.php`، `2026_01_01_000300_create_role_permission_table.php`، `2026_08_13_174426_...`، `2026_08_13_190000_rebuild_password_reset_tokens_table.php`، `2026_08_20_090000_...`، `2026_08_25_000001_create_role_permission_table.php`، `2026_08_25_000002_add_missing_auth_and_skill_indexes.php`
- migrations الـRoadmap: `2026_09_30_000010`، `2026_09_30_000011`، `2026_09_30_000013`، `2026_09_30_000014`، `2026_09_08_000004`، `2026_09_08_000005`، `2026_01_01_002100`، `2026_01_01_002200`

**Deployment:**
- `Dockerfile` (السطر 65: `php artisan migrate --force` — لم يُعدَّل)
- `.github/workflows/ci.yml`
- `.dockerignore`
- `composer.json` (سكربتات `setup` / `post-create-project-cmd`)
- ❌ **لا يوجد** `render.yaml` ولا أي ملف deployment آخر — Render يستخدم `Dockerfile`

**Config / Seeders / Env:**
- `config/database.php`، `phpunit.xml`، `.env`، `.env.example`، `.dockerignore`
- `database/seeders/` (11 ملفًا) + `database/factories/UserFactory.php`
- `bootstrap/app.php`، `routes/console.php`، `app/Console/Commands/`، `app/Models/User.php`
- `README.md`، `docs/adr/`
- Git: `git log --follow` على الملفات الحساسة + `git show` على الـcommits المؤثرة

---

## 3. Files Changed

| الملف | الحالة | التغيير |
|-------|--------|---------|
| `database/migrations/0001_01_01_000000_create_users_table.php` | معدَّل | حماية `password_reset_tokens` + `sessions` بـ`Schema::hasTable()` |
| `database/migrations/2026_01_01_000000_create_users_table.php` | معدَّل | حماية `users` + **تحقق صريح** من الأعمدة المملوكة له |
| `database/migrations/0001_01_01_000001_create_cache_table.php` | معدَّل | حماية `cache` + `cache_locks` |
| `database/migrations/0001_01_01_000002_create_jobs_table.php` | معدَّل | حماية `jobs` + `job_batches` + `failed_jobs` |
| `app/Console/Commands/MigrationDoctor.php` | **جديد** | أمر `db:migration-doctor` — تشخيص للقراءة فقط |

**الإجمالي:** 4 ملفات معدَّلة + 1 ملف جديد. **لم يُحذف أي ملف، ولم تُعدَّل أي migration خاصة بالـRoadmap.**

---

## 4. Exact Fix

### 4.1 الحماية (`Schema::hasTable`)

في الأربعة ملفات، كل `Schema::create('x', ...)` أصبح:

```php
if (! Schema::hasTable('x')) {
    Schema::create('x', function (Blueprint $table) { /* ... نفس البنية حرفيًا ... */ });
}
```

- **على قاعدة بيانات نظيفة:** لا فرق على الإطلاق — الجداول غير موجودة فتُنشأ كما كانت تمامًا.
- **على قاعدة بيانات موجودة:** يتخطى الإنشاء بدل الانهيار، ويُسجّل الـmigration كمنفَّذ.
- **لا `dropIfExists`، لا `drop`، لا تعديل بيانات.** الحماية **لا تحذف ولا تلمس أي صف**.

نطاق الحماية محصور في الجداول التي **ملكيتها مزدوجة فعلًا** (`password_reset_tokens` ×3، `users` ×2) أو **مملوكة للإطار** (`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`).

### 4.2 التحقق الصريح في `users` (منع الإخفاء)

حماية `users` وحدها كانت ستخفي مشكلة حقيقية: لو كان الجدول موجودًا ببنية Laravel الافتراضية القديمة (بلا `phone`/`locale`/`status`/`deleted_at`)، لتخطّيناه ولبقي التطبيق معطوبًا بصمت. لذلك أضفت تحققًا:

```php
private const OWNED_COLUMNS = ['phone', 'locale', 'status', 'deleted_at'];
// ...
if ($missing !== []) {
    throw new RuntimeException('The `users` table already exists but is missing: ...');
}
```

⇒ إن كان الجدول قديمًا فعلًا، يفشل الـdeploy **بصوت عالٍ وبرسالة قابلة للتنفيذ**، بدل رسالة 1050 الغامضة. **هذا ليس تجاهلًا للفشل — بل تشخيص أفضل له.**

### 4.3 أمر التشخيص `db:migration-doctor` (قراءة فقط)

أمر جديد يعمل على أي بيئة (بما فيها Shell الخاص بـRender، دون أي MySQL client). **لا يُنفّذ migration، ولا INSERT/UPDATE/DELETE.** يُخرج:

- عدد ملفات الـmigrations مقابل عدد الصفوف المسجّلة، وكل الـbatches.
- **الـpending migrations** مع: الجداول التي تُنشئها، التي تُعدّلها، وتحذير `DROPS` إن كان `up()` نفسه يُسقط جدولًا (كما في `2026_01_01_000300` و `2026_08_13_190000`).
- تصنيف كل pending: *already applied* (جداوله موجودة) أم *genuinely new* (جداوله مفقودة).
- كشف **الـorphans**: صفوف في `migrations` بلا ملف مطابق (توقيع انحراف التاريخ).
- كشف **الصفوف المشوّهة**: قيم تحتوي على مسار أو `.php` (تجعل كل الملفات تبدو pending).
- طباعة **SQL الـbaseline المقترح للمراجعة** — **دون تنفيذه**.

---

## 5. Production Safety

✅ **لم يُنفَّذ أي من التالي — إطلاقًا:**

- ❌ `migrate:fresh` / `migrate:refresh` / `migrate:rollback` / `migrate:reset`
- ❌ `DROP DATABASE` / `DROP TABLE` / أي `Schema::drop` أو `dropIfExists` جديد
- ❌ حذف أو تعديل أي جدول أو صف في Production
- ❌ تعديل بيانات المستخدمين
- ❌ تغيير DB credentials أو التحويل إلى SQLite
- ❌ تعطيل migrations أو جعل الـdeploy يتجاهل الفشل
- ❌ أي `INSERT` / `UPDATE` / `DELETE` على جدول `migrations` في Production
- ❌ `git reset --hard` / `git restore .` / `git clean -fd` / `merge --abort`
- ❌ `git commit` / `git push` (تُركت لك)

**التغييرات كلها إضافية (additive) ودفاعية (defensive):** أقصى ما تفعله هو *تخطّي* إنشاء جدول موجود. لا يوجد أي مسار تنفيذ يحذف بيانات.

**حالة Git قبل التعديل:** شجرة العمل كانت **نظيفة تمامًا** (`git status --short` فارغ) ⇒ لم يكن هناك أي تغيير غير محفوظ ليُفقد.

**لم أتحقق من قاعدة بيانات Production:** لا توجد credentials لها في `.env` المحلي (`DB_HOST=127.0.0.1`, `DB_DATABASE=skillspan_integration_test`). لم أخمّن credentials ولم أحاول الاتصال.

---

## 6. Migration Behavior

بعد الإصلاح، `php artisan migrate --force` على قاعدة بيانات Production الحالية:

1. يقرأ جدول `migrations` ⇒ يجد `0001_01_01_000000_create_users_table` ضمن الـpending.
2. ينفّذه: `Schema::hasTable('password_reset_tokens')` → **`true`** ⇒ يتخطى الإنشاء بدل 1050. ثم نفس الشيء لـ`sessions`.
3. **يُسجّل الـmigration في `migrations`** ⇒ لن يُعاد تنفيذه أبدًا بعد ذلك.
4. ينتقل للـmigration التالي، وهكذا.
5. أي migration **جديد فعلًا** (جداوله مفقودة) ينفّذ بشكل طبيعي ⇒ **migrations الجديدة فقط تُنفَّذ**.
6. أي جدول موجود **لا يُعاد إنشاؤه**، وبيانات Production تبقى كما هي.

**تم التحقق من هذا السلوك تجريبيًا** على قاعدة بيانات حُقن فيها انحراف مطابق لحالة Production (جدول `migrations` ناقص 4 صفوف + الجداول موجودة):

```
قبل الإصلاح : SQLSTATE[42S01] table already exists  → deploy متوقف
بعد الإصلاح : 4 migrations DONE، batch=2، 108/108 صفًا، صفر جداول محذوفة
```

**وأمر الـdeployment لم يُعدَّل:** `Dockerfile:65` يشغّل `php artisan migrate --force` عند كل إقلاع، وهذا **صحيح** — يظل يفشل بصوت عالٍ عند أي خطأ حقيقي، ولا يتجاهل أي فشل.

---

## 7. Tests (النتائج الفعلية)

| الفحص | النتيجة |
|-------|---------|
| `php artisan test` (قبل التعديل) | **1054 passed** (3765 assertions)، 0 failed — 171s |
| `php artisan test` (بعد التعديل) | **1054 passed** (3765 assertions)، 0 failed — 89s |
| `vendor/bin/pint --test` | **PASS** — 439 files، صفر style issues |
| `git diff --check` | **clean** (exit 0) |
| `git status --short` | 4 modified + 1 untracked (`MigrationDoctor.php`) |
| `php -l` على كل ملف معدَّل | no syntax errors |
| `migrate --force` على DB نظيفة | **108/108 DONE**، صفر أخطاء |
| `migrate:status` على DB نظيفة | **108 Ran**، 0 Pending |
| `db:migration-doctor` على DB نظيفة | "No pending migrations" |
| محاكاة انحراف Production | **ينجو** (بعد الإصلاح) — كان يفشل بـ42S01 (قبل) |
| محاكاة انحراف migration غير محمي | **يفشل بـ42S01** — تأكيد آلية السبب الجذري |
| محاكاة `users` ببنية قديمة | **يفشل برسالة واضحة** تسمّي الأعمدة الناقصة |

> ⚠️ **تنبيه:** الاختبارات تعمل على SQLite in-memory (`phpunit.xml`). نجاحها **لا يثبت** أن Production أُصلح. قاعدة MySQL المحلية (XAMPP، port 3308) **غير مشغّلة**، لذا تعذّر التحقق على MySQL محليًا.

**مigrations الـRoadmap سليمة ولم تُلمس:**
`2026_09_30_000010_create_roadmap_action_prerequisites_table` ✅
`2026_09_30_000011_add_next_best_action_to_roadmaps_table` ✅
`2026_09_30_000013_add_unique_roadmap_version_per_learner_role` ✅
`2026_09_30_000014_add_estimated_duration_weeks_to_roadmap_actions_table` ✅
كلها نُفِّذت بنجاح في آخر `migrate` كامل على قاعدة نظيفة.

---

## 8. Remaining Action — مطلوب منك على Production

> **مهم:** الإصلاح أعلاه يحل حالة `password_reset_tokens` و`users` تحديدًا (وهي المذكورة في الخطأ). لكن **إذا كان جدول `migrations` في Production ناقصًا صفوفًا أخرى**، فسيظهر نفس الخطأ 1050 على أول migration غير محمي بعده. **لهذا يجب تنفيذ الخطوات التالية — لا يمكن ولا يجوز حلها بالكود وحده.**

### الخطوة 1 — تشخيص (قراءة فقط، لا تكتب شيئًا)

على Render: افتح الخدمة → **Shell**، ثم:

```bash
php artisan migrate:status
php artisan db:migration-doctor
```

كلاهما **للقراءة فقط**. أو نفّذ الاستعلام الذي طلبته مباشرة على Clever Cloud:

```sql
SELECT migration, batch FROM migrations ORDER BY batch DESC, migration DESC;
```

**قبل أي شيء، احفظ نسخة من جدول الحوكمة (قراءة فقط):**

```bash
php artisan tinker --execute="file_put_contents('migrations_backup.json', DB::table('migrations')->get()->toJson());"
```

### الخطوة 2 — الـBaseline (يُنفَّذ يدويًا بعد مراجعتك — لم أنفّذه)

إذا أظهر التشخيص صفوفًا مصنَّفة **"already applied but unrecorded"**، فهذه migrations جداولها موجودة أصلًا ويجب **تسجيلها دون تنفيذها**. الأمر `db:migration-doctor` يطبع لك جملة `INSERT` جاهزة بالـbatch الصحيح، مثل:

```sql
-- راجعها أولًا، ثم نفّذها على قاعدة Clever Cloud
INSERT INTO migrations (migration, batch) VALUES
  ('0001_01_01_000000_create_users_table', <max_batch+1>),
  ('0001_01_01_000001_create_cache_table', <max_batch+1>);
```

بديل بدون MySQL client، من Shell الخاص بـRender:

```bash
php artisan tinker --execute="DB::table('migrations')->insert([['migration'=>'0001_01_01_000000_create_users_table','batch'=>N],['migration'=>'0001_01_01_000001_create_cache_table','batch'=>N]]);"
```

**قواعد صارمة عند التنفيذ:**
- سجّل فقط الـmigrations التي **جداولها موجودة فعلًا** — لا تسجّل migration جداوله مفقودة، وإلا لن تُنشأ أبدًا.
- **لا تحذف أي صف** من جدول `migrations`.
- استخدم `batch` جديدًا (`MAX(batch)+1`) ولا تلمس الصفوف القديمة.
- إن كان الـpending من نوع `Schema::table` (ALTER) فتحقق يدويًا من وجود الأعمدة قبل تسجيله.

### الخطوة 3 — إعادة النشر والتحقق

```bash
php artisan migrate --force     # أو أعد تشغيل الـdeploy على Render
php artisan migrate:status      # يجب أن يكون كل شيء Ran / 0 Pending
php artisan db:migration-doctor # يجب أن يقول: No pending migrations
```

### حالات خاصة

| ما يظهره التشخيص | ما يجب فعله |
|---|---|
| `migrations` **مفقود كليًا** والقاعدة مليئة بالجداول | شغّل `db:migration-doctor` — سيسرد كل ملف مع حالة جداوله، وسجّل الـ"already applied" فقط. **لا تشغّل `migrate` قبل ذلك** |
| صفوف **مشوّهة** (تحتوي `/` أو `.php`) | هذه مشكلة تنسيق: `UPDATE migrations SET migration = REPLACE(REPLACE(migration,'database/migrations/',''),'.php','');` بعد نسخة احتياطية |
| **orphans** (صفوف بلا ملف) | غير مؤذية لـ`migrate`؛ اتركها ولا تحذفها |
| migration يُظهر تحذير `DROPS` | لا تُسجّله (baseline) إلا بعد التأكد أن إسقاط جدوله مقبول — أو دعـه يُنفَّذ كاملًا |

### متابعة مقترحة (ليست عاجلة)

1. **`2026_01_01_000300_create_role_permission_table.php` اسمه مضلِّل** — يُنشئ `password_reset_tokens`. **لا تُعد اسمه ولا تحذفه** (سيُعاد تنفيذه على Production). وثّقه فقط.
2. **أربع أزواج من الـmigrations تتشارك نفس الـtimestamp**: `2026_09_08_000001`، `2026_09_22_000001`، `2026_09_22_000002`، `2026_09_29_000002`. الترتيب يبقى حتميًا (Laravel يرتب بالاسم الكامل) وقد تحقّقت من نجاحه، لكنه هشّ — الأفضل تفريق الـtimestamps في تعديلات قادمة.
3. **جعل الـmigrations المستقبلية ذرّية**: جدول واحد لكل migration قدر الإمكان، حتى لا يترك فشلٌ جزئيٌّ جدولًا بلا تسجيل (وهو أصل هذا الانحراف).

---

## ملخص في سطر واحد

الكود كان سليمًا لقاعدة نظيفة، لكنه هشّ تجاه قاعدة موجودة؛ **السبب الجذري هو عدم توافق جدول `migrations` مع الـschema الفعلي في Production** — أُصلحت الهشاشة في الكود (4 ملفات، بلا أي عملية مدمِّرة)، وبقيت خطوة واحدة يدوية: **تسجيل الـmigrations المنفَّذة سابقًا في جدول `migrations` دون إعادة تنفيذها**، بعد تشخيص `db:migration-doctor`.
