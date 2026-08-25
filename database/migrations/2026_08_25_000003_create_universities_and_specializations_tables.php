<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference tables backing student_profiles.university_id /
 * student_profiles.specialization_id (US-AUTH-07 / learner-profile task:
 * "define university/specialization as Foreign Keys — not plain text").
 * Populated by UniversitiesAndSpecializationsSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('universities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('specializations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specializations');
        Schema::dropIfExists('universities');
    }
};
