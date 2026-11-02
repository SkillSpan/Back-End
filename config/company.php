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

];
