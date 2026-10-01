# فلتر المشاريع حسب التخصص — Projects Role Filter

**الحالة:** منفَّذ ومجرَّب (`8` تِستات جديدة، كلها خضراء).
**الإندبوينت:** `GET /api/v1/projects`
**المعامل الجديد:** `role` (اختياري)

---

## 🇸🇾 بالعربي

### شو الجديد

صار فيك تفلتر كتالوج المشاريع **حسب التخصص**:

```
GET /api/v1/projects?role=Frontend Developer
```

المعامل **اختياري** — إذا ما بعتّو، بترجع كل المشاريع المتاحة متل قبل بالزبط. يعني ما في شي انكسر عند أي كلاينت قديم.

### كيف بيشتغل بالزبط

المشروع بيعلن عن دوره بمكانين، والفلتر بيطابق **أيّ واحد منهم**:

| المكان | شو يعني | مثال |
|---|---|---|
| `projects.role` | الدور الرئيسي (نص حر) | `Frontend Developer` |
| `project_roles` | الأدوار يلي الـ learner فيو يقدّم عليها، بترجع بـ `available_project_roles` | `Dashboard Engineer` |

⚠️ **الأدوار المعطّلة (`is_active = false`) ما بتتطابق** — لأنها مش أدوار فيك تقدّم عليها فعلاً. مضبوطة إنها ما تظهر.

### أمثلة

```bash
# كل مشاريع الفرونت
GET /api/v1/projects?role=Frontend Developer

# الفلتر مع باقي الفلاتر (بيشتغلوا مع بعض)
GET /api/v1/projects?role=Data Analyst&work_mode=remote

# مع ترقيم الصفحات
GET /api/v1/projects?role=Backend Developer&per_page=15&page=1
```

### سلوكيات مهمة

- **دور غير موجود** (مثلاً `role=Astronaut`) → **`200` مع صفحة فاضية**، مش `422`. وهو نفس سلوك `domain`. عشان هيك ما بتحتاجوا تعالجوا حالة خاصة.
- **قيمة أطول من 100 حرف** → `422 VALIDATION_ERROR`.
- **الفلتر ما بيكسر قواعد الصلاحيات.** مشروع `draft` أو `restricted` أو منتهي بيضل مخفي حتى لو دوره طابق — الفلتر بيضيّق النتائج، ما بيوسّعها.
- **النص حر مش قائمة** — لأن `role` و`project_roles.title` نصوص حرة، فقائمة مقفلة بترفض أدوار موجودة فعلاً.

### الرد

نفس الغلاف المعتاد بدون أي تغيير:

```json
{
  "success": true,
  "message": "Accessible projects retrieved successfully.",
  "data": [
    { "id": 2, "title": "…", "role": "Frontend Developer", "available_project_roles": [ … ] }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 50, "total": 1 }
}
```

### فكرة للواجهة

الفلاتر يلي عندكن حالياً (نوع / مجال / طريقة العمل / الصعوبة) — ضيفوا جنبها **قائمة تخصصات**. القيم يلي فعلاً في الداتا الآن: `Frontend Developer`, `Backend Developer`, `Data Analyst`, `UX/UI Designer`.

---

## 🇬🇧 English

### What changed

`GET /api/v1/projects` accepts a new **optional** `role` filter:

```
GET /api/v1/projects?role=Frontend Developer
```

Omitting it returns the full accessible catalog exactly as before — no existing client breaks.

### How matching works

A project advertises a role in two places, and the filter matches **either**:

| Source | Meaning | Example |
|---|---|---|
| `projects.role` | the single headline role (free text) | `Frontend Developer` |
| `project_roles` | the roles a learner may apply as, exposed as `available_project_roles` | `Dashboard Engineer` |

⚠️ **Deactivated roles (`is_active = false`) never match** — a learner cannot apply to them.

### Behaviour

- **Unknown role** → **`200` with an empty page**, not `422` (same as `domain`).
- **Value longer than 100 chars** → `422 VALIDATION_ERROR`.
- **The filter never widens visibility.** A `draft`, `restricted` or expired project stays hidden even if its role matches; the filter narrows, it does not bypass.
- **Free text, not a whitelist** — `projects.role` and `project_roles.title` are free-text columns, so an `in:` list would reject legitimate titles.

### Response

The envelope is unchanged (`{success, message, data, meta}`).

### UI suggestion

Add a specialty dropdown beside the existing filters. Values currently present in the data:
`Frontend Developer`, `Backend Developer`, `Data Analyst`, `UX/UI Designer`.
