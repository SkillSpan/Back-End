# خطة إصلاح SkillSpan Backend — feature/authentication

## الحالة: بانتظار موافقة المستخدم على الخطة للخروج من Plan Mode

## القرارات المؤكدة
- صلاحية التوكن: **14 يوم** (`'expiration' => 20160`)
- التوثيق: **شامل** Postman + INTEGRATION_README
- بدون commit إلا بطلب المستخدم
- الإيميلات تبقى sync (قرار الفريق — Render بدون queue worker)

---

## 1️⃣ أمان حرج
| # | الإجراء | الملف |
|---|---------|-------|
| 1.1 | حذف `routes/.env` الضائع | `routes/.env` |
| 1.2 | إنشاء `.dockerignore` (يستثني `.env*`, vendor, node_modules, storage) | جديد |
| 1.3 | إضافة `throttle:5,1` على create-admin | `routes/api.php:62` |
| 1.4 | إضافة `'admin_setup' => ['secret' => env('ADMIN_SETUP_SECRET', '')]` | `config/services.php` |
| 1.5 | استبدال `env()` بـ `config('services.admin_setup.secret')` + تسجيل الجلسة عبر `recordAuthSession` | `SetupController.php:24,71` |
| 1.6 | حذف `GoogleAuthService.php` (كود ميت + ثغرة org-approval bypass) | `app/Services/GoogleAuthService.php` |
| 1.7 | `'expiration' => 20160` | `config/sanctum.php:53` |
| 1.8 | رد محايد بدل `firstOrFail()` عند resend-otp لإيميل غير موجود | `AuthController.php:107` |

**تصحيح مهم:** `auth_sessions.token_hash` مخزّن أصلاً SHA-256 hashed (AuthService.php:746) — لا تعديل مطلوب عليه.

## 2️⃣ قاعدة البيانات
| # | الإجراء |
|---|---------|
| 2.1 | مايجريشن جديد: جدول `role_permission` (role_id FK + permission_id FK + unique composite) — الموديلات Role/Permission معلقين عليه والجدول غير موجود |
| 2.2 | مايجريشن جديد: index `(user_id, consumed_at)` على password_reset_tokens + indexes على `skills.name`, `skill_aliases.alias` |

## 3️⃣ اختبارات
| # | الإجراء |
|---|---------|
| 3.1 | تصحيح المسارات إلى `/api/v1/...` في: RegistrationTest, VerificationTest, OrganizationApprovalTest, ReadinessTest |
| 3.2 | كلاس `PasswordResetTest` جديد: forgot neutral response / resend cooldown / verify OTP / reset success / expired OTP / attempts cap / login بالباسورد الجديد |

## 4️⃣ جودة كود
| # | الإجراء | الملف |
|---|---------|-------|
| 4.1 | إصلاح N+1: whereIn واحد بدل query داخل foreach | `ReadinessService.php:76-92` |
| 4.2 | حذف dead code: `GeminiCLI.php`, `RegistrationOtpMail.php`, blade templates بمجلد app/Notifications/, `bootstrap/bootstrap_app.php`, `AuthService::canResendOtp()`, `passwordResetResendAvailableAt()` | متعدد |
| 4.3 | دمج login/loginOrganization عبر private attemptLogin() | `AuthController.php:164-274` |

## 5️⃣ توثيق
- postman_collection.json: تصحيح كل المسارات `/api/auth/...` → `/api/v1/auth/...` + إضافة 19 endpoint ناقص
- INTEGRATION_README.md: `/api/readiness/...` → `/api/v1/readiness/...`
- README.md: استبدال boilerplate بوثيقة مشروع حقيقية

## 6️⃣ تحقق نهائي
- `php artisan test` — الكل أخضر
- `vendor/bin/pint --dirty` أو على الملفات المتغيرة
