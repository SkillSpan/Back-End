# الشركة — كل الروابط من الألف للياء

هالملف بيغطي **رحلة حساب الشركة كاملة**: من التسجيل، للدخول، لانتظار الموافقة، للداشبورد،
لملف الشركة، للمواهب، وللإشعارات. كل رابط مكتوب بشكله النهائي، مع مين بيقدر يناديه وأي كود
خطأ بيرجع.

الروابط اللي انضافت بقصة **US-COMP-04** معلّمة بـ **جديد**. الباقي كان موجود وثابت.

---

## 0) قبل ما تبدأوا

| البند | القيمة |
|---|---|
| عنوان الخدمة | `https://skillspan.alwaysdata.net` |
| بادئة كل الروابط | `/api/v1` |
| ترويسة المصادقة | `Authorization: Bearer <token>` |
| ترويسة إلزامية | `Accept: application/json` — بدونها الخدمة بترجع تحويل HTML بدل 401 |
| شكل الرد الناجح | `{ success: true, message, data, request_id }` |
| شكل الرد الفاشل | `{ success: false, message, code, errors?, request_id }` |

**`request_id`:** كل رد بيرجّع واحد. خزّنوه بلوج الأخطاء — بيسرّع تتبّع أي شكوى.

**`code` هو اللي بتفرّعوا عليه، مش نص الرسالة.** الرسائل قابلة للتغيير، الأكواد ثابتة.

---

## 1) الرحلة كاملة — أي رابط بأي شاشة

| # | الشاشة | الروابط اللي بتستدعيها |
|---|---|---|
| 1 | تسجيل شركة | `POST /auth/register/organization` |
| 2 | دخول | `POST /auth/login/organization` |
| 3 | انتظار الموافقة | ولا رابط — كل روابط الشركة بترجع **403** بكود `ORGANIZATION_PENDING` |
| 4 | الداشبورد | `GET /company/dashboard` + `GET /notifications/unread-count` |
| 5 | ملف الشركة | `GET /profile/me` · `PATCH /profile/me` · رفع وتنزيل المستندات |
| 6 | المواهب | `GET /talent` · `GET /talent/filters` · `GET /talent/{student}` |
| 7 | جرس الإشعارات | `GET /notifications` · `PATCH /notifications/{id}/read` · `POST /notifications/read-all` |
| 8 | خروج | `POST /auth/logout` |

---

## 2) التسجيل والدخول

### `POST /api/v1/auth/register/organization`

`multipart/form-data` — لأن ملف الإثبات إلزامي.

| الحقل | إلزامي | ملاحظة |
|---|---|---|
| `name` | نعم | اسم الشخص المسؤول |
| `email` | نعم | فريد على مستوى المنصة |
| `password` + `password_confirmation` | نعم | 8 أحرف على الأقل |
| `terms_accepted` · `privacy_accepted` | نعم | لازم `true` |
| `organization_name` | نعم | اسم الشركة |
| `organization_type` | نعم | `company` · `university` · `training_partner` — للشركة `company` |
| `organization_contact_email` | نعم | إيميل التواصل |
| `organization_industry` · `organization_company_size` · `organization_website` · `organization_description` | لا | اختياري |
| `organization_country` · `organization_city` · `organization_address` · `organization_postal_code` | لا | اختياري |
| `phone` | لا | |
| `proof_file` | نعم | `jpg` · `jpeg` · `png` · `pdf`، حتى 5 ميغابايت |

الرد بيرجّع `user_id` و`email` و`status`. **الحساب مش مفعّل بعد** — لازم موافقة الإدارة.

### `POST /api/v1/auth/login/organization`

```json
{ "email": "admin@acme.com", "password": "..." }
```

```json
{
  "success": true,
  "message": "Logged in successfully.",
  "data": {
    "user": { "...": "..." },
    "organizations": [{ "...": "..." }],
    "token": "1|xxxxxxxx",
    "token_type": "Bearer",
    "redirect_url": "/company/dashboard"
  }
}
```

**اقروا `redirect_url` من الرد، ما تحسبوها بأنفسكم من الأدوار.** لو المؤسسة لسا تحت المراجعة
وجّهوا لشاشة الانتظار — الخدمة بترفض روابط الشركة بكود `ORGANIZATION_PENDING`.

**محاولات الدخول:** بعد 5 محاولات فاشلة بيرجع **429** مع ترويسة `Retry-After` (بالثواني) — مش 422.
افصلوا بين الحالتين في الواجهة.

### `POST /api/v1/auth/logout`

بيحتاج التوكن. بيلغي التوكن الحالي بس. `POST /auth/logout-all` بيلغي كل التوكنات.

---

## 3) حالة الموافقة — أهم شي بالرحلة

حساب الشركة بيمرق بثلاث حالات، والفرق بينهن هو **كود الرفض** اللي بترجعه كل الروابط:

| حالة المؤسسة | كود الرفض | شو تعرضوا |
|---|---|---|
| `pending` | `ORGANIZATION_PENDING` (403) | شاشة "طلبكم تحت المراجعة" |
| `rejected` | `ORGANIZATION_NOT_APPROVED` (403) | شاشة رفض مع سبب التواصل مع الدعم |
| `verified` | — | الشاشات عادية |

وكودين إضافيين مش متعلقين بالموافقة:

| الحالة | كود الرفض |
|---|---|
| الحساب دوره مش `company_admin` | `COMPANY_ADMIN_ONLY` (403) |
| الحساب مش تابع لأي مؤسسة | `NO_ORGANIZATION` (403) |
| عضو بالمؤسسة بس مش أدمن فيها | `NOT_ORGANIZATION_ADMIN` (403) |

**نصيحة تنفيذ:** اعملوا اعتراض (interceptor) واحد على 403 يقرأ `code` ويحوّل لشاشة الانتظار أو
الرفض. هيك ما بتكرّروا المنطق بكل صفحة.

---

## 4) الداشبورد — `GET /api/v1/company/dashboard`

```json
{
  "success": true,
  "message": "Company dashboard retrieved successfully.",
  "data": {
    "counts": { "projects": 4, "applicationsPending": 2, "unreadNotifications": 7 },
    "permissions": { "...": "ما اللي مسموح للحساب" },
    "tasks": [{ "...": "المهام المقترحة على الشركة" }],
    "projectStates": { "draft": 1, "submitted": 1, "open": 2, "completed": 1 },
    "recentProjects": [{ "...": "أحدث المشاريع مع المقاعد" }],
    "funnel": { "total": 9, "stages": {}, "byProject": [] }
  }
}
```

- `projectStates` فيه **كل** الحالات الاثنتي عشرة حتى لو صفر — ارسموا الشبكة من غير ما تعبّوا
  فراغات بأنفسكم: `draft` · `submitted` · `changes_requested` · `approved` · `rejected` ·
  `open` · `selection` · `active` · `under_review` · `completed` · `cancelled` · `archived`.
  قيمة `closed` القديمة **ما بترجع** — لا تحسبوا حسابها.
- `counts.unreadNotifications` هو اللي بيغذّي شارة الجرس.
- نسبة اكتمال الملف موجودة في `/profile/me` — الداشبورد بيقرأ نفس القيمة من نفس المكان.

---

## 5) الإشعارات — ستة روابط

| الطريقة | الرابط | الوظيفة |
|---|---|---|
| GET | `/api/v1/notifications` | القائمة — `?unread=1` (أو `unread_only=1`) غير المقروءة بس، `?category=`، `?per_page=` افتراضي 20 وأقصى 50. `meta.unread_count` جاهز فيها فما تحتاجوا نداء تاني للجرس |
| GET | `/api/v1/notifications/unread-count` | `{ unread_count: 7 }` — خفيف، للجرس |
| PATCH | `/api/v1/notifications/{id}/read` | تعليم واحد كمقروء (`POST` نفس المسار مقبول كاسم قديم) |
| POST | `/api/v1/notifications/read-all` | تعليم الكل كمقروء |
| GET | `/api/v1/notifications/preferences` | تفضيلات الإشعارات |
| PUT | `/api/v1/notifications/preferences` | حفظ التفضيلات |

هالروابط **مش** محجوزة بدور `company_admin` — أي حساب مسجّل بيشوف إشعاراته هو بس.

---

## 6) ملف الشركة

### `GET /api/v1/profile/me` — **جديد**

```json
{
  "success": true,
  "message": "Organization profile retrieved successfully.",
  "data": {
    "id": 12,
    "name": "Acme Industries",
    "type": "company",
    "industry": "Manufacturing",
    "company_size": "51-200",
    "website": "https://acme.com",
    "description": "We build things.",
    "country": "Syria",
    "city": "Damascus",
    "address": null,
    "postal_code": null,
    "contact_email": "hello@acme.com",
    "contact_phone": null,
    "logo": null,
    "verification_status": "verified",
    "verified_at": "2026-10-20T09:12:00+00:00",
    "reverification": {
      "required": false, "requested_at": null,
      "policy": "flag", "fields": ["name"], "message": null
    },
    "documents": {
      "business_registration": {
        "id": 5, "type": "business_registration", "name": "registration.pdf",
        "mime_type": "application/pdf", "size": 120422, "version": 1,
        "status": "pending", "uploaded_at": "2026-11-03T14:02:11+00:00",
        "available": true, "url": "/api/v1/company/profile/documents/5"
      },
      "additional": null
    },
    "profile_completeness": 83,
    "profile_fields": [{ "key": "info", "level": "required", "done": true }],
    "completeness": {
      "percent": 83, "version": "v1",
      "missing": [{ "field": "website", "level": "recommended" }]
    },
    "profile_updated_at": "2026-11-03T14:00:00+00:00",
    "profile_updated_by": 7,
    "updated_at": "2026-11-03T14:00:00+00:00"
  }
}
```

| الحقل | النوع | المعنى |
|---|---|---|
| `profile_completeness` | رقم 0–100 | نفس المفتاح اللي الداشبورد بيقرأه من قبل — ما تغيّر |
| `profile_fields` | مصفوفة | `key` + `level` + `done` لكل مجموعة حقول |
| `completeness.percent` | رقم 0–100 | نفس القيمة أعلاه، بشكل أوضح للشاشة الجديدة |
| `completeness.version` | نص | نسخة قواعد الاحتساب (`v1`) — اعرضوها في التشخيص بس |
| `completeness.missing` | مصفوفة | `field` بيطابق `profile_fields[].key`، و`level` إما `required` أو `recommended` |
| `documents` | كائن | سلوتين ثابتين: `business_registration` و `additional`. أي سلوت ما انرفع فيه شي = `null` |
| `documents.*.status` | نص | `pending` · `approved` · `rejected` — أي استبدال بيرجع `pending` |
| `documents.*.version` | رقم | 1 لأول رفع، وبيزيد مع كل استبدال |
| `documents.*.available` | منطقي | `false` معناها السجل موجود والملف مش على التخزين — اعرضوا "غير متاح" بدل رابط بيرجع 404 |
| `documents.*.url` | نص | جاهز للاستخدام — ما تبنيوا الرابط بأنفسكم |
| `reverification` | كائن | `required` + `requested_at` + `policy` + `fields` + `message` |

**مجموعات الحقول الستة:** `info` · `contact` · `address` (إلزامية) و `size` · `website` ·
`description` (مستحسنة). النسبة بتتحسب من الإلزامي أولاً، والمستحسن بيرفعها.

### `GET /api/v1/organization/profile`

المسار القديم — بيرجّع **نفس** الـ payload بالحرف، وما بيحتاج `company_admin` (بيمشي على العضوية
لأن مكاتب الجامعات وشركاء التدريب بيستخدموه كمان). **بلا تعديل** — التعديل على `/profile/me` بس.

### `PATCH /api/v1/profile/me` — **جديد**

تعديل جزئي — ابعتوا الحقول اللي اتغيّرت بس.

```json
{
  "name": "Acme Holdings",
  "city": "Aleppo",
  "website": "acme.com"
}
```

`website` بيقبل بدون `https://` والخدمة بتضيفه. الرد:

```json
{
  "success": true,
  "message": "Organization profile updated successfully.",
  "data": {
    "profile": { "...": "نفس شكل رد القراءة" },
    "changed": ["name", "website", "city"],
    "reverification_triggered": true,
    "reverification": {
      "required": true,
      "requested_at": "2026-11-03T14:10:00+00:00",
      "policy": "flag",
      "fields": ["name"],
      "message": "A field that identifies your organization changed, so its verification is being reviewed again."
    }
  }
}
```

- `changed` فيها أسماء الحقول اللي انكتبت فعلاً. لو ما تغيّر شي بترجع فاضية `[]` — هاد مش خطأ.
- لو `reverification_triggered` صحيحة، اعرضوا تنبيه "بياناتكم تحت المراجعة من جديد" بدون ما
  تغيّروا شارة `verification_status` — بتضل `verified` مع بوليسي `flag`.

**الحقول القابلة للتعديل:** `name` · `industry` · `company_size` · `website` · `description` ·
`country` · `city` · `address` · `postal_code` · `contact_email` · `contact_phone`

**الحقول للقراءة فقط:** `type` · `verification_status` · `verified_at`. بعت قيمة مختلفة لهاد
الحقول = 422 `FIELD_NOT_EDITABLE`. بعت نفس القيمة الحالية بيمرق عادي.

ما بتقدروا تفرّغوا الإيميل **و** الهاتف مع بعض — لازم يضل واحد منهن، وإلا `PROFILE_CONTACT_REQUIRED`.

### `POST /api/v1/company/profile/documents` — **جديد**

`multipart/form-data`

| الحقل | القيمة |
|---|---|
| `type` | `business_registration` أو `additional` |
| `file` | الملف نفسه |

الحد: **5 ميغابايت**، والأنواع: `pdf` · `jpg` · `jpeg` · `png`. لازم الامتداد ونوع المحتوى
الحقيقي يطابقوا — ملف exe مغيّر اسمه لـpdf بينرفض.

```json
{
  "success": true,
  "message": "Document replaced successfully.",
  "data": {
    "document": {
      "id": 9, "type": "business_registration", "name": "registration-v2.pdf",
      "mime_type": "application/pdf", "size": 118000, "version": 2,
      "status": "pending", "uploaded_at": "2026-11-03T14:20:00+00:00",
      "available": true, "url": "/api/v1/company/profile/documents/9"
    },
    "replaced": { "id": 5, "version": 1 },
    "profile": { "...": "الملف بعد الرفع" },
    "reverification_triggered": true,
    "reverification": { "...": "..." }
  }
}
```

- `replaced` بترجع `null` إذا كان أول رفع لهذا النوع.
- المستند القديم **ما بينحذف** — بيتحفظ في سجل التدقيق، والجديد بيشاور عليه.
- بعد الاستبدال، `documents.<type>` في الملف بيرجّع النسخة الأحدث.

### `GET /api/v1/company/profile/documents/{id}` — **جديد**

بيرجع الملف نفسه (binary) مع `Content-Disposition: attachment`. المستند اللي مش تابع لمؤسستكم
بيرجع **404** مش 403 — يعني ما في طريقة تعرفوا إذا الـid موجود عند غيركم.

اعرضوا الرابط من `documents.<type>.url` مباشرة، وتذكّروا إن `available: false` معناها اعرضوا
"غير متاح" بدل زر تنزيل.

---

## 7) المواهب — ثلاثة روابط

### `GET /api/v1/talent`

| البارامتر | النوع | ملاحظة |
|---|---|---|
| `search` | نص | بحث بالاسم |
| `career_role` | نص | الـslug من `/talent/filters` |
| `min_readiness` | رقم 0–100 | أقل درجة جاهزية |
| `sort` | نص | `readiness` (الافتراضي) أو `recent` |
| `page` | رقم | من 1 |
| `per_page` | رقم | من 1 إلى 100 |

```json
{
  "success": true,
  "data": [{ "id": 31, "name": "Lina H.", "career_role": "Backend Developer",
             "readiness": { "score": 78, "band": "near_ready" }, "top_skills": [] }],
  "meta": { "current_page": 1, "last_page": 3, "per_page": 15, "total": 42 }
}
```

`sort` غير مدعوم بيرجع 422 — ما بينتجاهل بصمت.

### `GET /api/v1/talent/filters`

```json
{
  "success": true,
  "data": {
    "career_roles": [{ "id": 3, "title": "Backend Developer", "slug": "backend-developer" }],
    "readiness_bands": ["foundation_needed", "developing", "moderate_readiness",
                        "near_ready", "highly_ready"]
  }
}
```

ابنوا قائمة الفلاتر من هالرد، مش من `/career-roles` — هاد الأخير محجوز للطلاب.

### `GET /api/v1/talent/{student}`

نفس قواعد الرؤية تبع القائمة. الطالب اللي مش مسموح تشوفوه بيرجع **404** (مش 403)، عشان ما
يتحول الرابط لوسيلة تعرفوا فيها مين موجود.

---

## 8) جدول كل الروابط — فهرس واحد

| # | الطريقة | الرابط | الوظيفة | جديد؟ |
|---|---|---|---|---|
| 1 | POST | `/api/v1/auth/register/organization` | تسجيل شركة + ملف إثبات | |
| 2 | POST | `/api/v1/auth/login/organization` | دخول + `redirect_url` | |
| 3 | POST | `/api/v1/auth/logout` | خروج | |
| 4 | GET | `/api/v1/company/dashboard` | أرقام الداشبورد | |
| 5 | GET | `/api/v1/notifications` | قائمة الإشعارات | |
| 6 | GET | `/api/v1/notifications/unread-count` | عدد غير المقروء | |
| 7 | PATCH | `/api/v1/notifications/{id}/read` | تعليم كمقروء | |
| 8 | POST | `/api/v1/notifications/read-all` | تعليم الكل | |
| 9 | GET | `/api/v1/notifications/preferences` | التفضيلات | |
| 10 | PUT | `/api/v1/notifications/preferences` | حفظ التفضيلات | |
| 11 | GET | `/api/v1/organization/profile` | ملف المؤسسة — المسار القديم | |
| 12 | GET | `/api/v1/profile/me` | قراءة ملف الشركة | **جديد** |
| 13 | PATCH | `/api/v1/profile/me` | تعديل الملف | **جديد** |
| 14 | POST | `/api/v1/company/profile/documents` | رفع أو استبدال مستند | **جديد** |
| 15 | GET | `/api/v1/company/profile/documents/{id}` | تنزيل مستند | **جديد** |
| 16 | GET | `/api/v1/talent` | قائمة المواهب | |
| 17 | GET | `/api/v1/talent/filters` | قيم الفلاتر | |
| 18 | GET | `/api/v1/talent/{student}` | بروفايل موهبة | |

**الروابط 4 و12–18** محجوزة بـ `company_admin` + مؤسسة `verified`. الروابط 1–3 و5–10 عامة
لأي حساب، و11 بتمشي على العضوية.

---

## 9) أكواد الأخطاء — كاملة

| الحالة | الكود | متى |
|---|---|---|
| 401 | — (بلا `code`) | توكن ناقص أو منتهي. الرد `{"message":"Unauthenticated."}` بس — **افحصوا الحالة 401 نفسها، ما تنتظروا كود** |
| 403 | `COMPANY_ADMIN_ONLY` | التوكن مش تبع company_admin |
| 403 | `ORGANIZATION_PENDING` | المؤسسة لسا تحت المراجعة |
| 403 | `ORGANIZATION_NOT_APPROVED` | طلب المؤسسة مرفوض |
| 403 | `NOT_ORGANIZATION_ADMIN` | عضو بالمؤسسة بس مش أدمن فيها |
| 403 | `NO_ORGANIZATION` | الحساب مش تابع لأي مؤسسة |
| 404 | `DOCUMENT_NOT_FOUND` | المستند مش موجود أو مش تبعكم |
| 404 | `DOCUMENT_UNAVAILABLE` | السجل موجود والملف مش على التخزين |
| 422 | `PROFILE_VALIDATION_FAILED` | فشل تحقق — مع `errors{}` لكل حقل |
| 422 | `PROFILE_CONTACT_REQUIRED` | إيميل وهاتف فاضيين سوا |
| 422 | `FIELD_NOT_EDITABLE` | محاولة تعديل حقل للقراءة فقط |
| 422 | `DOCUMENT_TYPE_UNKNOWN` | `type` مش من القائمة |
| 422 | `DOCUMENT_TYPE_NOT_SUPPORTED` | الامتداد أو المحتوى مش مسموح |
| 422 | `DOCUMENT_TOO_LARGE` | أكبر من 5 ميغابايت |
| 422 | `DOCUMENT_MISSING` | ما وصل ملف |
| 422 | `DOCUMENT_UPLOAD_FAILED` | الرفع انقطع قبل ما يكمل — أعدوا المحاولة |
| 422 | `DOCUMENT_REJECTED_BY_SCAN` | الملف فيه محتوى تنفيذي |
| 429 | — | محاولات دخول كتير — مع ترويسة `Retry-After` |
| 500 | `DOCUMENT_STORAGE_FAILED` | الخدمة ما قدرت تحفظ الملف — أعدوا المحاولة |

شكل خطأ التحقق:

```json
{
  "success": false,
  "message": "The profile could not be saved. Please check the highlighted fields.",
  "code": "PROFILE_VALIDATION_FAILED",
  "errors": {
    "contact_email": ["The contact e-mail is not a valid e-mail address."]
  }
}
```

---

## 10) نقاط لازم تتأكدوا منها في الواجهة

1. `verification_status` قيمها بس: `pending` · `verified` · `rejected`. **ما في `approved`**.
2. ما في حالة اسمها `reverification_pending`. المراجعة الجديدة بتنقرأ من كائن `reverification`
   المنفصل، والشارة بتضل Verified مع بوليسي `flag` (الافتراضي).
3. أي رفض لازم يتفرّع على `code` مش على نص الرسالة — **إلا الـ401**: ما فيها `code`، فافحصوا
   `status === 401` مباشرة.
4. الـETag / الطلبات الشرطية ما انعملت. إذا بدكم تتفادوا إعادة الرسم، قارنوا `updated_at`.
5. `/profile/me` و `/organization/profile` بيرجّعوا **نفس** الـ payload — شاشة وحدة بتحرّك
   الاثنين. بس التعديل على `/profile/me` بس.
6. اعتراض واحد على 403 بيغطي كل شاشات الشركة (شوف §3).
7. **قواعد حساب الاكتمال (v1)** — من `config/company.php`: `info` = الاسم **و** النوع (الاثنين)،
   `contact` = إيميل **أو** هاتف (واحد يكفي)، `address` = الدولة **و** المدينة بس، و`size` ·
   `website` · `description` كل وحدة بحد ذاتها. النسبة = المجموعات المكتملة ÷ 6.
8. **فئات الإشعارات** مش enum ثابت — نص حر، واللي مستخدم اليوم: `application` ·
   `mentor_connection` · `message`. أي فئة تانية اعرضوا أيقونة افتراضية.

---

## 11) برّا نطاق هالملف

المشاريع (`/api/v1/projects/*`) والمحادثات (`/api/v1/conversations/*`) **مش من قصص الشركة** — روابط
مشتركة مع الطالب، وعقدها الكامل في دوكس المشاريع والمحادثات.
