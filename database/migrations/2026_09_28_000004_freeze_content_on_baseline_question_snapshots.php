<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
