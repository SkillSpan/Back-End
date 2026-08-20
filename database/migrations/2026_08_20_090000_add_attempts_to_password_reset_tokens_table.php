<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * نضيف عداد محاولات على password_reset_tokens، بنفس منطق
     * account_verifications، عشان نمنع brute-force على كود استرجاع
     * كلمة المرور (سواء عبر endpoint التحقق الجديد أو reset-password نفسه).
     */
    public function up(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(0)->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
