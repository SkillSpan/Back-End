<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a free-text answer type to the baseline item bank.
 *
 * The Dynamic Assessment admin page manages questions with two answer
 * shapes: a multiple-choice question (the existing `single_choice`) and a
 * free-text question. `single_choice` and `scale` were the only two values
 * the `baseline_assessment_items.item_type` enum allowed, so a text question
 * could not be stored at all.
 *
 * `text` is APPENDED to the existing enum rather than introduced as a new
 * column or table, exactly the way `hide` was appended to
 * `feedback_events.event_type` (2026_09_29_000005). Everything else the text
 * type needs already exists: the prompt lives in `question_text` and the
 * model answer in the existing `correct_answer` column — no schema is
 * duplicated and no existing row changes.
 *
 * `baseline_question_snapshots.item_type` is a plain string column, so a
 * text question snapshots without any further change.
 */
return new class extends Migration
{
    private const TYPES = ['single_choice', 'scale', 'text'];

    public function up(): void
    {
        if (! Schema::hasColumn('baseline_assessment_items', 'item_type')) {
            return;
        }

        Schema::table('baseline_assessment_items', function (Blueprint $table) {
            $table->enum('item_type', self::TYPES)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('baseline_assessment_items', 'item_type')) {
            return;
        }

        Schema::table('baseline_assessment_items', function (Blueprint $table) {
            $table->enum('item_type', ['single_choice', 'scale'])->change();
        });
    }
};
