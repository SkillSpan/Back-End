# SkillSpan Backend — مرجع كل الـ API Endpoints

> ملف مرجعي مكمل لمجموعة Postman (`SkillSpan_API_Collection.postman_collection.json`).
> كل الـ endpoints مرتبة بنفس ترتيب المجموعات في الـ Collection.

## المتغيرات (Collection Variables)

| المتغير | القيمة الافتراضية | الشرح |
|---|---|---|
| `base_url` | `https://back-end-zdip.onrender.com` | رابط الباك إند. للإنتاج سيبها زي ما هي، وللتشغيل المحلي حطها http://localhost:8000 |
| `per_page` | `15` | حجم الصفحة في كل الـ list endpoints (افتراضي 15، أقصى 100) |
| `idempotency_key` | `idem-demo-001` | مفتاح التفرّد للتقديم — غيّره لما تحب تعمل تقديم جديد فعليًا |
| `token` | `{{learner_token}}` | التوكن الفعّال المستخدم في كل الطلبات (تغيّره حسب الدور اللي بتختبره) |
| `learner_email` | `learner@test.com` | إيميل حساب الطالب |
| `learner_password` | `password123` | باسورد حساب الطالب |
| `learner_token` | `` | بيتعبّى أوتوماتيك من طلب تسجيل الدخول |
| `learner_user_id` | `1` | الـ id بتاع الطالب (لازم لـ skill-match) |
| `owner_email` | `owner@test.com` | إيميل صاحب المشروع |
| `owner_password` | `password123` | باسورد صاحب المشروع |
| `owner_token` | `` | بيتعبّى أوتوماتيك |
| `mentor_email` | `mentor@test.com` | إيميل المرشد |
| `mentor_password` | `password123` | باسورد المرشد |
| `mentor_token` | `` | بيتعبّى أوتوماتيك |
| `admin_email` | `admin@test.com` | إيميل الأدمن |
| `admin_password` | `password123` | باسورد الأدمن |
| `admin_token` | `` | بيتعبّى أوتوماتيك |
| `org_email` | `org@test.com` | إيميل حساب المؤسسة عند التسجيل |
| `org_password` | `password123` | باسورد حساب المؤسسة |
| `org_member_email` | `member@org.test` | إيميل عضو المؤسسة للدخول |
| `org_member_password` | `password123` | باسورد عضو المؤسسة |
| `org_token` | `` | بيتعبّى أوتوماتيك |
| `new_learner_email` | `new.learner@test.com` | إيميل للتسجيل الجديد |
| `new_learner_password` | `password123` | باسورد للتسجيل الجديد |
| `new_admin_email` | `new.admin@test.com` | إيميل الأدمن الجديد |
| `new_admin_password` | `password123` | باسورد الأدمن الجديد |
| `new_mentor_email` | `new.mentor@test.com` | إيميل المرشد الجديد |
| `new_mentor_password` | `` | اتركه فاضي لو مش عايز تغيّر الباسورد لحساب موجود |
| `otp_code` | `123456` | كود الـ OTP المرسل على الإيميل (6 أرقام) |
| `google_credential` | `` | الـ ID token من Google Sign-In |
| `baseline_question_id` | `q1` | معرّف سؤال في التقييم الأساسي |
| `admin_setup_secret` | `` | قيمة ADMIN_SETUP_SECRET |
| `mentor_setup_secret` | `` | قيمة MENTOR_SETUP_SECRET — املأها بنفسك (مش محفوظة هنا عن قصد) |
| `internal_baseline_items_secret` | `` | قيمة INTERNAL_BASELINE_ITEMS_SECRET |
| `project_id` | `1` | مشروع للتجربة |
| `project_role_id` | `1` | الدور المطلوب في التقديم |
| `application_id` | `` | بيتعبّى أوتوماتيك من تقديم أو قائمة الطلبات |
| `application_status` | `submitted` | فلتر حالة الطلبات |
| `decision_status` | `accepted` | قرار صاحب المشروع: shortlisted/accepted/rejected/waitlisted |
| `recommendation_id` | `` | بيتعبّى أوتوماتيك من قائمة التوصيات |
| `recommendation_event_type` | `save` | التغذية الراجعة: save / hide / decline |
| `career_role_id` | `1` | مسار مهني معتمد |
| `skill_id` | `1` | مهارة موجودة |
| `learner_skill_id` | `` | بيتعبّى أوتوماتيك من مصفوفة المهارات |
| `student_profile_id` | `1` | الـ id بتاع StudentProfile |
| `assessment_id` | `` | بيتعبّى أوتوماتيك من بدء التقييم |
| `evidence_id` | `` | بيتعبّى أوتوماتيك |
| `assistant_interaction_id` | `` | بيتعبّى أوتوماتيك من سؤال المساعد |
| `student_id` | `` | الـ id بتاع الطالب المرتبط بالمرشد |
| `connection_id` | `` | بيتعبّى أوتوماتيك من روابط المرشد |
| `conversation_id` | `` | بيتعبّى أوتوماتيك من قائمة المحادثات |
| `message_id` | `` | بيتعبّى أوتوماتيك |
| `notification_id` | `` | بيتعبّى أوتوماتيك من قائمة الإشعارات |
| `unread_only` | `false` | true عشان تجيب غير المقروء بس |
| `notification_category` | `application` | فئة الإشعار |
| `organization_id` | `` | بيتعبّى أوتوماتيك من قائمة الأدمن |
| `organization_status` | `pending` | فلتر حالة المؤسسة: pending/verified/rejected |
| `country_id` | `1` | دولة من القائمة المرجعية |
| `university_id` | `1` | جامعة من القائمة المرجعية |
| `specialization_id` | `1` | تخصص من القائمة المرجعية |

## 00 · Setup (شغّل ده الأول)

تسجيل دخول لكل الأدوار + فحص الصحة. التوكنات بتتحفظ أوتوماتيك في المتغيرات.

| Method | Endpoint | الشرح |
|---|---|---|
| `POST` | `/api/v1/auth/login` | **POST /api/v1/auth/login** — دخول الطالب. التوكن بيتحفظ في `learner_token`. |
| `POST` | `/api/v1/auth/login` | **POST /api/v1/auth/login** — دخول صاحب المشروع. التوكن بيتحفظ في `owner_token`. |
| `POST` | `/api/v1/auth/login` | **POST /api/v1/auth/login** — دخول المرشد. التوكن بيتحفظ في `mentor_token`. |
| `POST` | `/api/v1/auth/login` | **POST /api/v1/auth/login** — دخول الأدمن. التوكن بيتحفظ في `admin_token`. |
| `GET` | `/up` | **GET /up** — 200 يعني التطبيق شغّال والنشر نجح. |

<details><summary>التفاصيل الكاملة</summary>

### `POST` /api/v1/auth/login

**POST /api/v1/auth/login** — دخول الطالب. التوكن بيتحفظ في `learner_token`.

### `POST` /api/v1/auth/login

**POST /api/v1/auth/login** — دخول صاحب المشروع. التوكن بيتحفظ في `owner_token`.

### `POST` /api/v1/auth/login

**POST /api/v1/auth/login** — دخول المرشد. التوكن بيتحفظ في `mentor_token`.

### `POST` /api/v1/auth/login

**POST /api/v1/auth/login** — دخول الأدمن. التوكن بيتحفظ في `admin_token`.

### `GET` /up

**GET /up** — 200 يعني التطبيق شغّال والنشر نجح.

</details>

## 01 · Auth — المصادقة

التسجيل، التفعيل، الدخول، استعادة كلمة المرور، والجلسة. **13 endpoint.**

| Method | Endpoint | الشرح |
|---|---|---|
| `POST` | `/api/v1/auth/register` | **POST /api/v1/auth/register** — تسجيل حساب فردي (طالب). بيتحقق من الإيميل (لازم يكون جديد)، الباسورد (8 أحرف على الأقل + تأكيد)، والموافقة على الشروط والسياسة. **لازم:** `name`, `email`, `password`, `password_confirmation`, `terms_accepted`, `privacy_accepted`. **اختياري:** `phone`, `locale` (`en`/`ar`), `career_status`, `academic_status`. **ممنوع** ترسل `organization_name` / `organization_type` / `proof_file` — دي بتاعة endpoint المؤسسات. بعد التسجيل بيوصلك OTP على الإيميل، وبتفعّل الحساب عبر `/auth/verify`. **معدّل الطلبات:** 10/دقيقة. **الرد:** 201. |
| `POST` | `/api/v1/auth/register/organization` | **POST /api/v1/auth/register/organization** — تسجيل حساب مؤسسة (شركة / جامعة / شريك تدريب). هذا الطلب **multipart/form-data** لأن فيه ملف إثبات (`proof_file`) — jpg/jpeg/png/pdf بحد أقصى 5MB. **لازم:** `name`, `email`, `password`, `password_confirmation`, `terms_accepted`, `privacy_accepted`, `organization_name`, `organization_type` (`company`/`university`/`training_partner`), `organization_contact_email`, `proof_file`. **مهم:** المؤسسة بتتسجّل بحالة `pending` — وأعضاؤها **مش هيقدروا يعملوا login** لحد ما الأدمن يوافق عبر `/admin/organizations/{id}/approve`. **معدّل الطلبات:** 10/دقيقة. **الرد:** 201. |
| `POST` | `/api/v1/auth/verify` | **POST /api/v1/auth/verify** — تأكيد الإيميل بالكود المرسل (6 أرقام). **لازم:** `email`, `otp` (نص بطول 6 بالظبط). لو الكود غلط أو منتهي → 422. **معدّل الطلبات:** 10/دقيقة. |
| `POST` | `/api/v1/auth/resend-otp` | **POST /api/v1/auth/resend-otp** — إعادة إرسال كود تفعيل الإيميل. **لازم:** `email`. **معدّل الطلبات:** 10/دقيقة. |
| `POST` | `/api/v1/auth/login` | **POST /api/v1/auth/login** — دخول حساب فردي (طالب). **لازم:** `email`, `password`. التوكن بيتحفظ أوتوماتيك في متغير `learner_token` من سكربت الاختبار. **أخطاء شائعة:** - `403` حساب `suspended`. - `422` إيميل مش متأكد بعد (لازم `/auth/verify`). - لو الحساب تابع لمؤسسة لسه `pending` → بيترفض برسالة "Your organization is still pending approval". **معدّل الطلبات:** 20/دقيقة. |
| `POST` | `/api/v1/auth/login` | **POST /api/v1/auth/login** — نفس الـ endpoint لكن لحساب صاحب المشروع. لازم عشان تختبر مسارات صاحب المشروع (قرارات الطلبات + عرض طلبات المشروع). التوكن بيتحفظ في `owner_token`. |
| `POST` | `/api/v1/auth/login` | **POST /api/v1/auth/login** — دخول حساب المرشد. المرشد **مالوش role** — هويته هي `ProfessionalProfile` بـ `type=mentor` و `verification_status=verified`. التوكن بيتحفظ في `mentor_token`. |
| `POST` | `/api/v1/auth/login` | **POST /api/v1/auth/login** — دخول حساب الأدمن. لازم لمسارات `/api/v1/admin/*` ومراجعة الأدلة. التوكن بيتحفظ في `admin_token`. |
| `POST` | `/api/v1/auth/login/google` | **POST /api/v1/auth/login/google** — دخول/تسجيل عبر Google Identity. **لازم:** `credential` (الـ ID token اللي راجع من Google Sign-In في الفرونت). **اختياري:** `terms_accepted`, `privacy_accepted` — مطلوبين فعليًا لو ده مستخدم جديد. الرد فيه `is_new_user` عشان تعرف إذا كان اتسجّل جديد. **معدّل الطلبات:** 20/دقيقة. |
| `POST` | `/api/v1/auth/login/organization` | **POST /api/v1/auth/login/organization** — دخول عضو في مؤسسة. **لازم:** `email`, `password`. **شرط مهم:** المؤسسة لازم تكون `verified`. لو `pending` أو `rejected` → الطلب بيترفض حتى لو بيانات الدخول صح. الرد بيرجّع `organizations` كمان. **معدّل الطلبات:** 20/دقيقة. |
| `POST` | `/api/v1/auth/forgot-password` | **POST /api/v1/auth/forgot-password** — يبعت كود OTP لإعادة تعيين كلمة المرور. **لازم:** `email`. **معدّل الطلبات:** 10/دقيقة. |
| `POST` | `/api/v1/auth/forgot-password/resend` | **POST /api/v1/auth/forgot-password/resend** — إعادة إرسال كود إعادة تعيين كلمة المرور. **لازم:** `email`. **معدّل الطلبات:** 10/دقيقة. |
| `POST` | `/api/v1/auth/forgot-password/verify` | **POST /api/v1/auth/forgot-password/verify** — خطوة وسيطة: التأكد إن الكود صحيح **قبل** ما تدخل الباسورد الجديد. **لازم:** `email`, `otp` (6 أرقام). بتفيدك في الواجهة عشان تمنع المستخدم يكمل لو الكود غلط. **معدّل الطلبات:** 10/دقيقة. |
| `POST` | `/api/v1/auth/reset-password` | **POST /api/v1/auth/reset-password** — تعيين كلمة المرور الجديدة بعد التحقق من الكود. **لازم:** `email`, `otp` (6 أرقام), `password`, `password_confirmation` (8 أحرف على الأقل). **معدّل الطلبات:** 10/دقيقة. |
| `POST` | `/api/v1/auth/logout` | **POST /api/v1/auth/logout** — يلغي التوكن الحالي بس. محتاج توكن. بيستخدم `{{token}}` — عدّل المتغير ده للتوكن اللي عايز تسجّله خروج. |
| `POST` | `/api/v1/auth/logout-all` | **POST /api/v1/auth/logout-all** — يلغي **كل** توكنات المستخدم على كل الأجهزة. مفيد لو الحساب اتسرق. **الرد:** 200 وكل التوكنات القديمة بتبقى 401. |

<details><summary>التفاصيل الكاملة</summary>

### `POST` /api/v1/auth/register

**POST /api/v1/auth/register** — تسجيل حساب فردي (طالب).

بيتحقق من الإيميل (لازم يكون جديد)، الباسورد (8 أحرف على الأقل + تأكيد)، والموافقة على الشروط والسياسة.

**لازم:** `name`, `email`, `password`, `password_confirmation`, `terms_accepted`, `privacy_accepted`.

**اختياري:** `phone`, `locale` (`en`/`ar`), `career_status`, `academic_status`.

**ممنوع** ترسل `organization_name` / `organization_type` / `proof_file` — دي بتاعة endpoint المؤسسات.

بعد التسجيل بيوصلك OTP على الإيميل، وبتفعّل الحساب عبر `/auth/verify`.

**معدّل الطلبات:** 10/دقيقة. **الرد:** 201.

### `POST` /api/v1/auth/register/organization

**POST /api/v1/auth/register/organization** — تسجيل حساب مؤسسة (شركة / جامعة / شريك تدريب).

هذا الطلب **multipart/form-data** لأن فيه ملف إثبات (`proof_file`) — jpg/jpeg/png/pdf بحد أقصى 5MB.

**لازم:** `name`, `email`, `password`, `password_confirmation`, `terms_accepted`, `privacy_accepted`, `organization_name`, `organization_type` (`company`/`university`/`training_partner`), `organization_contact_email`, `proof_file`.

**مهم:** المؤسسة بتتسجّل بحالة `pending` — وأعضاؤها **مش هيقدروا يعملوا login** لحد ما الأدمن يوافق عبر `/admin/organizations/{id}/approve`.

**معدّل الطلبات:** 10/دقيقة. **الرد:** 201.

### `POST` /api/v1/auth/verify

**POST /api/v1/auth/verify** — تأكيد الإيميل بالكود المرسل (6 أرقام).

**لازم:** `email`, `otp` (نص بطول 6 بالظبط).

لو الكود غلط أو منتهي → 422. **معدّل الطلبات:** 10/دقيقة.

### `POST` /api/v1/auth/resend-otp

**POST /api/v1/auth/resend-otp** — إعادة إرسال كود تفعيل الإيميل.

**لازم:** `email`. **معدّل الطلبات:** 10/دقيقة.

### `POST` /api/v1/auth/login

**POST /api/v1/auth/login** — دخول حساب فردي (طالب).

**لازم:** `email`, `password`.

التوكن بيتحفظ أوتوماتيك في متغير `learner_token` من سكربت الاختبار.

**أخطاء شائعة:**
- `403` حساب `suspended`.
- `422` إيميل مش متأكد بعد (لازم `/auth/verify`).
- لو الحساب تابع لمؤسسة لسه `pending` → بيترفض برسالة "Your organization is still pending approval".

**معدّل الطلبات:** 20/دقيقة.

### `POST` /api/v1/auth/login

**POST /api/v1/auth/login** — نفس الـ endpoint لكن لحساب صاحب المشروع.

لازم عشان تختبر مسارات صاحب المشروع (قرارات الطلبات + عرض طلبات المشروع).

التوكن بيتحفظ في `owner_token`.

### `POST` /api/v1/auth/login

**POST /api/v1/auth/login** — دخول حساب المرشد.

المرشد **مالوش role** — هويته هي `ProfessionalProfile` بـ `type=mentor` و `verification_status=verified`. التوكن بيتحفظ في `mentor_token`.

### `POST` /api/v1/auth/login

**POST /api/v1/auth/login** — دخول حساب الأدمن.

لازم لمسارات `/api/v1/admin/*` ومراجعة الأدلة. التوكن بيتحفظ في `admin_token`.

### `POST` /api/v1/auth/login/google

**POST /api/v1/auth/login/google** — دخول/تسجيل عبر Google Identity.

**لازم:** `credential` (الـ ID token اللي راجع من Google Sign-In في الفرونت).

**اختياري:** `terms_accepted`, `privacy_accepted` — مطلوبين فعليًا لو ده مستخدم جديد.

الرد فيه `is_new_user` عشان تعرف إذا كان اتسجّل جديد. **معدّل الطلبات:** 20/دقيقة.

### `POST` /api/v1/auth/login/organization

**POST /api/v1/auth/login/organization** — دخول عضو في مؤسسة.

**لازم:** `email`, `password`.

**شرط مهم:** المؤسسة لازم تكون `verified`. لو `pending` أو `rejected` → الطلب بيترفض حتى لو بيانات الدخول صح.

الرد بيرجّع `organizations` كمان. **معدّل الطلبات:** 20/دقيقة.

### `POST` /api/v1/auth/forgot-password

**POST /api/v1/auth/forgot-password** — يبعت كود OTP لإعادة تعيين كلمة المرور.

**لازم:** `email`. **معدّل الطلبات:** 10/دقيقة.

### `POST` /api/v1/auth/forgot-password/resend

**POST /api/v1/auth/forgot-password/resend** — إعادة إرسال كود إعادة تعيين كلمة المرور.

**لازم:** `email`. **معدّل الطلبات:** 10/دقيقة.

### `POST` /api/v1/auth/forgot-password/verify

**POST /api/v1/auth/forgot-password/verify** — خطوة وسيطة: التأكد إن الكود صحيح **قبل** ما تدخل الباسورد الجديد.

**لازم:** `email`, `otp` (6 أرقام).

بتفيدك في الواجهة عشان تمنع المستخدم يكمل لو الكود غلط. **معدّل الطلبات:** 10/دقيقة.

### `POST` /api/v1/auth/reset-password

**POST /api/v1/auth/reset-password** — تعيين كلمة المرور الجديدة بعد التحقق من الكود.

**لازم:** `email`, `otp` (6 أرقام), `password`, `password_confirmation` (8 أحرف على الأقل).

**معدّل الطلبات:** 10/دقيقة.

### `POST` /api/v1/auth/logout

**POST /api/v1/auth/logout** — يلغي التوكن الحالي بس.

محتاج توكن. بيستخدم `{{token}}` — عدّل المتغير ده للتوكن اللي عايز تسجّله خروج.

### `POST` /api/v1/auth/logout-all

**POST /api/v1/auth/logout-all** — يلغي **كل** توكنات المستخدم على كل الأجهزة.

مفيد لو الحساب اتسرق. **الرد:** 200 وكل التوكنات القديمة بتبقى 401.

</details>

## 02 · Profile — الملف الشخصي للطالب

إنشاء/عرض/تعديل `StudentProfile`. **3 endpoints.**

| Method | Endpoint | الشرح |
|---|---|---|
| `POST` | `/api/v1/profile` | **POST /api/v1/profile** — إنشاء `StudentProfile` لأول مرة. **لازم:** `university_name`, `student_university_number`, `specialization`, `academic_level`. **اختياري:** `expected_graduation` (2024–2150), `bio` (2000 حرف), `career_status`, `interests` (مصفوفة), `availability`, `preferred_work_type`, `visibility` (`public` / `organization_only` / `private`). **مهم:** من غير StudentProfile معظم مسارات الطالب بترجّع `422 STUDENT_PROFILE_NOT_FOUND` (المشاريع، الأدلة، مطابقة المشروع). **الصلاحية:** `role:learner`. |
| `GET` | `/api/v1/profile` | **GET /api/v1/profile** — يرجّع `StudentProfile` الخاص بالمستخدم الحالي. **الصلاحية:** `role:learner`. |
| `PUT` | `/api/v1/profile` | **PUT /api/v1/profile** — تعديل جزئي: كل الحقول `sometimes`. فيه حقل زيادة عن الإنشاء: `primary_career_role_id` — لازم يكون مسار مهني **موجود و`status=approved`** (`exists:career_roles,id,status,approved`). ده الحقل اللي بيتغذّى منه محرك التوصيات (target career role). **الصلاحية:** `role:learner`. |

<details><summary>التفاصيل الكاملة</summary>

### `POST` /api/v1/profile

**POST /api/v1/profile** — إنشاء `StudentProfile` لأول مرة.

**لازم:** `university_name`, `student_university_number`, `specialization`, `academic_level`.

**اختياري:** `expected_graduation` (2024–2150), `bio` (2000 حرف), `career_status`, `interests` (مصفوفة), `availability`, `preferred_work_type`, `visibility` (`public` / `organization_only` / `private`).

**مهم:** من غير StudentProfile معظم مسارات الطالب بترجّع `422 STUDENT_PROFILE_NOT_FOUND` (المشاريع، الأدلة، مطابقة المشروع).

**الصلاحية:** `role:learner`.

### `GET` /api/v1/profile

**GET /api/v1/profile** — يرجّع `StudentProfile` الخاص بالمستخدم الحالي.

**الصلاحية:** `role:learner`.

### `PUT` /api/v1/profile

**PUT /api/v1/profile** — تعديل جزئي: كل الحقول `sometimes`.

فيه حقل زيادة عن الإنشاء: `primary_career_role_id` — لازم يكون مسار مهني **موجود و`status=approved`** (`exists:career_roles,id,status,approved`).

ده الحقل اللي بيتغذّى منه محرك التوصيات (target career role).

**الصلاحية:** `role:learner`.

</details>

## 03 · Reference Data — بيانات مرجعية

قوائم الاختيار العامة (دول، جامعات، تخصصات) — **بدون توكن**. **4 endpoints.**

| Method | Endpoint | الشرح |
|---|---|---|
| `GET` | `/api/v1/reference/universities` | **GET /api/v1/reference/universities** — كل الجامعات المفعّلة (`is_active=true`)، مرتبة أبجديًا، ويرجّع `id` و `name` بس. **بدون توكن** — endpoint عام لقوائم الاختيار في التسجيل. |
| `GET` | `/api/v1/reference/specializations` | **GET /api/v1/reference/specializations** — كل التخصصات المفعّلة (`id`, `name`). **بدون توكن.** |
| `GET` | `/api/v1/reference/countries` | **GET /api/v1/reference/countries** — كل الدول مع الاسم العربي والكودين (`id`, `name`, `name_ar`, `iso2`, `iso3`)، مرتبة بالإنجليزي عشان الترتيب يبقى ثابت. **بدون توكن.** بتغذّي قائمة الدولة في التسجيل. |
| `GET` | `/api/v1/reference/countries/{{country_id}}/universities` | **GET /api/v1/reference/countries/{country}/universities** — الجامعات المفعّلة لدولة معينة (قائمة معتمدة على اختيار سابق). **بدون توكن.** لو الدولة مش موجودة → 404. |

<details><summary>التفاصيل الكاملة</summary>

### `GET` /api/v1/reference/universities

**GET /api/v1/reference/universities** — كل الجامعات المفعّلة (`is_active=true`)، مرتبة أبجديًا، ويرجّع `id` و `name` بس.

**بدون توكن** — endpoint عام لقوائم الاختيار في التسجيل.

### `GET` /api/v1/reference/specializations

**GET /api/v1/reference/specializations** — كل التخصصات المفعّلة (`id`, `name`).

**بدون توكن.**

### `GET` /api/v1/reference/countries

**GET /api/v1/reference/countries** — كل الدول مع الاسم العربي والكودين (`id`, `name`, `name_ar`, `iso2`, `iso3`)، مرتبة بالإنجليزي عشان الترتيب يبقى ثابت.

**بدون توكن.** بتغذّي قائمة الدولة في التسجيل.

### `GET` /api/v1/reference/countries/{{country_id}}/universities

**GET /api/v1/reference/countries/{country}/universities** — الجامعات المفعّلة لدولة معينة (قائمة معتمدة على اختيار سابق).

**بدون توكن.** لو الدولة مش موجودة → 404.

</details>

## 04 · Career Roles — المسارات المهنية

المسارات المعتمدة ومهاراتها. **3 endpoints.**

| Method | Endpoint | الشرح |
|---|---|---|
| `GET` | `/api/v1/career-roles` | **GET /api/v1/career-roles** — المسارات المهنية **المعتمدة بس** (`status=approved`)، مع `skills_count` لكل مسار، ومرتبة بالأحدث إصدارًا. **مُصفّحة 15/صفحة.** الرد بيرجّع الـ paginator كامل جوه `data` (`data.data` فيه العناصر). **الصلاحية:** `role:learner`. |
| `GET` | `/api/v1/career-roles/{{career_role_id}}` | **GET /api/v1/career-roles/{id}** — تفاصيل مسار معتمد: `title`, `version`, `effective_date`, `status`. لو المسار مش معتمد أو مش موجود → 404. **الصلاحية:** `role:learner`. |
| `GET` | `/api/v1/career-roles/{{career_role_id}}/skills` | **GET /api/v1/career-roles/{id}/skills** — المهارات المطلوبة للمسار مع المتطلبات السابقة (`prerequisites`) لكل مهارة. لو المسار مالوش مهارات → `422 CAREER_ROLE_NO_SKILLS`. **الصلاحية:** `role:learner`. |

<details><summary>التفاصيل الكاملة</summary>

### `GET` /api/v1/career-roles

**GET /api/v1/career-roles** — المسارات المهنية **المعتمدة بس** (`status=approved`)، مع `skills_count` لكل مسار، ومرتبة بالأحدث إصدارًا. **مُصفّحة 15/صفحة.**

الرد بيرجّع الـ paginator كامل جوه `data` (`data.data` فيه العناصر).

**الصلاحية:** `role:learner`.

### `GET` /api/v1/career-roles/{{career_role_id}}

**GET /api/v1/career-roles/{id}** — تفاصيل مسار معتمد: `title`, `version`, `effective_date`, `status`.

لو المسار مش معتمد أو مش موجود → 404.

**الصلاحية:** `role:learner`.

### `GET` /api/v1/career-roles/{{career_role_id}}/skills

**GET /api/v1/career-roles/{id}/skills** — المهارات المطلوبة للمسار مع المتطلبات السابقة (`prerequisites`) لكل مهارة.

لو المسار مالوش مهارات → `422 CAREER_ROLE_NO_SKILLS`.

**الصلاحية:** `role:learner`.

</details>

## 05 · Projects — المشاريع والاستكشاف

كتالوج المشاريع المفتوح + التفاصيل + مطابقة AI. **3 endpoints.**

| Method | Endpoint | الشرح |
|---|---|---|
| `GET` | `/api/v1/projects` | **GET /api/v1/projects** — كتالوج المشاريع المفتوح للطالب (Discovery). مش pre-assignment: الطالب بيشوف المشاريع المتاحة له حسب **المسار المهني المستهدف + المهارات المطلوبة + مستواه + الصعوبة**. التخصص الأكاديمي **مش** عامل استهداف. **فلاتر اختيارية (query):** - `search` — بحث نصي في `title`/`description`/`objectives` (أقصى 100 حرف) - `type` — `simulation` أو `company_sponsored` - `domain`, `work_mode` — مطابقة تامة - `difficulty` — رقم من 0 لـ 5 - `organization_id` — لازم موجود - `skill_ids[]` — المشروع لازم يطلب **كل** المهارات دي - `minimum_level` — بيشتغل مع `skill_ids` (أدنى مستوى مطلوب) بيرجّع أقصى 50 مشروع مرتبين بالأحدث. لو مفيش StudentProfile → `422 STUDENT_PROFILE_NOT_FOUND`. **الصلاحية:** `role:learner`. |
| `GET` | `/api/v1/projects/{{project_id}}` | **GET /api/v1/projects/{project}** — تفاصيل مشروع واحد بنفس قواعد الوصول/الإتاحة بتاعة الكتالوج. بيرجّع كمان `available_project_roles` — الأدوار اللي الطالب يقدر يختار منها وقت التقديم (الـ `project_role_id`). **الصلاحية:** `role:learner`. **404** لو المشروع مش متاح للطالب. |
| `POST` | `/api/v1/projects/{{project_id}}/match` | **POST /api/v1/projects/{project}/match** — يشغّل مطابقة AI بين الطالب والمشروع. **مفيش body** — المشروع جاي من الـ URL والمستخدم من التوكن. **اللي بيحصل جوه:** 1. `ProjectMatchingSnapshotService` بيتحقق من الإتاحة والصلاحية والأهلية وبيحفظ snapshot. 2. `ProjectMatchingService` بيبني payload ويبعت لـ FastAPI `POST /api/v1/project-matching`. 3. `ProjectMatchingRecommendationService` بيحفظ النتيجة لنفس الطالب في نفس الطلب (مفيش نداء تاني لـ FastAPI). **ملاحظة:** `request_id` العلوي هو بتاعك، و `data.request_id` هو اللي سافر لـ FastAPI — الاتنين مقصودين ومحفوظين. **الصلاحية:** `role:learner`. |

<details><summary>التفاصيل الكاملة</summary>

### `GET` /api/v1/projects?per_page={{per_page}}

**GET /api/v1/projects** — كتالوج المشاريع المفتوح للطالب (Discovery).

مش pre-assignment: الطالب بيشوف المشاريع المتاحة له حسب **المسار المهني المستهدف + المهارات المطلوبة + مستواه + الصعوبة**. التخصص الأكاديمي **مش** عامل استهداف.

**فلاتر اختيارية (query):**
- `search` — بحث نصي في `title`/`description`/`objectives` (أقصى 100 حرف)
- `type` — `simulation` أو `company_sponsored`
- `domain`, `work_mode` — مطابقة تامة
- `difficulty` — رقم من 0 لـ 5
- `organization_id` — لازم موجود
- `skill_ids[]` — المشروع لازم يطلب **كل** المهارات دي
- `minimum_level` — بيشتغل مع `skill_ids` (أدنى مستوى مطلوب)

بيرجّع أقصى 50 مشروع مرتبين بالأحدث. لو مفيش StudentProfile → `422 STUDENT_PROFILE_NOT_FOUND`.

**الصلاحية:** `role:learner`.

### `GET` /api/v1/projects/{{project_id}}

**GET /api/v1/projects/{project}** — تفاصيل مشروع واحد بنفس قواعد الوصول/الإتاحة بتاعة الكتالوج.

بيرجّع كمان `available_project_roles` — الأدوار اللي الطالب يقدر يختار منها وقت التقديم (الـ `project_role_id`).

**الصلاحية:** `role:learner`. **404** لو المشروع مش متاح للطالب.

### `POST` /api/v1/projects/{{project_id}}/match

**POST /api/v1/projects/{project}/match** — يشغّل مطابقة AI بين الطالب والمشروع.

**مفيش body** — المشروع جاي من الـ URL والمستخدم من التوكن.

**اللي بيحصل جوه:**
1. `ProjectMatchingSnapshotService` بيتحقق من الإتاحة والصلاحية والأهلية وبيحفظ snapshot.
2. `ProjectMatchingService` بيبني payload ويبعت لـ FastAPI `POST /api/v1/project-matching`.
3. `ProjectMatchingRecommendationService` بيحفظ النتيجة لنفس الطالب في نفس الطلب (مفيش نداء تاني لـ FastAPI).

**ملاحظة:** `request_id` العلوي هو بتاعك، و `data.request_id` هو اللي سافر لـ FastAPI — الاتنين مقصودين ومحفوظين.

**الصلاحية:** `role:learner`.

</details>

## 06 · Applications — طلبات التقديم

تقديم، متابعة، سحب، قرارات صاحب المشروع. **5 endpoints.**

| Method | Endpoint | الشرح |
|---|---|---|
| `POST` | `/api/v1/projects/{{project_id}}/applications` | **POST /api/v1/projects/{project}/applications** — الطالب بيقدّم على مشروع. **اختياري:** - `project_role_id` — الدور المطلوب (من `available_project_roles`) - `application_data` — **object** مش string (إجابات الطلب) - `recommendation_id` — لو التقديم جاي من توصية - `idempotency_key` — مفتاح تفرّد (أقصى 191 حرف) عشان تعيد المحاولة بأمان **الترتيب داخل الخدمة (مهم):** إعادة تشغيل idempotent → الإتاحة → **المراجع (الدور + التوصية)** → التكرار → السعة → الأهلية → الحفظ. يعني لو بعت `project_role_id` أو `recommendation_id` غلط، هترجّعلك `422` على المرجع حتى لو فيه سبب تاني (تكرار/سعة) صحيح — عشان الخطأ ما يتغطّاش. **الردود:** - `201` أول تقديم - `200` إعادة تشغيل بنفس `idempotency_key` (بيرجّع نفس النتيجة حتى لو المشروع امتلى بعد كده) - `409` تقديم مكرر وانت لسه عندك طلب نشط - `422` مشروع مقفول / مش متاح / مش مطابق للأهلية / مرجع غلط **الصلاحية:** `role:learner`. |
| `GET` | `/api/v1/applications` | **GET /api/v1/applications** — كل الطلبات اللي قدّمها الطالب الحالي. **فلاتر:** - `status` — `submitted` / `shortlisted` / `accepted` / `rejected` / `waitlisted` / `withdrawn` - `per_page` — افتراضي 15، وأقصى 100 (الباقي بيتقصّ تلقائيًا) الرد فيه `meta` (current_page, last_page, per_page, total). **الصلاحية:** `role:learner`. |
| `POST` | `/api/v1/applications/{{application_id}}/withdraw` | **POST /api/v1/applications/{application}/withdraw** — الطالب بيسحب طلبه. **مفيش body.** الطلب بيبقى `withdrawn`، وبكده يقدر يقدّم تاني على نفس المشروع (التفرّد `unique(project_id, applicant_id, active_key)` بيسمح بكده لأن `active_key` بيبقى `NULL` للطلبات المنتهية). السبب بيتسجّل في `audit_events` — مفيش عمود reason منفصل. **الصلاحية:** `role:learner`. **404** لو الطلب مش موجود، **422** لو مش قابل للسحب. |
| `GET` | `/api/v1/projects/{{project_id}}/applications` | **GET /api/v1/projects/{project}/applications** — صاحب المشروع بيشوف كل المتقدمين. **ملاحظة مهمة:** المسار ده **مش** `role:learner` — أي مستخدم مسجّل ممكن يملك مشروع، والملكية بتتحقق جوه الخدمة. لو انت مش المالك → **403**. **فلاتر:** `per_page` (افتراضي 15، أقصى 100). استخدم `{{owner_token}}` عشان تختبره (عدّل الـ Authorization). |
| `PATCH` | `/api/v1/projects/{{project_id}}/applications/{{application_id}}` | **PATCH /api/v1/projects/{project}/applications/{application}** — قبول/رفض/ترشيح/قائمة انتظار. **لازم:** `status` — واحدة من: `shortlisted`, `accepted`, `rejected`, `waitlisted`. **اختياري:** `reason` (أقصى 2000 حرف). **ملاحظات:** - `withdrawn` مش مسموح هنا (دي بتيجي من الطالب). - لو الطلب مش تابع للمشروع المذكور → `404 APPLICATION_PROJECT_MISMATCH`. - `accepted` هي الحالة اللي بتستهلك مقعد من `capacity` (حسب `ProjectCapacityPolicy`). - الملكية بتتحقق جوه الخدمة، مش بالـ middleware. **لازم توكن صاحب المشروع.** |

<details><summary>التفاصيل الكاملة</summary>

### `POST` /api/v1/projects/{{project_id}}/applications

**POST /api/v1/projects/{project}/applications** — الطالب بيقدّم على مشروع.

**اختياري:**
- `project_role_id` — الدور المطلوب (من `available_project_roles`)
- `application_data` — **object** مش string (إجابات الطلب)
- `recommendation_id` — لو التقديم جاي من توصية
- `idempotency_key` — مفتاح تفرّد (أقصى 191 حرف) عشان تعيد المحاولة بأمان

**الترتيب داخل الخدمة (مهم):** إعادة تشغيل idempotent → الإتاحة → **المراجع (الدور + التوصية)** → التكرار → السعة → الأهلية → الحفظ.

يعني لو بعت `project_role_id` أو `recommendation_id` غلط، هترجّعلك `422` على المرجع حتى لو فيه سبب تاني (تكرار/سعة) صحيح — عشان الخطأ ما يتغطّاش.

**الردود:**
- `201` أول تقديم
- `200` إعادة تشغيل بنفس `idempotency_key` (بيرجّع نفس النتيجة حتى لو المشروع امتلى بعد كده)
- `409` تقديم مكرر وانت لسه عندك طلب نشط
- `422` مشروع مقفول / مش متاح / مش مطابق للأهلية / مرجع غلط

**الصلاحية:** `role:learner`.

### `GET` /api/v1/applications?status={{application_status}}&per_page={{per_page}}

**GET /api/v1/applications** — كل الطلبات اللي قدّمها الطالب الحالي.

**فلاتر:**
- `status` — `submitted` / `shortlisted` / `accepted` / `rejected` / `waitlisted` / `withdrawn`
- `per_page` — افتراضي 15، وأقصى 100 (الباقي بيتقصّ تلقائيًا)

الرد فيه `meta` (current_page, last_page, per_page, total).

**الصلاحية:** `role:learner`.

### `POST` /api/v1/applications/{{application_id}}/withdraw

**POST /api/v1/applications/{application}/withdraw** — الطالب بيسحب طلبه.

**مفيش body.** الطلب بيبقى `withdrawn`، وبكده يقدر يقدّم تاني على نفس المشروع (التفرّد `unique(project_id, applicant_id, active_key)` بيسمح بكده لأن `active_key` بيبقى `NULL` للطلبات المنتهية).

السبب بيتسجّل في `audit_events` — مفيش عمود reason منفصل.

**الصلاحية:** `role:learner`. **404** لو الطلب مش موجود، **422** لو مش قابل للسحب.

### `GET` /api/v1/projects/{{project_id}}/applications?per_page={{per_page}}

**GET /api/v1/projects/{project}/applications** — صاحب المشروع بيشوف كل المتقدمين.

**ملاحظة مهمة:** المسار ده **مش** `role:learner` — أي مستخدم مسجّل ممكن يملك مشروع، والملكية بتتحقق جوه الخدمة. لو انت مش المالك → **403**.

**فلاتر:** `per_page` (افتراضي 15، أقصى 100).

استخدم `{{owner_token}}` عشان تختبره (عدّل الـ Authorization).

### `PATCH` /api/v1/projects/{{project_id}}/applications/{{application_id}}

**PATCH /api/v1/projects/{project}/applications/{application}** — قبول/رفض/ترشيح/قائمة انتظار.

**لازم:** `status` — واحدة من: `shortlisted`, `accepted`, `rejected`, `waitlisted`.
**اختياري:** `reason` (أقصى 2000 حرف).

**ملاحظات:**
- `withdrawn` مش مسموح هنا (دي بتيجي من الطالب).
- لو الطلب مش تابع للمشروع المذكور → `404 APPLICATION_PROJECT_MISMATCH`.
- `accepted` هي الحالة اللي بتستهلك مقعد من `capacity` (حسب `ProjectCapacityPolicy`).
- الملكية بتتحقق جوه الخدمة، مش بالـ middleware.

**لازم توكن صاحب المشروع.**

</details>

## 07 · Recommendations — التوصيات

توصيات المشاريع + التغذية الراجعة. **2 endpoints.**

| Method | Endpoint | الشرح |
|---|---|---|
| `GET` | `/api/v1/recommendations` | **GET /api/v1/recommendations** — توصيات المشاريع المولّدة للمستخدم الحالي (`type=project`)، مرتبة بالأحدث. **فلاتر:** `per_page` (افتراضي 15، أقصى 100). الرد فيه `meta`. كل توصية جواها بيانات المشروع كاملة (المؤسسة + المهارات المطلوبة + شروط الأهلية) عشان الواجهة تعرضها من غير نداءات إضافية. **الصلاحية:** `role:learner`. |
| `POST` | `/api/v1/recommendations/{{recommendation_id}}/feedback` | **POST /api/v1/recommendations/{recommendation}/feedback** — الطالب بيقول رأيه في التوصية. **لازم:** `event_type` — `save` أو `hide` أو `decline`. **اختياري:** `reason` (أقصى 2000 حرف). **ملاحظة:** `decline` بتتخزّن في قاعدة البيانات كـ `reject` — الاسم مختلف عن اللي بتبعتّه. أي قيمة تانية → `422 RECOMMENDATION_FEEDBACK_UNSUPPORTED` (والرد بيقولك القيم المدعومة). **مهم:** التوصية لازم تكون بتاعة نفس المستخدم — لو بتاعة حد تاني → **404**. **الصلاحية:** `role:learner`. |

<details><summary>التفاصيل الكاملة</summary>

### `GET` /api/v1/recommendations?per_page={{per_page}}

**GET /api/v1/recommendations** — توصيات المشاريع المولّدة للمستخدم الحالي (`type=project`)، مرتبة بالأحدث.

**فلاتر:** `per_page` (افتراضي 15، أقصى 100). الرد فيه `meta`.

كل توصية جواها بيانات المشروع كاملة (المؤسسة + المهارات المطلوبة + شروط الأهلية) عشان الواجهة تعرضها من غير نداءات إضافية.

**الصلاحية:** `role:learner`.

### `POST` /api/v1/recommendations/{{recommendation_id}}/feedback

**POST /api/v1/recommendations/{recommendation}/feedback** — الطالب بيقول رأيه في التوصية.

**لازم:** `event_type` — `save` أو `hide` أو `decline`.
**اختياري:** `reason` (أقصى 2000 حرف).

**ملاحظة:** `decline` بتتخزّن في قاعدة البيانات كـ `reject` — الاسم مختلف عن اللي بتبعتّه.
أي قيمة تانية → `422 RECOMMENDATION_FEEDBACK_UNSUPPORTED` (والرد بيقولك القيم المدعومة).

**مهم:** التوصية لازم تكون بتاعة نفس المستخدم — لو بتاعة حد تاني → **404**.

**الصلاحية:** `role:learner`.

</details>

## 08 · Readiness & Intelligence — الجاهزية والذكاء

الجاهزية المركبة، فجوة المهارات، ومطابقة المهارات. **5 endpoints.**

| Method | Endpoint | الشرح |
|---|---|---|
| `POST` | `/api/v1/readiness/calculate` | **POST /api/v1/readiness/calculate** — يحسب **Composite Readiness** للطالب. **اختياري:** `career_role_id` (أكبر من صفر). لو مش بعتّه بيستخدم `primary_career_role_id` من الملف الشخصي. **اللي بيحصل:** Laravel بيحسب المكوّنات المحلية (الخبرة العملية، موثوقية التقييم، اكتمال الملف) + بينده FastAPI `/skill-match` (خوارزمية `skill-match-v1`)، والأوزان `0.65/0.20/0.10/0.05` مع Critical Skill Cap `69.0`. **النطاقات:** foundation_needed 0–39.99 · developing 40–59.99 · moderate_readiness 60–74.99 · near_ready 75–89.99 · highly_ready 90–100. كل عملية حساب بتكتب صف في `decision_snapshots` الأول (pending → succeeded/failed). لو مفيش configuration نشِط → `422` صريح، ومفيش أي أرقام مُختلقة. **الصلاحية:** `role:learner`. |
| `GET` | `/api/v1/readiness/latest` | **GET /api/v1/readiness/latest** — آخر نتيجة جاهزية محفوظة (مفيش حساب جديد). مفيد للواجهة عشان تعرض آخر رقم من غير ما تشغّل الخوارزمية تاني. **الصلاحية:** `role:learner`. |
| `POST` | `/api/v1/intelligence/calculate` | **POST /api/v1/intelligence/calculate** — يحسب **Skill Gap Intelligence**. **اختياري:** `career_role_id`. Laravel بينده FastAPI `/skill-gap` (خوارزمية `skill-gap-v1`) عبر `IntelligenceClient`. **تحذير:** متخلطش بينه وبين الجاهزية — دي تدفقات مختلفة تمامًا (Readiness / Intelligence / Baseline) لكل واحدة نسخة خوارزمية مستقلة. **الصلاحية:** `role:learner`. |
| `GET` | `/api/v1/intelligence/latest` | **GET /api/v1/intelligence/latest** — آخر تحليل فجوة مهارات محفوظ. **الصلاحية:** `role:learner`. |
| `POST` | `/api/v1/skill-match` | **POST /api/v1/skill-match** — واجهة مباشرة لمحرك مطابقة المهارات (بدون تجميع الجاهزية). **لازم كل دي:** `student_profile_id`, `career_role_id`, `career_role_version`, `user_id`, `target_role`, و `skills` (مصفوفة عنصر واحد على الأقل). **كل عنصر في `skills`:** `skill_id`, `skill_name`, `current_level` (0–5), `required_level` (0–5), `importance_weight` (>0 وأقصى 1), `is_critical` (boolean). **الرد على الخطأ:** `422` بشكل `{"detail": {"code", "message"}}` — شكل مختلف عن باقي الـ API، خد بالك. **الصلاحية:** `role:learner`. |

<details><summary>التفاصيل الكاملة</summary>

### `POST` /api/v1/readiness/calculate

**POST /api/v1/readiness/calculate** — يحسب **Composite Readiness** للطالب.

**اختياري:** `career_role_id` (أكبر من صفر). لو مش بعتّه بيستخدم `primary_career_role_id` من الملف الشخصي.

**اللي بيحصل:** Laravel بيحسب المكوّنات المحلية (الخبرة العملية، موثوقية التقييم، اكتمال الملف) + بينده FastAPI `/skill-match` (خوارزمية `skill-match-v1`)، والأوزان `0.65/0.20/0.10/0.05` مع Critical Skill Cap `69.0`.

**النطاقات:** foundation_needed 0–39.99 · developing 40–59.99 · moderate_readiness 60–74.99 · near_ready 75–89.99 · highly_ready 90–100.

كل عملية حساب بتكتب صف في `decision_snapshots` الأول (pending → succeeded/failed). لو مفيش configuration نشِط → `422` صريح، ومفيش أي أرقام مُختلقة.

**الصلاحية:** `role:learner`.

### `GET` /api/v1/readiness/latest

**GET /api/v1/readiness/latest** — آخر نتيجة جاهزية محفوظة (مفيش حساب جديد).

مفيد للواجهة عشان تعرض آخر رقم من غير ما تشغّل الخوارزمية تاني.

**الصلاحية:** `role:learner`.

### `POST` /api/v1/intelligence/calculate

**POST /api/v1/intelligence/calculate** — يحسب **Skill Gap Intelligence**.

**اختياري:** `career_role_id`.

Laravel بينده FastAPI `/skill-gap` (خوارزمية `skill-gap-v1`) عبر `IntelligenceClient`.

**تحذير:** متخلطش بينه وبين الجاهزية — دي تدفقات مختلفة تمامًا (Readiness / Intelligence / Baseline) لكل واحدة نسخة خوارزمية مستقلة.

**الصلاحية:** `role:learner`.

### `GET` /api/v1/intelligence/latest

**GET /api/v1/intelligence/latest** — آخر تحليل فجوة مهارات محفوظ.

**الصلاحية:** `role:learner`.

### `POST` /api/v1/skill-match

**POST /api/v1/skill-match** — واجهة مباشرة لمحرك مطابقة المهارات (بدون تجميع الجاهزية).

**لازم كل دي:** `student_profile_id`, `career_role_id`, `career_role_version`, `user_id`, `target_role`, و `skills` (مصفوفة عنصر واحد على الأقل).

**كل عنصر في `skills`:** `skill_id`, `skill_name`, `current_level` (0–5), `required_level` (0–5), `importance_weight` (>0 وأقصى 1), `is_critical` (boolean).

**الرد على الخطأ:** `422` بشكل `{"detail": {"code", "message"}}` — شكل مختلف عن باقي الـ API، خد بالك.

**الصلاحية:** `role:learner`.

</details>

## 09 · Baseline Assessments — التقييم الأساسي

بدء/متابعة/تسليم التقييم الأساسي الديناميكي. **4 endpoints.**

| Method | Endpoint | الشرح |
|---|---|---|
| `POST` | `/api/v1/baseline-assessments` | **POST /api/v1/baseline-assessments** — يبدأ جلسة تقييم أساسي للطالب. **لازم:** `career_role_id` — لازم يكون موجود و `status=approved`. بيرجّع `id` بتاع الجلسة اللي بتستخدمه في باقي المسارات. **الصلاحية:** `role:learner`. |
| `GET` | `/api/v1/baseline-assessments/{{assessment_id}}` | **GET /api/v1/baseline-assessments/{assessment}** — تفاصيل الجلسة والأسئلة. **الصلاحية:** `role:learner`. |
| `PATCH` | `/api/v1/baseline-assessments/{{assessment_id}}` | **PATCH /api/v1/baseline-assessments/{assessment}** — حفظ تقدّم مؤقت قبل التسليم النهائي. **اختياري:** `progress` (object), `responses` (object). مفيد لو الطالب قفل الصفحة ورجع. **الصلاحية:** `role:learner`. |
| `POST` | `/api/v1/baseline-assessments/{{assessment_id}}/submit` | **POST /api/v1/baseline-assessments/{assessment}/submit** — التسليم النهائي والتقييم. **لازم:** `responses` — **مصفوفة** (list) فيها عنصر واحد على الأقل، وكل عنصر فيه `question_id` (نص، أقصى 100) و `answer` (أي نوع). بعد التسليم Laravel بينده FastAPI `/baseline` (خوارزمية `baseline-v1.0`) عبر `BaselineDataScienceClient`، وبيحدّث مستويات المهارات. **الصلاحية:** `role:learner`. |

<details><summary>التفاصيل الكاملة</summary>

### `POST` /api/v1/baseline-assessments

**POST /api/v1/baseline-assessments** — يبدأ جلسة تقييم أساسي للطالب.

**لازم:** `career_role_id` — لازم يكون موجود و `status=approved`.

بيرجّع `id` بتاع الجلسة اللي بتستخدمه في باقي المسارات. **الصلاحية:** `role:learner`.

### `GET` /api/v1/baseline-assessments/{{assessment_id}}

**GET /api/v1/baseline-assessments/{assessment}** — تفاصيل الجلسة والأسئلة.

**الصلاحية:** `role:learner`.

### `PATCH` /api/v1/baseline-assessments/{{assessment_id}}

**PATCH /api/v1/baseline-assessments/{assessment}** — حفظ تقدّم مؤقت قبل التسليم النهائي.

**اختياري:** `progress` (object), `responses` (object).

مفيد لو الطالب قفل الصفحة ورجع. **الصلاحية:** `role:learner`.

### `POST` /api/v1/baseline-assessments/{{assessment_id}}/submit

**POST /api/v1/baseline-assessments/{assessment}/submit** — التسليم النهائي والتقييم.

**لازم:** `responses` — **مصفوفة** (list) فيها عنصر واحد على الأقل، وكل عنصر فيه `question_id` (نص، أقصى 100) و `answer` (أي نوع).

بعد التسليم Laravel بينده FastAPI `/baseline` (خوارزمية `baseline-v1.0`) عبر `BaselineDataScienceClient`، وبيحدّث مستويات المهارات.

**الصلاحية:** `role:learner`.

</details>

## 10 · Skills — المهارات

مصفوفة المهارات والتصنيف المرجعي. **4 endpoints.**

| Method | Endpoint | الشرح |
|---|---|---|
| `GET` | `/api/v1/skills/matrix` | **GET /api/v1/skills/matrix** — مهارات المستخدم الحالي. **فلاتر اختيارية:** - `career_role_id` — يرجّع بس المهارات المرتبطة بالمسار ده - `learner_id` — **للأدمن بس**: يقدر يقرا مصفوفة طالب تاني. لو مش أدمن الحقل ده بيتتجاهل وهو دايمًا بياخد مصفوفته هو. **الصلاحية:** أي مستخدم مسجّل ونشِط. |
| `POST` | `/api/v1/skills/matrix` | **POST /api/v1/skills/matrix** — إضافة أو تحديث مهارة للطالب (upsert على `learner_id`+`skill_id`). **لازم:** `learner_id`, `skill_id`, `level` (0–5), `confidence_score` (0–100). **اختياري:** `source_type`, `algorithm_version`, `configuration_version`, `source_contributions` (object مش string). **صلاحيات:** الطالب بيكتب صفوفه هو بس — لو بعت `learner_id` بتاع حد تاني وإنت مش أدمن → **403**. الأدمن مسموح له. **الرد:** 201. |
| `PUT` | `/api/v1/skills/matrix/{{learner_skill_id}}` | **PUT /api/v1/skills/matrix/{id}** — تعديل صف مهارة موجود. **اختياري:** `level` (0–5), `confidence_score` (0–100), `source_type`, `algorithm_version`, `configuration_version`, `source_contributions`. **مهم:** `calculated_at` **مش** بيُقبل من العميل — خادم فقط (منعًا لتزوير وقت الحساب). لو الصف مش بتاعك وانت مش أدمن → **404** (مش 403، عشان ما نأكدش وجود الـ id). |
| `GET` | `/api/v1/skills/taxonomy` | **GET /api/v1/skills/taxonomy** — شجرة/تصنيف المهارات المرجعي. **الصلاحية:** أي مستخدم مسجّل ونشِط. |

<details><summary>التفاصيل الكاملة</summary>

### `GET` /api/v1/skills/matrix?career_role_id={{career_role_id}}&learner_id={{learner_user_id}}

**GET /api/v1/skills/matrix** — مهارات المستخدم الحالي.

**فلاتر اختيارية:**
- `career_role_id` — يرجّع بس المهارات المرتبطة بالمسار ده
- `learner_id` — **للأدمن بس**: يقدر يقرا مصفوفة طالب تاني. لو مش أدمن الحقل ده بيتتجاهل وهو دايمًا بياخد مصفوفته هو.

**الصلاحية:** أي مستخدم مسجّل ونشِط.

### `POST` /api/v1/skills/matrix

**POST /api/v1/skills/matrix** — إضافة أو تحديث مهارة للطالب (upsert على `learner_id`+`skill_id`).

**لازم:** `learner_id`, `skill_id`, `level` (0–5), `confidence_score` (0–100).
**اختياري:** `source_type`, `algorithm_version`, `configuration_version`, `source_contributions` (object مش string).

**صلاحيات:** الطالب بيكتب صفوفه هو بس — لو بعت `learner_id` بتاع حد تاني وإنت مش أدمن → **403**. الأدمن مسموح له.

**الرد:** 201.

### `PUT` /api/v1/skills/matrix/{{learner_skill_id}}

**PUT /api/v1/skills/matrix/{id}** — تعديل صف مهارة موجود.

**اختياري:** `level` (0–5), `confidence_score` (0–100), `source_type`, `algorithm_version`, `configuration_version`, `source_contributions`.

**مهم:** `calculated_at` **مش** بيُقبل من العميل — خادم فقط (منعًا لتزوير وقت الحساب).

لو الصف مش بتاعك وانت مش أدمن → **404** (مش 403، عشان ما نأكدش وجود الـ id).

### `GET` /api/v1/skills/taxonomy

**GET /api/v1/skills/taxonomy** — شجرة/تصنيف المهارات المرجعي.

**الصلاحية:** أي مستخدم مسجّل ونشِط.

</details>

## 11 · Evidence — الأدلة

رفع الأدلة ومراجعتها من الأدمن. **4 endpoints.**

| Method | Endpoint | الشرح |
|---|---|---|
| `GET` | `/api/v1/evidence` | **GET /api/v1/evidence** — الأدلة **المعتمدة بس** (`verification_status=verified`) للطالب الحالي. لو مفيش StudentProfile → `422`. **الصلاحية:** `role:learner`. |
| `POST` | `/api/v1/evidence` | **POST /api/v1/evidence** — رفع دليل مهارة. **multipart/form-data** لو بترفع ملف. **لازم:** `skill_id` — لازم يكون موجود و `status=active`. **ولازم واحد من الاتنين:** `evidence_url` (رابط صحيح) أو `evidence_file` (أقصى 10MB). **اختياري:** `description` (500), `evidence_date` (تاريخ، افتراضيًا النهاردة). الدليل بيتسجّل `pending` لحد ما أدمن يراجعه. لو نفس الدليل مرفوع قبل كده (نفس الطالب + المهارة + المصدر + المرجع) → **409**. **الصلاحية:** `role:learner`. |
| `GET` | `/api/v1/evidence/{{evidence_id}}` | **GET /api/v1/evidence/{id}** — تفاصيل دليل واحد. **الصلاحية:** أي مستخدم مسجّل ونشِط (الـ middleware على المسار ده `account.active` بس). |
| `PUT` | `/api/v1/evidence/{{evidence_id}}/review` | **PUT /api/v1/evidence/{id}/review** — الأدمن يعتمد أو يرفض الدليل. **لازم:** `verification_status` — `verified` أو `rejected`. **اختياري:** `reviewer_notes` (أقصى 500). الاعتماد بيحدّث مستوى المهارة وحساب الثقة. **الصلاحية:** `role:admin` — **لازم توكن أدمن** (عدّل Authorization لـ `{{admin_token}}`). |

<details><summary>التفاصيل الكاملة</summary>

### `GET` /api/v1/evidence

**GET /api/v1/evidence** — الأدلة **المعتمدة بس** (`verification_status=verified`) للطالب الحالي.

لو مفيش StudentProfile → `422`.

**الصلاحية:** `role:learner`.

### `POST` /api/v1/evidence

**POST /api/v1/evidence** — رفع دليل مهارة. **multipart/form-data** لو بترفع ملف.

**لازم:** `skill_id` — لازم يكون موجود و `status=active`.
**ولازم واحد من الاتنين:** `evidence_url` (رابط صحيح) أو `evidence_file` (أقصى 10MB).
**اختياري:** `description` (500), `evidence_date` (تاريخ، افتراضيًا النهاردة).

الدليل بيتسجّل `pending` لحد ما أدمن يراجعه. لو نفس الدليل مرفوع قبل كده (نفس الطالب + المهارة + المصدر + المرجع) → **409**.

**الصلاحية:** `role:learner`.

### `GET` /api/v1/evidence/{{evidence_id}}

**GET /api/v1/evidence/{id}** — تفاصيل دليل واحد.

**الصلاحية:** أي مستخدم مسجّل ونشِط (الـ middleware على المسار ده `account.active` بس).

### `PUT` /api/v1/evidence/{{evidence_id}}/review

**PUT /api/v1/evidence/{id}/review** — الأدمن يعتمد أو يرفض الدليل.

**لازم:** `verification_status` — `verified` أو `rejected`.
**اختياري:** `reviewer_notes` (أقصى 500).

الاعتماد بيحدّث مستوى المهارة وحساب الثقة.

**الصلاحية:** `role:admin` — **لازم توكن أدمن** (عدّل Authorization لـ `{{admin_token}}`).

</details>

## 12 · Assistant — المساعد الذكي

أسئلة المساعد المقيّدة بالنطاق + الإبلاغ. **2 endpoints.**

| Method | Endpoint | الشرح |
|---|---|---|
| `POST` | `/api/v1/assistant/ask` | **POST /api/v1/assistant/ask** — المساعد الذكي، **مقيّد بنطاق محدد**. **لازم:** `intent` — واحدة من الستة دي بالظبط: `explain_readiness`, `explain_skill_gap`, `explain_roadmap`, `explain_next_best_action`, `explain_project_recommendation`, `project_bounded_help`. **ولازم:** `question` (من 3 لـ 2000 حرف). **اختياري:** `recommendation_id`, `project_id` (أعداد أكبر من صفر). **خطأ مميّز:** لو الـ intent بره النطاق → `422` بكود `ASSISTANT_INTENT_NOT_ALLOWED` (مش `VALIDATION_ERROR`)، عشان الواجهة تفرّق بينهم. **الصلاحية:** `role:learner`. |
| `PUT` | `/api/v1/assistant/interactions/{{assistant_interaction_id}}/report` | **PUT /api/v1/assistant/interactions/{interaction}/report** — الإبلاغ عن رد مسيء/غلط. **لازم:** `report_status` — واحدة من: `unsafe`, `irrelevant`, `unfair`, `incorrect`. **اختياري:** `report_reason` (أقصى 1000 حرف). **الصلاحية:** `role:learner`. |

<details><summary>التفاصيل الكاملة</summary>

### `POST` /api/v1/assistant/ask

**POST /api/v1/assistant/ask** — المساعد الذكي، **مقيّد بنطاق محدد**.

**لازم:** `intent` — واحدة من الستة دي بالظبط:
`explain_readiness`, `explain_skill_gap`, `explain_roadmap`, `explain_next_best_action`, `explain_project_recommendation`, `project_bounded_help`.

**ولازم:** `question` (من 3 لـ 2000 حرف).
**اختياري:** `recommendation_id`, `project_id` (أعداد أكبر من صفر).

**خطأ مميّز:** لو الـ intent بره النطاق → `422` بكود `ASSISTANT_INTENT_NOT_ALLOWED` (مش `VALIDATION_ERROR`)، عشان الواجهة تفرّق بينهم.

**الصلاحية:** `role:learner`.

### `PUT` /api/v1/assistant/interactions/{{assistant_interaction_id}}/report

**PUT /api/v1/assistant/interactions/{interaction}/report** — الإبلاغ عن رد مسيء/غلط.

**لازم:** `report_status` — واحدة من: `unsafe`, `irrelevant`, `unfair`, `incorrect`.
**اختياري:** `report_reason` (أقصى 1000 حرف).

**الصلاحية:** `role:learner`.

</details>

## 13 · Mentor — المرشد

الطلاب، الروابط، وملخصات الطلاب. **5 endpoints.**

| Method | Endpoint | الشرح |
|---|---|---|
| `GET` | `/api/v1/mentor/students` | **GET /api/v1/mentor/students** — كل الطلاب المرتبطين بالمرشد الحالي. **الصلاحية:** `mentor` — الهوية هي `ProfessionalProfile` بـ `type=mentor` و `verification_status=verified`. **مفيش role اسمه mentor.** **لازم توكن مرشد** (عدّل Authorization لـ `{{mentor_token}}`). |
| `GET` | `/api/v1/mentor/students/{{student_id}}` | **GET /api/v1/mentor/students/{student}** — ملخص تقدّم طالب مرتبط بالمرشد. لو الطالب مش مرتبط بالمرشد → مرفوض. **الصلاحية:** مرشد. |
| `POST` | `/api/v1/mentor/connections` | **POST /api/v1/mentor/connections** — المرشد يربط طالب. **لازم:** `student_id` — لازم يكون مستخدم موجود. **اختياري:** `project_id` (لازم يكون مشروع موجود), `initiated_by` (`mentor` / `student` / `admin`). التفرّد على (mentor + student + project) — نفس الربط ما يتكرّرش. **الصلاحية:** مرشد. |
| `GET` | `/api/v1/mentor/connections` | **GET /api/v1/mentor/connections** — كل روابط المرشد (مع الطلاب). **الصلاحية:** مرشد. الرد فيه `meta` للترقيم. |
| `PATCH` | `/api/v1/mentor/connections/{{connection_id}}` | **PATCH /api/v1/mentor/connections/{connection}** — تغيير حالة الربط. **لازم:** `status` — `active` / `disconnected` / `archived`. **اختياري:** `reason` (أقصى 500 حرف). **الصلاحية:** مرشد. |

<details><summary>التفاصيل الكاملة</summary>

### `GET` /api/v1/mentor/students

**GET /api/v1/mentor/students** — كل الطلاب المرتبطين بالمرشد الحالي.

**الصلاحية:** `mentor` — الهوية هي `ProfessionalProfile` بـ `type=mentor` و `verification_status=verified`. **مفيش role اسمه mentor.**

**لازم توكن مرشد** (عدّل Authorization لـ `{{mentor_token}}`).

### `GET` /api/v1/mentor/students/{{student_id}}

**GET /api/v1/mentor/students/{student}** — ملخص تقدّم طالب مرتبط بالمرشد.

لو الطالب مش مرتبط بالمرشد → مرفوض.

**الصلاحية:** مرشد.

### `POST` /api/v1/mentor/connections

**POST /api/v1/mentor/connections** — المرشد يربط طالب.

**لازم:** `student_id` — لازم يكون مستخدم موجود.
**اختياري:** `project_id` (لازم يكون مشروع موجود), `initiated_by` (`mentor` / `student` / `admin`).

التفرّد على (mentor + student + project) — نفس الربط ما يتكرّرش.

**الصلاحية:** مرشد.

### `GET` /api/v1/mentor/connections

**GET /api/v1/mentor/connections** — كل روابط المرشد (مع الطلاب).

**الصلاحية:** مرشد. الرد فيه `meta` للترقيم.

### `PATCH` /api/v1/mentor/connections/{{connection_id}}

**PATCH /api/v1/mentor/connections/{connection}** — تغيير حالة الربط.

**لازم:** `status` — `active` / `disconnected` / `archived`.
**اختياري:** `reason` (أقصى 500 حرف).

**الصلاحية:** مرشد.

</details>

## 14 · Conversations & Chatbot — المحادثات

المحادثات، الرسائل، والشات بوت. **9 endpoints.**

| Method | Endpoint | الشرح |
|---|---|---|
| `POST` | `/api/v1/connections/{{connection_id}}/conversations` | **POST /api/v1/connections/{connection}/conversations** — فتح محادثة جوه رابط مرشد↔طالب. **مفيش body مطلوب.** لازم يكون المستخدم طرف في الرابط ده. **الصلاحية:** مستخدم مسجّل ونشِط. **الرد:** 201. |
| `GET` | `/api/v1/conversations` | **GET /api/v1/conversations** — كل محادثات المستخدم الحالي. **الترقيم:** `communication.conversations_per_page` (افتراضي 20). الرد فيه `meta`. |
| `GET` | `/api/v1/conversations/{{conversation_id}}` | **GET /api/v1/conversations/{conversation}** — تفاصيل محادثة بتحقق صلاحية (لازم تكون طرف فيها). **404/403** لو مش من محادثاتك. |
| `POST` | `/api/v1/conversations/{{conversation_id}}/messages` | **POST /api/v1/conversations/{conversation}/messages** — إرسال رسالة نصية. **لازم:** `body` — أقصى طول من `communication.message_max_length` (افتراضي 5000). **اختياري:** `message_type` (`text` / `system` / `chatbot`), `metadata` (object). **الصلاحية:** لازم تكون طرف في المحادثة. |
| `GET` | `/api/v1/conversations/{{conversation_id}}/messages` | **GET /api/v1/conversations/{conversation}/messages** — رسائل المحادثة. **الترقيم:** `communication.messages_per_page` (افتراضي 50). الرد فيه `meta`. |
| `POST` | `/api/v1/conversations/{{conversation_id}}/read` | **POST /api/v1/conversations/{conversation}/read** — تعليم رسائل المحادثة كمقروءة. **مفيش body.** بيصفّر عدّاد غير المقروء. |
| `GET` | `/api/v1/conversations/{{conversation_id}}/status` | **GET /api/v1/conversations/{conversation}/status** — حالة المحادثة وعدّاد غير المقروء. endpoint خفيف للواجهة عشان تحدّث الشارة. |
| `POST` | `/api/v1/conversations/{{conversation_id}}/chatbot/messages` | **POST /api/v1/conversations/{conversation}/chatbot/messages** — إرسال رسالة للشات بوت. **لازم:** `body` (أقصى `communication.message_max_length`). **اختياري:** `metadata`, `source` (أقصى 100 حرف). **مهم:** الـ endpoint **بيفرض** `message_type=chatbot` — ولو بعت `message_type` من عندك بيترفض. عشان كده ما تحطش الحقل ده في الـ body. **الصلاحية:** لازم تكون طرف في المحادثة. |
| `GET` | `/api/v1/conversations/{{conversation_id}}/chatbot/messages` | **GET /api/v1/conversations/{conversation}/chatbot/messages** — رسائل الشات بوت في المحادثة. الرد فيه `meta` للترقيم. |

<details><summary>التفاصيل الكاملة</summary>

### `POST` /api/v1/connections/{{connection_id}}/conversations

**POST /api/v1/connections/{connection}/conversations** — فتح محادثة جوه رابط مرشد↔طالب.

**مفيش body مطلوب.** لازم يكون المستخدم طرف في الرابط ده.

**الصلاحية:** مستخدم مسجّل ونشِط. **الرد:** 201.

### `GET` /api/v1/conversations

**GET /api/v1/conversations** — كل محادثات المستخدم الحالي.

**الترقيم:** `communication.conversations_per_page` (افتراضي 20). الرد فيه `meta`.

### `GET` /api/v1/conversations/{{conversation_id}}

**GET /api/v1/conversations/{conversation}** — تفاصيل محادثة بتحقق صلاحية (لازم تكون طرف فيها).

**404/403** لو مش من محادثاتك.

### `POST` /api/v1/conversations/{{conversation_id}}/messages

**POST /api/v1/conversations/{conversation}/messages** — إرسال رسالة نصية.

**لازم:** `body` — أقصى طول من `communication.message_max_length` (افتراضي 5000).
**اختياري:** `message_type` (`text` / `system` / `chatbot`), `metadata` (object).

**الصلاحية:** لازم تكون طرف في المحادثة.

### `GET` /api/v1/conversations/{{conversation_id}}/messages

**GET /api/v1/conversations/{conversation}/messages** — رسائل المحادثة.

**الترقيم:** `communication.messages_per_page` (افتراضي 50). الرد فيه `meta`.

### `POST` /api/v1/conversations/{{conversation_id}}/read

**POST /api/v1/conversations/{conversation}/read** — تعليم رسائل المحادثة كمقروءة.

**مفيش body.** بيصفّر عدّاد غير المقروء.

### `GET` /api/v1/conversations/{{conversation_id}}/status

**GET /api/v1/conversations/{conversation}/status** — حالة المحادثة وعدّاد غير المقروء.

endpoint خفيف للواجهة عشان تحدّث الشارة.

### `POST` /api/v1/conversations/{{conversation_id}}/chatbot/messages

**POST /api/v1/conversations/{conversation}/chatbot/messages** — إرسال رسالة للشات بوت.

**لازم:** `body` (أقصى `communication.message_max_length`).
**اختياري:** `metadata`, `source` (أقصى 100 حرف).

**مهم:** الـ endpoint **بيفرض** `message_type=chatbot` — ولو بعت `message_type` من عندك بيترفض. عشان كده ما تحطش الحقل ده في الـ body.

**الصلاحية:** لازم تكون طرف في المحادثة.

### `GET` /api/v1/conversations/{{conversation_id}}/chatbot/messages

**GET /api/v1/conversations/{conversation}/chatbot/messages** — رسائل الشات بوت في المحادثة.

الرد فيه `meta` للترقيم.

</details>

## 15 · Notifications — الإشعارات

القائمة، العدّاد، القراءة، والتفضيلات. **6 endpoints.**

| Method | Endpoint | الشرح |
|---|---|---|
| `GET` | `/api/v1/notifications` | **GET /api/v1/notifications** — إشعارات المستخدم الحالي. **فلاتر:** `category` (نص)، `unread_only` (`1`/`true`). الرد فيه `meta` وفيه `unread_count` جاهز — يعني مش محتاج نداء تاني للشارة. **الصلاحية:** أي مستخدم مسجّل ونشِط. |
| `GET` | `/api/v1/notifications/unread-count` | **GET /api/v1/notifications/unread-count** — رقم واحد بس لغير المقروء. endpoint خفيف مخصص للـ polling من الفرونت. |
| `POST` | `/api/v1/notifications/{{notification_id}}/read` | **POST /api/v1/notifications/{notification}/read** — تعليم إشعار واحد كمقروء. **مفيش body.** الإشعار لازم يكون بتاع المستخدم الحالي. |
| `POST` | `/api/v1/notifications/read-all` | **POST /api/v1/notifications/read-all** — تعليم كل إشعارات المستخدم كمقروءة. **مفيش body.** |
| `GET` | `/api/v1/notifications/preferences` | **GET /api/v1/notifications/preferences** — تفضيلات الإشعارات. **المنطق:** النظام opt-out — غياب الصف معناه **مُفعّل**. يعني اللي مش ظاهر في القائمة يبقى شغّال. |
| `PUT` | `/api/v1/notifications/preferences` | **PUT /api/v1/notifications/preferences** — تشغيل/إطفاء فئة على قناة. **لازم:** `category` (نص، أقصى 100)، `channel` (`in_app` أو `email`)، `enabled` (boolean). |

<details><summary>التفاصيل الكاملة</summary>

### `GET` /api/v1/notifications?unread_only={{unread_only}}&category={{notification_category}}

**GET /api/v1/notifications** — إشعارات المستخدم الحالي.

**فلاتر:** `category` (نص)، `unread_only` (`1`/`true`).

الرد فيه `meta` وفيه `unread_count` جاهز — يعني مش محتاج نداء تاني للشارة.

**الصلاحية:** أي مستخدم مسجّل ونشِط.

### `GET` /api/v1/notifications/unread-count

**GET /api/v1/notifications/unread-count** — رقم واحد بس لغير المقروء.

endpoint خفيف مخصص للـ polling من الفرونت.

### `POST` /api/v1/notifications/{{notification_id}}/read

**POST /api/v1/notifications/{notification}/read** — تعليم إشعار واحد كمقروء.

**مفيش body.** الإشعار لازم يكون بتاع المستخدم الحالي.

### `POST` /api/v1/notifications/read-all

**POST /api/v1/notifications/read-all** — تعليم كل إشعارات المستخدم كمقروءة.

**مفيش body.**

### `GET` /api/v1/notifications/preferences

**GET /api/v1/notifications/preferences** — تفضيلات الإشعارات.

**المنطق:** النظام opt-out — غياب الصف معناه **مُفعّل**. يعني اللي مش ظاهر في القائمة يبقى شغّال.

### `PUT` /api/v1/notifications/preferences

**PUT /api/v1/notifications/preferences** — تشغيل/إطفاء فئة على قناة.

**لازم:** `category` (نص، أقصى 100)، `channel` (`in_app` أو `email`)، `enabled` (boolean).

</details>

## 16 · Organization — المؤسسة

ملف المؤسسة المعتمدة. **1 endpoint.**

| Method | Endpoint | الشرح |
|---|---|---|
| `GET` | `/api/v1/organization/profile` | **GET /api/v1/organization/profile** — بيانات المؤسسة الخاصة بالمستخدم الحالي. **الصلاحية:** `organization.approved` — لازم المؤسسة تكون **معتمدة**، وإلا الطلب بيترفض. (ونفس السبب ده بيمنع الـ login أصلًا للمؤسسات `pending`.) |

<details><summary>التفاصيل الكاملة</summary>

### `GET` /api/v1/organization/profile

**GET /api/v1/organization/profile** — بيانات المؤسسة الخاصة بالمستخدم الحالي.

**الصلاحية:** `organization.approved` — لازم المؤسسة تكون **معتمدة**، وإلا الطلب بيترفض. (ونفس السبب ده بيمنع الـ login أصلًا للمؤسسات `pending`.)

</details>

## 17 · Admin — لوحة الأدمن

إدارة واعتماد المؤسسات. **5 endpoints.**

| Method | Endpoint | الشرح |
|---|---|---|
| `GET` | `/api/v1/admin/organizations` | **GET /api/v1/admin/organizations** — كل طلبات تسجيل المؤسسات. **فلاتر:** `status` — `pending` / `verified` / `rejected` (غير كده بيتجاهله)، و `per_page` (افتراضي 15). **الصلاحية:** `admin` — لازم توكن أدمن. |
| `GET` | `/api/v1/admin/organizations/{{organization_id}}` | **GET /api/v1/admin/organizations/{organization}** — تفاصيل مؤسسة مع رابط مستند الإثبات عشان الأدمن يراجع قبل القرار. **الصلاحية:** `admin`. |
| `GET` | `/api/v1/admin/organizations/{{organization_id}}/proof-file` | **GET /api/v1/admin/organizations/{organization}/proof-file** — تحميل ملف إثبات هوية المؤسسة. **الصلاحية:** `admin`. |
| `POST` | `/api/v1/admin/organizations/{{organization_id}}/approve` | **POST /api/v1/admin/organizations/{organization}/approve** — اعتماد المؤسسة. **مفيش body.** بعد الاعتماد أعضاء المؤسسة يقدروا يعملوا login. لو المؤسسة اتراجعت قبل كده → `422` "This organization has already been reviewed." **الصلاحية:** `admin`. |
| `POST` | `/api/v1/admin/organizations/{{organization_id}}/reject` | **POST /api/v1/admin/organizations/{organization}/reject** — رفض المؤسسة. **اختياري:** `reason` (أقصى 1000 حرف) — بيتسجّل في `audit_events`. بيحوّل المؤسسة لـ `rejected` وملف الإثبات كمان. نفس شرط "already reviewed" → 422. **الصلاحية:** `admin`. |

<details><summary>التفاصيل الكاملة</summary>

### `GET` /api/v1/admin/organizations?status={{organization_status}}&per_page={{per_page}}

**GET /api/v1/admin/organizations** — كل طلبات تسجيل المؤسسات.

**فلاتر:** `status` — `pending` / `verified` / `rejected` (غير كده بيتجاهله)، و `per_page` (افتراضي 15).

**الصلاحية:** `admin` — لازم توكن أدمن.

### `GET` /api/v1/admin/organizations/{{organization_id}}

**GET /api/v1/admin/organizations/{organization}** — تفاصيل مؤسسة مع رابط مستند الإثبات عشان الأدمن يراجع قبل القرار.

**الصلاحية:** `admin`.

### `GET` /api/v1/admin/organizations/{{organization_id}}/proof-file

**GET /api/v1/admin/organizations/{organization}/proof-file** — تحميل ملف إثبات هوية المؤسسة.

**الصلاحية:** `admin`.

### `POST` /api/v1/admin/organizations/{{organization_id}}/approve

**POST /api/v1/admin/organizations/{organization}/approve** — اعتماد المؤسسة.

**مفيش body.** بعد الاعتماد أعضاء المؤسسة يقدروا يعملوا login.

لو المؤسسة اتراجعت قبل كده → `422` "This organization has already been reviewed."

**الصلاحية:** `admin`.

### `POST` /api/v1/admin/organizations/{{organization_id}}/reject

**POST /api/v1/admin/organizations/{organization}/reject** — رفض المؤسسة.

**اختياري:** `reason` (أقصى 1000 حرف) — بيتسجّل في `audit_events`.

بيحوّل المؤسسة لـ `rejected` وملف الإثبات كمان. نفس شرط "already reviewed" → 422.

**الصلاحية:** `admin`.

</details>

## 18 · Internal & Setup — داخلي وإعداد

endpoint خدمة-لخدمة + إنشاء الأدمن والمرشد. **3 endpoints.**

| Method | Endpoint | الشرح |
|---|---|---|
| `GET` | `/api/v1/internal/baseline-items` | **GET /api/v1/internal/baseline-items?version=v1.0** — endpoint **خدمة لخدمة** لـ FastAPI (مش للمستخدم). **المصادقة:** هيدر `X-Internal-Secret` (مش Sanctum) — لأن النداء مش بالنيابة عن مستخدم. القيمة من `INTERNAL_BASELINE_ITEMS_SECRET`. **لازم (query):** `version` — نص. بيرجّع الأسئلة **مع الإجابات الصحيحة** (`correct_answer`) — وعلشان كده المقارنة بتحصل بـ `hash_equals()` (مقارنة ثابتة الوقت) عشان ما تتسرّبش المدة. **الردود:** `401` سر غلط أو مش مضبوط، `422` نسخة ناقصة. **معدّل الطلبات:** 60/دقيقة. |
| `POST` | `/api/v1/setup/create-admin` | **POST /api/v1/setup/create-admin** — إنشاء حساب أدمن. **محمي بسر:** `ADMIN_SETUP_SECRET` في `secret` جوه الـ body. لو المتغير مش مضبوط في البيئة → **403** "Admin setup is disabled". **لازم:** `name`, `email` (جديد), `password` (8 أحرف على الأقل). **الرد 201** وفيه `token` جاهز — احفظه فورًا، أو اعمل login عادي بعد كده. **معدّل الطلبات:** 5/دقيقة (متشدد عن قصد). |
| `POST` | `/api/v1/setup/create-mentor` | **POST /api/v1/setup/create-mentor** — تسجيل مرشد (مش ترقية بس). **محمي بسر:** `MENTOR_SETUP_SECRET` في `secret` جوه الـ body — **مفيش توكن Sanctum**. **✅ مضبوط وشغّال على الإنتاج** — متأكدين منه live على `back-end-zdip`: طلب بالسر الصح **من غير** `email` بيرجّع `422 "The email field is required."` (يعني السر عدّى)، وبالسر الغلط بيرجّع `403 "Invalid setup secret."`. لو المتغير اتشال من البيئة بيرجّع `403 "Mentor setup is disabled…"`. المتغيرات على Render لكل خدمة لوحدها — لازم يكون على `back-end-zdip` تحديدًا، مش على `skillspan-intelligence` ولا الفرونت. **لازم:** `email`. **اختياري:** `name`, `password`, `expertise`, `affiliation`, `availability`. **السلوك حسب حالة الإيميل:** - مش موجود → بينشئ مستخدم `active` ومفعّل على طول (`email_verified_at=now()`) - `pending` → بيفعّله - `active` → بيرقّيه مرشد - `suspended` / `deleted` → **422** وبعدين بيعمل/بيحدّث `ProfessionalProfile` بـ `type=mentor` و `verification_status=verified` — ودي الهوية اللي بتفتح مسارات المرشد كلها. **المستخدم الجديد مابياخدش أي role.** **مهم:** ده كمان **مسار إعادة تعيين كلمة المرور** — لو بعت `password` لإيميل موجود، الباسورد بيتبدّل (`password_reset: true` في الرد). لو سِبته فاضي، الباسورد القديم ما بيتغيّرش — لأن فلو الـ OTP بيبعت الكود على إيميل المرشد اللي ممكن ما يكونش تحت إيدك. **الرد 201** وفيه `generated_password` **مرة واحدة بس** لو المستخدم جديد. ومابيرجّعش أبدًا باسورد إنت اللي بعتّه. **معدّل الطلبات:** 10/دقيقة. |

<details><summary>التفاصيل الكاملة</summary>

### `GET` /api/v1/internal/baseline-items?version=v1.0

**GET /api/v1/internal/baseline-items?version=v1.0** — endpoint **خدمة لخدمة** لـ FastAPI (مش للمستخدم).

**المصادقة:** هيدر `X-Internal-Secret` (مش Sanctum) — لأن النداء مش بالنيابة عن مستخدم. القيمة من `INTERNAL_BASELINE_ITEMS_SECRET`.

**لازم (query):** `version` — نص.

بيرجّع الأسئلة **مع الإجابات الصحيحة** (`correct_answer`) — وعلشان كده المقارنة بتحصل بـ `hash_equals()` (مقارنة ثابتة الوقت) عشان ما تتسرّبش المدة.

**الردود:** `401` سر غلط أو مش مضبوط، `422` نسخة ناقصة.

**معدّل الطلبات:** 60/دقيقة.

### `POST` /api/v1/setup/create-admin

**POST /api/v1/setup/create-admin** — إنشاء حساب أدمن.

**محمي بسر:** `ADMIN_SETUP_SECRET` في `secret` جوه الـ body. لو المتغير مش مضبوط في البيئة → **403** "Admin setup is disabled".

**لازم:** `name`, `email` (جديد), `password` (8 أحرف على الأقل).

**الرد 201** وفيه `token` جاهز — احفظه فورًا، أو اعمل login عادي بعد كده.

**معدّل الطلبات:** 5/دقيقة (متشدد عن قصد).

### `POST` /api/v1/setup/create-mentor

**POST /api/v1/setup/create-mentor** — تسجيل مرشد (مش ترقية بس).

**محمي بسر:** `MENTOR_SETUP_SECRET` في `secret` جوه الـ body — **مفيش توكن Sanctum**.

**✅ مضبوط وشغّال على الإنتاج** — متأكدين منه live على `back-end-zdip`: طلب بالسر الصح **من غير** `email` بيرجّع `422 "The email field is required."` (يعني السر عدّى)، وبالسر الغلط بيرجّع `403 "Invalid setup secret."`. لو المتغير اتشال من البيئة بيرجّع `403 "Mentor setup is disabled…"`. المتغيرات على Render لكل خدمة لوحدها — لازم يكون على `back-end-zdip` تحديدًا، مش على `skillspan-intelligence` ولا الفرونت.

**لازم:** `email`.
**اختياري:** `name`, `password`, `expertise`, `affiliation`, `availability`.

**السلوك حسب حالة الإيميل:**
- مش موجود → بينشئ مستخدم `active` ومفعّل على طول (`email_verified_at=now()`)
- `pending` → بيفعّله
- `active` → بيرقّيه مرشد
- `suspended` / `deleted` → **422**

وبعدين بيعمل/بيحدّث `ProfessionalProfile` بـ `type=mentor` و `verification_status=verified` — ودي الهوية اللي بتفتح مسارات المرشد كلها. **المستخدم الجديد مابياخدش أي role.**

**مهم:** ده كمان **مسار إعادة تعيين كلمة المرور** — لو بعت `password` لإيميل موجود، الباسورد بيتبدّل (`password_reset: true` في الرد). لو سِبته فاضي، الباسورد القديم ما بيتغيّرش — لأن فلو الـ OTP بيبعت الكود على إيميل المرشد اللي ممكن ما يكونش تحت إيدك.

**الرد 201** وفيه `generated_password` **مرة واحدة بس** لو المستخدم جديد. ومابيرجّعش أبدًا باسورد إنت اللي بعتّه.

**معدّل الطلبات:** 10/دقيقة.

</details>

## 19 · System — النظام

فحص الصحة وتوثيق Swagger. **2 endpoints.**

| Method | Endpoint | الشرح |
|---|---|---|
| `GET` | `/up` | **GET /up** — فحص صحة التطبيق (Laravel health route). **200** = التطبيق شغّال. أسرع طريقة تتأكد إن النشر نجح. |
| `GET` | `/api/documentation` | **GET /api/documentation** — واجهة Swagger UI لتوثيق الـ API. بيرجّع **HTML** (مش JSON) — افتحه في المتصفح. المواصفة نفسها على `/docs` أو حسب إعداد `config/l5-swagger.php`. |
| `GET` | `/api/oauth2-callback` | **GET /api/oauth2-callback** — مسار داخلي جاي من حزمة `l5-swagger` (مش endpoint تجاري ومش مخصص للاختبار اليدوي). موجود هنا للاكتمال بس — Swagger UI هو اللي بيستخدمه في فلو OAuth2 عشان يستقبل الـ authorization code. **متستخدمهوش في الاختبار.** |

<details><summary>التفاصيل الكاملة</summary>

### `GET` /up

**GET /up** — فحص صحة التطبيق (Laravel health route).

**200** = التطبيق شغّال. أسرع طريقة تتأكد إن النشر نجح.

### `GET` /api/documentation

**GET /api/documentation** — واجهة Swagger UI لتوثيق الـ API.

بيرجّع **HTML** (مش JSON) — افتحه في المتصفح. المواصفة نفسها على `/docs` أو حسب إعداد `config/l5-swagger.php`.

### `GET` /api/oauth2-callback

**GET /api/oauth2-callback** — مسار داخلي جاي من حزمة `l5-swagger` (مش endpoint تجاري ومش مخصص للاختبار اليدوي).

موجود هنا للاكتمال بس — Swagger UI هو اللي بيستخدمه في فلو OAuth2 عشان يستقبل الـ authorization code. **متستخدمهوش في الاختبار.**

</details>
