<?php

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Readiness\ReadinessService;
use App\Support\SafeLog;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * STEP 14 — logging must never become a copy of the data it is describing.
 *
 * The trap this file pins down: `Illuminate\Database\QueryException` builds its
 * message with `Str::replaceArray('?', $bindings, $sql)`, so the bound values
 * are substituted back into the SQL, and the PDO message in front of it echoes
 * the offending value as well. Logging `$e->getMessage()` on a database failure
 * therefore writes row data (emails, message bodies, hashes) into laravel.log.
 */
class SensitiveDataLoggingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'LEAK-PROBE-VALUE-9f3a';

    public function test_the_raw_query_exception_message_really_does_leak_bound_values(): void
    {
        // Evidence, not paranoia: this asserts the framework behaviour the
        // whole step exists to defend against. If Laravel ever stops
        // substituting bindings, this test fails loudly and the redaction can
        // be reconsidered instead of silently over-engineering.
        try {
            DB::table('a_table_that_does_not_exist')
                ->where('email', self::SECRET)
                ->get();

            $this->fail('Expected the missing-table query to raise a QueryException.');
        } catch (QueryException $e) {
            $this->assertStringContainsString(
                self::SECRET,
                $e->getMessage(),
                'QueryException no longer substitutes bound values — revisit SafeLog.',
            );
        }
    }

    public function test_safe_log_reason_drops_the_bound_values_but_keeps_the_sql_shape(): void
    {
        $reason = SafeLog::reason($this->leakyQueryException());

        $this->assertStringNotContainsString(self::SECRET, $reason);
        // The part an operator actually needs is still there.
        $this->assertStringContainsString('insert into support_messages', $reason);
        $this->assertStringContainsString('QueryException', $reason);
        $this->assertStringContainsString('mysql', $reason);
    }

    public function test_safe_log_reason_leaves_application_exceptions_untouched(): void
    {
        // Only driver-built messages are unsafe. Anything this application
        // throws carries no bound data and must keep its full message, or the
        // fix would blind every other failure.
        $this->assertSame(
            'Skill data is stale.',
            SafeLog::reason(new RuntimeException('Skill data is stale.')),
        );
    }

    public function test_a_database_failure_is_logged_without_the_bound_values(): void
    {
        $learner = $this->learner();

        $this->mock(ReadinessService::class, function ($mock): void {
            $mock->shouldReceive('calculate')->andThrow($this->leakyQueryException());
        });

        Sanctum::actingAs($learner);
        Log::spy();

        $this->postJson('/api/v1/readiness/calculate', [])
            ->assertStatus(500)
            ->assertJsonPath('code', 'READINESS_CALCULATION_FAILED');

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'Readiness calculation failed')
                && ! str_contains($context['failure_reason'] ?? '', self::SECRET)
                && str_contains($context['failure_reason'] ?? '', 'insert into support_messages'))
            ->once();
    }

    public function test_an_unhandled_database_failure_is_reported_without_the_bound_values(): void
    {
        Log::spy();

        // The second door: an exception nobody caught, handled by the default
        // reporter. The registered callback must redact it and suppress the
        // framework's own (leaky) report — hence `once()`.
        app(ExceptionHandler::class)->report($this->leakyQueryException());

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []): bool => $message === 'Unhandled database query failure.'
                && ! str_contains($context['sql'] ?? '', self::SECRET)
                && ($context['sql'] ?? null) === 'insert into support_messages (body) values (?)')
            ->once();
    }

    /**
     * A QueryException whose message embeds the bound value, exactly as the
     * framework builds it — plus a PDO message that echoes it a second time.
     */
    private function leakyQueryException(): QueryException
    {
        return new QueryException(
            'mysql',
            'insert into support_messages (body) values (?)',
            [self::SECRET],
            new PDOException(
                "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '".self::SECRET."' for key 'body_unique'"
            ),
        );
    }

    private function learner(): User
    {
        $role = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);

        $user = User::forceCreate([
            'name' => 'Test Learner',
            'email' => uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($role->id);

        StudentProfile::forceCreate(['user_id' => $user->id]);

        return $user;
    }
}
