<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-REC-01 — record which assistant prompt produced an answer.
 *
 * The assistant service reports `prompt_version` on every response (its own
 * `prompt.SYSTEM_PROMPT_VERSION`), and its README is explicit that downstream
 * evaluations reference that value. Without a column for it, the version is
 * dropped on arrival and an answer can never be traced back to the prompt that
 * generated it — which makes the evaluation reference meaningless.
 *
 * Kept separate from `algorithm_version` rather than reusing it. They answer
 * different questions and are not interchangeable:
 *
 *   algorithm_version    which intelligence algorithm produced the learner's
 *                        stored readiness / gaps / roadmap (REC-01, §8.6)
 *   prompt_version       which assistant prompt explained that data
 *
 * Both are nullable. An interaction may legitimately have neither: a failed
 * call never received a version to record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_interactions', function (Blueprint $table) {
            $table->string('prompt_version')->nullable()->after('algorithm_version');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_interactions', function (Blueprint $table) {
            $table->dropColumn('prompt_version');
        });
    }
};
