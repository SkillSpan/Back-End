<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('career_role_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('career_role_version');
            $table->unsignedInteger('version')->default(1);
            $table->enum('status', ['active','superseded'])->default('active');
            $table->timestamp('generated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmaps');
    }
};
