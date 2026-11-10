<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_carry_the_security_headers(): void
    {
        $response = $this->getJson('/api/v1/profile');

        $response->assertStatus(401);

        $this->assertSecurityHeaders($response);
    }

    public function test_web_responses_carry_the_security_headers(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();

        $this->assertSecurityHeaders($response);
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->get('/admin/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/admin/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_the_headers_are_not_duplicated(): void
    {
        $response = $this->getJson('/api/v1/profile');

        $this->assertCount(1, $response->headers->all('x-content-type-options'));
        $this->assertCount(1, $response->headers->all('x-frame-options'));
        $this->assertCount(1, $response->headers->all('referrer-policy'));
    }

    private function assertSecurityHeaders(TestResponse $response): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
    }
}
