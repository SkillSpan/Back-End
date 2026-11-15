<?php

use App\Models\Application;

return [

    'capacity' => [

        'consuming_statuses' => [Application::STATUS_ACCEPTED],

        'reject_submission_when_full' => true,

    ],

];
