<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learner profile avatar.
 *
 * Mirrors `admin_profiles` (see the 2026_10_08_000001 migration): the bytes are
 * stored base64-encoded inside the row rather than as a path, because the avatar
 * is served from its own route where the browser caches it — keeping the blob
 * out of every JSON payload and log line. `student_profiles` already exists on
 * production, so the ALTER is guarded the same way the existing migrations guard
 * their columns: a replay on a database that already has the columns is a no-op.
 */
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
