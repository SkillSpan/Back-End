<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmap_actions', function (Blueprint $table) {
            $table->decimal('estimated_duration_weeks', 6, 2)
                ->nullable()
                ->after('estimated_hours');
        });
    }

    public function down(): void
    {
        Schema::table('roadmap_actions', function (Blueprint $table) {
            $table->dropColumn('estimated_duration_weeks');
        });
    }
};
