# Company API — test matrix

Every company-facing endpoint, what guards it, and where its test lives.
**1793 tests / 11 828 assertions** green, Pint clean. Date 2026-11-03.
Company surface alone: **107 tests** in `tests/Feature/Company` (33 of them the profile story).

`tests/Feature/Company/CompanyApiTest.php` is the **matrix**: it checks that every route below is
registered with the method and path the clients use, then runs the same three guard checks against
each one (no token → 401, learner → 403, pending organization → 403) and one success call per
endpoint. A route dropped in a merge fails there, by name, instead of 404-ing in production.

The **deep** column is where the actual rules live — those files already existed and are not
duplicated by the matrix.

| # | Endpoint | Guard | Matrix test | Deep test |
|---|---|---|---|---|
| 1 | `POST /auth/register/organization` | public, `throttle:10,1` | `test_an_organization_can_register` | `Auth/RegistrationTest` |
| 2 | `POST /auth/login/organization` | public, `throttle:20,1` | `test_an_organization_can_log_in_and_lands_on_its_dashboard` | `Auth/OrganizationApprovalTest` |
| 3 | `GET /organization/profile` | auth + active + approved org | `test_the_organization_profile_endpoint_answers` | `Auth/OrganizationProfileTest`, `Auth/OrganizationMembershipScopeTest` |
| 4 | `GET /profile/me` | + `role:company_admin` | `test_the_profile_me_endpoint_answers` | `Company/OrganizationProfileTest` |
| 5 | `PATCH /profile/me` | + `role:company_admin` | `test_the_profile_me_endpoint_accepts_an_update` | `Company/OrganizationProfileTest` (17 tests) |
| 6 | `GET /company/dashboard` | + `role:company_admin` | `test_the_dashboard_endpoint_answers` | `Company/CompanyDashboardTest` |
| 7 | `POST /company/profile/documents` | + `role:company_admin` | `test_the_document_upload_endpoint_answers` | `Company/OrganizationProfileTest` (documents) |
| 8 | `GET /company/profile/documents/{id}` | + `role:company_admin` | `test_the_document_download_endpoint_answers` | `Company/OrganizationProfileTest` (access control) |
| 9 | `GET /talent` | + `role:company_admin` | `test_the_talent_list_endpoint_answers` | `Talent/TalentDiscoveryTest` |
| 10 | `GET /talent/filters` | + `role:company_admin` | `test_the_talent_filters_endpoint_answers` | `Talent/TalentDiscoveryTest` |
| 11 | `GET /talent/{student}` | + `role:company_admin` | `test_the_talent_detail_endpoint_answers` | `Talent/TalentDiscoveryTest` (404 ≠ 403) |

## The guard codes, per endpoint

| Code | When | Endpoints |
|---|---|---|
| `UNAUTHENTICATED` | no / expired token | 3–11 |
| `COMPANY_ADMIN_ONLY` | a learner token on a company-only route | 4–11 |
| `NO_ORGANIZATION` | authenticated, but administers no organization | 3 |
| `NOT_ORGANIZATION_ADMIN` | belongs to an organization, but is not its admin | 3, 4–11 (inside the controller) |
| `ORGANIZATION_PENDING` / `ORGANIZATION_NOT_APPROVED` | the organization is still under review, or was refused | 3–11 |

**Why #3 behaves differently:** `/organization/profile` predates the company story and is the
generic self-service organization endpoint (university and training-partner desks use it too), so it
gates on **membership**, not on `role:company_admin`. A learner therefore gets `NO_ORGANIZATION`
rather than `COMPANY_ADMIN_ONLY` — pinned by
`test_the_generic_organization_profile_refuses_a_learner`.

## Not in this matrix, on purpose

| Surface | Why | Where |
|---|---|---|
| `POST/PATCH /projects`, `/projects/{id}/submit|open|approve|…` | shared project lifecycle: authorization is in `ProjectLifecycleService` (owner **or** admin), not a company route group | `tests/Feature/Projects` |
| `/admin/organizations/*` | platform-administrator review workflow, not a company endpoint | `tests/Feature/Admin/AdminOrganizationTest` |
| `/notifications/*` | user-scoped, not company-scoped (the dashboard links into them) | `tests/Feature/Company/CompanyNotificationApiTest` |
