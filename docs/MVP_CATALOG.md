# MVP catalogue — specializations, career roles, skills

This document records the MVP organization of specializations and career roles,
and the audit that preceded it. It is the human-readable companion to
`config/mvp.php` (the policy) and `database/seeders/MvpCatalogSeeder.php` (the
applier).

Nothing in this work deleted a row, renamed a slug, or removed a relationship.

---

## 1. Specializations

### Offered in the MVP

| # | Specialization | Source row |
|---|----------------|------------|
| 1 | Computer Science | existing (id 1) |
| 2 | Information Technology | existing (id 4) |
| 3 | Information Systems | existing (id 3) |
| 4 | Software Engineering | existing (id 2) |
| 5 | Data Science | existing (id 5) |
| 6 | Artificial Intelligence | existing (id 6) |
| 7 | Computer Engineering | existing (id 8) |
| 8 | Networks & Cloud Computing | **created** — the catalog had no single row for this MVP track |
| + | Self-Learning / Free Track | existing, `is_free_track = true` |

### The "Networks & Cloud Computing" merge

The catalog split this ground across three rows: `Computer Networks`,
`Cloud Computing` and `DevOps & Infrastructure`. The MVP lists one track, so the
seeder creates `Networks & Cloud Computing` once and links it to the union of
the three legacy tracks' career roles (plus its own priority roles). The three
legacy rows are then **deactivated, not deleted**: their ids, their pivot links
and any historical reference stay intact, and bringing one back is a single
`UPDATE`.

This was the only new specialization row. No existing row was renamed or
re-slugged — the table has no slug column, and `name` (unique) is the only
stable identifier, so renaming would have rewritten what existing profiles
display.

### Deactivated (kept for the future)

Everything outside the MVP list — the other technical tracks (`Cybersecurity`,
`Web Development`, `Data Engineering`, `Machine Learning`, `Game Development`,
`UI/UX Design`, …) and the ten non-technical rows that predate the taxonomy
(`Nursing`, `Law`, `Accounting`, …). They remain in `specializations` with
`is_active = false`.

Total: 41 rows, 9 active, 32 inactive.

### Where the list is consumed

| Surface | Behaviour |
|---|---|
| `GET /api/v1/reference/specializations` | already filtered on `is_active` — now returns exactly the 9 MVP tracks |
| `GET /api/v1/career-roles?specialization_id=` | rejects an inactive id with 422 (see §4) |
| `POST /api/v1/baseline-assessments` | rejects an inactive `specialization_id` with 422 |
| Admin specialization panel | unchanged; still lists every row so an operator can reactivate one |

---

## 2. Career roles

### Two independent flags

`status` = review state of the definition (`draft` / `approved` / `retired`).
`is_active` = whether the product offers the role right now (new column,
`BOOLEAN DEFAULT TRUE`, so every pre-existing role stayed offered the moment the
migration ran).

A role is offered only when **both** hold. Deactivating never touches `status`.

### Numbers

| | Count |
|---|---|
| Career roles seeded by `CareerRoleSeeder` | 146 |
| Deactivated for the MVP | 53 |
| Still offered | 93 |

(A development database that also ran `E2eRoadmapFixtureSeeder` has two extra
test roles — `e2e-roadmap-primary`, `e2e-roadmap-allmet` — so it reports
148 / 95. They are created only outside production.)

### Deactivated (row, skills, questions and pivot links all preserved)

The roles the brief names explicitly: `software-tester`, `qa-engineer`,
`test-automation-engineer`, `it-support-specialist`, `network-administrator`,
`erp-specialist`, `business-analyst`.

The rest of the QA family: `manual-qa-tester`, `automation-qa-engineer`,
`performance-test-engineer`.

Administrative / operational roles: `system-administrator`,
`systems-administrator`, `it-administrator`, `it-manager`,
`it-project-coordinator`, `it-operations-manager`, `it-operations-specialist`,
`technical-support-engineer`, `cloud-support-engineer`,
`network-support-engineer`, `noc-engineer`, `crm-specialist`,
`technology-consultant`, `digital-transformation-specialist`,
`digital-solutions-specialist`, `automation-specialist`,
`business-systems-analyst`.

UI/UX and design — the MVP has no design track: `ui-designer`, `ux-designer`,
`product-designer`, `ux-researcher`, `interaction-designer`,
`design-system-designer`, plus the two game-design roles `game-designer` and
`technical-game-designer` (design roles, not engineering ones).

Cybersecurity — the MVP has no security track: the complete family, i.e. every
role curated under the *Cybersecurity*, *Offensive Security* and *Digital
Forensics* specializations — `security-analyst`, `soc-analyst`,
`cybersecurity-engineer`, `security-engineer`, `incident-response-analyst`,
`threat-intelligence-analyst`, `security-consultant`, `penetration-tester`,
`ethical-hacker`, `red-team-operator`, `vulnerability-analyst`,
`security-testing-engineer`, `digital-forensics-analyst`, `dfir-analyst`,
`cybercrime-analyst`, `incident-response-specialist` — plus the two security
roles curated under other tracks: `network-security-engineer` (*Computer
Networks*) and `cloud-security-engineer` (*Cloud Computing*). Their domain is
network / cloud, but a "security engineer" is a security specialism, so the MVP
decision applies to them as well.

`api-developer` is in the policy list but does not exist in the catalog, so the
update is a no-op; it is recorded as a future role instead (§3). A test asserts
that every other configured slug really exists, so a typo cannot silently leave
a role visible.

### Deliberately NOT deactivated

`network-engineer`, `cloud-engineer`, `devops-engineer`,
`site-reliability-engineer` — hands-on engineering roles the brief asks for by
name, despite the resemblance to the administrative roles above and to the two
security roles now excluded. A test pins all four as still offered, in the same
assertion that pins the two security roles as not offered, so the boundary
cannot drift in either direction.

`network-security-engineer` (curated under *Computer Networks*) and
`cloud-security-engineer` (curated under *Cloud Computing*) — network and cloud
engineering roles of tracks that ARE in the MVP, not members of the
cybersecurity family. A test pins both as still offered, so a future
"deactivate everything matching `security`" sweep cannot take them out by
accident.

### Ambiguous cases — documented, left active

The brief says not to auto-deactivate a role that could be read two ways. These
were reviewed and kept active:

| Role | Why it stays |
|---|---|
| `systems-analyst` | Systems analysis is technical design work; the brief only excludes *Business* Analyst |
| `product-data-analyst`, `marketing-data-analyst` | Data analysis specialisms, not design or security |
| `data-visualization-specialist` | A data role (Data Analytics track), despite the "visualisation" wording |

They appear in the neutral "rest" block of every list, after the priority roles.

### `CareerRoleSeeder` interaction

`CareerRoleSeeder` writes `title`, `status`, `effective_date` and the role's
skill set; it never writes `is_active`, so a deactivation survives a re-seed.
`MvpCatalogSeeder` is the only *bulk* writer of the flag; a single role can also
be switched from the admin panel (§9).

---

## 3. Roles the brief asks for that the catalog does not contain

Recorded instead of invented — creating a role without required skills would
produce a role that can never start an assessment:

| Slug | Title | Needed by |
|---|---|---|
| `desktop-developer` | Desktop Developer | Computer Science, Software Engineering |
| `python-developer` | Python Developer | Artificial Intelligence, Computer Engineering |
| `computer-engineer` | Computer Engineer | Computer Engineering |
| `cloud-developer` | Cloud Developer | Networks & Cloud Computing |
| `infrastructure-automation-engineer` | Infrastructure Automation Engineer | Networks & Cloud Computing |
| `api-developer` | API Developer | (excluded from MVP anyway) |

"Business Intelligence Developer" is **not** missing — it exists as
`bi-developer` (title "BI Developer") and is used as-is.

---

## 4. Ordering and availability

Every active specialization returns **all** 93 active roles (95 in a dev
database with the E2E fixtures). The specialization decides only the order:

1. its priority slugs from `config/mvp.php`, in the configured order;
2. every other active role, ordered by `version DESC, id DESC`.

The rank is compiled into the SQL (`CASE career_roles.slug WHEN ? THEN n …`)
before pagination, so:

* the priority roles are first on page 1, not just first within a page;
* every row gets exactly one rank — no duplicates by construction;
* `version DESC, id DESC` makes the order total, so pages never drift.

The free track has no priority list → neutral order. Omitting
`specialization_id` produces the same neutral order as before this change, so
existing consumers see their previous list.

`GET /api/v1/career-roles/{id}` and `.../skills` still resolve an **inactive**
role: deactivation removes a role from the pickers, it must never lock a learner
or a project out of something they already reference.

---

## 5. Skills audit

Catalog state: **212 skills**, all `status = active`, all with at least one
active question.

### Skills with no career role (12) — kept

`java`, `c` (C++), `rust`, `graphql`, `css-tailwind`, `pandas`, `numpy`,
`model-serving`, `reinforcement-learning`, `ospf`, `bgp`, `wireless-networking`.

Each has a question in the bank. They are unused by the current role
definitions, which is not a reason to delete them — they are the pool the
missing/future roles will draw from.

### Near-duplicates — reviewed, none merged

| Pair | Finding |
|---|---|
| `statistics` / `statistics-fundamentals` | Different scope: modelling/statistics vs. the basics a Data Analyst needs. Both are required by different roles (`data-scientist` vs `data-analyst`). Kept apart. |
| `sql` / `sql-databases` | `sql` is query language; `sql-databases` is the broader store/design competency used by 16 roles. Kept apart. |
| `authentication` / `authentication-authorization` | Single concern vs. the full authN+authZ competency. Kept apart. |
| `css` / `css-tailwind` | Plain CSS vs. utility-framework practice. Kept apart. |
| `c` (C++) / `cc` (C/C++) | `c` is the language; `cc` is the shared C/C++ embedded competency. Slugs do not collide (`c` vs `cc`). Kept apart. |

No merge was performed: merging requires proven identity of content, purpose
**and** measurement, and none of these pairs qualifies. Merging would also
rewrite role→skill and question→skill mappings.

### Categories filled in (7)

These were the only skills with a `NULL` category. Category is display metadata
(shown in talent discovery; never used to filter or score), so this is a safe,
reversible improvement:

| Skill | Was | Now |
|---|---|---|
| Python | NULL | Programming |
| JavaScript | NULL | Programming |
| TypeScript | NULL | Programming |
| Java | NULL | Programming |
| C++ | NULL | Programming |
| Rust | NULL | Programming |
| SQL | NULL | Databases |

No name, slug or id changed.

### Integrity checks — all clean

| Check | Result |
|---|---|
| `career_role_skills` rows pointing at a missing skill | 0 |
| `career_role_skills` rows pointing at a missing role | 0 |
| Duplicate `(career_role, skill)` pairs | 0 |
| Pivot rows with a missing specialization or role | 0 |
| Question `item_id` prefix ≠ its skill's slug | 0 (of 272) |
| Questions with no `skill_id` | 0 |
| Career roles with no required skills | 0 |

---

## 6. Assessment coverage

Chain preserved: `Career Role → Required Skills → Questions mapped to those
skills`. Specializations are **not** part of the assessment chain.

| Check | Result |
|---|---|
| Active questions | 272 (all `single_choice`) |
| Skills with ≥ 1 active question | 212 / 212 |
| Required skills with no active question | **0** |
| Career roles that cannot start an assessment | **0** |
| Duplicate question text | 1 pair — "What is phishing?" appears twice, filed under different skills; both are valid single-choice items with different options. Left as-is (removing either would change a skill's coverage). |

Because the selector's floor is one question per required skill, every role —
including every deactivated role — still has full coverage. **No question was
added or rewritten**: there was no gap to close.

Deactivating a role does not delete its questions, and existing assessments keep
working: the selector and the submission path read the frozen snapshot, never
the live activation flag.

---

## 7. Migration and rollback

`2026_11_07_000001_add_is_active_to_career_roles_table.php`

* adds `career_roles.is_active` (`BOOLEAN DEFAULT TRUE`) — existing rows become
  active without an UPDATE;
* adds `career_roles_status_is_active_index` on `(status, is_active)`, the exact
  pair every learner-facing list filters on;
* `down()` drops the index and the column, restoring the previous schema.

No data migration, no backfill, no destructive statement. Safe on SQLite
(tests) and MySQL (production).

## 8. Applying it to an existing deployment

```
php artisan migrate --force
php artisan db:seed --class="Database\Seeders\MvpCatalogSeeder" --force
```

`MvpCatalogSeeder` also drops the cached reference lists
(`ReferenceCache::flush()`), because `/api/v1/reference/specializations` is
cached for twelve hours: without it the learner-facing dropdown would keep
serving the old track list after the policy had already been applied in the
database. A redeploy is unaffected — a fresh container starts with an empty
file cache.

Rollback: `php artisan migrate:rollback --path=database/migrations/2026_11_07_000001_add_is_active_to_career_roles_table.php`
restores the previous schema. Note that it drops the activation flag, so every
role becomes "offered" again; deactivate the MVP scope again by re-running the
seeder after a re-migrate.

---

## 9. Admin control of a single career role

The policy file governs the MVP in bulk, but two operations need to happen
between deploys: publishing a role that was just created, and retiring one role
without touching the policy. Both are session-authenticated admin endpoints —
the route group already carries `auth` + `account.active` + `admin`, so they are
administrator-only, and they are **additive**: no existing route, payload or
behaviour changes.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/admin/api/career-roles` | Paginated roles with `is_active`. Filters: `q` (title/slug), `status`, `is_active` (0/1), `per_page` |
| `POST` | `/admin/api/career-roles/{id}/activate` | Offer the role to learners |
| `POST` | `/admin/api/career-roles/{id}/deactivate` | Remove it from every picker |

Rules:

* **Activation requires `status = approved`.** Otherwise the request is refused
  with `409 CAREER_ROLE_ACTIVATION_REFUSED` and the role stays inactive —
  publishing an unreviewed definition would put unapproved required skills in
  front of students. Deactivation is always allowed.
* Neither endpoint deletes anything. A deactivated role keeps its row, its
  required skills, its questions, its pivot links and every assessment,
  readiness result or project that references it.
* `is_active` is exposed in *these* payloads only. The public career-role
  payload still hides it, so the frontend contract is untouched.

### Creating a role no longer publishes it

`SpecializationService::createCareerRole()` now writes `is_active = false`
explicitly. Before, the column default published a brand-new role to every
learner the moment it was saved — including a half-authored role with no skills
yet. "Create" and "offer to the whole student body" are now two separate
decisions; the role is approved but hidden until an administrator activates it.

Roles that already existed keep whatever activation state they had.

---

## 10. Reference cache invalidation on admin writes

`GET /api/v1/reference/specializations` is cached for twelve hours. Every
mutating method of `SpecializationService` (`create`, `update`, `setActive`,
`delete`) now ends with `ReferenceCache::flush()`, so an operator's change is
visible on the very next public request instead of after the TTL — the class
docblock says the cache must be invalidated by the writer, and this is that
writer.

Covered by `AdminSpecializationCacheTest`, which primes the cache with a real
request, mutates through the admin API, and asserts the next public request
already reflects it. Removing the flush makes five of those seven tests fail.
