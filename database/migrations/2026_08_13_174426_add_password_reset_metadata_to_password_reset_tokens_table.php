<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            if (!Schema::hasColumn('password_reset_tokens', 'expires_at')) {
                $table->timestamp('expires_at')->nullable();
            }

            if (!Schema::hasColumn('password_reset_tokens', 'consumed_at')) {
                $table->timestamp('consumed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('password_reset_tokens', 'expires_at')) {
                $table->dropColumn('expires_at');
            }

            if (Schema::hasColumn('password_reset_tokens', 'consumed_at')) {
                $table->dropColumn('consumed_at');
            }
        });
    }
};
