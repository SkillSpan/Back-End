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
 *
 * ## Index ordering (why the new index is added before the old one is dropped)
 *
 * `baseline_attempt_unique` is the ONLY index whose leftmost column is
 * `student_profile_id`, and that column carries the foreign key
 * `baseline_assessments_student_profile_id_foreign`. MySQL refuses to drop
 * an index a foreign key still needs:
 *
 *   SQLSTATE[HY000]: General error: 1553 Cannot drop index
 *   'baseline_attempt_unique': needed in a foreign key constraint
 *
 * Adding the replacement first keeps an index on `student_profile_id`
 * available at every point, so the drop succeeds. The new index has
 * `student_profile_id` as its leftmost column, so it serves the foreign key
 * on its own — no extra supporting index is needed, and none is left behind.
 *
 * The same ordering applies in reverse in down().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('baseline_assessments', function (Blueprint $table) {
            // Add the replacement BEFORE dropping the old index: the foreign
            // key on student_profile_id must always have a supporting index.
            $table->unique(
                ['student_profile_id', 'assessment_type', 'assessment_version', 'career_role_id'],
                'baseline_attempt_role_unique'
            );

            $table->dropUnique('baseline_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::table('baseline_assessments', function (Blueprint $table) {
            // Mirror the ordering: restore an index on student_profile_id
            // before dropping the one currently serving the foreign key.
            //
            // NOTE: this rollback re-imposes a strictly narrower uniqueness
            // rule, so it cannot succeed while the database holds two
            // assessments for the same learner, type and version under
            // DIFFERENT career roles — those rows are exactly what this
            // migration was written to allow. Such rows must be resolved
            // deliberately before rolling back; this migration will not
            // delete learner data to force the index back on.
            $table->unique(
                ['student_profile_id', 'assessment_type', 'assessment_version'],
                'baseline_attempt_unique'
            );

            $table->dropUnique('baseline_attempt_role_unique');
        });
    }
};
