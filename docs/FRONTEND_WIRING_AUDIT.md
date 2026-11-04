# تدقيق الربط — الفرونت مع الباك (واجهة الشركة)

**النسخة المفحوصة:** `SkillSpan-Frontend-FULL-tested/skillspan-frontend`
**الباك:** `8eab8e1` المنشور على `https://skillspan.alwaysdata.net`
**التاريخ:** 2026-11-04

> ## ✅ الحالة: منتهي — كل اللي تحت هالخط انصلح
>
> - **الـ5 أجسام** تصلّحت بالفرونت (مع تحديث الاختبارات اللي كانت بتثبّت الشكل القديم).
> - **الـ3 فلاتر الصامتة** صارت `q` / `recent|oldest|deadline|title` / `stats.by_status`.
> - **الـ6 مسارات الناقصة** انعملت بالباك: `GET /projects/{id}/team`،
>   `DELETE /projects/{id}/team/assignments/{application}`،
>   `POST /projects/{id}/team/invitations`، `GET`+`POST /team-invitations/{id}*`،
>   `PATCH`/`DELETE /talent/lists/{id}`، `DELETE /talent/lists/{id}/candidates/{student}`،
>   `GET /talent/funnel`.
> - **الـ4 أزرار دورة حياة** صار إلها انتقالات: `reopen` · `revise` · `complete` ·
>   `cancel` · `archive`.
>
> **اللي بيضل من هالتدقيق:** اختبارات الفرونت اللي بتفشل بسبب مكوّنات ناقصة
> بالنسخة نفسها (22 اختبار في `CompanyProfile` و`CompanyDashboard` و
> `CompanyNotifications` و`CompanyCache` و`ProjectPublication` و`TalentEvidence`).
> سببها `Element type is invalid … Check the render method of ProfileEditForm`
> — مكوّن مش مُصدَّر بالنسخة، **ولا علاقة إلها بالربط**. ما تنلمس من هالجانب.

---

## 0. الخلاصة بجملة

**المسارات (paths) كلها صح** — ولا مسار مكتوب غلط. بس في **5 نداءات جسمها (body)
مختلف عن اللي بيستناه الباك** فبترجع 422، و**6 مسارات ناقصة بالباك** بترجع 404،
و**3 مسارات دعوات** ما إلها وجود بالباك أصلاً، و**3 فلاتر بتشتغل بالصامت** (ما
بترجع خطأ بس ما بتعمل شي).

الترتيب بالأولوية: القسم 1 قبل 2 قبل 3.

---

## 1. نداءات بتفشل — 5

كل واحد إله تعديل بسطر واحد بالفرونت. العمود الأخير هو الحل الأدق.

### 1.1 قرار التقديم — أخطرها

| | |
|---|---|
| المسار | `POST /api/v1/applications/{id}/decision` ✅ موجود |
| الفرونت يبعت | `{ "decision": "shortlist", "reason": "..." }` |
| الباك يستنى | `{ "status": "shortlisted", "reason": "..." }` |
| النتيجة | **422 `VALIDATION_ERROR`** — `status` مطلوب |

الفرونت عندو الخريطة جاهزة أصلاً في `applicationReviewApi.js`:

```js
export const DECISION_RESULT = { shortlist: 'shortlisted', waitlist: 'waitlisted', accept: 'accepted', reject: 'rejected' };
```

فالتعديل:

```js
// كان
const body = { decision, ...(reason ? { reason } : {}) };
// صار
const body = { status: DECISION_RESULT[decision], ...(reason ? { reason } : {}) };
```

**يعني كل أزرار القرار (shortlist / waitlist / accept / reject) ما بتشتغل اليوم.**

### 1.2 تعيين الفريق

| | |
|---|---|
| المسار | `POST /api/v1/projects/{id}/team/assignments` ✅ موجود |
| الفرونت يبعت | `{ "assignments": [ { "application_id": 1, "role_id": 2 } ] }` |
| الباك يستنى | `{ "members": [ { "application_id": 1, "project_role": "Data Analyst" } ] }` |
| النتيجة | **422** — `members` مطلوب |

اسم الحقل، واسم حقل الدور، ونوع الدور (الباك بياخد **نص** حر، مش `role_id`):

```js
const body = {
  members: assignments.map((a) => ({
    application_id: a.applicationId,
    ...(a.roleName ? { project_role: a.roleName } : {}),
  })),
};
```

### 1.3 مقارنة المواهب

| | |
|---|---|
| المسار | `POST /api/v1/talent/compare` ✅ موجود |
| الفرونت يبعت | `{ "candidate_ids": [2,3] }` |
| الباك يستنى | `{ "student_ids": [2,3] }` |
| النتيجة | **422** |

### 1.4 إضافة مرشح لقائمة

| | |
|---|---|
| المسار | `POST /api/v1/talent/lists/{id}/candidates` ✅ موجود |
| الفرونت يبعت | `{ "candidate_ids": [2,3,4] }` |
| الباك يستنى | `{ "student_id": 2 }` — **واحد لكل طلب** |
| النتيجة | **422** |

الحل: لفة على المصفوفة، طلب لكل مرشح. المكرر بيرجع `200` مع
`already_listed: true` فما في مشكلة لو انعاد.

### 1.5 إنشاء قائمة مع مرشحين — صامت

`POST /api/v1/talent/lists` بيقبل `name` بس. الفرونت بيبعت `candidate_ids` كمان
والباك بيتجاهلها → **القائمة بتنعمل فاضية**. لازم نداء 1.4 بعدها.

---

## 2. فلاتر بتشتغل بالصامت — 3

ما بترجع خطأ، بس ما بتعمل شي. أخطر من الخطأ لأنه ما بينتبه عليها حدا.

| الشاشة | الفرونت بيبعت | الباك بيقرأ | الأثر |
|---|---|---|---|
| بحث المشاريع | `search` | `q` | البحث ما بيرجّع نتائج مفلترة |
| ترتيب المشاريع | `updated_desc` · `deadline_asc` · `title_asc` · `created_desc` | `recent` · `oldest` · `deadline` · `title` | كل الترتيب بيرجع `recent` |
| عدّادات الفلاتر | `meta.status_counts` | `stats.by_status` (برّا `meta`) | الشيبس بلا أرقام |

الحل بالفرونت: `search` → `q`، وخريطة قيم للترتيب، و`statusCounts` تُقرأ من
`payload.stats.by_status` بدل `meta.status_counts`.

---

## 3. مسارات ناقصة بالباك — 6

الفرونت بيناديها، الباك بيرجّع **404**.

| # | المسار | مين بيستعمله |
|---|---|---|
| 1 | `GET /api/v1/projects/{id}/team` | شاشة الفريق — بتحمّل الفريق أول ما تفتح |
| 2 | `DELETE /api/v1/projects/{id}/team/assignments/{applicationId}` | إزالة تعيين |
| 3 | `PATCH /api/v1/talent/lists/{id}` | إعادة تسمية قائمة |
| 4 | `DELETE /api/v1/talent/lists/{id}` | حذف قائمة |
| 5 | `DELETE /api/v1/talent/lists/{id}/candidates/{studentId}` | إزالة مرشح من قائمة |
| 6 | `GET /api/v1/talent/funnel` | عدّادات المسار (محفوظ/تواصل/ردّ) |

---

## 4. الدعوات — 3 مسارات بلا جدول أصلاً

الفرونت فيه تدفّق دعوات كامل، والباك ما عندو `team_invitations` ولا جدول ولا
موديل. يعني هاد **ميزة كاملة**، مش راوت ناقص:

- `POST /api/v1/projects/{id}/team/invitations`
- `GET /api/v1/team-invitations/{id}`
- `POST /api/v1/team-invitations/{id}/response`

قرار مطلوب قبل التنفيذ: مين بيقبل الدعوة، ووين بتظهر للطالب، وشو بيصير للتعيين
إذا انرفضت.

---

## 5. أفعال دورة الحياة — 4 أزرار ما إلها انتقال

الفرونت بيعرضها حسب جدول Sprint، والباك بيرفضها **422 `PROJECT_INVALID_TRANSITION`**
(والفرونت بيعرض الرفض، فالشاشة ما بتنهار — بس الزر ما بينفع أبداً).

| الفعل | من → إلى | موجود بالباك؟ |
|---|---|---|
| `reopen` | `selection` → `open` | ❌ |
| `revise` | `rejected` → `draft` | ❌ |
| `archive` | `rejected` / `cancelled` / `completed` → `archived` | ❌ |
| `cancel` | أي حالة → `cancelled` | ❌ (موجود للأدمن بس: `/admin/projects/{id}/cancel`) |

**اللي بيشتغل من نفس الشاشة:** `submit` · `resubmit` · `open` · `move_to_selection` · `start`.

---

## 6. اللي مربوط صح — تأكيد

مفحوص مسار + فعل + جسم، وكلو مطابق:

**المشاريع:** `POST /projects` · `PATCH /projects/{id}` · `POST /projects/{id}/submit` ·
`POST /projects/{id}/clone` · `GET /company/projects` · `GET /company/projects/{id}` ·
`POST /projects/{id}/transitions` (لأفعال القسم 5 اللي إلها انتقال)

**التقديمات:** `GET /projects/{id}/applications` · `GET /applications/{id}` ·
`POST /projects/{id}/start`

**المواهب:** `GET /talent` · `GET /talent/{id}` · `GET /talent/filters` ·
`GET /talent/lists` · `GET /talent/lists/{id}` · `GET`/`POST /talent/candidates/{id}/notes` ·
`POST /talent/candidates/{id}/contact`

**الملف واللوحة:** `GET`/`PATCH /profile/me` · `POST /company/profile/documents` ·
`GET /company/profile/documents/{id}` · `GET /company/dashboard` ·
`GET /organization/profile` · مسارات الإشعارات الأربعة

**اللوغو:** `logo_url` بينعرض بـ `<img src>` **بلا** `Authorization` — بعكس روابط
المستندات.

---

## 7. الخلاصة التنفيذية

| الجهة | الشغل |
|---|---|
| الفرونت | 5 تعديلات جسم (القسم 1) + 3 فلاتر (القسم 2) — كلها دقائق |
| الباك | 6 راوتات (القسم 3) + 4 انتقالات (القسم 5) |
| قرار | تدفّق الدعوات (القسم 4) |
