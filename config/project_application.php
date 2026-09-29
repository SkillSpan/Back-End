<?php

use App\Models\Application;

return [

    /*
    |--------------------------------------------------------------------------
    | Project application capacity policy
    |--------------------------------------------------------------------------
    |
    | `projects.capacity` is the single source of truth for how many seats a
    | project has. This file holds the POLICY around that number, kept separate
    | so the rule can change without touching the schema or ApplicationService.
    |
    | Business meaning (confirmed 2026-09-29): capacity represents ACTUAL
    | PROJECT SEATS / PARTICIPATION capacity. It is NOT the number of learners
    | who are allowed to discover or apply for the project, and it must never be
    | used as a career-role or skill eligibility mechanism.
    |
    */

    'capacity' => [

        /*
         | Which application statuses occupy a seat.
         |
         | Default: only `accepted` — a seat is taken when it is awarded, not
         | when someone merely applies. Changing this list is the entire change
         | needed to switch capacity semantics; nothing else reads the rule.
         */
        'consuming_statuses' => [Application::STATUS_ACCEPTED],

        /*
         | Whether a project with no seats left rejects a NEW submission.
         |
         | INFERRED DEFAULT, not an explicit business statement. The US-MATCH-02
         | test list requires a "Full project" case under application creation,
         | which only makes sense if submission is rejected once every seat has
         | been awarded. Set to false to let learners keep applying to a full
         | project and have the seat limit bite at acceptance time only.
         */
        'reject_submission_when_full' => true,

    ],

];
