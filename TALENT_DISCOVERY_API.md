# Talent Discovery — `GET /api/v1/talent` (BA-01)

> موجّه للفرونت (FE-07). مكتوب بنفس أسلوب `EVIDENCE_ADD_API.md`.
> **الـ endpoints دي شغّالة على الإنتاج ومتأكد منها.**

---

## الملخص

| البند | القيمة |
|---|---|
| Base URL | `https://back-end-zdip.onrender.com` |
| Auth | `Authorization: Bearer <token>` (Sanctum) |
| الدور المطلوب | **`company_admin`** — مش `learner`، مش `mentor` |
| شرط إضافي ١ | الحساب لازم يكون `active` |
| شرط إضافي ٢ | **الأورجانيزيشن لازم تكون معتمدة** (`verified`) — لو `pending` أو `rejected` → `403` |
| الشكل | `{ success, message, data, meta, request_id }` |

### الـ endpoints التلاتة

| Method | Endpoint | الوظيفة |
|---|---|---|
| `GET` | `/api/v1/talent` | القائمة (paginated + filters) — **دي اللي بدّلت `getRecommendations()`** |
| `GET` | `/api/v1/talent/filters` | القيم المتاحة للفلاتر — **نادِها الأول** |
| `GET` | `/api/v1/talent/{student}` | بروفايل طالب واحد (تفاصيل) |

---

## ⚠️ أول حاجة: الفرق عن `getRecommendations()`

الصفحة كانت بتتغذّى من `GET /api/v1/recommendations`. **ده كان غلط**، والـendpoint ده **لسه موجود ومتغيّرش**:

- `/recommendations` بيرجّع **توصيات المشاريع المخزّنة للمستخدم نفسه** (`recommendation` rows where `user_id` = اللي بيسأل و `type='project'`).
- ومحمي بـ`role:learner` — يعني **شركة بتاخد `403 LEARNER_ONLY`**. مستحيل تملأ بيها صفحة شركة.
- الجديد `/api/v1/talent` بيجاوب على السؤال الصح: **«مين الطلاب اللي الشركة دي مسموح لها تشوفهم؟»**

---

## الـ Query Parameters (للقائمة بس)

| البارامتر | النوع | افتراضي | ملاحظات |
|---|---|---|---|
| `search` | string ≤255 | — | بحث جزئي في **الاسم** أو **مسمى الـcareer role** (مش في الـslug) |
| `career_role` | string ≤255 | — | الـ**slug**. **هاته من `/talent/filters`** — مش من `/career-roles` (تحت) |
| `min_readiness` | number 0–100 | — | على **أحدث** readiness score |
| `sort` | `readiness` \| `recent` | `readiness` | أي قيمة تانية → **`422`** (مش بيتجاهلها) |
| `page` | integer ≥1 | `1` | |
| `per_page` | integer 1–100 | `15` | فوق الـ100 → **`422`** |

---

## `GET /api/v1/talent` — القائمة

```json
{
  "success": true,
  "message": "Discoverable talent retrieved successfully.",
  "data": [
    {
      "id": 42,
      "name": "Lena Kim",
      "career_role": { "id": 1, "title": "Backend Engineer", "slug": "backend-engineer" },
      "specialization": "Computer Science",
      "university_name": "Cairo University",
      "career_status": "student",
      "enrollment_status": "enrolled",
      "availability": "full_time",
      "preferred_work_type": null,
      "completeness_percent": 82,
      "visibility": "public",
      "contactable": true,
      "readiness": { "score": 78.5, "band": "near_ready" },
      "top_skills": [
        { "name": "Laravel", "category": "Backend", "level": 3.5,
          "max_level": 5, "verified": true, "evidence_count": 4 }
      ]
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 },
  "request_id": "470ac66f-9fc8-4ea6-b1b9-1daf53bc0802"
}
```

- **`id`** = الـ**user id** بتاع الطالب (مش `student_profile.id`). ده اللي بتبعته لـ`/talent/{student}`.
- **`top_skills`** = **٣ مهارات بالكتير**، الأعلى level الأول.
- **`readiness.score`** ممكن تكون `null` لو الطالب لسه مفيش عنده حساب readiness.

## `GET /api/v1/talent/filters`

```json
{
  "success": true,
  "message": "Talent discovery filters retrieved successfully.",
  "data": {
    "career_roles": [
      { "id": 1, "title": "Backend Engineer", "slug": "backend-engineer" }
    ],
    "readiness_bands": [
      "foundation_needed", "developing", "moderate_readiness",
      "near_ready", "highly_ready"
    ]
  },
  "request_id": "3b0194f7-6812-42a5-afe1-143165d6c8c7"
}
```

## `GET /api/v1/talent/{student}` — التفاصيل

نفس الـ14 حقل اللي فوق، **زائد**:

| الحقل | ملاحظات |
|---|---|
| `bio`, `interests[]`, `academic_level`, `expected_graduation`, `graduation_status` | بيانات البروفايل |
| `skills[]` | لحد **20** مهارة، بنفس شكل `top_skills` |
| `projects[]` | `{ id, title, type, role, state, sponsor_org, joined_at, left_at }` — لحد 20 |
| `evaluation{}` | بالظبط **5 مفاتيح** — تحت |

```json
"evaluation": {
  "authorized": true,
  "overall_rating": 4.5,
  "criteria_scores": [
    { "key": "quality", "score": 4.6 },
    { "key": "communication", "score": 4.2 }
  ],
  "evaluations_count": 3,
  "reviewed_at": "2026-06-01"
}
```

- `authorized: false` معناها «مفيش تقييم نهائي» — وباقي الحقول كلها `null` / `[]`. **اتعامل معاها كـempty state.**
- **`key` هو مفتاح الـenum، مش label جاهز للعرض.** القيم المحتملة:
  `quality, commitment, communication, collaboration, creativity, problem_solving, delivery`.
  **الترجمة والـlabel مسؤوليتك** (زي ما عملتوا مع باقي الـenums).

---

## الأخطاء

| Status | السبب | الشكل |
|---|---|---|
| `401` | مفيش توكن / توكن غلط | `{"message":"Unauthenticated."}` — **مفيش `success` هنا** |
| `403` | مش `company_admin` | `{"success":false,"code":"COMPANY_ADMIN_ONLY", ...}` |
| `403` | الأورجانيزيشن `pending` أو `rejected` | نفس شكل الـ`403` |
| `422` | فاليديشن (`per_page` > 100، `sort` غلط، `min_readiness` بره 0–100) | شكل Laravel الافتراضي `{message, errors}` |
| `404` | `/talent/{student}` — **لأي سبب** (تحت) | `{"success":false,"message":"No discoverable student with that id."}` |

---

## ⚠️ ملاحظات مهمة — لازم الفرونت ياخد باله

### 1. ⚠️⚠️ القائمة **فاضية** لحد ما الطالب يوافق — وده مش باج

`student_profiles.visibility` بيبدأ **`private`** و `consent_given` بيبدأ **`false`**. يعني على أي قاعدة بيانات جديدة، `data` هترجع **`[]`** لحد ما الطلاب يفعّلوا الظهور بنفسهم.

**متعملش "Empty state" بيقول إن مفيش نتائج للفلتر** — لازم تفرّق بين:
- «مفيش طالب مطابق للفلتر» (المستخدم يغيّر الفلتر)
- «لسه مفيش طلاب فعّلوا الظهور» (مفيش حاجة المستخدم يعملها)

### 2. `matchScore` **اتشال** — استخدم `readiness`

الـmock القديم كان فيه `matchScore`. مش موجود ومش هيتضاف. البديل:

```json
"readiness": { "score": 78.5, "band": "near_ready" }
```

`band` من القائمة دي: `foundation_needed, developing, moderate_readiness, near_ready, highly_ready` — وهاتها من `/talent/filters` بدل ما تكتبها بإيدك.

### 3. `career_role` — القيم من `/talent/filters` **مش** من `/career-roles`

`GET /api/v1/career-roles` كل الـ3 routes بتاعته `role:learner`، فـ**شركة بتاخد `403`**. عشان كده `/talent/filters` موجود: بيرجّع بس الأدوار اللي **فعلاً بترجّع نتائج** للشركة دي.

⚠️ **متستخدمش جدول الأدوار الكامل كـdropdown** — هيبقى فيه اختيارات بترجّع صفر نتايج دايمًا.

### 4. الـ`404` مش `403` في `/talent/{student}` — **وده مقصود**

`404` واحدة بتغطي: id مش موجود، بروفايل `private`، طالب موافقش، أو طالب في أورجانيزيشن تانية.

**السبب:** لو رجّعنا `403` كنا بنأكّد إن الـid **موجود**، والراوت يبقى أداة تخمين عضوية.

**متعرضش رسالة زي «مش مسموح لك»** — اعرض «مش موجود». غير كده الـUI بيسرّب معلومة.

### 5. مفيش إيميل في الرد — **بقصد**

مش هتلاقي `email` في القائمة ولا في التفاصيل. الشركة المفروض توصل للطالب عن طريق **طلب تواصل مسجّل** (مهمة لاحقة)، مش بحصاد الإيميلات من endpoint قايمة.

`contactable: true` موجودة عشان تشغّل زرار التواصل من الرد نفسه بدل ما تفترضه.

### 6. ⚠️ الصفحة **متركّبة في المكان الغلط**

`TalentDiscoveryView.jsx` حالياً **مركّبة جوه `LearnerWorkspace`** — يعني بتترندر في **سيشن learner**، وده هياخد `403` من الـAPI ده فورًا.

**لازم تتنقل لسيشن شركة (`company_admin`) قبل أي wiring.** ده شرط مسبق، مش تحسين.

### 7. ⚠️ تصحيح للـbrief: `TalentDiscoveryView.jsx` مش بينده `getRecommendations()`

الصفحة دي **mock data 100%** ومفيهاش أي نداء API خالص. نداء `getRecommendations()` موجود في **`LearnerWorkspace.jsx:39`** — لصفحة تانية خالص (داشبورد الطالب).

يعني الشغل مش «بدّل الدالة» — الشغل **ربط الصفحة من الصفر** + نقلها لسيشن الشركة.

### 8. الأرقام العشرية

`readiness.score` و `level` و `score` بيرجعوا **numbers** (مش strings زي بعض الـendpoints التانية)، لأنهم محسوبين في PHP مش مقروئين كـ`DECIMAL` من MySQL. لسه، لو بتقارن، اتأكد من النوع.

---

## أمثلة

```bash
TOKEN=<company_admin_token>

# ١) القيم المتاحة — نادِها الأول وابني منها الـdropdowns
curl -s "https://back-end-zdip.onrender.com/api/v1/talent/filters" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"

# ٢) القائمة
curl -s "https://back-end-zdip.onrender.com/api/v1/talent?per_page=12&sort=readiness" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"

# ٣) مع فلاتر
curl -s "https://back-end-zdip.onrender.com/api/v1/talent?search=lena&career_role=backend-engineer&min_readiness=70&page=1&per_page=12" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"

# ٤) التفاصيل
curl -s "https://back-end-zdip.onrender.com/api/v1/talent/42" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

> ⚠️ `Accept: application/json` **إلزامي**. من غيره أي `auth` failure بترجع **`302`** (redirect لصفحة اللوجين) بدل **`401`** — وهتضيّع وقت في التشخيص.

---

## للسياق — الصلاحيات

| Endpoint | الصلاحية |
|---|---|
| `GET /api/v1/talent` | `company_admin` + أورجانيزيشن معتمدة |
| `GET /api/v1/talent/filters` | `company_admin` + أورجانيزيشن معتمدة |
| `GET /api/v1/talent/{student}` | `company_admin` + أورجانيزيشن معتمدة |
| `GET /api/v1/recommendations` | **`learner`** — متغيّرش، ولسه بتاخد `403` للشركة |
| `GET /api/v1/career-roles` | **`learner`** — متاحش للشركة، استخدم `/talent/filters` |

**التوثيق:** الـ3 endpoints موثّقين على `https://back-end-zdip.onrender.com/docs` تحت tag **Talent Discovery**.
