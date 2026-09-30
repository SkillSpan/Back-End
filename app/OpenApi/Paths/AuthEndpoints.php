<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI documentation for App\Http\Controllers\Api\AuthController.
 * Paths are relative to the `/api` server, so they carry the `/v1` prefix
 * exactly as registered in routes/api.php.
 */
class AuthEndpoints
{
    #[OA\Post(
        path: '/v1/auth/register',
        operationId: 'authRegister',
        tags: ['Authentication'],
        summary: 'Register an individual learner account',
        description: 'Creates an individual (learner) account, stores the terms/privacy acceptance '
            .'timestamps and sends an email verification code (OTP). The account stays inactive until '
            .'it is verified. Throttled to 10 requests per minute.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'password_confirmation', 'terms_accepted', 'privacy_accepted'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Sara Ahmad'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255, example: 'sara@example.com', description: 'Must be unique and not a disposable domain.'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, maxLength: 20, example: '+962790000000'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'StrongPass123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'StrongPass123'),
                    new OA\Property(property: 'terms_accepted', type: 'boolean', example: true),
                    new OA\Property(property: 'privacy_accepted', type: 'boolean', example: true),
                    new OA\Property(property: 'locale', type: 'string', nullable: true, enum: ['en', 'ar'], example: 'en'),
                    new OA\Property(property: 'career_status', type: 'string', nullable: true, example: 'student'),
                    new OA\Property(property: 'academic_status', type: 'string', nullable: true, enum: ['enrolled', 'graduated', 'on_leave', 'student', 'graduate', 'looking_for_job', 'employed']),
                    new OA\Property(property: 'user_type', type: 'string', nullable: true, enum: ['individual']),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Account created; verification email sent.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Your account has been created successfully. Please check your email to verify your account.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'user_id', type: 'integer', example: 42),
                                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'sara@example.com'),
                                new OA\Property(property: 'status', type: 'string', example: 'pending'),
                                new OA\Property(property: 'requires_verification', type: 'boolean', example: true),
                                new OA\Property(property: 'terms_accepted_at', type: 'string', format: 'date-time', nullable: true),
                                new OA\Property(property: 'privacy_accepted_at', type: 'string', format: 'date-time', nullable: true),
                                new OA\Property(property: 'resend_available_at', type: 'string', format: 'date-time'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Validation error (duplicate email, disposable email, weak password, …).', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many requests.'),
        ],
    )]
    public function register(): void {}

    #[OA\Post(
        path: '/v1/auth/register/organization',
        operationId: 'authRegisterOrganization',
        tags: ['Authentication'],
        summary: 'Register an organization account',
        description: 'Creates a company / university / training-partner account. A proof document '
            .'(`proof_file`) is mandatory and is reviewed by an administrator before the account is '
            .'activated. This flow deliberately does NOT use an email OTP. Throttled to 10 requests per minute.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['name', 'email', 'password', 'password_confirmation', 'terms_accepted', 'privacy_accepted', 'organization_name', 'organization_type', 'organization_contact_email', 'proof_file'],
                    properties: [
                        new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Acme Admin'),
                        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@acme.com'),
                        new OA\Property(property: 'phone', type: 'string', nullable: true),
                        new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8),
                        new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
                        new OA\Property(property: 'terms_accepted', type: 'boolean', example: true),
                        new OA\Property(property: 'privacy_accepted', type: 'boolean', example: true),
                        new OA\Property(property: 'locale', type: 'string', nullable: true, enum: ['en', 'ar']),
                        new OA\Property(property: 'organization_name', type: 'string', maxLength: 255, example: 'Acme Software'),
                        new OA\Property(property: 'organization_type', type: 'string', enum: ['company', 'university', 'training_partner'], example: 'company'),
                        new OA\Property(property: 'organization_contact_email', type: 'string', format: 'email', example: 'contact@acme.com'),
                        new OA\Property(property: 'organization_contact_phone', type: 'string', nullable: true),
                        new OA\Property(property: 'organization_website', type: 'string', nullable: true, format: 'uri'),
                        new OA\Property(property: 'organization_description', type: 'string', nullable: true, maxLength: 1000),
                        new OA\Property(property: 'organization_industry', type: 'string', nullable: true),
                        new OA\Property(property: 'organization_company_size', type: 'string', nullable: true),
                        new OA\Property(property: 'organization_country', type: 'string', nullable: true),
                        new OA\Property(property: 'organization_city', type: 'string', nullable: true),
                        new OA\Property(property: 'organization_address', type: 'string', nullable: true, maxLength: 500),
                        new OA\Property(property: 'organization_postal_code', type: 'string', nullable: true),
                        new OA\Property(property: 'proof_file', type: 'string', format: 'binary', description: 'Registration certificate. jpg/jpeg/png/pdf, max 5 MB.'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Organization account created; pending administrator review.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Your organization account has been created successfully. Your proof document will be reviewed by an administrator before your account is activated.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'user_id', type: 'integer'),
                                new OA\Property(property: 'email', type: 'string', format: 'email'),
                                new OA\Property(property: 'status', type: 'string', example: 'pending'),
                                new OA\Property(property: 'requires_verification', type: 'boolean', example: false),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Validation error.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function registerOrganization(): void {}

    #[OA\Post(
        path: '/v1/auth/verify',
        operationId: 'authVerify',
        tags: ['Authentication'],
        summary: 'Verify the email OTP and activate the account',
        description: 'Validates the 6-digit code sent by email. Unknown emails and wrong codes return the '
            .'same "invalid or expired" outcome so the endpoint cannot be used to enumerate accounts.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'otp'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'sara@example.com'),
                    new OA\Property(property: 'otp', type: 'string', minLength: 6, maxLength: 6, example: '123456'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Account activated (or email confirmed for an organization member).',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Your account has been activated successfully. You can now log in.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [new OA\Property(property: 'redirect_url', type: 'string', example: '/login')],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Invalid or expired code.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function verify(): void {}

    #[OA\Post(
        path: '/v1/auth/resend-otp',
        operationId: 'authResendOtp',
        tags: ['Authentication'],
        summary: 'Resend the email verification code',
        description: 'Always returns the same neutral response regardless of whether the email exists, to '
            .'avoid leaking registered addresses. Rate limited to one resend per interval.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['email'], properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'sara@example.com'),
            ]),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Neutral acknowledgement.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'If an account with that email exists, a new verification code has been sent.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [new OA\Property(property: 'resend_available_at', type: 'string', format: 'date-time')],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Validation error.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function resendOtp(): void {}

    #[OA\Post(
        path: '/v1/auth/login',
        operationId: 'authLogin',
        tags: ['Authentication'],
        summary: 'Log in (individual accounts)',
        description: 'Authenticates an account and issues a Sanctum bearer token. After 5 failed attempts '
            .'the caller is locked out for 5 minutes (HTTP 429 with Retry-After). Throttled to 20 requests per minute.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['email', 'password'], properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'sara@example.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'StrongPass123'),
            ]),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logged in.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Logged in successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                                new OA\Property(property: 'token', type: 'string', example: '1|abcdefghijklmnopqrstuvwxyz0123456789'),
                                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Invalid credentials or inactive account.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many login attempts.'),
        ],
    )]
    public function login(): void {}

    #[OA\Post(
        path: '/v1/auth/login/google',
        operationId: 'authLoginGoogle',
        tags: ['Authentication'],
        summary: 'Log in or register with Google',
        description: 'Verifies a Google ID token server-side, then logs in or creates an individual learner '
            .'account. Consent flags are only enforced on the account-creation path.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['credential'],
                properties: [
                    new OA\Property(property: 'credential', type: 'string', description: 'Google ID token returned by Google Identity Services.', example: 'eyJhbGciOiJSUzI1NiIs...'),
                    new OA\Property(property: 'terms_accepted', type: 'boolean', nullable: true, example: true),
                    new OA\Property(property: 'privacy_accepted', type: 'boolean', nullable: true, example: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logged in (and possibly created).',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Logged in successfully with Google.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                                new OA\Property(property: 'token', type: 'string'),
                                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                                new OA\Property(property: 'is_new_user', type: 'boolean'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Invalid Google credential.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function loginWithGoogle(): void {}

    #[OA\Post(
        path: '/v1/auth/login/organization',
        operationId: 'authLoginOrganization',
        tags: ['Authentication'],
        summary: 'Log in (organization administrator accounts)',
        description: 'Organization-scoped login. Rejects individual accounts, and blocks accounts whose '
            .'organization is still pending or rejected. Throttled to 20 requests per minute.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['email', 'password'], properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@acme.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'StrongPass123'),
            ]),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logged in.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Logged in successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                                new OA\Property(property: 'organizations', type: 'array', items: new OA\Items(ref: '#/components/schemas/Organization')),
                                new OA\Property(property: 'token', type: 'string'),
                                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Not an organization account, pending/rejected organization, or invalid credentials.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many login attempts.'),
        ],
    )]
    public function loginOrganization(): void {}

    #[OA\Post(
        path: '/v1/auth/forgot-password',
        operationId: 'authForgotPassword',
        tags: ['Authentication'],
        summary: 'Request a password reset code',
        description: 'Sends a password reset code when the account exists; the response is always the same '
            .'neutral shape. Rate limited per account.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['email'], properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'sara@example.com'),
            ]),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Neutral acknowledgement.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'If an account with that email exists, a password reset code has been sent.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [new OA\Property(property: 'resend_available_at', type: 'string', format: 'date-time')],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Validation error.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function forgotPassword(): void {}

    #[OA\Post(
        path: '/v1/auth/forgot-password/resend',
        operationId: 'authResendPasswordReset',
        tags: ['Authentication'],
        summary: 'Resend the password reset code',
        description: 'Re-sends the reset code while respecting the resend interval. Always neutral.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['email'], properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'sara@example.com'),
            ]),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Neutral acknowledgement.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'If an account with that email exists, a password reset code has been sent.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [new OA\Property(property: 'resend_available_at', type: 'string', format: 'date-time')],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Validation error.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function resendPasswordReset(): void {}

    #[OA\Post(
        path: '/v1/auth/forgot-password/verify',
        operationId: 'authVerifyPasswordReset',
        tags: ['Authentication'],
        summary: 'Verify the password reset code (without consuming it)',
        description: 'Checks that the reset code is valid so the frontend can move to the "set new password" '
            .'screen. The code is not consumed here; consumption happens in `/auth/reset-password`.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['email', 'otp'], properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'sara@example.com'),
                new OA\Property(property: 'otp', type: 'string', minLength: 6, maxLength: 6, example: '123456'),
            ]),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Code verified.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Code verified. You can now set a new password.'),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Invalid or expired code.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function verifyPasswordReset(): void {}

    #[OA\Post(
        path: '/v1/auth/reset-password',
        operationId: 'authResetPassword',
        tags: ['Authentication'],
        summary: 'Reset the password using the emailed code',
        description: 'Validates the reset code and updates the account password.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'otp', 'password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'sara@example.com'),
                    new OA\Property(property: 'otp', type: 'string', minLength: 6, maxLength: 6, example: '123456'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'NewStrongPass123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'NewStrongPass123'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Password reset.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Your password has been reset successfully. You can now log in.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [new OA\Property(property: 'redirect_url', type: 'string', example: '/login')],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Invalid or expired code.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function resetPassword(): void {}

    #[OA\Post(
        path: '/v1/auth/logout',
        operationId: 'authLogout',
        tags: ['Authentication'],
        summary: 'Log out of the current device',
        description: 'Revokes only the Sanctum token used for this request and marks the matching '
            .'auth session revoked. Requires authentication.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logged out.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Logged out successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [new OA\Property(property: 'redirect_url', type: 'string', example: '/')],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function logout(): void {}

    #[OA\Post(
        path: '/v1/auth/logout-all',
        operationId: 'authLogoutAll',
        tags: ['Authentication'],
        summary: 'Log out of all devices',
        description: 'Revokes every Sanctum token belonging to the user, including the one used for this '
            .'request, and records the revocation on every active auth session.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logged out everywhere.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'You have been logged out from all devices.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [new OA\Property(property: 'redirect_url', type: 'string', example: '/login')],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function logoutAll(): void {}
}
