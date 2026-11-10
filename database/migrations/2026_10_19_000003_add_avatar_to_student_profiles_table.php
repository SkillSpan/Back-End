<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('student_profiles') && ! Schema::hasColumn('student_profiles', 'avatar_data')) {
            Schema::table('student_profiles', function (Blueprint $table) {
                $table->binary('avatar_data')->nullable()->after('consent_given');
                $table->string('avatar_mime')->nullable()->after('avatar_data');
                $table->timestamp('avatar_updated_at')->nullable()->after('avatar_mime');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('student_profiles') && Schema::hasColumn('student_profiles', 'avatar_data')) {
            Schema::table('student_profiles', function (Blueprint $table) {
                $table->dropColumn(['avatar_data', 'avatar_mime', 'avatar_updated_at']);
            });
        }
    }
};
