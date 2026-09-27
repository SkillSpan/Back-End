<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-REC-01 — permitted assistant interaction metadata.
 *
 * SRS v1.1 §12.5 requires AI conversations to "follow approved privacy,
 * retention, and consent rules". The approved reading here is data
 * minimisation: this table stores audit *metadata* only — who asked,
 * which intent, which context the answer was derived from, and the
 * outcome. It deliberately does NOT store the question or the assistant's
 * reply text.
 *
 * `context_reference` is therefore a fingerprint of the context snapshot
 * that was sent, not the snapshot itself. It is enough to prove which
 * data an answer was derived from, and to reproduce the decision, without
 * persisting learner content (SRS v1.1 §9.5, REC-01).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_interactions', function (Blueprint $table) {
            $table->id();

            // Every row belongs to exactly one learner. BR-11 / §12.5: all
            // reads of this table are scoped by this column.
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();

            // Which permitted assistant capability was requested
            // (§12.5 scope of the assistant). Validated before any call.
            $table->string('intent');

            // Fingerprint of the context snapshot sent to the service.
            $table->string('context_reference');

            $table->foreignId('related_recommendation_id')
                ->nullable()
                ->constrained('recommendations')
                ->nullOnDelete();

            $table->foreignId('related_project_id')
                ->nullable()
                ->constrained('projects')
                ->nullOnDelete();

            $table->enum('response_status', ['pending', 'succeeded', 'failed'])
                ->default('pending');

            // §12.6 incident flow / REC-08: a learner may report a response
            // as unsafe, irrelevant, unfair or incorrect.
            $table->enum('report_status', ['unsafe', 'irrelevant', 'unfair', 'incorrect'])
                ->nullable();

            // §12.6 requires the recorded reason, not just the classification.
            $table->text('report_reason')->nullable();
            $table->timestamp('reported_at')->nullable();

            // Reproducibility (REC-01, §8.6) — resolved, never fabricated.
            $table->string('algorithm_version')->nullable();
            $table->string('configuration_version')->nullable();

            // Stable failure code when response_status = failed, so a
            // failed interaction is auditable without storing a payload.
            $table->string('failure_code')->nullable();

            $table->uuid('request_id');

            $table->timestamps();

            $table->index(['student_profile_id', 'created_at']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_interactions');
    }
};
