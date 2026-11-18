<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs an inconsistency introduced by two competing create migrations for
 * `saved_career_roles`.
 *
 * History: `2026_11_13_000002_create_saved_career_roles_table` (surrogate `id`
 * + unique index) and `2026_11_09_000001_create_saved_career_roles_table`
 * (composite primary key on user_id/career_role_id, no `id` column) both
 * existed. Because 2026_11_09 sorts first, a database that migrated while both
 * files were present ended up with the composite-key shape, and the later
 * migration short-circuited on its own `hasTable` guard. The 2026_11_09 file was
 * then deleted, so fresh installs create the correct shape while an already
 * migrated database keeps the old one.
 *
 * The application reads `id` (SavedCareerRoleService::list orders by it), so a
 * database stuck on the composite-key shape breaks GET /saved-career-roles with
 * "Unknown column 'id'". This migration converges both shapes on the intended
 * schema without dropping the table or any row.
 */
return new class extends Migration
{
    private const TABLE = 'saved_career_roles';

    private const UNIQUE = 'saved_career_roles_user_role_unique';

    private const USER_INDEX = 'saved_career_roles_user_id_index';

    public function up(): void
    {
        // The create migration owns a missing table; nothing to repair.
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        // Already on the intended shape: only make sure the guard index exists.
        if (Schema::hasColumn(self::TABLE, 'id')) {
            if ($this->isMySqlFamily()) {
                $this->ensureUniqueIndex();
            }

            return;
        }

        // Tests run on SQLite and always build the modern shape, so the MySQL
        // repair below is the only branch that needs raw DDL.
        if (! $this->isMySqlFamily()) {
            return;
        }

        $this->convertCompositePrimaryKey();
    }

    private function isMySqlFamily(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Reversing this would delete the surrogate key and could drop rows that
     * share a (user_id, career_role_id) pair only by primary-key uniqueness.
     * The forward path is additive, so the rollback is deliberately a no-op.
     */
    public function down(): void
    {
        //
    }

    private function convertCompositePrimaryKey(): void
    {
        // InnoDB refuses to drop the primary key while a foreign key depends on
        // it as its only index, so give user_id its own index first.
        $this->ensureIndex(self::USER_INDEX, 'user_id');

        if ($this->hasPrimaryKey()) {
            DB::statement('ALTER TABLE `'.self::TABLE.'` DROP PRIMARY KEY');
        }

        DB::statement(
            'ALTER TABLE `'.self::TABLE.'` '
            .'ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST'
        );

        $this->ensureUniqueIndex();

        // The unique index starts with user_id, so the temporary helper index is
        // redundant once it exists. Dropping it keeps the repaired schema
        // identical to a fresh install.
        if ($this->hasIndex(self::USER_INDEX)) {
            DB::statement('ALTER TABLE `'.self::TABLE.'` DROP INDEX `'.self::USER_INDEX.'`');
        }
    }

    private function ensureUniqueIndex(): void
    {
        if ($this->hasIndex(self::UNIQUE)) {
            return;
        }

        // Duplicate rows would make this fail loudly rather than silently
        // discarding data, which is the correct outcome for a data repair.
        DB::statement(
            'ALTER TABLE `'.self::TABLE.'` ADD UNIQUE INDEX `'.self::UNIQUE.'` (`user_id`, `career_role_id`)'
        );
    }

    private function ensureIndex(string $name, string $column): void
    {
        if ($this->hasIndex($name) || $this->columnHasLeadingIndex($column)) {
            return;
        }

        DB::statement('ALTER TABLE `'.self::TABLE.'` ADD INDEX `'.$name.'` (`'.$column.'`)');
    }

    private function hasIndex(string $name): bool
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    private function hasPrimaryKey(): bool
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (($index['primary'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only a secondary index can stand in for the foreign key once the primary
     * key is dropped, so the primary index is deliberately not counted here.
     */
    private function columnHasLeadingIndex(string $column): bool
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (($index['primary'] ?? false) === true) {
                continue;
            }

            $columns = $index['columns'] ?? [];

            if (($columns[0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
};
