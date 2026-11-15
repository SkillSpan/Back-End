<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    'data_science' => [
        'url' => env('DATA_SCIENCE_SERVICE_URL', 'http://127.0.0.1:8001'),

        'timeout' => (int) env('DATA_SCIENCE_SERVICE_TIMEOUT', 60),

        'service_token' => env('DATA_SCIENCE_SERVICE_TOKEN'),

        'api_version' => env('DATA_SCIENCE_API_VERSION', 'v1'),

        'skill_gap_path' => env(
            'DATA_SCIENCE_SKILL_GAP_PATH',
            '/api/v1/skill-gap',
        ),

        'roadmap_path' => env(
            'DATA_SCIENCE_ROADMAP_PATH',
            '/api/v1/roadmap',
        ),

        'skill_match_path' => env(
            'DATA_SCIENCE_SKILL_MATCH_PATH',
            '/api/v1/skill-match',
        ),

        'project_matching_path' => env(
            'DATA_SCIENCE_PROJECT_MATCHING_PATH',
            '/api/v1/project-matching',
        ),

        'project_matching_enabled' => (bool) env('DATA_SCIENCE_PROJECT_MATCHING_ENABLED', false),

        'algorithm_version' => env('DATA_SCIENCE_ALGORITHM_VERSION', 'skill-gap-v1'),

        'roadmap_enabled' => (bool) env('DATA_SCIENCE_ROADMAP_ENABLED', false),

        'baseline' => [
            'path' => env('DATA_SCIENCE_BASELINE_PATH', '/api/v1/baseline'),
            'version' => env('DATA_SCIENCE_BASELINE_VERSION', 'v1.0'),
            'enabled' => (bool) env('DATA_SCIENCE_BASELINE_ENABLED', false),
        ],

        'baseline_assessment' => [
            'min_questions_per_skill' => (int) env('BASELINE_MIN_QUESTIONS_PER_SKILL', 1),
            'max_questions_per_skill' => (int) env('BASELINE_MAX_QUESTIONS_PER_SKILL', 3),
            'max_total_questions' => (int) env('BASELINE_MAX_TOTAL_QUESTIONS', 30),
            'deterministic_selection' => (bool) env('BASELINE_DETERMINISTIC_SELECTION', false),
        ],
    ],

    'assistant' => [
        'enabled' => (bool) env('ASSISTANT_ENABLED', false),

        'approval_reference' => env('ASSISTANT_APPROVAL_REFERENCE'),

        'url' => env('ASSISTANT_SERVICE_URL', 'http://127.0.0.1:8010'),
        'path' => env('ASSISTANT_SERVICE_PATH', '/chat'),

        'service_token' => env('ASSISTANT_SERVICE_TOKEN'),

        'timeout' => (int) env('ASSISTANT_SERVICE_TIMEOUT', 60),
    ],

    'support' => [
        'handoff_seconds' => (int) env('SUPPORT_HANDOFF_SECONDS', 5),

        'max_transcript_messages' => (int) env('SUPPORT_HANDOFF_MAX_TRANSCRIPT', 20),

        'notify_admins' => (bool) env('SUPPORT_NOTIFY_ADMINS', true),
    ],

    'profile' => [
        'avatar_size' => (int) env('PROFILE_AVATAR_SIZE', 256),

        'avatar_max_upload_kb' => (int) env('PROFILE_AVATAR_MAX_KB', 4096),

        'avatar_max_dimension' => (int) env('PROFILE_AVATAR_MAX_DIMENSION', 8000),

        'avatar_max_pixels' => (int) env('PROFILE_AVATAR_MAX_PIXELS', 25000000),
    ],

    'evidence' => [

        'source_weights' => [
            'self_assessment' => (float) env(
                'EVIDENCE_WEIGHT_SELF_ASSESSMENT',
                0.10
            ),

            'assessment_test' => (float) env(
                'EVIDENCE_WEIGHT_ASSESSMENT_TEST',
                0.30
            ),

            'project_performance' => (float) env(
                'EVIDENCE_WEIGHT_PROJECT_PERFORMANCE',
                0.35
            ),

            'expert_evaluation' => (float) env(
                'EVIDENCE_WEIGHT_EXPERT_EVALUATION',
                0.20
            ),

            'certificate' => (float) env(
                'EVIDENCE_WEIGHT_CERTIFICATE',
                0.05
            ),
        ],

        'pending_verified_factor' => (float) env(
            'EVIDENCE_PENDING_VERIFIED_FACTOR',
            0.50
        ),

        'recency' => [

            'six_months' => (float) env(
                'EVIDENCE_RECENCY_6_MONTHS',
                1.00
            ),

            'twelve_months' => (float) env(
                'EVIDENCE_RECENCY_12_MONTHS',
                0.90
            ),

            'twenty_four_months' => (float) env(
                'EVIDENCE_RECENCY_24_MONTHS',
                0.75
            ),

            'older' => (float) env(
                'EVIDENCE_RECENCY_OLDER',
                0.60
            ),
        ],
    ],

    'admin_setup' => [
        'secret' => env('ADMIN_SETUP_SECRET', ''),

        'enabled' => (bool) env('ADMIN_SETUP_ENABLED', true),
    ],

    'mentor_setup' => [
        'secret' => env('MENTOR_SETUP_SECRET', ''),

        'enabled' => (bool) env('MENTOR_SETUP_ENABLED', true),
    ],

    'internal' => [
        'baseline_items_secret' => env('INTERNAL_BASELINE_ITEMS_SECRET', ''),
    ],

];
