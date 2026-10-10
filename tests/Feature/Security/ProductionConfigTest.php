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
