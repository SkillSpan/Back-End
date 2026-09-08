<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('readiness_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('career_role_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('career_role_version');
            $table->decimal('score', 5, 2);
            $table->decimal('skill_match_component', 5, 2)->nullable();
            $table->decimal('practical_experience_component', 5, 2)->nullable();
            $table->decimal('assessment_reliability_component', 5, 2)->nullable();
            $table->decimal('profile_completeness_component', 5, 2)->nullable();
            $table->boolean('critical_cap_applied')->default(false);
            $table->enum('band', ['foundation_needed', 'developing', 'moderate_readiness', 'near_ready', 'highly_ready']);
            $table->string('algorithm_version');
            $table->timestamp('calculated_at');
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('readiness_results');
    }
};
