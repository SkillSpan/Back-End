<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            $table->foreignId('decision_snapshot_id')
                ->nullable()
                ->after('id')
                ->constrained('decision_snapshots')
                ->cascadeOnDelete();
            $table->string('algorithm_version')
                ->nullable()
                ->after('status');
            $table->string('configuration_version')
                ->nullable()
                ->after('algorithm_version');
            $table->string('request_id', 64)
                ->nullable()
                ->after('configuration_version');
            $table->text('explanation')->nullable()->after('request_id');
        });
    }

    public function down(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decision_snapshot_id');
            $table->dropColumn([
                'algorithm_version',
                'configuration_version',
                'request_id',
                'explanation',
            ]);
        });
    }
};
