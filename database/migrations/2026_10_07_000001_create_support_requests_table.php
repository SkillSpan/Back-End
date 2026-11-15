<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_requests')) {
            Schema::create('support_requests', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

                $table->foreignId('student_profile_id')
                    ->nullable()
                    ->constrained('student_profiles')
                    ->nullOnDelete();

                $table->foreignId('assigned_to')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->enum('status', ['pending', 'assigned', 'resolved', 'closed'])
                    ->default('pending');

                $table->string('reason')->default('insufficient_context');

                $table->string('subject')->nullable();

                $table->json('transcript')->nullable();

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
