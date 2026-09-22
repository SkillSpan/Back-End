# Chatbot service — integration review against US-REC-01

**Subject:** `E:\SkillSpan\chatbot` (FastAPI, 6 source files, ~24 KB)
**Reviewed against:** the US-REC-01 Laravel implementation at `1b985a1`
**Verdict:** the contract I declared "awaiting" **already exists**. My Laravel client is wired to the
wrong shape — and, more seriously, the service is grounded on *documentation*, not on the learner's
own data, so US-REC-01's central promise ("explain **my** readiness / **my** gaps") is not
deliverable through it without a decision from the team.

> **Update — service-side fixes applied.** Findings 3, 4, 6, 7 and 8 below are fixed, and a test
> suite now exists (29 tests, all passing). See §7. Findings 1 and 2 are addressed only partially,
> and §8 explains why: the deployed frontend calls this service **directly**, which invalidates the
> fix I would otherwise have made.
>
> **Update — RAG is blocked on a missing corpus.** See §9. There is no SkillSpan documentation
> anywhere in the tree to retrieve over.

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

---

## 7. Service-side fixes applied

All changes are inside `E:\SkillSpan\chatbot`. **No rollback point was created**, because the
directory is not a git repository — the pre-change sources are recoverable from
`E:\SkillSpan\chatbot.zip` (it holds all five original `.py` files).

| Finding | Fix |
| --- | --- |
| 3 — `/health` said `ok` with no keys | `status` is now `"ok"` or `"degraded"`, plus a `providers` list. Still HTTP 200: a missing key is a config problem, not a dead process, so a Render restart would not fix it. |
| 4 — `SYSTEM_PROMPT_VERSION` never returned | `prompt_version` is now a **required** field on `ChatResponse`, and is set on the success, fallback and 500 paths. This is what fills the `algorithm_version` column my audit table already had. |
| 6 — no tests | `tests/` with 29 tests (orchestrator, prompt, endpoints) + `requirements-dev.txt` + `pytest.ini` + `conftest.py`. They stub the chain, so they need no API keys and make no network calls. |
| 7 — minor | `not_blank` now returns the stripped value; `@app.on_event("startup")` replaced with the `lifespan` context manager. |
| 1 — no auth | **Partial.** Optional shared token: when `SERVICE_TOKEN` is set, `/chat` requires `Authorization: Bearer …`; `/health` stays open so Render's probe works. Deliberately optional rather than required — see §8. |
| 2 — outage indistinguishable from success | **Partial.** `provider_used: null` is now documented in the code, the README and the docstring as *the* failure signal. I did **not** change the 200, because that is a deliberate UI decision, not mine to reverse. |

A test also caught a real bug while writing it: `build_user_prompt` treated whitespace-only
`context` as present, so it injected an empty documentation block. The API path was safe because
`ChatRequest` normalises it to `None` first, but the function was wrong on its own. Fixed in
`prompt.py`.

**Verified:** `29 passed`. `main.py`, `config.py`, `models.py`, `prompt.py`, `providers.py` and the
tests all compile, and the app imports cleanly.

---

## 8. ⚠️ The finding that changes the integration: the frontend calls this service directly

> **CORRECTION (later session) — this inference was wrong, and it is worth recording why.**
> I read the Vercel origin in `CORS_ALLOWED_ORIGINS` as proof that the browser called `/chat`.
> A CORS entry is evidence of an *intended* browser path, not of an *actual* caller — and here the
> intended path was never built. Direct inspection of the frontend repo found **zero** files
> mentioning "chat" or "assistant", no chatbot host or port, no service token, and no assistant UI
> at all; its only base URL is `VITE_API_BASE_URL` → the Laravel API. Its own `.env.example` reads
> *"React never talks to FastAPI directly - every request goes through Laravel."*
>
> So the §12.5 gate was not being bypassed — it was **inert**, because nothing could reach the
> service *through Laravel* either: `AssistantClient` sent fields the service never accepted and
> threw before opening a socket. The recommendation below (Laravel-mediated only) still stands and
> is now enforced; the severity assessment does not. Lesson: an allow-list describes intent, not
> traffic — go and read the caller.

The startup log printed the real CORS configuration, and `.env` holds:

```
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:5173,http://localhost:8010,https://skillspan-iota.vercel.app
```

That last origin is the **deployed Vercel frontend**. So the browser is allowed to call `/chat`
itself — which contradicts the README's own diagram (`React frontend -> Laravel backend -> [this
service]`). The service is wired for direct browser access, not for Laravel-in-the-middle.

Three consequences, in order of severity:

1. **The §12.5 gate can be bypassed entirely.** If the browser composes the request, the browser
   composes `context` and `message`. Learner data would reach Gemini/Groq/Cerebras **without passing
   through Laravel at all** — so the governance gate I built guards a path that is not the only
   path. A gate on one of two doors is not a gate.
2. **A shared token cannot be the answer.** A token shipped to the browser is public. So the auth
   fix is necessarily incomplete: either Laravel proxies all assistant traffic (making the token
   viable), or the service needs real per-user authentication, or it stays open and we accept that
   quota is spendable by anyone. That is a decision, and it is why I made the token optional rather
   than required — requiring it would have broken the deployed frontend on deploy.
3. **CORS is not access control.** It constrains browsers only. Any `curl` can call `/chat` today
   regardless of that list. Combined with (2), the endpoint is effectively public.

**Recommendation:** decide whether the assistant is Laravel-mediated (then remove the Vercel origin
from `CORS_ALLOWED_ORIGINS`, set `SERVICE_TOKEN`, and route all traffic through Laravel — which is
also what US-REC-01's audit trail and §12.5 gate assume) or browser-direct (then Laravel's audit
table records only what it is told, and the gate needs rethinking). **The current state is both at
once, and that is the actual problem.**

---

## 9. RAG is blocked on a missing corpus

"Full RAG in the service" needs three things: a corpus, a retrieval pipeline, and wiring into the
prompt. I can build the last two. **The first does not exist.**

I searched the whole tree for candidate content:

```
find /e/SkillSpan -iname "*.md" -o -iname "*.pdf" -o -iname "*.txt"   (excluding vendor/.venv/node_modules)
```

Everything found is developer-facing: READMEs, `docs/api/skill_gap_api.md`, and session notes. There
is **no SkillSpan user documentation** — no Help Center content, no feature guide, no platform
manual. `data-science-service/docs/` contains a single API doc.

This matters more than it looks, because the system prompt is unambiguous:

> *"Base your answer solely on the official SkillSpan documentation provided as context."*
> *"If the context does not contain the answer, respond exactly: 'I don't have enough information…'"*

So the bot's entire grounding rests on a corpus that has not been written. I will **not** invent one:
a bot confidently answering from fabricated documentation is strictly worse than one that says it
does not know, and it is the exact failure the grounding rules exist to prevent.

What that leaves:

- **(a) The corpus** — content ownership. Someone must write and approve the SkillSpan documentation.
  I can propose a structure and a chunking/ingestion pipeline, but not the facts.
- **(b) The pipeline** — chunking, embeddings (Gemini `text-embedding-*` is already available via the
  existing `GEMINI_API_KEY`, so no new vendor), a vector index, and top-k retrieval injected as
  `context`. For a corpus of this size an in-process index is sufficient; a hosted vector database
  would be unnecessary cost.
- **(c) The seam** — `build_user_prompt` already delimiters and labels retrieved context as reference
  material, so retrieval plugs in without touching the injection guard.

Note also that (b) interacts with §8: if RAG runs **in the service**, the service needs its own
retrieval on every call, and `context` becomes a redundant second channel. If it runs **in Laravel**,
the service stays stateless exactly as designed and `context` is the only channel. The second is
closer to the current architecture — but it is a decision, not a default.



## 10. RAG built — and what "built" does and does not mean

I implemented (b) the pipeline and (c) the seam from §9. **(a) the corpus is still missing, and it is
still the blocker.** So the honest status is: *retrieval is complete and inert*. The code is written
and tested; it grounds nothing until someone writes the documentation.

### What was added to `E:/SkillSpan/chatbot`

| File | Responsibility |
|---|---|
| `rag/chunking.py` | Paragraph packing → sentence splitting → hard-split fallback, with overlap. Pure: no network, no model |
| `rag/embeddings.py` | `Embedder` Protocol + `GeminiEmbedder`; `RETRIEVAL_DOCUMENT` vs `RETRIEVAL_QUERY` kept separate |
| `rag/index.py` | `VectorIndex`: build / save / load / cosine search. JSON, in-process |
| `rag/retrieval.py` | The request-path `Retriever` and the failure policy |
| `rag/ingest.py` | CLI: `--stats` (free), `--check` (verify the model), default (build + save) |
| `CORPUS.md` | What belongs in the corpus, who writes it, what must never go in it |

Wiring: `main.py` retrieves only when the caller supplied no `context`; `models.py` gained a
`retrieval` field on `/health`; `config.py` gained `rag_enabled` / `corpus_dir` / `index_path` /
`embedding_model` / `top_k` and a `retrieval_configured` property.

### The decisions, and why

**In the service, not in Laravel.** The user chose "RAG كامل بالخدمة". This does contradict §9's
observation that Laravel-side retrieval keeps the service stateless — but the service was already
receiving no learner data, and the corpus is *public platform documentation*, not learner data. So
retrieval in the service does not widen the §12.5 surface with respect to *learners*. It does widen
it with respect to *the corpus* — see below.

**Fail soft, always.** Retrieval is an enhancement, never a dependency. A missing index, a stale
index, an index built by another embedding model, or an embedding-provider outage all return no
context, and the turn still succeeds. A retrieval outage must never turn a working chat into a 500 —
that is the single most important property in `tests/test_retrieval.py`.

**A caller-supplied `context` wins.** Retrieval only fills the gap, which is what keeps the change
additive: an existing caller sending its own context sees byte-identical behaviour.

**An index built by a different model is refused at load time.** Mismatched vectors do not raise —
they return plausible nonsense, which is the worst possible failure mode for a system whose whole job
is grounding. `index.json` records its model; `/health` reports `unavailable` rather than quietly
retrieving garbage.

**Off by default.** `RAG_ENABLED` unset ⇒ `/health` says `retrieval: "off"` and `/chat` behaves
exactly as it did before retrieval existed. A deployment cannot accidentally ground in a half-built
corpus.

### ⚠️ RAG creates a new external-data flow, so §12.5 applies to the corpus

Before retrieval, the service sent Gemini only the user's typed message. After retrieval it also
sends **whatever is in the corpus**, on every question, to a third-party model. That is a new
insertion of data into an external AI service, and §12.5 governs it.

The corpus is therefore constrained the same way learner data is: no secrets, no credentials, no
learner data, no internal-only material. `CORPUS.md` states this, and `CORPUS.md` is deliberately
placed **outside** `corpus/` — anything inside `corpus/` is ingested and becomes grounding text,
including a guidelines file. That is a trap worth avoiding explicitly.

### Deployment gap

`index.json` is gitignored (a derived artifact; committing it lets a stale index outlive the docs it
describes). A fresh Render instance therefore has no index, so `RAG_ENABLED=true` alone yields
`retrieval: "unavailable"`. The build command must run `python -m rag.ingest` once the corpus exists.
Documented in `README.md` and flagged in `render.yaml`.

### Still blocked, unchanged

1. **The corpus (§9a).** Content ownership. Nobody can write the Readiness Score weights except the
   people who own the Readiness Score. Until then this code retrieves nothing.
2. **The access architecture (§8).** Whether the browser keeps calling the service directly. This
   still determines whether the §12.5 gate is real, and it is still a decision rather than a change.
3. **Where learner data goes (§12.5, §6).** Still unanswered, so `AssistantClient` in Laravel is
   still deliberately unwired.

Verification: **88 tests pass** (`pytest -q`), including the full retrieval pipeline; `python -m
rag.ingest --stats` on a two-document corpus reports chunks and sizes correctly and writes nothing;
on the empty real corpus it exits 2 and reports that nothing was built.

---

## 11. Access architecture decided and enforced (Laravel-mediated only)

The §8 decision was made: **all assistant traffic is Laravel-mediated, with no browser path.**
Implemented across both sides.

**Service (`E:/SkillSpan/chatbot`).** `SERVICE_TOKEN` is now **mandatory with no unauthenticated
mode** — missing or wrong credential → 401, token not configured → **503** (fail closed, not open).
Constant-time comparison via `hmac.compare_digest`; scheme matched case-insensitively per RFC 7235.
`/health` stays open for Render but folds `auth_enabled` into `status`, so a token-less deploy
reports `degraded` instead of `ok`. `CORS_ALLOWED_ORIGINS` emptied in `.env`, `.env.example` and
`render.yaml` — the Vercel origin is gone. The `/chat` contract is unchanged.

**Laravel.** `AssistantClient` was sending `{context_snapshot, question, request_id}` and reading
`algorithm_version`, none of which the service has ever accepted or returned. It therefore threw
before opening a socket — which is why the §12.5 gate had never actually been exercised. It now
sends `{user_id, message, context}` with the bearer token server-side plus `X-Request-ID`. New
`AssistantAnswer` value object carries the reply to the caller **without persisting it** (the
service returned a reply that Laravel silently dropped; not storing it was correct, not returning
it was a bug). `provider_used === null` is now a soft failure — `status=failed` with
`ASSISTANT_PROVIDERS_UNAVAILABLE`, fallback text still relayed. A `prompt_version` column was added,
distinct from `algorithm_version`.

**Verification.** Laravel **299 passed / 1068 assertions** (was 280/1016), chatbot **103 passed**
(was 88), Pint clean on 291 files. Live: `/chat` with no token → 401; with the correct token → 200;
CORS preflight from the Vercel origin → 405 with no `Access-Control-*` headers. The CORS tests were
proved able to fail by re-running with the origin re-added (2 failures).

### Still blocked, revised

1. **The corpus (§9a).** Unchanged — content ownership.
2. ~~The access architecture (§8).~~ **Resolved** — see above.
3. **Where learner data goes (§12.5, §6).** Unchanged.
4. **`E:/SkillSpan/chatbot` is not a git repository.** Every service-side change in this session and
   the last is uncommitted and unversioned. This is now the most fragile thing in the integration.
5. **The frontend has no assistant UI.** Closing the service does not create one; whoever builds it
   must call `POST /api/v1/assistant/ask` and must never receive the service URL or token.
