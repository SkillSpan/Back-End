<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
