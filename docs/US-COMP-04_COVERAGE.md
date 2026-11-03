# US-COMP-04 — Organization Profile Management

**State:** implemented and tested. 30 new feature tests, full suite green at **1717 tests /
11 570 assertions**, Pint clean.
**Date:** 2026-11-03 · **Branch:** `feature/authentication` · **Commits:** `0051041`, `14240f8`

Legend: ✅ done · ⚠️ partial (reason given) · ⬜ not backend

---

## 1. Endpoints

| Method | Path | Guard | Controller |
|---|---|---|---|
| `GET` | `/api/v1/profile/me` | auth + active + approved org + `company_admin` | `OrganizationProfileController@show` |
| `PATCH` | `/api/v1/profile/me` | same | `@update` |
| `POST` | `/api/v1/company/profile/documents` | same | `@uploadDocument` |
| `GET` | `/api/v1/company/profile/documents/{document}` | same | `@downloadDocument` |
| `GET` | `/api/v1/organization/profile` | auth + approved org | `OrganizationController@profile` — **kept**, now returns the identical payload from the same service |

The organization is always resolved from the caller's **own admin membership**
(`organization_members.role_in_org = admin` **and** `status = active`) — never from a route
parameter, a query string or a body field. There is no id to tamper with, so "cross-organization
access" is not a check that can be forgotten; it is the only way the lookup works.

## 2. Backend tasks

| Task | State | Where |
|---|---|---|
| Profile read endpoint | ✅ | `OrganizationProfileService::payload()` |
| Profile update endpoint (partial) | ✅ | `::update()` — diffs, writes only what changed |
| Required fields enforced server-side | ✅ | `UpdateOrganizationProfileRequest` + `guardRequiredContact()` |
| E-mail / website / phone format | ✅ | custom website closure (needs a dot + 2-letter TLD), `email`, phone regex |
| Read-only fields refused, not ignored | ✅ | `guardReadOnly()` → `422 FIELD_NOT_EDITABLE` |
| Document upload | ✅ | `::uploadDocument()` |
| Document replacement / versioning | ✅ | new row + `replaces_id` + `version`; nothing is overwritten |
| Malware screening where available | ⚠️ | no AV engine on the host. `App\Services\Screening\DocumentScreeningService` runs an EICAR signature check + script-marker check and says so in its class doc. Swap its body for a real engine and the contract holds (`null` = clean) |
| Completeness, server-side, config-driven | ✅ | `config/company.php` → `profile.fields`; `::completeness()` |
| Missing fields named | ✅ | `completeness.missing[]` with `level` (`required` / `recommended`) |
| Re-verification policy | ✅ | `flag` (default) / `pending` / `off`, env `COMPANY_REVERIFICATION_MODE` |
| Audit every change | ✅ | `organization.profile.updated`, `organization.document.uploaded`, `company.profile.denied` |
| Access control (BR-07) | ✅ | ownership goes through the organization's own `files()`; a foreign document is a **404**, not a 403 |

## 3. Database

`2026_11_03_000001_add_profile_metadata_to_organizations.php` — all nullable, so every existing row
stays valid: `profile_updated_at`, `profile_updated_by`, `profile_completeness`,
`completeness_version`, `reverification_required_at` (+ index).

`2026_11_03_000002_add_document_versioning_to_uploaded_files.php` — `version` (default 1),
`replaces_id`, `original_name`, `screened_at` (+ index on `replaces_id`).

`completeness_version` exists because a stored `67 %` is unreadable once the field list changes:
the percentage alone does not say *67 % of what*.

## 4. Testing — the story's fifteen bullets

| # | Bullet | Test |
|---|---|---|
| 1 | view the profile | `test_company_admin_can_view_the_profile` |
| 2 | update the profile | `test_profile_can_be_updated_and_the_change_is_persisted` |
| 3 | missing required field | `test_missing_required_field_is_rejected` |
| 4 | invalid e-mail / website / phone | `test_invalid_contact_details_are_rejected` |
| 5 | nothing written when validation fails | `test_profile_is_untouched_after_a_failed_validation` |
| 6 | upload a document | `test_document_can_be_uploaded` |
| 7 | replace a document | `test_replacing_a_document_keeps_the_previous_version` |
| 8 | unsupported type | `test_unsupported_document_type_is_rejected` |
| 9 | oversized file | `test_oversized_document_is_rejected` |
| 10 | document access control | `test_a_document_of_another_organization_is_not_reachable` |
| 11 | completeness | `test_completeness_counts_the_configured_groups` |
| 12 | re-verification policy | five tests — `flag`, not-relevant field, document, `pending`, `off` |
| 13 | audit record | `test_profile_update_and_document_upload_are_audited` |
| 14 | cross-organization access | `test_an_admin_only_ever_edits_the_organization_they_administrate` |
| 15 | learner direct access | `test_a_learner_cannot_reach_the_company_profile` |

Four extra tests cover what a reader would otherwise have to assume: the legacy path returns the
same payload, an unchanged PATCH is a no-op, the contact group cannot be emptied, read-only fields
are refused, an unapproved organization is refused, a denied request is audited, a file whose
content contradicts its extension is rejected, and the antivirus signature still trips the scan.

`tests/Feature/Company/OrganizationProfileTest.php` — 30 tests, 184 assertions.

---

## 5. Answers to the frontend team's questions

**1. What values can `verification_status` take?**
`pending` | `verified` | `rejected`. There is **no** `approved` value — "approved" is `verified`.

**2. Is there a `reverification_pending` / `reverification_required` status?**
No. `verification_status` keeps its three values and a change that matters is reported **beside**
it:

```json
"reverification": {
  "required": true,
  "requested_at": "2026-11-02T15:04:00+00:00",
  "policy": "flag",
  "fields": ["name"],
  "message": "A field that identifies your organization changed, so its verification is being reviewed again."
}
```
`policy` is `flag` in production: the badge stays **Verified** and a "being reviewed again" note is
shown. Only `policy: "pending"` (not the default) would move the status back to `pending`.

**3. Shape of completeness?**
```json
"profile_completeness": 50,
"profile_fields": [{ "key": "info", "level": "required", "done": true }, ...],
"completeness": {
  "percent": 50,
  "version": "v1",
  "missing": [{ "field": "website", "level": "recommended" }]
}
```
`profile_completeness` / `profile_fields` are the flat keys the dashboard already reads — unchanged.
`completeness` is the richer object. `percent` is a whole number 0–100; `missing[].field` matches
`profile_fields[].key` and the existing `company.ws.field.*` i18n labels.

**4. Which fields are editable?**
`name`, `industry`, `company_size`, `website`, `description`, `country`, `city`, `address`,
`postal_code`, `contact_email`, `contact_phone`.

**5. Which are read-only?**
`type`, `verification_status`, `verified_at`. Echoing the current value back is fine (a form seeded
from the GET does that on every save); sending a *different* one is `422 FIELD_NOT_EDITABLE`.

**6. Website format?**
Send `acme.com` or `https://acme.com` — a scheme is added server-side, and the stored value always
has one. A bare host with no dot is rejected.

**7. Upload limits and types?**
5 MB (`COMPANY_DOCUMENT_MAX_KB`), `application/pdf`, `image/jpeg`, `image/png` — extension **and**
detected MIME must both match. Document types: `business_registration` (one slot, required) and
`additional`.

**8. What happens to a replaced document?**
The old row stays; the new one has `version: 2` and `replaces_id`. The response says which one it
replaced (`data.replaced`). A replacement goes back to `status: "pending"` — nothing about the
previous approval carries over.

**9. ETag / conditional requests?**
Not implemented, and not planned for this story. `updated_at` is in the payload if you want to skip
a re-render when it has not moved.

**Error shape** — every failure carries a machine code, so branch on `code` and not on the prose:

| Status | `code` |
|---|---|
| 401 | `UNAUTHENTICATED` |
| 403 | `COMPANY_ADMIN_ONLY` · `ORGANIZATION_PENDING` · `ORGANIZATION_NOT_APPROVED` · `NOT_ORGANIZATION_ADMIN` · `NO_ORGANIZATION` |
| 404 | `DOCUMENT_NOT_FOUND` · `DOCUMENT_UNAVAILABLE` |
| 422 | `PROFILE_VALIDATION_FAILED` (with `errors{}`) · `PROFILE_CONTACT_REQUIRED` · `FIELD_NOT_EDITABLE` · `DOCUMENT_TYPE_UNKNOWN` · `DOCUMENT_TYPE_NOT_SUPPORTED` · `DOCUMENT_TOO_LARGE` · `DOCUMENT_MISSING` · `DOCUMENT_REJECTED_BY_SCAN` |

---

## 6. What is **not** done

| Item | Why |
|---|---|
| Deployed | the two commits are local. Render builds the branch on push and runs `migrate --force`; alwaysdata needs `git pull && migrate --force && optimize:clear && config:cache` |
| Real antivirus | no engine on the host — see §2. The signature check is what "where available" allows here |
| Logo upload | `logo` is returned read-only; there is no upload endpoint for it in this story (the learner avatar endpoint is a different table). Say the word and it is one more action on the same controller |
| Document deletion | documents are versioned on purpose; nothing in the story asks for a delete, and deleting would break the audit trail |
| Frontend wiring | another team's scope — this document is the contract |
