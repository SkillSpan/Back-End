<?php

return [
    // NOTE: this is the Laravel readiness formula/weights version — NOT the
    // FastAPI skill-gap algorithm version. It is stored on readiness_results
    // as `configuration_version`. The FastAPI algorithm version is stored
    // separately as `algorithm_version`, taken directly from the FastAPI
    // response at calculation time (see ReadinessService::calculate()).
    'configuration_version' => 'readiness-v1',

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
