<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Per-user hiding of a conversation.
 *
 * WHY A PIVOT AND NOT A `deleted_at` ON `conversations`
 * ----------------------------------------------------
 * A mentoring conversation is shared by two people, and the thread carries an
 * explicit notice that "communication is recorded for audit purposes". Deleting
 * the conversation row would therefore destroy the other participant's history
 * and the audit trail — so "delete" in the UI must mean "remove it from *my*
 * list", not "erase it for both of us".
 *
 * A row here hides one conversation from one user. The conversation, its
 * messages and the audit records are untouched, and the other participant is
 * unaffected. Rows are removed when a new message arrives, so a conversation
 * the user hid reappears the moment someone writes in it — the same behaviour
 * every chat app has, and it means nothing can be lost silently.
 *
 * `unique(conversation_id, user_id)` makes hiding idempotent: hiding twice is a
 * no-op instead of a duplicate row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_user_hides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('hidden_at');
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_user_hides');
    }
};
