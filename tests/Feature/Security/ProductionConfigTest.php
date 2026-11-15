<?php

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

class ProductionConfigTest extends TestCase
{
    public function test_debug_is_forced_off_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true]);

        (new AppServiceProvider($this->app))->boot();

        $this->assertFalse(config('app.debug'));
    }

    public function test_debug_is_left_alone_outside_production(): void
    {
        config(['app.debug' => true]);

        (new AppServiceProvider($this->app))->boot();

        $this->assertTrue(config('app.debug'));
    }

    public function test_cors_never_pairs_credentials_with_a_wildcard_origin(): void
    {
        $this->assertTrue(config('cors.supports_credentials'));

        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertSame([], config('cors.allowed_origins_patterns'));
    }

    public function test_the_deployed_frontend_origin_is_allowed_without_any_env_entry(): void
    {
        $origins = config('cors.allowed_origins');

        $this->assertContains('https://skillspan-iota.vercel.app', $origins);

        $this->assertContains('http://localhost:5173', $origins);
    }

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

        $this->assertStringContainsString('APP_ENV=production', $example);
        $this->assertStringContainsString('APP_DEBUG=false', $example);
    }
}
