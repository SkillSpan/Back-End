<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'SkillSpan API',
    description: 'SkillSpan Backend API Documentation'
)]
#[OA\Server(
    url: '/api',
    description: 'SkillSpan API Server'
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum'
)]
class OpenApiSpec {}
