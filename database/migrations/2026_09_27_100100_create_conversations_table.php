<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('conversations')) {
            Schema::create('conversations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mentor_student_connection_id')->constrained()->cascadeOnDelete();
                $table->enum('status', ['active', 'archived', 'closed'])->default('active');
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->timestamp('last_message_at')->nullable();
                // Privacy/retention: conversations past this timestamp are
                // eligible for automated archival or purge by a scheduled
                // cleanup command. NULL = no expiry (admin-set connections).
                $table->timestamp('retention_expires_at')->nullable();
                $table->text('archived_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['mentor_student_connection_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
