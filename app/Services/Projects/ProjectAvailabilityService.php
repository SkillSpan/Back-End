<?php

namespace App\Services\Projects;

use App\Models\Project;
use stdClass;

class ProjectAvailabilityService
{
    public function isAvailable(Project $project): bool
    {
        return $this->check($project)->available;
    }

    public function check(Project $project): object
    {
        $reasons = [];

        if ($project->status !== 'open') {
            $reasons[] = 'Project is not open (current status: '.$project->status.').';
        }

        if ($project->application_deadline !== null && $project->application_deadline->lt(now())) {
            $reasons[] = 'Application deadline has passed.';
        }

        $result = new stdClass();
        $result->available = empty($reasons);
        $result->reasons = $reasons;

        return $result;
    }
}
