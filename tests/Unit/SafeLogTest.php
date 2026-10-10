<?php

namespace Tests\Unit;

use App\Support\SafeLog;
use Tests\TestCase;

/**
 * STEP 18 — the upstream failure body must never be copied into our log.
 *
 * The body of a failed response from another service is untrusted text this
 * application does not control: on a 5xx it can be a framework debug page or a
 * traceback that echoes the request that was sent. For the intelligence calls
 * that request is the learner's own record, so quoting the body would make the
 * log a second copy of the data.
 */
class SafeLogTest extends TestCase
{
    public function test_it_keeps_the_documented_fastapi_detail_message(): void
    {
        // The one thing worth keeping: the service's own one-line explanation.
        $this->assertSame(
            'Baseline scoring failed: unknown assessment_version',
            SafeLog::upstreamBody('{"detail":"Baseline scoring failed: unknown assessment_version"}'),
        );
    }

    public function test_it_never_copies_an_undocumented_body(): void
    {
        // A traceback / debug page that echoes the request. The marker stands in
        // for the learner data a debug page would include.
        $traceback = "Traceback (most recent call last):\n"
            ."  File \"main.py\", line 42, in score\n"
            ."    payload = {'email': 'LEAK-PROBE-VALUE-9f3a'}\n"
            ."KeyError: 'skills'";

        $summary = SafeLog::upstreamBody($traceback);

        $this->assertStringNotContainsString('LEAK-PROBE-VALUE-9f3a', $summary);
        $this->assertStringNotContainsString('Traceback', $summary);
        // Still useful: the operator can see the service answered and how much.
        $this->assertSame('<'.strlen($traceback).'-byte upstream body omitted>', $summary);
    }

    public function test_it_does_not_copy_a_body_that_merely_contains_a_detail_looking_key(): void
    {
        // `detail` present but not a string (FastAPI's 422 shape is a list of
        // objects that DO carry the offending input) — must not be quoted.
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
        // `\n` inside a JSON string is the escape sequence (a raw newline would
        // make the body invalid JSON — see the traceback case above).
        $this->assertSame(
            'line one line two',
            SafeLog::upstreamBody('{"detail":"line one\n   line two"}'),
        );
    }
}
