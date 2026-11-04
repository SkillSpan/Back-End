# دليل الباك اند — المشاريع والتقديمات والمواهب

**رد على طلب الليدر** · 2026-11-03 · `feature/authentication`

---

## 0. اقرأ هذا أولاً — الحقيقة قبل الدليل

الليدر طلب دليل لـ **17 رابط**. بعد مقارنة طلبه مع جدول الروابط الحقيقي
(`php artisan route:list --json`) النتيجة:

| الحالة | العدد |
|---|---|
| موجود بالمسار نفسه اللي طلبه | **4** |
| موجود بمسار مختلف (بديل حقيقي، موثّق تحت) | **2** |
| **غير موجود إطلاقاً** | **11** |

هذا الملف **ما بيوثّق روابط غير موجودة**. لكل رابط تحت واحدة من ثلاث حالات:
**موجود** (مع JSON حقيقي)، أو **موجود بمسار تاني** (مع المسار الصح)، أو
**غير موجود** (مع أقرب شيء متاح، أو "لا يوجد بديل").

كل الـ JSON تحت مأخوذ من الكود نفسه — من `ProjectResource`،
`ApplicationResource`، `TalentCardResource`، والأغلفة في الكونترولرز. مو مصمّم
على الورق.

> **الخلاصة اللي لازم تتقال لليدر بصراحة:** الشغل الموجود بيغطي دورة حياة
> المشروع من المسودة لحد الفتح للتقديم، وقرارات التقديم. اللي ناقص هو: قائمة
> مشاريع الشركة، نسخ مشروع، فريق المشروع، بدء التنفيذ، ومجموعة المواهب
> (مقارنة/قوائم/ملاحظات/تواصل).

---

## 1. القواعد العامة

### Base URL

```
https://skillspan.alwaysdata.net
```

### الترويسات — إلزامية على كل طلب

```http
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json      ← ما عدا رفع الملفات (multipart/form-data)
```

> ⚠️ **`Accept: application/json` إلزامي.** بدونه لارافيل بترجّع `302` أو HTML
> بدل `401`، والفرونت بيظن إنه الرابط مش موجود.

### غلاف النجاح

كل نجاح فيه `success` + `message` + `request_id`. العنصر نفسه يكون تحت `data`
(مفرد أو مصفوفة)، والقوائم معها `meta`.

```json
{
  "success": true,
  "message": "Project created successfully.",
  "data": { },
  "request_id": "8f2c1d4e-..."
}
```

### غلاف الخطأ

```json
{
  "code": "PROJECT_NOT_EDITABLE",
  "message": "This project can only be edited while it is a draft or has changes requested.",
  "request_id": "8f2c1d4e-...",
  "details": { }
}
```

`code` هو اللي الفرونت يفرّع عليه. `message` نص للإنسان وبيتغيّر.
`details` اختياري وبيوصل بس لما يكون في تفاصيل مفيدة.

### `request_id`

لو الفرونت بعت `X-Request-ID` بينرجع نفسه، وإلا السيرفر بيولّد UUID ويرجّعه في
الجسم وفي ترويسة `X-Request-ID`. استخدموه في تذاكر الدعم.

### ⚠️ استثناء مهم: `401` بلا `code`

الرد الحقيقي عند غياب التوكن:

```json
{ "message": "Unauthenticated." }
```

**بلا `code` وبلا `request_id`.** افحصوا **الحالة 401 نفسها**، ما تنتظروا كود.

### ⚠️ بعض الـ `403` بلا `code` كذلك

| المصدر | الشكل |
|---|---|
| `admin` middleware | `{"success": false, "message": "You are not authorized to perform this action."}` |
| `role:company_admin` middleware | `{"code": "COMPANY_ADMIN_ONLY", "message": "..."}` |
| `organization.approved` middleware | `{"success": false, "code": "ORGANIZATION_PENDING" \| "ORGANIZATION_NOT_APPROVED", "message": "..."}` |

---

## 2. المشاريع — الموجود

### 2.1 `POST /api/v1/projects` — إنشاء مشروع

| | |
|---|---|
| الحراسة | `auth:sanctum` + `account.active` — **بلا `role`** |
| مين مسموح | `company_admin` أو `university_admin` أو `admin` (القرار في الـ service، مو في الراوت) |
| الناتج | المشروع بيتولد دايماً بحالة **`draft`** — ما بيتولد `open` ولا `submitted` أبداً |

**الطلب**

```json
{
  "type": "company_sponsored",
  "title": "لوحة تحليلات لبيانات المبيعات",
  "description": "بناء لوحة تحكم تعرض مبيعات الفرعين مع تقارير أسبوعية.",
  "objectives": "تمكين فريق المبيعات من متابعة الأداء بدون ملفات إكسل.",
  "learning_outcomes": ["تحليل بيانات حقيقية", "كتابة SQL متقدم"],
  "difficulty": 3,
  "work_mode": "remote",
  "career_role_id": 7,
  "domain": "data",
  "schedule": "20 ساعة/أسبوع",
  "capacity": 5,
  "min_team_size": 2,
  "start_date": "2026-12-15",
  "end_date": "2027-02-15",
  "application_deadline": "2026-12-01",
  "confidentiality": "public",
  "required_skills": [
    {
      "skill_id": 12,
      "minimum_required_level": 3,
      "is_critical_entry": true
    },
    {
      "skill_id": 15,
      "minimum_required_level": 2
    }
  ],
  "roles": [
    { "title": "Data Analyst", "description": "تحليل وتجهيز البيانات" }
  ],
  "eligibility_constraints": [
    { "constraint_type": "location", "value": "Damascus" }
  ]
}
```

**قواعد مهمة**

- `type` إلزامي: `simulation` أو `company_sponsored` فقط.
- `title` إلزامي (≤ 255). `career_role_id` **إلزامي** وموجود فعلاً في `career_roles`.
- كل `skill_id` لازم يكون **مربوط بالـ career role** في `career_role_skills` — مو بس موجود في جدول المهارات. وإلا `PROJECT_SKILL_NOT_IN_CAREER_ROLE`.
- `minimum_required_level` إلزامي لكل مهارة، بين 0 و 5 (بيدعم كسور).
- `organization_id` و `owner_id` **مش موثوقين** من الجسم: ممثل الشركة مشروعه دايماً لمؤسسته وهو مالكه. الأدمن فقط بيقدر يحددهم.
- ترتيب التواريخ ما بينفحص هون — الـ service بيفحصه على الحالة النهائية.

**الرد `201`**

```json
{
  "success": true,
  "message": "Project created successfully.",
  "data": {
    "id": 41,
    "title": "لوحة تحليلات لبيانات المبيعات",
    "description": "بناء لوحة تحكم تعرض مبيعات الفرعين مع تقارير أسبوعية.",
    "type": "company_sponsored",
    "domain": "data",
    "objectives": "تمكين فريق المبيعات من متابعة الأداء بدون ملفات إكسل.",
    "learning_outcomes": ["تحليل بيانات حقيقية", "كتابة SQL متقدم"],
    "difficulty": 3,
    "work_mode": "remote",
    "career_role_id": 7,
    "career_role": { "id": 7, "title": "Data Analyst", "slug": "data-analyst", "version": 1 },
    "role": "Data Analyst",
    "schedule": "20 ساعة/أسبوع",
    "capacity": 5,
    "min_team_size": 2,
    "application_deadline": "2026-12-01",
    "start_date": "2026-12-15",
    "end_date": "2027-02-15",
    "duration_days": 62,
    "status": "draft",
    "confidentiality": "public",
    "version": 1,
    "organization": { "id": 3, "title": "شركة النور" },
    "required_skills": [
      {
        "skill_id": 12,
        "skill_name": "SQL",
        "minimum_level": 3,
        "minimum_required_level": 3,
        "is_critical_entry": true
      },
      {
        "skill_id": 15,
        "skill_name": "Power BI",
        "minimum_level": 2,
        "minimum_required_level": 2,
        "is_critical_entry": false
      }
    ],
    "available_project_roles": [
      { "id": 88, "title": "Data Analyst", "description": "تحليل وتجهيز البيانات" }
    ],
    "eligibility": null,
    "capacity_state": null,
    "created_at": "2026-11-03T18:40:11+00:00",
    "updated_at": "2026-11-03T18:40:11+00:00"
  },
  "request_id": "8f2c1d4e-1a2b-4c3d-9e8f-0a1b2c3d4e5f"
}
```

> `eligibility` و `capacity_state` بيكونوا `null` على روابط الإدارة — بيتحسبوا
> بس على تفاصيل المشروع للطالب. `null` معناها "ما تحسبت"، **مو** "متاح".

**الأخطاء**

| الحالة | `code` | المعنى |
|---|---|---|
| 403 | `PROJECT_CREATE_FORBIDDEN` | الدور مش من إدارة مؤسسة ولا أدمن |
| 422 | `VALIDATION_ERROR` | حقل ناقص/غير صالح — `errors` فيها التفاصيل |
| 422 | `PROJECT_ORGANIZATION_REQUIRED` | مشروع شركة بلا مؤسسة |
| 422 | `PROJECT_ORGANIZATION_NOT_FOUND` | `organization_id` مش موجود |
| 422 | `PROJECT_ORGANIZATION_NOT_A_COMPANY` | المؤسسة مش نوعها شركة |
| 422 | `PROJECT_ORGANIZATION_NOT_VERIFIED` | المؤسسة مش موثّقة |
| 422 | `PROJECT_ORGANIZATION_NOT_ADMINISTERED` | الأدمن بيحاول ينشئ لمؤسسة مش بيديرها |
| 422 | `PROJECT_OWNER_REQUIRED` | أدمن ينشئ مشروع شركة بلا `owner_id` |
| 422 | `PROJECT_OWNER_INVALID` | `owner_id` مش مستخدم موجود |
| 422 | `PROJECT_OWNER_MUST_BE_COMPANY_REPRESENTATIVE` | المالك مش ممثل شركة |
| 422 | `PROJECT_OWNER_NOT_ORGANIZATION_REPRESENTATIVE` | المالك مش تابع لهالمؤسسة |
| 422 | `PROJECT_TYPE_INVALID` | نوع مشروع غير مدعوم |
| 422 | `PROJECT_SKILL_NOT_IN_CAREER_ROLE` | مهارة مش ضمن الـ career role |
| 422 | `PROJECT_INVALID_DATES` | ترتيب تواريخ خاطئ |
| 500 | `PROJECT_REQUEST_FAILED` | خطأ غير متوقع |

---

### 2.2 `PATCH /api/v1/projects/{project}` — تعديل مشروع

| | |
|---|---|
| الحراسة | `auth:sanctum` + `account.active` |
| مين مسموح | **المالك** (`owner_id`) أو `admin` |
| متى مسموح | بس في `draft` أو `changes_requested` |

كل الحقول اختيارية — تعديل جزئي بيلمس بس اللي انبعت. الحقول المتداخلة
(`required_skills.*`, `roles.*`) بتحلّ محلّ المجموعة كاملة لما تنبعت.

**الطلب — مثال جزئي**

```json
{
  "title": "لوحة تحليلات المبيعات — النسخة الثانية",
  "capacity": 6,
  "required_skills": [
    { "skill_id": 12, "minimum_required_level": 3, "is_critical_entry": true }
  ]
}
```

**الرد `200`**

نفس شكل `data` تاع الإنشاء، مع `message: "Project updated successfully."` و
`version` ممكن يكون زاد.

> **متى يزيد `version`؟** بس لما يتغيّر حقل مؤثر على المطابقة (المهارات،
> القدرة، النوع…) — التعديل الوصفي (العنوان/الوصف) ما بيرفع الرقم. الـ
> applications بتحفظ الـ version اللي قدّمت عليه، فهاد بيحميها من التغيير الصامت.

**الأخطاء**

| الحالة | `code` | المعنى |
|---|---|---|
| 403 | `PROJECT_NOT_OWNED` | مش المالك ومش أدمن |
| 404 | `PROJECT_NOT_FOUND` | المشروع مش موجود |
| 422 | `PROJECT_NOT_EDITABLE` | الحالة مش `draft`/`changes_requested` — `details.editable_statuses` فيها المسموح |
| 422 | `PROJECT_INVALID_DATES` | تواريخ متناقضة |
| 422 | `PROJECT_SKILL_NOT_IN_CAREER_ROLE` | مهارة مش ضمن الـ career role |
| 422 | `VALIDATION_ERROR` | جسم غير صالح |
| 500 | `PROJECT_REQUEST_FAILED` | خطأ غير متوقع |

---

### 2.3 `POST /api/v1/projects/{project}/submit` — إرسال للمراجعة

| | |
|---|---|
| الحراسة | `auth:sanctum` + `account.active` |
| مين مسموح | المالك أو `admin` |
| الانتقال | `draft` → `submitted` · `changes_requested` → `submitted` |
| الجسم | **فاضي** — ما في حقول |

```http
POST /api/v1/projects/41/submit
```

**الرد `200`** — نفس شكل المشروع مع `"status": "submitted"` و
`message: "Project submitted for review."`

**شرط الاكتمال** — لو ناقص شيء ما بينتقل، وكل النواقص بترجع مرة واحدة في
`details.missing`:

`type`, `title`, `description`, `objectives`, `capacity`,
`required_skills` (مهارة وحدة على الأقل), `roles` (دور واحد على الأقل),
`start_date`, `end_date`, `application_deadline`,
`end_date_before_start_date`, `application_deadline_after_start_date`

**الأخطاء**

| الحالة | `code` | المعنى |
|---|---|---|
| 404 | `PROJECT_NOT_FOUND` | المشروع مش موجود |
| 403 | `PROJECT_NOT_OWNED` | مش المالك ومش أدمن |
| 422 | `PROJECT_INCOMPLETE` | `details.missing` فيها النواقص |
| 422 | `PROJECT_INVALID_TRANSITION` | الحالة الحالية ما بتسمح — `details.allowed_transitions` |
| 409 | `PROJECT_STATUS_CONFLICT` | حد تاني غيّر الحالة بنفس اللحظة — أعد المحاولة |
| 500 | `PROJECT_REQUEST_FAILED` | خطأ غير متوقع |

---

### 2.4 مسارات المراجعة (أدمن فقط)

`POST /api/v1/projects/{project}/approve` ·
`POST /api/v1/projects/{project}/request-changes` ·
`POST /api/v1/projects/{project}/reject`

| | |
|---|---|
| الحراسة | `auth:sanctum` + `account.active` + `admin` |
| الجسم | `{ "reason": "..." }` — اختياري، ≤ 1000 حرف |

- **`request-changes`**: `reason` **إلزامي فعلياً**، وإلا `PROJECT_REASON_REQUIRED` (422).
- **`approve` / `reject`**: `reason` اختياري، وبيتسجّل في سجل التدقيق بس.

> هذه المسارات **مش للشركة** — الشركة ما بتعتمد مشروعها بنفسها. الاعتماد
> لأدمن المنصة.

**الأخطاء المشتركة:** `403` من `admin` middleware بشكل
`{"success": false, "message": "You are not authorized to perform this action."}` ·
`403 PROJECT_REVIEW_FORBIDDEN` (خط الدفاع الثاني) ·
`404 PROJECT_NOT_FOUND` · `422 PROJECT_REASON_REQUIRED` ·
`422 PROJECT_INVALID_TRANSITION` · `409 PROJECT_STATUS_CONFLICT`

---

### 2.5 `POST /api/v1/projects/{project}/open` — فتح التقديم

| | |
|---|---|
| الحراسة | `auth:sanctum` + `account.active` |
| مين مسموح | المالك أو `admin` |
| الانتقال | `approved` → `open` |
| الجسم | فاضي |

بعد الفتح المشروع بيصير ظاهر للطلاب، والمطابقة والتقديم بيشتغلوا عليه.

---

## 3. التقديمات — الموجود

### 3.1 `GET /api/v1/projects/{project}/applications` — تقديمات مشروعي

| | |
|---|---|
| الحراسة | `auth:sanctum` + `account.active` — **بلا `role`** |
| مين مسموح | مالك المشروع أو `admin` (فحص ملكية صريح في الـ service) |
| Query | `page`, `per_page` (افتراضي 15، أقصى 100) |

**الرد `200`**

```json
{
  "success": true,
  "message": "Project applications retrieved successfully.",
  "data": [
    {
      "id": 512,
      "project_id": 41,
      "applicant_id": 77,
      "project_role_id": 88,
      "project_role": { "id": 88, "title": "Data Analyst" },
      "status": "submitted",
      "is_active": true,
      "project_version": 2,
      "application_data": { "motivation": "..." },
      "recommendation_id": 30,
      "recommendation_algorithm_version": "v1",
      "recommendation_configuration_version": 1,
      "decision_reason": null,
      "decided_by": null,
      "decided_at": null,
      "submitted_at": "2026-11-02T09:12:44+00:00",
      "withdrawn_at": null,
      "created_at": "2026-11-02T09:12:44+00:00",
      "updated_at": "2026-11-02T09:12:44+00:00"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 },
  "request_id": "8f2c1d4e-..."
}
```

> `project` و `applicant` **مش موجودين** في هالرد — بيتحملوا بس لما ينطلبوا
> صراحة. اللي موجود `applicant_id`. إذا الفرونت بدو الاسم لازم نضيفه (تعديل
> صغير على الكونترولر).

**الأخطاء:** `404 PROJECT_NOT_FOUND` · `403 APPLICATION_LIST_FORBIDDEN` ·
`500 APPLICATION_FAILED`

---

### 3.2 `PATCH /api/v1/projects/{project}/applications/{application}` — قرار

> **هذا هو بديل `POST /applications/{id}/decision`.** المسار مختلف، لكن
> الوظيفة نفسها.

| | |
|---|---|
| الحراسة | `auth:sanctum` + `account.active` |
| مين مسموح | مالك المشروع أو `admin` |
| `{application}` | لازم يكون تابع فعلاً لـ `{project}` |

**الطلب**

```json
{
  "status": "shortlisted",
  "reason": "خبرة SQL قوية ومشروع تخرج قريب من احتياجنا."
}
```

- `status` **إلزامي**: `shortlisted` / `accepted` / `rejected` / `waitlisted` فقط.
- `reason` اختياري، ≤ 2000 حرف.

**الرد `200`**

```json
{
  "success": true,
  "message": "Application status updated successfully.",
  "data": {
    "id": 512,
    "project_id": 41,
    "applicant_id": 77,
    "status": "shortlisted",
    "is_active": true,
    "decision_reason": "خبرة SQL قوية ومشروع تخرج قريب من احتياجنا.",
    "decided_by": 9,
    "decided_at": "2026-11-03T19:02:10+00:00",
    "submitted_at": "2026-11-02T09:12:44+00:00",
    "withdrawn_at": null,
    "created_at": "2026-11-02T09:12:44+00:00",
    "updated_at": "2026-11-03T19:02:10+00:00"
  },
  "request_id": "8f2c1d4e-..."
}
```

**الأخطاء**

| الحالة | `code` | المعنى |
|---|---|---|
| 404 | `APPLICATION_NOT_FOUND` | التقديم مش موجود |
| 404 | `APPLICATION_PROJECT_MISMATCH` | التقديم مش تابع لهالمشروع |
| 403 | `APPLICATION_DECISION_FORBIDDEN` | مش مالك المشروع |
| 422 | `VALIDATION_ERROR` | `status` مش من القيم الأربعة |
| 422 | `APPLICATION_INVALID_TRANSITION` | انتقال غير مسموح من الحالة الحالية |
| 422 | `PROJECT_FULL` | القبول بيعدّي سعة المشروع |
| 500 | `APPLICATION_FAILED` | خطأ غير متوقع |

---

## 4. المواهب — الموجود

### 4.1 `GET /api/v1/talent` — قائمة المواهب

| | |
|---|---|
| الحراسة | `auth:sanctum` + `account.active` + `role:company_admin` + `organization.approved` |

**Query**

| الحقل | النوع | ملاحظة |
|---|---|---|
| `search` | نص ≤ 255 | بحث حر |
| `career_role` | نص ≤ 255 | **slug** مو id |
| `min_readiness` | رقم 0–100 | حد أدنى للجاهزية |
| `sort` | `readiness` \| `recent` | غير المدعوم بيرجّع 422 |
| `page` | عدد ≥ 1 | |
| `per_page` | 1–100 | |

**الرد `200`**

```json
{
  "success": true,
  "message": "Discoverable talent retrieved successfully.",
  "data": [
    {
      "id": 77,
      "name": "ريم الحسن",
      "career_role": "Data Analyst",
      "specialization": "تحليل بيانات",
      "university_name": "جامعة دمشق",
      "career_status": "student",
      "enrollment_status": "enrolled",
      "availability": "part_time",
      "preferred_work_type": "remote",
      "completeness_percent": 82,
      "visibility": "discoverable",
      "contactable": true,
      "readiness": { "score": 74, "band": "ready" },
      "top_skills": ["SQL", "Power BI"]
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 },
  "request_id": "8f2c1d4e-..."
}
```

### 4.2 `GET /api/v1/talent/{student}` — ملف موهبة

نفس شكل الغلاف، `data` فيه الملف الكامل (مهارات، مشاريع، أدلة، تقييم).

> ⚠️ **إيميل الطالب مش موجود في العقد — بقصد.** التعليق في الكود حرفياً:
> "a company is expected to reach a learner through a recorded contact request
> (a follow-up task), not by harvesting mailboxes from a list endpoint."
> يعني **رابط التواصل كان مهمة متابعة معروفة، وما تنفّذ بعد.**

**خطأ `404` بشكل مختلف — بلا `code`:**

```json
{
  "success": false,
  "message": "No discoverable student with that id.",
  "request_id": "8f2c1d4e-..."
}
```

> 404 مش 403 بقصد: الـ 403 كان بيكشف إنه الـ id موجود، فيصير الرابط أداة
> تخمين.

### 4.3 `GET /api/v1/talent/filters` — قيم الفلاتر المتاحة

بيرجّع بس الـ career roles اللي فعلاً بتعطي نتائج لهذا المستدعي — مو كل الجدول.
(لأنه `GET /career-roles` محجوب عن الشركة بـ `role:learner`.)

### 4.4 `GET /api/v1/company/dashboard` — أقرب شيء لـ "مشاريع الشركة"

```json
{
  "success": true,
  "message": "Company dashboard retrieved successfully.",
  "data": {
    "counts": { "projects": 12, "applicationsPending": 7, "unreadNotifications": 3 },
    "permissions": { },
    "tasks": [ ],
    "projectStates": {
      "draft": 2, "submitted": 1, "changes_requested": 0, "approved": 1,
      "rejected": 0, "open": 4, "selection": 1, "active": 2,
      "under_review": 0, "completed": 1, "cancelled": 0, "archived": 0
    },
    "recentProjects": [
      {
        "id": 41,
        "name": "لوحة تحليلات لبيانات المبيعات",
        "kind": "real",
        "track": "data",
        "state": "open",
        "deadline": { "date": "2026-12-01", "closed": false },
        "filled": 3,
        "capacity": 5
      }
    ],
    "funnel": { }
  },
  "request_id": "8f2c1d4e-..."
}
```

`projectStates` فيها **كل الـ 12 حالة** حتى لو صفر. `recentProjects` محدودة
بـ **5** (قابلة للتغيير من `config('company.dashboard.recent_projects_limit')`).

---

## 5. الروابط المطلوبة — الحالة والبديل

### 5.1 جدول سريع

| # | المطلوب | الحالة | البديل الحقيقي |
|---|---|---|---|
| 1 | `POST /projects` | ✅ موجود | — |
| 2 | `PATCH /projects/{id}` | ✅ موجود | — |
| 3 | `POST /projects/{id}/submit` | ✅ موجود | — |
| 4 | `GET /projects/{id}/applications` | ✅ موجود | — |
| 5 | `POST /applications/{id}/decision` | 🔁 مسار مختلف | `PATCH /projects/{project}/applications/{application}` |
| 6 | `GET /talent` (منطق) | 🔁 مسار مختلف | `GET /api/v1/talent` — موجود وموسّع |
| 7 | `POST /projects/{id}/clone` | ❌ غير موجود | لا يوجد — نسخ يدوي عبر `POST /projects` |
| 8 | `GET /company/projects` | ❌ غير موجود | `GET /company/dashboard` (آخر 5 + أعداد) |
| 9 | `GET /company/projects/{id}` | ❌ غير موجود | لا يوجد بديل للشركة |
| 10 | `POST /projects/{id}/transitions` | ❌ غير موجود | 5 روابط منفصلة (تحت) |
| 11 | `GET /applications/{id}` | ❌ غير موجود | لا يوجد — القائمة ثم فلترة |
| 12 | `POST /projects/{id}/team/assignments` | ❌ غير موجود | لا يوجد |
| 13 | `POST /projects/{id}/start` | ❌ غير موجود | لا يوجد |
| 14 | `POST /talent/compare` | ❌ غير موجود | لا يوجد |
| 15 | `POST /talent/lists` | ❌ غير موجود | لا يوجد |
| 16 | `POST /talent/lists/{id}/candidates` | ❌ غير موجود | لا يوجد |
| 17 | `POST /talent/candidates/{id}/notes` | ❌ غير موجود | لا يوجد |
| 18 | `POST /talent/candidates/{id}/contact` | ❌ غير موجود | لا يوجد (مهمة متابعة معروفة) |

### 5.2 التفاصيل

**`POST /projects/{id}/clone`** — ما في أي كود نسخ. البديل الوحيد: الفرونت
يقرأ المشروع ويعيد إرساله على `POST /projects`. ⚠️ لكن `GET /projects/{id}`
محجوب عن الشركة (`role:learner`)، فالفرونت ما بيقدر يقرأ مشروعه بنفسه إلا من
`recentProjects` الناقص. **هذه فجوة حقيقية.**

**`GET /company/projects`** — ما في قائمة مشاريع للشركة. الموجود
`/company/dashboard` اللي بيرجّع أعداد لكل حالة + آخر 5 مشاريع. أي شاشة "كل
مشاريعي" ما إلها داتا.

**`GET /company/projects/{id}`** — ما في. و`GET /projects/{id}` موجود لكن
محجوب بـ `role:learner`، فالشركة بتاخد `403 COMPANY_ADMIN_ONLY`… يعني عملياً
**الشركة ما بتقدر تقرأ مشروعها**. (الأدمن عنده `GET /admin/projects/{id}`.)

**`POST /projects/{id}/transitions`** — ما في مسار انتقال عام. الموجود خمس
مسارات صريحة، كل واحد بيولّد رسالة وكود مختلف:

```
POST /projects/{id}/submit            draft|changes_requested → submitted
POST /projects/{id}/approve           submitted → approved            (أدمن)
POST /projects/{id}/request-changes   submitted → changes_requested   (أدمن، reason إلزامي)
POST /projects/{id}/reject            submitted → rejected            (أدمن)
POST /projects/{id}/open              approved → open
```

**`GET /applications/{id}`** — ما في قراءة تقديم واحد. الموجود:
`GET /projects/{project}/applications` (مالك المشروع) و
`GET /applications` (**للطالب نفسه فقط**، `role:learner`).

**`POST /projects/{id}/team/assignments`** — ما في جدول تعيينات فريق ولا
رابط. (في جدول `project_team_members` بيُستخدم في حساب ملف الموهبة، بس ما في
رابط بيكتب عليه من جهة الشركة.)

**`POST /projects/{id}/start`** — ما في. وأهم من هيك: **`open → selection`
معلَن في `Project::TRANSITIONS` لكن بلا راوت**، و`selection` ما إلها أي انتقال
خارجي. يعني **دورة الحياة ما بتوصل لـ `active` ولا `completed` عبر الـ API
إطلاقاً**. (التفاصيل في القسم 6.)

**`POST /talent/compare` · `/talent/lists*` · `/talent/candidates/{id}/notes`**
— ما في أي شيء من هالثلاثة: لا جداول قوائم، لا ملاحظات، لا مقارنة. الموجود
بحث/فلترة/ملف واحد فقط.

**`POST /talent/candidates/{id}/contact`** — غير موجود، **وهذا مذكور في الكود
كمهمة متابعة**: إيميل الطالب محجوب بقصد لحد ما يصير "contact request مسجّل".
يعني الرابط مطلوب معروف، بس ما تنفّذ.

---

## 6. جواب السؤال الأول — الحالات الـ 12 ومين بينقل لمين

### 6.1 الأسماء بالضبط (كما في `Project::STATUSES`)

| # | القيمة | المعنى |
|---|---|---|
| 1 | `draft` | مسودة |
| 2 | `submitted` | مُرسل للمراجعة |
| 3 | `changes_requested` | مطلوب تعديلات |
| 4 | `approved` | معتمد |
| 5 | `rejected` | مرفوض |
| 6 | `open` | مفتوح للتقديم |
| 7 | `selection` | اختيار |
| 8 | `active` | قيد التنفيذ |
| 9 | `under_review` | قيد التقييم |
| 10 | `completed` | مكتمل |
| 11 | `cancelled` | ملغى |
| 12 | `archived` | مؤرشف |

> `closed` **مو من الـ 12**. موجود في الكود كقيمة قديمة للتوافق مع قاعدة
> البيانات فقط، وما بيُقرأ ولا بيُكتب، وما بيرجع في الداشبورد.

### 6.2 الانتقالات — اللي بيشتغل فعلاً

| من | إلى | مين | الرابط |
|---|---|---|---|
| `draft` | `submitted` | المالك / أدمن | `POST /projects/{id}/submit` |
| `submitted` | `approved` | أدمن | `POST /projects/{id}/approve` |
| `submitted` | `changes_requested` | أدمن | `POST /projects/{id}/request-changes` |
| `submitted` | `rejected` | أدمن | `POST /projects/{id}/reject` |
| `changes_requested` | `submitted` | المالك / أدمن | `POST /projects/{id}/submit` |
| `approved` | `open` | المالك / أدمن | `POST /projects/{id}/open` |

### 6.3 الانتقالات المعلنة بس مش قابلة للتنفيذ

| من | إلى | الحالة |
|---|---|---|
| `open` | `selection` | معلن في `TRANSITIONS` — **ما في راوت** |
| `selection` | — | ما إله أي انتقال خارجي |
| `active` | — | ما إله أي انتقال |
| `under_review` | — | ما إله أي انتقال |
| `completed` · `cancelled` · `archived` · `rejected` | — | نهائية |

**يعني عملياً:** 6 انتقالات فقط قابلة للتنفيذ عبر HTTP. الحالات
`selection` / `active` / `under_review` / `completed` **ما في طريق للوصول
إلها**. أي شاشة بتفترض إنه المشروع بيوصل لـ "قيد التنفيذ" بتحتاج شغل باك اند
جديد.

---

## 7. جواب السؤال الثاني — قيم القرار

**أربع قيم فقط، بأسماء كاملة (مو مختصرة):**

```json
{ "status": "shortlisted" }
{ "status": "accepted" }
{ "status": "rejected" }
{ "status": "waitlisted" }
```

- ❌ `shortlist` / `accept` / `reject` / `waitlist` — **مرفوضة**، بترجع `422 VALIDATION_ERROR`.
- ✅ `shortlisted` / `accepted` / `rejected` / `waitlisted` — الصح.

**حالات التقديم الكاملة (6):** `submitted`, `shortlisted`, `accepted`,
`rejected`, `waitlisted`, `withdrawn`.

**الانتقالات المسموحة:**

| من | إلى |
|---|---|
| `submitted` | `shortlisted` · `accepted` · `rejected` · `waitlisted` · `withdrawn` |
| `shortlisted` | `accepted` · `rejected` · `waitlisted` · `withdrawn` |
| `waitlisted` | `accepted` · `rejected` · `withdrawn` |
| `accepted` | `withdrawn` |
| `rejected` | — نهائي |
| `withdrawn` | — نهائي |

- `withdrawn` بيجي من **الطالب** عبر `POST /applications/{id}/withdraw`، مو من الشركة.
- `rejected` نهائي — ما في إعادة فتح.
- القبول بيعدّي السعة → `422 PROJECT_FULL`.

---

## 8. جواب السؤال الثالث — اللوغو

**لا، ما في رابط لرفع اللوغو.**

التفاصيل:

- عمود `organizations.logo` **موجود** في قاعدة البيانات.
- `GET /api/v1/profile/me` **بيرجّعه** للقراءة فقط: `"logo": "..."`.
- `logo` **مو** ضمن `OrganizationProfileService::EDITABLE` — أي محاولة لإرساله
  في `PATCH /profile/me` بترجع `422 FIELD_NOT_EDITABLE`.
- `POST /api/v1/profile/avatar` **موجود** لكنه `role:learner` — **الشركة ما
  بتقدر تستخدمه** (بترجع `403 COMPANY_ADMIN_ONLY`).

**النتيجة:** اللوغو بينقرأ بس ما بينكتب من أي جهة في الـ API. لازم إما:
(أ) نضيف `POST /company/profile/logo`، أو (ب) نضيف `logo` كـ document slot في
نفس آلية US-COMP-04 (اللي فيها فحص MIME وسكان ونسخ versioned أصلاً).

**التوصية:** (ب) — الآلية جاهزة ومجرّبة، والفرق بسيط.

---

## 9. جدول أكواد الأخطاء الكامل

| الحالة | `code` | متى |
|---|---|---|
| 401 | *(بلا `code`)* | توكن ناقص/منتهي — `{"message":"Unauthenticated."}` |
| 403 | `COMPANY_ADMIN_ONLY` | الدور مش `company_admin` |
| 403 | `ORGANIZATION_PENDING` | المؤسسة قيد المراجعة |
| 403 | `ORGANIZATION_NOT_APPROVED` | المؤسسة مرفوضة |
| 403 | *(بلا `code`، `success:false`)* | `admin` middleware |
| 403 | `ACCOUNT_DISABLED` | حساب محذوف/معطّل |
| 403 | `PROJECT_CREATE_FORBIDDEN` | إنشاء مشروع بدور غير مسموح |
| 403 | `PROJECT_NOT_OWNED` | إدارة مشروع مش ملكه |
| 403 | `PROJECT_REVIEW_FORBIDDEN` | مراجعة من غير أدمن |
| 403 | `APPLICATION_DECISION_FORBIDDEN` | قرار على مشروع مش ملكه |
| 403 | `APPLICATION_LIST_FORBIDDEN` | قراءة تقديمات مشروع مش ملكه |
| 404 | `PROJECT_NOT_FOUND` | مشروع غير موجود |
| 404 | `APPLICATION_NOT_FOUND` | تقديم غير موجود |
| 404 | `APPLICATION_PROJECT_MISMATCH` | تقديم مش تابع للمشروع |
| 404 | *(بلا `code`، `success:false`)* | موهبة غير مرئية |
| 409 | `PROJECT_STATUS_CONFLICT` | تعارض حالة متزامن |
| 422 | `VALIDATION_ERROR` | جسم غير صالح — `errors` |
| 422 | `PROJECT_INCOMPLETE` | نواقص قبل الإرسال — `details.missing` |
| 422 | `PROJECT_NOT_EDITABLE` | تعديل خارج النافذة — `details.editable_statuses` |
| 422 | `PROJECT_INVALID_TRANSITION` | انتقال غير مسموح — `details.allowed_transitions` |
| 422 | `PROJECT_REASON_REQUIRED` | `request-changes` بلا سبب |
| 422 | `PROJECT_INVALID_DATES` | ترتيب تواريخ خاطئ |
| 422 | `PROJECT_TYPE_INVALID` | نوع مشروع غير مدعوم |
| 422 | `PROJECT_SKILL_NOT_IN_CAREER_ROLE` | مهارة خارج الـ career role |
| 422 | `PROJECT_ORGANIZATION_*` | 4 أكواد خاصة بالمؤسسة (القسم 2.1) |
| 422 | `PROJECT_OWNER_*` | 4 أكواد خاصة بالمالك (القسم 2.1) |
| 422 | `APPLICATION_INVALID_TRANSITION` | قرار غير مسموح من الحالة الحالية |
| 422 | `PROJECT_FULL` | القبول بيعدّي السعة |
| 422 | `APPLICATION_DUPLICATE` | تقديم مكرر (طالب) |
| 422 | `APPLICATION_NOT_ELIGIBLE` | الطالب مش مؤهل (طالب) |
| 500 | `PROJECT_REQUEST_FAILED` | خطأ غير متوقع في المشاريع |
| 500 | `APPLICATION_FAILED` | خطأ غير متوقع في التقديمات |

---

## 10. خلاصة تنفيذية لليدر

**اللي جاهز:** إنشاء مشروع، تعديله، إرساله للمراجعة، اعتماده/رفضه/طلب
تعديلات، فتحه للتقديم، قراءة تقديماته، اتخاذ قرار على كل تقديم، واستكشاف
المواهب (بحث/فلترة/ملف).

**اللي ناقص وبيوقف شاشات كاملة:**

1. **قائمة مشاريع الشركة** (`GET /company/projects`) — أي شاشة "مشاريعي" بلا داتا.
2. **قراءة مشروع واحد للشركة** (`GET /company/projects/{id}`) — الشركة ما بتقدر تقرأ مشروعها.
3. **نسخ مشروع** — لا كود ولا طريق بديل نظيف.
4. **فريق المشروع + بدء التنفيذ** — دورة الحياة واقفة عند `open`؛ `active`/`completed` غير قابلين للوصول.
5. **مجموعة المواهب** (مقارنة/قوائم/ملاحظات/تواصل) — 5 روابط، لا شيء منها موجود.

**سؤال قرار مطلوب من الليدر:** هالخمسة تُنفّذ الآن كباك اند، ولا الفرونت يبني
شاشاتها لاحقاً وتنزل في سبرنت تالي؟ الجواب بيحدد إذا الدليل بينتحوّل لخطة
تنفيذ.
