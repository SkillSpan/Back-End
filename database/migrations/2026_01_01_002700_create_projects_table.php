<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['simulation','company_sponsored']);
            $table->string('domain')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('objectives')->nullable();
            $table->json('learning_outcomes')->nullable();
            $table->decimal('difficulty', 3, 2)->nullable();
            $table->string('work_mode')->nullable();
            $table->string('role')->nullable();
            $table->string('schedule')->nullable();
            $table->unsignedInteger('capacity')->default(1);
            $table->unsignedInteger('min_team_size')->nullable();
            $table->date('application_deadline')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->enum('status', ['draft','pending_review','open','closed','in_progress','completed','archived'])->default('draft');
            $table->enum('confidentiality', ['public','restricted'])->default('public');
            $table->foreignId('rubric_id')->nullable()->constrained('rubrics')->nullOnDelete();
            $table->foreignId('cloned_from_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
