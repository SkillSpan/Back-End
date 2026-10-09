<?php

/*
|--------------------------------------------------------------------------
| Rate limits for the expensive endpoints
|--------------------------------------------------------------------------
|
| Each entry is "requests per minute, per authenticated user" and backs a
| named limiter registered in AppServiceProvider::configureRateLimiting().
|
| The scope is deliberately narrow: these are the routes that are expensive
| to serve — an external Data Science / assistant round trip, a decoded file
| upload, or a fan-out of notifications — not every route on the API. Cheap
| reads (reference data, the catalogue, notifications) are left alone.
|
| The numbers are an abuse ceiling, not a usage quota, so they are generous:
| a learner who asks a few questions, recalculates twice and uploads a couple
| of certificates never comes close, while a script looping the endpoint hits
| the wall on the first burst. Every value is env-tunable so a deployment can
| raise or lower it without a code change.
|
*/

return [
    // POST /api/v1/assistant/ask — an LLM round trip behind a 60 s timeout.
    'assistant_ask' => (int) env('RATE_LIMIT_ASSISTANT_ASK', 20),

    // POST /api/v1/readiness/calculate and POST /api/v1/skill-match — both are
    // a synchronous Data Science call.
    'readiness_calculate' => (int) env('RATE_LIMIT_READINESS_CALCULATE', 10),

    // POST /api/v1/intelligence/calculate — the heaviest call, since one
    // decision composes the skill gap and the roadmap.
    'intelligence_calculate' => (int) env('RATE_LIMIT_INTELLIGENCE_CALCULATE', 10),

    // POST /api/v1/projects/{project}/match — external matching call plus a
    // stored snapshot and a persisted recommendation.
    'project_matching' => (int) env('RATE_LIMIT_PROJECT_MATCHING', 10),

    // POST /api/v1/evidence — stores a file and triggers a skill
    // recalculation.
    'evidence_upload' => (int) env('RATE_LIMIT_EVIDENCE_UPLOAD', 20),

    // POST /api/v1/support/requests and its /messages sibling — each write
    // fans out notifications to the mentor and the admins. Higher than the
    // rest because a support thread is a back-and-forth conversation.
    'support_write' => (int) env('RATE_LIMIT_SUPPORT_WRITE', 30),
];
