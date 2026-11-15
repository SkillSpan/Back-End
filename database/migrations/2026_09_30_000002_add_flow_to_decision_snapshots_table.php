<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('decision_snapshots', 'flow')) {
            Schema::table('decision_snapshots', function (Blueprint $table) {
                $table->string('flow', 32)
                    ->nullable()
                    ->after('decision_uuid')
                    ->comment('intelligence | readiness_legacy');

                $table->index(
                    ['student_profile_id', 'flow', 'status'],
                    'decision_snapshots_profile_flow_status_index',
                );
            });
        }

        $this->backfillFlow();
    }

    public function down(): void
    {
        if (Schema::hasColumn('decision_snapshots', 'flow')) {
            Schema::table('decision_snapshots', function (Blueprint $table) {
                $table->dropIndex('decision_snapshots_profile_flow_status_index');
                $table->dropColumn('flow');
            });
        }
    }

    private function backfillFlow(): void
    {
        DB::table('decision_snapshots')
            ->select('id', 'snapshot')
            ->whereNull('flow')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row->snapshot, true);

                    $flow = is_array($payload) && ($payload['flow'] ?? null) === 'readiness_legacy'
                        ? 'readiness_legacy'
                        : 'intelligence';

                    DB::table('decision_snapshots')
                        ->where('id', $row->id)
                        ->update(['flow' => $flow]);
                }
            });
    }
};
