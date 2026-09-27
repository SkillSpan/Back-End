<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Composite Readiness — versioning (ADR-001)
    |--------------------------------------------------------------------------
    |
    | Laravel is the sole owner and orchestrator of Composite Readiness.
    | FastAPI supplies ONE component (Skill Match) and never computes the
    | composite. Three orthogonal identifiers are recorded for every
    | composite result and must never be conflated:
    |
    |  - composite_algorithm_version — the STRUCTURE of the composite: which
    |    components exist, how they are aggregated, how unavailable
    |    components are excluded and their weights redistributed, the
    |    Critical Skill rule, and the Banding rule. Bumped ONLY when the
    |    structure changes (e.g. a fifth component is added, or the
    |    redistribution policy changes) — NOT when a weight, cap or band
    |    boundary is tuned.
    |
    |  - configuration_version — the NUMBERS: the active
    |    AlgorithmConfiguration row, recorded as `config-v{n}`. Bumped when
    |    any weight, the critical-skill cap or a band threshold is tuned.
    |
    |  - algorithm_version — FastAPI's Skill Match algorithm version, taken
    |    from the validated service response. It versions ONE component, not
    |    the composite.
    |
    | The former `configuration_version => 'readiness-v1'` key was a dead
    | alias for the composite structure (nothing read it) and was removed in
    | favour of `composite_algorithm_version` (ADR-001 §3.2).
    */
    'composite_algorithm_version' => 'composite-readiness-v1',

    // FastAPI's Skill Match component algorithm. Used ONLY as the
    // placeholder recorded on the PENDING decision snapshot before the
    // service call — the persisted algorithm_version always comes from the
    // validated response (ReadinessService::calculate()). The Composite is
    // built on the Skill Match contract, never on the older skill-gap one.
    'skill_match' => [
        'algorithm_version' => 'skill-match-v1',
    ],

    'weights' => [
        'skill_match' => 0.65,
        'practical_experience' => 0.20,
        'assessment_reliability' => 0.10,
        'profile_completeness' => 0.05,
    ],

    'critical_skill' => [
        'minimum_match' => 0.50,
        'cap' => 69.0,
    ],

    'bands' => [
        'foundation_needed' => ['min' => 0, 'max' => 39.99],
        'developing' => ['min' => 40, 'max' => 59.99],
        'moderate_readiness' => ['min' => 60, 'max' => 74.99],
        'near_ready' => ['min' => 75, 'max' => 89.99],
        'highly_ready' => ['min' => 90, 'max' => 100],
    ],

    'practical_experience' => [
        'full_credit_project_count' => 2,
        'algorithm_version' => 'practical-experience-v1',
        'config_version' => 'practical-experience-config-v1',
    ],

    'assessment_reliability' => [
        'algorithm_version' => 'assessment-reliability-v1',
        'config_version' => 'assessment-reliability-config-v1',
    ],

    'profile_completeness' => [
        'algorithm_version' => 'profile-completeness-v1',
        'config_version' => 'profile-completeness-config-v1',
        'required_fields' => [
            'university_name',
            'student_university_number',
            'specialization',
            'academic_level',
            'career_status',
            'interests',
            'availability',
        ],
    ],
];
