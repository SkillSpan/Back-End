# Project Type / Difficulty — عقد القيم (BA-03)

> موجّه للفرونت (FE-09). مكتوب بنفس أسلوب `TALENT_DISCOVERY_API.md` و `EVIDENCE_ADD_API.md`.
>
> **القاعدة الحاكمة:** الـBackend هو المصدر الرسمي للقيمة، والفرونت هو اللي بيعمل map للـlabel.  
> **مفيش أي قيمة في الباك اتغيّرت في التاسك دي** — القيم كانت صح ومقفولة من الأصل، والتاسك دي **توثيق + تثبيت بـtest**.



---

## الملخص

| البند                    | القيمة                                                                                                    |
| ------------------------ | --------------------------------------------------------------------------------------------------------- |
| الحقول المعنية           | `projects.type` · `projects.difficulty` · `roadmap_actions.type`                                          |
| نتيجة الفحص              | القيم في الباك **صحيحة ومقفولة** — مفيش داعي لأي تغيير في DB أو API                                       |
| ملفات الكود اللي اتغيّرت | `app/OpenApi/Schemas/ProjectSchemas.php` — **٣ حقول كانوا غلط في الـschema** (توثيق بس، مفيش سلوك اتغيّر) |
| ملفات اتضافت             | `tests/Feature/Projects/ProjectTypeDifficultyContractTest.php` + المستند ده                               |
| ملف اتولّد من جديد       | `storage/api-docs/api-docs.json` (لازم، عشان `/docs` بيتقدّم من الملف المـcommitted)                      |
| قرار الـCapstone         | يتـmap على `real_project` (مفيش قيمة اسمها `capstone` في الباك)                                           |
| قرار الـdifficulty       | رقم `0–5` + **bands مقترحة** (تحت)                                                                        |

---

## ⚠️ أول حاجة: فيه **تلات** حقول اسمهم `type` — والفرونت بيخلط بينهم

ده كان أصل المشكلة. الفرونت بيبني كروت المشاريع من `ALL_TYPES` و `TYPE_META`، والقيم دي **مش** قيم `projects.type`:

| الحقل                   | الجدول            | عدد القيم | الفرونت بيقراه منين                                     |
| ----------------------- | ----------------- | --------- | ------------------------------------------------------- |
| `projects.type`         | `projects`        | **٢**     | `GET /api/v1/projects` → `data[].type`                  |
| `roadmap_actions.type`  | `roadmap_actions` | **٥**     | `GET /api/v1/intelligence/*` → `roadmap.actions[].type` |
| `roadmap_actions.phase` | `roadmap_actions` | ٤         | نفس المصدر — `phase` (مش `type`)                        |

كروت الفرونت الخمسة (`Practice Task` / `Simulation Project` / `Real Project` / `Assessment` / `Capstone`) هي **مزيج**: أربعة منهم قيم `roadmap_actions.type`، والخامس (`Capstone`) **مالوش أي مقابل في الباك**.

---

## جدول ١ — `projects.type` (فيه **قيمتين بس**)

ده نوع المشروع الحقيقي اللي بيتخزّن في `projects` وبيتعرض في كتالوج المشاريع.

| Backend Value       | المعنى                                                                                         | Frontend Label المقترح    |
| ------------------- | ---------------------------------------------------------------------------------------------- | ------------------------- |
| `simulation`        | مشروع محاكاة داخلي بتاع SkillSpan — `organization_id` بيفضل `null`، ومالك المشروع هو اللي عمله | **Simulation Project** 🧪 |
| `company_sponsored` | مشروع حقيقي تابع لشركة — الشركة بتملكه وبتعمل رعاية له                                         | **Real Project** 🚀       |

### القيم اللي **ممنوعة** على `projects.type`

`capstone` · `real_project` · `simulation_project` · `practice` · `assessment` · `resource` · `company-sponsored` · `Simulation` · `SIMULATION` → **كلها `422 VALIDATION_ERROR`**.

> ملاحظة: قيمة فيها مسافة زيادة في الآخر (`"simulation "`) **بتتعدّل تلقائيًا** بالـ`TrimStrings` middleware قبل الـvalidation، فبتتـaccept للسبب الصح. ده مش استثناء في العقد.

### فين القيمتين متثبّتين في الكود (٤ أماكن — لازم يفضلوا متفقين)

| المكان                                                  | السطر                                                                                |
| ------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| migration `create_projects_table`                       | `enum('type', ['simulation','company_sponsored'])`                                   |
| `StoreProjectRequest`                                   | `'type' => ['required','string', Rule::in(['simulation','company_sponsored'])]`      |
| `UpdateProjectRequest`                                  | بيورّث من فوق — بيحوّل `required` لـ`sometimes` بس، فبياخد **نفس** المجموعة المقفولة |
| `ProjectController::validatedFilters()` (فلتر الكتالوج) | `'type' => ['nullable','string','in:simulation,company_sponsored']`                  |
| `ProjectLifecycleService::completenessGaps()`           | حاجز تاني: لو النوع خارج القيمتين بيتحسب `gap`                                       |

---

## جدول ٢ — `roadmap_actions.type` (فيه **٥ قيم**)

ده اللي بيظهر في الرودماب (`roadmap.actions[]`) — وده مصدر أربعة من كروت الفرونت.

| Backend Value       | المعنى                                        | Frontend Label المقترح                                   |
| ------------------- | --------------------------------------------- | -------------------------------------------------------- |
| `assessment`        | تقييم/امتحان مهارة                            | **Assessment** 📝                                        |
| `resource`          | مورد تعليمي (مش كارت مشروع — كورس/مقال/فيديو) | **Resource** 📚 (الفرونت حاليًا مالوش label ليه)         |
| `practice`          | تمرين تطبيقي                                  | **Practice Task** 🔧                                     |
| `simulated_project` | مشروع محاكاة جوه الرودماب                     | **Simulation Project** 🧪 (الفرونت بيعرضها "Simulation") |
| `real_project`      | مشروع حقيقي جوه الرودماب                      | **Real Project** 🚀                                      |

> ⚠️ **متخلطش:** `simulated_project` (بالـ`d`، في الرودماب) ≠ `simulation` (في `projects`). الاتنين معناهم متقارب بس **مكانين مختلفين تمامًا** ومينفعش تتبادلوا.

### فين الـ٥ قيم متثبّتين

| المكان                                      | التفاصيل                                                                                   |
| ------------------------------------------- | ------------------------------------------------------------------------------------------ |
| migration `create_roadmap_actions_table`    | `enum('type', ['assessment','resource','practice','simulated_project','real_project'])`    |
| `RoadmapAction::TYPES`                      | نفس القائمة — **المصدر الوحيد** المستخدم في التحقق                                         |
| `IntelligencePersistenceService::mapType()` | أي نوع غير معروف → **`502 INTELLIGENCE_INVALID_RESPONSE`** — **مش** بيـcoerce لـ`practice` |

---

## `Capstone` — مش موجود في الباك

الفرونت عنده `Capstone` في `TYPE_META` و `ALL_TYPES`، لكن **مفيش أي قيمة اسمها `capstone`** في `projects.type` ولا في `roadmap_actions.type`.

**القرار المعتمد:** `Capstone` يتـmap على **`real_project`**.

السبب: الـCapstone في الفرونت هو **milestone** (آخر مرحلة، "combines everything in a deployed production context") — يعني **مش نوع مختلف**، هو `real_project` اللي واقع في آخر phase.

**التنفيذ الموصى به للفرونت:**

```js
// الـCapstone مش قيمة — هو label عرض لـreal_project في مرحلة career_readiness.
const isCapstone = (action) =>
  action.type === 'real_project' && action.phase === 'career_readiness';
```

لو الفرونت عايز يبعت `type` للباك، **يبعت `real_project` بس** — متبعتش `capstone` أبدًا.

---

## جدول ٣ — `projects.difficulty` (**رقم، مش enum**)

| البند          | القيمة                                                                       |
| -------------- | ---------------------------------------------------------------------------- |
| نوع العمود     | `decimal(3,2)` — **nullable**                                                |
| المدى المسموح  | `0` → `5` (**الاتنين شاملين**)                                               |
| الخطوة         | `0.01` (الـadmin form بتستخدم `step="0.5"`، بس الـAPI بيقبل أي رقم في المدى) |
| القيمة الفاضية | `null` مسموحة (الحقل `sometimes, nullable`)                                  |
| فيه enum؟      | **لأ**                                                                       |

### الـBands المقترحة (٤ bands — مطابقة لـ`DIFF_META` عند الفرونت اللي عنده `dots: 1..4`)

| المدى (شامل الطرفين) | Label            | dots |
| -------------------- | ---------------- | ---- |
| `0.00 – 1.24`        | **Beginner**     | 1    |
| `1.25 – 2.49`        | **Intermediate** | 2    |
| `2.50 – 3.74`        | **Advanced**     | 3    |
| `3.75 – 5.00`        | **Expert**       | 4    |
| `null`               | «غير محدد»       | 0    |

```js
// الحدود: أقل طرف شامل (lower-inclusive) — 1.25 → Intermediate، 2.5 → Advanced.
export function difficultyBand(value) {
  if (value === null || value === undefined) return null; // اعرض «غير محدد»
  if (value < 1.25) return 'Beginner';
  if (value < 2.5)  return 'Intermediate';
  if (value < 3.75) return 'Advanced';
  return 'Expert';                                        // 3.75 – 5.00
}
```

> ⚠️ **متستخدمش `RATING_LEVELS`** (اللي في `assessmentQuestions.js`) لـdifficulty المشروع. دي **سكيل مختلف تمامًا**: مقياس الـBaseline Assessment، مداه `0–4`، وليه ٥ قيم (`No Experience / Beginner / Developing / Intermediate / Advanced`). بيتشاركوا في كلمة "Beginner" وبس.

### ⚠️ تحذير مهم عن فلتر الـdifficulty

`GET /api/v1/projects?difficulty=3` بيعمل **مطابقة تامة (exact match)**، **مش** فلتر بالـband:

- `?difficulty=3` → المشاريع اللي `difficulty = 3.00` بس.
- **مفيش** `?difficulty=advanced` ولا `?min_difficulty` / `?max_difficulty`.

يعني الفرونت لو عايز "Advanced" لازم **يفلتر client-side** بالـ`difficultyBand()`، أو يبعت قيمة رقمية واحدة يعرفها. المدى المسموح في الفلتر `0–5` برضه، وأي قيمة بره → `422`.

---

## فين القيم بتظهر في الـAPI (create / update / list / show)

| العملية     | الـEndpoint                                                                 | `type`                                                                         | `difficulty`                                   |
| ----------- | --------------------------------------------------------------------------- | ------------------------------------------------------------------------------ | ---------------------------------------------- |
| **Create**  | `POST /api/v1/projects` (شركة/جامعة) · `POST /api/v1/admin/projects` (أدمن) | **مطلوب** + مقفول على القيمتين                                                 | اختياري، `0–5`                                 |
| **Update**  | `PATCH /api/v1/projects/{id}` · `PATCH /api/v1/admin/projects/{id}`         | اختياري (نفس المجموعة المقفولة) — **مينفعش يتغيّر بعد ما المشروع يبقى `open`** | اختياري، `0–5`                                 |
| **List**    | `GET /api/v1/projects` (learner)                                            | فلتر `?type=` مقفول على القيمتين                                               | فلتر `?difficulty=` رقم `0–5`، **مطابقة تامة** |
| **Show**    | `GET /api/v1/projects/{id}`                                                 | بيتـreturn **زي ما هو** (`data.type`)                                          | بيتـreturn **زي ما هو** (`data.difficulty`)    |
| **Roadmap** | `GET /api/v1/intelligence/*`                                                | `roadmap.actions[].type` بيتـreturn خام                                        | —                                              |

### ملاحظات على الـUpdate

- تغيير `type` على مشروع `draft` **مسموح** (مثال: `company_sponsored` → `simulation`)، والـservice بيعيد تشغيل قواعد الـownership للنوع الجديد.
- `type` **بيتقفل** بمجرد ما المشروع يبقى `open` — محاولة تغييره بعد كده بترجع `422`.
- قائمة الـ`admin` بترجع `type` و `difficulty` كفلترين، والـ`difficulty` عندها `is_numeric` (بتتجاهل القيمة غير الرقمية بدل ما ترفضها) — **الفرونت مايعتمدش على ده**، اعتمد على فلتر الكتالوج.

---

## الـTests اللي بتثبّت العقد


### جديد — `tests/Feature/Projects/ProjectTypeDifficultyContractTest.php` (9 tests / 73 assertions)

| الـTest                                                        | بيثبّت إيه                                                                  |
| -------------------------------------------------------------- | --------------------------------------------------------------------------- |
| `the_roadmap_action_vocabulary_is_exactly_the_documented_five` | `RoadmapAction::TYPES` === الـ٥ قيم حرفيًا (drift guard)                    |
| `the_project_type_vocabulary_is_exactly_the_documented_two`    | القيمتين بيتـaccept، وأي قيمة تانية `422`، وعدد القيم المميزة في الجدول = 2 |
| `difficulty_accepts_the_full_inclusive_0_to_5_range`           | `0` و `5` والكسور بيتـaccept وبيتـreturnوا صح                               |
| `difficulty_rejects_values_outside_0_to_5`                     | `-0.01` · `5.01` · `6` · `99` → `422`                                       |
| `difficulty_is_nullable_because_it_is_not_an_enum`             | `null` مسموح                                                                |
| `the_catalog_type_filter_accepts_only_the_official_values`     | الفلتر يقبل القيمتين، ويرفض `capstone`/`real_project`/`hackathon`           |
| `the_catalog_difficulty_filter_uses_the_same_0_to_5_bounds`    | الفلتر نفس الحدود                                                           |
| `update_accepts_a_switch_between_the_two_official_types`       | تغيير النوع على draft ينجح                                                  |
| `update_rejects_an_unofficial_type`                            | `capstone` على الـupdate → `422`                                            |

### موجود قبل كده (مش اتغيّر — وبيغطي جانب الرفض)

| الملف                                                      | الـTest                                                                                                                                                                                                      |
| ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `tests/Feature/Projects/ProjectManagementWorkflowTest.php` | `test_creation_validates_the_project_type` · `test_the_project_type_cannot_change_once_the_project_is_open`                                                                                                  |
| `tests/Feature/Admin/AdminProjectTest.php`                 | `test_creating_refuses_an_unsupported_project_type`                                                                                                                                                          |
| `tests/Feature/Projects/ProjectCatalogTest.php`            | `test_invalid_type_filter_is_rejected` · `test_invalid_difficulty_filter_is_rejected` · `test_authenticated_learner_can_access_valid_project_details` (تأكيد إن `show` بترجّع `type`/`difficulty` زي ما هما) |
| `tests/Feature/Intelligence/RoadmapActionContractTest.php` | `test_unknown_action_type_is_rejected_instead_of_falling_back_to_practice` · `test_every_allowed_action_type_is_accepted_and_persisted`                                                                      |

---

## نتيجة التحقق (create / update / list / show)

| السويت                                                                                                    | النتيجة                         |
| --------------------------------------------------------------------------------------------------------- | ------------------------------- |
| `ProjectTypeDifficultyContractTest` (جديد)                                                                | **9 passed** (73 assertions)    |
| `ProjectCatalogTest` + `ProjectManagementWorkflowTest` + `AdminProjectTest` + `RoadmapActionContractTest` | **178 passed** (606 assertions) |
| Pint                                                                                                      | نظيف                            |

---


## ⚠️ تصحيح اتلقى في `/docs` (الـOpenAPI)

`app/OpenApi/Schemas/ProjectSchemas.php` — الـ`Project` schema (اللي بيتـreturn من الكتالوج ومن `GET /api/v1/projects/{id}`) كان فيه **٣ حقول موصوفة غلط**، وكلها في نفس الحتة اللي الفرونت بيقرا منها:

| الحقل               | كان مكتوب            | الصح                                         | ليه                                                      |
| ------------------- | -------------------- | -------------------------------------------- | -------------------------------------------------------- |
| `difficulty`        | `type: string` ❌     | `number`, `format: float`, `0–5` ✅           | العمود `decimal(3,2)` والـmodel بيعمله cast لـ`float`    |
| `type`              | `string` من غير enum | `enum: ['simulation','company_sponsored']` ✅ | نفس القيمتين المقفولتين، والعمود `NOT NULL`              |
| `learning_outcomes` | `type: string` ❌     | `array` of `string` ✅                        | الـmodel بيعمله cast لـ`array` والـrequest بيقبل `array` |

**التأثير:** أي حد بيولّد types من `/docs` كان هيطلعله `difficulty: string` — يعني مقارنات نصية وأخطاء وقت التشغيل على الفرونت.

**التصحيح توثيقي بحت** — مفيش أي تغيير في السلوك ولا في الـDB. والملف المولّد `storage/api-docs/api-docs.json` لازم يتـregenerate ويتـcommit، لأن الـdeploy بيقدّم الملف المـcommitted مش بيعمل generate وقت الـboot. الـdiff كان **15+/4−** كله جوه `components.schemas.Project` بس، والـpaths/الـtags فضلوا **83/22** زي ما هما.

---

## ✅ اللي الفرونت لازم يعمله

1. **متخلطش** قيم `roadmap_actions.type` بـ`projects.type`. كروت الرودماب من `roadmap.actions[].type`، وكروت الكتالوج من `projects.type`.
2. الـ`TYPE_META` keys عندك **labels** — لازم تتعمل map من القيمة الرسمية للـlabel قبل العرض.
3. `Capstone` = label عرض لـ`real_project` (في phase `career_readiness`) — **متبعتش `capstone` للباك**.
4. اعرض `difficulty` بالـ`difficultyBand()` فوق، ومتفلترش بالـband على السيرفر.
5. الـ`src/api.js` حاليًا **مش** بيبعت `type` ولا `difficulty` خالص — فالربط الجديد هو اللي محتاج الـmapping ده.
