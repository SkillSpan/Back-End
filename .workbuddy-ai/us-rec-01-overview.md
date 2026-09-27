# US-REC-01 — Laravel implementation (overview)

**Commit:** `1d1d871` on `feature/authentication` · **Verified:** 280 tests / 1016 assertions passing,
Pint clean on 288 files.

## What was done

Implemented the *Backend — Laravel* half of US-REC-01 (Intelligent Assistant & Personalized
Guidance) against SRS v1.1. Laravel owns authorisation, validation, orchestration and persistence;
FastAPI owns all generated prose. No assistant answer text is written in Laravel.

- `POST /api/v1/assistant/ask` — intent whitelist limited to the permitted assistant behaviour.
- `PUT /api/v1/assistant/interactions/{id}/report` — the §12.6 / REC-08 incident flow, scoped to
  the owning learner.
- `assistant_interactions` table + model — metadata and a `ctx-<sha256>` fingerprint only, never
  the question or the answer (§12.5 data minimisation, BR-14).
- §12.5 governance gate — off by default, and not enableable without a recorded approval
  reference (mirrors REC-06 / BR-REC-07).
- Learner context snapshot built from real sources only; a missing source is recorded as
  `unavailable` rather than fabricated.
- Every query scoped to the authenticated `student_profile_id` (BR-11).
- FastAPI client left as an explicit contract boundary (`ASSISTANT_CONTRACT_PENDING`) — the schema
  is deliberately not guessed.

## Three defects found and fixed while making the tests genuinely pass

1. **The §12.5 gate was effectively unreachable** — asserted only inside the client, i.e. after
   the context snapshot had been assembled and after configuration resolution. Now the first
   data-independent check in `AssistantService::ask()`.
2. **A scope refusal could never carry its stable code** — `AskAssistantRequest` is validated
   before the controller body runs, so the controller's mapping branch was dead and the response
   fell back to a `code`-less 422. Now emitted from `failedValidation()`.
3. **The report-ownership test was not testing ownership** — the second learner had no student
   profile, so the request short-circuited before the BR-11 check it exists to prove.

## Not done, and why

The FastAPI assistant contract (does not exist in any source — not invented), the assistant's
reply text (FastAPI's), the Frontend UI (out of scope), and the retention period for
`assistant_interactions` (undefined by the SRS — hence metadata-only storage).

## Also worth knowing

The story's `AC-01`…`AC-16` list and Definition of Done **exist in no source document**. SRS v1.1
numbers requirements `REC-0X` / `BR-REC-0X` / `ROAD-11` instead. The mapping is in
[`us-rec-01-verification.md`](./us-rec-01-verification.md), which is the detailed report; the
search evidence is in [`us-rec-01-gap-analysis.md`](./us-rec-01-gap-analysis.md).

## Repo note

The `E:` checkout's branch ref vanished twice this session (an external process removing
`.git/refs/heads/feature/`, not a git fault). The commit was intact in the object database and
recovered. The prior session's CI work is documented in [`overview.md`](./overview.md).
