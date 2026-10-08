<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages inside a support request thread.
 *
 * Mirrors `messages` (the mentor↔student table) so the two read the same way,
 * but is deliberately separate: a support thread has no
 * `mentor_student_connection_id`, and mixing the two would make every existing
 * conversation query need a discriminator.
 *
 * `sender_id` is a learner for inbound and a mentor/admin for outbound, so the
 * thread renders the same way in both directions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_messages')) {
            Schema::create('support_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('support_request_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();

                $table->text('body');

                // 'system' is used for the handoff marker ("Transferring you to
                // technical support") so the thread shows what happened without
                // pretending a person wrote it.
                $table->enum('message_type', ['text', 'system'])->default('text');

                $table->json('metadata')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->foreignId('read_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['support_request_id', 'created_at']);
                $table->index(['sender_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};
