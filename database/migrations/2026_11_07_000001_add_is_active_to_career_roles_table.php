<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'career_roles_status_is_active_index';

    public function up(): void
    {
        Schema::table('career_roles', function (Blueprint $table) {
            if (! Schema::hasColumn('career_roles', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });

        Schema::table('career_roles', function (Blueprint $table) {
            if (! $this->hasIndex()) {
                $table->index(['status', 'is_active'], self::INDEX);
            }
        });
    }

    public function down(): void
    {
        Schema::table('career_roles', function (Blueprint $table) {
            if ($this->hasIndex()) {
                $table->dropIndex(self::INDEX);
            }
        });

        Schema::table('career_roles', function (Blueprint $table) {
            if (Schema::hasColumn('career_roles', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }

    private function hasIndex(): bool
    {
        foreach (Schema::getIndexes('career_roles') as $index) {
            if (($index['name'] ?? null) === self::INDEX) {
                return true;
            }
        }

        return false;
    }
};
