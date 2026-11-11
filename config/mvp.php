<?php

/**
 * MVP catalogue policy.
 *
 * This file is the SINGLE source of truth for three things the MVP needs to
 * control without touching code:
 *
 *   1. which specializations are offered to learners (`specializations`),
 *   2. which career roles are AVAILABLE right now
 *      (`available_career_role_slugs`) — every other role is "Coming Soon",
 *   3. the display priority of career roles per specialization
 *      (`career_role_priority`).
 *
 * WHY A CONFIG FILE AND NOT DATABASE COLUMNS
 * ------------------------------------------
 * The priority is a *presentation* rule, not a fact about a role: the same
 * "Backend Developer" row is first for Information Systems and fifth for
 * Computer Science. Storing it as a column would duplicate the role row or
 * force an extra table just to hold ordering metadata, and it would make the
 * order impossible to review in a pull request. Keeping it here means the
 * whole MVP shape is diffable, testable and changeable in one place.
 *
 * ACTIVATION IS STILL DATABASE-DRIVEN
 * -----------------------------------
 * Nothing here filters queries by itself. `MvpCatalogSeeder` translates this
 * policy into `specializations.is_active` and `career_roles.is_active`, and
 * the API reads those columns. That keeps runtime behaviour explainable from
 * the database alone, and lets an administrator override a single row without
 * a deploy.
 *
 * IMPORTANT — every slug below is matched EXACTLY against the seeded
 * `career_roles.slug`. A slug that does not exist is ignored (it can never
 * invent a role), and the audit in `docs/MVP_CATALOG.md` lists the roles the
 * product brief asked for that the catalog does not contain yet.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Specializations offered in the MVP
    |--------------------------------------------------------------------------
    |
    | Matched by `specializations.name` (that column is unique and is the only
    | stable identifier the table has — there is no slug column). A row is
    | never renamed or deleted by the seeder: rows outside this list are simply
    | switched to is_active = false and stay in the table, so every historical
    | student profile that mentions them keeps resolving.
    |
    */
    'specializations' => [
        'Computer Science',
        'Information Technology',
        'Information Systems',
        'Software Engineering',
        'Data Science',
        'Artificial Intelligence',
        'Computer Engineering',
        'Networks & Cloud Computing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Specializations merged into one MVP entry
    |--------------------------------------------------------------------------
    |
    | The MVP lists a single "Networks & Cloud Computing" track. The catalog
    | predates that decision and splits the same ground across three rows
    | (Computer Networks, Cloud Computing, DevOps & Infrastructure). Rather than
    | rename or delete them — which would rewrite history for existing learners
    | — the seeder creates the merged row once and links it to the union of
    | their career roles. The three legacy rows are then deactivated, so a
    | learner sees exactly one entry while the old rows and their pivot links
    | survive untouched.
    |
    | Empty this array and the merged row simply gets its own priority roles.
    |
    */
    'merged_specializations' => [
        'Networks & Cloud Computing' => [
            'Computer Networks',
            'Cloud Computing',
            'DevOps & Infrastructure',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | The self-learning track
    |--------------------------------------------------------------------------
    |
    | Behaves like any other specialization for *availability* (every active
    | role is offered) but deliberately has no priority list, so the order stays
    | neutral. It is never deactivated.
    |
    */
    'free_track' => 'Self-Learning / Free Track',

    /*
    |--------------------------------------------------------------------------
    | Career role display priority, per specialization
    |--------------------------------------------------------------------------
    |
    | The specialization no longer RESTRICTS which roles a learner may pick —
    | every approved + active role is offered under every specialization. This
    | list only decides the ORDER: roles listed first appear first, the rest
    | follow in a stable neutral order.
    |
    | Rules the implementation relies on:
    |  - a role that is not in the list is still returned (never hidden);
    |  - a slug listed twice is de-duplicated by the resolver;
    |  - a slug that does not exist in `career_roles` is ignored;
    |  - the ordering is applied to the QUERY, before pagination, so pages stay
    |    stable and no role is skipped or repeated.
    |
    */
    'career_role_priority' => [

        'Computer Science' => [
            'frontend-developer',
            'backend-developer',
            'full-stack-developer',
            'software-engineer',
            'mobile-developer',
            // 'desktop-developer' — not in the catalog yet (see docs).
            'data-analyst',
            'data-scientist',
            'ai-engineer',
            'machine-learning-engineer',
        ],

        'Information Technology' => [
            'network-engineer',
            'cloud-engineer',
            'devops-engineer',
            'database-administrator',
            'backend-developer',
            'frontend-developer',
            'mobile-developer',
            'data-analyst',
        ],

        'Information Systems' => [
            'backend-developer',
            'frontend-developer',
            'full-stack-developer',
            // "Business Intelligence Developer" is seeded as `bi-developer`
            // (title "BI Developer"); it is the same role, not a new one.
            'bi-developer',
            'data-engineer',
            'database-developer',
            'software-engineer',
        ],

        'Software Engineering' => [
            'software-engineer',
            'backend-developer',
            'frontend-developer',
            'full-stack-developer',
            'mobile-developer',
            // 'desktop-developer' — not in the catalog yet (see docs).
            'devops-engineer',
            'database-administrator',
            'data-engineer',
        ],

        'Data Science' => [
            'data-analyst',
            'data-scientist',
            'data-engineer',
            'bi-developer',
            'machine-learning-engineer',
            'ai-engineer',
            'nlp-engineer',
            'computer-vision-engineer',
        ],

        'Artificial Intelligence' => [
            'ai-engineer',
            'machine-learning-engineer',
            'data-scientist',
            'nlp-engineer',
            'computer-vision-engineer',
            'data-engineer',
            'data-analyst',
            'backend-developer',
            // 'python-developer' — not in the catalog yet (see docs).
        ],

        'Computer Engineering' => [
            // 'computer-engineer' — not in the catalog yet (see docs).
            'network-engineer',
            'backend-developer',
            // 'python-developer' — not in the catalog yet (see docs).
            'ai-engineer',
        ],

        'Networks & Cloud Computing' => [
            'cloud-engineer',
            'devops-engineer',
            'network-engineer',
            // 'cloud-developer' — not in the catalog yet (see docs).
            // 'infrastructure-automation-engineer' — not in the catalog yet.
            'site-reliability-engineer',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Career roles available in the MVP
    |--------------------------------------------------------------------------
    |
    | EXACTLY these four roles are usable today. They are the only rows with
    | `is_active = true`, so they are the only ones a learner can pick and the
    | only ones that can start a baseline assessment.
    |
    | EVERY OTHER career role stays in the catalog and is returned by the
    | listing API marked as "Coming Soon" (`is_available = false`). Nothing is
    | deleted, hidden or detached: a role keeps its id, its required skills,
    | its questions and its pivot links — the flag only decides whether it can
    | be USED right now.
    |
    | Re-enabling a role later is one line here plus a re-run of
    | `MvpCatalogSeeder`, or a single activation from the admin panel.
    |
    | Slugs are matched exactly against `career_roles.slug`. A slug that does
    | not exist is ignored, so a typo can never invent a role — and a test
    | asserts that all four do exist.
    |
    */
    'available_career_role_slugs' => [
        'frontend-developer',
        'backend-developer',
        'full-stack-developer',
        'mobile-developer',
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles the brief asks for that the catalog does not contain
    |--------------------------------------------------------------------------
    |
    | Recorded here (and in docs/MVP_CATALOG.md) instead of being invented:
    | creating a role without required skills would produce a role that can
    | never start an assessment. They are future work, not MVP gaps.
    |
    */
    'missing_career_roles' => [
        'desktop-developer' => 'Desktop Developer',
        'python-developer' => 'Python Developer',
        'computer-engineer' => 'Computer Engineer',
        'cloud-developer' => 'Cloud Developer',
        'infrastructure-automation-engineer' => 'Infrastructure Automation Engineer',
        'api-developer' => 'API Developer',
    ],
];
