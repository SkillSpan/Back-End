# SkillSpan Backend

Backend for **SkillSpan**, a skills-readiness platform. Learners
register, verify their email, build a student profile, evaluate their
skills, receive intelligence results for a target career role, and
discover and match with projects. Organizations register with a proof
document and gain access after admin approval.

------------------------------------------------------------------------

## Table of Contents

-   [Overview](#overview)
-   [Stack](#stack)
-   [Setup](#setup)
-   [Authentication](#authentication)
-   [Learner Profile](#learner-profile)
-   [Intelligence & Roadmap](#intelligence--roadmap)
-   [Dynamic Baseline Assessment](#dynamic-baseline-assessment)
-   [Readiness](#readiness)
-   [Project Discovery & Matching](#project-discovery--matching)
-   [Organizations & Admin](#organizations--admin)
-   [API Route Summary](#api-route-summary)
-   [Roadmap Data Model](#roadmap-data-model)
-   [Version Ownership](#version-ownership)
-   [Security & Ownership Principles](#security--ownership-principles)
-   [Testing](#testing)
-   [Integration Notes](#integration-notes)
-   [Admin Bootstrap](#admin-bootstrap)

------------------------------------------------------------------------

## Overview

SkillSpan uses Laravel as the main application backend and integrates
with a FastAPI intelligence service for data-science calculations.

The current backend covers:

-   Learner and organization authentication.
-   Email OTP verification and password recovery.
-   Learner profile management.
-   Skill evidence and skill evaluation data.
-   Intelligence calculation for target career roles.
-   Dynamic baseline assessment generation and submission.
-   Roadmap generation and persistence.
-   Roadmap versioning and lifecycle management.
-   Multiple skill prerequisites per roadmap action.
-   Next Best Action handling.
-   Project discovery, eligibility, matching, and recommendations.
-   Organization registration and administration.
-   Admin approval/rejection workflows.

Laravel remains responsible for application state, authorization,
validation, persistence, and lifecycle management. FastAPI provides
intelligence calculations and roadmap recommendations.

------------------------------------------------------------------------

# Stack

-   **Laravel 12** (PHP \^8.2)
-   **MySQL**
-   **Laravel Sanctum** token authentication
-   **FastAPI microservice integration** for intelligence calculations
-   **Gemini integration stub** for future AI features
-   **REST API** under `/api/v1`

------------------------------------------------------------------------

# Setup

``` bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

The local API is served at:

``` text
http://localhost:8000/api/v1
```

## Data Science Service Configuration

The backend uses environment variables for the FastAPI service:

``` env
DATA_SCIENCE_SERVICE_URL=http://127.0.0.1:8001
DATA_SCIENCE_SERVICE_TOKEN=
DATA_SCIENCE_SERVICE_TIMEOUT=60
DATA_SCIENCE_API_VERSION=v1

DATA_SCIENCE_SKILL_GAP_PATH=/api/v1/skill-gap
DATA_SCIENCE_ROADMAP_PATH=/api/v1/roadmap
DATA_SCIENCE_PROJECT_MATCHING_PATH=/api/v1/project-matching

DATA_SCIENCE_ROADMAP_ENABLED=false
DATA_SCIENCE_PROJECT_MATCHING_ENABLED=false
```

The roadmap flow is feature-gated. When roadmap generation is disabled,
Laravel does not fabricate a roadmap result.

------------------------------------------------------------------------

# Authentication

## Learner Authentication Flow

1.  `POST /api/v1/auth/register` --- register a learner and send email
    OTP.
2.  `POST /api/v1/auth/verify` --- verify the account using the OTP.
3.  `POST /api/v1/auth/login` --- receive a Sanctum Bearer token.
4.  Use the token through:

``` http
Authorization: Bearer <token>
```

Tokens are valid for 14 days.

## Organization Authentication

Organization registration requires a proof document and admin approval
before the organization can log in.

Relevant endpoints:

``` text
POST /api/v1/auth/register/organization
POST /api/v1/auth/login/organization
```

## Password Recovery

``` text
POST /api/v1/auth/forgot-password
POST /api/v1/auth/forgot-password/resend
POST /api/v1/auth/forgot-password/verify
POST /api/v1/auth/reset-password
```

## Google Login

``` text
POST /api/v1/auth/login/google
```

------------------------------------------------------------------------

# Learner Profile

Authenticated learners can manage their profile through:

``` text
POST /api/v1/profile
GET  /api/v1/profile
PUT  /api/v1/profile
```

The student profile includes, among other fields:

-   University information
-   Specialization
-   Academic level
-   Expected graduation
-   Bio
-   Career status
-   Interests
-   `availability`
-   `weekly_availability_hours`
-   Preferred work type
-   Primary career role
-   Consent information

## Availability

The backend keeps the existing textual:

``` text
availability
```

and also supports:

``` text
weekly_availability_hours
```

as a nullable numeric value.

Both fields are retained because they represent different information.

Example:

``` json
{
  "availability": "evenings",
  "weekly_availability_hours": 15
}
```

`weekly_availability_hours` is used as learner availability information
for intelligence and roadmap calculations.

------------------------------------------------------------------------

# Intelligence & Roadmap

## Intelligence Endpoints

``` text
POST /api/v1/intelligence/calculate
GET  /api/v1/intelligence/latest
```

The intelligence workflow coordinates:

1.  Decision snapshot creation.
2.  Skill-gap calculation through FastAPI.
3.  Readiness information.
4.  Roadmap generation when enabled.
5.  Response validation.
6.  Persistence of the validated result.

------------------------------------------------------------------------

## Intelligence Snapshot

The internal intelligence snapshot can contain:

### Learner

-   `student_profile_id`
-   `user_id`
-   `availability`
-   `weekly_availability_hours`

### Career Role

-   `career_role_id`
-   role title
-   role version

### Skills

-   `skill_id`
-   `skill_name`
-   `current_level`
-   `required_level`
-   `importance_weight`
-   `is_critical`
-   `confidence`
-   `evidence`
-   `prerequisite_skill_ids`

`confidence` follows the backend/FastAPI contract using a **0--100
scale**.

The internal snapshot is not exposed directly to the frontend.

------------------------------------------------------------------------

# Roadmap

Roadmap generation is integrated through the FastAPI intelligence
service.

The Roadmap endpoint is:

``` text
/api/v1/roadmap
```

Laravel sends a Roadmap-specific request rather than sending the
complete internal intelligence payload.

The request is prepared through:

``` text
IntelligenceClient::toRoadmapRequest()
```

This keeps internal fields separate from the public FastAPI Roadmap
contract.

For example, internal data such as:

``` text
skill_gap_result
```

is not sent to the Roadmap endpoint unless it is explicitly part of the
Roadmap contract.

Laravel still retains internal intelligence and skill-gap data for its
own workflow and persistence.

------------------------------------------------------------------------

## Roadmap Action Types

The current Roadmap contract supports only:

``` text
assessment
resource
practice
simulated_project
real_project
```

Unknown action types are treated as contract violations and are
rejected.

Laravel does not silently convert an unknown action type into another
type.

------------------------------------------------------------------------

## Roadmap Action Effort & Duration

The Roadmap contract distinguishes between **effort** and **calendar
duration**.

### `estimated_hours`

Represents the total effort required to complete the action.

Example:

``` json
{
  "estimated_hours": 12
}
```

### `estimated_duration_weeks`

Represents the expected calendar duration in weeks, taking the learner's
weekly availability into account.

Example:

``` json
{
  "estimated_hours": 12,
  "estimated_duration_weeks": 3
}
```

These fields have different meanings:

``` text
estimated_hours
    = total effort

estimated_duration_weeks
    = calendar duration
```

Laravel validates, persists, and exposes these values using the same
contract names.

`estimated_duration_hours` is not part of the current Roadmap v1
contract.

------------------------------------------------------------------------

## Roadmap Actions

A persisted Roadmap Action can contain:

``` text
id
phase
type
title
objective
description
target_skill_id
prerequisite_skill_id
prerequisite_skill_ids
priority_score
estimated_hours
estimated_duration_weeks
order_index
completion_criteria
explanation
status
```

------------------------------------------------------------------------

## Multiple Prerequisites

A Roadmap Action can depend on multiple skills.

Example:

``` json
{
  "prerequisite_skill_ids": [2, 5, 8]
}
```

The complete dependency graph is persisted through:

``` text
roadmap_action_prerequisites
```

The legacy single:

``` text
prerequisite_skill_id
```

is retained for backward compatibility where required.

The multiple-prerequisite relation is the source of truth for the new
Roadmap dependency graph.

Invalid prerequisite structures are rejected rather than silently
discarded.

------------------------------------------------------------------------

## Next Best Action

FastAPI can nominate:

``` text
next_best_action_id
```

Laravel validates that the referenced action belongs to the same Roadmap
and resolves it to the persisted Laravel `RoadmapAction`.

The frontend can receive:

``` text
roadmap.next_best_action_id
```

This allows the frontend to identify the recommended next action without
recalculating the Roadmap.

------------------------------------------------------------------------

# Roadmap Version Ownership

Roadmap versioning separates algorithm/configuration versions from
Laravel persistence versions.

## FastAPI owns

``` text
algorithm_version
configuration_version
```

Example:

``` text
algorithm_version = roadmap-v1
configuration_version = roadmap-config-v1
```

These values are returned by FastAPI and persisted on the Laravel
`roadmaps` record.

## Laravel owns

``` text
roadmap_version
status
```

Example:

``` text
First roadmap:
version = 1
status = active

Second roadmap:
old roadmap  -> superseded
new roadmap  -> active
version      -> 2
```

`roadmap_version` is therefore not taken from FastAPI.

------------------------------------------------------------------------

# Roadmap Persistence

Laravel persists:

-   Roadmap identity and role context.
-   Decision snapshot reference.
-   FastAPI `algorithm_version`.
-   FastAPI `configuration_version`.
-   Laravel `roadmap_version`.
-   Laravel roadmap lifecycle `status`.
-   Roadmap actions.
-   Multiple action prerequisites.
-   Next Best Action.
-   Estimated effort and duration.
-   Generated timestamp.
-   Explanations and action metadata.

Persistence occurs only after the FastAPI response passes the relevant
validation.

Malformed or inconsistent Roadmap responses must not create a partial
persisted Roadmap.

------------------------------------------------------------------------

# Dynamic Baseline Assessment

Baseline assessments are learner-specific and role-specific.

## Endpoints

``` text
POST  /api/v1/baseline-assessments
GET   /api/v1/baseline-assessments/{assessment}
PATCH /api/v1/baseline-assessments/{assessment}
POST  /api/v1/baseline-assessments/{assessment}/submit
```

Starting an assessment requires a target career role.

Example:

``` json
{
  "career_role_id": 1
}
```

## Internal Baseline Items

``` text
GET /api/v1/internal/baseline-items?version=v1.0
```

This endpoint is protected by the configured internal service secret and
is intended for service-to-service access.

## Baseline Configuration

``` env
BASELINE_MIN_QUESTIONS_PER_SKILL=1
BASELINE_MAX_QUESTIONS_PER_SKILL=3
BASELINE_MAX_TOTAL_QUESTIONS=30
BASELINE_DETERMINISTIC_SELECTION=false
```

The selection service enforces skill coverage and can report
insufficient question coverage rather than generating an incomplete
assessment.

------------------------------------------------------------------------

# Readiness

The legacy readiness endpoints remain Laravel API functionality:

``` text
POST /api/v1/readiness/calculate
GET  /api/v1/readiness/latest
```

Composite Readiness remains Laravel-owned.

Roadmap algorithm/configuration versions must not be confused with
Composite Readiness versions.

------------------------------------------------------------------------

# Project Discovery & Matching

All learner project endpoints require:

``` text
auth:sanctum
active account
learner role
```

## Discovery

``` text
GET /api/v1/projects
GET /api/v1/projects/{project}
```

`GET /api/v1/projects` supports:

``` text
search
type
domain
work_mode
difficulty
organization_id
skill_ids[]
minimum_level
```

Pagination:

``` text
page
per_page
```

`per_page` is limited to 50.

Projects are ordered by:

``` text
created_at DESC, id DESC
```

The `id` tie-breaker keeps pagination deterministic.

------------------------------------------------------------------------

## Project Matching

``` text
POST /api/v1/projects/{project}/match
```

Project matching is gated by:

``` env
DATA_SCIENCE_PROJECT_MATCHING_ENABLED=false
```

When disabled, Laravel returns the appropriate unavailable response
instead of fabricating a matching result.

The configured FastAPI path is:

``` text
/api/v1/project-matching
```

------------------------------------------------------------------------

## Stored Recommendations

``` text
GET /api/v1/recommendations
GET /api/v1/projects/{project}/recommendation
POST /api/v1/recommendations/{recommendation}/feedback
```

Recommendations can contain:

-   score
-   reasons
-   limiting factors
-   factors
-   weighted contributions
-   skill results
-   algorithm version
-   configuration version
-   project version

Read-only recommendation endpoints use persisted data and do not
recalculate the recommendation.

------------------------------------------------------------------------

## Discovery vs Eligibility

Project discovery and project eligibility are intentionally separate.

`GET /api/v1/projects` checks project availability and access but does
not apply all hard eligibility rules.

Hard eligibility is enforced during the matching flow.

The current comparable learner fields include:

-   `availability`
-   `preferred_work_type`

Project location and language are stored but are not used for the
corresponding eligibility comparison when no comparable student-profile
field exists.

------------------------------------------------------------------------

## Restricted Projects

Restricted projects are visible to learners with an active membership in
the owning organization.

Membership states such as `invited` or `removed` do not grant access.

------------------------------------------------------------------------

## Recommendation Access Redaction

Historical recommendation rows are not deleted when project access
changes.

If the learner can no longer access the project, result-derived fields
are redacted while the row remains identifiable.

The response keeps fields such as:

``` text
id
type
project_id
generated_at
access_revoked
```

while protected result fields can become `null`.

This prevents stale recommendation data from being exposed after access
changes.

------------------------------------------------------------------------

# API Route Summary

## Public

  ---------------------------------------------------------------------------------------
  Method                  Endpoint                                Description
  ----------------------- --------------------------------------- -----------------------
  POST                    `/api/v1/auth/register`                 Learner registration

  POST                    `/api/v1/auth/register/organization`    Organization
                                                                  registration

  POST                    `/api/v1/auth/verify`                   Email OTP verification

  POST                    `/api/v1/auth/resend-otp`               Resend verification OTP

  POST                    `/api/v1/auth/login`                    Learner login

  POST                    `/api/v1/auth/login/google`             Google ID-token login

  POST                    `/api/v1/auth/login/organization`       Organization login

  POST                    `/api/v1/auth/forgot-password`          Password-reset OTP

  POST                    `/api/v1/auth/forgot-password/resend`   Resend reset OTP

  POST                    `/api/v1/auth/forgot-password/verify`   Verify reset OTP

  POST                    `/api/v1/auth/reset-password`           Set new password

  GET                     `/up`                                   Health check
  ---------------------------------------------------------------------------------------

## Authenticated Learner

  -----------------------------------------------------------------------------------------------------
  Method                  Endpoint                                              Description
  ----------------------- ----------------------------------------------------- -----------------------
  POST                    `/api/v1/auth/logout`                                 Revoke current token

  POST                    `/api/v1/auth/logout-all`                             Revoke all tokens

  POST                    `/api/v1/profile`                                     Create learner profile

  GET                     `/api/v1/profile`                                     Get learner profile

  PUT                     `/api/v1/profile`                                     Update learner profile

  POST                    `/api/v1/readiness/calculate`                         Calculate legacy
                                                                                readiness

  GET                     `/api/v1/readiness/latest`                            Latest readiness

  POST                    `/api/v1/intelligence/calculate`                      Run intelligence
                                                                                workflow

  GET                     `/api/v1/intelligence/latest`                         Get latest intelligence
                                                                                result

  POST                    `/api/v1/baseline-assessments`                        Start baseline
                                                                                assessment

  GET                     `/api/v1/baseline-assessments/{assessment}`           Get assessment

  PATCH                   `/api/v1/baseline-assessments/{assessment}`           Update assessment

  POST                    `/api/v1/baseline-assessments/{assessment}/submit`    Submit assessment

  GET                     `/api/v1/projects`                                    Discover projects

  GET                     `/api/v1/projects/{project}`                          Project details

  POST                    `/api/v1/projects/{project}/match`                    Match learner with
                                                                                project

  GET                     `/api/v1/projects/{project}/recommendation`           Get stored
                                                                                recommendation

  GET                     `/api/v1/recommendations`                             List stored
                                                                                recommendations

  POST                    `/api/v1/recommendations/{recommendation}/feedback`   Submit recommendation
                                                                                feedback
  -----------------------------------------------------------------------------------------------------

------------------------------------------------------------------------

# Organizations & Admin

## Organization Profile

``` text
GET /api/v1/organization/profile
```

Only approved organization accounts can access protected organization
functionality.

## Admin Organization Management

Admin endpoints require `auth:sanctum` and the admin role.

``` text
GET  /api/v1/admin/organizations
GET  /api/v1/admin/organizations/{id}
GET  /api/v1/admin/organizations/{id}/proof-file
POST /api/v1/admin/organizations/{id}/approve
POST /api/v1/admin/organizations/{id}/reject
```

------------------------------------------------------------------------

# Roadmap Data Model

The current Roadmap model is centered around:

``` text
Roadmap
 ├── RoadmapAction
 │    ├── targetSkill
 │    └── prerequisites
 │          └── roadmap_action_prerequisites
 │                └── Skill
 │
 └── nextBestAction
```

A Roadmap also belongs to:

``` text
StudentProfile
CareerRole
DecisionSnapshot
```

This structure preserves:

-   Roadmap history
-   action dependencies
-   Next Best Action
-   algorithm/configuration metadata
-   Laravel lifecycle state

------------------------------------------------------------------------

# Security & Ownership Principles

-   Sanctum tokens are used for learner authentication.
-   FastAPI uses a service-to-service token rather than learner Sanctum
    tokens.
-   Service credentials must never be exposed to the frontend.
-   Laravel owns authorization and persistence.
-   FastAPI owns intelligence calculation outputs.
-   Composite Readiness remains Laravel-owned.
-   FastAPI Roadmap owns `algorithm_version` and
    `configuration_version`.
-   Laravel owns `roadmap_version` and `status`.
-   Invalid intelligence responses are rejected rather than silently
    corrected.
-   Unknown Roadmap action types are contract violations.
-   Invalid prerequisite structures are not silently discarded.
-   Internal Intelligence payload fields are not automatically forwarded
    to FastAPI Roadmap endpoints.

------------------------------------------------------------------------

# Testing

Run:

``` bash
php artisan test
```

Code style:

``` bash
vendor/bin/pint --test
```

Diff validation:

``` bash
git diff --check
```

Tests use an in-memory SQLite database where configured.

The intelligence tests mock the FastAPI service with `Http::fake` and
verify Laravel-side behavior such as:

-   payload construction
-   Roadmap request transformation
-   snapshot creation
-   response validation
-   Roadmap persistence
-   Roadmap versioning
-   multiple prerequisite persistence
-   Next Best Action handling
-   effort/duration contract
-   error handling

These mocked tests do **not** prove that the deployed Laravel and
FastAPI services can communicate successfully in production. A real E2E
integration test requires the deployed intelligence service and the
correct service token.

------------------------------------------------------------------------

# Integration Notes

The FastAPI integration is intentionally defensive.

Laravel validates:

-   request/decision identity
-   algorithm versions
-   configuration versions
-   skill IDs
-   skill-level consistency
-   skill-gap consistency
-   Roadmap action types
-   target skill references
-   prerequisite skill references
-   action IDs
-   Next Best Action references
-   Roadmap structure
-   `estimated_hours`
-   `estimated_duration_weeks`

## Roadmap Request Transformation

The internal intelligence payload is not sent directly to the Roadmap
endpoint.

The flow is:

``` text
Internal Intelligence Payload
            ↓
IntelligenceClient::toRoadmapRequest()
            ↓
Roadmap-specific request contract
            ↓
POST /api/v1/roadmap
            ↓
FastAPI Roadmap v1
```

This prevents internal-only fields such as `skill_gap_result` and
Laravel persistence metadata from leaking into the Roadmap request.

The Roadmap request transformation must remain deterministic and contain
only fields supported by the agreed FastAPI Roadmap contract.

A malformed or inconsistent FastAPI response should fail explicitly
instead of being converted into a fabricated result.

------------------------------------------------------------------------

# Admin Bootstrap

The endpoint:

``` text
POST /api/v1/setup/create-admin
```

creates an initial administrator account.

It is protected by:

``` env
ADMIN_SETUP_SECRET
```

The request must provide the matching secret and is rate-limited.

------------------------------------------------------------------------

## Development Checklist

Before committing backend changes:

``` bash
git status
git diff --check
php artisan test
vendor/bin/pint --test
```

Before pushing:

-   Verify the correct branch.
-   Review the complete diff.
-   Confirm no secrets or tokens are included.
-   Confirm migrations are safe.
-   Confirm Roadmap contract matches the FastAPI version being
    integrated.
-   Run the relevant E2E contract test when the FastAPI service is
    available.

### Roadmap Contract Summary

``` text
FastAPI → Laravel

algorithm_version
configuration_version
estimated_hours
estimated_duration_weeks
next_best_action_id
actions
prerequisite_skill_ids

Laravel-owned:

roadmap_version
status
```

------------------------------------------------------------------------

## Commit Message for this README update

``` text
docs: update roadmap and intelligence integration
```
