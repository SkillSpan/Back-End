# Assistant "temporarily unavailable / The assistant is not enabled."

**Reported from:** the frontend AI Assistant chat panel
**Date:** 2026-10-01 (updated after live verification against the deployed services)
> ## 🟢 UPDATE (later the same day) — defect 2 is FIXED, and the failure has moved on
>
> Re-tested against production. **Two of the three original conclusions changed:**
>
> | Original claim | Reality now |
> |---|---|
> | §12.5 gate closed on Render (`ASSISTANT_ENABLED` unset) | ✅ **OPEN** — the user set it; the response is no longer `ASSISTANT_NOT_ENABLED` |
> | Laravel's 4-field payload rejected with `422 extra_forbidden` | ✅ **FIXED by the DS owner.** Laravel's *exact* payload now returns **`200`** with `grounded: true`, `provider_used: "groq"`, `sources: ["laravel_context"]` |
> | Wrong URL / wrong token | ❌ **still true — this is now the ONLY blocker** |
>
> **The current failure is `503 ASSISTANT_UNAVAILABLE`** — *"The assistant service is unavailable or
> timed out."* That is the **connection-failure** branch (`AssistantClient` line 150), *not* the
> HTTP-5xx branch (which says "failed to process the request"). So Laravel never reached the service:
> Render is still using the local default `http://127.0.0.1:8010`, where nothing listens.
>
> **Fix = three env vars on Render, no code change:**
> ```
> ASSISTANT_SERVICE_URL   = https://skillspan-intelligence.onrender.com
> ASSISTANT_SERVICE_PATH  = /api/v1/assistant/chat
> ASSISTANT_SERVICE_TOKEN = <same value as DATA_SCIENCE_SERVICE_TOKEN>
> ASSISTANT_SERVICE_TIMEOUT = 120
> ```
> ⚠️ **Keep `context` and `intent` in the payload.** `sources: ["laravel_context"]` proves the service
> grounds on the context Laravel sends — it does **not** do its own retrieval. §3b's suggestion to
> drop them to silence a 422 is now moot and must not be followed.
>
> ⚠️ **Cold start = 33.2 s.** The Render service sleeps; the first request after idle is very slow.
>
> Sections §3b, §4b and the appendix below are kept as the **historical record** of the contract
> defect — read them as "what it was", not "what it is". Current state:
> `CHATBOT_VS_ASSISTANT_TESTING.md`.

**Verdict (as originally written):** ⚠️ **Two independent defects, and the first one hides the second.**

| | Defect | State |
|---|---|---|
| 1 | §12.5 governance gate is closed on the Render backend (`ASSISTANT_ENABLED` unset) | config — ✅ **resolved** |
| 2 | **Laravel ↔ FastAPI contract mismatch** — Laravel sends 3 fields FastAPI rejects | code — ✅ **resolved on the service side** |

Defect 1 explained the message the user saw. Defect 2 would have moved the failure to HTTP 422 once
the flag was enabled — and it did, until the service side was updated to accept the 4-field payload.

---

## 0. Live verification (what was actually tested, not assumed)

Everything below was verified against the **deployed** services, not from local config:

| Check | Result |
|---|---|
| Production FastAPI reachable | ✅ `https://skillspan-intelligence.onrender.com` → `200`, OpenAPI served |
| Assistant endpoint exists | ✅ `POST /api/v1/assistant/chat` present in the OpenAPI document |
| Assistant endpoint actually answers | ✅ real reply, `provider_used: "groq"`, `prompt_version: "v2"` |
| `DATA_SCIENCE_SERVICE_TOKEN` authenticates it | ✅ `200` |
| `ASSISTANT_SERVICE_TOKEN` authenticates it | ❌ **`401 Invalid or missing service authentication token.`** |
| Laravel's payload accepted by FastAPI | ❌ **`422 extra_forbidden` on `user_id`, `intent`, `context`** — ⚠️ **no longer true; re-tested → `200`** |

**Conclusion:** the AI service is live and healthy. The breakage is entirely on the **Laravel side** —
wrong URL, wrong token, wrong payload shape.

---

## 1. What the user sees, and where each half comes from

The panel shows two distinct strings, produced by two different systems:

```
Assistant temporarily unavailable            ← the frontend's own heading (AssistantView.jsx)
The assistant is not enabled.                ← the BACKEND's message, relayed verbatim
```

Both parts of the round trip:

| Step | File | What happens |
|---|---|---|
| 1 | `src/components/learner/AssistantView.jsx` (line ~880) | catches the API error and renders a `type: "unavailable"` card titled **"Assistant temporarily unavailable"** |
| 2 | `app/Http/Controllers/Api/AssistantController.php` (line 75) | catches `AssistantException` and returns `{code, message, ...}` with the exception's status |
| 3 | `app/Services/Assistant/AssistantClient.php` (line 75) | throws that exception: `'The assistant is not enabled.'`, status **503**, code **`ASSISTANT_NOT_ENABLED`** |
| 4 | `config/services.php` (line 180) | `'enabled' => (bool) env('ASSISTANT_ENABLED', false)` |

So the message is **not** an error page or a crash — it is the backend correctly refusing to send
learner data to an external AI provider without a recorded approval.

> The "Please try the question again in a few seconds." line is also the frontend's own text
> (`AssistantView.jsx`), added when the status is **not** 422. It is **misleading here** — retrying
> will never help, because the gate is a configuration state, not a transient outage. See §4.

---

## 2. The exact gate

`AssistantClient::assertGovernanceApproval()` — fails **closed**, and it checks **two** things:

```php
public function assertGovernanceApproval(): void
{
    if (! config('services.assistant.enabled', false)) {
        Log::warning('Assistant request refused: §12.5 gate closed (not enabled).');

        throw new AssistantException(
            'The assistant is not enabled.',
            503,
            'ASSISTANT_NOT_ENABLED',
        );
    }

    $approval = config('services.assistant.approval_reference');

    if (! is_string($approval) || trim($approval) === '') {
        Log::warning('Assistant request refused: §12.5 gate closed (no recorded approval).');

        throw new AssistantException(
            'The assistant is enabled without a recorded §12.5 governance approval.',
            503,
            'ASSISTANT_APPROVAL_NOT_RECORDED',
        );
    }
}
```

The comment above it states the intent:

> *"SRS v1.1 §12.5 — AI Assistant Boundaries: Sensitive data shall not be inserted into external AI
> services without explicit technical and governance approval. Fails closed. The assistant stays
> disabled unless it is explicitly enabled AND a reference to the recorded approval is present."*

Because the message shown was **`"The assistant is not enabled."`** (not the *approval* variant),
the missing piece is the **first** check — `ASSISTANT_ENABLED`. Concretely, on the deployment:

```
ASSISTANT_ENABLED  is false / unset
```

### ⚠️ Do not be misled by the `(canceled)` requests in the browser Network tab

If the Network panel shows requests marked **`(canceled)`**, **that is not the cause of this
problem** — it is a symptom, and chasing it will waste time. The reason:

1. Laravel refuses the request at `assertGovernanceApproval()` — **before any network I/O**. Look at
   the order inside `AssistantClient::ask()`: the gate is asserted on the **first line**, ahead of
   `resolvedBaseUrl()`, `resolvedServiceToken()`, and the `Http::post()`. **No request is ever sent
   to FastAPI.** So there is nothing for FastAPI to cancel.
2. A `(canceled)` entry in a browser's Network tab is normally a **client-side** cancellation — the
   component unmounted, a `AbortController` fired, or StrictMode double-invoked the effect in dev.
   It says nothing about the server's reason for refusing.
3. The request **did** complete: it returned **HTTP 503** with a full JSON body
   (`{"code":"ASSISTANT_NOT_ENABLED","message":"The assistant is not enabled.",...}`). A genuinely
   canceled request has **no response body at all**. The presence of that body is proof the
   round trip finished normally.

**The decisive evidence is the response body's `code` field — not the Network tab's status column.**
`ASSISTANT_NOT_ENABLED` is unambiguous and points straight at the closed gate.

The one thing the 503 *does* tell you indirectly: because the rejection happens before any HTTP
call, the frontend's request **timed out or was superseded normally** — there is no long-hanging
FastAPI call to explain a cancel.

---

## 3. Why it works locally but not on Render

The local `.env` in this checkout **has the gate open**:

```dotenv
ASSISTANT_ENABLED=true
ASSISTANT_APPROVAL_REFERENCE=LOCAL-DEV-E2E-2026-09-30
ASSISTANT_SERVICE_URL=http://127.0.0.1:8010
ASSISTANT_SERVICE_TOKEN=73f8769...af0eb
```

The committed `.env.example` has it **closed** — which is what a Render service inherits unless the
values are set in the Render dashboard:

```dotenv
ASSISTANT_ENABLED=false
ASSISTANT_APPROVAL_REFERENCE=
```

**So the deployed instance is behaving exactly as designed.** Nobody broke anything.

⚠️ **There is a second, separate problem hiding behind the first one.** Both of these point at the
loopback address:

```dotenv
ASSISTANT_SERVICE_URL=http://127.0.0.1:8010
```

On Render, `127.0.0.1` is **the Laravel container itself**. The FastAPI chatbot service does not run
inside it, so even after `ASSISTANT_ENABLED=true` is set, the next failure would be
`ASSISTANT_UNAVAILABLE` (a connection refused / timeout), not a working assistant. **Both must be
fixed, or the chat will fail one step later with a different message.**

### ✅ The good news: the URL and token already exist — under a different key

The assistant service is **the same deployed FastAPI service** the other Data Science integrations
already talk to. Comparing config:

```dotenv
DATA_SCIENCE_SERVICE_URL=https://skillspan-intelligence.onrender.com   ← correct host
DATA_SCIENCE_SERVICE_TOKEN=kEkpn2MA...7JLzO3kd                         ← authenticates (200)

ASSISTANT_SERVICE_URL=http://127.0.0.1:8010                            ← wrong host
ASSISTANT_SERVICE_TOKEN=73f87692...129af0eb                            ← rejected (401)
```

I verified this empirically against the live service:

| Token sent | Result |
|---|---|
| `DATA_SCIENCE_SERVICE_TOKEN` | ✅ `200` — real answer from `groq` |
| `ASSISTANT_SERVICE_TOKEN` | ❌ `401 Invalid or missing service authentication token.` |

**Note the path too.** FastAPI's real route is `POST /api/v1/assistant/chat`. Laravel's
`ASSISTANT_SERVICE_PATH` defaults to `/chat`. So even with the right host, Laravel would `POST` to
`https://skillspan-intelligence.onrender.com/chat` — which **does not exist** (404).

---

## 3b. 🔴 Defect 2 — the Laravel ↔ FastAPI contract does not match

**This is a code defect, independent of the config. It must be fixed before the chat can work.**

### What Laravel sends (`AssistantClient`, `ask()`, line 132)

```php
$payload = [
    'user_id' => $userId,
    'message' => $message,
    'intent'  => $intent,
    'context' => $this->serialiseContext($contextSnapshot),
];
```

### What FastAPI accepts (`AssistantChatRequest` in the deployed OpenAPI)

```json
{
  "properties": {
    "message": { "type": "string", "minLength": 1, "maxLength": 2000 },
    "locale":  { "anyOf": [{ "enum": ["ar", "en"] }, { "type": "null" }] }
  },
  "required": ["message"],
  "additionalProperties": false          ← ← ← THE PROBLEM
}
```

`additionalProperties: false` means Pydantic **rejects the request outright** on any unknown key.

### Reproduced live

```
POST /api/v1/assistant/chat
{ "user_id": "42", "message": "hello", "intent": "explain_readiness", "context": "{\"x\":1}" }
```

```json
HTTP 422
{
  "detail": [
    {"type":"extra_forbidden","loc":["body","user_id"],"msg":"Extra inputs are not permitted"},
    {"type":"extra_forbidden","loc":["body","intent"], "msg":"Extra inputs are not permitted"},
    {"type":"extra_forbidden","loc":["body","context"],"msg":"Extra inputs are not permitted"}
  ]
}
```

**Three of Laravel's four fields are rejected.** The same request with only `message` returns
`200`.

### Consequence for Laravel's error handling

A 422 from FastAPI is mapped by `AssistantClient::parseResponse()` to
`ASSISTANT_VALIDATION_ERROR` (422). So once the gate is open, the learner's reward for asking a
question is *"The assistant service rejected the request."* — a confusing message that looks like
**the learner's** fault when it is in fact a permanent integration bug.

### Two ways to fix it — a decision is needed

**Option 1 — change Laravel to match FastAPI (smaller, recommended).**
FastAPI is already published and working, and the same service backs `skill-gap`, `baseline` and
`roadmap`, so its contract is presumably the one other clients rely on.

```php
// AssistantClient::ask()
$payload = [
    'message' => $message,
    'locale'  => $locale,        // 'ar' | 'en' — take from the learner's profile
];
```

But this **loses** `intent` and `context`, which raises real questions that must be answered before
anyone edits code:

- **`context`** is the whole point of §12.5 — it is the *approved learner snapshot* that makes the
  answer grounded. Dropping it means the assistant answers from general knowledge only. Yet the
  live `200` response above returned `"grounded": true` with `sources`, so FastAPI **already does
  its own retrieval** (RAG over its own corpus) rather than relying on a Laravel-supplied snapshot.
  → **Confirm with the Data Science owner which model is intended.** If retrieval is server-side,
  dropping `context` is correct; if not, FastAPI must be taught to accept it.
- **`intent`** currently gates which of six prompt templates is used. FastAPI's schema has no
  equivalent, so either it infers intent from the message, or the feature is simply not
  implemented on that side yet.

⚠️ **Do not silently drop `intent` and `context` just to make the 422 go away.** Both carry
semantics the SRS relies on. Flag this to whoever owns the FastAPI service.

**Option 2 — change FastAPI to accept Laravel's payload.**
Add `user_id`, `intent`, `context` to `AssistantChatRequest` (and set
`additionalProperties: true`, or accept the fields explicitly). Correct if the design intent really
is "Laravel supplies the approved snapshot and the intent", but it is a change to a **deployed**
service and needs the Data Science owner.

**Whichever is chosen, lock it with a contract test** so the two sides cannot drift again — see
§4b.

---

## 4. How to fix it — two different goals, pick one

### Goal A — make the assistant actually work on the deployment (the real fix)

**All of the following are required. Setting only the first one moves the failure one step further
along, it does not fix it.** The failures are layered, and **each stage has its own distinct error
code** — so the code you see tells you exactly how far the config got.

| # | Variable | Required value | If wrong you get |
|---|---|---|---|
| 1 | `ASSISTANT_ENABLED` | `true` | **`ASSISTANT_NOT_ENABLED`** ← *the reported symptom* |
| 2 | `ASSISTANT_APPROVAL_REFERENCE` | an **existing, approved** reference (ticket id, email thread, governance record) — non-empty | `ASSISTANT_APPROVAL_NOT_RECORDED` |
| 3 | `ASSISTANT_SERVICE_URL` | **`https://skillspan-intelligence.onrender.com`** — already known, see §3 | `ASSISTANT_UNAVAILABLE` (connection refused / timeout) |
| 3b | `ASSISTANT_SERVICE_PATH` | **`/api/v1/assistant/chat`** — the default `/chat` is wrong | `404` → `ASSISTANT_INVALID_RESPONSE` (502) |
| 4 | `ASSISTANT_SERVICE_TOKEN` | **must equal `DATA_SCIENCE_SERVICE_TOKEN`** — same service, verified | `ASSISTANT_NOT_CONFIGURED` (503, not 401 — by design) |
| 5 | *(code, not env)* contract shape | see §3b — **this is a code change, not a setting** | `ASSISTANT_VALIDATION_ERROR` (422) |

The ordering matters and is deliberate — Laravel asserts them in this sequence, so the **first**
blocker is the one you see. Right now the reported code is `ASSISTANT_NOT_ENABLED`, which means #1
is the current blocker; #2–#4 are almost certainly also unset and will surface one at a time as you
fix them.

> 💡 Since `DATA_SCIENCE_SERVICE_URL` and `DATA_SCIENCE_SERVICE_TOKEN` are **already** correct on
> Render (the other DS integrations work), the fastest safe fix is to make the assistant read the
> same values — either by setting `ASSISTANT_SERVICE_URL`/`ASSISTANT_SERVICE_TOKEN` to the same
> values, or by pointing the assistant config at the `data_science` block so they can never diverge
> again. **Prefer the second**: two config keys that must hold the same secret will eventually drift.

Then:

1. **No new deployment is needed** — the FastAPI service is already live at
   `https://skillspan-intelligence.onrender.com` and already answers (verified, §0). The earlier
   assumption that a chatbot service had to be deployed separately was wrong: the assistant lives
   inside the **existing Intelligence service**, at `POST /api/v1/assistant/chat`. (`GET /health`
   confirms it.)
2. Set `ASSISTANT_SERVICE_URL=https://skillspan-intelligence.onrender.com` (**not** loopback).
3. Set `ASSISTANT_SERVICE_PATH=/api/v1/assistant/chat` (**not** the `/chat` default).
4. Set `ASSISTANT_ENABLED=true`.
5. Set `ASSISTANT_APPROVAL_REFERENCE` to the **real recorded approval** (ticket id / email thread /
   governance record). Do **not** copy the local dev placeholder (`LOCAL-DEV-E2E-2026-09-30`) — the
   whole point of the field is that it names an actual approval for sending learner data to a
   third-party AI provider.
6. Set `ASSISTANT_SERVICE_TOKEN` to the **same value as `DATA_SCIENCE_SERVICE_TOKEN`** — verified
   working against this exact endpoint. The current local value returns `401`.
7. **Fix the payload shape (§3b).** This is a code change and no setting can work around it.

Then re-deploy (config is cached — see §6).

### 4b. Lock the contract so it cannot drift again

Whatever shape is agreed in §3b, add a test that fails when the two sides disagree. Without one,
this exact bug will come back the next time either side is edited.

The strongest form needs no network: fetch the deployed OpenAPI document once, and assert Laravel's
outbound payload only uses keys the schema allows.

```php
// tests/Feature/Assistant/AssistantContractTest.php (sketch)
public function test_the_laravel_payload_matches_the_deployed_fastapi_schema(): void
{
    $schema = json_decode(
        file_get_contents(base_path('tests/Fixtures/assistant_chat_schema.json')), true
    );

    $allowed = array_keys($schema['properties']);
    $payload = (new AssistantPayloadBuilder)->build(/* … */);

    foreach (array_keys($payload) as $key) {
        $this->assertContains(
            $key, $allowed,
            "Laravel sends `{$key}` but FastAPI's AssistantChatRequest rejects it "
            .'(additionalProperties: false) — the integration will 422.'
        );
    }
}
```

Also add the reverse guard — `additionalProperties: false` must stay in the fixture, so a future
FastAPI change that *loosens* the schema is noticed rather than silently relied upon.

Store the schema as a checked-in fixture (as above) rather than fetching it at test time, so the
suite stays offline and deterministic; refresh the fixture deliberately when the contract changes.

### Goal B — stop the chat from promising something it cannot deliver (the UX fix)

While the gate is closed, the frontend should **not** render a generic "try again in a few seconds".
The backend already sends the reason in a machine-readable field — the frontend is just not reading
it. `AssistantController` returns:

```json
{
  "code": "ASSISTANT_NOT_ENABLED",
  "message": "The assistant is not enabled.",
  "request_id": "..."
}
```

**Frontend change** — branch on `error?.code` in the `catch` block of `AssistantView.jsx`:

| `error.code` | Meaning | What the panel should say |
|---|---|---|
| `ASSISTANT_NOT_ENABLED` | governance gate closed | "The AI Assistant is not enabled yet." + **do not** say "try again" |
| `ASSISTANT_APPROVAL_NOT_RECORDED` | enabled but no approval on file | same, plus "pending governance approval" |
| `ASSISTANT_NOT_CONFIGURED` | URL/token missing or rejected | "The assistant is misconfigured." — a deployment issue, not the learner's |
| `ASSISTANT_UNAVAILABLE` | service down / timed out | **this** is the only case where "try again in a few seconds" is honest |
| `ASSISTANT_PROVIDERS_UNAVAILABLE` | soft failure, HTTP 200 + `provider_used: null` | a *successful* response with fallback text — see §5 |

Retry text must be reserved for `ASSISTANT_UNAVAILABLE`. Right now **every** non-422 error gets
"please try again", which sends users into a pointless retry loop.

---

## 5. ⚠️ The soft-failure trap (worth knowing before debugging further)

`AssistantClient`'s docblock warns about this explicitly:

> *"`provider_used === null` is a SOFT failure, not a success. The service returns HTTP 200 with a
> fallback message when every provider in its failover chain fails, so the status code cannot
> distinguish 'answered' from 'nobody answered'."*

So a reply of *"I'm temporarily unavailable. Please try again shortly."* arriving with **HTTP 200**
is a real, handled outcome — not an error. `AssistantService` records it as `STATUS_FAILED` /
`ASSISTANT_PROVIDERS_UNAVAILABLE` while still relaying the text to the learner, on the reasoning
that *"the learner reading 'temporarily unavailable' is better than an error state."*

**Do not confuse this with the message the user reported.** The reported message came with **503**,
not 200 — they are two unrelated failures that happen to use similar wording.

---

## 6. Debugging checklist (in order)

1. **Which code came back?** Check the network response body for `code`. Everything else follows from it.
2. **Is the gate the cause?** `ASSISTANT_ENABLED` must be truthy **and** `ASSISTANT_APPROVAL_REFERENCE` non-empty.
3. **Are the values actually live?** The deploy CMD caches config (`config:cache`). An env change on
   Render requires a **re-deploy**, not just a restart, or the old cached config is served.
4. **Is the URL reachable from Laravel?** `ASSISTANT_SERVICE_URL` must not be `127.0.0.1` in a
   container. `curl https://skillspan-intelligence.onrender.com/health` from the Laravel container.
5. **Is the path right?** Must be `/api/v1/assistant/chat` — a bare `/chat` returns `404`.
6. **Do the tokens match?** `ASSISTANT_SERVICE_TOKEN` must equal `DATA_SCIENCE_SERVICE_TOKEN` (same
   service). A rejected credential is reported as 503 `ASSISTANT_NOT_CONFIGURED`, never as a 401 —
   by design. Test it directly:
   ```bash
   curl -s -X POST https://skillspan-intelligence.onrender.com/api/v1/assistant/chat \
     -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -d '{"message":"hello"}' -w '\n%{http_code}\n'
   ```
   `200` = token good. `401` = token wrong.
7. **Does the payload shape match?** Send Laravel's exact four fields and look for
   `extra_forbidden` (see §3b).
8. **Are the AI providers up?** If the chatbot's own providers all fail, you get HTTP 200 +
   `provider_used: null` (see §5).

Check the backend log for the exact gate that fired — the two paths log distinguishable warnings:

```
Assistant request refused: §12.5 gate closed (not enabled).
Assistant request refused: §12.5 gate closed (no recorded approval).
```

> ⚠️ **Note the Render free tier.** The same `config/services.php` file documents that this service
> idles out after ~15 minutes with a 24.7–33.8 s cold start (see the `data_science.timeout`
> comment). A slow first call is expected and is not an outage — it is also why the assistant
> timeout defaults to 60 s rather than something tighter.

---

## 7. Bottom line

- **The frontend chat panel is not broken.** It is correctly reporting a backend refusal. Its call
  to `POST /api/v1/assistant/ask` with `intent` + `question` is exactly right per
  `AskAssistantRequest`. The string *"The assistant is not enabled."* is **not** hardcoded in the
  frontend — it is Laravel's response, relayed verbatim.
- **The Laravel endpoint is not broken either.** It is faithfully enforcing §12.5.
- **`(canceled)` entries in the browser Network tab are a red herring** — not the cause, and not
  even evidence of the cause. Laravel refuses **before any network I/O**, so nothing was ever sent
  to FastAPI. The decisive evidence is the response body's `code` field.
- **The AI service itself is healthy and already deployed.** `https://skillspan-intelligence.onrender.com`
  answers `POST /api/v1/assistant/chat` with a real reply (`provider_used: "groq"`). No new
  deployment is needed.
- **There are two defects, and the first hides the second:**
  1. the §12.5 gate is closed (`ASSISTANT_ENABLED` unset) → the message the user saw;
  2. **Laravel sends `user_id` / `intent` / `context`, FastAPI rejects all three**
     (`additionalProperties: false`) → `422` once the gate is open.
- **So flipping the flag is not enough.** Config alone gets you to a `422`; the payload shape in
  `AssistantClient::ask()` must change too, and **which fields to keep is a design decision** that
  needs the Data Science owner (see §3b) — `context` is central to §12.5, so it must not be dropped
  silently.
- **The URL and token already exist under the `DATA_SCIENCE_*` keys** and are known-good. The
  assistant's own keys hold a loopback URL and a token the service rejects (`401`).
- **The frontend should stop saying "try again in a few seconds"** for a permanent
  configuration state — that is the one genuinely actionable frontend change here.

---

## Appendix — the exact code path

```
POST /api/v1/assistant/ask
  → AssistantController@ask
      → AssistantService::ask()
          → AssistantClient::ask()
              → assertGovernanceApproval()        ← fails here: ASSISTANT_ENABLED is false
                  Log::warning('…§12.5 gate closed (not enabled).')
                  throw AssistantException('The assistant is not enabled.', 503, 'ASSISTANT_NOT_ENABLED')
      ← caught in AssistantController (line 75)
  ← { "code": "ASSISTANT_NOT_ENABLED", "message": "The assistant is not enabled.", "request_id": "…" }  HTTP 503

AssistantView.jsx catch (error)
  → error.status !== 422
  → title:       "Assistant temporarily unavailable"
  → explanation: error.message  →  "The assistant is not enabled."
  → canHelp:     "Please try the question again in a few seconds."
```

### After the gate is opened — the SECOND failure, waiting behind it

```
AssistantClient::ask()
  → assertGovernanceApproval()          ✅ passes (once ASSISTANT_ENABLED + APPROVAL_REFERENCE are set)
  → resolvedBaseUrl()                   ⚠️ currently http://127.0.0.1:8010      → unreachable from Render
  → resolvedServiceToken()              ⚠️ currently the 73f87692… token         → service returns 401
  → Http::post($baseUrl.'/chat', [      ⚠️ path should be /api/v1/assistant/chat
        'user_id' => …,                 ❌ extra_forbidden
        'message' => …,                 ✅ accepted
        'intent'  => …,                 ❌ extra_forbidden
        'context' => …,                 ❌ extra_forbidden
    ])
  ← FastAPI: HTTP 422 { "detail": [ …extra_forbidden × 3… ] }
  → parseResponse(): status === 422
      throw AssistantException('The assistant service rejected the request.', 422, 'ASSISTANT_VALIDATION_ERROR')
  ← learner sees a message implying their question was malformed — it was not
```
