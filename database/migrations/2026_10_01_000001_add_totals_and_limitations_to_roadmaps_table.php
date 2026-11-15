<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            $table->decimal('estimated_total_hours', 8, 2)
                ->nullable()
                ->after('explanation');

            $table->unsignedInteger('estimated_duration_weeks')
                ->nullable()
                ->after('estimated_total_hours');

            $table->json('limitations')
                ->nullable()
                ->after('estimated_duration_weeks');
        });
    }

    public function down(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            $table->dropColumn([
                'estimated_total_hours',
                'estimated_duration_weeks',
                'limitations',
            ]);
        });
    }
};
