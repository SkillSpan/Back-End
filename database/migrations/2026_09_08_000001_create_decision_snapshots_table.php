<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('decision_uuid')->unique();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('career_role_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('career_role_version');
            $table->string('algorithm_version');
            $table->string('configuration_version');
            $table->string('request_id', 64)->index();
            $table->json('snapshot');
            $table->enum('status', ['pending', 'succeeded', 'failed'])->default('pending');
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->index(['student_profile_id', 'career_role_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_snapshots');
    }
};
