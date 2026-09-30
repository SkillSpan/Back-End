# SkillSpan Back-End — دليل تسليم الـ API للفرونت إند

> مُولَّد من `routes/api.php` الفعلي (84 مسار) + تحقّق حقيقي على بيئة الإنتاج.
> التاريخ: 2026-09-29

---

## 0. قبل ما تبدأ — 3 أمور لازم تنتبه لها

**Base URL**

```
https://back-end-zdip.onrender.com
```

⚠️ لو شفت `back-end-zdp.onrender.com` (بدون حرف `i`) في أي مستند قديم — هذا **غلط مطبعي**،
وهذا العنوان مشغّل صفحة 404 فقط. لو الفرونت نسخه من هناك، كل الطلبات رح ترجع 404.

**كل المسارات تبدأ بـ `/api/v1`**

**لازم ترسل هذين الهيدرين مع كل طلب** — وإلا Laravel رح يرجّع HTML بدل JSON:

```
Accept: application/json
Content-Type: application/json      # only on requests that carry a body
```

---

## 1. المصادقة

- التوكن من `POST /api/v1/auth/login` — نوعه **Sanctum Bearer token**.
- يُرسَل في كل طلب محمي:

```
Authorization: Bearer <token>
```

- **حالة الحساب تحكم الوصول:** `active` / `suspended` / `pending` / `deleted`. كل المسارات
  المحمية تمرّ على middleware `account.active`، فحساب موقوف أو غير مُفعّل يُرفض حتى لو التوكن صحيح.
- **حساب المؤسسة له بوابة ثانية:** لازم تكون المؤسسة **معتمدة** من الأدمن (`organization.approved`).
  مؤسسة `pending` أو `rejected` تُرفض في **تسجيل الدخول نفسه**، مش بس في المسارات المحمية.

### هيدر مفيد: `X-Request-ID`

كل رد يحمل `request_id` في الجسم و`X-Request-ID` في الهيدرز. أرسل أنت قيمة خاصة بك في
`X-Request-ID` لو بدك تتبّع طلب معيّن — الباك إند رح يستخدمها ويرجّعها زي ما هي. استعملها في تذاكر الدعم.

---

## 2. شكل الرد — ⚠️ يوجد أكثر من نمط

هذه أهم نقطة في هذا المستند. **لا تكتب معالج أخطاء واحد يفترض وجود `success`** — لأنه ما رح ينفع مع 4 مسارات.

### النمط A — السائد (19 من 23 controller)

```json
{
  "success": true,
  "message": "Accessible projects retrieved successfully.",
  "data": { },
  "request_id": "3f2a…"
}
```

عند الفشل:

```json
{
  "success": false,
  "message": "…",
  "code": "PROJECT_NOT_FOUND",
  "errors": { "field": ["…"] },
  "request_id": "3f2a…"
}
```

### النمط B — 4 مسارات فقط ترجع JsonResource مباشرة

تنطبق على: **`/api/v1/assistant/*`**، **`/api/v1/intelligence/*`**، **`/api/v1/readiness/*`**،
**`POST /api/v1/skill-match`**.

```json
{ "data": { } }
```

لا يوجد `success` ولا `message` ولا `request_id` في الجسم (فقط هيدر `X-Request-ID`).
عند الفشل ترجع `{ "code": "…", "message": "…", "request_id": "…", "details": { } }`.

**التوصية للفرونت:** اقرأ البيانات هكذا — `const payload = res.data?.data ?? res.data;`

### النمط C — حالة خاصة: `GET /api/v1/career-roles`

ترجع `success` و`data`، لكن `data` هو **الـ paginator نفسه**:

```json
{
  "success": true,
  "message": "Career roles retrieved successfully.",
  "data": {
    "current_page": 1,
    "data": [ ],
    "per_page": 15,
    "total": 42
  }
}
```

يعني العناصر في `data.data` — وليس في `data` مثل باقي المسارات. ولا يوجد مفتاح `meta`.

### أخطاء الـ middleware (Sanctum)

لا تمرّ على أي من الأنماط أعلاه:

```json
{ "message": "Unauthenticated." }
```

---

## 3. أشكال الأخطاء — متحقَّق منها فعليًا على الإنتاج

| الكود | متى | الجسم الفعلي |
|---|---|---|
| `401` | توكن مفقود/غلط | `{"message":"Unauthenticated."}` |
| `403` | صلاحية غير كافية (سر غلط، دور غلط) | `{"success":false,"message":"Invalid setup secret."}` |
| `404` | مسار أو مورد غير موجود | رد Laravel القياسي |
| `422` | فشل التحقق (الأشهر) | `{"message":"…","errors":{"email":["The email field is required."]}}` |
| `429` | تجاوزت حد الطلبات | رد throttle القياسي |
| `5xx` | خطأ داخلي | `{"code":"…","message":"…","request_id":"…"}` |

**مهم:** قيم `422` تُقرأ من `errors.<field>[0]` — وهذا ثابت في كل المسارات تقريبًا.

**مهم:** حدّ الطلبات (rate limit) مطبَّق على مسارات المصادقة: `10/دقيقة` للتسجيل والتحقق
والباسورد، و`20/دقيقة` لتسجيل الدخول. اعرض رسالة واضحة للمستخدم عند `429`.

---

## 4. الترقيم (Pagination)

المسارات التالية ترجع كائن `meta` مع القائمة:

| Endpoint | ملاحظة |
|---|---|
| `GET /api/v1/applications` | `per_page` افتراضي 15، أقصى 100 |
| `GET /api/v1/projects/{project}/applications` | نفس الشي |
| `GET /api/v1/recommendations` | نفس الشي |
| `GET /api/v1/notifications` | نفس الشي |
| `GET /api/v1/conversations` | افتراضي 20 |
| `GET /api/v1/conversations/{conversation}/messages` | افتراضي 50 |
| `GET /api/v1/conversations/{conversation}/chatbot/messages` | افتراضي 50 |
| `GET /api/v1/mentor/connections` | افتراضي 20 |

شكل `meta`:

```json
{ "meta": { "current_page": 1, "per_page": 15, "total": 42, "last_page": 3 } }
```

تُمرَّر كـ query params: `?page=2&per_page=30`.

**استثناءان:**
- `GET /api/v1/projects` — ترجع **مصفوفة عادية** (بحد أقصى 50 عنصر)، بلا ترقيم.
- `GET /api/v1/career-roles` — ترقيم مثبَّت على 15، والـ paginator داخل `data` (انظر النمط C).

---

## 5. جدول المسارات الكامل — مصحَّح من الكود

العمود الأخير هو **الصلاحية الفعلية** كما هي في الـ middleware، وليست وصفًا نظريًا.

**مفاتيح الصلاحية:** `Token` = `Authorization: Bearer`، `حساب نشط` = الحساب `active`،
`متعلم`/`أدمن`/`مرشد موثّق` = دور المستخدم، `rate-limit x,y` = حد الطلبات.

### 1. المصادقة والحسابات

| Method | Endpoint | الصلاحية الفعلية |
|---|---|---|
| `POST` | `/api/v1/auth/forgot-password` | rate-limit 10,1 |
| `POST` | `/api/v1/auth/forgot-password/resend` | rate-limit 10,1 |
| `POST` | `/api/v1/auth/forgot-password/verify` | rate-limit 10,1 |
| `POST` | `/api/v1/auth/login` | rate-limit 20,1 |
| `POST` | `/api/v1/auth/login/google` | rate-limit 20,1 |
| `POST` | `/api/v1/auth/login/organization` | rate-limit 20,1 |
| `POST` | `/api/v1/auth/logout` | Token + حساب نشط |
| `POST` | `/api/v1/auth/logout-all` | Token + حساب نشط |
| `POST` | `/api/v1/auth/register` | rate-limit 10,1 |
| `POST` | `/api/v1/auth/register/organization` | rate-limit 10,1 |
| `POST` | `/api/v1/auth/resend-otp` | rate-limit 10,1 |
| `POST` | `/api/v1/auth/reset-password` | rate-limit 10,1 |
| `POST` | `/api/v1/auth/verify` | rate-limit 10,1 |

### 2. الملف الشخصي

| Method | Endpoint | الصلاحية الفعلية |
|---|---|---|
| `GET` | `/api/v1/profile` | Token + حساب نشط + متعلم |
| `POST` | `/api/v1/profile` | Token + حساب نشط + متعلم |
| `PUT` | `/api/v1/profile` | Token + حساب نشط + متعلم |

### 3. إدارة المؤسسات (أدمن)

| Method | Endpoint | الصلاحية الفعلية |
|---|---|---|
| `GET` | `/api/v1/admin/organizations` | Token + حساب نشط + أدمن |
| `GET` | `/api/v1/admin/organizations/{organization}` | Token + حساب نشط + أدمن |
| `POST` | `/api/v1/admin/organizations/{organization}/approve` | Token + حساب نشط + أدمن |
| `GET` | `/api/v1/admin/organizations/{organization}/proof-file` | Token + حساب نشط + أدمن |
| `POST` | `/api/v1/admin/organizations/{organization}/reject` | Token + حساب نشط + أدمن |

### 4. ملف المؤسسة

| Method | Endpoint | الصلاحية الفعلية |
|---|---|---|
| `GET` | `/api/v1/organization/profile` | Token + حساب نشط + مؤسسة معتمدة |

### 5. المشاريع والتقديم والمطابقة

| Method | Endpoint | الصلاحية الفعلية |
|---|---|---|
| `GET` | `/api/v1/applications` | Token + حساب نشط + متعلم |
| `POST` | `/api/v1/applications/{application}/withdraw` | Token + حساب نشط + متعلم |
| `GET` | `/api/v1/projects` | Token + حساب نشط + متعلم |
| `GET` | `/api/v1/projects/{project}` | Token + حساب نشط + متعلم |
| `GET` | `/api/v1/projects/{project}/applications` | Token + حساب نشط |
| `POST` | `/api/v1/projects/{project}/applications` | Token + حساب نشط + متعلم |
| `PATCH` | `/api/v1/projects/{project}/applications/{application}` | Token + حساب نشط |
| `POST` | `/api/v1/projects/{project}/match` | Token + حساب نشط + متعلم |
| `GET` | `/api/v1/recommendations` | Token + حساب نشط + متعلم |
| `POST` | `/api/v1/recommendations/{recommendation}/feedback` | Token + حساب نشط + متعلم |
| `POST` | `/api/v1/skill-match` | Token + حساب نشط + متعلم |

### 6. الجاهزية والذكاء والتقييم الأساسي

| Method | Endpoint | الصلاحية الفعلية |
|---|---|---|
| `POST` | `/api/v1/baseline-assessments` | Token + حساب نشط + متعلم |
| `GET` | `/api/v1/baseline-assessments/{assessment}` | Token + حساب نشط + متعلم |
| `PATCH` | `/api/v1/baseline-assessments/{assessment}` | Token + حساب نشط + متعلم |
| `POST` | `/api/v1/baseline-assessments/{assessment}/submit` | Token + حساب نشط + متعلم |
| `POST` | `/api/v1/intelligence/calculate` | Token + حساب نشط + متعلم |
| `GET` | `/api/v1/intelligence/latest` | Token + حساب نشط + متعلم |
| `POST` | `/api/v1/readiness/calculate` | Token + حساب نشط + متعلم |
| `GET` | `/api/v1/readiness/latest` | Token + حساب نشط + متعلم |

### 7. المساعد الذكي

| Method | Endpoint | الصلاحية الفعلية |
|---|---|---|
| `POST` | `/api/v1/assistant/ask` | Token + حساب نشط + متعلم |
| `PUT` | `/api/v1/assistant/interactions/{interaction}/report` | Token + حساب نشط + متعلم |

### 8. البيانات المرجعية

| Method | Endpoint | الصلاحية الفعلية |
|---|---|---|
| `GET` | `/api/v1/reference/countries` | عام |
| `GET` | `/api/v1/reference/countries/{country}/universities` | عام |
| `GET` | `/api/v1/reference/specializations` | عام |
| `GET` | `/api/v1/reference/universities` | عام |

### 9. المهارات والأدلة والأدوار المهنية

| Method | Endpoint | الصلاحية الفعلية |
|---|---|---|
| `GET` | `/api/v1/career-roles` | Token + حساب نشط + متعلم |
| `GET` | `/api/v1/career-roles/{id}` | Token + حساب نشط + متعلم |
| `GET` | `/api/v1/career-roles/{id}/skills` | Token + حساب نشط + متعلم |
| `GET` | `/api/v1/evidence` | Token + حساب نشط + متعلم |
| `POST` | `/api/v1/evidence` | Token + حساب نشط + متعلم |
| `GET` | `/api/v1/evidence/{id}` | Token + حساب نشط |
| `PUT` | `/api/v1/evidence/{id}/review` | Token + حساب نشط + أدمن |
| `GET` | `/api/v1/skills/matrix` | Token + حساب نشط |
| `POST` | `/api/v1/skills/matrix` | Token + حساب نشط |
| `PUT` | `/api/v1/skills/matrix/{id}` | Token + حساب نشط |
| `GET` | `/api/v1/skills/taxonomy` | Token + حساب نشط |

### 10. المرشد والطلاب

| Method | Endpoint | الصلاحية الفعلية |
|---|---|---|
| `GET` | `/api/v1/mentor/connections` | Token + حساب نشط + مرشد موثّق |
| `POST` | `/api/v1/mentor/connections` | Token + حساب نشط + مرشد موثّق |
| `PATCH` | `/api/v1/mentor/connections/{connection}` | Token + حساب نشط + مرشد موثّق |
| `GET` | `/api/v1/mentor/students` | Token + حساب نشط + مرشد موثّق |
| `GET` | `/api/v1/mentor/students/{student}` | Token + حساب نشط + مرشد موثّق |

### 11. المحادثات والرسائل

| Method | Endpoint | الصلاحية الفعلية |
|---|---|---|
| `POST` | `/api/v1/connections/{connection}/conversations` | Token + حساب نشط |
| `GET` | `/api/v1/conversations` | Token + حساب نشط |
| `GET` | `/api/v1/conversations/{conversation}` | Token + حساب نشط |
| `GET` | `/api/v1/conversations/{conversation}/chatbot/messages` | Token + حساب نشط |
| `POST` | `/api/v1/conversations/{conversation}/chatbot/messages` | Token + حساب نشط |
| `GET` | `/api/v1/conversations/{conversation}/messages` | Token + حساب نشط |
| `POST` | `/api/v1/conversations/{conversation}/messages` | Token + حساب نشط |
| `POST` | `/api/v1/conversations/{conversation}/read` | Token + حساب نشط |
| `GET` | `/api/v1/conversations/{conversation}/status` | Token + حساب نشط |

### 12. الإشعارات

| Method | Endpoint | الصلاحية الفعلية |
|---|---|---|
| `GET` | `/api/v1/notifications` | Token + حساب نشط |
| `GET` | `/api/v1/notifications/preferences` | Token + حساب نشط |
| `PUT` | `/api/v1/notifications/preferences` | Token + حساب نشط |
| `POST` | `/api/v1/notifications/read-all` | Token + حساب نشط |
| `GET` | `/api/v1/notifications/unread-count` | Token + حساب نشط |
| `POST` | `/api/v1/notifications/{notification}/read` | Token + حساب نشط |

### 13. الإعداد والأنظمة

| Method | Endpoint | الصلاحية الفعلية |
|---|---|---|
| `GET` | `/api/documentation` | عام |
| `GET` | `/api/oauth2-callback` | عام |
| `GET` | `/api/v1/internal/baseline-items` | rate-limit 60,1 |
| `POST` | `/api/v1/setup/create-admin` | rate-limit 5,1 |
| `POST` | `/api/v1/setup/create-mentor` | rate-limit 10,1 |
| `GET` | `/up` | عام |

---

## 6. ملاحظات لازم تنعرف قبل التنفيذ

1. **`GET /api/v1/admin/organizations/{organization}/proof-file`** ترجع **ملفًا للتنزيل**
   (binary)، وليست JSON. استعمل `responseType: 'blob'` في الفرونت.

2. **`POST /api/v1/setup/create-admin` و `POST /api/v1/setup/create-mentor`** عامّان (بلا توكن)
   لكنهما **محميان بسر** يُقارَن في الكود. أي طلب بدون السر الصحيح يرجّع `403`.
   **الفرونت ما بيحتاجهم إطلاقًا** — هذولا للاستخدام الإداري من Postman/سكربت فقط.

3. **`GET /api/v1/internal/baseline-items`** ليس للمستخدمين — يتطلب هيدر `X-Internal-Secret`
   ويُستدعى من خدمة الـ Data Science فقط.

4. **`GET /up`** فحص صحة بسيط (health check) — مفيد للمراقبة، ويرجّع صفحة HTML لا JSON.

5. **`GET /api/documentation`** صفحة Swagger UI — موجودة لكنها **فارغة حاليًا** (لا يوجد
   spec مُولَّد)، فلا تعتمد عليها. اعتمد على هذا المستند + مجموعة Postman.

6. **مسارات خدمة Data Science** (`/health`, `/welcome`, `POST /api/v1/skill-gap`) **ليست جزءًا
   من هذا المشروع** — هي خدمة FastAPI منفصلة بعنوان ونظام مصادقة مختلفين. لا تخلط بينها وبين
   `POST /api/v1/skill-match` الخاص بـ Laravel.

7. **لا يوجد أي `DELETE`** في الـ API كله — الإلغاء يتم بمسارات `withdraw` أو بتغيير حالة.

---

## 7. المطلوب من الفرونت عند أي خطأ

أرسل مع التذكرة: **الـ endpoint + الـ HTTP status + قيمة `request_id` من الرد**.
بدون `request_id` ما نقدر نربط الخطأ بالسجل على الإنتاج.
