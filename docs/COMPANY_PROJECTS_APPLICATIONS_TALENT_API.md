# دليل الباك اند — المشاريع والتقديمات والمواهب

**رد على طلب الليدر** · محدّث 2026-11-04 · `feature/authentication`

---

## 0. ملخص — إيش صار

النسخة الأولى من هالملف قالت إنه **11 رابط من 17 مش موجودين**. هلق صاروا
**كلهم موجودين**، بالإضافة لثلاثة روابط قراءة وروابط اللوغو.

| البند | قبل | بعد |
|---|---|---|
| روابط المشاريع المطلوبة | 3 من 7 | **7 من 7** |
| روابط التقديمات والفريق | 2 من 5 | **5 من 5** |
| روابط المواهب | 0 من 5 | **5 من 5** |
| رفع اللوغو | ❌ | ✅ `POST` + `DELETE` |
| الحالات القابلة للوصول | 6 انتقالات | **8 انتقالات** (`selection` و`active` صاروا reachable) |

**اللي أضفناه فوق الطلب** (لأن الكتابة بلا قراءة ما بتفيد): `GET /talent/lists`،
`GET /talent/lists/{id}`، `GET /talent/candidates/{id}/notes`.

**اللي لسا ناقص بصراحة:** لا يوجد رابط لإنهاء مشروع (`active → completed`)
ولا لأرشفته، ولا يوجد بعد رابط للطالب يرد فيه على طلب التواصل. التفاصيل في
القسم 11.

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
> بدل `401`.

### غلاف النجاح

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

### ⚠️ استثناء `401`

```json
{ "message": "Unauthenticated." }
```

بلا `code` وبلا `request_id`. **افحصوا الحالة 401 نفسها.**

### ⚠️ ثلاث أشكال مختلفة للـ `403`

| المصدر | الشكل |
|---|---|
| `admin` middleware | `{"success": false, "message": "You are not authorized to perform this action."}` |
| `role:company_admin` | `{"code": "COMPANY_ADMIN_ONLY", "message": "..."}` |
| `organization.approved` | `{"success": false, "code": "ORGANIZATION_PENDING" \| "ORGANIZATION_NOT_APPROVED", "message": "..."}` |

> ⚠️ روابط اللوغو (`/company/profile/logo`) هي الوحيدة اللي ردّها **بلا
> `request_id`** — لأنها بتستخدم نفس مساعدات ملف الشركة القديمة. لو بدكم
> نوحّدها، تعديل صغير.

---

## 2. المشاريع

### 2.1 `POST /api/v1/projects` — إنشاء

| | |
|---|---|
| الحراسة | `auth:sanctum` + `account.active` — **بلا `role`** |
| مين مسموح | `company_admin` / `university_admin` / `admin` (القرار في الـ service) |
| الناتج | دايماً **`draft`** |

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

**الرد `201`**

```json
{
  "success": true,
  "message": "Project created successfully.",
  "data": {
    "id": 41,
    "title": "لوحة تحليلات لبيانات المبيعات",
    "type": "company_sponsored",
    "domain": "data",
    "difficulty": 3,
    "work_mode": "remote",
    "career_role_id": 7,
    "career_role": { "id": 7, "title": "Data Analyst", "slug": "data-analyst", "version": 1 },
    "role": "Data Analyst",
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
      }
    ],
    "available_project_roles": [
      { "id": 88, "title": "Data Analyst", "description": "تحليل وتجهيز البيانات" }
    ],
    "eligibility": null,
    "capacity_state": null,
    "created_at": "2026-11-04T18:40:11+00:00",
    "updated_at": "2026-11-04T18:40:11+00:00"
  },
  "request_id": "8f2c1d4e-..."
}
```

> `eligibility` و `capacity_state` = `null` على روابط الإدارة — بيتحسبوا بس
> على تفاصيل المشروع للطالب.

**الأخطاء:** `403 PROJECT_CREATE_FORBIDDEN` · `422 VALIDATION_ERROR` ·
`422 PROJECT_ORGANIZATION_*` (4 أكواد) · `422 PROJECT_OWNER_*` (4 أكواد) ·
`422 PROJECT_TYPE_INVALID` · `422 PROJECT_SKILL_NOT_IN_CAREER_ROLE` ·
`422 PROJECT_INVALID_DATES` · `500 PROJECT_REQUEST_FAILED`

---

### 2.2 `PATCH /api/v1/projects/{project}` — تعديل

| | |
|---|---|
| مين مسموح | **المالك** أو `admin` |
| متى مسموح | بس `draft` أو `changes_requested` |

كل الحقول اختيارية. تعديل جزئي بيلمس بس اللي انبعت.

```json
{
  "title": "لوحة تحليلات المبيعات — النسخة الثانية",
  "capacity": 6
}
```

**الرد `200`** — نفس شكل `data` تاع الإنشاء، `message` = `"Project updated successfully."`

> **متى يزيد `version`؟** بس لما يتغيّر حقل مؤثر على المطابقة (المهارات،
> القدرة، النوع). التعديل الوصفي ما بيرفع الرقم.

**الأخطاء:** `403 PROJECT_NOT_OWNED` · `404 PROJECT_NOT_FOUND` ·
`422 PROJECT_NOT_EDITABLE` (مع `details.editable_statuses`) ·
`422 PROJECT_INVALID_DATES` · `422 PROJECT_SKILL_NOT_IN_CAREER_ROLE` ·
`422 VALIDATION_ERROR` · `500 PROJECT_REQUEST_FAILED`

---

### 2.3 `POST /api/v1/projects/{project}/submit` — إرسال للمراجعة

`draft` → `submitted` · `changes_requested` → `submitted`. **الجسم فاضي.**

**شرط الاكتمال** — لو ناقص شيء ما بينتقل، وكل النواقص بترجع مرة واحدة في
`details.missing`:

`type`, `title`, `description`, `objectives`, `capacity`,
`required_skills`, `roles`, `start_date`, `end_date`, `application_deadline`,
`end_date_before_start_date`, `application_deadline_after_start_date`

**الأخطاء:** `404 PROJECT_NOT_FOUND` · `403 PROJECT_NOT_OWNED` ·
`422 PROJECT_INCOMPLETE` · `422 PROJECT_INVALID_TRANSITION` ·
`409 PROJECT_STATUS_CONFLICT`

---

### 2.4 `POST /api/v1/projects/{project}/clone` — نسخ مشروع 🆕

| | |
|---|---|
| الحراسة | `auth:sanctum` + `account.active` |
| مين مسموح | **المالك** أو `admin` — نفس قاعدة التعديل |
| الناتج | مشروع **جديد** بحالة `draft`، `version = 1` |
| الرد | `201` — **`data.id` هو الجديد، مو اللي في الرابط** |

**الطلب — كله اختياري**

```json
{
  "title": "دفعة الربيع",
  "start_date": "2027-03-01",
  "end_date": "2027-05-01",
  "application_deadline": "2027-02-15",
  "capacity": 6
}
```

بلا `title`؟ بيصير `"<العنوان الأصلي> (copy)"` (مقطوع عند 255 حرف).

**اللي بينتسخ:** النوع، الـ career role، المهارات المطلوبة، الأدوار (حتى
المعطّلة)، قيود الأهلية، المالك، المؤسسة.

**اللي ما بينتسخ:** الحالة (دايماً `draft`)، `version`، وكل بيانات المراجعة
(`approved_by`, `approved_at`, …).

**الرد `201`**

```json
{
  "success": true,
  "message": "Project cloned successfully.",
  "data": {
    "id": 42,
    "title": "دفعة الربيع",
    "status": "draft",
    "version": 1,
    "capacity": 6,
    "start_date": "2027-03-01",
    "end_date": "2027-05-01",
    "application_deadline": "2027-02-15",
    "organization": { "id": 3, "title": "شركة النور" },
    "required_skills": [ { "skill_id": 12, "skill_name": "SQL", "minimum_level": 3 } ],
    "available_project_roles": [ { "id": 89, "title": "Data Analyst" } ]
  },
  "request_id": "8f2c1d4e-..."
}
```

> الرد هنا بيستخدم `ProjectResource` مباشرة، فما فيه `is_editable` ولا
> `project_roles` ولا `eligibility_constraints` — هدول بس في `company/projects`.
> لو بدك تفاصيل المحرّر بعد النسخ، اقرأ المشروع الجديد من
> `GET /company/projects/{data.id}`.

**الأخطاء:** `404 PROJECT_NOT_FOUND` · `403 PROJECT_NOT_OWNED` ·
`422 VALIDATION_ERROR` (مثلاً `capacity: 0`) · `500 PROJECT_REQUEST_FAILED`

> ⚠️ لو نسخ أدمن مشروع شركة، النسخة بتبقى **لنفس الشركة ولنفس المالك** — مو
> للأدمن.

---

### 2.5 `POST /api/v1/projects/{project}/transitions` — الانتقال العام 🆕

رابط واحد لكل حركة، بدل ما تحفظوا خمس مسارات.

```json
{ "to": "submitted" }
```

أو مع سبب:

```json
{ "to": "changes_requested", "reason": "النطاق واسع أكتر من فصل واحد." }
```

| `to` | من | مين | يعادل |
|---|---|---|---|
| `submitted` | `draft` · `changes_requested` | المالك/أدمن | `/submit` |
| `approved` | `submitted` | **أدمن** | `/approve` |
| `changes_requested` | `submitted` | **أدمن** | `/request-changes` |
| `rejected` | `submitted` | **أدمن** | `/reject` |
| `open` | `approved` | المالك/أدمن | `/open` |
| `selection` | `open` | المالك/أدمن | — |
| `active` | `selection` | المالك/أدمن | `/start` |

**الرد `200`** — نفس شكل المشروع مع الحالة الجديدة و`message` مطابق للمسار
المتخصص.

**الرفض**

```json
{
  "code": "PROJECT_INVALID_TRANSITION",
  "message": "That lifecycle transition is not allowed.",
  "request_id": "8f2c1d4e-...",
  "details": {
    "project_id": 41,
    "status": "draft",
    "requested_status": "open",
    "allowed_transitions": ["submitted"]
  }
}
```

> `allowed_transitions` هي **المجموعة القابلة للتنفيذ فعلاً**، مو كل ما تسمح به
> الحالة في الموديل. اللي بيمشي عليها ما بيوقع على طريق مسدود.

**الأخطاء:** `404 PROJECT_NOT_FOUND` · `403 PROJECT_NOT_OWNED` ·
`403` من `admin` middleware · `422 VALIDATION_ERROR` (حالة غير موجودة) ·
`422 PROJECT_INVALID_TRANSITION` · `422 PROJECT_REASON_REQUIRED` ·
`422 PROJECT_INCOMPLETE` · `422 PROJECT_TEAM_EMPTY` · `409 PROJECT_STATUS_CONFLICT`

---

### 2.6 مسارات المراجعة — أدمن فقط

`POST /projects/{project}/approve` · `/request-changes` · `/reject`

| | |
|---|---|
| الحراسة | `auth:sanctum` + `account.active` + `admin` |
| الجسم | `{ "reason": "..." }` — ≤ 1000 حرف |

- `request-changes`: `reason` **إلزامي** → وإلا `422 PROJECT_REASON_REQUIRED`
- `approve` / `reject`: `reason` اختياري، بيتسجّل في سجل التدقيق

---

### 2.7 `POST /api/v1/projects/{project}/open` — فتح التقديم

`approved` → `open`. الجسم فاضي. بعدها المشروع بيصير ظاهر للطلاب.

---

### 2.8 `POST /api/v1/projects/{project}/start` — بدء التنفيذ 🆕

| | |
|---|---|
| مين مسموح | المالك أو `admin` |
| الانتقال | `selection` → `active` |
| الشرط | **الفريق لازم يكون فيه عضو نشط واحد على الأقل** |

الجسم فاضي.

**الرد `200`**

```json
{
  "success": true,
  "message": "Project started successfully.",
  "data": { "id": 41, "status": "active", "version": 2 },
  "request_id": "8f2c1d4e-..."
}
```

**الأخطاء:** `422 PROJECT_TEAM_EMPTY` · `422 PROJECT_INVALID_TRANSITION` (لو
المشروع لسا `open` — لازم يمرق على `selection` أول) · `403 PROJECT_NOT_OWNED` ·
`404 PROJECT_NOT_FOUND`

---

### 2.9 `GET /api/v1/company/projects` — مشاريع شركتي 🆕

| | |
|---|---|
| الحراسة | `auth:sanctum` + `account.active` + `organization.approved` + `role:company_admin` |
| النطاق | **مؤسسة المستدعي فقط** — من عضويته، مو من أي بارامتر |

**Query**

| الحقل | القيم | ملاحظة |
|---|---|---|
| `q` | نص | يبحث في `title` و `domain`، ويطابق id لو رقم |
| `status` | أي من الـ 12 | قيمة غير معروفة **بتتجاهل**، مو 422 |
| `type` | `simulation` \| `company_sponsored` | |
| `career_role_id` | عدد | |
| `sort` | `recent` \| `oldest` \| `deadline` \| `title` | افتراضي `recent` |
| `page` | عدد ≥ 1 | |
| `per_page` | 1–100 | افتراضي 15 |

**الرد `200`**

```json
{
  "success": true,
  "message": "Company projects retrieved successfully.",
  "data": [
    {
      "id": 41,
      "title": "لوحة تحليلات لبيانات المبيعات",
      "type": "company_sponsored",
      "domain": "data",
      "status": "open",
      "capacity": 5,
      "application_deadline": "2026-12-01",
      "duration_days": 62,
      "version": 2,
      "organization": { "id": 3, "title": "شركة النور" },
      "required_skills": [ { "skill_id": 12, "skill_name": "SQL", "minimum_level": 3 } ],
      "available_project_roles": [ { "id": 88, "title": "Data Analyst" } ],
      "is_editable": false,
      "project_roles": [
        { "id": 88, "title": "Data Analyst", "description": null, "is_active": true }
      ],
      "eligibility_constraints": [
        { "id": 5, "constraint_type": "location", "value": "Damascus" }
      ],
      "created_at": "2026-11-01T09:00:00+00:00",
      "updated_at": "2026-11-03T12:30:00+00:00"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 },
  "stats": {
    "total": 1,
    "by_status": {
      "draft": 0, "submitted": 0, "changes_requested": 0, "approved": 0,
      "rejected": 0, "open": 1, "selection": 0, "active": 0,
      "under_review": 0, "completed": 0, "cancelled": 0, "archived": 0
    }
  },
  "filters": { "sort": "recent" },
  "request_id": "8f2c1d4e-..."
}
```

> `stats.by_status` فيها **الـ 12 حالة دايماً** حتى لو صفر، ومستقلة عن الفلاتر
> — عشان كروت الملخص تضل مرجع ثابت.

**الأخطاء:** `403 NO_ORGANIZATION` · `403 NOT_ORGANIZATION_ADMIN` ·
`403 ORGANIZATION_PENDING` · `403 COMPANY_ADMIN_ONLY`

---

### 2.10 `GET /api/v1/company/projects/{project}` — مشروع واحد 🆕

نفس الحقول، `data` مفرد بدل مصفوفة، و`meta`/`stats` مش موجودين.

**الأخطاء:** `404 PROJECT_NOT_FOUND` (حتى لو المشروع موجود لمؤسسة تانية —
**404 مو 403**، عشان الرابط ما يصير أداة تخمين) · نفس أكواد الـ 403 فوق

---

## 3. التقديمات

### 3.1 `GET /api/v1/projects/{project}/applications` — تقديمات مشروعي

| | |
|---|---|
| مين مسموح | مالك المشروع أو `admin` |
| Query | `page`, `per_page` (15، أقصى 100) |

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

> `project` و `applicant` مش محمّلين هون — اللي موجود `applicant_id`. الاسم
> بيجي في `GET /applications/{id}`.

**الأخطاء:** `404 PROJECT_NOT_FOUND` · `403 APPLICATION_LIST_FORBIDDEN` ·
`500 APPLICATION_FAILED`

---

### 3.2 `GET /api/v1/applications/{application}` — تقديم واحد 🆕

| | |
|---|---|
| الحراسة | `auth:sanctum` + `account.active` — **بلا `role`** |
| مين مسموح | **الطالب صاحب التقديم** · **مالك المشروع** · `admin` |

> الرابط بلا `role` بقصد: القارئ الطبيعي مرة يكون طالب ومرة يكون مالك مشروع،
> فالقاعدة هي الملكية مو الدور.

**الرد `200`**

```json
{
  "success": true,
  "message": "Application retrieved successfully.",
  "data": {
    "id": 512,
    "project_id": 41,
    "applicant_id": 77,
    "project_role": { "id": 88, "title": "Data Analyst" },
    "status": "shortlisted",
    "is_active": true,
    "project_version": 2,
    "decision_reason": "خبرة SQL قوية.",
    "decided_by": 9,
    "decided_at": "2026-11-03T19:02:10+00:00",
    "submitted_at": "2026-11-02T09:12:44+00:00",
    "project": { "id": 41, "title": "لوحة تحليلات لبيانات المبيعات", "status": "open" },
    "applicant": { "id": 77, "name": "ريم الحسن" },
    "created_at": "2026-11-02T09:12:44+00:00",
    "updated_at": "2026-11-03T19:02:10+00:00"
  },
  "request_id": "8f2c1d4e-..."
}
```

**الأخطاء:** `404 APPLICATION_NOT_FOUND` · `403 APPLICATION_VIEW_FORBIDDEN`

---

### 3.3 `POST /api/v1/applications/{application}/decision` — القرار 🆕

نفس المسار اللي طلبته. بيشتغل على `application_id` لحاله، بلا حاجة تعرف رقم
المشروع. في كمان المسار القديم
`PATCH /api/v1/projects/{project}/applications/{application}` بنفس الوظيفة
تماماً — أي واحد فيهم بيمشي، والرد متطابق حرفياً.

```json
{
  "status": "shortlisted",
  "reason": "خبرة SQL قوية ومشروع تخرج قريب من احتياجنا."
}
```

- `status` إلزامي: `shortlisted` / `accepted` / `rejected` / `waitlisted`
- `reason` اختياري ≤ 2000 حرف
- الصلاحية: مالك المشروع أو `admin` — بتتفحص جوّا الـ service على المشروع
  التابع للتقديم، فحذف المشروع من الرابط ما خفّف أي حماية

**الرد `200`** — نفس شكل `GET /applications/{id}`، `message` =
`"Application status updated successfully."`

**الأخطاء:** `404 APPLICATION_NOT_FOUND` · `403 APPLICATION_DECISION_FORBIDDEN` ·
`422 VALIDATION_ERROR` · `422 APPLICATION_INVALID_TRANSITION` · `422 PROJECT_FULL` ·
`500 APPLICATION_FAILED`

> المسار القديم بيضيف خطأ واحد زيادة: `404 APPLICATION_PROJECT_MISMATCH` — إذا
> رقم التقديم ما بيعود للمشروع اللي بالرابط. بمسار `POST` هالحالة مستحيلة لأن
> المشروع ما إله وجود بالرابط.

---

### 3.4 `POST /api/v1/projects/{project}/team/assignments` — تعيين الفريق 🆕

| | |
|---|---|
| مين مسموح | المالك أو `admin` |
| متى مسموح | المشروع `open` أو `selection` |
| الأثر الجانبي | أول تعيين بينقل `open` → `selection` تلقائياً |

**الطلب**

```json
{
  "name": "دفعة أ",
  "members": [
    { "application_id": 512, "project_role": "Data Analyst" },
    { "application_id": 513 }
  ]
}
```

- `members` إلزامي، 1–100
- `members.*.application_id` إلزامي و`distinct` (نفس التقديم مرتين = 422)
- `members.*.project_role` اختياري — بلاها بياخد عنوان دور التقديم
- `name` اختياري (اسم الفريق)

**الرد `201`**

```json
{
  "success": true,
  "message": "Team members assigned successfully.",
  "data": {
    "id": 7,
    "project_id": 41,
    "name": "دفعة أ",
    "members_count": 2,
    "members": [
      {
        "id": 21,
        "user_id": 77,
        "name": "ريم الحسن",
        "email": "reem@example.com",
        "project_role": "Data Analyst",
        "assignment_state": "active",
        "joined_at": "2026-11-04T19:10:00+00:00",
        "left_at": null
      }
    ],
    "created_at": "2026-11-04T19:10:00+00:00",
    "updated_at": "2026-11-04T19:10:00+00:00"
  },
  "request_id": "8f2c1d4e-..."
}
```

> الإيميل موجود هون — خلافاً لروابط المواهب. الفرق مقصود: هدول أعضاء فريق
> **مقبولين**، والمالك لازم يقدر يتواصل معهم. مجرد وجود شخص على shortlist ما
> بيعطي إيميله.

**الأخطاء**

| الحالة | `code` | المعنى |
|---|---|---|
| 404 | `PROJECT_NOT_FOUND` | المشروع مش موجود |
| 403 | `PROJECT_NOT_OWNED` | مش المالك |
| 422 | `PROJECT_NOT_ASSIGNABLE` | الحالة مش `open`/`selection` |
| 422 | `APPLICATION_NOT_FOUND` | `application_id` مش موجود |
| 422 | `APPLICATION_PROJECT_MISMATCH` | التقديم لمشروع تاني |
| 422 | `APPLICATION_NOT_ACCEPTED` | التقديم مش `accepted` |
| 422 | `TEAM_MEMBER_ALREADY_ASSIGNED` | الشخص معيّن أصلاً |
| 422 | `PROJECT_FULL` | الفريق بيعدّي السعة |
| 422 | `VALIDATION_ERROR` | جسم غير صالح |
| 500 | `PROJECT_REQUEST_FAILED` | خطأ غير متوقع |

> كل التعيينات في الطلب الواحد داخل transaction واحدة: لو واحد فشل، **ولا
> واحد** بينتعيّن.

---

## 4. المواهب

### 4.1 `GET /api/v1/talent` — القائمة

| الحراسة | `auth:sanctum` + `account.active` + `role:company_admin` + `organization.approved` |
|---|---|

**Query:** `search` · `career_role` (slug) · `min_readiness` (0–100) ·
`sort` (`readiness` \| `recent`) · `page` · `per_page` (1–100)

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

`data` فيه الملف الكامل (مهارات، مشاريع، أدلة، تقييم).

> ⚠️ **إيميل الطالب مش موجود — بقصد.** الرد عند عدم الرؤية `404` بشكل
> `{"success": false, "message": "No discoverable student with that id."}` —
> **بلا `code`**.

### 4.3 `GET /api/v1/talent/filters` — قيم الفلاتر المتاحة

بيرجّع بس الـ career roles اللي فعلاً بتعطي نتائج لهذا المستدعي.

---

### 4.4 `POST /api/v1/talent/compare` — مقارنة 🆕

```json
{ "student_ids": [77, 81, 90] }
```

- 2 إلى **5** مرشحين
- المرشح غير المرئي بينحط في `unavailable` **وما بيفشل الطلب** — إلا لو فضل
  أقل من اتنين

**الرد `200`**

```json
{
  "success": true,
  "message": "Candidates compared successfully.",
  "data": {
    "students": [
      {
        "id": 77,
        "name": "ريم الحسن",
        "career_role": { "id": 7, "title": "Data Analyst", "slug": "data-analyst" },
        "specialization": "تحليل بيانات",
        "university_name": "جامعة دمشق",
        "readiness": { "score": 74, "band": "ready" },
        "completeness_percent": 82,
        "contactable": true,
        "skills": [
          { "name": "SQL", "category": "data", "level": 4, "max_level": 5, "verified": true, "evidence_count": 2 }
        ]
      },
      {
        "id": 81,
        "name": "سامي درويش",
        "readiness": { "score": 61, "band": "developing" },
        "completeness_percent": 70,
        "contactable": true,
        "skills": [
          { "name": "SQL", "category": "data", "level": 2, "max_level": 5, "verified": false, "evidence_count": 0 }
        ]
      }
    ],
    "skills": [
      {
        "name": "SQL",
        "category": "data",
        "levels": { "77": 4, "81": 2 },
        "verified": { "77": true, "81": false }
      }
    ],
    "unavailable": [90]
  },
  "request_id": "8f2c1d4e-..."
}
```

> `levels` و `verified` مفاتيحها **id الطالب كنص**. كل صف فيه قيمة لكل طالب —
> `null` معناها "ما عنده المهارة"، مو "الحقل ناقص". الصفوف مرتبة حسب عدد
> الطلاب اللي عندهم المهارة.

**الأخطاء:** `422 VALIDATION_ERROR` (أقل من 2، أكثر من 5، id مكرر) ·
`422 TALENT_COMPARE_TOO_FEW` · `500 TALENT_REQUEST_FAILED`

---

### 4.5 `POST /api/v1/talent/lists` — إنشاء shortlist 🆕

```json
{ "name": "Backend — الربيع", "description": "مرشحو مشروع الـ API." }
```

**الرد `201`**

```json
{
  "success": true,
  "message": "Talent list created successfully.",
  "data": {
    "id": 3,
    "name": "Backend — الربيع",
    "description": "مرشحو مشروع الـ API.",
    "candidates_count": 0,
    "creator": { "id": 9, "name": "لينا" },
    "created_at": "2026-11-04T19:20:00+00:00",
    "updated_at": "2026-11-04T19:20:00+00:00"
  },
  "request_id": "8f2c1d4e-..."
}
```

**الأخطاء:** `422 VALIDATION_ERROR` · `500 TALENT_REQUEST_FAILED`

### 4.6 `GET /api/v1/talent/lists` — قوائم شركتي 🆕

```json
{
  "success": true,
  "message": "Talent lists retrieved successfully.",
  "data": [
    { "id": 3, "name": "Backend — الربيع", "description": null, "candidates_count": 4,
      "creator": { "id": 9, "name": "لينا" },
      "created_at": "2026-11-04T19:20:00+00:00", "updated_at": "2026-11-04T19:20:00+00:00" }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 },
  "request_id": "8f2c1d4e-..."
}
```

### 4.7 `GET /api/v1/talent/lists/{list}` — قائمة واحدة 🆕

نفس الحقول + `candidates`:

```json
{
  "id": 3,
  "name": "Backend — الربيع",
  "candidates_count": 1,
  "candidates": [
    { "id": 11, "student_id": 77, "name": "ريم الحسن", "note": "SQL قوي.", "added_at": "2026-11-04T19:22:00+00:00" }
  ]
}
```

**الأخطاء:** `404 TALENT_LIST_NOT_FOUND` (حتى لو القائمة لمؤسسة تانية)

### 4.8 `POST /api/v1/talent/lists/{list}/candidates` — إضافة مرشح 🆕

```json
{ "student_id": 77, "note": "SQL قوي." }
```

**الرد:** `201` لو جديد، **`200` لو موجود أصلاً** — الضغط مرتين على زر
"أضف للقائمة" مو خطأ.

```json
{
  "success": true,
  "message": "Candidate added to the list.",
  "data": { "id": 11, "student_id": 77, "name": "ريم الحسن", "note": "SQL قوي.", "already_listed": false },
  "request_id": "8f2c1d4e-..."
}
```

> `note` لو انبعتت على إضافة مكررة بتعتبر **تعديل** على الملاحظة.

**الأخطاء:** `404 TALENT_LIST_NOT_FOUND` · `404 TALENT_NOT_FOUND` (المرشح مش
مرئي) · `422 VALIDATION_ERROR` · `500 TALENT_REQUEST_FAILED`

---

### 4.9 `POST /api/v1/talent/candidates/{student}/notes` — ملاحظة 🆕

```json
{ "body": "تواصل ممتاز، بدها مقابلة تانية." }
```

**الرد `201`**

```json
{
  "success": true,
  "message": "Note added successfully.",
  "data": {
    "id": 14,
    "body": "تواصل ممتاز، بدها مقابلة تانية.",
    "author": { "id": 9, "name": "لينا" },
    "created_at": "2026-11-04T19:25:00+00:00"
  },
  "request_id": "8f2c1d4e-..."
}
```

**الأخطاء:** `404 TALENT_NOT_FOUND` · `422 VALIDATION_ERROR` (جسم فاضي) ·
`500 TALENT_REQUEST_FAILED`

### 4.10 `GET /api/v1/talent/candidates/{student}/notes` — ملاحظات المرشح 🆕

```json
{
  "success": true,
  "message": "Candidate notes retrieved successfully.",
  "data": [
    { "id": 14, "body": "تواصل ممتاز، بدها مقابلة تانية.",
      "author": { "id": 9, "name": "لينا" }, "created_at": "2026-11-04T19:25:00+00:00" }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 },
  "request_id": "8f2c1d4e-..."
}
```

> الملاحظات **للمؤسسة كلها**، مو للكاتب — والطالب ما بيشوفها أبداً.

---

### 4.11 `POST /api/v1/talent/candidates/{student}/contact` — طلب تواصل 🆕

```json
{ "message": "بدنا نناقش تدريب باك اند لمدة فصل." }
```

**الرد `201`**

```json
{
  "success": true,
  "message": "Contact request sent to the candidate.",
  "data": {
    "id": 5,
    "student_id": 77,
    "candidate_name": "ريم الحسن",
    "message": "بدنا نناقش تدريب باك اند لمدة فصل.",
    "status": "pending",
    "created_at": "2026-11-04T19:30:00+00:00"
  },
  "request_id": "8f2c1d4e-..."
}
```

> **ما في إيميل في الرد.** الطلب بينتسجل، والطالب هو اللي بيقرر يرد.

**الأخطاء:** `404 TALENT_NOT_FOUND` · `422 TALENT_CONTACT_PENDING` (في طلب
معلّق أصلاً لنفس المرشح من نفس الشركة) · `422 VALIDATION_ERROR` ·
`500 TALENT_REQUEST_FAILED`

> طلب `declined` أو `accepted` **ما بيمنع** طلب جديد — بس المعلّق بيمنع.

---

## 5. اللوغو 🆕

### `POST /api/v1/company/profile/logo`

`multipart/form-data` — الحقل اسمه **`logo`** (مو `file`).

| | |
|---|---|
| الحراسة | نفس مجموعة ملف الشركة |
| الأنواع | PNG · JPEG · WebP |
| الحجم | 2 MB (قابل للتغيير من `COMPANY_LOGO_MAX_KB`) |
| الفحص | نفس فحص المستندات (توقيع + clamd لو متاح) |

**الرد `200`**

```json
{
  "success": true,
  "message": "Logo updated successfully.",
  "data": {
    "logo": "logos/3/AbC123.png",
    "logo_url": "https://skillspan.alwaysdata.net/storage/logos/3/AbC123.png",
    "profile": { "id": 3, "name": "شركة النور", "logo_url": "..." }
  }
}
```

> `logo` = المسار المخزّن (نفس معناها القديم)، `logo_url` = الشكل القابل
> للعرض. الاثنين بيرجعوا في `GET /profile/me` كمان.

**الأخطاء**

| الحالة | `code` | المعنى |
|---|---|---|
| 422 | `LOGO_MISSING` | ما انبعت ملف |
| 422 | `LOGO_TOO_LARGE` | أكبر من الحد |
| 422 | `LOGO_TYPE_NOT_SUPPORTED` | مش PNG/JPEG/WebP، أو المحتوى ما يطابق الامتداد |
| 422 | `LOGO_REJECTED_BY_SCAN` | انرفض من الفحص |
| 422 | `LOGO_UPLOAD_FAILED` | الرفع انقطع |
| 500 | `LOGO_STORAGE_FAILED` | الخزن فشل |

### `DELETE /api/v1/company/profile/logo`

بيمسح الملف من القرص ويرجّع `logo: null`. **Idempotent** — الطلب التاني بينجح
كمان.

> 🔒 **SVG مرفوض بقصد** — SVG ملف XML بيقدر يحمل سكربت، واللوغو بينعرض بكل
> صفحة بتظهر فيها الشركة.

---

## 6. الحالات الـ 12 ومين بينقل لمين

### 6.1 الأسماء بالضبط (`Project::STATUSES`)

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

> `closed` **مو من الـ 12**. قيمة قديمة للتوافق مع قاعدة البيانات فقط.

### 6.2 الانتقالات — كلها قابلة للتنفيذ هلق

| من | إلى | مين | الرابط |
|---|---|---|---|
| `draft` | `submitted` | المالك / أدمن | `/submit` أو `/transitions` |
| `submitted` | `approved` | أدمن | `/approve` أو `/transitions` |
| `submitted` | `changes_requested` | أدمن | `/request-changes` أو `/transitions` |
| `submitted` | `rejected` | أدمن | `/reject` أو `/transitions` |
| `changes_requested` | `submitted` | المالك / أدمن | `/submit` أو `/transitions` |
| `approved` | `open` | المالك / أدمن | `/open` أو `/transitions` |
| `open` | `selection` | المالك / أدمن | `/transitions` أو أول تعيين فريق |
| `selection` | `active` | المالك / أدمن | `/start` أو `/transitions` |

### 6.3 اللي لسا مسدود

| من | الحالة |
|---|---|
| `active` | ما في انتقال خارجي — **لا يوجد رابط لإنهاء المشروع** |
| `under_review` | غير قابل للوصول إطلاقاً |
| `completed` · `cancelled` · `archived` · `rejected` | نهائية |

يعني المشروع بيوصل لـ `active` وبيقف. **إنهاء المشروع (`completed`) وأرشفته
(`archived`) لسا بلا روابط** — لازم قرار قبل ما تنبني شاشة "المشاريع المنتهية".

---

## 7. قيم القرار

**أربع قيم، بأسماء كاملة:**

```json
{ "status": "shortlisted" }
{ "status": "accepted" }
{ "status": "rejected" }
{ "status": "waitlisted" }
```

- ❌ `shortlist` / `accept` / `reject` / `waitlist` → `422 VALIDATION_ERROR`
- ✅ `shortlisted` / `accepted` / `rejected` / `waitlisted`

**حالات التقديم الست:** `submitted`, `shortlisted`, `accepted`, `rejected`,
`waitlisted`, `withdrawn`.

| من | إلى |
|---|---|
| `submitted` | `shortlisted` · `accepted` · `rejected` · `waitlisted` · `withdrawn` |
| `shortlisted` | `accepted` · `rejected` · `waitlisted` · `withdrawn` |
| `waitlisted` | `accepted` · `rejected` · `withdrawn` |
| `accepted` | `withdrawn` |
| `rejected` · `withdrawn` | نهائي |

- `withdrawn` بيجي من **الطالب** عبر `POST /applications/{id}/withdraw`
- القبول بيعدّي السعة → `422 PROJECT_FULL`
- **فقط `accepted` بينتعيّن على الفريق**

---

## 8. جدول أكواد الأخطاء الكامل

| الحالة | `code` | متى |
|---|---|---|
| 401 | *(بلا `code`)* | توكن ناقص/منتهي |
| 403 | `COMPANY_ADMIN_ONLY` | الدور مش `company_admin` |
| 403 | `ORGANIZATION_PENDING` | المؤسسة قيد المراجعة |
| 403 | `ORGANIZATION_NOT_APPROVED` | المؤسسة مرفوضة |
| 403 | `ACCOUNT_DISABLED` | حساب محذوف/معطّل |
| 403 | `NO_ORGANIZATION` | الحساب مش تابع لأي مؤسسة |
| 403 | `NOT_ORGANIZATION_ADMIN` | عضو بس مو أدمن |
| 403 | *(بلا `code`، `success:false`)* | `admin` middleware |
| 403 | `PROJECT_CREATE_FORBIDDEN` | إنشاء بدور غير مسموح |
| 403 | `PROJECT_NOT_OWNED` | إدارة/نسخ/تعيين مشروع مش ملكه |
| 403 | `PROJECT_REVIEW_FORBIDDEN` | مراجعة من غير أدمن |
| 403 | `APPLICATION_VIEW_FORBIDDEN` | قراءة تقديم مش له |
| 403 | `APPLICATION_DECISION_FORBIDDEN` | قرار على مشروع مش ملكه |
| 403 | `APPLICATION_LIST_FORBIDDEN` | قراءة تقديمات مشروع مش ملكه |
| 404 | `PROJECT_NOT_FOUND` | مشروع غير موجود / مش لمؤسستك |
| 404 | `APPLICATION_NOT_FOUND` | تقديم غير موجود |
| 404 | `APPLICATION_PROJECT_MISMATCH` | تقديم مش تابع للمشروع |
| 404 | `TALENT_NOT_FOUND` | موهبة غير مرئية |
| 404 | `TALENT_LIST_NOT_FOUND` | قائمة مش لمؤسستك |
| 404 | *(بلا `code`، `success:false`)* | `GET /talent/{id}` لموهبة غير مرئية |
| 409 | `PROJECT_STATUS_CONFLICT` | تعارض حالة متزامن |
| 422 | `VALIDATION_ERROR` | جسم غير صالح — `errors` |
| 422 | `PROJECT_INCOMPLETE` | نواقص قبل الإرسال — `details.missing` |
| 422 | `PROJECT_NOT_EDITABLE` | تعديل خارج النافذة |
| 422 | `PROJECT_INVALID_TRANSITION` | انتقال غير مسموح — `details.allowed_transitions` |
| 422 | `PROJECT_REASON_REQUIRED` | `request-changes` بلا سبب |
| 422 | `PROJECT_INVALID_DATES` | ترتيب تواريخ خاطئ |
| 422 | `PROJECT_TYPE_INVALID` | نوع مشروع غير مدعوم |
| 422 | `PROJECT_SKILL_NOT_IN_CAREER_ROLE` | مهارة خارج الـ career role |
| 422 | `PROJECT_NOT_ASSIGNABLE` | تعيين فريق خارج `open`/`selection` |
| 422 | `PROJECT_TEAM_EMPTY` | بدء مشروع بلا فريق |
| 422 | `PROJECT_FULL` | تعدّي السعة (قبول أو تعيين) |
| 422 | `PROJECT_ORGANIZATION_*` | 4 أكواد خاصة بالمؤسسة |
| 422 | `PROJECT_OWNER_*` | 4 أكواد خاصة بالمالك |
| 422 | `APPLICATION_INVALID_TRANSITION` | قرار غير مسموح من الحالة الحالية |
| 422 | `APPLICATION_NOT_ACCEPTED` | تعيين تقديم مش `accepted` |
| 422 | `APPLICATION_DUPLICATE` | تقديم مكرر (طالب) |
| 422 | `APPLICATION_NOT_ELIGIBLE` | الطالب مش مؤهل (طالب) |
| 422 | `TEAM_MEMBER_ALREADY_ASSIGNED` | الشخص معيّن أصلاً |
| 422 | `TALENT_COMPARE_TOO_FEW` | أقل من اتنين مرئيين للمقارنة |
| 422 | `TALENT_CONTACT_PENDING` | في طلب تواصل معلّق |
| 422 | `LOGO_MISSING` · `LOGO_TOO_LARGE` · `LOGO_TYPE_NOT_SUPPORTED` · `LOGO_REJECTED_BY_SCAN` · `LOGO_UPLOAD_FAILED` | رفع اللوغو |
| 500 | `PROJECT_REQUEST_FAILED` | خطأ غير متوقع في المشاريع/الفريق |
| 500 | `APPLICATION_FAILED` | خطأ غير متوقع في التقديمات |
| 500 | `TALENT_REQUEST_FAILED` | خطأ غير متوقع في المواهب |
| 500 | `LOGO_STORAGE_FAILED` | خزن اللوغو فشل |

---

## 9. أخطاء شائعة عند الربط

| العرض | السبب |
|---|---|
| `401` على كل شي | `Accept: application/json` ناقص، أو توكن من سيرفر تاني |
| `403 COMPANY_ADMIN_ONLY` | التوكن لطالب، مو لشركة |
| `403 ORGANIZATION_PENDING` | المؤسسة لسا ما انعتمدت |
| `403 NOT_ORGANIZATION_ADMIN` | عضو مو أدمن بالمؤسسة |
| `404` على مشروعك | المشروع تابع لمؤسسة تانية، أو id غلط |
| `422 PROJECT_NOT_EDITABLE` | المشروع مش `draft`/`changes_requested` |
| `422 APPLICATION_NOT_ACCEPTED` | بدك تعيّن شخص لسا `shortlisted` |
| `422 PROJECT_TEAM_EMPTY` | بدك تبدأ مشروع بلا فريق |
| `422 PROJECT_INVALID_TRANSITION` | راجع `details.allowed_transitions` |
| `201` مع `already_listed: true` | مستحيل — المكرر بيرجّع `200` |
| `data.id` بعد clone = id الرابط | غلط — اقرأ `data.id`، هو المشروع الجديد |

---

## 10. ملاحظات على العقد

1. **`data` دايماً ملفوف.** كل نجاح `{data, success, message, request_id}`.
   الاستثناء: `POST/DELETE /company/profile/logo` بلا `request_id`.
2. **`eligibility` و `capacity_state` = `null`** على كل روابط الإدارة — يعني
   "ما تحسبت"، **مو** "متاح".
3. **`stats.by_status`** فيها الـ 12 حالة دايماً، ومستقلة عن الفلاتر.
4. **`is_editable`** بيجي بس من `company/projects` — هو اللي بيقرر زر التعديل.
5. **`project_roles` مقابل `available_project_roles`:** الأول كل الأدوار (حتى
   المعطّلة، للفورم)، التاني الأدوار الفعّالة (للعرض).
6. **الإيميل:** ممنوع في `/talent*`، مسموح في `team/assignments` فقط.

---

## 11. اللي لسا ناقص — بصراحة

| النقص | الأثر |
|---|---|
| `active → completed` | ما في طريقة تخلّص مشروع. شاشة "مكتمل" بلا داتا |
| `under_review` | حالة معرّفة بس ما حدا بيوصلها |
| أرشفة / إلغاء من جهة الشركة | `cancel` موجود للأدمن فقط (`/admin/projects/{id}/cancel`) |
| رد الطالب على طلب التواصل | الجدول بيدعم `accepted`/`declined`، بس ما في راوت للطالب |

**الأسئلة الثلاثة — الجواب النهائي:**

1. **الحالات:** 12، `draft` … `archived`. **8 انتقالات قابلة للتنفيذ** (جدول
   6.2). `active` نهائية حالياً.
2. **قيم القرار:** `shortlisted` / `accepted` / `rejected` / `waitlisted`.
3. **اللوغو:** **نعم** — `POST` و`DELETE` على `/company/profile/logo`.
