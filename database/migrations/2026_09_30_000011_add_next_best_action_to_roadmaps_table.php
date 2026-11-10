<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            $table->foreignId('next_best_action_id')
                ->nullable()
                ->after('status')
                ->constrained('roadmap_actions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('next_best_action_id');
        });
    }
};
