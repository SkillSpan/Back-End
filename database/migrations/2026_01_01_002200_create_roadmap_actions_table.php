<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_id')->constrained()->cascadeOnDelete();
            $table->enum('phase', ['foundations', 'core_skills', 'applied_practice', 'career_readiness']);
            $table->enum('type', ['assessment', 'resource', 'practice', 'simulated_project', 'real_project']);
            $table->foreignId('target_skill_id')->nullable()->constrained('skills')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('priority_score', 8, 4)->nullable();
            $table->decimal('estimated_hours', 6, 2)->nullable();
            $table->unsignedInteger('order_index')->default(0);
            $table->enum('status', ['not_started', 'in_progress', 'blocked', 'completed', 'skipped'])->default('not_started');
            $table->text('completion_criteria')->nullable();
            $table->text('skip_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_actions');
    }
};
