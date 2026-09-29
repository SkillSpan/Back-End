<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Protect baseline question snapshots from source-data deletion.
 *
 * A snapshot is a historical record of exactly what a learner was shown
 * and how their answers are validated. It already stores its own copy of
 * the question content (item_id, item_type, question_text, options) —
 * see 2026_09_28_000004 — so it does NOT need to depend on the live
 * `baseline_assessment_items` / `skills` rows to remain meaningful.
 *
 * The original foreign keys used cascadeOnDelete(), which meant:
 *
 *   - deleting a question from the bank deleted the snapshot of every
 *     assessment that had ever used it, silently erasing assessment
 *     history; and
 *   - deleting a skill did the same for every snapshot mapped to it.
 *
 * That directly contradicts snapshot immutability: source data is
 * mutable editorial content, while a snapshot is an append-only
 * historical record.
 *
 * New behaviour:
 *
 *   - `baseline_assessment_item_id` becomes NULLABLE and nullOnDelete().
 *     Deleting a bank question detaches the snapshot from the (now
 *     gone) live row; the frozen content keeps the snapshot fully
 *     readable and submittable. Nullable is required because the column
 *     must accept the NULL that ON DELETE SET NULL writes.
 *   - `skill_id` becomes restrictOnDelete(). A skill is a stable
 *     taxonomy node with no delete UI; blocking the delete is safer than
 *     orphaning assessments, and RESTRICT is portable across
 *     MySQL/PostgreSQL/SQLite.
 *
 * `baseline_assessment_id` keeps cascadeOnDelete(): deleting the parent
 * assessment legitimately removes its own snapshot rows. `career_role_id`
 * keeps cascadeOnDelete() for the same reason (a retired role takes its
 * role-scoped assessments with it).
 *
 * The unique index `bqs_assessment_item_unique` is also dropped: with
 * item references now nullable, NULLs would no longer collide under a
 * unique index — i.e. the constraint would silently stop enforcing
 * "one snapshot per item per assessment". At selection time every
 * question carries a distinct item_id, so the index no longer protects
 * anything the selection algorithm does not already guarantee, and a
 * duplicate (assessment, item) pair is already impossible by
 * construction. Any future duplicate guard belongs on the frozen
 * `item_id` string, not on a column that may be nulled out.
 *
 * ## Index ordering (why a replacement index is added before the drop)
 *
 * `bqs_assessment_item_unique` is `(baseline_assessment_id,
 * baseline_assessment_item_id)`, so its LEFTMOST column is
 * `baseline_assessment_id` — which carries the foreign key
 * `baseline_question_snapshots_baseline_assessment_id_foreign`. It is the
 * only index that can serve that key (`baseline_assessment_item_id`,
 * `skill_id` and `career_role_id` each have their own). MySQL therefore
 * refuses the drop:
 *
 *   SQLSTATE[HY000]: General error: 1553 Cannot drop index
 *   'bqs_assessment_item_unique': needed in a foreign key constraint
 *
 * A replacement index on `baseline_assessment_id` is created FIRST, so the
 * foreign key is supported at every point in the migration.
 *
 * Note: SQLite cannot ALTER a foreign key in place; the driver rebuilds
 * the table via its own table-copy. RefreshDatabase runs the whole
 * migration chain on SQLite, so this path is exercised by the test
 * suite, not just by production MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Support the baseline_assessment_id foreign key BEFORE removing the
        // unique index that currently serves it (MySQL error 1553 otherwise).
        Schema::table('baseline_question_snapshots', function (Blueprint $table) {
            $table->index('baseline_assessment_id', 'bqs_assessment_id_fk_idx');
        });

        Schema::table('baseline_question_snapshots', function (Blueprint $table) {
            // Dropping an index on a nullable column must happen before
            // the column is rebuilt by the foreign-key change on some
            // drivers; do it first for determinism.
            $table->dropUnique('bqs_assessment_item_unique');
        });

        Schema::table('baseline_question_snapshots', function (Blueprint $table) {
            // Detach on source-question deletion instead of destroying
            // the historical snapshot.
            $table->dropForeign(['baseline_assessment_item_id']);
            $table->foreignId('baseline_assessment_item_id')
                ->nullable()
                ->change();
        });

        Schema::table('baseline_question_snapshots', function (Blueprint $table) {
            $table->foreign('baseline_assessment_item_id')
                ->references('id')
                ->on('baseline_assessment_items')
                ->nullOnDelete();
        });

        Schema::table('baseline_question_snapshots', function (Blueprint $table) {
            // A skill is a stable taxonomy node — refuse to delete while
            // assessment history references it rather than orphan the
            // snapshot's skill mapping.
            $table->dropForeign(['skill_id']);
            $table->foreign('skill_id')
                ->references('id')
                ->on('skills')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('baseline_question_snapshots', function (Blueprint $table) {
            $table->dropForeign(['baseline_assessment_item_id']);
            $table->dropForeign(['skill_id']);
        });

        Schema::table('baseline_question_snapshots', function (Blueprint $table) {
            // Restore the original destructive behaviour.
            $table->foreign('baseline_assessment_item_id')
                ->references('id')
                ->on('baseline_assessment_items')
                ->cascadeOnDelete();

            $table->foreign('skill_id')
                ->references('id')
                ->on('skills')
                ->cascadeOnDelete();
        });

        Schema::table('baseline_question_snapshots', function (Blueprint $table) {
            $table->unique(
                ['baseline_assessment_id', 'baseline_assessment_item_id'],
                'bqs_assessment_item_unique'
            );
        });

        // The restored unique index serves the baseline_assessment_id foreign
        // key again, so the replacement index is no longer needed. Dropped
        // last, mirroring the ordering in up().
        Schema::table('baseline_question_snapshots', function (Blueprint $table) {
            $table->dropIndex('bqs_assessment_id_fk_idx');
        });
    }
};
