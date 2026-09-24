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

        // Service-to-service credential (US-INT-01 §4). Never a learner
        // Sanctum token, never exposed to the frontend.
        'service_token' => env('DATA_SCIENCE_SERVICE_TOKEN'),

        'api_version' => env('DATA_SCIENCE_API_VERSION', 'v1'),

        // Versioned contract paths (US-INT-01 §26). These defaults were
        // verified against the deployed service's own OpenAPI document,
        // NOT against the SRS text: the live "SkillSpan Intelligence
        // Service" exposes unprefixed paths and has no /api/v1/intelligence/*
        // namespace at all.
        //
        // There is deliberately no `readiness_path`: the deployed service
        // has no standalone readiness endpoint. /api/v1/skill-gap returns
        // the readiness block (readiness_score, base_readiness_score,
        // critical_skill_cap_applied, met_skills, …) in the SAME response
        // as the per-skill gaps, so a second call would be a duplicate.
        'skill_gap_path' => env(
            'DATA_SCIENCE_SKILL_GAP_PATH',
            '/api/v1/skill-gap',
        ),

        // UNVERIFIED: the deployed service exposes no roadmap endpoint in
        // any form (prefixed or not), so this path cannot be confirmed
        // against a live contract yet. It is inert while
        // `roadmap_enabled` is false, and whoever enables roadmap
        // generation must confirm this path against the service first.
        'roadmap_path' => env(
            'DATA_SCIENCE_ROADMAP_PATH',
            '/api/v1/roadmap',
        ),

        // Skill Match v1 contract confirmed by Data Science — used by the
        // legacy ReadinessService (POST /api/v1/readiness/calculate).
        // Independent of the intelligence/* paths above.
        'skill_match_path' => env(
            'DATA_SCIENCE_SKILL_MATCH_PATH',
            '/api/v1/skill-match',
        ),

        // Compatibility fallback (US-INT-01 §11): used only when the
        // service response carries no algorithm_version metadata.
        'algorithm_version' => env('DATA_SCIENCE_ALGORITHM_VERSION', 'skill-gap-v1'),

        // Which intelligence endpoints are enabled. Roadmap generation is
        // gated because the FastAPI roadmap endpoint is still being
        // rolled out — disabled means 503, never a fabricated result.
        'roadmap_enabled' => (bool) env('DATA_SCIENCE_ROADMAP_ENABLED', false),

        'baseline' => [
            'path' => env('DATA_SCIENCE_BASELINE_PATH', 'api/v1/baseline'),
            'version' => env('DATA_SCIENCE_BASELINE_VERSION', 'v1.0'),
            'enabled' => (bool) env('DATA_SCIENCE_BASELINE_ENABLED', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | US-REC-01 — Intelligent Assistant (§12.5 governance gate)
    |--------------------------------------------------------------------------
    |
    | SRS v1.1 §12.5: "Sensitive data shall not be inserted into external AI
    | services without explicit technical and governance approval."
    |
    | This gate FAILS CLOSED. The assistant stays disabled unless it is both
    | explicitly enabled AND a reference to the recorded approval is present,
    | mirroring the approved pattern already used for collaborative signals
    | (REC-06 / BR-REC-07): off by default, and not enableable without
    | approval metadata.
    |
    | Laravel is the ONLY permitted caller of the assistant service. The
    | service requires a bearer token on every /chat and allows no browser
    | origins, so the frontend cannot reach it directly — which is what makes
    | the gate above meaningful rather than one door of two.
    |
    */
    'assistant' => [
        'enabled' => (bool) env('ASSISTANT_ENABLED', false),

        // Free-text reference to the recorded §12.5 approval (ticket id,
        // email thread, governance record). Must be non-empty before any
        // learner context may leave the platform.
        'approval_reference' => env('ASSISTANT_APPROVAL_REFERENCE'),

        // FastAPI chatbot service (POST /chat). Default port 8010 matches the
        // service's README; 8000 is frequently already taken locally.
        'url' => env('ASSISTANT_SERVICE_URL', 'http://127.0.0.1:8010'),
        'path' => env('ASSISTANT_SERVICE_PATH', '/chat'),

        // Service-to-service credential (US-INT-01 §4 pattern). Never a
        // learner Sanctum token, never exposed to the frontend. Must match
        // SERVICE_TOKEN in the assistant service's environment.
        'service_token' => env('ASSISTANT_SERVICE_TOKEN'),

        // The service fails over across three providers with a 30s cap each,
        // so its worst case is ~90s. A ceiling below that can cut off a
        // request the service would eventually have answered; lowering the
        // service's own per-provider timeout is the better fix.
        'timeout' => (int) env('ASSISTANT_SERVICE_TIMEOUT', 60),
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
