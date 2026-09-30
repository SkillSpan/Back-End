# تقرير: لماذا لا يصل بريد القبول (Organization Approval Email)

**التاريخ**: 2026-09-30 · **الفرع**: `feature/authentication` · **المجلد**: `D:\newnn\Back-End`

---

## 1. الخلاصة

**شغّلت المسار كاملًا وأرسلت بريد القبول فعلًا — والرسالة تخرج.** لم أعد أعتمد على
`Notification::fake()` (وهو الذي كان يخفي الحقيقة في كل الفحوصات السابقة)، بل شغّلت إرسالًا حقيقيًا
عبر `mail.default = log` وقرأت الرسالة المولَّدة:

```
########## APPROVE ##########
HTTP STATUS: 200
To: owner+6abce935f005b@company.com
Subject: Your Organization Has Been Approved - SkillSpan
<title>Organization Approved</title>          ← القالب صُيِّر كاملًا

########## REJECT ##########
HTTP STATUS: 200
To: owner+6abce92608cb2@company.com
Subject: Update on Your Organization Registration - SkillSpan
<title>Organization Registration Update</title>
```

**إذن السبب ليس في الشيفرة**: `approve()` و`reject()` يستخدمان **نفس الـ helper** ونفس نمط الإرسال،
والقالب موجود ويُصيَّر. أي أن العطل في **بيئة التشغيل أو في البيانات**، وليس في منطق المشروع.

لهذا بنيت أداة تشخيص تُحدّد السبب الفعلي عندك في أمر واحد (البند 4)، وأصلحت الثغرة الحقيقية
الوحيدة التي كانت موجودة: **الفشل الصامت** — كانت هناك ثلاث حالات يفشل فيها الإرسال دون أي أثر في
الـ log (البند 3).

---

## 2. الأسباب الممكنة — مرتّبة بحسب الاحتمال

كود القبول والرفض متطابق، لذا لا بد أن السبب في واحد من هذه:

### أ) الموافقة مرفوضة قبل الإرسال (422) — **الأرجح**
`approve()` يرفض الموافقة إذا لم يوجد للمؤسسة ملف إثبات:
`"This organization has no proof document to review. It cannot be approved."`
بينما `reject()` **لا يملك هذا الشرط** — فهو يرسل دائمًا.

> هذا هو **الفرق البنيوي الوحيد** بين المسارين، وهو بالضبط شكل العَرَض الذي وصفته:
> "بريد الرفض يصل، وبريد القبول لا يصل".
>
> تحقّقت أن تسجيل المؤسسة ذرّي (`uploadProofFile` داخل نفس الـ transaction)، فلا يُفترض أن توجد
> مؤسسة بلا ملف إثبات. لكن لو حُذف الصف يدويًا أو جاءت البيانات من seed/نقل قديم، فسيحدث هذا.

### ب) لا يوجد مستلم فعلي
`notifyOrganizationAdmins()` يجمع **حسابات المستخدمين** التي تُدير المؤسسة
(`organization_members.role_in_org='admin'` و `status='active'`).
فإذا كانت العضوية `removed`/`invited`، أو الحساب بلا `email`، أو لا توجد عضوية أصلًا → **لا يُرسل
شيء**. والأسوأ: Laravel's mail channel **يعود بصمت** إذا كان `routeNotificationFor('mail')` فارغًا،
فلا استثناء ولا سطر في الـ log.

### ج) البريد خرج فعلًا والمشكلة في التسليم
أي أن `approve` أعاد **200** لكن الرسالة لم تصل. هنا الأسباب:
- `MAIL_MAILER=log` في بيئة النشر → الرسالة تُكتب في `storage/logs/laravel.log` ولا تُسلَّم لأي صندوق.
- **`MAIL_FROM_ADDRESS=hello@example.com`** (القيمة الافتراضية في `.env` و`.env.example`) →
  نطاق `example.com` لا يملك SPF/DKIM، وكثير من المزوّدين يُسقط أو يحجب مثل هذه الرسائل.
  هذا يفسّر وصول رسالة وحجب أخرى (التسليم غير حتمي).
- `queue` → إن كان أي إشعار يمر عبر طابور بلا worker فسيبقى في جدول `jobs`. (تحقّقت: **لا يوجد أي
  إشعار** في المشروع يستخدم `ShouldQueue`، فالمسار متزامن بالكامل.)

### د) المؤسسة حالتها ليست `pending`
إن جرّبت الموافقة على مؤسسة **سبق رفضها أو اعتمادها**، فسيرجع 422
`"This organization has already been reviewed."` ولن يُرسل شيء — وهذا سلوك مقصود لمنع البريد المكرر.

---

## 3. ما تم إصلاحه فعليًا

**المشكلة الحقيقية الوحيدة في الكود: الفشل الصامت.** ثلاث حالات كان يفشل فيها الإرسال دون أي أثر.

| الملف | التعديل |
|---|---|
| `app/Http/Controllers/Api/Admin/OrganizationController.php` | `notifyOrganizationAdmins()` صار يُسجّل في الـ log قائمة **العناوين التي أُرسل إليها** و**العضويات التي تم تخطّيها وسبب التخطّي**، ويكتب `warning` صريحًا عندما لا يوجد أي مستلم بدل أن يعود بصمت |
| `app/Console/Commands/CheckReviewEmail.php` | **جديد** — أمر تشخيص `admin:check-review-email` (البند 4) |
| `tests/Feature/Admin/AdminOrganizationTest.php` | +2 اختبار للسجلات |
| `tests/Feature/Admin/CheckReviewEmailCommandTest.php` | **جديد** — 7 اختبارات للأمر |

لم يتغيّر أي عقد API، ولا أُنشئ أي Mail class جديد، ولم تُعدَّل أي إعدادات بيئة.

---

## 4. كيف تعرف السبب عندك في أمر واحد

```bash
php artisan admin:check-review-email {organization_id}
# ولمعرفة هل التسليم يعمل فعلًا:
php artisan admin:check-review-email {organization_id} --send-to=your@email.com
```

الأمر يطبع مسار القرار كاملًا:

```
  Organization 12 — Test Company (company)
  verification_status  pending
  Proof document       MISSING                      ← لو MISSING: الموافقة ترجع 422 ولا يُرسل شيء
  Admin memberships (organization_members.role_in_org = admin):
    SKIPPED owner@company.com — membership removed  ← لو SKIPPED: لن يصل شيء
    NOTIFIED owner@company.com
  Mailer               log                          ← لو log: لا تسليم لأي صندوق
  From                 hello@example.com             ← لو example.com: تسليم غير موثوق
  Templates:
    OK     emails.organization-approved             ← لو FAILS: القالب هو سبب الفشل
    OK     emails.organization-rejected
```

الخيار `--send-to` يرسل **بريد قبول حقيقي** إلى عنوان تختاره، فتتحقق من التسليم من طرفه إلى طرفه.
إن وصلت رسالة `--send-to` فالمسار سليم والمشكلة في المستلم الأصلي؛ وإن لم تصل فالمشكلة في
إعدادات البريد أو مزوّد الإرسال.

---

## 5. الاختبارات والنتيجة

| الأمر | النتيجة |
|---|---|
| `AdminOrganizationTest` + `CheckReviewEmailCommandTest` | **31 passed (124 assertions)** |
| المجموعة الكاملة `php artisan test` | **878 passed (3124 assertions)** — 0 فشل |
| `php vendor/bin/pint` على الملفات الأربعة | **PASS** |

### اختبارات هذا التعديل
- `test_approval_logs_the_recipients_it_notified` — الإرسال الناجح يُسجَّل.
- `test_approval_with_no_active_admin_logs_a_warning_and_sends_nothing` — الحالة الصامتة تُسجَّل.
- 7 اختبارات للأمر: مؤسسة غير موجودة · ملف إثبات مفقود · العنوان الذي سيُرسل إليه · عضوية `removed`
  كمتلقٍّ متخطّى · مؤسسة سبق مراجعتها · تصيير القالبين · الإرسال الفعلي عبر `--send-to`.

---

## 6. ما يحتاج تدخلًا يدويًا

1. **شغّل الأمر أعلاه** على نفس المؤسسة التي لا يصل بريدها — سيقول لك السبب مباشرة.
2. **افحص `MAIL_MAILER` و`MAIL_FROM_ADDRESS` على Render** (`back-end-zdip`). إن كان
   `MAIL_MAILER=log` فلن يصل أي بريد. وإن كان `MAIL_FROM_ADDRESS` ما زال `hello@example.com`
   فالرسائل تُحجب أو تُصنَّف spam — وهذا أقوى تفسير لوصول رسالة وحجب أخرى.
3. **إن كانت الموافقة ترجع 422**: المؤسسة بلا ملف إثبات — أعد رفع الإثبات (أو راجع
   `php artisan admin:check-proofs`).
4. **`composer install` مطلوب على بيئة النشر** (الحزمة `propaganistas/laravel-disposable-email`
   معلنة لكنها لم تكن مثبّتة، وهو ما كان يعطّل كل مسارات التسجيل).
