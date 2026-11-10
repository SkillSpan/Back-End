<?php

namespace Tests\Unit;

use App\Support\SafeLog;
use Tests\TestCase;

class SafeLogTest extends TestCase
{
    public function test_it_keeps_the_documented_fastapi_detail_message(): void
    {
        $this->assertSame(
            'Baseline scoring failed: unknown assessment_version',
            SafeLog::upstreamBody('{"detail":"Baseline scoring failed: unknown assessment_version"}'),
        );
    }

    public function test_it_never_copies_an_undocumented_body(): void
    {
        $traceback = "Traceback (most recent call last):\n"
            ."  File \"main.py\", line 42, in score\n"
            ."    payload = {'email': 'LEAK-PROBE-VALUE-9f3a'}\n"
            ."KeyError: 'skills'";

        $summary = SafeLog::upstreamBody($traceback);

        $this->assertStringNotContainsString('LEAK-PROBE-VALUE-9f3a', $summary);
        $this->assertStringNotContainsString('Traceback', $summary);
        $this->assertSame('<'.strlen($traceback).'-byte upstream body omitted>', $summary);
    }

    public function test_it_does_not_copy_a_body_that_merely_contains_a_detail_looking_key(): void
    {
        $body = '{"detail":[{"loc":["body","email"],"input":"LEAK-PROBE-VALUE-9f3a"}]}';

        $summary = SafeLog::upstreamBody($body);

        $this->assertStringNotContainsString('LEAK-PROBE-VALUE-9f3a', $summary);
        $this->assertSame('<'.strlen($body).'-byte upstream body omitted>', $summary);
    }

    public function test_it_handles_an_empty_body(): void
    {
        $this->assertSame('<empty body>', SafeLog::upstreamBody(''));
        $this->assertSame('<empty body>', SafeLog::upstreamBody("  \n "));
    }

    public function test_it_bounds_a_pathologically_long_detail_message(): void
    {
        $detail = str_repeat('x', 5000);

        $this->assertSame(200, mb_strlen(SafeLog::upstreamBody('{"detail":"'.$detail.'"}')));
    }

    public function test_it_collapses_a_multiline_detail_to_one_line(): void
    {
        $this->assertSame(
            'line one line two',
            SafeLog::upstreamBody('{"detail":"line one\n   line two"}'),
        );
    }
}
