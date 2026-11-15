<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('notifications', 'organization_id')) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('user_id');
            }

            if (! Schema::hasColumn('notifications', 'entity_type')) {
                $table->string('entity_type')->nullable()->after('link');
            }

            if (! Schema::hasColumn('notifications', 'entity_id')) {
                $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
            }
        });

        Schema::table('notifications', function (Blueprint $table) {
            if (! Schema::hasIndex('notifications', 'notifications_organization_id_index')) {
                $table->index('organization_id', 'notifications_organization_id_index');
            }

            if (! Schema::hasIndex('notifications', 'notifications_entity_type_entity_id_index')) {
                $table->index(['entity_type', 'entity_id'], 'notifications_entity_type_entity_id_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (Schema::hasIndex('notifications', 'notifications_organization_id_index')) {
                $table->dropIndex('notifications_organization_id_index');
            }

            if (Schema::hasIndex('notifications', 'notifications_entity_type_entity_id_index')) {
                $table->dropIndex('notifications_entity_type_entity_id_index');
            }
        });

        Schema::table('notifications', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['organization_id', 'entity_type', 'entity_id'],
                fn (string $column) => Schema::hasColumn('notifications', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
