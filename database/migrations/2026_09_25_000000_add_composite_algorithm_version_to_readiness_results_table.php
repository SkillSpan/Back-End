<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('readiness_results', function (Blueprint $table) {
            if (! Schema::hasColumn('readiness_results', 'composite_algorithm_version')) {
                $table->string('composite_algorithm_version')
                    ->nullable()
                    ->after('configuration_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('readiness_results', function (Blueprint $table) {
            if (Schema::hasColumn('readiness_results', 'composite_algorithm_version')) {
                $table->dropColumn('composite_algorithm_version');
            }
        });
    }
};
