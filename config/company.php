<?php

return [

    'dashboard' => [
        'deadline_window_days' => (int) env('COMPANY_DEADLINE_WINDOW_DAYS', 7),

        'recent_projects_limit' => (int) env('COMPANY_RECENT_PROJECTS_LIMIT', 5),

        'funnel_project_limit' => (int) env('COMPANY_FUNNEL_PROJECT_LIMIT', 5),

        'task_project_limit' => (int) env('COMPANY_TASK_PROJECT_LIMIT', 5),

        'task_row_limit' => (int) env('COMPANY_TASK_ROW_LIMIT', 5),
    ],

    'notifications' => [
        'max_per_page' => (int) env('COMPANY_NOTIFICATIONS_MAX_PER_PAGE', 50),
        'default_per_page' => (int) env('COMPANY_NOTIFICATIONS_PER_PAGE', 20),
    ],

    'profile' => [
        'completeness_version' => env('COMPANY_PROFILE_COMPLETENESS_VERSION', 'v1'),

        'fields' => [
            ['key' => 'info', 'level' => 'required', 'mode' => 'all', 'columns' => ['name', 'type']],
            ['key' => 'contact', 'level' => 'required', 'mode' => 'any', 'columns' => ['contact_email', 'contact_phone']],
            ['key' => 'address', 'level' => 'required', 'mode' => 'all', 'columns' => ['country', 'city']],
            ['key' => 'size', 'level' => 'recommended', 'mode' => 'all', 'columns' => ['company_size']],
            ['key' => 'website', 'level' => 'recommended', 'mode' => 'all', 'columns' => ['website']],
            ['key' => 'description', 'level' => 'recommended', 'mode' => 'all', 'columns' => ['description']],
        ],

        'reverification' => [
            'mode' => env('COMPANY_REVERIFICATION_MODE', 'flag'),
            'fields' => ['name'],
            'documents' => ['business_registration'],
        ],

        'documents' => [
            'allowed' => [
                'business_registration' => ['stored_as' => 'certificate', 'required' => true],
                'additional' => ['stored_as' => 'other', 'required' => false],
            ],
            'max_kb' => (int) env('COMPANY_DOCUMENT_MAX_KB', 5120),
            'mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
            'mime_types' => ['application/pdf', 'image/jpeg', 'image/png'],
            'disk' => env('COMPANY_DOCUMENT_DISK', null),
            'clamav' => [
                'socket' => env('COMPANY_CLAMAV_SOCKET'),
                'timeout' => (int) env('COMPANY_CLAMAV_TIMEOUT', 10),
            ],
            'scan' => filter_var(env('COMPANY_DOCUMENT_SCAN', true), FILTER_VALIDATE_BOOLEAN),
        ],

        'logo' => [
            'max_kb' => (int) env('COMPANY_LOGO_MAX_KB', 2048),
            'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
            'mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
            'disk' => env('COMPANY_LOGO_DISK', 'public'),
        ],
    ],

];
