<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BA-02 — settle the Evidence review states.
 *
 * SRS v1.1 §13.6 (Table 75, "Core status dictionaries") lists the Evidence
 * states, and SRS SKL-05 (Must) requires an authorised reviewer to be able to
 * "verify, reject, or request clarification for submitted evidence", with
 * "State changes include reason, reviewer, timestamp, and notifications".
 * UC-03 step 4 repeats the same three reviewer outcomes.
 *
 * The backend only ever persisted `pending`, `verified`, `rejected` and
 * `expired`, so the "request clarification" outcome from SKL-05 had nowhere to
 * live — a reviewer could only approve or reject. `revision` is that missing
 * outcome, and it is the value the frontend already models.
 *
 * `revision` is APPENDED to the existing enum rather than introduced as a new
 * column, exactly the way `hide` was appended to `feedback_events.event_type`
 * (2026_09_29_000005) and `text` to `baseline_assessment_items.item_type`
 * (2026_10_08_000002). Appending is deliberate and not cosmetic: MySQL stores
 * an ENUM value by its position, so inserting `revision` anywhere other than
 * the end would silently renumber the existing values and corrupt every row
 * already persisted. No existing state is renamed and no existing row changes.
 *
 * The transition rules that go with this state live in
 * App\Models\SkillEvidence::REVIEWABLE_STATUSES / REVIEW_OUTCOMES and are
 * enforced by EvidenceController::review().
 */
return new class extends Migration
{
    /**
     * The enum as it must be AFTER this migration.
     *
     * `revision` is last on purpose — see the class doc block.
     */
    private const STATUSES = [
        'pending',
        'verified',
        'rejected',
        'expired',
        'revision',
    ];

    /**
     * The enum exactly as `2026_01_01_001800_create_skill_evidences_table`
     * created it, so `down()` is a real reversal.
     */
    private const ORIGINAL_STATUSES = [
        'pending',
        'verified',
        'rejected',
        'expired',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('skill_evidences', 'verification_status')) {
            return;
        }

        Schema::table('skill_evidences', function (Blueprint $table) {
            $table->enum('verification_status', self::STATUSES)
                ->default('pending')
                ->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('skill_evidences', 'verification_status')) {
            return;
        }

        /*
         * A row may already hold `revision` by the time this is rolled back.
         * Rather than let the column truncate it to an empty string, move those
         * rows to `pending` first: `pending` is the non-terminal state the row
         * was in before a reviewer acted on it, so the evidence simply becomes
         * reviewable again instead of losing its status.
         */
        DB::table('skill_evidences')
            ->where('verification_status', 'revision')
            ->update(['verification_status' => 'pending']);

        Schema::table('skill_evidences', function (Blueprint $table) {
            $table->enum('verification_status', self::ORIGINAL_STATUSES)
                ->default('pending')
                ->change();
        });
    }
};
