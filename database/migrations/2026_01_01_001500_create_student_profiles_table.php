<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('education')->nullable();
            $table->string('specialization')->nullable();
            $table->string('career_status')->nullable();
            $table->json('interests')->nullable();
            $table->string('availability')->nullable();
            $table->string('preferred_work_type')->nullable();
            $table->enum('visibility', ['public', 'organization_only', 'private'])->default('private');
            $table->unsignedTinyInteger('completeness_percent')->default(0);
            $table->enum('enrollment_status', ['enrolled', 'graduated', 'on_leave'])->default('enrolled');
            $table->boolean('graduation_status')->default(false);
            $table->date('graduation_date')->nullable();
            $table->foreignId('primary_career_role_id')->nullable()->constrained('career_roles')->nullOnDelete();
            $table->boolean('consent_given')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
