# تسجيلات الحسابات في SkillSpan — ملاحظة مرجعية

> ملاحظة شخصية للتعلم: **ليه كل تسجيل بيطلب حاجات مختلفة؟**
> كل معلومة هنا مأخوذة من الكود نفسه — أسماء الملفات مكتوبة جنب كل حاجة عشان ترجع لها.

---

## الخلاصة في 3 قواعد

1. **التسجيل الذاتي (المستخدم بيسجّل نفسه)** لازم **موافقة على الشروط + سياسة الخصوصية**، ولازم
   **تأكيد الإيميل بـ OTP** — لأن مفيش حد بيتحقق من هويته غير الإيميل.
2. **الدخول بجوجل** مابيطلبش OTP — **جوجل أكّد الإيميل أصلًا** — بس لسه بيطلب الموافقتين لو المستخدم جديد.
3. **المسارات الإدارية (`/setup/*`)** مابتطلبش لا موافقات ولا OTP — **السر هو التصريح**، واللي معاه السر
   (الأدمن) هو المسؤول عن الحساب.

> يعني: **اللي بيتحقق من هوية المستخدم هو اللي بيحدد إيه اللي مطلوب.** مستخدم بيسجّل نفسه → إيميل + OTP.
> جوجل بيشهد له → مفيش OTP. الأدمن بيشهد له → مفيش OTP، بس سر.

---

## جدول المقارنة — الـ 5 مسارات

| | `register` | `register/organization` | `login/google` | `setup/create-admin` | `setup/create-mentor` |
|---|---|---|---|---|---|
| **الموافقات (terms + privacy)** | ✅ مطلوبة | ✅ مطلوبة | ✅ لمستخدم جديد بس | ❌ | ❌ |
| **OTP / تأكيد إيميل** | ✅ | ✅ | ❌ (جوجل أكّده) | ❌ | ❌ |
| **سر في البيئة** | ❌ | ❌ | ❌ | ✅ `ADMIN_SETUP_SECRET` | ✅ `MENTOR_SETUP_SECRET` |
| **توكن Sanctum مطلوب** | ❌ | ❌ | ❌ | ❌ | ❌ |
| **`password_confirmation`** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **ملف مرفوع** | ❌ | ✅ `proof_file` | ❌ | ❌ | ❌ |
| **موافقة إضافية بعدها** | — | ✅ اعتماد المؤسسة من الأدمن | — | — | — |
| **الحقول المطلوبة** | 5 | 10 | 1 | 4 | **1** (`email`) |
| **معدّل الطلبات** | 10/دقيقة | 10/دقيقة | 20/دقيقة | 5/دقيقة | 10/دقيقة |
| **الكود** | 201 | 201 | 200/201 | 201 | 201 |

---

## مسار بمسار

### 1. `POST /api/v1/auth/register` — طالب (فرد)

**مطلوب:** `name`, `email`, `password` + `password_confirmation`, `terms_accepted`,
`privacy_accepted`.

**اختياري:** `phone`, `locale` (`en`/`ar`), `career_status`, `academic_status`
(`enrolled`/`graduated`/`on_leave`/`student`/`graduate`/`looking_for_job`/`employed`).

**ممنوع (`prohibited`):** `organization_name`, `organization_type`, `proof_file` — لو بعتهم بيرجّع
**422** برسالة بتوجّهك لـ `/register/organization`. الفكرة: مسار واحد لكل نوع حساب، فمفيش لبس.

**وبعدين:** الإيميل مش متأكد → **مش هتقدر تعمل login** لحد ما تكمّل `POST /auth/verify`.

`app/Http/Requests/Auth/RegisterRequest.php`

### 2. `POST /api/v1/auth/register/organization` — شركة / جامعة / شريك تدريب

**نوع الطلب:** `multipart/form-data` — لأن فيه ملف.

**مطلوب زيادة:** `organization_name`, `organization_type`
(`company`/`university`/`training_partner`), `organization_contact_email`,
و `proof_file` (jpg/jpeg/png/pdf، أقصى 5MB).

**اختياري:** `organization_website`, `organization_description`, `organization_industry`,
`organization_company_size`, `organization_country/city/address/postal_code`, `phone`.

**مهم جدًا:** المؤسسة بتتسجّل **`pending`**، و**كل أعضائها مش هيعرفوا يعملوا login** لحد ما الأدمن
يعتمدها. وده مقصود: ما ينفعش حد يقول "أنا شركة" وخلاص من غير إثبات.

`app/Http/Requests/Auth/RegisterOrganizationRequest.php`

### 3. `POST /api/v1/auth/login/google` — دخول/تسجيل بجوجل

**مطلوب:** `credential` (الـ ID token من Google Sign-In في الفرونت).

**الموافقات:** `terms_accepted` + `privacy_accepted` — **مطلوبة لو المستخدم جديد**، ومتجاهلة لو
موجود (لأنه وافق قبل كده).

**ليه مفيش OTP؟** لأن جوجل أكّد الإيميل بالفعل، فالكود بيسجّل `email_verified_at = now()` و
`status = active` على طول. الباسورد بيتولّد عشوائي (64 حرف) لأن الدخول بجوجل مش بباسورد.

**الرد فيه `is_new_user`** عشان الواجهة تعرف تعرض "أهلًا بيك" ولا "نوّرت تاني".

`app/Services/AuthService.php` (حوالي سطر 195–235)

### 4. `POST /api/v1/setup/create-admin` — إنشاء أدمن

**مطلوب:** `secret`, `name`, `email`, `password` (8+).

**مفيش** موافقات ولا OTP. الرد **201** وفيه **`token` جاهز** — يعني بيتسجّل دخول على طول.

**معدّل الطلبات 5/دقيقة** (أشد من الباقي عن قصد — أخطر endpoint).

`app/Http/Controllers/Api/SetupController.php` (سطر 28)

### 5. `POST /api/v1/setup/create-mentor` — تسجيل مرشد

**مطلوب:** `secret`, `email` — **وخلاص**.

**اختياري:** `name`, `password`, `expertise`, `affiliation`, `availability`.

**مفيش** موافقات، ولا OTP، ولا `password_confirmation`. والسبب واضح: **إنت اللي بتسجّله**، فإنت
الضمانة. وده كمان بيخليه **مسارك لإعادة تعيين باسورد مرشد نسي باسوره** — من غير OTP ومن غير ما
تحتاج توصل على إيميله.

**4 نتائج حسب حالة الإيميل:**

| حالة الإيميل | النتيجة |
|---|---|
| مش موجود | حساب جديد `active` + إيميل متأكد على طول + `generated_password` (مرة واحدة) |
| `pending` | بيتفعّل |
| `active` | بيترقّى مرشد (ولو بعتّ `password` بيتبدّل مع `password_reset: true`) |
| `suspended` / `deleted` / محذوف ناعم | **422** — لازم ترجّعه الأول |

**المستخدم الجديد مابياخدش أي role** — ده مقصود. هوية المرشد هي `ProfessionalProfile` بـ
`type=mentor` و `verification_status=verified`، ودي اللي بتفتح مسارات `/api/v1/mentor/*`.

`app/Http/Controllers/Api/SetupController.php` (سطر 115)

---

## بوابة الدخول — اللي بيخلي التسجيل "ناقص" لو ما كمّلتوش

مهما كان مسار التسجيل، الدخول بيرجّع **422 "You must activate your account first."** لو اتحقق شرطين:

```php
if ($user->status !== 'active' || ! $user->email_verified_at) { ... }
```

فيه كمان بوابتين تانيين في نفس المكان (`AuthController::attemptLogin`):

- **المؤسسة مش معتمدة** → **422** "Your organization is still pending approval." — وده بيتفحص على
  **كل** عضويات المستخدم النشطة، وبيشتغل على `/auth/login` العادي كمان مش بس
  `/auth/login/organization` (عشان محدش يعمل bypass).
- **5 محاولات دخول غلط** لنفس الإيميل + IP → **429** + هيدر `Retry-After`، قفل 5 دقايق.

**فرق مهم بين 422 و 429:** الاتنين "فشل"، بس 429 معناها "استنى" و422 معناها "البيانات غلط". الواجهة
لازم تفرّق بينهم — عشان كده الـ 429 معاه `Retry-After`.

---

## مفاهيم Laravel اللي ورا ده كله

دي الحاجات اللي لو فهمتها هتفهم كل الكود ده بسرعة:

**التحقق (Validation)**
- `FormRequest` = كلاس لوحده للتحقق (`app/Http/Requests/`). بيتنادى تلقائيًا لما تعمله type-hint في
  الـ controller — لو فشل بيرجّع 422 لوحده قبل ما كودك يشتغل أصلًا.
- ساعات التحقق بيتكتب inline بـ `$request->validate([...])` أو `validator(...)` — زي
  `SetupController` و`SkillsController`. الاتنين شغالين، بس الـ FormRequest أنضف لما القواعد تكبر.
- **قواعد مهمة شوفتها هنا:**
  - `confirmed` → لازم يجي معاه حقل `<name>_confirmation` (عشان كده `password_confirmation` مطلوب).
  - `accepted` → لازم القيمة تكون "نعم": `yes` / `on` / `1` / `true`. عشان كده `terms_accepted: true`.
  - `prohibited` → الحقل **ممنوع** يتبعت. عكس `required` تمامًا — وده اللي بيمنع خلط نوعي الحسابات.
  - `Rule::exists('skills','id')->where(fn($q) => $q->where('status','active'))` → "لازم يكون موجود
    **و** حالته active" — مش مجرد `exists`.
  - `sometimes` → طبّق القاعدة **بس لو الحقل مبعوت**. ودي اللي بتخلي الـ PATCH/PUT يبقى تعديل جزئي.

**الأمان**
- **السر مش بيتخزن في الكود** — بيتقرأ من البيئة: `config('services.mentor_setup.secret')`
  (و`config/services.php` بيقراه من `env('MENTOR_SETUP_SECRET')`).
- **`hash_equals()` مش `!==`** — مقارنة ثابتة الوقت. `!==` العادية بتوقف عند أول حرف مختلف، فبتسرّب
  طول الجزء الصح عن طريق فرق التوقيت (timing attack). شوفها كمان في
  `Internal\BaselineItemsController` — هناك أخطر لأن الرد فيه الإجابات الصح.
- **`throttle:10,1`** في الـ route = 10 طلبات في الدقيقة (middleware)، و**`RateLimiter`** جوه الكود
  لحالات أدق (زي 5 محاولات دخول فاشلة لكل إيميل+IP).

**التعامل مع الداتابيز**
- **`forceCreate()` مش `create()`** — `create()` بيمشي على `$fillable` في الموديل وبيتجاهل أي حقل
  مش فيه **من غير أي رسالة خطأ**. `forceCreate()` بيتخطى الحماية دي. عشان كده `SetupController`
  بيستخدمها مع `status` و `email_verified_at` — لو استخدم `create()` كانوا اختفوا بصمت.
- **`forceFill()->save()`** نفس الفكرة للتعديل — زي ما شفت في `verification_status` بتاع
  `ProfessionalProfile`: الحقل ده **مقصود** إنه مش mass-assignable، لأن أي `create($request->all())`
  مستقبلي كان هيخلي أي حد يرقّي نفسه لمرشد موثّق.
- **`User::withTrashed()`** — الحذف هنا **ناعم** (`SoftDeletes`): الصف لسه في الجدول وماسك الفهرس
  الفريد على `users.email`. فلو دوّرت بـ `User::where(...)` مش هتلاقيه، وبعدين `forceCreate` هيموت
  بـ **500** بدل ما يرجّع **422** مفهومة.

**شكل الردود**
- كل الردود نفس الشكل: `success` + `message` + `data`. والأخطاء: `code` + `message` + `request_id`.
- `201` = اتعمل حاجة جديدة، `200` = تمام بس مفيش حاجة جديدة (زي إعادة تشغيل idempotent).
- القوائم فيها `meta` (current_page / last_page / per_page / total).

---

## أخطاء هتشوفها كتير ومعناها

| الكود | الرسالة / السبب | الحل |
|---|---|---|
| 422 | `The email field is required.` | الحقل ناقص |
| 422 | `This email is already registered.` | الإيميل مستخدم — سجّل دخول أو استخدم إيميل تاني |
| 422 | `You must accept the Terms and Conditions.` | `terms_accepted` لازم `true` |
| 422 | `The provided credentials are incorrect.` | إيميل أو باسورد غلط |
| 422 | `You must activate your account first.` | كمّل `/auth/verify` |
| 422 | `Your organization is still pending approval.` | استنى اعتماد الأدمن |
| 422 | `The selected career role is not approved.` | المسار المهني لازم `status=approved` |
| 403 | `Mentor setup is disabled…` | `MENTOR_SETUP_SECRET` مش مضبوط في البيئة |
| 403 | `Invalid setup secret.` | قيمة السر غلط |
| 429 | `Too many login attempts…` | استنى، وشوف `Retry-After` |
| 500 | `NOT NULL constraint failed: …` | حقل إلزامي مش في `$fillable` فاتسقط بصمت |

---

## فكرة أخيرة تتعلم منها

**ليه `create-mentor` بياخد `email` بس؟** لأن كل حقل تاني ليه قيمة افتراضية منطقية: `name` من قبل
الـ `@`، و`password` عشوائي، والباقي `nullable`. القاعدة العملية: **متطلبش من المستخدم حاجة إنت
تقدر تشتقها أو تستغنى عنها** — كل حقل مطلوب زيادة = نقطة فشل زيادة.

**وليه `create-mentor` مش محتاج توكن Sanctum؟** لأن اللي بيناديه مش مستخدم مسجّل — هو إنت (الأدمن) من
برّه. لو كان محتاج توكن كنت هتحتاج أدمن موجود الأول (بيضة ولا فرخة). السر بيحل الـ bootstrap ده.

---

## وصفة عملية: المرشد نسي باسوره — بدون أي فرونت إند

السيناريو: مرشد بيجيلك → بتعمله حساب → بيدخل عادي → **نسي الباسورد** → بيجيلك تاني.

### متستخدمش `forgot-password`

الكود (OTP) بيتبعت على **إيميل المرشد**، مش إيميلك. إنت مش هتشوفه، فالـ flow هيتوقف عندك وخلاص.
(ينفع بس لو إنت فعليًا بتتحكم في إيميله — وإنت مش محتاجه أصلًا.)

### استخدم `create-mentor` تاني — نفس الـ endpoint

**الخطوة 1 — أول مرة:**

```
POST /api/v1/setup/create-mentor
{
  "secret":   "<السر>",
  "email":    "mentor@example.com",
  "name":     "د. فلان",
  "password": "FirstPass123"
}
→ 201 · registered: true
```

بعدها المرشد بيدخل عادي على `POST /api/v1/auth/login` بـ `{ email, password }`.

**الخطوة 2 — نسي الباسورد؟ كرّر نفس الطلب بالباسورد الجديد:**

```
POST /api/v1/setup/create-mentor
{
  "secret":   "<السر>",
  "email":    "mentor@example.com",
  "password": "NewPass456"
}
→ 201 · registered: false · password_reset: true
```

**مفيش OTP، مفيش إيميل، مفيش فرونت إند.** وبعدها بتقوله الباسورد الجديد وخلاص.

### 4 حاجات تخلي بالك منها

1. **لو سِبت `password` فاضي** في الخطوة دي، الباسورد القديم **مش بيتغيّر** — ده مقصود، مش خطأ.
2. **الإيميل لازم يكون نفسه بالحرف.** مفيش بحث بالاسم — عشان كده احفظ إيميلات المرشدين عندك.
3. **لو حالته `suspended` أو `deleted`** (أو محذوف ناعم) → **422**، لازم ترجّعه الأول.
4. **الحد 10 طلبات/دقيقة** على الـ endpoint.

### ليه التصميم كده؟

`create-mentor` بيعمل **إنشاء أو تحديث**، مش إنشاء بس: `ProfessionalProfile::firstOrNew` بيمنع تكرار
الصف، و`forceFill` بيحدّث الباسورد لو بعتّه. يعني **endpoint واحد بيخدم الإنشاء والاستعادة** — مسار
واحد تتعلمه بدل مسارين.

وده اللي بيخلي `password_reset` مفيد: لو `true` يبقى ده كان **إعادة تعيين** مش حساب جديد، فتأكد إنك
بعتّت الباسورد للمرشد الصح 🙂
