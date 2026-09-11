<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

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
        'timeout' => (int) env('DATA_SCIENCE_SERVICE_TIMEOUT', 10),
        'algorithm_version' => env('DATA_SCIENCE_ALGORITHM_VERSION', 'skill-gap-v1'),
        'baseline' => [
            'path' => env('DATA_SCIENCE_BASELINE_PATH', 'api/v1/baseline'),
            'version' => env('DATA_SCIENCE_BASELINE_VERSION', 'v1.0'),
            'enabled' => (bool) env('DATA_SCIENCE_BASELINE_ENABLED', false),
        ],
    ],

    // SRS v1.1, Section 10.3 (Tables 48-50) — skill level & confidence
    // calculation from evidence. Configurable and versioned per the
    // "Configurability" algorithm design principle (Table 46).
    'evidence' => [

        /*
     * Skill-confidence-v1
     *
     * Approved by Data Science:
     *
     * self_assessment      = 0.10
     * assessment_test      = 0.30
     * project_performance  = 0.35
     * expert_evaluation    = 0.20
     * certificate          = 0.05
     *
     * Total = 1.00
     */
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

        /*
     * Pending verification factor.
     *
     * Approved by Data Science:
     * pending = 0.50
     */
        'pending_verified_factor' => (float) env(
            'EVIDENCE_PENDING_VERIFIED_FACTOR',
            0.50
        ),

        /*
     * Evidence recency.
     *
     * Version:
     * evidence-recency-v1
     *
     * Calendar-month thresholds:
     *
     * 0-6 months       = 1.00
     * >6-12 months     = 0.90
     * >12-24 months    = 0.75
     * >24 months       = 0.60
     */
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
<<<<<<< HEAD
        // verified_i for pending evidence in the confidence formula.
        // Agreed with Data Science as a configurable v1 default — see
        // SRS 10.3.2 (Confidence Score).
        'pending_verified_factor' => (float) env('EVIDENCE_PENDING_VERIFIED_FACTOR', 0.5),

        'recency' => [
            'six_months' => (float) env('EVIDENCE_RECENCY_6_MONTHS', 1.00),
            'twelve_months' => (float) env('EVIDENCE_RECENCY_12_MONTHS', 0.90),
            'twenty_four_months' => (float) env('EVIDENCE_RECENCY_24_MONTHS', 0.75),
            'older' => (float) env('EVIDENCE_RECENCY_OLDER', 0.60),
        ],
=======
>>>>>>> e148b3b61f01f9d8efbb1cbb708740d61057a1a6
    ],

    'admin_setup' => [
        'secret' => env('ADMIN_SETUP_SECRET', ''),
    ],

    // Shared secret for server-to-server calls from the Data Science
    // FastAPI service (GET /api/v1/internal/baseline-items). Not a user
    // token — Sanctum auth doesn't apply to this route.
    'internal' => [
        'baseline_items_secret' => env('INTERNAL_BASELINE_ITEMS_SECRET', ''),
    ],

];
