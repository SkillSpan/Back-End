# Assistant Chat — Frontend Wiring Handoff

**Status:** the backend is complete, tested, and **verified working end-to-end against the live
chatbot service** (see §7). The governance gate is now open on this checkout.

**One thing remains, and it is not the backend:** the chat UI is still a static demo stub (§1).
It never issues a network request, so it will keep showing the same hardcoded sentence no matter
what the API returns.

---

## 0. ⚠️ The governance gate — now open on this checkout

`config/services.php` gates the whole feature, and it **fails closed**:

```php
'assistant' => [
    'enabled' => (bool) env('ASSISTANT_ENABLED', false),
    'approval_reference' => env('ASSISTANT_APPROVAL_REFERENCE'),
    'url' => env('ASSISTANT_SERVICE_URL', 'http://127.0.0.1:8010'),
    'path' => env('ASSISTANT_SERVICE_PATH', '/chat'),
    'service_token' => env('ASSISTANT_SERVICE_TOKEN'),
],
```

The gate:

| Env var | Required value | Why |
|---|---|---|
| `ASSISTANT_ENABLED` | `true` | master switch |
| `ASSISTANT_APPROVAL_REFERENCE` | a **non-empty** string | a ticket id / governance record proving the §12.5 approval was granted |

A bare `enabled=true` with no approval reference still refuses, with
`503 ASSISTANT_APPROVAL_NOT_RECORDED` (REC-06 / BR-REC-07). A blank/whitespace reference counts
as missing. This is deliberate: learner context may not leave the platform without a recorded
approval.

**On this checkout both are now set**, so the API answers rather than returning
`503 ASSISTANT_NOT_ENABLED`. ⚠️ **Every deployment has its own `.env`** — a different environment
that has not had this set will still refuse. That is the gate working, not a regression.

With the gate closed the API returns:

| Status | `code` |
|---|---|
| 503 | `ASSISTANT_NOT_ENABLED` |
| 503 | `ASSISTANT_APPROVAL_NOT_RECORDED` |
| 503 | `ASSISTANT_NOT_CONFIGURED` (gate open, no service token) |

Then the FastAPI chatbot service must actually be reachable at `ASSISTANT_SERVICE_URL` +
`ASSISTANT_SERVICE_PATH` (`http://127.0.0.1:8010` `/chat` by default) and must share
`ASSISTANT_SERVICE_TOKEN` as its own `SERVICE_TOKEN`.

⚠️ **Laravel is the only permitted caller.** The assistant service requires a bearer token and
allows no browser origins, so the frontend must never call it directly — it goes through
`POST /api/v1/assistant/ask` only.

---

## 1. What is actually happening in the UI

The chat replies with the same sentence no matter what is asked:

> "I have received your question. In a live integration, I would respond with your verified
> SkillSpan context here. Use the scenario preview buttons below to explore each response type."

That string is **hardcoded in the frontend**. It is not a backend response, and no network
request is made.

**Where it lives** (two components, both with the same defect):

| File | Line | Notes |
|---|---|---|
| `src/components/AssistantPanel.tsx` | 667 | `sendMessage()` |
| `src/components/AssistantView.tsx` | 624 | same pattern |

The offending function in `AssistantPanel.tsx`:

```tsx
function sendMessage(text: string) {
  if (!text.trim()) return;
  const userMsg: Message = { /* … */ };
  setMessages(prev => [...prev, userMsg]);
  setInput("");
  setShowSuggested(false);
  setIsTyping(true);
  setTimeout(() => {                      // ← a timer, not a request
    setIsTyping(false);
    const reply: Message = {
      id: `a-${Date.now()}`, role: "assistant", type: "text",
      text: "I have received your message. To see structured responses, use the scenario " +
            "preview buttons below. In a live integration, I would respond with your " +
            "verified career context here.",   // ← hardcoded
    };
    setMessages(prev => [...prev, reply]);
  }, 1600);
}
```

There is **no `fetch`/`axios` call anywhere in this path**. The 1.6s delay is a fake "typing"
animation, which is why it feels like it is thinking but always says the same thing.

The "scenario preview buttons" and `SCENARIOS` / `DemoScenario` state are demo scaffolding and
have no backend counterpart.

---

## 2. The real endpoint

```
POST /api/v1/assistant/ask
```

**Auth:** `auth:sanctum` + `account.active` + `role:learner`
→ a **Bearer token** of a signed-in **learner**. An admin token will be refused (`role:learner`).

**Precondition:** the learner must have a `student_profile`. Without one the API returns
`422 STUDENT_PROFILE_NOT_FOUND` — this is expected, not a bug.

### Request body

```json
{
  "intent": "explain_readiness",
  "question": "whats the skillspan platform",
  "recommendation_id": null,
  "project_id": null
}
```

| Field | Rules |
|---|---|
| `intent` | **required**, must be one of the six below |
| `question` | **required**, string, 3–2000 chars |
| `recommendation_id` | optional, integer > 0 |
| `project_id` | optional, integer > 0 |

**`intent` is a closed whitelist** — this is the assistant's scope gate, and an unlisted value is
refused *before* any model is contacted:

```
explain_readiness
explain_skill_gap
explain_roadmap
explain_next_best_action
explain_project_recommendation
project_bounded_help
```

⚠️ **The UI must map a free-text question onto one of these six.** There is no "chat freely"
intent. "whats the skillspan platform" has no home in this vocabulary and will be rejected with
`422 ASSISTANT_INTENT_NOT_ALLOWED` — which is a **scope decision by design**, not a failure.

Practical approach: let the UI's existing buttons/suggestions choose the intent, and send the
user's typed text as `question`. If a free-text box is kept, pick a default intent (e.g.
`explain_readiness`) or map keywords → intent client-side.

### Response — `201`

```json
{
  "data": {
    "id": 42,
    "intent": "explain_readiness",
    "context_reference": "…",
    "related_recommendation_id": null,
    "related_project_id": null,
    "response_status": "succeeded",
    "report_status": null,
    "report_reason": null,
    "reported_at": null,
    "algorithm_version": "…",
    "prompt_version": "…",
    "configuration_version": "…",
    "failure_code": null,
    "request_id": "…",
    "created_at": "2026-09-30T19:00:00+00:00",
    "reply": "…",              // ← render THIS
    "provider_used": "…"
  }
}
```

- **`data.reply` is the text to render.** `provider_used` says which model produced it.
- **`201`, not `200`** — an interaction row was created. Do not treat 201 as an error.
- ⚠️ **`reply` present while `response_status` is `"failed"` is a coherent pair, not a bug** —
  it means the service answered with a fallback. **Branch on `response_status` for any
  monitoring/analytics, but always render `reply` if it is present.**
- `reply` is **absent on `/report`** responses (there is no reply to relay).

### ⚠️ What to expect from the reply text right now

Verified against the live service: for a learner whose stored decision data is empty, the reply is
**"I don't have enough information in the SkillSpan documentation to answer that…"**

This is **correct, not a bug** — but it is worth understanding before you file it:

Laravel always sends a non-null `context` (the learner's stored snapshot). The service treats any
non-null `context` as *"use this verbatim and skip retrieval."* So for a Laravel-originated call the
service's own documentation corpus is **never consulted**. When the snapshot says every section is
`available: false` (no readiness calculated, no roadmap, no skill gaps), the model is grounded in
exactly one fact — that nothing is available — and it correctly declines rather than inventing an
explanation.

Proven both ways against `POST /chat` on 8010, same question:

| `context` | Result |
|---|---|
| supplied (what Laravel sends) | *"I don't have enough information…"* |
| omitted | a full, correct explanation of the Readiness Score |

**Practical consequence for the UI: an empty-state wording is misleading.** A learner with no
readiness result yet will see a refusal that reads like a fault. Consider gating the chat behind
"you have at least one calculated readiness result", or mapping this specific reply to an
onboarding message ("calculate your readiness first to ask about it").

**This is an intentional trade-off, not an oversight.** Laravel's stored decision data is
authoritative, and REC-07 forbids writing a second, unreviewed paraphrase of the learner's record to
feed the model. Sending `context: null` would switch retrieval on but ground answers in generic
documentation rather than the learner's actual data — the opposite of the feature's purpose.
Pinned by `test_the_context_is_always_present_so_service_retrieval_never_runs`.

### Errors

| Status | `code` | Meaning |
|---|---|---|
| 422 | `ASSISTANT_INTENT_NOT_ALLOWED` | intent not in the whitelist (scope refusal) |
| 422 | `VALIDATION_ERROR` | malformed payload (e.g. `question` too short) |
| 422 | `STUDENT_PROFILE_NOT_FOUND` | learner has no student profile |
| 401 | `{message}` | no/invalid token |
| 403 | — | not a learner |
| 503 | `ASSISTANT_NOT_ENABLED` | the §0 gate is closed |
| 503 | `ASSISTANT_APPROVAL_NOT_RECORDED` | enabled but no approval reference |
| 503 | `ASSISTANT_NOT_CONFIGURED` | gate open but no service token is configured |
| 500 | `ASSISTANT_FAILED` | upstream failure |

Error envelope is `{ code, message, request_id, errors? }`.

### Reporting a reply (the UI's report button)

```
PUT /api/v1/assistant/interactions/{id}/report
```

Body: `{ "report_status": "unsafe" }` — one of `unsafe`, `irrelevant`, `unfair`, `incorrect`.
Reports are per-learner; reporting twice overwrites. Response is the interaction **without**
`reply`.

---

## 3. Minimal change to wire it up

Replace the `setTimeout` block in `sendMessage()`:

```tsx
async function sendMessage(text: string) {
  if (!text.trim()) return;

  const userMsg: Message = { /* …unchanged… */ };
  setMessages(prev => [...prev, userMsg]);
  setInput("");
  setShowSuggested(false);
  setIsTyping(true);

  try {
    const res = await fetch(`${import.meta.env.VITE_API_BASE_URL}/api/v1/assistant/ask`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "Authorization": `Bearer ${token}`,   // the learner's Sanctum token
      },
      body: JSON.stringify({
        intent: intentFor(text),              // map onto the six-value whitelist
        question: text.trim(),
      }),
    });

    const body = await res.json();

    if (!res.ok) {
      // 422 ASSISTANT_INTENT_NOT_ALLOWED is a scope refusal, not a crash.
      // 503 ASSISTANT_NOT_ENABLED means the §0 gate is still closed.
      throw Object.assign(new Error(body.message || "Assistant unavailable"), body);
    }

    const reply: Message = {
      id: `a-${Date.now()}`, role: "assistant", type: "text",
      text: body.data.reply ?? "The assistant did not return a reply.",
      timestamp: new Date().toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" }),
    };
    setMessages(prev => [...prev, reply]);
  } catch (e) {
    // surface e.message to the user
  } finally {
    setIsTyping(false);
  }
}
```

`intentFor()` is the only genuinely new logic required — a small keyword→intent map, or drive the
intent from whichever button/suggestion the user clicked.

---

## 4. What NOT to change

- **The backend is done.** 61 assistant tests pass; the endpoint, its validation, its scope
  gate, its governance gate and its reporting flow are all working. Nothing server-side needs
  touching.
- **Do not add a "general chat" intent** to make free text work. The six-value whitelist is the
  assistant's scope contract (SRS v1.1 §12.5) and is deliberately closed — a reply about
  anything outside the learner's own stored readiness / skill-gap / roadmap / recommendations is
  out of scope by design. Widening it is a product decision, not a wiring fix.
- **Do not call the FastAPI chatbot service from the browser.** It rejects browser origins and
  requires a service token; going around Laravel defeats the governance gate.

---

## 5. Reproducing / verifying

First satisfy §0 (`ASSISTANT_ENABLED=true`, a non-empty `ASSISTANT_APPROVAL_REFERENCE`, and a
running chatbot service).

```
POST /api/v1/assistant/ask
Authorization: Bearer <learner token>
Content-Type: application/json

{ "intent": "explain_readiness", "question": "How ready am I?" }
```

Expected: `201` with `data.reply` populated.

With an intent outside the whitelist
(`{"intent":"chat","question":"whats the skillspan platform"}`) expect
`422 ASSISTANT_INTENT_NOT_ALLOWED` — that is the gate working correctly, and it explains why a
generic question cannot be answered by this endpoint as-is.

### Test coverage already proving this

```
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test --filter=Assistant
# 62 passed (174 assertions)
```

Relevant cases in `tests/Feature/Assistant/AssistantAskTest.php`:
`test_the_assistant_is_off_by_default`, `test_enabling_without_a_recorded_approval_still_fails_closed`,
`test_a_blank_approval_reference_is_treated_as_missing`, `test_an_intent_outside_the_permitted_scope_is_refused`,
`test_a_permitted_intent_is_orchestrated_end_to_end`, `test_the_reply_is_relayed_to_the_caller`.

In `tests/Feature/Assistant/AssistantClientTest.php`:
`test_it_sends_the_documented_contract_payload` (asserts the exact outgoing key set),
`test_it_sends_the_intent_the_caller_validated_verbatim`.

---

## 7. Backend status — verified live end-to-end

The backend half of this path has now been run against the real chatbot service, not a mock.
Result: **working**. `POST /chat` answered via Groq in ~3.1s and the interaction was recorded
`SUCCEEDED` with `provider_used=groq`, `prompt_version=v1`.

### The bug this uncovered

`AssistantService::ask()` passed the question to the transport as `$input['question']`, while the
form request validates `question` (1) and the client sends `message` (2). Those two disagreed, so
the service **never set `intent` at all** and `AssistantClient` sent only
`{user_id, message, context}` — three keys where the service declares four.

Both sides are now fixed:

| File | Change |
|---|---|
| `app/Services/Assistant/AssistantService.php` | passes the validated `$intent` into `client->ask(...)` |
| `app/Services/Assistant/AssistantClient.php` | `ask()` takes `string $intent`; payload is `{user_id, message, intent, context}` |
| `chatbot/models.py` | `ChatRequest.intent: str \| None` — optional, blank normalised to `None`, unknown values accepted |
| `chatbot/main.py` | intent recorded in the request log line |

`intent` is deliberately **optional and advisory** on the service side: Laravel has already
enforced the six-value whitelist, and accepting unknown values means the gateway can add an intent
without the service shipping a matching release first.

### Checkout notes (this machine)

* Laravel expects `ASSISTANT_SERVICE_URL=http://127.0.0.1:8010`. The chatbot's own README and
  `main.py:12` agree on port **8010** — do not run it on 5000, where Laravel will not find it.
* `.env` had `ASSISTANT_ENABLED=false` with a blank approval reference (the §12.5 gate failing
  closed). Both are now set. `config/services.php` already defaults the URL and path correctly.
* `ASSISTANT_SERVICE_TOKEN` matches the service's `SERVICE_TOKEN` — verified by sha256, not by eye.
* The service needs `AlgorithmConfiguration` (status `active`) seeded, or the context builder
  throws `INTELLIGENCE_CONFIGURATION_INVALID` before the assistant is ever reached:
  `php artisan db:seed --class=AlgorithmConfigurationSeeder --force`

### The endpoint itself was proven over real HTTP

Beyond calling the service directly, the **whole learner route** was exercised end to end with no
mock — `POST /api/v1/assistant/ask` → middleware → FormRequest → `AssistantService` →
`AssistantClient` → live FastAPI → back. All checks passed:

| Check | Result |
|---|---|
| `HTTP 201` | ✅ (≈4.4 s including the model call) |
| `X-Request-ID` echoed | ✅ |
| `data.reply` non-empty prose | ✅ |
| `data.response_status = succeeded` | ✅ |
| audit row persisted with the right `intent` | ✅ |
| no column that could hold a reply (§12.5) | ✅ — `assistant_interactions` has no `reply`/`answer`/`response` column at all |
| out-of-scope intent → `422 ASSISTANT_INTENT_NOT_ALLOWED` | ✅ |
| no token → `401` | ✅ |

The §12.5 check is **structural, not a string comparison**: the absence of any reply-bearing column
means no code path *can* persist the prose, which is a far stronger guarantee than testing that one
particular string is missing.

### Verify the whole chain yourself

```bash
# 1. the service is up and can actually answer (not just a live process)
curl --noproxy '*' http://127.0.0.1:8010/health
# {"status":"ok","providers":["groq","gemini","cerebras"],"auth_enabled":true,"retrieval":"ready"}

# 2. Laravel reaches it through the governance gate
php artisan tinker --execute="
\$sp = App\Models\StudentProfile::first();
\$a = app(App\Services\Assistant\AssistantService::class)->ask(\$sp,
  ['intent'=>'explain_readiness','question'=>'How is my Readiness Score calculated?'], 'probe-1');
echo mb_substr(\$a->reply, 0, 200);
"
```

Use `--noproxy '*'`: this shell exports `HTTP_PROXY`, and without it a localhost probe is routed
through the proxy and reports a misleading `502` instead of connection-refused.

---

## 8. Summary of the two required actions

| # | Action | Owner | Where |
|---|---|---|---|
| 1 | ~~Set `ASSISTANT_ENABLED=true` and a non-empty `ASSISTANT_APPROVAL_REFERENCE`~~ **done** | backend / ops | `.env` (per service) |
| 2 | Replace the hardcoded `setTimeout` reply with a `POST /api/v1/assistant/ask` call | frontend | `AssistantPanel.tsx:667`, `AssistantView.tsx:624` |

(1) is complete and the API now answers `201` with a real reply. **Only (2) remains** — and until
it lands, the UI still shows the hardcoded string regardless of what the backend does, because the
stub never issues a request at all.
Until (2) is done the UI never sends anything at all.

`.env` is gitignored, so the local `ASSISTANT_SERVICE_TOKEN` is not committed — but it must match
`SERVICE_TOKEN` in the chatbot service's own environment.

