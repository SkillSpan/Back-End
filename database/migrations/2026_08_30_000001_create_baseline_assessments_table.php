<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baseline_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->string('assessment_type')->default('baseline');
            $table->string('assessment_version');
            $table->enum('status', ['in_progress', 'completed'])->default('in_progress');
            $table->json('progress')->nullable();
            $table->json('responses')->nullable();
            $table->json('result')->nullable();
            $table->json('normalized_skills')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['student_profile_id', 'assessment_type', 'assessment_version'],
                'baseline_attempt_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baseline_assessments');
    }
};
