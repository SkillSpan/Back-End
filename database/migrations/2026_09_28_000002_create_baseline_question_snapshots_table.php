<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baseline_question_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('baseline_assessment_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('baseline_assessment_item_id')
                ->constrained('baseline_assessment_items')
                ->cascadeOnDelete();
            $table->foreignId('career_role_id')
                ->constrained('career_roles')
                ->cascadeOnDelete();
            $table->foreignId('skill_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->decimal('importance_weight', 4, 3);
            $table->boolean('is_critical')->default(false);
            $table->timestamps();

            $table->unique(
                ['baseline_assessment_id', 'baseline_assessment_item_id'],
                'bqs_assessment_item_unique'
            );
            $table->index(['career_role_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baseline_question_snapshots');
    }
};
