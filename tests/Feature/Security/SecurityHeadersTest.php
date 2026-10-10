<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * STEP 15 — every response must carry the browser-facing security headers.
 *
 * Before this, the app sent none of them (the only one in the codebase was a
 * single nosniff on one avatar response), so an API response could be sniffed
 * and re-interpreted as HTML, and the admin panel could be framed.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_carry_the_security_headers(): void
    {
        // An unauthenticated call still runs the global middleware stack, so
        // even the 401 the framework produces must carry the headers.
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
        // Plain HTTP: HSTS would be both meaningless and wrong to advertise.
        $this->get('/admin/login')->assertHeaderMissing('Strict-Transport-Security');

        // TLS-terminated (as behind the proxy in production).
        $this->get('https://localhost/admin/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_the_headers_are_not_duplicated(): void
    {
        $response = $this->getJson('/api/v1/profile');

        // A controller that also sets a header must not end up with two values
        // for it — duplicated security headers are a classic misconfiguration
        // and some browsers then ignore the pair.
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
