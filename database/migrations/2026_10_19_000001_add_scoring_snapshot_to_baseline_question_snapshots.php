<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
