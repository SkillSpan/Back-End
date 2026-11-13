<?php

return [

    'milestones' => [
        'extension_cutoff_hours' => (int) env('SPRINT5_EXTENSION_CUTOFF_HOURS', 0),
        'max_milestones_per_project' => (int) env('SPRINT5_MAX_MILESTONES', 50),
        'reminder_window_days' => (int) env('SPRINT5_REMINDER_WINDOW_DAYS', 3),
    ],

    'submissions' => [
        'max_file_kb' => (int) env('SPRINT5_MAX_SUBMISSION_KB', 20480),
        'max_versions_per_target' => (int) env('SPRINT5_MAX_SUBMISSION_VERSIONS', 50),
        'allowed_extensions' => [
            'zip', 'rar', '7z', 'tar', 'gz', 'pdf', 'doc', 'docx', 'md', 'txt',
        ],
        'allowed_mime_types' => [
            'application/zip',
            'application/x-zip-compressed',
            'application/x-rar-compressed',
            'application/vnd.rar',
            'application/x-7z-compressed',
            'application/gzip',
            'application/x-tar',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
            'text/markdown',
        ],
        'allowed_link_hosts' => [],
    ],

    'evaluation' => [
        'dispute_period_days' => (int) env('SPRINT5_DISPUTE_PERIOD_DAYS', 14),
        'score_precision' => (int) env('SPRINT5_SCORE_PRECISION', 2),
        'moderation_mode' => env('SPRINT5_MODERATION_MODE', 'flagged_only'),
        'default_due_days' => (int) env('SPRINT5_EVALUATION_DUE_DAYS', 7),
    ],

    'record' => [
        'share_link_default_days' => (int) env('SPRINT5_SHARE_DEFAULT_DAYS', 30),
        'share_link_max_days' => (int) env('SPRINT5_SHARE_MAX_DAYS', 180),
        'default_section_visibility' => env('SPRINT5_RECORD_DEFAULT_VISIBILITY', 'private'),
    ],

    'rubrics' => [
        'weight_tolerance' => 0.001,
    ],

];
