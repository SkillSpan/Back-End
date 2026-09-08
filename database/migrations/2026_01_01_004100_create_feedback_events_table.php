<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recommendation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('event_type', ['view', 'click', 'save', 'apply', 'reject', 'complete', 'rating']);
            $table->decimal('rating_value', 3, 2)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_events');
    }
};
