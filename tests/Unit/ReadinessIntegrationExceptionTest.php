<?php

namespace Tests\Unit;

use App\Exceptions\ReadinessIntegrationException;
use Tests\TestCase;

class ReadinessIntegrationExceptionTest extends TestCase
{
    public function test_integration_exception_can_be_constructed_without_readonly_property_collision(): void
    {
        $exception = new ReadinessIntegrationException(
            'The Data Science service is unavailable.',
            503,
            'DATA_SCIENCE_UNAVAILABLE',
            ['request_id' => 'test-request'],
        );

        $this->assertSame(503, $exception->status);
        $this->assertSame('DATA_SCIENCE_UNAVAILABLE', $exception->codeName);
        $this->assertSame(['request_id' => 'test-request'], $exception->details);
    }
}
