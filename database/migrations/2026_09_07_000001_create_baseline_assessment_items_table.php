<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baseline_assessment_items', function (Blueprint $table) {
            $table->id();
            $table->string('assessment_version');
            $table->string('item_id');
            $table->enum('item_type', ['single_choice', 'scale']);
            $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();
            $table->json('options');
            $table->string('correct_answer')->nullable();
            $table->json('scoring_rule')->nullable();
            $table->decimal('weight', 4, 3)->default(1.000);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['assessment_version', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baseline_assessment_items');
    }
};
