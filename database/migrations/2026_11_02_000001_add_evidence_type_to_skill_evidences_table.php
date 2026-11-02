<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the artifact kind of a learner submission as its OWN column.
 *
 * WHY A NEW COLUMN AND NOT A NEW `source` VALUE
 * ---------------------------------------------
 * `skill_evidences.source` is NOT "what kind of file/link is this". It is the
 * evidence SOURCE of the skill-evaluation model: the five weighted channels
 * that config('services.evidence.source_weights') sums to 1.00 —
 * self_assessment, assessment_test, project_performance, expert_evaluation and
 * certificate — and SkillEvaluationService::recalculate() groups by it to
 * compute level and confidence. A learner submitting a portfolio link is the
 * `certificate` channel (a self-submitted artifact that nobody has assessed
 * yet), which is why the endpoint has always written `source = 'certificate'`.
 *
 * The frontend's evidence kinds (GitHub repository, live deployment, design
 * file, document) are a different axis: they describe the SHAPE of the proof,
 * not the assessment channel it feeds. Folding them into `source` would mean
 * either inventing unweighted enum values (breaking the 1.00 weight budget and
 * therefore every confidence score) or renaming existing values (rewriting
 * history for every stored row). So they live in `evidence_type` instead.
 *
 * BACKWARD COMPATIBILITY
 * ----------------------
 * The column is NULLABLE and appended — no existing column is touched, no
 * existing row is rewritten and no enum is renumbered (MySQL stores ENUM values
 * by position, so editing `source` in place is exactly the corruption this
 * avoids). Rows created before this migration keep `evidence_type = NULL`,
 * which the API reports honestly as "type not recorded" rather than guessing.
 * Every existing endpoint, test and the baseline-assessment writer
 * (which force-creates `assessment_test` evidence) keep working untouched.
 *
 * WHY `string` AND NOT `enum`
 * ---------------------------
 * The allowed values are owned by App\Models\SkillEvidence::EVIDENCE_TYPES and
 * enforced by StoreEvidenceRequest, so an unknown value is still a 422. A
 * VARCHAR is used because this axis is expected to grow with the frontend
 * (video walkthrough, certificate scan, ...), and adding a value to a MySQL
 * ENUM requires an ALTER on a live table — the same reason the enum-append
 * dance was needed for `revision`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('skill_evidences', 'evidence_type')) {
            return;
        }

        Schema::table('skill_evidences', function (Blueprint $table) {
            /*
             * 32 characters is comfortably wider than the longest value the
             * model accepts ("github_repository", 17) and leaves room for the
             * values the frontend is expected to add, without an ALTER.
             */
            $table->string('evidence_type', 32)
                ->nullable()
                ->after('source');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('skill_evidences', 'evidence_type')) {
            return;
        }

        Schema::table('skill_evidences', function (Blueprint $table) {
            $table->dropColumn('evidence_type');
        });
    }
};
