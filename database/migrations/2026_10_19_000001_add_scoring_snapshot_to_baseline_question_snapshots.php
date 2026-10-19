<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Freeze the SCORING inputs of each selected question alongside its content.
 *
 * The Intelligence baseline contract requires the caller to send, per
 * question: `correct_answer`, `scoring_rule`, the assessment `weight` and the
 * skill's `slug`. Those values live on the mutable
 * `baseline_assessment_items` / `skills` rows, so reading them at submit time
 * would score the learner against whatever the bank looks like NOW rather
 * than what they were shown when the assessment started.
 *
 * These columns make the snapshot able to rebuild the outbound request on its
 * own. All four are nullable on purpose:
 *
 *  - rows created before this migration have no frozen scoring data, and are
 *    handled by a strictly-verified fallback in the payload builder (never by
 *    inventing defaults);
 *  - `correct_answer` is legitimately null for `scale` items, and
 *    `scoring_rule` is legitimately null for `single_choice` items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('baseline_question_snapshots', function (Blueprint $table) {
            if (! Schema::hasColumn('baseline_question_snapshots', 'correct_answer')) {
                $table->string('correct_answer')->nullable()->after('options');
            }

            if (! Schema::hasColumn('baseline_question_snapshots', 'scoring_rule')) {
                $table->json('scoring_rule')->nullable()->after('correct_answer');
            }

            // Same precision as baseline_assessment_items.weight (decimal 4,3).
            if (! Schema::hasColumn('baseline_question_snapshots', 'weight')) {
                $table->decimal('weight', 4, 3)->nullable()->after('scoring_rule');
            }

            if (! Schema::hasColumn('baseline_question_snapshots', 'skill_slug')) {
                $table->string('skill_slug')->nullable()->after('weight');
            }
        });
    }

    public function down(): void
    {
        Schema::table('baseline_question_snapshots', function (Blueprint $table) {
            foreach (['skill_slug', 'weight', 'scoring_rule', 'correct_answer'] as $column) {
                if (Schema::hasColumn('baseline_question_snapshots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
