<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = 'student_profiles';
        $column = 'expected_graduation';

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` VARCHAR(10) NULL");
            DB::statement("UPDATE `{$table}` SET `{$column}` = LEFT(`{$column}`, 4)");
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
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` VARCHAR(10) NULL");
            DB::statement("UPDATE `{$table}` SET `{$column}` = CONCAT(`{$column}`, '-01-01') WHERE `{$column}` IS NOT NULL");
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` DATE NULL");

            return;
        }

        Schema::table($table, function (Blueprint $table) {
            $table->date('expected_graduation')->nullable()->change();
        });

        DB::table($table)->orderBy('id')->each(function ($profile) use ($column) {
            if (filled($profile->{$column})) {
                DB::table($table)->where('id', $profile->id)->update([
                    $column => $profile->{$column}.'-01-01',
                ]);
            }
        });
    }
};
