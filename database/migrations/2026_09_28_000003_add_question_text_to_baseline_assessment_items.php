<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The baseline item bank had no question content column at all — clients
 * only ever received `item_id` + `options`, with no prompt to render. This
 * adds the real prompt text.
 *
 * The column is NULLABLE on purpose: pre-existing items have no authored
 * text, and we must not invent placeholder question text. The API returns
 * `question_text: null` for those items and the client falls back to its
 * own copy keyed by `item_id` until content is authored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('baseline_assessment_items', function (Blueprint $table) {
            if (! Schema::hasColumn('baseline_assessment_items', 'question_text')) {
                $table->text('question_text')->nullable()->after('item_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('baseline_assessment_items', function (Blueprint $table) {
            if (Schema::hasColumn('baseline_assessment_items', 'question_text')) {
                $table->dropColumn('question_text');
            }
        });
    }
};
