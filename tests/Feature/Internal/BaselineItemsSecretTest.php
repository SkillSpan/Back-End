<?php

namespace Tests\Feature\Internal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for the server-to-server baseline-items endpoint.
 *
 * The shared secret was compared with !==, which short-circuits on the
 * first differing byte and therefore leaks the length of the matching
 * prefix through response timing. The endpoint returns `correct_answer`
 * for every item, so it is worth a constant-time comparison — the same
 * hash_equals() pattern SetupController already used for
 * ADMIN_SETUP_SECRET.
 */
class BaselineItemsSecretTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-very-long-internal-secret-value';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.internal.baseline_items_secret' => self::SECRET]);
    }

    public function test_correct_secret_is_accepted(): void
    {
        $this->getJson('/api/v1/internal/baseline-items?version=v1.0', [
            'X-Internal-Secret' => self::SECRET,
        ])->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_missing_secret_is_rejected(): void
    {
        $this->getJson('/api/v1/internal/baseline-items?version=v1.0')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $this->getJson('/api/v1/internal/baseline-items?version=v1.0', [
            'X-Internal-Secret' => 'totally-wrong',
        ])->assertStatus(401);
    }

    /**
     * A value sharing a long prefix with the real secret must still be
     * rejected — this is the case a short-circuiting comparison is most
     * sensitive to.
     */
    public function test_near_miss_secret_is_rejected(): void
    {
        $nearMiss = substr(self::SECRET, 0, -1).'X';

        $this->getJson('/api/v1/internal/baseline-items?version=v1.0', [
            'X-Internal-Secret' => $nearMiss,
        ])->assertStatus(401);
    }

    public function test_prefix_only_secret_is_rejected(): void
    {
        $this->getJson('/api/v1/internal/baseline-items?version=v1.0', [
            'X-Internal-Secret' => substr(self::SECRET, 0, 8),
        ])->assertStatus(401);
    }

    /**
     * Fails closed: with no secret configured, nobody gets in.
     */
    public function test_endpoint_is_disabled_when_no_secret_is_configured(): void
    {
        config(['services.internal.baseline_items_secret' => '']);

        $this->getJson('/api/v1/internal/baseline-items?version=v1.0', [
            'X-Internal-Secret' => self::SECRET,
        ])->assertStatus(401);
    }
}
