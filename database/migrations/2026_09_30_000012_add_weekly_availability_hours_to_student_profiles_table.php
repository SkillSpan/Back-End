<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap v1 integration — a numeric weekly availability for the learner.
 *
 * `availability` (free text, e.g. "evenings" / "flexible") is kept as-is
 * and stays independent: this new column carries the concrete number of
 * hours per week the Roadmap algorithm consumes. It is nullable — an
 * unknown value is never coerced to 0 — and decimal so fractional hours
 * (e.g. 20.5) survive the round-trip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->decimal('weekly_availability_hours', 5, 1)
                ->nullable()
                ->after('availability');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn('weekly_availability_hours');
        });
    }
};
