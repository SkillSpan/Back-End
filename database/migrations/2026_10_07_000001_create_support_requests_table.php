<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-REC-01 follow-up — human handoff for the intelligent assistant.
 *
 * When the assistant answers `insufficient_context` (or the learner asks for
 * a human outright), the conversation is escalated to a support person.
 *
 * Deliberately NOT a `conversations` row: `conversations.mentor_student_connection_id`
 * is NOT nullable, so a conversation can only exist between a mentor and a
 * student who are already connected. A learner with no mentor could then never
 * be escalated — exactly the case that needs help most. Support is therefore
 * its own thread, which also keeps mentor↔student messaging free of platform
 * support traffic.
 *
 * `transcript` is a snapshot taken at handoff time, not a live view. It is the
 * one place the learner's own words are persisted, and only because a human
 * needs them to help; `assistant_interactions` still stores no question text.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_requests')) {
            Schema::create('support_requests', function (Blueprint $table) {
                $table->id();

                // The learner who asked for help.
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

                // Optional: the profile the assistant was answering about.
                $table->foreignId('student_profile_id')
                    ->nullable()
                    ->constrained('student_profiles')
                    ->nullOnDelete();

                // The support person (mentor) who picked it up. Null = unclaimed.
                $table->foreignId('assigned_to')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->enum('status', ['pending', 'assigned', 'resolved', 'closed'])
                    ->default('pending');

                // Why the handoff happened. `insufficient_context` when the
                // assistant could not answer, `learner_requested` when the
                // learner asked for a human.
                $table->string('reason')->default('insufficient_context');

                // Short line shown in the support inbox list.
                $table->string('subject')->nullable();

                // Snapshot of the assistant exchange at handoff time:
                // [{role: "learner"|"assistant", body: string, at: iso8601}, ...]
                $table->json('transcript')->nullable();

                // Which assistant interaction triggered this, for audit.
                $table->foreignId('source_interaction_id')
                    ->nullable()
                    ->constrained('assistant_interactions')
                    ->nullOnDelete();

                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['status', 'created_at']);
                $table->index(['user_id', 'created_at']);
                $table->index(['assigned_to', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_requests');
    }
};
