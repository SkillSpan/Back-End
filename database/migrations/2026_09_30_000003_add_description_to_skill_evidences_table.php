<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `skill_evidences` had no `description` column at all, yet
 * `EvidenceController::store()` already validated a `description` input
 * ('sometimes', 'string', 'max:500'). The field was therefore accepted and
 * then silently discarded — the request succeeded with 201 and nothing was
 * stored, with no error to tell the caller. This adds the missing column so
 * the validated value has somewhere to live.
 *
 * The column is NULLABLE on purpose: existing rows have no description and we
 * must not invent placeholder text for them. The API returns
 * `description: null` for those rows, which is the honest answer.
 *
 * Type is `string` (VARCHAR 500) rather than `text` to match the existing
 * validation cap exactly — max:500 means a longer value is a 422, so the
 * column can never need to hold more.
 *
 * Guarded with `hasColumn` so a re-run on a database that already has the
 * column (e.g. after a partially-applied migration) is a no-op instead of an
 * error. `down()` is a real reversal, not a no-op: dropping the column is the
 * exact inverse of creating it, so `migrate:rollback` genuinely undoes this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_evidences', function (Blueprint $table) {
            if (! Schema::hasColumn('skill_evidences', 'description')) {
                $table->string('description', 500)->nullable()->after('reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('skill_evidences', function (Blueprint $table) {
            if (Schema::hasColumn('skill_evidences', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
