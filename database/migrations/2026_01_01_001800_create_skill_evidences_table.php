<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->enum('source', ['self_assessment', 'assessment_test', 'project_performance', 'expert_evaluation', 'certificate']);
            $table->decimal('value', 4, 2);
            $table->decimal('normalized_value', 4, 2);
            $table->string('reference')->nullable();
            $table->date('evidence_date');
            $table->enum('verification_status', ['pending', 'verified', 'rejected', 'expired'])->default('pending');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reviewer_notes')->nullable();
            $table->decimal('recency_factor', 3, 2)->default(1.00);
            $table->string('source_record_type')->nullable();
            $table->unsignedBigInteger('source_record_id')->nullable();
            $table->timestamps();
            $table->index(['source_record_type', 'source_record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_evidences');
    }
};
