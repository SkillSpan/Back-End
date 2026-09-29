<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-MATCH-02 — add the `hide` feedback event.
 *
 * US-MATCH-02 requires recommendation feedback for save / hide / decline. The
 * existing `feedback_events.event_type` enum already covers save, apply, reject
 * and the rest, but has no `hide`. The story says: "If `hide` does not exist,
 * add it consistently to the existing event model/schema."
 *
 * `hide` is appended to the existing enum rather than stored in a new column,
 * so all recommendation feedback keeps flowing through one event table and the
 * existing FeedbackEvent model / Recommendation::feedbackEvents() relation.
 *
 * NOT implemented here: the suppression window the story mentions ("hidden or
 * declined recommendations may be suppressed for a configured period"). No such
 * configuration exists anywhere in config/ or the seeders, and the story
 * forbids inventing arbitrary values, so only the event is recorded. The gap is
 * documented in the final report.
 *
 * `decline` maps onto the existing `reject` event rather than a new enum value:
 * the story lists decline alongside the events that already exist, and
 * US-MATCH-02 says not to create parallel concepts where one already exists.
 */
return new class extends Migration
{
    private const EVENTS = [
        'view',
        'click',
        'save',
        'apply',
        'reject',
        'complete',
        'rating',
        'hide',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('feedback_events', 'event_type')) {
            return;
        }

        Schema::table('feedback_events', function (Blueprint $table) {
            $table->enum('event_type', self::EVENTS)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('feedback_events', 'event_type')) {
            return;
        }

        Schema::table('feedback_events', function (Blueprint $table) {
            $table->enum('event_type', [
                'view',
                'click',
                'save',
                'apply',
                'reject',
                'complete',
                'rating',
            ])->change();
        });
    }
};
