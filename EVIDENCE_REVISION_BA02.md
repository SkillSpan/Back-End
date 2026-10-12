# BA-02 — حسم Evidence Revision والحالات

> **القرار النهائي:** حالة `revision` **مطلوبة فعلًا** واتضافت رسميًا كحالة رابعة في دورة مراجعة الدليل.
> الحالة اتضافت بناءً على **SRS** (مش لأن الفرونت بيستخدمها) — الأدلة تحت.
>
> **النطاق:** Backend فقط (Laravel). الفرونت مش اتغيّر منه حاجة هنا.

---

## ١. إيه اللي كان موجود قبل التعديل (نتيجة الفحص الكامل)

اتفحص الـimplementation كله: `routes/api.php`, `EvidenceController`, `SkillEvidence` model,
migrations, validation, policies/permissions, `SkillEvaluationService`, OpenAPI, وtests.

| الطبقة | الملف | اللي كان موجود |
|---|---|---|
| Route | `routes/api.php` (سطر 295–306) | `POST /evidence`, `GET /evidence`, `GET /evidence/{id}`, `PUT /evidence/{id}/review` (middleware `role:admin`) |
| Controller | `app/Http/Controllers/Api/EvidenceController.php` | `store()` بيخلق `pending` — `index()` بيرجّع `verified` بس — `review()` بيقبل `verified`/`rejected` بس |
| Model | `app/Models/SkillEvidence.php` | `verification_status` في `$fillable`، مفيش ثوابت للحالات |
| Migration | `2026_01_01_001800_create_skill_evidences_table.php` | enum: `['pending', 'verified', 'rejected', 'expired']` — default `pending` |
| Service | `app/Services/Skills/SkillEvaluationService.php` | `verificationFactor()`: `verified=1.0`, `pending=pending_verified_factor`, `default=0.0` |
| Resources | — | **مفيش** Resource للدليل؛ الكنترولر بيرجّع الموديل زي ما هو (كل أعمدة الجدول) |
| Policies | — | **مفيش** `EvidencePolicy`؛ التصريح inline (أدمن أو صاحب الدليل) + middleware `role:admin` |
| Tests | `tests/Feature/Evidence/` | 3 ملفات (Submission, FileUploadSecurity, Review) — **مفيش أي تغطية لحالة `revision`** |

### الحالات اللي كان الـBackend بيدعمها فعليًا (قبل BA-02)

- **في قاعدة البيانات:** `pending`, `verified`, `rejected`, `expired`
- **في validation بتاع المراجعة:** `verified`, `rejected` — بس
- **في `store()`:** `pending`
- **`revision`:** **مش موجودة في أي مكان في الـBackend** (ولا في الـSRS). كانت موجودة في الفرونت بس.

**يعني `expired` كانت موجودة في الـenum بس مستحيل توصلها من الـAPI** — فجوة حقيقية.

---

## ٢. الأدلة من الـSRS (ليه `revision` مطلوبة)

القرار مبني على مصدرين متضاربين شكليًا في الـSRS، والحسم كان لصالح الـMust requirement:

### ✅ دليل مع وجود `revision`

- **SKL-05 (Must):** _"Authorized reviewers shall verify, reject, or **request clarification** for submitted evidence."_
  → **٣ نتائج مراجعة**، مش اتنين. الـBackend كان بينفّذ اتنين بس.
  والـAcceptance بتاعتها: _"State changes include reason, reviewer, timestamp, and notifications."_ — يعني الطلب ده **تغيير حالة** لازم يتسجل.
- **UC-03 خطوة 4:** _"An authorized reviewer verifies, rejects, or requests clarification where required."_ — نفس الـ٣ أفعال.
- الفرونت بيستخدم `revision` أصلًا → التوافق على الاسم بيوفّر breaking change.

### ⚠️ دليل ضد وجود `revision`

- **§13.6 / Table 75 "Core status dictionaries"** بتقول إن قاموس الدليل هو:
  `submitted, pending_review, verified, rejected, expired, superseded, voided` — **مفيش فيها `revision`**.

### 🧭 الحسم

Table 75 هو قاموس "core"، لكنه **أصلًا مش وصف للـBackend الحالي**:
- الـBackend بيستخدم `pending` مش `submitted`/`pending_review`.
- ولا عنده `superseded` ولا `voided`.

يعني Table 75 **مرجع مستهدف**، وهو نفسه ناقص بخصوص فعل "request clarification" اللي SKL-05 (Must)
بيفرضه. الحالة اللي ملهاش اسم في القاموس = **فجوة في الـSRS**، مش سبب لإلغاء الـMust requirement.

**لذلك:** اتحقّق شرط SKL-05 بإضافة `revision`، والاسم اتوافق مع الفرونت (`revision`).
**وملاحظة مهمة:** `revision` ≠ `rejected`. الاتنين مش بيزودوا السكور، لكن `revision` = «لسه فيه أمل، عدّل وابعت» و`rejected` = «مرفوض». دمجهم كان هيلغي الـMust requirement عمليًا.

---

## ٣. الحالات الرسمية النهائية (`verification_status`)

| الحالة | المعنى | تزود الـSkill Level؟ | factor في الـConfidence |
|---|---|---|---|
| `pending` | مستني مراجعة (اللي `store()` بيخلقه) | ❌ | `pending_verified_factor` (0.50) |
| `verified` | متقبول ✅ | ✅ **هي الوحيدة** | `1.0` |
| `rejected` | مرفوض ❌ | ❌ | `0.0` |
| `expired` | بقى غير صالح | ❌ | `0.0` |
| `revision` | **الأدمن طلب توضيح/تعديل** (SKL-05) | ❌ | `0.0` |

- الأسماء الحالية **ماتغيّرتش** (`pending`/`verified`/`rejected` ماتغيروش، و`expired` فضلت).
- **مفيش** حالة تانية (`approved`, `clarification`, `changes_requested`…) → `422`.
- `revision` اتعمل لها **append في آخر الـenum** — مهم جدًا لأن MySQL بيخزّن ENUM بالـindex،
  فأي إدخال في النص كان هيخرّب كل الصفوف الموجودة. الصفوف الحالية ماتغيّرتش خالص.

---

## ٤. الـTransitions المسموحة

```
pending  ──▶  verified     ✅
pending  ──▶  rejected     ✅
pending  ──▶  revision     ✅   ← الأدمن بيطلب توضيح (SKL-05)
revision ──▶  verified     ✅   ← الطالب عدّل والأدمن قبل
revision ──▶  rejected     ✅
revision ──▶  revision     ✅   (طلب توضيح تاني)
────────────────────────────────────────────
verified / rejected / expired  ──▶  أي حاجة   ❌  422 INVALID_EVIDENCE_TRANSITION
```

- المصدر المسموح للمراجعة: **`pending` أو `revision`** بس (`SkillEvidence::REVIEWABLE_STATUSES`).
- القاعدة دي مبنية على **BR-EVA-06**: _"A correction shall supersede or revise ... it shall not silently rewrite history."_
  يعني ممنوع تعيد مراجعة دليل متقبول/مرفوض وتمسح القرار بصمت.
- القيم المسموح ترسلها: **`verified` | `rejected` | `revision`** (`SkillEvidence::REVIEW_OUTCOMES`).

---

## ٥. الملفات اللي اتغيّرت

| # | الملف | التغيير |
|---|---|---|
| 1 | `database/migrations/2026_10_12_000001_add_revision_to_skill_evidences_verification_status.php` | **جديد** — append `revision` لـenum + `down()` آمن (بيحوّل صفوف `revision` لـ`pending` قبل الرجوع) |
| 2 | `app/Models/SkillEvidence.php` | **جديد** — ثوابت `STATUS_*`, `STATUSES`, `REVIEW_OUTCOMES`, `REVIEWABLE_STATUSES` (مصدر واحد للحقيقة) |
| 3 | `app/Http/Controllers/Api/EvidenceController.php` | `review()`: بيقبل `revision`، بيطبّق transition guard، ورسالة رد واضحة لكل حالة |
| 4 | `app/Services/Skills/SkillEvaluationService.php` | `verificationFactor()`: `revision => 0.0` بشكل صريح + توضيح ليه مش زي `pending` |
| 5 | `app/OpenApi/Schemas/SkillSchemas.php` | enum الـEvidence schema: `+revision` و`+expired` |
| 6 | `app/OpenApi/Paths/EvidenceCareerRoleEndpoints.php` | enum الـreview `+revision`، ووصف الـtransitions و`INVALID_EVIDENCE_TRANSITION` |
| 7 | `EVIDENCE_ADD_API.md` | قسم جديد «الحالات الرسمية والـtransitions» + حلّ تعارضات merge قديمة كانت سايبة في الملف |
| 8 | `tests/Feature/Evidence/EvidenceRevisionStateTest.php` | **جديد** — 16 test |

> **ماتغيّرتش:** الـroutes، الـ`store()`، الـ`index()` (لسه `verified` بس)، الـDB schema بتاع الأعمدة
> (بس قيمة enum واحدة اتضافت)، ولا أي اسم حالة قديمة.

---

## ٦. الـTests

- **جديد:** `tests/Feature/Evidence/EvidenceRevisionStateTest.php` — 16 test / 22 assertion:
  - `revision` بتتقبل وبتتخزّن فعلًا (`assertDatabaseHas`)
  - بترجع في الـreview response وفي `GET /evidence/{id}` بعد **reload**
  - الرسالة سليمة نحويًا ("Revision requested for the evidence successfully.")
  - `revision → verified` و`revision → rejected` و`revision → revision` مسموحين
  - `verified`/`rejected`/`expired` مايتفعش يتراجعوا → `422 INVALID_EVIDENCE_TRANSITION`
  - `approved` و`pending` مرفوضين في الـvalidation
  - `revision` **مابتزودش** الـlevel ولا الـconfidence (بتتحقق من الـsnapshot: `verification_factor = 0.0`)
  - `verified` لسه بتزوّدهم (no regression)
  - الطالب مش قادر يطلب revision → `403`
- **موجود:** 25 test في `EvidenceSubmissionTest` + `EvidenceReviewTest` + `EvidenceFileUploadSecurityTest` — كلهم عدّوا.

**نتيجة التشغيل:**
```
php artisan test tests/Feature/Evidence
Tests:    41 passed (107 assertions)
Duration: 9.28s
```

---

## ٧. أمثلة حقيقية (اتجابت من سيرفر Laravel شغّال فعليًا، مش مكتوبة بإيد)

### (أ) طلب توضيح — `PUT /api/v1/evidence/1/review`

**Request**
```http
PUT /api/v1/evidence/1/review
Authorization: Bearer 1|LR2AmD7PyiPVVqEmTzlorCOESQch28bqXbR6Oe4ac9676168
Accept: application/json
Content-Type: application/json

{
  "verification_status": "revision",
  "reviewer_notes": "Please upload the certificate as a PDF."
}
```

**Response `200`**
```json
{
  "success": true,
  "message": "Revision requested for the evidence successfully.",
  "data": {
    "id": 1,
    "student_profile_id": 1,
    "skill_id": 1,
    "source": "certificate",
    "reference": "https://example.com/cert.pdf",
    "evidence_date": "2026-10-12T00:00:00.000000Z",
    "verification_status": "revision",
    "reviewer_id": 2,
    "reviewer_notes": "Please upload the certificate as a PDF.",
    "recency_factor": 1,
    "created_at": "2026-10-12T18:10:21.000000Z",
    "updated_at": "2026-10-12T18:11:17.000000Z"
  }
}
```

### (ب) بعد الـreload — `GET /api/v1/evidence/1` (الحالة استمرت)

**Response `200`** (مختصر — الحقول المهمة)
```json
{
  "success": true,
  "message": "Evidence retrieved successfully.",
  "data": {
    "id": 1,
    "verification_status": "revision",
    "reviewer_id": 2,
    "reviewer_notes": "Please upload the certificate as a PDF.",
    "reviewer": { "id": 2, "name": "Admin" }
  }
}
```

### (ج) محاولة إعادة مراجعة دليل نهائي

**Request** `PUT /api/v1/evidence/1/review` → `{"verification_status":"rejected"}` (والدليل حالته `verified`)

**Response `422`**
```json
{
  "success": false,
  "code": "INVALID_EVIDENCE_TRANSITION",
  "message": "Evidence in status \"verified\" cannot be reviewed again. Only pending or revision evidence can be reviewed."
}
```

### (د) قيمة حالة غير معروفة

**Request** `PUT /api/v1/evidence/1/review` → `{"verification_status":"approved"}`

**Response `422`**
```json
{
  "message": "The selected verification status is invalid.",
  "errors": { "verification_status": ["The selected verification status is invalid."] }
}
```

---

## ٨. حاجات خارج نطاق BA-02 (توصيات)

1. **`GET /api/v1/evidence` لسه بيرجّع `verified` بس.** يعني الطالب مش هيشوف الـ`pending`/`revision`/`rejected`
   في القايمة، بس في `GET /evidence/{id}`. **ده أهم gap متبقّي للـflow** — لو الفرونت محتاج يعرض «مطلوب منك تعديل»،
   ده محتاج فلتر/endpoint تاني (قرار منفصل). مش اتغيّر هنا عشان ما نكسرش العقد الحالي.
2. **`expired` لسه مستحيل توصلها من الـAPI.** موجودة في الـenum والـSRS، بس مفيش مسار بيحطها
   (مفروض تكون آلية/time-based). اتسابت زي ما هي.
3. **مفيش `superseded`/`voided`** (موجودين في SRS Table 75). إضافتهم محتاجة قرار + schema change، فماتعملتش هنا.
4. **إعادة الإرسال بعد `revision`** بتنشئ سجل دليل **جديد** (`store()`)، والسجل القديم بيفضل `revision`.
   مفيش ربط «supersede» بينهم لحد دلوقتي.
