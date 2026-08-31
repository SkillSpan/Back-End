<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The learner profile stores "Expected Graduation" as a year only (the
 * Profile Setup screen offers a year picker, e.g. "2031"). The field was
 * a full date; convert it to a plain 4-digit integer (year).
 *
 * On MySQL (production / Render) the column is rewritten with explicit
 * ALTER/UPDATE steps: writing a bare year ("2031") back into a DATE column
 * fails under strict mode when real date rows exist, so the column is first
 * widened to VARCHAR, reduced to its leading 4-digit year, then narrowed to
 * a nullable integer. On other drivers (SQLite used by the test-suite) the
 * types are dynamic and the straightforward row rewrite is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = 'student_profiles';
        $column = 'expected_graduation';

        if (DB::getDriverName() === 'mysql') {
            // 1. Widen DATE -> VARCHAR(10) so values can be safely rewritten.
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` VARCHAR(10) NULL");
            // 2. Reduce every stored value to its leading 4-digit year.
            DB::statement("UPDATE `{$table}` SET `{$column}` = LEFT(`{$column}`, 4)");
            // 3. Narrow VARCHAR year -> nullable integer.
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` INT NULL");

            return;
        }

        DB::table($table)->orderBy('id')->each(function ($profile) use ($column) {
            if (filled($profile->{$column})) {
                DB::table($table)->where('id', $profile->id)->update([
                    $column => (int) substr((string) $profile->{$column}, 0, 4),
                ]);
            }
        });

        Schema::table($table, function (Blueprint $table) {
            $table->integer('expected_graduation')->nullable()->change();
        });
    }

    public function down(): void
    {
        $table = 'student_profiles';
        $column = 'expected_graduation';

        if (DB::getDriverName() === 'mysql') {
            // 1. Widen INT year -> VARCHAR(10) so we can append "-01-01".
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` VARCHAR(10) NULL");
            // 2. Turn the bare year back into a full first-of-the-year date.
            DB::statement("UPDATE `{$table}` SET `{$column}` = CONCAT(`{$column}`, '-01-01') WHERE `{$column}` IS NOT NULL");
            // 3. Narrow VARCHAR date -> nullable DATE.
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` DATE NULL");

            return;
        }

        Schema::table($table, function (Blueprint $table) {
            $table->date('expected_graduation')->nullable()->change();
        });

        DB::table($table)->orderBy('id')->each(function ($profile) use ($column) {
            if (filled($profile->{$column})) {
                DB::table($table)->where('id', $profile->id)->update([
                    $column => $profile->{$column} . '-01-01',
                ]);
            }
        });
    }
};
