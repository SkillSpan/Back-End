<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'roadmap_actions';

    private const COLUMN = 'estimated_duration_weeks';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        if ($this->isIntegerType(Schema::getColumnType(self::TABLE, self::COLUMN))) {
            return;
        }

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

        if (! $this->isIntegerType(Schema::getColumnType(self::TABLE, self::COLUMN))) {
            return;
        }

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
