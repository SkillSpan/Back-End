<?php

return [

    'retention_days' => (int) env('COMMUNICATION_RETENTION_DAYS', 365),

    'message_max_length' => (int) env('COMMUNICATION_MESSAGE_MAX_LENGTH', 5000),

    'messages_per_page' => (int) env('COMMUNICATION_MESSAGES_PER_PAGE', 50),
    'conversations_per_page' => (int) env('COMMUNICATION_CONVERSATIONS_PER_PAGE', 20),
];
