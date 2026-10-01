<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap v1 contract alignment — `estimated_duration_weeks` is an
 * INTEGER | null (a whole number of calendar weeks), but the column was
 * originally created as DECIMAL(6, 2) by
 * 2026_09_30_000014_add_estimated_duration_weeks_to_roadmap_actions_table.
 *
 * That historical migration is NOT modified (it may already have run on
 * Production); this migration changes the column type to UNSIGNED INTEGER
 * NULL instead.
 *
 * Data safety: a DECIMAL -> INTEGER narrowing would ROUND real fractional
 * values (e.g. 1.50 -> 2) if it were applied blindly. This migration
 * therefore REFUSES to run when any non-integer value exists, instead of
 * silently rewriting data. Resolve those rows deliberately, then re-run.
 */
return new class extends Migration
{
    private const TABLE = 'roadmap_actions';

    private const COLUMN = 'estimated_duration_weeks';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        // Idempotent: the column is already an integer type — nothing to do.
        if ($this->isIntegerType(Schema::getColumnType(self::TABLE, self::COLUMN))) {
            return;
        }

        // Never round real data silently: refuse when fractional values exist.
        if ($this->hasFractionalValues()) {
            throw new RuntimeException(
                'Cannot change roadmap_actions.estimated_duration_weeks to INTEGER: the table '
                .'contains fractional values. FastAPI v1 only returns whole weeks, so these rows '
                .'predate the contract. Review/repair them explicitly, then re-run this migration. '
                .'No data was modified.'
            );
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unsignedInteger(self::COLUMN)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        // Idempotent: already the original DECIMAL(6, 2) — nothing to do.
        if (! $this->isIntegerType(Schema::getColumnType(self::TABLE, self::COLUMN))) {
            return;
        }

        // Revert to exactly the type declared by the original migration.
        // INTEGER -> DECIMAL is lossless, so no data guard is required.
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->decimal(self::COLUMN, 6, 2)->nullable()->change();
        });
    }

    private function isIntegerType(string $type): bool
    {
        return str_contains(strtolower($type), 'int');
    }

    private function hasFractionalValues(): bool
    {
        return DB::table(self::TABLE)
            ->whereNotNull(self::COLUMN)
            ->get([self::COLUMN])
            ->contains(function (object $row): bool {
                $value = (float) $row->{self::COLUMN};

                return $value !== (float) (int) $value;
            });
    }
};
