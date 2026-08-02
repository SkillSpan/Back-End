<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('roadmap_action_id')->nullable()->constrained('roadmap_actions')->nullOnDelete();
            $table->foreignId('learning_resource_id')->nullable()->constrained('learning_resources')->nullOnDelete();
            $table->string('status')->nullable();
            $table->decimal('progress_value', 5, 2)->nullable();
            $table->text('completion_evidence')->nullable();
            $table->string('source')->nullable();
            $table->string('verification_state')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_activities');
    }
};
