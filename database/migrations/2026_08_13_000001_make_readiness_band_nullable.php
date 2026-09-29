<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Makes readiness_results.band nullable and adds the two "latest row"
 * lookup indexes.
 *
 * ## Foreign-key / index interaction
 *
 * Both composite indexes created here are the ONLY indexes whose leftmost
 * column is `student_profile_id`:
 *
 *   skill_evaluations_latest_lookup_idx  (student_profile_id, skill_id, calculated_at)
 *   readiness_results_latest_lookup_idx  (student_profile_id, career_role_id, calculated_at)
 *
 * `student_profile_id` carries a foreign key on both tables, and MySQL
 * refuses to drop an index a foreign key still needs:
 *
 *   SQLSTATE[HY000]: General error: 1553 Cannot drop index
 *   'skill_evaluations_latest_lookup_idx': needed in a foreign key constraint
 *
 * up() and down() are therefore kept symmetric: whichever side is about to
 * remove the composite index first ensures a plain index on
 * `student_profile_id` exists, and the other side removes that plain index
 * once the composite one covers the foreign key again. Without this, a
 * rollback either fails outright or leaves the migration unable to re-run.
 */
return new class extends Migration
{
    /**
     * Plain foreign-key support indexes, keyed by table.
     *
     * @var array<string, string>
     */
    private const FK_SUPPORT_INDEXES = [
        'skill_evaluations' => 'skill_evaluations_student_profile_fk_idx',
        'readiness_results' => 'readiness_results_student_profile_fk_idx',
    ];

    public function up(): void
    {
        // 1. Create the composite lookup indexes first. They cover
        //    student_profile_id, so the foreign keys stay satisfied.
        if (! Schema::hasIndex('skill_evaluations', 'skill_evaluations_latest_lookup_idx')) {
            Schema::table('skill_evaluations', function (Blueprint $table) {
                $table->index(
                    ['student_profile_id', 'skill_id', 'calculated_at'],
                    'skill_evaluations_latest_lookup_idx'
                );
            });
        }

        if (! Schema::hasIndex('readiness_results', 'readiness_results_latest_lookup_idx')) {
            Schema::table('readiness_results', function (Blueprint $table) {
                $table->index(
                    ['student_profile_id', 'career_role_id', 'calculated_at'],
                    'readiness_results_latest_lookup_idx'
                );
            });
        }

        // 2. A plain FK-support index left behind by an earlier rollback is
        //    now redundant, so up() -> down() -> up() stays repeatable.
        foreach (self::FK_SUPPORT_INDEXES as $table => $index) {
            if (Schema::hasIndex($table, $index)) {
                Schema::table($table, function (Blueprint $blueprint) use ($index) {
                    $blueprint->dropIndex($index);
                });
            }
        }

        // 3. Band becomes nullable.
        Schema::table('readiness_results', function (Blueprint $table) {
            $table->enum('band', [
                'foundation_needed',
                'developing',
                'moderate_readiness',
                'near_ready',
                'highly_ready',
            ])->nullable()->change();
        });
    }

    public function down(): void
    {
        // 1. Ensure the foreign keys are supported BEFORE the composite
        //    indexes are dropped (MySQL error 1553 otherwise).
        foreach (self::FK_SUPPORT_INDEXES as $table => $index) {
            if (! Schema::hasIndex($table, $index)) {
                Schema::table($table, function (Blueprint $blueprint) use ($index) {
                    $blueprint->index('student_profile_id', $index);
                });
            }
        }

        // 2. Now the composite indexes can be removed.
        Schema::table('skill_evaluations', function (Blueprint $table) {
            $table->dropIndex('skill_evaluations_latest_lookup_idx');
        });

        Schema::table('readiness_results', function (Blueprint $table) {
            $table->dropIndex('readiness_results_latest_lookup_idx');
            $table->enum('band', [
                'foundation_needed',
                'developing',
                'moderate_readiness',
                'near_ready',
                'highly_ready',
            ])->change();
        });
    }
};
