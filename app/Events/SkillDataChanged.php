<?php

namespace App\Events;

use App\Models\StudentProfile;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * US-INT-01 §24 — an approved data change touched the learner's skill
 * state (approved baseline assessment, approved evidence review,
 * approved project evaluation when it exists, or a career-role change).
 *
 * Listeners recalculate intelligence for the affected learner. The
 * event carries only identifiers — no PII, no payloads.
 */
class SkillDataChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly StudentProfile $studentProfile,
        public readonly string $source,
    ) {}
}
