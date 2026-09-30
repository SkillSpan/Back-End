# Organization Registration — Frontend Field-Name Audit

**Repo audited:** `github.com/SkillSpan/Front-end` (public), branch `main`, commit `42319f7` (Sep 24, 2026)
**Backend contract:** `app/Http/Requests/Auth/RegisterOrganizationRequest.php`
**Method:** read the live `main` sources, then POST the exact same multipart body to a live backend.

---

## TL;DR

The main-branch frontend **can register successfully** — but **6 fields are silently dropped**.
They are sent under names the backend does not validate, so validation passes with them as `null`
and nothing is ever written to the database.

The most user-visible symptom is exactly the reported one:

> the admin review panel shows **"No description was provided for this organization."**
> even though the user typed a description in the form.

`description` is not the only casualty — `industry` also arrives as `null`, and the company's
**size, website, country, city, address and postal code all arrive empty**, so the admin panel
has nothing to render for them.

---

## Root cause

`src/utils/payloadMapping.js` → `buildOrganizationFormData()` appends these keys:

| Sent by the frontend | Backend expects | Stored? |
|---|---|---|
| `name` | `name` | ✅ |
| `email` | `email` | ✅ |
| `phone` | `phone` | ✅ |
| `password` / `password_confirmation` | same | ✅ |
| `organization_name` | `organization_name` | ✅ |
| `organization_type` | `organization_type` | ✅ |
| `organization_industry` | `organization_industry` | ✅ |
| `organization_contact_email` | **`organization_contact_email`** — required, kept | ✅ |
| `proof_file` | `proof_file` | ✅ |
| `terms_accepted` / `privacy_accepted` | same | ✅ |
| `organization_size` | **`organization_company_size`** | ❌ `null` |
| `website` | **`organization_website`** | ❌ `null` |
| `description` | **`organization_description`** | ❌ `null` |
| `country` | **`organization_country`** | ❌ `null` |
| `city` | **`organization_city`** | ❌ `null` |
| `address` | **`organization_address`** | ❌ `null` |
| `postal_code` | **`organization_postal_code`** | ❌ `null` |

Also sent but silently ignored by the backend: `administrator_name` and
`additional_document` (neither has a validation rule).

> **Careful:** `organization_contact_email` looks like the other `organization_*`
> aliases but is genuinely **required** (`['required','email','max:255']`) and is
> written to `organizations.contact_email`. It must stay. Only the seven names in
> the lower half of the table above are wrong.

Two further notes:

- The file's own header comment lists the wrong names as an **unverified assumption**:
  *"flag to backend if any of these field names are wrong"*. That flag was never raised.
- `name` / `email` were added **alongside** the wrong `administrator_name` /
  `organization_contact_email` rather than replacing them, so registration began working
  while the remaining 7 fields stayed broken. This is why the bug was easy to miss.

---

## The fix (frontend, 7 lines)

In `src/utils/payloadMapping.js` → `buildOrganizationFormData()`, rename the appended keys:

```js
-  formData.append('organization_size', companySize || '');
-  formData.append('website', website || '');
-  formData.append('description', companyDescription || '');
-  formData.append('country', country || '');
-  formData.append('city', city || '');
-  formData.append('address', address || '');
-  formData.append('postal_code', postalCode || '');
+  formData.append('organization_company_size', companySize || '');
+  formData.append('organization_website', website || '');
+  formData.append('organization_description', companyDescription || '');
+  formData.append('organization_country', country || '');
+  formData.append('organization_city', city || '');
+  formData.append('organization_address', address || '');
+  formData.append('organization_postal_code', postalCode || '');
```

Also: drop the two ignored aliases (`administrator_name`, `additional_document`), and make sure
`name`, `email` **and** `organization_contact_email` are all three present.

Nothing else needs to change. The wizard (`CompanyStep1..5`), the submit call in
`CompanyStep4.jsx`, `registerOrganization()` in `src/api.js` and the whole backend are correct.

**Backend must not be changed** — the `organization_*` prefix is the established contract used by
every other organization endpoint.

### Applying it

Two branches carry the bug — `main` (`42319f7`) and `develop` (`a5d59b9`). Patch both:

```bash
git apply FRONTEND_ORG_FIELD_FIX.patch      # applies to either branch
```

The patch also updates `src/utils/__tests__/payloadMapping.test.js`, which currently asserts the
**wrong** names and would otherwise lock the bug in place.

---

## Proof

| Step | Result |
|---|---|
| POST the exact `main`-branch multipart body | `201 Created` — registration itself works |
| `organizations.description` after that request | `NULL` (the typed description is discarded) |
| `organizations.company_size`, `website`, `country`, `city`, `address`, `postal_code` | all `NULL` |
| Admin `GET /admin/api/organizations/{id}` | returns `"description": null` |
| Admin panel `resources/views/admin/organizations.blade.php` | renders the empty-state copy — correct behaviour **given the null** |

The backend's admin panel and API are **not** at fault. The panel was previously rendering only
4 of the available fields, which has been fixed separately (commit `75d9dd7`); it now renders all
nine detail rows and shows the empty-state copy only when the value is genuinely null or blank.

---

## Verification after the frontend fix

Re-run the organization registration through the UI, then check:

```bash
php artisan tinker --execute="
  \$o = App\Models\Organization::latest('id')->first();
  dump(\$o->only(['name','description','industry','company_size','website','country','city','address','postal_code']));
"
```

Every column should now hold the value typed into the form.

---

## Evidence gathered

**1. The exact names are confirmed, not guessed.** `RegisterOrganizationRequest.php` declares:

```php
'organization_website'        => ['nullable','url','max:255'],
'organization_description'    => ['nullable','string','max:1000'],
'organization_company_size'   => ['nullable','string','max:50'],
'organization_country'        => ['nullable','string','max:255'],
'organization_city'           => ['nullable','string','max:255'],
'organization_address'        => ['nullable','string','max:500'],
'organization_postal_code'    => ['nullable','string','max:20'],
'organization_contact_email'  => ['required','email','max:255'],   // NOT a bug
```

and `AuthService::createOrganization()` consumes each one
(`'description' => $data['organization_description'] ?? null,` …). Unprefixed names match
**no** rule.

**2. The corrected function emits all 18 validated keys.** Executing the real
`buildOrganizationFormData()` and listing `[...fd.keys()]` gives:

```
name, email, organization_contact_email, phone, password, password_confirmation,
organization_name, organization_type, organization_industry,
organization_company_size, organization_website, organization_description,
organization_country, organization_city, organization_address,
organization_postal_code, terms_accepted, privacy_accepted
```

**3. The check can fail.** Temporarily stripping the prefix from those seven appends — i.e.
reproducing the live bug — makes the same check exit non-zero:

```
MISSING: organization_company_size, organization_website, organization_description,
         organization_country, organization_city, organization_address,
         organization_postal_code
EXIT=1
```

**4. The frontend unit suite passes.** `vitest run src/utils/__tests__/payloadMapping.test.js`
→ **14 passed**. Two of the added tests are explicit regression guards: one asserts all seven
`organization_`-prefixed keys carry the typed value, the other asserts none of the seven
unprefixed names is present.

**5. The backend round trip is covered.** Two integration tests were added to
`tests/Feature/Admin/AdminOrganizationTest.php`:

- `test_every_registration_detail_is_stored_and_returned_to_the_admin_panel` — POSTs the full
  Northwind payload, asserts all ten columns on the `Organization` model, then asserts the same
  ten values through `GET /api/v1/admin/organizations/{id}`.
- `test_the_organization_list_carries_every_registration_detail` — asserts the panel's list
  endpoint (whose rows live under `data.data`) carries the same fields.

`AdminOrganizationTest` → **29 passed** (was 28). `pint --test` → **PASS**.
