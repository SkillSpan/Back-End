<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('baseline_question_snapshots', function (Blueprint $table) {
            $table->index('baseline_assessment_id', 'bqs_assessment_id_fk_idx');
        });

        Schema::table('baseline_question_snapshots', function (Blueprint $table) {
            $table->dropUnique('bqs_assessment_item_unique');
        });

        Schema::table('baseline_question_snapshots', function (Blueprint $table) {
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

        Schema::table('baseline_question_snapshots', function (Blueprint $table) {
            $table->dropIndex('bqs_assessment_id_fk_idx');
        });
    }
};
