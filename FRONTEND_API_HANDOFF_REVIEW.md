# ؤمراجعة دليل الـ API المُرسَل للفرونت إند

**الملف المراجَع:** `SkillSpan_API_Endpoints_Complete_Updated.pdf` (6 صفحات، 82 مسار)  
**تاريخ المراجعة:** 2026-09-29  
**طريقة المراجعة:** استخراج النص من الـ PDF، ثم مقارنته بـ `php artisan route:list --json`  
(84 مسار)، ثم **تحقّق فعلي** على `https://back-end-zdip.onrender.com`.

---

## الحكم النهائي

المستند **جيد كفهرس مسارات** — التغطية كاملة فعلًا (كل مسار برمجي موجود فيه)، والوصف العام  
لمرحلة كل مسار صحيح. **لكنه ما بيكفي للتسليم**، لسببين:

1. **فيه 8 صفوف صلاحيات غلط** + وصفان مضلِّلان — وهاد أخطر شي، لأن الفرونت رح يبني الـ routing  
   والـ guards على أساسها.
2. **ناقصه كامل قسم "شكل الرد"** — وهو أهم شي للفرونت. أكثر من نمط رد متعايش في نفس الـ API،  
   ولو ما انكتب صراحةً رح يكتشفه الفرونت بالغلط بعد أسبوع شغل.

---

## 1. التغطية — مكمّلة، مع 3 مسارات بنية تحتية ناقصة

الـ PDF ذكر **82 مسارًا**، وهو رقم صحيح للنسخة الأحدث. نسختك المحلية فيها **81**، والفرق مسار واحد  
(`GET /api/v1/projects/{project}/recommendation` — انظر البند 3).

المسارات الثلاثة الناقصة من المستند كلها **بنية تحتية، مش شغل الفرونت**، لكن الأفضل ذكرها:

| Endpoint                   | الوظيفة                   | للفرونت؟                        |
| -------------------------- | ------------------------- | ------------------------------- |
| `GET /up`                  | فحص صحة (health check)    | للمراقبة فقط                    |
| `GET /api/documentation`   | صفحة Swagger UI           | انظر البند 6 — **فارغة حاليًا** |
| `GET /api/oauth2-callback` | رد نداء OAuth تبع Swagger | لا                              |

كذلك في **3 صفوف مكرّرة** (مرة في الجدول ومرة في "الدليل العملي"): `GET /api/v1/internal/baseline-items`،  
`POST /api/v1/baseline-assessments`، `POST /api/v1/intelligence/calculate`.

وفي **تعارض في أسماء المتغيرات**: الجداول تستخدم `{assessment}` بينما الدليل العملي في آخر المستند  
يستخدم `{id}` لنفس المسارات. الفرونت لازم يستعمل `{assessment}`.

---

## 2. ⚠️ 8 صفوف صلاحيات غلط

هاد أخطر شي في المستند. العمود "الصلاحية/الحماية" مكتوب نظريًا وليس من الـ middleware الفعلي:

| Endpoint                                                      | الـ PDF يقول       | **الحقيقة في الكود**                                              |
| ------------------------------------------------------------- | ------------------ | ----------------------------------------------------------------- |
| `GET /api/v1/projects/{project}/applications`                 | متعلم + Token      | **بدون دور** — `Token + حساب نشط` فقط                             |
| `PATCH /api/v1/projects/{project}/applications/{application}` | متعلم + Token      | **بدون دور** — مقصود، والملكية تُفحص في الـ Service               |
| `POST /api/v1/evidence`                                       | متعلم/مدير + Token | **متعلم فقط**                                                     |
| `GET /api/v1/evidence`                                        | متعلم/مدير + Token | **متعلم فقط**                                                     |
| `GET /api/v1/evidence/{id}`                                   | متعلم/مدير + Token | **أي مستخدم مصادَق** (بدون دور)                                   |
| `PUT /api/v1/evidence/{id}/review`                            | متعلم/مدير + Token | **أدمن فقط**                                                      |
| `POST /api/v1/setup/create-admin`                             | عام (محدود المعدل) | عام لكن **محمي بسر** `ADMIN_SETUP_SECRET` → بدون السر يرجّع `403` |
| `POST /api/v1/setup/create-mentor`                            | عام (محدود المعدل) | نفس الشي مع `MENTOR_SETUP_SECRET`                                 |

**الأدلة:**

- مسارا `projects/{project}/applications` موجودان في مجموعة middleware منفصلة في `routes/api.php`  
  (السطر 109)، وفيها تعليق صريح: *"Deliberately NOT role-gated: a project owner is any  
  authenticated user, so authorization is explicit project ownership inside ApplicationService."*
- `GET /api/v1/evidence/{id}` بدون `role:` — راجع `routes/api.php`.
- السر: متحقَّق منه فعليًا على الإنتاج — سر غلط يرجّع `403 {"success":false,"message":"Invalid setup secret."}`.

**كذلك وصفان مضلِّلان:**

| Endpoint                                                    | الـ PDF يقول                  | الصواب                                                                                                       |
| ----------------------------------------------------------- | ----------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `GET /api/v1/projects/{project}/applications`               | "إنشاء طلب تقديم على المشروع" | **عرض** طلبات التقديم على المشروع (من جهة صاحب المشروع) — مش إنشاء. الخلط مع `POST` على نفس المسار خطير جدًا |
| `GET /api/v1/admin/organizations/{organization}/proof-file` | "عرض/تنزيل ملف الإثبات"       | صحيح، لكن ناقص إنه **يرجّع ملف binary** وليس JSON → الفرونت يحتاج `responseType: 'blob'`                     |

---

## 3. مسار في المستند **غير موجود في نسختك المحلية**

```
GET /api/v1/projects/{project}/recommendation
```

- في المستند: موجود.
- في نسختك (`910821e`): **غير موجود**.
- على الإنتاج: **شغّال** — طلب بدون توكن يرجّع `401 Unauthenticated`، وليس `404`.

يعني **الإنتاج متقدّم عن نسختك**. وهاد بيفسّر رقم "82" في المستند.

**الخيارات:** إما تعمل `pull` من `origin/feature/authentication` (الأفضل — بيجيب كمان ترقيم  
`/projects` وتعديلات الحماية)، أو تشيل هذا المسار من المستند قبل ما تبعته.

⚠️ بعد الـ pull، **`GET /api/v1/projects` بتصير مرقّمة** (`page`/`per_page`، والرد يصير فيه `meta`)،  
و`GET /api/v1/recommendations` بتبلّش تحجب (`access_revoked`) النتائج تبع مشاريع صار المتعلم ما  
يقدر يشوفها. **هاد تغيير كاسر للفرونت** — لازم ينكتب في المستند.

---

## 4. ⚠️ الثغرة الأكبر: المستند ما يوصف شكل الرد إطلاقًا

المستند يوصف: method + path + سطر وصف + صلاحية. وبس.  
الفرونت محتاج كذلك — وكل هاد **ناقص**:

- حقول الـ request body، أيها إلزامي، أنواعها، الـ enums
- شكل الـ response body
- **شكل الأخطاء** — وهاد خطير لأن الـ API فيه **أكثر من نمط**
- عقد الترقيم (`meta`, `per_page`)
- صيغة هيدر المصادقة

### النمط السائد (19 من 23 controller)

```json
{ "success": true, "message": "…", "data": { }, "request_id": "…" }
```

### لكن 4 مسارات ترجع شكلًا مختلفًا تمامًا

`/api/v1/assistant/*` ، `/api/v1/intelligence/*` ، `/api/v1/readiness/*` ، `POST /api/v1/skill-match`  
ترجع JsonResource مباشرة: `{ "data": { } }` — **بلا `success` ولا `message` ولا `request_id`**.

### وحالة ثالثة

`GET /api/v1/career-roles` ترجع `success` و`data`، لكن `data` هو **الـ paginator نفسه**  
(`data.data` + `data.current_page`) وبلا `meta`.

### وأخطاء الـ middleware شكلها رابع

`{"message":"Unauthenticated."}` — بلا `success`.

**متحقَّق منها live على الإنتاج:**

- `POST /api/v1/auth/login` بجسم فاضي → `422 {"message":"…","errors":{"email":["…"]}}` — **بلا `success`**
- `POST /api/v1/setup/create-mentor` بجسم فاضي → `422 {"success":false,"message":"…","errors":{…}}` — **فيه `success`**

نفس الكود، نفس الحالة، شكلان مختلفان. **الفرونت ما يقدر يكتب معالج أخطاء واحد.**

---

## 5. أمور اكتشفتها على الإنتاج أثناء التحقق

### أ) `APP_DEBUG` شغّال على الإنتاج — تسريب معلومات

```
GET /api/v1/nonexistent-xyz
→ 404 {
    "message": "The route api/v1/nonexistent-xyz could not be found.",
    "exception": "Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException",
    "file": "/var/www/html/vendor/laravel/framework/src/Illuminate/Routing/AbstractRouteCollection.php",
    "line": 44,
    "trace": [ … ]
  }
```

أي طلب لمسار غلط يكشف **مسار الملفات الكامل، أسماء الكلاسات، وأثر التنفيذ (stack trace)**.  
هاد خطر أمني، وكذلك مشكلة للفرونت: شكل الخطأ يختلف بين بيئة التطوير والإنتاج.  
**الحل:** `APP_DEBUG=false` في متغيرات خدمة `back-end-zdip` على Render.

### ب) Swagger موجود بس فارغ — لا تعتمد عليه

- `GET /api/documentation` → `200`، الصفحة تفتح، وتوجّه على  
  `https://back-end-zdip.onrender.com/docs?api-docs.json`
- `GET /docs?api-docs.json` → **`404`** → الصفحة تفتح **بدون أي تعريف**
- عدد التعليقات `@OA\` في المشروع = **`0`**
- مجلد `storage/api-docs/` غير موجود

فالجملة في المستند *"يجب الرجوع إلى Swagger"* **طريق مسدود**. لازم إما:  
(1) تُكتب تعليقات `@OA` وتُولَّد الـ spec، أو (2) يُرسَل للفرونت **مجموعة Postman** بدلًا منه.  
الخيار الثاني جاهز عندك: `SkillSpan_API_Collection.postman_collection.json` + `API_ENDPOINTS_REFERENCE.md`.

### ج) خطأ مطبعي في عنوان الإنتاج

- الصفحة 1: `https://back-end-zdp.onrender.com` ← **غلط** (ناقص حرف `i`)، ما بيفتح شي
- الصفحة 5: `https://back-end-zdip.onrender.com` ← صح

لو الفرونت نسخ العنوان من الصفحة 1، كل الطلبات رح ترجع 404 ويضيّعوا يوم كامل يبحثون عن السبب.

---

## 6. قائمة العمل — مرتّبة بالأولوية

| # | الإجراء                                                                           | لمن             |
| - | --------------------------------------------------------------------------------- | --------------- |
| 1 | اسحب من `origin/feature/authentication` لتجيب `projects/{project}/recommendation` | الباك إند (انت) |
| 2 | صحّح 8 صفوف الصلاحيات + وصفَي المسارين                                            | الباك إند       |
| 3 | أضف قسم **"شكل الرد"** (النمط A + B + career-roles + أخطاء middleware)            | الباك إند       |
| 4 | أضف قسم **"الترقيم"** + تحذير أن `/projects` بتصير مرقّمة بعد الـ pull            | الباك إند       |
| 5 | أضف قسم **"أشكال الأخطاء"** (401/403/404/422/429)                                 | الباك إند       |
| 6 | صحّح الـ Base URL في الصفحة 1                                                     | الباك إند       |
| 7 | أضف ملاحظات: `proof-file` blob، `setup/*` سرية، Data Science خدمة منفصلة          | الباك إند       |
| 8 | أرسل مجموعة Postman مع المستند بدل الاعتماد على Swagger                           | الباك إند       |
| 9 | `APP_DEBUG=false` على خدمة `back-end-zdip`                                        | DevOps          |

---

## 7. المستند الجاهز للإرسال

بدل ما تعدّل الـ PDF يدويًا، جهّزت لك نسخة مصحّحة وكاملة:

**`FRONTEND_API_HANDOFF.md`** — يحتوي على:

- عنوان الإنتاج الصحيح + تنبيه على الخطأ المطبعي
- قسم المصادقة وحالات الحساب وبوابة المؤسسة
- **أنماط الرد الثلاثة** بأمثلة JSON حقيقية
- **جدول أشكال الأخطاء** متحقَّق منه live
- **عقد الترقيم** لكل مسار
- **جدول 84 مسارًا** بالصلاحية الفعلية من الـ middleware (مولَّد من الكود، مش مكتوب يدويًا)
- 7 ملاحظات تنفيذية للفرونت

هذا المستند **جاهز للإرسال** كما هو. ولو بدك، بمقدّر أحوّله PDF أو HTML بنفس التنسيق العربي.

