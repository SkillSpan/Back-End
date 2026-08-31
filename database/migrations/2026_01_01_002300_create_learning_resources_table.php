<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_resources', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('provider')->nullable();
            $table->string('url');
            $table->string('type')->nullable();
            $table->decimal('level', 3, 2)->nullable();
            $table->string('language', 10)->nullable();
            $table->decimal('effort_hours', 6, 2)->nullable();
            $table->decimal('cost', 8, 2)->nullable();
            $table->foreignId('skill_id')->nullable()->constrained('skills')->nullOnDelete();
            $table->text('prerequisites')->nullable();
            $table->enum('status', ['active', 'inactive', 'flagged'])->default('active');
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_resources');
    }
};
