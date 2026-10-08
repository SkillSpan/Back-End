<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-REC-01 follow-up — record what the assistant said about its own answer.
 *
 * `response_status` already distinguishes "a provider replied" from "the whole
 * failover chain failed". It does NOT distinguish "the assistant answered the
 * question" from "the assistant replied that it does not know" — both are a
 * successful provider call, so both were recorded as `succeeded`.
 *
 * The service reports the difference explicitly in its `status` field
 * (`answered` | `insufficient_context`), and this column is where Laravel keeps
 * it. It is what the human-handoff offer is built on.
 *
 * No learner content is added here: `answer_status` is an enum-ish string and
 * `grounded` is a boolean. The question and the reply are still not stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_interactions', function (Blueprint $table) {
            if (! Schema::hasColumn('assistant_interactions', 'answer_status')) {
                $table->string('answer_status')->nullable()->after('response_status');
            }

            if (! Schema::hasColumn('assistant_interactions', 'grounded')) {
                $table->boolean('grounded')->nullable()->after('answer_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assistant_interactions', function (Blueprint $table) {
            if (Schema::hasColumn('assistant_interactions', 'grounded')) {
                $table->dropColumn('grounded');
            }

            if (Schema::hasColumn('assistant_interactions', 'answer_status')) {
                $table->dropColumn('answer_status');
            }
        });
    }
};
