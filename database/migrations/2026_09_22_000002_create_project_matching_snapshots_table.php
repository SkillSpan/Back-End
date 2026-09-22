<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 8 — project matching decision snapshots.
 *
 * One immutable row per validated project-matching input set, captured
 * BEFORE the matching/payload layer (Task 9). Stores a copy of the
 * pre-matching validated state so that historical matching decisions
 * remain reproducible even if the underlying project or learner state
 * changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_matching_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('project_version');
            $table->string('algorithm_version');
            $table->string('configuration_version');
            $table->string('request_id', 64)->index();
            $table->json('snapshot');
            $table->enum('status', ['pending', 'validated', 'failed'])->default('pending');
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();

            $table->index(['student_profile_id', 'project_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_matching_snapshots');
    }
};
