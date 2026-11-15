<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->index(['user_id', 'consumed_at'], 'password_reset_tokens_user_consumed_index');
        });

        Schema::table('skills', function (Blueprint $table) {
            $table->index('name');
        });

        Schema::table('skill_aliases', function (Blueprint $table) {
            $table->index('alias');
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->dropIndex('password_reset_tokens_user_consumed_index');
        });

        Schema::table('skills', function (Blueprint $table) {
            $table->dropIndex(['name']);
        });

        Schema::table('skill_aliases', function (Blueprint $table) {
            $table->dropIndex(['alias']);
        });
    }
};
