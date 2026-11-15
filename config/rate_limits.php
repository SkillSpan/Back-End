<?php

return [
    'assistant_ask' => (int) env('RATE_LIMIT_ASSISTANT_ASK', 20),

    'readiness_calculate' => (int) env('RATE_LIMIT_READINESS_CALCULATE', 10),

    'intelligence_calculate' => (int) env('RATE_LIMIT_INTELLIGENCE_CALCULATE', 10),

    'project_matching' => (int) env('RATE_LIMIT_PROJECT_MATCHING', 10),

    'baseline_submit' => (int) env('RATE_LIMIT_BASELINE_SUBMIT', 10),

    'evidence_upload' => (int) env('RATE_LIMIT_EVIDENCE_UPLOAD', 20),

    'support_write' => (int) env('RATE_LIMIT_SUPPORT_WRITE', 30),
];
