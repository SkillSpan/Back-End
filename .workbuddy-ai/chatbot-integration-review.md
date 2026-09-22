# Chatbot service — integration review against US-REC-01

**Subject:** `E:\SkillSpan\chatbot` (FastAPI, 6 source files, ~24 KB)
**Reviewed against:** the US-REC-01 Laravel implementation at `1b985a1`
**Verdict:** the contract I declared "awaiting" **already exists**. My Laravel client is wired to the
wrong shape — and, more seriously, the service is grounded on *documentation*, not on the learner's
own data, so US-REC-01's central promise ("explain **my** readiness / **my** gaps") is not
deliverable through it without a decision from the team.

---

## 0. Correction first — this was my error

`app/Services/Assistant/AssistantClient.php` states, and my commit message repeats:

> *"No assistant endpoint exists in any approved source: not in SRS v1.1, not in
> `SkillSpan_New_Endpoints.pdf`, and not in the `data-science-service` tree."*

**That is wrong.** I searched `data-science-service` and never listed `/e/SkillSpan/` itself, where
`chatbot/` sits directly beside it:

```
/e/SkillSpan/
├── Back-End-feature-authentication/   ← the Laravel repo
├── chatbot/                           ← THE ASSISTANT SERVICE — I missed this
└── data-science-service/
```

The contract was on disk the whole time. The `ASSISTANT_CONTRACT_PENDING` marker was a **search
failure, not a missing artefact**, and I should not have stated it as a finding about the world.

Worth adding: the Laravel repo contains **zero** references to "chatbot" — no URL, no config key.
So nobody had wired this up from the Laravel side either. That part of my conclusion stands.

---

## 1. The actual contract

| | |
| --- | --- |
| `POST /chat` | request `{user_id, message, context?}` → response `{reply, provider_used, timestamp}` |
| `GET /health` | `{status: "ok"}` |
| Validation | Pydantic → **422** with a detailed body |
| All providers down | **HTTP 200** with `reply = FALLBACK_REPLY`, `provider_used = null` (deliberate) |
| Unexpected error | **500**, body still carries the fallback reply |
| Auth | **none** — no service token, no request-id header |

Field rules (`models.py`):

- `user_id` — `str`, min 1, must not be whitespace-only
- `message` — `str`, 1–2000 chars, must not be whitespace-only
- `context` — `str | None`; blank/whitespace is coerced to `None`
- `reply` — the assistant's text
- `provider_used` — `"gemini"` \| `"groq"` \| `"cerebras"` \| `null`
- `timestamp` — ISO 8601 string

Behaviour: failover chain **Gemini → Groq → Cerebras**, 30 s per attempt, `temperature=0.2`,
`max_output_tokens=1024`. Providers without a key are skipped before any network call.

---

## 2. Where my Laravel client diverges

| # | My implementation | The real service | Consequence |
| --- | --- | --- | --- |
| 1 | Sends `{context_snapshot: array, question, request_id}` | Expects `{user_id, message, context: str}` | **422 on every call.** Field names *and* shape differ. |
| 2 | Reads `$response['algorithm_version']` | Returns `{reply, provider_used, timestamp}` | No such field; `algorithm_version` is always `null`. |
| 3 | Deliberately does **not** return the reply (`AssistantInteractionResource`, pinned by `test_the_response_never_contains_a_generated_answer`) | `reply` **is** the payload | **The UI receives no answer.** The endpoint cannot power a chat. |
| 4 | `context` = the learner's personal data snapshot | `context` = retrieved **SkillSpan documentation** (RAG grounding) | Semantic mismatch — see §3. |
| 5 | Assumes a service token + `X-Request-ID`, mirroring `IntelligenceClient` | No auth, no correlation header | No request correlation across services. |
| 6 | Treats any non-null reply as `SUCCEEDED` | Returns **200 + fallback** when *all* providers fail | **An LLM outage would be recorded as a success.** See §5. |

Item 3 is the one that matters most: I conflated *"Laravel must not generate prose"* (the actual
rule) with *"Laravel must not relay prose"* (which I invented). Relaying FastAPI's reply to the
client is the entire function of the endpoint. Not storing it is defensible; not returning it is a
functional bug of mine.

---

## 3. The real blocker — documentation-grounded, not learner-grounded

`prompt.py` is explicit about what the service is:

> *"Base your answer solely on the official SkillSpan documentation provided as context."*
> *"If the context does not contain the answer, respond exactly: 'I don't have enough information in
> the SkillSpan documentation to answer that.'"*
> *"Do not answer from memory or general knowledge."*

And `build_user_prompt()` sends the bare `message` when no context is supplied — the comment says
this is "the intended behaviour until retrieval is added".

So: send `"Why is my readiness score low?"` with no context and the service will, **by design**,
answer *"I don't have enough information in the SkillSpan documentation to answer that."*

The service has **no field for the learner's own data**. Its only inputs are the question and a
documentation blob. US-REC-01 requires explaining the learner's *personal* readiness, gaps, roadmap
and next action. Those are two different jobs.

There are exactly two ways to close that gap, and **both are decisions for the team, not for me**:

- **(a) Laravel composes the learner's data into `message`/`context` as text.** No service change —
  but it means the learner's readiness, gaps and roadmap are pasted into a prompt that is forwarded
  to Gemini, Groq and Cerebras. That is precisely what §12.5 governs (§4).
- **(b) The service gains a field for learner context** (e.g. `learner_context`), so grounding
  documentation and personal data stay separable and can be governed differently. A Data Science
  change, plus a prompt revision (`SYSTEM_PROMPT_VERSION` is `v1` and the file says downstream
  evaluations reference it).

Related: the system prompt's privacy rule ("Never disclose another user's data") is **unenforceable
inside this service**. `user_id` is accepted, logged, and never used — it is not sent to the model
and not checked against anything. So `user_id` is not an identity, it is a log line. That is a
defensible v1 choice, but it means BR-11 is enforced *entirely* by Laravel. It should be stated that
way rather than implied by the prompt.

---

## 4. §12.5 is now concrete, not hypothetical

The Laravel gate requires a recorded approval before any learner context leaves the platform. This
review is the first hard evidence that the gate is guarding something real: this service forwards
its prompt to **three external LLM providers**, two of which (Groq, Cerebras) are third-party
inference with their own retention behaviour.

Concretely, the gate is the reason decision (a) above cannot be taken casually. Good news: the gate
already works and fails closed, so nothing can leak while the decision is pending.

---

## 5. Findings on the service itself

Ordered by what I would fix first.

1. **No authentication.** The README says *"Authentication is handled upstream by Laravel; the
   service trusts the `user_id` it receives."* But on Render this is a public URL with no token. Any
   caller can burn the three free-tier quotas and use the service as an LLM proxy. The intelligence
   service already solves this with a shared service token — reuse that pattern.
2. **An LLM outage is indistinguishable from success at the HTTP layer.** Returning 200 with a
   fallback is a reasonable *UI* choice, but it is a poor *integration* signal: Laravel (and Render's
   health checks) cannot tell "answered" from "all three providers down". My client currently books
   it as `SUCCEEDED`. At minimum, callers must branch on `provider_used === null`; better, add a
   machine-readable status.
3. **`/health` reports `ok` even with zero provider keys configured.** `config.py` logs an error at
   startup, but the probe still returns `{"status":"ok"}`, so a completely dead service looks
   healthy to Render. Consider reporting provider availability in the probe.
4. **`SYSTEM_PROMPT_VERSION` exists but is never returned.** The file says *"downstream evaluations
   reference this version"* — yet a caller cannot record which prompt version produced a reply. This
   is exactly the `algorithm_version` column my audit table already has, sitting empty. Adding
   `prompt_version` to `ChatResponse` would fill it with one line of change.
5. **`user_id` is accepted but unused** (§3) — either use it or document that it is a log label.
6. **No tests.** The README says so openly. The failover orchestrator is pure logic with an obvious
   mock seam — it is the highest-value, lowest-effort test in the service, and it is the part most
   likely to break silently.
7. **Minor:** `@app.on_event("startup")` is deprecated in favour of the `lifespan` context manager.
   The `not_blank` validator checks but does not `strip()`, so `"  hi  "` is forwarded verbatim. The
   README's example timestamp ends in `Z`, but `.isoformat()` on a UTC datetime emits `+00:00`.
8. **No version control.** `chatbot/` is not a git repository (`.gitignore` exists, `.git` does not).
   The only backup appears to be `chatbot.zip`. And `.env` holds three live API keys — correctly
   gitignored, but sitting in a directory with no history.

---

## 6. Decisions I need before touching the client

I am deliberately **not** rewiring `AssistantClient` yet: the shape is obvious, but the semantics
are not mine to choose.

1. **§3 — where does the learner's data go?** Option (a) compose into the prompt, or (b) a new
   service field? This is the decision the whole integration hangs on.
2. **Should the endpoint return FastAPI's `reply` to the client?** I believe yes — without it the
   endpoint is useless to the UI, and the "don't generate prose in Laravel" rule is untouched by
   relaying it. But it changes the response contract I documented and tested, so I want it confirmed.
3. **Do we store the reply?** My current answer is no (retention period is still undefined by
   §9.5). Returning-without-storing is a coherent position and I would keep it unless told otherwise.
4. **Add `prompt_version` to `ChatResponse`** (finding 4)? One-line change, and it makes the audit
   row meaningful.
5. **Add a service token** (finding 1)? That is a change on the chatbot side, not mine.
6. **§12.5 approval** — still not recorded. Nothing can go live until it is, and §4 shows why the
   gate is not ceremony.

Answer 1 and 2 and I can wire the client, adjust the response resource, and re-run the suite.
