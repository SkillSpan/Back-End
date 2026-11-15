<?php

namespace App\Events;

use App\Models\StudentProfile;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SkillDataChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly StudentProfile $studentProfile,
        public readonly string $source,
    ) {}
}
