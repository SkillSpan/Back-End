<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learner-profile academic fields (learner-profile task list), applied to
 * the existing student_profiles table per team decision — the table keeps
 * its established Student naming instead of a new learner_profiles table.
 *
 * university_id / specialization_id replace the former free-text
 * education / specialization columns, which are dropped here: the SRS
 * explicitly requires these two values as normalized Foreign Keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->foreignId('university_id')
                ->nullable()
                ->after('user_id')
                ->constrained('universities')
                ->nullOnDelete();
            $table->foreignId('specialization_id')
                ->nullable()
                ->after('university_id')
                ->constrained('specializations')
                ->nullOnDelete();
            $table->string('academic_level')->nullable()->after('specialization_id');
            $table->date('expected_graduation')->nullable()->after('academic_level');
            $table->text('bio')->nullable()->after('expected_graduation');
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn(['education', 'specialization']);
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->string('education')->nullable();
            $table->string('specialization')->nullable();
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('university_id');
            $table->dropConstrainedForeignId('specialization_id');
            $table->dropColumn(['academic_level', 'expected_graduation', 'bio']);
        });
    }
};
