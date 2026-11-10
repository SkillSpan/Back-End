<?php

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

/**
 * Production configuration guards.
 *
 * These pin the invariants that make a deploy safe rather than the values a
 * local machine happens to use. Each one is a mistake that is invisible until
 * it is expensive:
 *
 *   - debug mode on in production leaks stack traces, the environment and the
 *     query log to whoever triggers an error;
 *   - a wildcard CORS origin next to `supports_credentials` is both unsafe and
 *     silently broken, because browsers reject `*` in that combination;
 *   - a committed `.env.example` with a real secret in it is a secret in the
 *     repository, forever.
 */
class ProductionConfigTest extends TestCase
{
    public function test_debug_is_forced_off_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true]);

        (new AppServiceProvider($this->app))->boot();

        // The exception handler reads this flag when it renders, so forcing it
        // here — before any handler runs — is what stops a debug page from ever
        // being produced, even though the environment still said otherwise.
        $this->assertFalse(config('app.debug'));
    }

    public function test_debug_is_left_alone_outside_production(): void
    {
        config(['app.debug' => true]);

        (new AppServiceProvider($this->app))->boot();

        // The guard is scoped to production: local development keeps its debug
        // flag, because useful error output is the whole point there.
        $this->assertTrue(config('app.debug'));
    }

    public function test_cors_never_pairs_credentials_with_a_wildcard_origin(): void
    {
        $this->assertTrue(config('cors.supports_credentials'));

        // Browsers reject `*` outright when credentials are allowed, so a
        // wildcard here would be an unsafe configuration AND a silent CORS
        // outage — the failure looks like "the frontend cannot log in".
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertSame([], config('cors.allowed_origins_patterns'));
    }

    /**
     * The deployed frontend must be allowed to call the API out of the box.
     *
     * Reported from the live server: only `http://localhost:5173` was accepted,
     * so the deployed site could not log in at all. `CORS_ALLOWED_ORIGINS` lives
     * in `.env`, `.env` is git-ignored, and `git pull` never refreshes it — so
     * the origin that must never break is guaranteed in code instead, where it
     * travels with the deploy.
     */
    public function test_the_deployed_frontend_origin_is_allowed_without_any_env_entry(): void
    {
        $origins = config('cors.allowed_origins');

        $this->assertContains('https://skillspan-iota.vercel.app', $origins);

        // Local development must keep working too — the env var is merged in,
        // not replaced by the production default.
        $this->assertContains('http://localhost:5173', $origins);
    }

    /**
     * And the browser-level proof: a preflight from that origin is answered
     * with the origin echoed back, which is what the browser actually checks.
     */
    public function test_a_preflight_from_the_deployed_frontend_is_allowed(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/talent/compare', [], [], [], [
            'HTTP_ORIGIN' => 'https://skillspan-iota.vercel.app',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $response->assertStatus(204);
        $this->assertSame(
            'https://skillspan-iota.vercel.app',
            $response->headers->get('Access-Control-Allow-Origin'),
        );
    }

    public function test_the_env_example_ships_no_secret_values(): void
    {
        $secretKeys = [
            'APP_KEY',
            'DB_PASSWORD',
            'MAIL_PASSWORD',
            'AWS_SECRET_ACCESS_KEY',
            'GEMINI_API_KEY',
            'GOOGLE_CLIENT_ID',
            'DATA_SCIENCE_SERVICE_TOKEN',
            'ASSISTANT_SERVICE_TOKEN',
            'ADMIN_SETUP_SECRET',
            'MENTOR_SETUP_SECRET',
            'INTERNAL_BASELINE_ITEMS_SECRET',
        ];

        foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');

            if (in_array(trim($key), $secretKeys, true)) {
                $this->assertSame(
                    '',
                    trim($value),
                    "{$key} must stay empty in the committed .env.example",
                );
            }
        }
    }

    public function test_the_env_example_documents_the_required_production_values(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        // The checklist is what stops the local template from being deployed
        // as-is: the two variables whose absence is invisible in a smoke test.
        $this->assertStringContainsString('APP_ENV=production', $example);
        $this->assertStringContainsString('APP_DEBUG=false', $example);
    }
}
