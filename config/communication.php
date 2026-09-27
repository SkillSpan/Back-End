<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Conversation & message retention
    |---------------------------------------------------------------------------
    | Conversations older than this many days become eligible for
    | automated archival. Messages past the archive grace period are
    | soft-deleted to satisfy privacy/retention requirements.
    */
    'retention_days' => (int) env('COMMUNICATION_RETENTION_DAYS', 365),

    /*
    |---------------------------------------------------------------------------
    | Message constraints
    |---------------------------------------------------------------------------
    */
    'message_max_length' => (int) env('COMMUNICATION_MESSAGE_MAX_LENGTH', 5000),

    /*
    |---------------------------------------------------------------------------
    | Pagination
    |---------------------------------------------------------------------------
    */
    'messages_per_page' => (int) env('COMMUNICATION_MESSAGES_PER_PAGE', 50),
    'conversations_per_page' => (int) env('COMMUNICATION_CONVERSATIONS_PER_PAGE', 20),
];
