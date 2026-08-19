<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * يضيف عداد المحاولات الفاشلة للتحقق من الـ OTP، عشان نطبّق فعليًا
     * حد أقصى (max_attempts) بدل ما يضل موجود بالـ config بدون تفعيل.
     */
    public function up(): void
    {
        Schema::table('account_verifications', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(0)->after('challenge_state');
        });
    }

    public function down(): void
    {
        Schema::table('account_verifications', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
