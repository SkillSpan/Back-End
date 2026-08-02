<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submission_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('evaluator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('rubric_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('rubric_version');
            $table->enum('status', ['draft','finalized','moderated','voided'])->default('draft');
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->text('self_reflection')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->text('moderation_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluations');
    }
};
