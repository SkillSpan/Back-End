# الشركة — تقرير الاختبار الحيّ والشامل (2026-11-04)

الباك إند الحيّ: `https://skillspan.alwaysdata.net` · الفرونت: `main` = `fae60d4`

---

## 1. الفحص الحيّ لكل الروابط (47 رابط)

كل رابط اتنادى **بدون توكن** أولاً (الـ 401 بيجي من الـ middleware قبل أي controller، فما في أي كتابة على
الداتابيز المشتركة)، وبعدها الـ **GET** فقط اتعاد بتوكن طالب حقيقي للتأكد من الحراسة.

**النتيجة: 47 رابط، 0 ناقص.**

| المجموعة | الروابط | بدون توكن | بتوكن طالب |
|---|---|---|---|
| لوحة التحكم | `GET /company/dashboard` | 401 | **403 COMPANY_ADMIN_ONLY** |
| مشاريع الشركة | `GET /company/projects`, `/company/projects/{id}` | 401 | 403 |
| الملف التعريفي | `GET/PATCH /profile/me`, `GET /organization/profile` | 401 | 403 |
| المستندات | `POST /company/profile/documents`, `GET .../documents/{id}` | 401 | 403 |
| اللوجو | `POST`, `DELETE /company/profile/logo` | 401 | — |
| المشاريع | `GET/POST /projects`, `GET/PATCH /projects/{id}` | 401 | 200 / 404 |
| النسخ والانتقالات | `POST /projects/{id}/clone`, `/transitions`, `/start`, `/submit` | 401 | — |
| المتقدمون | `GET /projects/{id}/applications`, `GET /applications/{id}` | 401 | 404 |
| القرار | `POST /applications/{id}/decision` | 401 | — |
| الفريق | `GET /projects/{id}/team`, `POST .../assignments`, `DELETE .../assignments/{a}` | 401 | 404 |
| الدعوات | `POST .../team/invitations`, `GET/POST /team-invitations/{id}` | 401 | 404 |
| المواهب | `GET /talent`, `/talent/filters`, `/talent/{id}`, `/talent/lists` | 401 | 403 |
| القوائم | `POST/PATCH/DELETE /talent/lists`, `POST/DELETE .../candidates` | 401 | 403 |
| المقارنة والتواصل | `POST /talent/compare`, `/candidates/{id}/notes`, `/contact` | 401 | — |
| القمع | `GET /talent/funnel` | 401 | 403 |
| الإشعارات | `GET /notifications`, `/unread-count`, `POST /read-all`, `/{id}/read` | 401 | 200 |

**ملاحظة مهمة:** روتس الشركة بترجع **403 `COMPANY_ADMIN_ONLY`** لتوكن طالب — يعني الحراسة شغالة فعلياً،
مش بس الراوت موجود. والروتس المشتركة (`/projects`, `/applications`, `/notifications`) بترجع 200 للطالب.

---

## 2. اختبار السلوك (كل عنصر عندو اختبار)

### الباك إند

| السويت | النتيجة |
|---|---|
| `tests/Feature/Company` + `Projects` + `Talent` (30 ملف) | **749 passed** (2586 assertions) |
| السويت الكامل | **1969 passed** (12334 assertions) — 5m34s |

| العنصر | ملف الاختبار |
|---|---|
| لوحة التحكم | `CompanyDashboardTest` |
| الملف التعريفي (US-COMP-04) | `OrganizationProfileTest` |
| رفع/استبدال المستندات | `OrganizationProfileTest` |
| اللوجو (رفع/حذف/حد الحجم/الـ URL العام) | `OrganizationLogoTest` |
| الإشعارات | `CompanyNotificationApiTest` |
| قائمة مشاريع الشركة | `CompanyProjectListTest` |
| إنشاء/تعديل/نشر مشروع | `ProjectManagementWorkflowTest` |
| نسخ مشروع | `ProjectCloneAndTransitionTest` |
| الانتقالات (12 حالة) | `ProjectCloneAndTransitionTest`, `ProjectClosingTransitionsTest` |
| تعيين الفريق وبدء المشروع | `ProjectTeamAndStartTest` |
| قراءة الفريق + الدعوات | `TeamReadAndInvitationsTest` |
| قرار التقديم | `ApplicationDecisionByIdTest`, `ApplicationWorkflowTest` |
| تفاصيل التقديم | `ApplicationShowTest` |
| بحث المواهب + المقارنة + القوائم + الملاحظات + التواصل + القمع | `TalentDiscoveryTest`, `TalentEngagementTest` |
| صلاحيات الوصول | `ProjectAccessControlTest` |

### الفرونت

| السويت | النتيجة |
|---|---|
| `src/components/company` (14 ملف) | **167 passed** |
| السويت الكامل (39 ملف) | **308 passed** |
| `vite build` | ناجح |

---

## 3. اللي ما بينفع اختباره بدون حساب شركة

الفحص الحيّ أثبت إن كل رابط موجود ومحمي، لكن **السلوك** (إنشاء مشروع فعلي، قرار تقديم، تعيين فريق،
دعوة طالب) ما بينفع يتنفّذ بدون **توكن `company_admin`** لحساب شركة مُعتمدة على السيرفر الحيّ —
لأن أي POST هنا بيكتب على داتابيز الإنتاج المشتركة.

لو انعطى حساب شركة (إيميل + باسورد) بينعمل سيناريو حيّ كامل:
دخول → لوحة التحكم → إنشاء مشروع → تقديم للنشر → فتح → استقبال تقديم → قرار → تعيين فريق → دعوة → بدء.

---

## 4. السيناريو الكامل (رحلة الشركة) — 37/37

`tools/company_e2e.py` بيمشي الرحلة بالترتيب الحقيقي على HTTP فعلي (مش اختبار وحدة)، على داتابيز
SQLite معزولة — مش داتابيز الإنتاج. السكربت بيرفض يشتغل لو العنوان مش loopback، عشان ما حدا يغلط
ويشغّله على السيرفر الحيّ.

| # | الخطوة | النتيجة |
|---|---|---|
| 1 | تسجيل دخول الشركة (`/auth/login/organization`) | 200 + توكن |
| 2 | تسجيل دخول الأدمن والطالب | 200 + توكن |
| 3 | لوحة التحكم | 200 |
| 4 | الملف التعريفي للمؤسسة | 200 |
| 5 | إنشاء مشروع (draft) | 201 |
| 6 | تعديل المشروع | 200 |
| 7 | قائمة مشاريع الشركة + التفاصيل | 200 |
| 8 | تقديم للنشر (submit) | 200 `submitted` |
| 9 | موافقة الأدمن | 200 `approved` |
| 10 | فتح المشروع | 200 `open` |
| 11 | الطالب يقدّم | 201 |
| 12 | الشركة تشوف التقديمات + التفاصيل | 200 |
| 13 | قبول التقديم | 200 `accepted` |
| 14 | عرض الفريق | 200 |
| 15 | تعيين على الفريق | 201 |
| 16 | بدء المشروع | 200 `active` |
| 17 | إكمال المشروع | 200 `completed` |
| 18 | أرشفة المشروع | 200 `archived` |
| 19 | نسخ المشروع | 201 |
| 20 | بحث المواهب + الفلاتر | 200 (رجّع طالبين) |
| 21 | إنشاء قائمة مواهب | 201 |
| 22 | إضافة مرشّح للقائمة | 201 |
| 23 | قراءة القائمة / تسميتها من جديد | 200 |
| 24 | إزالة المرشّح / حذف القائمة | 200 |
| 25 | مقارنة مرشّحين | 200 |
| 26 | إضافة ملاحظة / قراءتها | 201 / 200 |
| 27 | طلب تواصل | 201 |
| 28 | قمع المواهب | 200 `{saved, contacted: 1, responded}` |
| 29 | الإشعارات + عدد غير المقروء | 200 |

**ملاحظات من التشغيل (كلها فجوات في بيانات الاختبار، مش أخطاء في المنتج):**
- `status` و`email_verified_at` مش في `User::$fillable` → لازم `forceFill`، وإلا كل دخول بيرجع
  "You must activate your account first".
- دخول المؤسسة بيقرأ `organization_members` (`role_in_org = admin`, `status = active`)، مش جدول
  `user_role` — عضوية ناقصة = "This account is not registered as an organization".
- مشروع بلا `required_skills` و`roles` بيرفض الـ submit بـ `PROJECT_INCOMPLETE` (وهذا سلوك صحيح).
- بحث المواهب بيرجّع فاضي إلا لو `student_profiles.visibility = 'public'` **و** `consent_given = true`
  — وهو المطلوب أمنياً (BR-02/BR-05).

---

## 5. خطآن حقيقيان انكشفا بالحساب الحقيقي (وتم إصلاحهما)

التشغيل بحساب شركة فعلي على الإنتاج كشف إن **إنشاء مشروع من الواجهة كان مستحيلاً** لسببين:

| # | المشكلة | الأثر | الإصلاح |
|---|---|---|---|
| 1 | `GET /api/v1/career-roles` كان محمياً بـ `role:learner` | الشركة تاخد `403 LEARNER_ONLY` → قائمة «الدور المهني» فاضية، و`career_role_id` **إلزامي** → الفورم ما بينحفظ | القائمة والتفاصيل صارت متاحة لأي حساب نشط ومُصادق (بيانات مرجعية عامة). `/career-roles/{id}/skills` بقي learner-only لأنه بيجاوب بمستويات صاحب الطلب |
| 2 | `GET /api/v1/rubrics` غير موجود (404) | قائمة الـ rubric فاضية والفرونت بيعتبرها إلزامية → «حفظ كمسودة» و«إرسال» مقفولين | أُضيف `RubricController@index`: بس الروبركس المنشورة، وبتنعرض باسم `approved` (المخزّن `published`)، ومعها `criteria_count` |

كمان: صار الـ rubric **مطلوب فقط إذا في خيارات متاحة** بالفرونت — لأن `rubrics` ممكن ترجع فاضية
لو ما في ولا rubric منشور، والباك إند أصلاً ما بيشترطها.

الاختبارات: `tests/Feature/Company/RubricListTest` (5) + عكس اختبارين كانوا يثبّتون المنع القديم
(صارت توثّق القاعدة الجديدة). السويت الكامل: **1975 passed (12358 assertions)**.
