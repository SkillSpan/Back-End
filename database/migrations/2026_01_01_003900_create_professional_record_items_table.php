<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_record_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('verification_state', ['self_declared', 'verified'])->default('self_declared');
            $table->enum('visibility', ['public', 'organization_only', 'private'])->default('private');
            $table->date('achieved_at')->nullable();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_record_items');
    }
};
