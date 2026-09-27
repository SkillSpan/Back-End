<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Scope the assessment uniqueness rule by career role.
 *
 * The original unique index was
 *   (student_profile_id, assessment_type, assessment_version)
 * which allowed exactly ONE baseline assessment per learner per version —
 * so a learner could never assess against a second career role.
 *
 * Requirement: a learner may take a baseline assessment per career role.
 * The index becomes
 *   (student_profile_id, assessment_type, assessment_version, career_role_id)
 *
 * `career_role_id` is nullable (legacy rows). MySQL/PostgreSQL/SQLite all
 * treat NULLs as distinct in a unique index, so legacy NULL-role rows do
 * not collide with each other — matching the previous behaviour for
 * assessments that were never role-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('baseline_assessments', function (Blueprint $table) {
            $table->dropUnique('baseline_attempt_unique');

            $table->unique(
                ['student_profile_id', 'assessment_type', 'assessment_version', 'career_role_id'],
                'baseline_attempt_role_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('baseline_assessments', function (Blueprint $table) {
            $table->dropUnique('baseline_attempt_role_unique');

            $table->unique(
                ['student_profile_id', 'assessment_type', 'assessment_version'],
                'baseline_attempt_unique'
            );
        });
    }
};
