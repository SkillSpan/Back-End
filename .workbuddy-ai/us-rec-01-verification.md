# US-REC-01 — Implementation & verification statement

**Scope:** the *Backend — Laravel* half only, as the task defined it.
**Commit:** `1d1d871` on `feature/authentication` (parent `540dff3`).
**Verified:** 280 tests / 1016 assertions passing; `vendor/bin/pint --test` clean on 288 files.

This document is the explicit confirmation the task asked for at the end: which items are
**actually implemented in code and proven by a passing test**, and which are **not done** and why.

---

## 1. The `AC-01` → `AC-16` list and the Definition of Done do not exist

The task asked me to review `AC-01`…`AC-16` and the story's Definition of Done before declaring
the work finished. **Those identifiers exist in no source on this machine** — not in SRS v1.1,
not in `SkillSpan_New_Endpoints.pdf`, not in `SkillSpan_API_Endpoints.pdf`, not in the repo, not
in `Desktop/TExt/skillSpan.txt`. Full search evidence is in
[`us-rec-01-gap-analysis.md`](./us-rec-01-gap-analysis.md) §1.

SRS v1.1 does not use `US-XXX-NN` story identifiers at all. It numbers requirements
`REC-01`…`REC-08`, `BR-REC-01`…`08`, `WRK-06`, `ROAD-11`, and states acceptance criteria **inline
in an "Acceptance / Verification" column** rather than as a numbered `AC-*` list.

So there is nothing to tick off under those names. Rather than invent a list — which would be the
fabrication the same task forbids — the table below maps each requirement of the task onto the
identifier SRS v1.1 actually uses, and states its real status. You authorised SRS v1.1 as the sole
numbering source; this is that mapping, and it is **my** mapping, not the team's signed-off list.

---

## 2. Status of every item in scope

| Task requirement | SRS v1.1 source | Status | Proof |
| --- | --- | --- | --- |
| Assistant restricted to a permitted scope; out-of-scope refused **before** FastAPI | `§12.5`, `BR-01` | ✅ implemented | `test_an_intent_outside_the_permitted_scope_is_refused`, `test_an_out_of_scope_intent_never_reaches_the_service`, `test_a_missing_intent_is_a_plain_validation_error` |
| No fabrication; missing data recorded as unavailable | `REC-07`, `§12.5` | ✅ implemented | `test_every_section_is_explicitly_unavailable_for_a_bare_learner`, `test_it_refuses_when_no_active_configuration_exists`, `test_with_the_gate_open_the_missing_contract_is_reported_honestly` |
| Never modify authoritative records | `BR-REC-02` | ✅ by construction | The assistant has no write path: the only table it writes is `assistant_interactions`. No Readiness / Roadmap / Evaluation model is touched. |
| §12.5 governance gate in front of any learner data leaving the platform | `§12.5` | ✅ implemented | `test_the_assistant_is_off_by_default`, `test_enabling_without_a_recorded_approval_still_fails_closed`, `test_a_blank_approval_reference_is_treated_as_missing`, `test_a_closed_gate_sends_nothing_to_the_service`, `test_the_governance_gate_is_asserted_before_configuration_is_resolved` |
| Every query scoped to the authenticated learner | `BR-11` | ✅ implemented | `test_another_learners_readiness_is_never_included`, `test_another_learners_recommendation_is_not_returned`, `test_a_project_with_no_connection_to_the_learner_is_not_accessible`, `test_an_application_by_another_learner_does_not_grant_access`, `test_a_learner_cannot_report_another_learners_interaction`, `test_a_non_existent_interaction_is_indistinguishable_from_a_forbidden_one` |
| Report an unsafe / irrelevant / unfair / incorrect response | `§12.6` (items 27–32) + `REC-08` | ✅ implemented | `AssistantReportTest` — all 8 tests |
| Explainable Next Best Action | `ROAD-11` | ✅ implemented (read-side only) | `test_the_next_best_action_skips_completed_actions`, `test_the_next_best_action_is_unavailable_when_everything_is_complete` |
| Audit record for every interaction | `§12.5` / `§9.5` | 🟡 partial | `test_a_failed_interaction_is_still_audited`, `test_the_audit_row_stores_no_conversation_content`, `test_the_request_id_is_correlated_through_to_the_audit_row`. Direction is implemented; the **retention period is still undefined by the SRS** (see §4). |
| Reproducibility from snapshot + configuration version | `REC-01`, `§8.6` | 🟡 partial | `configuration_version` is resolved from the active `AlgorithmConfiguration` and stored; the context is stored as a **fingerprint** (`ctx-<sha256>`), not as a full input snapshot. Deliberate — see §4. |
| The assistant's actual reply text | — | ❌ **not implemented** | FastAPI's responsibility. Prohibited in Laravel by the task. |
| `AC-01`…`AC-16`, Definition of Done | — | 🔴 **no source document** | §1 above. |

### Evidence

42 tests, all passing, in `tests/Feature/Assistant/`:

- `AssistantAskTest.php` — 18 tests: authorization, scope, the §12.5 gate, configuration binding,
  the audit record, and the no-fabrication guarantee.
- `AssistantContextTest.php` — 16 tests: the context snapshot, per-section unavailability, and
  BR-11 scoping at the query level.
- `AssistantReportTest.php` — 8 tests: the §12.6 / REC-08 incident flow.

---

## 3. Defects found and fixed while verifying

The task insisted that items be *actually* working, not "supposed to work". Making the tests
genuinely pass surfaced three real defects. All three are fixed; each is now pinned by a test.

1. **The §12.5 governance gate was effectively unreachable.**
   It was asserted only inside `AssistantClient::ask()`, which runs *after* the learner context
   snapshot had already been assembled and *after* configuration resolution. Two consequences:
   a control that is consulted only after the learner's data has been gathered is not a control;
   and a disabled assistant returned `INTELLIGENCE_CONFIGURATION_INVALID` instead of
   `ASSISTANT_NOT_ENABLED`, pointing operators at the wrong subsystem.
   *Fix:* the gate is now the first data-independent check in `AssistantService::ask()`. The
   client re-asserts it, so the transport can never be reached without approval.
   *Pinned by:* `test_the_governance_gate_is_asserted_before_configuration_is_resolved`.

2. **A scope refusal could never carry its stable error code.**
   `AskAssistantRequest` is validated during route resolution, so a failing `intent` never reached
   the controller body — the controller's `catch (ValidationException)` branch that mapped
   `ASSISTANT_INTENT_NOT_ALLOWED` was dead, and the response fell back to a `code`-less 422,
   breaking this API's uniform error envelope.
   *Fix:* `failedValidation()` on the request now emits the code, where the failure actually
   occurs. *Pinned by:* `test_an_intent_outside_the_permitted_scope_is_refused`,
   `test_a_missing_intent_is_a_plain_validation_error`.

3. **The report-ownership test was not testing ownership.**
   `test_a_learner_cannot_report_another_learners_interaction` built the "other" learner with a
   role but no student profile, so the controller short-circuited on `STUDENT_PROFILE_NOT_FOUND`
   and the BR-11 check it exists to prove was never reached — it passed for the wrong reason.
   *Fix:* the second learner is now fully provisioned.

---

## 4. Not done, and why

- **The FastAPI assistant contract.** No assistant endpoint exists in the SRS, in
  `SkillSpan_New_Endpoints.pdf`, or in `/e/SkillSpan/data-science-service`. Per the task, the
  contract was **not** invented. `AssistantClient::ask()` is the single boundary and fails with
  the stable code `ASSISTANT_CONTRACT_PENDING`; wiring it up is one method, mirroring
  `IntelligenceClient::post()`.
- **The assistant's reply text.** FastAPI's, by design.
- **Frontend UI.** Explicitly out of scope.
- **Retention period for `assistant_interactions`.** `§9.5` requires periods to be defined per
  data category but does not define them. Because no period exists, the table stores **metadata
  and a fingerprint only — never the question or the answer** (§12.5 data minimisation). This is
  the safe default while the period is undefined; it also means a future retention rule cannot
  retroactively expose conversation content, because none was ever stored.
- **A full input snapshot.** Same reason as above: `context_reference` is a `ctx-<sha256>`
  fingerprint, which gives reproducibility and change detection without storing the payload.

---

## 5. Definition of Done — assessment

The story's DoD list is not available (§1), so I assessed against the conditions the task itself
set out:

| Condition | Met? |
| --- | --- |
| Every decision traceable to a source item, none invented | ✅ each decision cites `REC-*` / `BR-REC-*` / `§12.5` / `§12.6` / `ROAD-11` in code comments |
| Follows the existing `app/Services/Intelligence/` architecture | ✅ same validate → build → persist PENDING → call → validate → persist COMPLETE shape |
| Laravel authoritative + orchestration only; no assistant prose written in Laravel | ✅ no answer text is generated, stored, or echoed |
| No fabrication, no fallback data | ✅ unavailable sources are recorded as unavailable; the client refuses rather than guessing |
| No authoritative record modified | ✅ no write path exists |
| Every query scoped to the authenticated `student_profile_id` | ✅ and proven at the query level |
| Intent validated against the permitted scope before FastAPI | ✅ rejected during route resolution, i.e. as early as possible |
| `assistant_interactions` migration + model | ✅ metadata only, per BR-14 |
| Service failures handled with the existing exception/error-code pattern | ✅ `AssistantException` → `ReadinessException` → the controller's error envelope |
| Pluggable FastAPI client, contract not invented | ✅ explicit marker, one method to implement |
| Report endpoint with ownership verification | ✅ `PUT /api/v1/assistant/interactions/{id}/report` |
| Tests pass for real | ✅ 280 passed / 1016 assertions, verified — not assumed |

---

## 6. Open items for you

1. **The §12.5 approval is still not recorded.** The endpoint ships disabled
   (`ASSISTANT_ENABLED=false`) and refuses to enable without `ASSISTANT_APPROVAL_REFERENCE`. Until
   governance signs off, no learner data can leave the platform — which is the intended state.
2. **Retention period** for `assistant_interactions` — needed before any conversation content
   could ever be stored, and needed to define a purge job.
3. **`C:` checkout is stale** at `d025485` and needs catching up with `feature/authentication`.
4. **Pre-existing, unrelated:** the SRS specifies `POST /api/v1/intelligence/project-recommendations`
   but `routes/api.php` exposes only `/intelligence/calculate` and `/intelligence/latest`.
