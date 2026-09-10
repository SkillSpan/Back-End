<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-INT-01 — decision snapshots.
 *
 * One row per intelligence decision (skill-gap + readiness + roadmap
 * triple). The snapshot captures the validated input state BEFORE the
 * FastAPI calls: role id/version, required role skills, learner skill
 * state, availability, and the resolved algorithm/configuration
 * versions. Enough to reproduce or explain every historical result —
 * without storing PII (no email, no name, no credentials).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('decision_uuid')->unique();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('career_role_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('career_role_version');
            $table->string('algorithm_version');
            $table->string('configuration_version');
            $table->string('request_id', 64)->index();
            $table->json('snapshot');
            $table->enum('status', ['pending', 'succeeded', 'failed'])->default('pending');
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->index(['student_profile_id', 'career_role_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_snapshots');
    }
};
