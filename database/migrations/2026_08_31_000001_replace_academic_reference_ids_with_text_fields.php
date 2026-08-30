<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the learner-profile university / specialization Foreign Keys
 * with free-text fields (SRS change): university_id / specialization_id
 * referenced reference tables, but the learner just types (or picks from
 * an autocomplete list) the university name, their university student
 * number, and the specialization name. Storing plain text removes the
 * dependency on seeded reference rows and the "specialization id is
 * required" validation failure when those tables are empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->string('university_name')->nullable()->after('user_id');
            $table->string('student_university_number')->nullable()->after('university_name');
            $table->string('specialization')->nullable()->after('student_university_number');
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('university_id');
            $table->dropConstrainedForeignId('specialization_id');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->foreignId('university_id')->nullable()->constrained('universities')->nullOnDelete();
            $table->foreignId('specialization_id')->nullable()->constrained('specializations')->nullOnDelete();
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn(['university_name', 'student_university_number', 'specialization']);
        });
    }
};
