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
