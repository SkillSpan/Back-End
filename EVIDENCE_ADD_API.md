# Add Evidence — `POST /api/v1/evidence`

> موجّه للفرونت. مكتوب بنفس أسلوب `API_ENDPOINTS_REFERENCE.md` §11.
> **الـ endpoint ده شغّال على الإنتاج ومتأكد منه.**

---

## الملخص

| البند | القيمة |
|---|---|
| Method | `POST` |
| URL | `https://back-end-zdip.onrender.com/api/v1/evidence` |
| Auth | `Authorization: Bearer <token>` (Sanctum) |
| الدور المطلوب | `learner` + الحساب `active` |
| شرط إضافي | المستخدم لازم يكون عنده **StudentProfile**، وإلا `422` |
| Content-Type | `application/json` لو بتبعت `evidence_url` — أو `multipart/form-data` لو بترفع `evidence_file` |

---

## الـ Body

| الحقل | إلزامي | النوع | ملاحظات |
|---|---|---|---|
| `skill_id` | ✅ | integer | لازم يكون موجود في جدول `skills` و `status = active`. هاته من `GET /api/v1/skills/taxonomy` |
| `evidence_url` | ⚠️ | string (url) | **واحد من الاتنين إلزامي**: `evidence_url` أو `evidence_file` |
| `evidence_file` | ⚠️ | file | أقصى حجم **10MB** (10,000 كيلوبايت). مفيش قيد على نوع الملف |
| `description` | ❌ (اختياري) | string (max 500) | ✅ بيتخزّن وبيترجّع — اتصحّح |
| `evidence_date` | ❌ | date | افتراضيًا تاريخ النهاردة |

لو بعتّ الاتنين (`evidence_url` و `evidence_file`) → الـ `evidence_url` هو اللي بيكسب، والملف بيتجاهل.

---

## الرد عند النجاح — `201`

```json
{
  "success": true,
  "message": "Evidence submitted successfully, pending review.",
  "data": {
    "id": 1,
    "student_profile_id": 3,
    "skill_id": 12,
    "source": "certificate",
    "value": "0.00",
    "normalized_value": "0.00",
    "reference": "https://example.com/cert.pdf",
    "evidence_date": "2026-09-01T00:00:00.000000Z",
    "verification_status": "pending",
    "reviewer_id": null,
    "reviewer_notes": null,
    "recency_factor": "1.00",
    "source_record_type": null,
    "source_record_id": null,
    "created_at": "2026-09-30T09:15:00.000000Z",
    "updated_at": "2026-09-30T09:15:00.000000Z"
  }
}
```

الرد **مش** ملفوف في Resource، فبيرجّع كل أعمدة الجدول زي ما هي.

---

## الأخطاء

| Status | الشكل | السبب |
|---|---|---|
| `401` | `{"message":"Unauthenticated."}` | مفيش توكن أو توكن غلط — **لاحظ: مفيش `success` هنا** |
| `403` | `{"success":false,"message":"..."}` | المستخدم مش بدور `learner` |
| `422` | `{"success":false,"message":"You must complete your student profile before submitting evidence."}` | مفيش StudentProfile |
| `422` | `{"message":"The skill id field is required.","errors":{...}}` | فاليديشن (شكل Laravel الافتراضي) |
| `409` | `{"success":false,"message":"Duplicate evidence already exists for this skill."}` | نفس الطالب + نفس المهارة + نفس المصدر + نفس المرجع |

---

## ⚠️ ملاحظات مهمة — لازم الفرونت ياخد باله

1. **`description` — اتصحّح ✅ (كان بيتقبل ومش بيتخزّن).**
   كان الحقل موجود في `validate()` بس مش موجود في `SkillEvidence::create()` ولا في أعمدة الجدول،
   يعني أي وصف كان بيروح في الهوا بدون أي إيرور. **اتصلّح:** ضفنا عمود `description` (nullable،
   الحد 500) وربطناه في الموديل والكونترولّر. دلوقتي الوصف بيتخزّن فعليًا وبيترجّع في
   `POST` و`GET /api/v1/evidence/{id}` و`GET /api/v1/evidence`.
   ⚠️ لو السيرفر لسا على نسخة قديمة، لازم deploy الأول.

2. **القيم العشرية بترجع كـ strings على الإنتاج، مش numbers.**
   `"value": "0.00"` و `"normalized_value": "0.00"` و `"recency_factor": "1.00"`.
   السبب إن PDO بيرجّع أعمدة `DECIMAL` من MySQL كـ string، والـ model مفيش فيه `$casts` للحقول دي.
   **اتأكدنا منها عمليًا على قاعدة الإنتاج.** لو الفرونت بيعمل `Number(x)` أو مقارنات، لازم يحوّل الأول.

3. **`GET /api/v1/evidence` بيرجّع الأدلة المعتمدة بس (`verification_status = verified`).**
   يعني بعد ما الطالب يرفع دليل بنجاح، **مش هيظهر في القايمة** لحد ما أدمن يعتمده من
   `PUT /api/v1/evidence/{id}/review`. لو محتاجين نعرض الـ pending/rejected كمان، ده endpoint تاني
   ومحتاجين نضيفه.

4. **`reference` للملفات المرفوعة مش رابط عام.**
   لو رفع `evidence_file`، القيمة اللي بترجع في `reference` بتبقى مسار داخلي على السيرفر
   (زي `evidence/AbC123.jpg`) — **مش متاح من المتصفح**، ومفيش endpoint لتحميله حاليًا.
   فمينفعش الفرونت يعرض الملف بعد الرفع. لو محتاجين ده، قولوا.

5. **مفيش endpoint يجيب كل الأدلة للطالب** (pending + verified + rejected). `GET /api/v1/evidence`
   مفلتر على `verified` بس.

---

## أمثلة

### برابط (JSON)

```bash
curl -X POST https://back-end-zdip.onrender.com/api/v1/evidence \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
        "skill_id": 12,
        "evidence_url": "https://example.com/cert.pdf",
        "description": "خلصت كورس SQL المتقدم بامتياز.",
        "evidence_date": "2026-09-01"
      }'
```

### بملف (multipart)

```bash
curl -X POST https://back-end-zdip.onrender.com/api/v1/evidence \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -F "skill_id=12" \
  -F "description=مشروع تحليل بيانات سلمته واتقيّم." \
  -F "evidence_file=@cert.pdf"
```

> مع `multipart/form-data` **ما تحددش `Content-Type` يدويًا** — curl (أو الـ FormData في المتصفح)
> بيحدد الـ boundary بنفسه.

### إزاي تجيب `skill_id` صالح

```bash
curl -s https://back-end-zdip.onrender.com/api/v1/skills/taxonomy \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
# → { "success": true, "data": [ { "id": 12, "name": "...", ... } ] }
```

بيرجّع المهارات اللي `status = active` بس — ودي بالظبط اللي `skill_id` بيقبلها.

---

## Endpoints الأدلة الأربعة (للسياق)

| Method | Endpoint | الصلاحية |
|---|---|---|
| `POST` | `/api/v1/evidence` | `learner` — **رفع دليل (الـ endpoint المطلوب)** |
| `GET` | `/api/v1/evidence` | `learner` — المعتمدة بس |
| `GET` | `/api/v1/evidence/{id}` | أي مستخدم نشِط — المالك أو أدمن |
| `PUT` | `/api/v1/evidence/{id}/review` | `admin` — اعتماد/رفض |
