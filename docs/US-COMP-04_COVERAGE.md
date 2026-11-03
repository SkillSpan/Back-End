# US-COMP-04 — Organization Profile Management

Coverage against `sprint_4_company.pdf` §5 (pp. 11–16), task by task.
**State:** implemented, tested, deployed.
**Date:** 2026-11-03 · **Branch:** `feature/authentication` · **Live on alwaysdata:** `b32a13d`

Suite: **1793 tests / 11 828 assertions**, Pint clean. Company surface: 106 tests, of which 33 are
the profile story and 40 are the endpoint matrix.

Legend: ✅ done · ⚠️ partial (reason given) · ⬜ not backend

---

## 1. Backend — Laravel (the story's twelve bullets)

| # | Task | State | Where |
|---|---|---|---|
| 1 | Organization profile read endpoint | ✅ | `OrganizationProfileController@show` → `OrganizationProfileService::payload()` |
| 2 | Organization profile update endpoint | ✅ | `@update` → `::update()` — diffs, writes only what changed |
| 3 | Validate organization membership and authorization | ✅ | route gates + `ResolvesCompanyOrganization` (pivot `role_in_org=admin` **and** `status=active`) |
| 4 | Validate required fields and formats | ✅ | `UpdateOrganizationProfileRequest` + `guardRequiredContact()` |
| 5 | Document upload and replacement endpoint | ✅ | `@uploadDocument` → `::uploadDocument()`; a replacement is a new row |
| 6 | Validate document type and size | ✅ | extension **and** detected MIME, 5 MB, `DOCUMENT_TYPE_NOT_SUPPORTED` / `DOCUMENT_TOO_LARGE` |
| 7 | Apply malware screening **where available** | ✅ | `App\Services\Screening\DocumentScreeningService` — streams to clamd when `COMPANY_CLAMAV_SOCKET` is set, otherwise runs the signature pass (EICAR + script markers) |
| 8 | Store document references with access control | ✅ | `uploaded_files` morph to the organization; a foreign document is a **404** |
| 9 | Profile completeness from configured fields | ✅ | `config/company.php` → `profile.fields`; `::completeness()` |
| 10 | Re-verification policy for verification-relevant fields | ✅ | `flag` (default) / `pending` / `off`, `COMPANY_REVERIFICATION_MODE` |
| 11 | Audit records for profile and document changes | ✅ | `organization.profile.updated`, `organization.document.uploaded`, `company.profile.denied` |
| 12 | Clear validation and authorization errors | ✅ | every failure carries a `code` (see §5) |

**On bullet 7, precisely:** no antivirus is installed on the app servers, so the default is a
signature check — but it is no longer a decision baked into the code. Set
`COMPANY_CLAMAV_SOCKET=unix:///var/run/clamav/clamd.ctl` (or `tcp://127.0.0.1:3310`) and every
upload is streamed to clamd over INSTREAM before it is stored. **An unreachable engine falls back to
the signature check instead of refusing uploads** — a scanner being offline must not become an
outage.

**How the two gates divide the work** (measured over real HTTP, not assumed):

| Upload | fileinfo says | Caught by | Code |
|---|---|---|---|
| `<?php … ?>` renamed to `.pdf` | `text/x-php` | MIME gate | `DOCUMENT_TYPE_NOT_SUPPORTED` |
| PDF **polyglot** — starts `%PDF`, PHP payload after the header | `application/pdf` | content scan | `DOCUMENT_REJECTED_BY_SCAN` |

The second row is the reason the scan exists: it is the only payload the MIME gate cannot see.

## 2. Database (the story's seven items)

| # | Item | State | Column |
|---|---|---|---|
| 1 | Organization profile updated timestamp | ✅ | `organizations.profile_updated_at` |
| 2 | Updated by (user ID) | ✅ | `organizations.profile_updated_by` |
| 3 | Profile completeness value | ✅ | `organizations.profile_completeness` (tinyint) |
| 4 | Completeness configuration version | ✅ | `organizations.completeness_version` |
| 5 | Document version and replaced-document reference | ✅ | `uploaded_files.version`, `.replaces_id` (+ index) |
| 6 | Verification-relevant change flag | ✅ | `organizations.reverification_required_at` (+ index) — **non-null is the flag**, and it carries *when*, which a boolean cannot |
| 7 | Audit information | ✅ | `audit_events` (actor, action, entity, before, after, request_id, ip, user agent, occurred_at) |

Migrations: `2026_11_03_000001_add_profile_metadata_to_organizations`,
`2026_11_03_000002_add_document_versioning_to_uploaded_files`. All columns nullable, so every
existing organization stays valid.

## 3. API

| Method | Path | State |
|---|---|---|
| `GET` | `/api/v1/profile/me` | ✅ |
| `PATCH` | `/api/v1/profile/me` | ✅ |
| `POST` | `/api/v1/company/profile/documents` | ✅ |
| `GET` | `/api/v1/company/profile/documents/{document}` | ✅ (the URL the payload hands back) |
| `GET` | `/api/v1/organization/profile` | ✅ kept, same payload from the same service |

The story's "the API must" list: authentication ✅ · organization ownership ✅ · field and file
validation ✅ · **returns completeness and verification status** ✅ · clear error codes ✅.

## 4. Testing — the story's fifteen bullets

| # | Bullet | Test |
|---|---|---|
| 1 | View organization profile | `test_company_admin_can_view_the_profile` |
| 2 | Successful profile update | `test_profile_can_be_updated_and_the_change_is_persisted` |
| 3 | Missing required fields | `test_missing_required_field_is_rejected` |
| 4 | Invalid email, website, phone formats | `test_invalid_contact_details_are_rejected` |
| 5 | Preservation of entered values after errors | `test_profile_is_untouched_after_a_failed_validation` |
| 6 | Document upload | `test_document_can_be_uploaded` |
| 7 | Document replacement | `test_replacing_a_document_keeps_the_previous_version` |
| 8 | Unsupported file type | `test_unsupported_document_type_is_rejected` |
| 9 | Oversized file | `test_oversized_document_is_rejected` |
| 10 | Document access control | `test_a_document_of_another_organization_is_not_reachable` |
| 11 | Profile completeness calculation | `test_completeness_counts_the_configured_groups` |
| 12 | Re-verification policy handling | five tests — `flag`, non-relevant field, document, `pending`, `off` |
| 13 | Audit record creation | `test_profile_update_and_document_upload_are_audited` |
| 14 | Unauthorized access from another organization | `test_an_admin_only_ever_edits_the_organization_they_administrate` |
| 15 | Direct API access by a learner account | `test_a_learner_cannot_reach_the_company_profile` |

Extra coverage: the legacy path returns the same payload, an unchanged PATCH is a no-op, the contact
group cannot be emptied, read-only fields are refused, an unapproved organization is refused, a denied
request is audited, the EICAR signature still trips the scan, the scan can be switched off, and an
unreachable engine falls back.

## 5. Error codes

| Status | `code` |
|---|---|
| 401 | `UNAUTHENTICATED` |
| 403 | `COMPANY_ADMIN_ONLY` · `ORGANIZATION_PENDING` · `ORGANIZATION_NOT_APPROVED` · `NOT_ORGANIZATION_ADMIN` · `NO_ORGANIZATION` |
| 404 | `DOCUMENT_NOT_FOUND` · `DOCUMENT_UNAVAILABLE` |
| 422 | `PROFILE_VALIDATION_FAILED` (with `errors{}`) · `PROFILE_CONTACT_REQUIRED` · `FIELD_NOT_EDITABLE` · `DOCUMENT_TYPE_UNKNOWN` · `DOCUMENT_TYPE_NOT_SUPPORTED` · `DOCUMENT_TOO_LARGE` · `DOCUMENT_MISSING` · `DOCUMENT_REJECTED_BY_SCAN` |

## 6. Business rules

| Rule | State | Evidence |
|---|---|---|
| BR-01 authorized members only | ✅ | approved org + `role:company_admin` + admin pivot |
| BR-02 no fields owned by another role type | ✅ | `type` is not editable → `FIELD_NOT_EDITABLE` |
| BR-03 verification status read-only | ✅ | `guardReadOnly()`; only a *different* value is refused |
| BR-04 required fields server-side | ✅ | form request + `PROFILE_CONTACT_REQUIRED` |
| BR-05 email / website / phone formats | ✅ | custom website rule (dot + 2-letter TLD), phone regex |
| BR-06 type, size, screening, access control | ✅ | see §1 |
| BR-07 documents only for the organization | ✅ | ownership through the org's own `files()`; foreign id → 404 |
| BR-08 completeness + missing-field explanation | ✅ | `completeness.missing[]` with `level` |
| BR-09 re-verification policy | ✅ | `reverification` object + the three modes |
| BR-10 audit with actor, entity, before/after, timestamp | ✅ | `audit_events` |
| BR-11 representative account details not editable here | ✅ | `name`/`email`/`password` of the *user* are not in `EDITABLE` and never read from the request |

## 7. What is **not** backend (the rest of the story's Definition of Done)

UI/UX and Frontend task lists, "Frontend and Backend are integrated", Product Owner approval — other
teams. `docs/US-COMP-04_FRONTEND_API.pdf` is the contract handed over for the integration.

## 8. Deploy

Live on alwaysdata at `b32a13d`; the three migrations ran on the host (`DONE`), `optimize:clear`
cleared the routes cache, and `GET /api/v1/profile/me` answers **401** unauthenticated (404 before
the deploy). Render builds the same branch on push.
