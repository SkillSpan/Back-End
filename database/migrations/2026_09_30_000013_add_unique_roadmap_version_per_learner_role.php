<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap v1 integration — concurrency safety for the Laravel-owned
 * roadmap version.
 *
 * The persisted version is derived as MAX(version)+1 per
 * (student_profile_id, career_role_id) inside the persistence
 * transaction. Two concurrent calculations for the same learner + role
 * could read the same MAX before either inserts, and both write the same
 * version. A UNIQUE constraint turns that race into a hard, atomic
 * failure (the losing transaction rolls back) instead of two roadmaps
 * silently sharing one version.
 *
 * This adds a constraint only — no column, no data change. It is safe
 * for existing data because the version is unique by construction
 * (max+1). The index also serves the MAX(version) lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            $table->unique(
                ['student_profile_id', 'career_role_id', 'version'],
                'roadmaps_learner_role_version_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            $table->dropUnique('roadmaps_learner_role_version_unique');
        });
    }
};
