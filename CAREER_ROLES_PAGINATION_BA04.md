# BA-04 — تثبيت Pagination Contract الخاص بـ Career Roles

> **القرار النهائي: الخيار A** — الـEndpoint صار يدعم `per_page` بشكل صحيح، بـ**default = 15** و
> **maximum = 100** (بيتقلّم، ما بيرجعش 422). الـresponse shape **ما تغيّر**.
>
> **النطاق:** Backend فقط (Laravel).

---

## ١. الـEndpoint

```
GET /api/v1/career-roles
```

| البند | القيمة |
|---|---|
| Method | `GET` |
| Auth | `Authorization: Bearer <token>` (Sanctum) |
| الدور | `learner` + الحساب `active` |
| Controller | `App\Http\Controllers\Api\CareerRoleController::index()` |
| Request | `App\Http\Requests\ListCareerRolesRequest` |
| Resource | **مفيش** Resource — الرد بيتبني inline |

---

## ٢. الفحص — الوضع قبل BA-04

| السؤال | الجواب (قبل) |
|---|---|
| هل `per_page` مدعوم؟ | **لأ** |
| الـdefault page size | **15** (hardcoded: `->paginate(15)`) |
| هل فيه maximum؟ | **لأ** — الرقم ثابت في الكود، مش قابل للتعديل |
| هل `per_page` بيتم تجاهله؟ | **أيوه، بصمت** — أي `?per_page=N` كان بيتجاهل والرد دايمًا 15 |
| شكل الـpagination response | الـpaginator **جوه `data`**، ومفيش مفتاح `meta` |

`ListCareerRolesRequest` كان بيـvalidate `specialization_id` **بس** — مفيش أي فاليديشن أو قراءة لـ`per_page`.

السبب المباشر: الكنترولر كان بيبعت الرقم كـliteral — `->paginate(15)` — فالـquery string ما كان بيتقراش أصلًا.

### ليه كان كده؟ (وسياق مهم)
الكنترولر كان مكتوب فيه صراحةً إن «`per_page` مثبّت على 15 لأن عقد الفرونت بيوثّق الترقيم كـfixed
(handoff Pattern C)»، و`FRONTEND_API_HANDOFF.html` كان فعلًا بيقول «ترقيم مثبَّت على 15».
يعني كان **قرار مقصود**، ومغطّى بتست (`..._pagination_is_fixed_at_15_...`).

---

## ٣. ليه اخترنا الخيار A (مش B)

| الاعتبار | النتيجة |
|---|---|
| **SRS NFR-PERF-03 (Must)** | «List endpoints shall use pagination and **enforce a configurable maximum page size**» — رقم ثابت 15 ما بيدّيش أي maximum قابل للتعديل، فالمتطلب مش متحقق |
| **SRS §7.3** | «Pagination shall expose consistent ... page metadata and enforce maximum page size» |
| **عرف المشروع** | `README` §Pagination + `FRONTEND_API_HANDOFF` §4 + `ApplicationController`/`RecommendationController`: الاتفاق العام هو `per_page` default 15 وأقصى 100 |
| **الاستثناء** | `career-roles` كان **الـendpoint الوحيد** في المشروع اللي بيتجاهل `per_page` — وده مسجّل كملاحظة في `CONVERSATION_API_TEST_MATRIX.md` («Either honour `per_page` here too, or document these as fixed-size») |
| **التوافق للخلف** | الـdefault فضل 15 → أي عميل ما بيبعتش `per_page` (الفرونت الحالي) بياخد نفس الرد بالحرف |

⚠️ **ما عملناش أي special-case للرقم 50.** الرقم 50 بيشتغل لأنه ببساطة جوه المدى القياسي 1–100، مش لأن الفرونت طلبه.

⚠️ **الخيار B كان وارد** (نوثّق إنه fixed ونخلّي الفرونت يتعامل)، لكنه كان هيسيب NFR-PERF-03 ناقص، وكان هيخلّي
`career-roles` استثناء دائم من عرف المشروع. الخيار A بيشيل الاستثناء وهو آمن تمامًا.

---

## ٤. العقد النهائي (Pagination Contract)

### الـParameters المدعومة
| Param | النوع | افتراضي | ملاحظات |
|---|---|---|---|
| `page` | integer | `1` | سلوك Laravel القياسي |
| `per_page` | integer | **15** | المدى الفعّال **1..100** |
| `specialization_id` | integer | — | فلتر اختياري (زي قبل) |

### القواعد
- `per_page` غير موجود / مش رقم / `<= 0` → **15** (الافتراضي).
- `per_page > 100` → **بيتقلّم لـ100** (مش 422). القيمة اللي بترجع في `data.per_page` هي القيمة الفعلية المطبّقة.
- عشان نمنع unbounded result set، مستحيل تطلب الجدول كله في طلب واحد.
- الترتيب زي قبل: `version DESC, id DESC` (tie-breaker ثابت → الترقيم deterministic).

### شكل الـResponse (ما تغيّرش)
```json
{
  "success": true,
  "message": "Career roles retrieved successfully.",
  "data": { "current_page": 1, "data": [ ... ], "per_page": 15, "total": 42, "last_page": 3, ... },
  "request_id": "…"
}
```
- العناصر في **`data.data`**، والـpaginator **جوه `data`**، و**مفيش مفتاح `meta`** (نمط C).

---

## ٥. أمثلة حقيقية (من سيرفر Laravel شغّال فعليًا)

### (أ) `GET /api/v1/career-roles?per_page=50` — و20 role معتمد موجودين
```http
GET /api/v1/career-roles?per_page=50
Authorization: Bearer <learner-token>
Accept: application/json
```
```json
{
  "success": true,
  "message": "Career roles retrieved successfully.",
  "data": {
    "current_page": 1,
    "data": [ { "id": 20, "title": "Role 20", "slug": "role-20", "version": 1, "status": "approved",
                "effective_date": "2026-10-12T00:00:00.000000Z", "skills_count": 0 } ],
    "per_page": 50,
    "total": 20,
    "last_page": 1
  },
  "request_id": "…"
}
```
**قبل BA-04:** نفس الطلب كان يرجّع `per_page: 15` و15 عنصر. **بعد:** `per_page: 50`.

### (ب) `GET /api/v1/career-roles` (بدون `per_page`) — ما تغيّرش
`per_page: 15` · `total: 20` · `last_page: 2` · **15 عنصر في الصفحة الأولى** (نفس السلوك القديم بالحرف).

### (ج) `GET /api/v1/career-roles?per_page=100000` — الحد الأقصى
`per_page: 100` (اتقلّم) · العناصر اللي رجعت: 20.

---

## ٦. الملفات اللي اتغيّرت

| # | الملف | التغيير |
|---|---|---|
| 1 | `app/Http/Controllers/Api/CareerRoleController.php` | ثوابت `DEFAULT_PER_PAGE=15` / `MAX_PER_PAGE=100` + `perPage()` (نفس نمط `ApplicationController`)؛ `paginate(15)` → `paginate($this->perPage($request))` |
| 2 | `app/OpenApi/Paths/EvidenceCareerRoleEndpoints.php` | وصف `careerRolesIndex` + parameter جديد `per_page` + وصف الـpaginator |
| 3 | `FRONTEND_API_HANDOFF.html` | النمط C + قائمة الاستثناءات (§4) اتحدّثوا |
| 4 | `tests/Feature/CareerRoles/CareerRoleRetrievalTest.php` | **+7 tests** جديدة؛ وإعادة تسمية `..._fixed_at_15_...` → `..._default_page_size_is_15_...` (نفس الـassertions) |
| 5 | `storage/api-docs/api-docs.json` | مُولَّد: `php artisan l5-swagger:generate` |

> **ما تغيّرش:** الـroutes، الـauthorization، الـresponse envelope، ترتيب الصفوف، ولا شكل الـpaginator.

---

## ٧. الـTests

- **جديدة (7):** `per_page` بيتنفّذ · `per_page=50` بيشتغل · التقليم على 100 · القيم غير الموجبة بترجع للافتراضي ·
  القيم غير الرقمية بترجع للافتراضي · شكل الرد ما اتغيّرش (مفيش `meta`) · `per_page` مع فلتر `specialization_id`.
- **موجودة وعدّت:** كل الـCareerRoles (بما فيها `FreeTrackTest` و`CareerRoleSpecializationTest`).

**نتيجة التشغيل:**
```
php artisan test tests/Feature/CareerRoles
Tests:    50 passed (182 assertions)

php artisan test            # السويت كامل
Tests:    1544 passed (10643 assertions)   # 0 failures
```

---

## ٨. هل مطلوب تغييرات من الفرونت؟

**لأ، ولا سطر.** الفرونت الحالي بيقرا `data.data` + `data.current_page` + `data.per_page` + `data.total` —
كلها زي ما هي. لو حابب يستفيد من `per_page`، يبعت `?per_page=50` وبس.

## ٩. القيود المتبقية
- الـ`meta` block لسه مش موجود (الـpaginator جوه `data`) — تغييره كان هيكسر الفرونت، فاتركب زي ما هو (نمط C).
- `per_page` بيتقلّم بصمت ولا بيرجّع 422 — متوافق مع `ApplicationController`/`RecommendationController`.
- `GET /api/v1/projects` لسه بيرجّع مصفوفة عادية بحد 50 عنصر بلا ترقيم (استثناء تاني موثّق، خارج BA-04).
