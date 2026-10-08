<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives specializations the two fields the admin management page needs.
 *
 *  - `description` — the "Description" column and form field on
 *    /admin/specializations. Nullable, so existing rows are untouched.
 *
 *  - `is_free_track` — marks the special "Self-Learning / Free Track"
 *    specialization. When it is set, the career-role selection returns
 *    EVERY career role in the system instead of only the linked ones, so a
 *    self-taught learner is not restricted to an academic track.
 *
 *    A dedicated flag is used rather than a name comparison: the behaviour
 *    must not break the moment an administrator renames the row, and it is
 *    the "clear relationship" the spec asks for instead of inserting a
 *    pivot row for every (free track, career role) pair.
 *
 * Both columns are additive; no existing column or row is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('specializations', function (Blueprint $table) {
            if (! Schema::hasColumn('specializations', 'description')) {
                $table->text('description')->nullable()->after('name');
            }

            if (! Schema::hasColumn('specializations', 'is_free_track')) {
                $table->boolean('is_free_track')->default(false)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('specializations', function (Blueprint $table) {
            if (Schema::hasColumn('specializations', 'is_free_track')) {
                $table->dropColumn('is_free_track');
            }

            if (Schema::hasColumn('specializations', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
