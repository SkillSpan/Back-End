<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * US-INT-01 — record WHICH flow produced a decision snapshot.
 *
 * Two flows write decision_snapshots rows and both mark them
 * `succeeded`:
 *
 *   - the intelligence flow (POST /intelligence/calculate) —
 *     skill gaps + readiness + roadmap;
 *   - the legacy composite readiness flow (POST /readiness/calculate) —
 *     readiness + skill-match gaps, never a roadmap.
 *
 * They were only distinguishable by a `flow` key buried inside the JSON
 * snapshot payload, so `GET /intelligence/latest` — which selects the
 * newest SUCCEEDED snapshot — could return a readiness decision and
 * report `roadmap: null` while a roadmap for that learner and role sat in
 * the database untouched. Promoting the marker to a real, indexed column
 * makes the two flows explicitly separable instead of inferred from a
 * JSON blob.
 *
 * Additive and idempotent: guarded by hasColumn(), and existing rows are
 * backfilled from their own snapshot payload before anything reads the
 * column.
 */
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

    /**
     * Label pre-existing rows from their own payload, so the read path can
     * rely on the column alone. Rows written before the legacy readiness
     * flow started tagging itself default to the intelligence flow, which
     * is what the old read path already assumed for them.
     */
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
