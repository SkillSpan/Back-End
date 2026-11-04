<?php

/*
|--------------------------------------------------------------------------
| Company workspace
|--------------------------------------------------------------------------
|
| Tunables for the company-facing dashboard (US-COMP-03). Every value is
| env-driven so a deployment can change a window or a page size without a
| code change — BR-11 asks for a *configurable* maximum page size, and the
| "deadline approaching" window is explicitly configurable in the story.
|
*/

return [

    'dashboard' => [
        // "Open projects whose application deadline is approaching, within a
        // configurable window" — Section 2, task type `deadline`.
        'deadline_window_days' => (int) env('COMPANY_DEADLINE_WINDOW_DAYS', 7),

        // "Most recently updated projects" — Section 3.
        'recent_projects_limit' => (int) env('COMPANY_RECENT_PROJECTS_LIMIT', 5),

        // How many projects the funnel breaks down (Section 4, `byProject`).
        'funnel_project_limit' => (int) env('COMPANY_FUNNEL_PROJECT_LIMIT', 5),

        // Cap on per-project rows inside a single task (Section 2). The task's
        // own `count` is always the full total; this only bounds the list.
        'task_project_limit' => (int) env('COMPANY_TASK_PROJECT_LIMIT', 5),

        // Cap on how many `revise` / `draft` task rows are emitted.
        'task_row_limit' => (int) env('COMPANY_TASK_ROW_LIMIT', 5),
    ],

    'notifications' => [
        // BR-11 — configurable maximum page size for the notification history.
        'max_per_page' => (int) env('COMPANY_NOTIFICATIONS_MAX_PER_PAGE', 50),
        'default_per_page' => (int) env('COMPANY_NOTIFICATIONS_PER_PAGE', 20),
    ],

    /*
    |----------------------------------------------------------------------
    | Organization profile (US-COMP-04)
    |----------------------------------------------------------------------
    |
    | BR-08 asks for completeness to be "calculated server-side from the
    | configured required and recommended fields", and AC-09 wants the
    | missing ones named. So the field list lives here, not in the service:
    | changing it is a configuration change, and `completeness_version` is
    | stored alongside every snapshot so an old percentage stays readable.
    |
    | `mode` is how a group counts as done: `all` = every column present,
    | `any` = at least one (a company with a phone but no e-mail is still
    | reachable).
    |
    */
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

        /*
        | BR-09 — re-verification policy for verification-relevant changes.
        |
        |   flag    (default) keep `verified`, set `reverification_required_at`
        |           and tell the caller. Nobody loses access to their own
        |           dashboard for renaming their company.
        |   pending move the organization back to `pending` — the strict
        |           reading, at the cost of locking the company out of the
        |           company workspace until an admin approves it again.
        |   off     no policy: the change is audited like any other.
        |
        | `type` is deliberately NOT in `fields`: BR-02 forbids a company user
        | from editing fields owned by another role type, and moving a company
        | to `university` / `training_partner` is exactly that. It is rejected
        | as read-only instead (see FIELD_NOT_EDITABLE).
        */
        'reverification' => [
            'mode' => env('COMPANY_REVERIFICATION_MODE', 'flag'),
            'fields' => ['name'],
            'documents' => ['business_registration'],
        ],

        /*
        | BR-06 / AC-07 — document rules.
        |
        | `allowed` maps the public type the client sends to the `type` enum
        | stored on `uploaded_files` (which predates this story and is shared
        | with learner CVs). `business_registration` reuses `certificate`,
        | the same slot the registration proof was stored in, so a replacement
        | supersedes the document uploaded at sign-up instead of creating a
        | second, parallel identity document.
        */
        'documents' => [
            'allowed' => [
                'business_registration' => ['stored_as' => 'certificate', 'required' => true],
                'additional' => ['stored_as' => 'other', 'required' => false],
            ],
            'max_kb' => (int) env('COMPANY_DOCUMENT_MAX_KB', 5120),
            'mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
            'mime_types' => ['application/pdf', 'image/jpeg', 'image/png'],
            'disk' => env('COMPANY_DOCUMENT_DISK', null),
            // BR-06 — "malware-screened where available". No engine is
            // installed on the app servers, so the default is the signature
            // check in App\Services\Screening\DocumentScreeningService. Point
            // `socket` at a clamd instance (unix:///var/run/clamav/clamd.ctl
            // or tcp://127.0.0.1:3310) and every upload is streamed to it
            // first. An unreachable socket falls back to the signature check
            // instead of refusing uploads.
            'clamav' => [
                'socket' => env('COMPANY_CLAMAV_SOCKET'),
                'timeout' => (int) env('COMPANY_CLAMAV_TIMEOUT', 10),
            ],
            // Set false to skip the content check entirely (tests, or a
            // deployment that screens elsewhere).
            'scan' => filter_var(env('COMPANY_DOCUMENT_SCAN', true), FILTER_VALIDATE_BOOLEAN),
        ],

        /*
        | The organization logo.
        |
        | A separate block from `documents` on purpose: a logo is not an
        | identity document, it is never reviewed, and it never triggers
        | re-verification. It does share the upload pipeline — same disk, same
        | screening — because it is still a file from an untrusted client.
        |
        | PNG/JPEG/WebP only. SVG is deliberately excluded: it is XML that can
        | carry script, and a logo is rendered on every page the company
        | appears on.
        */
        'logo' => [
            'max_kb' => (int) env('COMPANY_LOGO_MAX_KB', 2048),
            'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
            'mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
            // The PUBLIC disk, unlike documents. A logo is meant to be shown on
            // every page the company appears on, so it needs a URL a browser can
            // fetch with no token. The default `local` disk is private: its
            // /storage/{path} route demands a signature, so a logo stored there
            // would answer 404 to the browser even though the upload succeeded.
            // Requires `php artisan storage:link` on the host, once.
            'disk' => env('COMPANY_LOGO_DISK', 'public'),
        ],
    ],

];
