<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_interactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();

            $table->string('intent');

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

            $table->enum('report_status', ['unsafe', 'irrelevant', 'unfair', 'incorrect'])
                ->nullable();

            $table->text('report_reason')->nullable();
            $table->timestamp('reported_at')->nullable();

            $table->string('algorithm_version')->nullable();
            $table->string('configuration_version')->nullable();

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
