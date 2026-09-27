<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Freeze the CONTENT of each selected question, not just its
 * question-to-skill mapping.
 *
 * The original snapshot only stored (assessment, item, role, skill, weight,
 * critical). Because the live `baseline_assessment_items` row is mutable,
 * editing an item after an assessment started changed what the learner was
 * shown, and — worse — changed the option list used to validate their
 * answers. These columns make the snapshot self-contained and immutable.
 *
 * All four columns are nullable: rows created before this migration have no
 * frozen content. Reads fall back to the live item only for those legacy
 * rows; every row created from now on is fully frozen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('baseline_question_snapshots', function (Blueprint $table) {
            if (! Schema::hasColumn('baseline_question_snapshots', 'question_text')) {
                $table->text('question_text')->nullable()->after('skill_id');
            }

            if (! Schema::hasColumn('baseline_question_snapshots', 'item_type')) {
                $table->string('item_type')->nullable()->after('question_text');
            }

            if (! Schema::hasColumn('baseline_question_snapshots', 'options')) {
                $table->json('options')->nullable()->after('item_type');
            }

            if (! Schema::hasColumn('baseline_question_snapshots', 'item_id')) {
                $table->string('item_id')->nullable()->after('options');
            }
        });
    }

    public function down(): void
    {
        Schema::table('baseline_question_snapshots', function (Blueprint $table) {
            foreach (['item_id', 'options', 'item_type', 'question_text'] as $column) {
                if (Schema::hasColumn('baseline_question_snapshots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
