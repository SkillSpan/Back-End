<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messages')) {
            Schema::create('messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
                $table->text('body');
                $table->enum('message_type', ['text', 'system', 'chatbot'])->default('text');
                // Arbitrary metadata: attachments refs, edited_at, etc.
                $table->json('metadata')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->foreignId('read_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['conversation_id', 'created_at']);
                $table->index(['sender_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
