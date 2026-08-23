<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * يضيف عمود attempts لجدول account_verifications، وهو مطلوب لتطبيق
 * OTP max_attempts فعليًا داخل AuthService::verifyOtp(). هذا الـmigration
 * كان موجود سابقًا وانحذف بالغلط بـcommits لاحقة، فتم إرجاعه بتاريخ جديد
 * حتى ينفذ بشكل صحيح على أي بيئة (بما فيها البيئات يلي شغلت migrate
 * قبل الحذف).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_verifications', function (Blueprint $table) {
            if (! Schema::hasColumn('account_verifications', 'attempts')) {
                $table->unsignedTinyInteger('attempts')->default(0)->after('challenge_state');
            }
        });
    }

    public function down(): void
    {
        Schema::table('account_verifications', function (Blueprint $table) {
            if (Schema::hasColumn('account_verifications', 'attempts')) {
                $table->dropColumn('attempts');
            }
        });
    }
};
