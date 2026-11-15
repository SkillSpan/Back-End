<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
