# US-REC-01 — Gap analysis before implementation

**Verdict: I cannot start writing code yet.** The document US-REC-01 lives in does not exist
on this machine, and the closest available document (SRS v1.1) uses a **different identifier
scheme** and **does not define an assistant endpoint**.

This is a traceability report, not a code review. It states exactly what I verified, what is
missing, and the decisions I need from you.

---

## 1. Evidence — what I actually checked

I searched the repo, `Desktop`, `Downloads` and `Documents`, and extracted text from every
candidate PDF (`pypdf`, 63 pages for the SRS).

| Artefact | `US-REC` | `AC-01`…`AC-16` | `BR-11` / `BR-14` | `Scenario 5`/`6` | `Intelligent Assistant` |
| --- | --- | --- | --- | --- | --- |
| `SkillSpan_Complete_SRS_Team_Integrated_v1.1.pdf` (63 pp) | **0** | **0** | **0** | **0** | **0** |
| `SkillSpan_Complete_SRS_Team_Integrated_v1.1 (1).pdf` | **0** | **0** | **0** | **0** | **0** |
| `SkillSpan_New_Endpoints.pdf` (2 pp) | **0** | **0** | **0** | **0** | **0** |
| `SkillSpan_API_Endpoints.pdf` (2 pp) | **0** | **0** | **0** | **0** | **0** |
| repo (`app/`, `database/`, `routes/`, `tests/`) | **0** | **0** | **0** | **0** | **0** |
| `Desktop/TExt/skillSpan.txt` | **0** | **0** | **0** | **0** | **0** |

The only match anywhere was an unrelated comment in `AuthController.php` referencing
`US-AUTH-06`.

**The SRS contains no `US-XXX-NN` user-story identifiers at all.** It identifies requirements as
`REC-01`, `WRK-06`, `ROAD-11`, `BR-REC-01`… — requirement IDs, not stories with acceptance
criteria. There is no `AC-*` list, no `BR-01`/`BR-11`/`BR-14`, and no `Scenario 5`/`Scenario 6`.

> ⚠️ Note on method: a naive grep on this PDF returns false negatives — it extracts **one word
> per line**, so multi-word phrases never match. Every result above was taken *after* collapsing
> whitespace. The "0 hits" are real, not an extraction artefact.

---

## 2. 🔴 Blocker — the story document is not available

Your prompt requires every design decision to trace to a specific `US-REC-01` item, and asks me
to confirm `AC-01`→`AC-16` and the Definition of Done at the end of the story. **None of those
identifiers exist in any file I can reach.** I will not invent an acceptance-criteria list —
that is exactly the "don't fabricate" rule your own prompt sets.

Two ways forward (your call):

- **A — send the real story document.** I implement strictly against it and verify each AC.
- **B — authorise SRS v1.1 as the authority.** I proceed from the SRS requirements below, and
  flag every point where your prompt and the SRS disagree. This unblocks work now, but the
  resulting AC coverage will be *my* mapping, not the team's signed-off list.

---

## 3. 🔴 Blocker — the SRS gates sending learner data to the external service

`§12.5 AI Assistant Boundaries`, verbatim:

> *"Sensitive data shall not be inserted into external AI services without explicit technical
> and governance approval."*
> *"AI conversations used for quality improvement shall follow approved privacy, retention, and
> consent rules."*

Your prompt's whole design is *build a learner context snapshot → send it to FastAPI*. The SRS
puts an explicit **technical + governance approval** gate in front of that. Combined with your
answer that the FastAPI contract is the data analyst's work and isn't available, this is the
single biggest risk in the task: the architecture you described may not be approved to run yet.

I need to know whether that approval exists. If it does not, the honest implementation is to
build the gateway **behind a disabled-by-default flag**, with the approval recorded as a
documented precondition — not to ship a live path that sends learner context out.

---

## 4. 🟡 Ambiguities that need a decision

| # | Question | Why it blocks |
| --- | --- | --- |
| 1 | **"Application context *where permitted*"** — your prompt says ask. The SRS never defines it. | I cannot add a data source I cannot define. |
| 2 | **`recommendations` is keyed on `user_id`, not `student_profile_id`**, and has **no project FK** — it uses `candidate_type` + `candidate_id`. | Your rule "every query scoped by `student_profile_id`, no exception" cannot be met against this table as-is, and `related_project_id` has nothing to point at. |
| 3 | **Retention period for assistant interactions.** `§9.5` says periods "shall be defined by data category, purpose, legal obligation, consent, and operational need" — i.e. it requires a definition without providing one. | Determines whether the table stores a snapshot, a hash, or a reference. |

---

## 5. ✅ What the SRS *does* cover — usable once we agree on authority

This is better than nothing, and most of your prompt maps onto it cleanly:

| Your prompt | SRS equivalent | Status |
| --- | --- | --- |
| "The assistant shall not" — no fabrication, no invented learner data | `§12.5`, `REC-07` | ✅ covered |
| No writing to authoritative records / cannot override Readiness | `BR-REC-02` ("No recommendation score shall grant authorization or override project rules") | ✅ covered |
| Scope of the assistant (explain readiness, gap, roadmap, next action, project) | `§12.5` (may explain, clarify, ask reflective questions, suggest next steps, direct to approved resources) | ✅ covered |
| Scenario 6 — report an unsafe/irrelevant/unfair response | `§12.6 Decision Incident and Dispute Flow` (items 27–32) + `REC-08` | ✅ covered |
| Next Best Action | `ROAD-11` — "one explainable Next Best Action… includes why it is next and what completion unlocks" | ✅ covered |
| Reproducibility / versioning | `REC-01` (reproducible from input snapshot + configuration version), `§8.6` (explicit versioned schemas) | ✅ covered |
| BR-14 audit of every interaction | `§12.5` + `§9.5` — direction clear, **period undefined** | 🟡 partial |
| AC-01 → AC-16 | — | 🔴 **not found** |
| FastAPI assistant endpoint | — | 🔴 **does not exist** (see below) |

### The assistant endpoint genuinely does not exist

The SRS lists these intelligence endpoints: `POST /api/v1/intelligence/skill-gap`,
`…/readiness`, `…/roadmap`, `…/project-recommendations`. **There is no assistant endpoint** in
the SRS, in `SkillSpan_New_Endpoints.pdf`, or in `/e/SkillSpan/data-science-service` (which
contains no `assistant` code at all).

So your prompt's instruction applies directly: I build a **pluggable client with an explicit
"awaiting FastAPI contract" marker**, not a guessed contract.

> 💭 Separately noticed, out of scope: the SRS specifies
> `POST /api/v1/intelligence/project-recommendations`, but `routes/api.php` only exposes
> `/intelligence/calculate` and `/intelligence/latest`. Either the SRS or the routes are stale.
> Flagging it, not touching it.

---

## 6. Recommended next step

1. Decide **A or B** in §2 (send the story, or authorise the SRS).
2. Answer the §12.5 approval question in §3 — this decides whether the endpoint ships live or
   behind a flag.
3. Answer the three decisions in §4.

Once those are in, the Laravel work is well-defined and I can follow the existing
`IntelligenceController` pattern without inventing anything.
