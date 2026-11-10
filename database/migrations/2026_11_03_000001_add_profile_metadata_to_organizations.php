<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'profile_updated_at')) {
                $table->timestamp('profile_updated_at')->nullable()->after('verified_at');
            }

            if (! Schema::hasColumn('organizations', 'profile_updated_by')) {
                $table->unsignedBigInteger('profile_updated_by')->nullable()->after('profile_updated_at');
            }

            if (! Schema::hasColumn('organizations', 'profile_completeness')) {
                $table->unsignedTinyInteger('profile_completeness')->nullable()->after('profile_updated_by');
            }

            if (! Schema::hasColumn('organizations', 'completeness_version')) {
                $table->string('completeness_version', 32)->nullable()->after('profile_completeness');
            }

            if (! Schema::hasColumn('organizations', 'reverification_required_at')) {
                $table->timestamp('reverification_required_at')->nullable()->after('completeness_version');
            }
        });

        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasIndex('organizations', 'organizations_reverification_required_at_index')) {
                $table->index('reverification_required_at', 'organizations_reverification_required_at_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasIndex('organizations', 'organizations_reverification_required_at_index')) {
                $table->dropIndex('organizations_reverification_required_at_index');
            }
        });

        Schema::table('organizations', function (Blueprint $table) {
            $columns = array_values(array_filter(
                [
                    'profile_updated_at',
                    'profile_updated_by',
                    'profile_completeness',
                    'completeness_version',
                    'reverification_required_at',
                ],
                fn (string $column) => Schema::hasColumn('organizations', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
