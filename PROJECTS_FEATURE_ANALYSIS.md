# تحليل ميزة المشاريع (Projects) — SkillSpan Back-End

> تحليل من الكود نفسه: الجداول، الموديلات، الخدمات، المسارات، **والفجوة بين اللي متصمّم واللي متطبّق**.

---

## الخلاصة في سطر واحد

**المشاريع في النظام ده للقراءة بس.** كل الكود بيستهلك مشاريع موجودة مسبقًا — **مفيش ولا سطر واحد
بيعمل `Project`**: لا endpoint، لا seeder، لا factory، لا أمر artisan.

```bash
grep -rn "Project::create\|Project::forceCreate\|new Project(" app/ database/ routes/
# → مفيش ولا نتيجة
```

يعني لو الداتابيز فاضية، **مفيش أي طريقة تعمل مشروع من الكود**. لازم إدخال يدوي.

---

## 1. الجداول — 8 جداول

| الجدول | الغرض | له API؟ |
|---|---|---|
| `projects` | المشروع نفسه (27 عمود) | **قراءة بس** |
| `project_required_skills` | المهارات المطلوبة + `minimum_level` + `is_critical_entry` | ❌ |
| `project_eligibility_constraints` | شروط الأهلية (`constraint_type` + `value`) | ❌ |
| `project_roles` | الأدوار المتاحة للتقديم (`title`, `description`, `is_active`) | ❌ (بتُقرأ) |
| `project_teams` | فرق المشروع | ❌ |
| `project_team_members` | أعضاء الفريق + `assignment_state` | ❌ |
| `project_milestones` | مراحل التنفيذ | ❌ |
| `project_matching_snapshots` | لقطة ثابتة لكل عملية مطابقة | ❌ (بتتكتب داخليًا) |

**كمان موجودة ومرتبطة:** `rubrics`, `rubric_dimensions`, `submissions`, `evaluations`,
`evaluation_dimension_scores`, `evaluation_disputes` — والموديل `Project` له علاقات ليهم
(`rubric()`, `submissions()`, `evaluations()`) **بس مفيش controllers ليهم خالص**.

---

## 2. دورة الحياة — متصمّمة كاملة، متطبّق جزء صغير

عمود `status` في الجدول معرّف بـ 7 حالات:

```
draft → pending_review → open → closed → in_progress → completed → archived
```

وكمان فيه `approved_by` + `approved_at` — يعني **فيه workflow موافقة مقصود** (المشروع بيتعمل draft،
بيترفع للمراجعة، بيتعتمد، وبعدين يفتح).

**اللي متطبّق فعلًا:**

| الحالة | المعنى | متطبّق؟ |
|---|---|---|
| `draft` | المشروع اتعمل لسه | ❌ مفيش إنشاء أصلًا |
| `pending_review` | مستني مراجعة | ❌ مفيش endpoint للمراجعة |
| **`open`** | **متاح للطلاب** | ✅ **الاستكشاف + التفاصيل + المطابقة + التقديم** |
| `closed` | اتقفل | ❌ مفيش endpoint (تعديل يدوي) |
| `in_progress` | شغّال | ❌ مفيش endpoints (فرق/مراحل/تسليمات) |
| `completed` | خلص | ❌ مفيش تقييم |
| `archived` | مؤرشف | ❌ |

**يعني ~15% من الدورة متطبّق** — والباقي متصمّم في الـ schema بس.

---

## 3. الخدمات — 8 خدمات

| الخدمة | دورها |
|---|---|
| `ProjectAccessService` ⭐ | مصدر واحد لـ "الطالب يشوف أي مشاريع" — **جديد من زميلك** |
| `ProjectAvailabilityService` | هل المشروع متاح؟ (`status='open'` + `application_deadline` لسه ما عدّاش) |
| `ProjectEligibilityService` | هل الطالب أهل للمشروع؟ (شروط `work_mode` و `schedule` بس) |
| `ProjectCapacityPolicy` | المقاعد: مين بياخد مقعد، وهل نمنع تقديم لما يمتلي |
| `ProjectMatchingPayloadBuilder` | بيبني الـ payload اللي يروح لـ FastAPI |
| `ProjectMatchingSnapshotService` | بيتحقق من الصلاحية والأهلية و**يحفظ لقطة ثابتة** قبل أي نداء خارجي |
| `ProjectMatchingService` | بينده FastAPI `/api/v1/project-matching` ويتحقق من الرد |
| `ProjectMatchingRecommendationService` | يحفظ النتيجة في `recommendations` (بدون نداء تاني) |

**نقطة تصميم مهمة:** الاستكشاف **مابيفلترش** بالأهلية. يعني المشروع اللي الطالب مش أهل له **يفضل
ظاهر** في الكتالوج، والأهلية بتتفرض وقت **المطابقة** (`ProjectMatchingSnapshotService`). السبب: ما
نحرمش الطالب من رؤية المشروع، بس نمنع إنه يقدّم عليه.

---

## 4. مسار الطالب خطوة بخطوة

```
POST /api/v1/profile                    ← ينشئ StudentProfile (إلزامي)
        ↓
GET /api/v1/projects                    ← الكتالوج: متاح + مصرّح له بس
        ↓
GET /api/v1/projects/{id}               ← التفاصيل + available_project_roles
        ↓
POST /api/v1/projects/{id}/match        ← مطابقة AI (بتكتب snapshot + recommendation)
        ↓
GET /api/v1/projects/{id}/recommendation ← شرح النتيجة المحفوظة (قراءة بس)
        ↓
POST /api/v1/projects/{id}/applications ← التقديم
        ↓
GET /api/v1/applications                ← متابعة / سحب
```

**ومسار صاحب المشروع:**
```
GET   /api/v1/projects/{id}/applications            ← يشوف المتقدمين
PATCH /api/v1/projects/{id}/applications/{app}      ← shortlisted / accepted / rejected / waitlisted
```

---

## 5. قواعد الوصول (مهمة للاختبار)

### متى يظهر المشروع في الكتالوج؟

لازم **الأربعة** دول مع بعض (`ProjectController::accessibleProjectsQuery`):

1. `status = 'open'` ← **الافتراضي `draft`، فلازم تحدده صراحة**
2. `end_date` فاضي أو ≥ النهاردة
3. `application_deadline` فاضي أو ≥ النهاردة
4. `confidentiality = 'public'` **أو** (`restricted` + `organization_id` = مؤسسة الطالب)

### المؤسسات المقيّدة

`restricted` بتظهر بس للطالب اللي عنده عضوية **`status='active'`** في `organization_members`.
العضويات `invited` و `removed` **مابتديش أي صلاحية**. والطالب ممكن ينتمي لأكتر من مؤسسة فيشوف
المقيّد بتاع كلهم.

### شروط الأهلية — نوعان شغّالان بس من 4

| النوع | متطبّق؟ | بيتقارن بإيه |
|---|---|---|
| `work_mode` | ✅ | `student_profiles.preferred_work_type` |
| `schedule` | ✅ | `student_profiles.availability` |
| `location` | ❌ متخزّن ومتجاهَل | مفيش عمود مقابل |
| `language` | ❌ متخزّن ومتجاهَل | مفيش عمود مقابل |

---

## 6. الفجوة — إيه الناقص بالظبط

| الناقص | الأثر |
|---|---|
| **إنشاء مشروع** (`POST /projects`) | **حاجز كامل** — مفيش مشاريع، فمفيش اختبار |
| تعديل مشروع (`PATCH /projects/{id}`) | مفيش تغيير في البيانات |
| رفع للمراجعة + اعتماد (`pending_review` → `open`) | `approved_by`/`approved_at` مش بيتكتبوا أبدًا |
| قفل/أرشفة | `closed`/`archived` يدوي بس |
| إدارة الأدوار والمهارات المطلوبة | `project_roles` و `project_required_skills` يدوي |
| الفرق (`project_teams`) | الجدول والموديل موجودين، مفيش API |
| المراحل (`project_milestones`) | نفس الحالة |
| التسليمات والتقييمات (`submissions`/`evaluations`) | نفس الحالة |
| `rubrics` | علاقة موجودة، مفيش API |

---

## 7. حاجات جديدة في نسخة زميلك (لسه مش مسحوبة عندك)

⚠️ **دي على `origin/feature/authentication` ولسه مش عندك محليًا.**

- **`GET /api/v1/projects` بقى مُصفّح (paginated)** — `page` و `per_page` (1–50، افتراضي 50) وبيرجّع
  `meta`. قبل كده كان `limit(50)` بدون pagination.
- **endpoint جديد:** `GET /api/v1/projects/{project}/recommendation` — شرح محفوظ، **قراءة بس** ومابيندهش
  FastAPI. بيرجّع `404 PROJECT_NOT_FOUND` لو المشروع مش موجود **أو** مش مسموح له (نفس الرد في الحالتين،
  عشان ما ينفعش تستخدمه للاستطلاع على مشاريع مقيّدة).
- **حجب في قائمة التوصيات:** لو الطالب بقى مش قادر يوصل لمشروع، الصف **مابيتحذفش** — بس بيتحجب:
  `access_revoked = true` وكل حقول النتيجة تبقى `null` (`project`, `score`, `reasons`,
  `limiting_factors`, `algorithm_version`, …). الحقول التعريفية بس هي اللي تفضل (`id`, `type`,
  `project_id`, `generated_at`).
- **`minimum_level` من غير `skill_ids`** → `422` صريح بدل ما يتجاهله بصمت.
- **`skill_ids[]`** بقت **set** — التكرار بيتشال.

**الترتيب بعد السحب:** اختبارات، وبعدين تحديث الـ Postman collection (endpoint جديد + pagination +
`access_revoked`).

---

## 8. إزاي تعمل مشروع للاختبار (مؤقتًا)

الأعمدة الإلزامية **3 بس** (`owner_id`, `type`, `title`) وكلهم في `$fillable`:

```php
php artisan tinker

App\Models\Project::create([
    'owner_id'   => App\Models\User::first()->id,
    'type'       => 'simulation',   // أو company_sponsored
    'title'      => 'مشروع تجريبي',
    'status'     => 'open',         // ⚠️ ضروري — الافتراضي draft
    'capacity'   => 3,
    'difficulty' => 3,
]);
```

عايز تقديم بـ `project_role_id`؟ ضيف دور كمان:

```php
$p = App\Models\Project::first();
$p->projectRoles()->create(['title' => 'Backend Developer', 'is_active' => true]);
```

> ⚠️ ده **حل مؤقت**. الحل الصح إنشاء endpoint `POST /api/v1/projects` لصاحب المشروع.
