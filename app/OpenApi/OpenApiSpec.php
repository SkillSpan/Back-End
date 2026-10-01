<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Root OpenAPI document.
 *
 * Everything here is descriptive only — it documents the API that already
 * exists in routes/api.php. It does not change any behaviour.
 *
 * The API is versioned under the `/v1` prefix (see routes/api.php), and the
 * server below is `/api`, so an operation path of `/v1/auth/login` resolves
 * to `/api/v1/auth/login` — exactly the registered route.
 */
#[OA\Info(
    version: '1.0.0',
    title: 'SkillSpan API',
    description: 'SkillSpan Backend API — learner profiles, skills, evidence, readiness, '
        .'intelligence decisions, career roles, projects, mentoring and administration. '
        .'All routes below are the ones actually registered in routes/api.php.',
)]
#[OA\Server(
    url: '/api',
    description: 'SkillSpan API (Laravel) — same origin as this documentation',
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum',
    description: 'Laravel Sanctum personal access token. Send it as: `Authorization: Bearer <token>`. '
        .'Obtain a token from `POST /api/v1/auth/login`.',
)]
#[OA\Tag(name: 'Authentication', description: 'Registration, OTP verification, login, password reset and logout.')]
#[OA\Tag(name: 'Learner Profile', description: 'The authenticated learner\'s own student profile.')]
#[OA\Tag(name: 'Reference Data', description: 'Public lookup data for onboarding dropdowns (no authentication).')]
#[OA\Tag(name: 'Skills', description: 'Skill taxonomy and per-learner skill matrix.')]
#[OA\Tag(name: 'Evidence', description: 'Skill evidence submission and administrative review.')]
#[OA\Tag(name: 'Career Roles', description: 'Approved career roles and their required-skill decision snapshots.')]
#[OA\Tag(name: 'Readiness', description: 'Career readiness calculation and latest stored result.')]
#[OA\Tag(name: 'Intelligence', description: 'Combined decision (readiness + skill gaps + roadmap) calculation and retrieval.')]
#[OA\Tag(name: 'Baseline Assessment', description: 'Baseline skill assessment lifecycle.')]
#[OA\Tag(name: 'Assistant', description: 'Read-only intelligent assistant over the learner\'s own stored decisions.')]
#[OA\Tag(name: 'Projects', description: 'Project catalog plus the owner/administrator project lifecycle.')]
#[OA\Tag(name: 'Project Matching', description: 'Deterministic learner-to-project matching recommendation.')]
#[OA\Tag(name: 'Recommendations', description: 'Stored project matching recommendations for the learner.')]
#[OA\Tag(name: 'Applications', description: 'Student project applications and owner decisions.')]
#[OA\Tag(name: 'Admin', description: 'Platform-administrator surfaces: organization approval and project moderation.')]
#[OA\Tag(name: 'Organization', description: 'Self-service organization account endpoints.')]
#[OA\Tag(name: 'Mentor', description: 'Mentor student discovery and mentor-student connections.')]
#[OA\Tag(name: 'Conversations', description: 'Mentor-student conversations and messages, including chatbot messages.')]
#[OA\Tag(name: 'Notifications', description: 'In-app notification feed and delivery preferences.')]
#[OA\Tag(name: 'Setup', description: 'Bootstrap endpoints for creating the first admin/mentor accounts.')]
#[OA\Tag(name: 'Internal', description: 'Server-to-server endpoints called by the Data Science service.')]
class OpenApiSpec {}
