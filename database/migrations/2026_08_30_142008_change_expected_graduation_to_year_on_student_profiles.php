<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The learner profile stores "Expected Graduation" as a year only (the
 * Profile Setup screen offers a year picker, e.g. "2031"). The field was
 * a full date; convert it to a plain 4-digit integer (year) so the
 * frontend's year value is stored and validated naturally instead of
 * fabricating a fake first-of-the-year timestamp. This keys off the
 * learner-profile task that replaced the FK academic fields with free
 * text and keeps the profile shape aligned with the frontend.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('student_profiles')->get()->each(function ($profile) {
            if (filled($profile->expected_graduation)) {
                $year = (string) substr((string) $profile->expected_graduation, 0, 4);
                DB::table('student_profiles')
                    ->where('id', $profile->id)
                    ->update(['expected_graduation' => $year]);
            }
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->integer('expected_graduation')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->date('expected_graduation')->nullable()->change();
        });

        DB::table('student_profiles')->get()->each(function ($profile) {
            if (filled($profile->expected_graduation)) {
                $asDate = $profile->expected_graduation.'-01-01';
                DB::table('student_profiles')
                    ->where('id', $profile->id)
                    ->update(['expected_graduation' => $asDate]);
            }
        });
    }
};
