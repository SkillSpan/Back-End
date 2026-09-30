# SkillSpan Backend

> **Developer Onboarding & Technical Reference**
>
> This document is the starting point for any developer joining the
> SkillSpan backend. It explains what the backend does, how the main
> components interact, how data moves through the system, where each
> responsibility lives, and how to work with the API and FastAPI
> intelligence service.

------------------------------------------------------------------------

## Table of Contents

1.  [What is SkillSpan?](#what-is-skillspan)
2.  [Architecture Overview](#architecture-overview)
3.  [Main User Journey](#main-user-journey)
4.  [Backend Responsibilities](#backend-responsibilities)
5.  [Tech Stack](#tech-stack)
6.  [Project Structure](#project-structure)
7.  [Getting Started](#getting-started)
8.  [Environment Configuration](#environment-configuration)
9.  [Authentication](#authentication)
10. [Learner Profile](#learner-profile)
11. [Assessment & Intelligence Flow](#assessment--intelligence-flow)
12. [FastAPI Integration](#fastapi-integration)
13. [Roadmap System](#roadmap-system)
14. [Project Discovery & Matching](#project-discovery--matching)
15. [Organizations & Admin](#organizations--admin)
16. [API Reference](#api-reference)
17. [Database & Core Relationships](#database--core-relationships)
18. [Version Ownership](#version-ownership)
19. [Security Principles](#security-principles)
20. [Testing](#testing)
21. [Development Workflow](#development-workflow)
22. [Integration / E2E Notes](#integration--e2e-notes)
23. [Admin Bootstrap](#admin-bootstrap)

------------------------------------------------------------------------

# What is SkillSpan?

**SkillSpan** is a skills-readiness platform that connects a learner's
profile, skills, assessments, career goals, practical opportunities, and
intelligence-driven recommendations.

The Laravel backend is the application's main system of record. It
handles authentication, authorization, profile data, database
persistence, business rules, validation, and API responses.

A FastAPI intelligence service is used for data-science calculations
such as skill-gap analysis, roadmap generation, and project matching.

Organizations can register, upload proof documents, and access
organization functionality after administrator approval.

------------------------------------------------------------------------

# Architecture Overview

At a high level:

``` text
                         ┌─────────────────────┐
                         │      Frontend       │
                         │  Web / API Client   │
                         └──────────┬──────────┘
                                    │
                                    │ HTTP / JSON
                                    ▼
                    ┌──────────────────────────────┐
                    │       Laravel Backend        │
                    │                              │
                    │ Auth / Profiles / API        │
                    │ Validation / Authorization   │
                    │ Business Rules / Persistence │
                    └──────────────┬───────────────┘
                                   │
                    ┌──────────────┴──────────────┐
                    │                             │
                    ▼                             ▼
             ┌──────────────┐              ┌──────────────┐
             │    MySQL     │              │   FastAPI    │
             │              │              │ Intelligence │
             │ Users        │              │              │
             │ Profiles     │              │ Skill Gap    │
             │ Skills       │              │ Roadmap      │
             │ Roadmaps     │              │ Matching     │
             │ Projects     │              │              │
             └──────────────┘              └──────────────┘
```

### The important rule

**Laravel owns application state. FastAPI owns intelligence
calculations.**

Laravel does not blindly expose FastAPI responses. It validates the
response, applies Laravel-owned rules, persists the accepted result, and
returns the application API response.

------------------------------------------------------------------------

# Main User Journey

The main learner journey can be understood as:

``` text
Register
   │
   ▼
Email Verification
   │
   ▼
Login
   │
   ▼
Complete Learner Profile
   │
   ▼
Baseline / Skill Evidence
   │
   ▼
Select Career Role
   │
   ▼
Intelligence Calculation
   │
   ├──────────────► Skill Gap
   │
   ├──────────────► Readiness
   │
   └──────────────► Roadmap
                         │
                         ▼
                  Persist Roadmap
                         │
                         ▼
                Next Best Action
                         │
                         ▼
                   Frontend
```

Project discovery is a parallel practical-opportunity flow:

``` text
Learner
   │
   ▼
Discover Projects
   │
   ▼
Check Access / Availability
   │
   ▼
Project Matching
   │
   ▼
FastAPI Intelligence
   │
   ▼
Persist Recommendation
   │
   ▼
Frontend
```

------------------------------------------------------------------------

# Backend Responsibilities

  -----------------------------------------------------------------------
  Area                    Laravel                 FastAPI
  ----------------------- ----------------------- -----------------------
  Authentication          ✅                      ---

  Authorization           ✅                      ---

  User/Profile            ✅                      ---
  persistence                                     

  Database state          ✅                      ---

  Decision snapshots      ✅                      ---

  Skill-gap calculation   Orchestrates            ✅

  Readiness calculation   Owns application flow   Calculation integration

  Roadmap calculation     Orchestrates            ✅

  Roadmap persistence     ✅                      ---

  Roadmap                 ✅                      ---
  lifecycle/status                                

  Roadmap persisted       ✅                      ---
  version                                         

  Roadmap algorithm       Stores FastAPI result   ✅
  version                                         

  Roadmap configuration   Stores FastAPI result   ✅
  version                                         

  Project discovery       ✅                      ---

  Project                 ✅                      ---
  access/eligibility                              
  rules                                           

  Project matching        Orchestrates            ✅
  calculation                                     

  Recommendation          ✅                      ---
  persistence                                     
  -----------------------------------------------------------------------

This separation is important when changing code. If a change affects
persisted application state, authorization, or lifecycle state, it
normally belongs in Laravel.

------------------------------------------------------------------------

# Tech Stack

-   **Laravel 12**
-   **PHP \^8.2**
-   **MySQL**
-   **Laravel Sanctum**
-   **FastAPI** intelligence microservice
-   **Gemini integration stub** for future AI functionality
-   **REST API** under `/api/v1`

------------------------------------------------------------------------

# Project Structure

The main areas to know when entering the codebase are:

``` text
app/
├── Http/
│   ├── Controllers/       # API request handling
│   ├── Requests/          # Input validation
│   └── Resources/         # API response formatting
│
├── Models/                # Eloquent database models
│
└── Services/
    └── Intelligence/
        ├── IntelligenceClient.php
        ├── IntelligencePayloadBuilder.php
        ├── IntelligencePersistenceService.php
        ├── IntelligenceResponseValidator.php
        └── DecisionSnapshotService.php

database/
├── migrations/            # Database schema
├── seeders/               # Initial/reference data
└── factories/             # Test data

routes/
└── api.php                # API routes

tests/
├── Feature/               # API/workflow tests
└── Unit/                  # Unit-level tests
```

### Where to start when changing Intelligence

A useful reading order is:

``` text
Controller
   ↓
Intelligence Service
   ↓
IntelligencePayloadBuilder
   ↓
IntelligenceClient
   ↓
FastAPI
   ↓
IntelligenceResponseValidator
   ↓
IntelligencePersistenceService
   ↓
Resource
```

------------------------------------------------------------------------

# Getting Started

## Requirements

Install:

-   PHP 8.2+
-   Composer
-   MySQL
-   Node.js only if frontend tooling is needed separately
-   Access to the FastAPI intelligence service for live integration
    testing

## Installation

``` bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Local API:

``` text
http://localhost:8000/api/v1
```

Health check:

``` text
GET /up
```

------------------------------------------------------------------------

# Environment Configuration

The FastAPI integration uses environment variables similar to:

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

Do not commit real service tokens or production credentials.

The Roadmap and project-matching flows are feature-gated.

------------------------------------------------------------------------

# Authentication

## Learner Flow

``` text
POST /api/v1/auth/register
        ↓
POST /api/v1/auth/verify
        ↓
POST /api/v1/auth/login
        ↓
Bearer token
```

The token is sent as:

``` http
Authorization: Bearer <token>
```

Token lifetime is 14 days.

## Organization Flow

Organizations register with a proof document and require administrator
approval before accessing protected organization functionality.

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

Authenticated learners can use:

``` text
POST /api/v1/profile
GET  /api/v1/profile
PUT  /api/v1/profile
```

Relevant intelligence-related profile fields include:

``` text
availability
weekly_availability_hours
preferred_work_type
primary career role
```

Example:

``` json
{
  "availability": "evenings",
  "weekly_availability_hours": 15
}
```

`availability` remains the existing textual availability field.

`weekly_availability_hours` is a nullable numeric value used as
quantitative learner availability information.

------------------------------------------------------------------------

# Assessment & Intelligence Flow

## Dynamic Baseline Assessment

The baseline assessment is role-specific.

``` text
POST /api/v1/baseline-assessments
GET /api/v1/baseline-assessments/{assessment}
PATCH /api/v1/baseline-assessments/{assessment}
POST /api/v1/baseline-assessments/{assessment}/submit
```

Starting an assessment requires:

``` json
{
  "career_role_id": 1
}
```

Internal service access:

``` text
GET /api/v1/internal/baseline-items?version=v1.0
```

## Intelligence Calculation

Main endpoints:

``` text
POST /api/v1/intelligence/calculate
GET  /api/v1/intelligence/latest
```

Conceptually:

``` text
Intelligence Calculate Request
          │
          ▼
Build Decision Snapshot
          │
          ▼
Validate Learner + Career Role + Skills
          │
          ▼
Call FastAPI Skill Gap
          │
          ▼
Validate Skill Gap Result
          │
          ▼
Generate Roadmap if enabled
          │
          ▼
Validate Roadmap
          │
          ▼
Persist Decision / Roadmap
          │
          ▼
Return Laravel API Response
```

The internal snapshot can contain:

### Learner

``` text
student_profile_id
user_id
availability
weekly_availability_hours
```

### Role

``` text
career_role_id
title
version
```

### Skills

``` text
skill_id
skill_name
current_level
required_level
importance_weight
is_critical
confidence
evidence
prerequisite_skill_ids
```

`confidence` uses the **0--100** scale.

------------------------------------------------------------------------

# FastAPI Integration

The FastAPI service is accessed through Laravel's intelligence client.

The important concept is that the internal Laravel Intelligence payload
and the external FastAPI Roadmap contract are not necessarily identical.

## Roadmap Request Transformation

The flow is:

``` text
Internal Intelligence Payload
            │
            ▼
IntelligenceClient::toRoadmapRequest()
            │
            ▼
Roadmap-specific request
            │
            ▼
POST /api/v1/roadmap
            │
            ▼
FastAPI Roadmap v1
```

`toRoadmapRequest()` prevents internal-only fields from being forwarded
blindly.

For example:

``` text
skill_gap_result
```

is internal intelligence data and is not automatically included in the
Roadmap request unless explicitly required by the agreed Roadmap
contract.

Laravel-owned persistence metadata such as:

``` text
roadmap_version
status
```

is also not treated as FastAPI-owned calculation output.

## Response Validation

Laravel validates FastAPI responses before persistence.

Validation covers the Roadmap contract, including:

-   identity/context
-   algorithm version
-   configuration version
-   action types
-   target skills
-   prerequisites
-   action IDs
-   Next Best Action
-   effort/duration fields
-   Roadmap structure

Invalid data must fail explicitly.

There should be no silent conversion of malformed data into a different
valid value.

------------------------------------------------------------------------

# Roadmap System

The Roadmap is the learner's generated sequence of actions for
progressing toward a target career role.

## Supported Action Types

Only these action types are currently supported:

``` text
assessment
resource
practice
simulated_project
real_project
```

An unknown action type is a contract violation.

------------------------------------------------------------------------

## Effort vs Calendar Duration

The Roadmap distinguishes between effort and calendar duration.

### `estimated_hours`

Total effort required to complete the action.

### `estimated_duration_weeks`

Expected calendar duration in weeks, taking the learner's weekly
availability into account.

Example:

``` json
{
  "estimated_hours": 12,
  "estimated_duration_weeks": 3
}
```

Meaning:

``` text
12 hours = total effort
3 weeks  = expected calendar duration
```

`estimated_duration_hours` is not part of the current Roadmap v1
contract.

------------------------------------------------------------------------

## Roadmap Action

A persisted action can contain:

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

An action can require multiple skills:

``` json
{
  "prerequisite_skill_ids": [2, 5, 8]
}
```

Laravel persists the complete dependency graph through:

``` text
roadmap_action_prerequisites
```

The legacy:

``` text
prerequisite_skill_id
```

is retained where backward compatibility requires it.

New multiple-prerequisite logic uses the relation containing all
prerequisite skills.

------------------------------------------------------------------------

## Next Best Action

FastAPI can return:

``` text
next_best_action_id
```

Laravel validates that the action belongs to the same generated Roadmap,
maps it to the persisted `RoadmapAction`, and exposes the persisted
reference to the frontend.

Conceptually:

``` text
FastAPI next_best_action_id
            ↓
Laravel validation
            ↓
Persisted RoadmapAction
            ↓
roadmap.next_best_action_id
```

------------------------------------------------------------------------

## Roadmap Lifecycle

Laravel owns the persisted Roadmap lifecycle.

``` text
FastAPI generates roadmap
          ↓
Laravel validates
          ↓
Create roadmap
          ↓
status = active
          ↓
New roadmap generated
          ↓
Previous roadmap = superseded
          ↓
New roadmap = active
```

Roadmap history is retained rather than overwritten.

------------------------------------------------------------------------

# Project Discovery & Matching

Learner project endpoints require an authenticated active learner
account.

## Discovery

``` text
GET /api/v1/projects
GET /api/v1/projects/{project}
```

Supported filters include:

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

Maximum `per_page` is 50.

Ordering:

``` text
created_at DESC, id DESC
```

The ID tie-breaker keeps pagination deterministic.

## Matching

``` text
POST /api/v1/projects/{project}/match
```

Matching uses the FastAPI intelligence service when enabled:

``` env
DATA_SCIENCE_PROJECT_MATCHING_ENABLED=false
```

## Stored Recommendations

``` text
GET /api/v1/recommendations
GET /api/v1/projects/{project}/recommendation
POST /api/v1/recommendations/{recommendation}/feedback
```

Recommendation data can include:

``` text
score
reasons
limiting_factors
factors
weighted_contributions
skill_results
algorithm_version
configuration_version
project_version
```

Read endpoints use persisted recommendation data rather than
recalculating it.

------------------------------------------------------------------------

## Discovery vs Eligibility

Project discovery and hard eligibility are separate concerns.

Discovery applies project availability and access rules.

Hard eligibility is enforced during matching.

Restricted projects are visible only to learners with an active
membership in the owning organization.

`invited` and `removed` memberships do not grant access.

------------------------------------------------------------------------

# Organizations & Admin

## Organization Profile

``` text
GET /api/v1/organization/profile
```

Only approved organizations can access protected organization
functionality.

## Admin

Admin endpoints require `auth:sanctum` and the admin role:

``` text
GET  /api/v1/admin/organizations
GET  /api/v1/admin/organizations/{id}
GET  /api/v1/admin/organizations/{id}/proof-file
POST /api/v1/admin/organizations/{id}/approve
POST /api/v1/admin/organizations/{id}/reject
```

------------------------------------------------------------------------

# API Reference

## Public

  ---------------------------------------------------------------------------------------
  Method                  Endpoint                                Purpose
  ----------------------- --------------------------------------- -----------------------
  POST                    `/api/v1/auth/register`                 Learner registration

  POST                    `/api/v1/auth/register/organization`    Organization
                                                                  registration

  POST                    `/api/v1/auth/verify`                   Email verification

  POST                    `/api/v1/auth/resend-otp`               Resend verification OTP

  POST                    `/api/v1/auth/login`                    Learner login

  POST                    `/api/v1/auth/login/google`             Google login

  POST                    `/api/v1/auth/login/organization`       Organization login

  POST                    `/api/v1/auth/forgot-password`          Start password recovery

  POST                    `/api/v1/auth/forgot-password/resend`   Resend recovery OTP

  POST                    `/api/v1/auth/forgot-password/verify`   Verify recovery OTP

  POST                    `/api/v1/auth/reset-password`           Reset password

  GET                     `/up`                                   Health check
  ---------------------------------------------------------------------------------------

## Authenticated Learner

  -----------------------------------------------------------------------------------------------------
  Method                  Endpoint                                              Purpose
  ----------------------- ----------------------------------------------------- -----------------------
  POST                    `/api/v1/auth/logout`                                 Logout current token

  POST                    `/api/v1/auth/logout-all`                             Revoke all tokens

  POST                    `/api/v1/profile`                                     Create profile

  GET                     `/api/v1/profile`                                     Get profile

  PUT                     `/api/v1/profile`                                     Update profile

  POST                    `/api/v1/readiness/calculate`                         Calculate readiness

  GET                     `/api/v1/readiness/latest`                            Get latest readiness

  POST                    `/api/v1/intelligence/calculate`                      Run intelligence
                                                                                workflow

  GET                     `/api/v1/intelligence/latest`                         Get latest intelligence
                                                                                result

  POST                    `/api/v1/baseline-assessments`                        Start assessment

  GET                     `/api/v1/baseline-assessments/{assessment}`           Get assessment

  PATCH                   `/api/v1/baseline-assessments/{assessment}`           Update assessment

  POST                    `/api/v1/baseline-assessments/{assessment}/submit`    Submit assessment

  GET                     `/api/v1/projects`                                    Discover projects

  GET                     `/api/v1/projects/{project}`                          Project details

  POST                    `/api/v1/projects/{project}/match`                    Match with project

  GET                     `/api/v1/projects/{project}/recommendation`           Get stored
                                                                                recommendation

  GET                     `/api/v1/recommendations`                             List recommendations

  POST                    `/api/v1/recommendations/{recommendation}/feedback`   Recommendation feedback
  -----------------------------------------------------------------------------------------------------

------------------------------------------------------------------------

# Database & Core Relationships

The core Intelligence/Roadmap relationships can be understood as:

``` text
User
 │
 └── StudentProfile
       │
       ├── Career Role
       │      │
       │      └── Career Role Skills
       │             │
       │             └── Skill Dependencies
       │
       └── DecisionSnapshot
              │
              └── Roadmap
                    │
                    ├── RoadmapAction
                    │      │
                    │      ├── Target Skill
                    │      │
                    │      └── Multiple Prerequisites
                    │             │
                    │             └── Skill
                    │
                    └── Next Best Action
```

Important Roadmap persistence tables/models include:

``` text
roadmaps
roadmap_actions
roadmap_action_prerequisites
```

The separate prerequisite relation prevents the dependency graph from
being reduced to a single prerequisite.

------------------------------------------------------------------------

# Version Ownership

There are two different kinds of versions.

## FastAPI-owned

``` text
algorithm_version
configuration_version
```

Example:

``` text
roadmap-v1
roadmap-config-v1
```

These describe the intelligence calculation used to produce the Roadmap.

## Laravel-owned

``` text
roadmap_version
status
```

Example:

``` text
Roadmap 1 → active

Roadmap 2 generated:
Roadmap 1 → superseded
Roadmap 2 → active
```

Do not use the FastAPI algorithm version as the persisted Roadmap
version.

------------------------------------------------------------------------

# Security Principles

-   Sanctum protects authenticated application endpoints.
-   FastAPI service authentication uses a service token.
-   Service credentials must never be exposed to the frontend.
-   Laravel owns authorization and application state.
-   FastAPI does not decide Laravel persistence lifecycle.
-   Roadmap `status` is Laravel-owned.
-   Roadmap `roadmap_version` is Laravel-owned.
-   FastAPI `algorithm_version` and `configuration_version` are stored
    as calculation metadata.
-   Invalid intelligence responses are rejected.
-   Unknown action types are rejected.
-   Invalid prerequisite structures are rejected.
-   Internal intelligence fields are not blindly forwarded to external
    service endpoints.
-   Production credentials must not be committed.

------------------------------------------------------------------------

# Testing

## Run the test suite

``` bash
php artisan test
```

## Code style

``` bash
vendor/bin/pint --test
```

## Diff validation

``` bash
git diff --check
```

Tests use an in-memory SQLite database where configured.

The intelligence tests can mock FastAPI using `Http::fake`.

These tests verify Laravel-side behavior such as:

-   payload construction
-   Roadmap request transformation
-   response validation
-   decision snapshots
-   persistence
-   Roadmap versioning
-   multiple prerequisites
-   Next Best Action
-   effort/duration fields
-   error handling

### Important

Mocked Laravel tests do **not** prove a live Laravel ↔ FastAPI
deployment works.

A real E2E test requires:

-   reachable FastAPI service
-   correct service URL
-   valid service token
-   matching request/response contract

------------------------------------------------------------------------

# Development Workflow

Before changing code:

``` bash
git status --short
git branch --show-current
git diff --stat
```

After changes:

``` bash
php artisan test
vendor/bin/pint --test
git diff --check
git status --short
```

Before committing:

1.  Review the full diff.
2.  Confirm no unrelated files changed.
3.  Confirm no secrets were added.
4.  Confirm migrations are safe.
5.  Confirm API contracts are intentional.
6.  Confirm FastAPI request/response changes match the agreed contract.

### Git safety

Do not use destructive commands to discard existing developer work:

``` text
git reset --hard
git clean
git restore .
git checkout -- .
```

Do not commit or push changes that have not been reviewed.

------------------------------------------------------------------------

# Integration / E2E Notes

The Laravel intelligence layer is designed as a defensive integration
boundary:

``` text
Frontend
   ↓
Laravel Controller
   ↓
Intelligence Service
   ↓
Payload Builder
   ↓
Intelligence Client
   ↓
toRoadmapRequest()
   ↓
FastAPI
   ↓
Response Validator
   ↓
Persistence Service
   ↓
Laravel Database
   ↓
API Resource
   ↓
Frontend
```

The most important integration rule is:

> **Build a dedicated request for every external contract. Do not send
> the complete internal application payload just because it is
> available.**

For Roadmap:

``` text
Internal payload
      ↓
toRoadmapRequest()
      ↓
Roadmap contract
      ↓
FastAPI /api/v1/roadmap
```

For the Roadmap response:

``` text
FastAPI result
      ↓
Validate
      ↓
Persist
      ↓
Expose through Laravel Resource
```

This keeps the FastAPI contract stable while allowing Laravel's internal
snapshot and persistence structures to evolve independently.

------------------------------------------------------------------------

# Admin Bootstrap

Initial admin creation:

``` text
POST /api/v1/setup/create-admin
```

The endpoint requires:

``` env
ADMIN_SETUP_SECRET
```

The request must provide the matching secret and is rate-limited.

------------------------------------------------------------------------

# Quick Reference for New Developers

If you are new to the backend, use this order:

### 1. Understand the API

Start with:

``` text
routes/api.php
```

### 2. Understand authentication

Read:

``` text
Auth Controllers
Auth Requests
Sanctum configuration
```

### 3. Understand learner data

Read:

``` text
StudentProfile
CareerRole
CareerRoleSkill
Skill
```

### 4. Understand intelligence

Read:

``` text
IntelligencePayloadBuilder
IntelligenceClient
IntelligenceResponseValidator
DecisionSnapshotService
IntelligencePersistenceService
```

### 5. Understand Roadmap persistence

Read:

``` text
Roadmap
RoadmapAction
roadmap_action_prerequisites
```

### 6. Understand API output

Read the relevant:

``` text
Http/Resources
```

### 7. Run tests

``` bash
php artisan test
```

------------------------------------------------------------------------

# Troubleshooting

## FastAPI calls fail

Check:

``` env
DATA_SCIENCE_SERVICE_URL
DATA_SCIENCE_SERVICE_TOKEN
DATA_SCIENCE_SERVICE_TIMEOUT
```

Then verify the corresponding path:

``` env
DATA_SCIENCE_SKILL_GAP_PATH
DATA_SCIENCE_ROADMAP_PATH
DATA_SCIENCE_PROJECT_MATCHING_PATH
```

## Roadmap is not generated

Check:

``` env
DATA_SCIENCE_ROADMAP_ENABLED
```

The Roadmap flow is intentionally feature-gated.

## Project matching is unavailable

Check:

``` env
DATA_SCIENCE_PROJECT_MATCHING_ENABLED
```

## Tests fail after a database change

Run:

``` bash
php artisan migrate:fresh --seed
php artisan test
```

Only use `migrate:fresh` in an appropriate local/test environment. Never
use destructive database commands against production data.

------------------------------------------------------------------------

# Admin Bootstrap

`POST /api/v1/setup/create-admin` creates an initial administrator
account.

It requires:

``` env
ADMIN_SETUP_SECRET
```

and is rate-limited.

------------------------------------------------------------------------

## Roadmap Contract Summary

### FastAPI → Laravel

``` text
algorithm_version
configuration_version
estimated_hours
estimated_duration_weeks
next_best_action_id
actions
prerequisite_skill_ids
```

### Laravel-owned

``` text
roadmap_version
status
```

### Action types

``` text
assessment
resource
practice
simulated_project
real_project
```

### Availability

``` text
availability
weekly_availability_hours
```

### Confidence

``` text
0–100
```

------------------------------------------------------------------------

## Documentation Commit

``` text
docs: improve developer onboarding documentation
```
