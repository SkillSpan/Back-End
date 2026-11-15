<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'deactivated_at')) {
                $table->timestamp('deactivated_at')->nullable()->after('verified_at');
            }

            if (! Schema::hasColumn('organizations', 'deactivation_reason')) {
                $table->string('deactivation_reason', 500)->nullable()->after('deactivated_at');
            }
        });

        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasIndex('organizations', 'organizations_deactivated_at_index')) {
                $table->index('deactivated_at', 'organizations_deactivated_at_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasIndex('organizations', 'organizations_deactivated_at_index')) {
                $table->dropIndex('organizations_deactivated_at_index');
            }
        });

        Schema::table('organizations', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['deactivated_at', 'deactivation_reason'],
                fn (string $column) => Schema::hasColumn('organizations', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
