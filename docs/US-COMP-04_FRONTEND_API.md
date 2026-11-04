# US-COMP-04 — روابط إدارة ملف الشركة

الروابط الجديدة اللي انضافت في قصة **Organization Profile Management**. كلها تحتاج ترويسة
`Authorization: Bearer <token>`، وحساب دوره `company_admin`، ومؤسسة حالتها `verified`.

عنوان الخدمة هو نفسه اللي بتستخدموه حالياً — ما في عنوان جديد.

---

## الروابط

| الطريقة | الرابط | الوظيفة |
|---|---|---|
| GET | `/api/v1/profile/me` | قراءة ملف الشركة كامل |
| PATCH | `/api/v1/profile/me` | تعديل حقول الملف (جزئي) |
| POST | `/api/v1/company/profile/documents` | رفع مستند أو استبدال مستند موجود |
| GET | `/api/v1/company/profile/documents/{id}` | تنزيل المستند |

والرابط القديم `/api/v1/organization/profile` لسا شغال، وبيعطي **نفس** الـ payload بالحرف —
ما في داعي تغيّروه، بس الجديد هو اللي عليه التعديل.

---

## 1) قراءة الملف

`GET /api/v1/profile/me`

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

**الحقول اللي تحتاج شرح:**

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

---

## 2) تعديل الملف

`PATCH /api/v1/profile/me` — تعديل جزئي، ما في داعي تبعتوا كل الحقول.

```json
{
  "name": "Acme Holdings",
  "city": "Aleppo",
  "website": "acme.com"
}
```

`website` بيقبل بدون `https://` والخدمة بتضيفه، والمخزّن دايماً بيكون فيه scheme. الرد:

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

**الحقول القابلة للتعديل:**

`name` · `industry` · `company_size` · `website` · `description` · `country` · `city` ·
`address` · `postal_code` · `contact_email` · `contact_phone`

**الحقول للقراءة فقط:** `type` · `verification_status` · `verified_at`.

لو بعتوا نفس القيمة الحالية بتمرق عادي (الفورم المعبّى من الـGET بيعمل هيك كل مرة). لو بعتوا
**قيمة مختلفة** بيرجع 422 بالكود `FIELD_NOT_EDITABLE`.

قاعدة إضافية: ما بتقدروا تفرّغوا الإيميل **و** الهاتف مع بعض — لازم يضل واحد منهن، وإلا
`PROFILE_CONTACT_REQUIRED`.

---

## 3) رفع مستند

`POST /api/v1/company/profile/documents` — `multipart/form-data`

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

---

## 4) تنزيل مستند

`GET /api/v1/company/profile/documents/{id}`

بيرجع الملف نفسه (binary) مع `Content-Disposition: attachment`. المستند اللي مش تابع لمؤسستكم
بيرجع **404** مش 403 — يعني ما في طريقة تعرفوا إذا الـid موجود عند غيركم.

---

## أكواد الأخطاء

| الحالة | الكود | متى |
|---|---|---|
| 401 | `UNAUTHENTICATED` | توكن ناقص أو منتهي |
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
| 422 | `DOCUMENT_UPLOAD_FAILED` | الرفع انقطع قبل ما يكمل (شبكة أو حد PHP) — أعدوا المحاولة |
| 422 | `DOCUMENT_REJECTED_BY_SCAN` | الملف فيه محتوى تنفيذي |
| 500 | `DOCUMENT_STORAGE_FAILED` | الخدمة ما قدرت تحفظ الملف — خطأ من عندنا، أعدوا المحاولة |

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

## نقاط لازم تتأكدوا منها في الواجهة

1. `verification_status` قيمها بس: `pending` · `verified` · `rejected`. **ما في `approved`**.
2. ما في حالة اسمها `reverification_pending`. المراجعة الجديدة بتنقرأ من كائن `reverification`
   المنفصل، والشارة بتضل Verified مع بوليسي `flag` (الافتراضي).
3. أي رفض لازم يتفرّع على `code` مش على نص الرسالة.
4. الـETag / الطلبات الشرطية ما انعملت بهالقصة. إذا بدكم تتفادوا إعادة الرسم، قارنوا `updated_at`.
