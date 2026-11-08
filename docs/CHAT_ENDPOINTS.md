# Chat / Assistant endpoints — alwaysdata

**Base URL (new):** `https://skillspan.alwaysdata.net`
**API prefix:** `/api/v1`

> ⚠️ The old base `https://back-end-zdip.onrender.com` is still running but is a **different
> deployment with a different database**. The Vercel bundle now points at alwaysdata. Sanctum
> tokens are issued per backend, so a token obtained from Render returns **401** on alwaysdata —
> the frontend must log in again after the switch.

All routes below require:

```
Authorization: Bearer <token>
Accept: application/json
```

A `X-Request-ID` header is optional on every request; if sent it is echoed back on the response
(and inside error payloads) for correlation.

---

## 1. AI assistant ("chat with the bot")

Learner-only (`role:learner`). A mentor or company account gets **403**.

### POST `/api/v1/assistant/ask`

The main chat call. Server-side it is a billed LLM round trip (up to ~60 s), throttled at
**20 requests/minute per learner** → **429** when exceeded.

```bash
curl -X POST https://skillspan.alwaysdata.net/api/v1/assistant/ask \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "intent": "explain_readiness",
    "question": "ليش جاهزيتي نازلة؟",
    "project_id": null,
    "recommendation_id": null
  }'
```

**Body**

| field | type | required | notes |
|---|---|---|---|
| `intent` | string | ✅ | one of the six values below |
| `question` | string | ✅ | 3–2000 chars |
| `recommendation_id` | int | — | only with `explain_project_recommendation` |
| `project_id` | int | — | only with `project_bounded_help` |

Allowed `intent` values (anything else → **422 `ASSISTANT_INTENT_NOT_ALLOWED`**):

```
explain_readiness
explain_skill_gap
explain_roadmap
explain_next_best_action
explain_project_recommendation
project_bounded_help
```

**201 Created** — `data` is the interaction record plus the reply:

```json
{
  "data": {
    "id": 41,
    "intent": "explain_readiness",
    "context_reference": "readiness:...",
    "related_recommendation_id": null,
    "related_project_id": null,
    "response_status": "answered",
    "answer_status": "answered",
    "grounded": true,
    "report_status": null,
    "report_reason": null,
    "reported_at": null,
    "algorithm_version": "v1",
    "prompt_version": "v1",
    "configuration_version": "v1",
    "failure_code": null,
    "request_id": "…",
    "created_at": "2026-11-01T09:12:33+00:00",

    "reply": "…نص الإجابة…",
    "provider_used": "fastapi",
    "handoff_available": false
  }
}
```

How to read it in the UI:

- **`reply`** — always render it. It is *not* persisted (data minimisation), so it only exists on
  this response. If the frontend needs a history, it must keep it in memory/local state.
- **`response_status`** — branch monitoring on this, not on `reply`. `failed` + a `reply` present
  is a valid pair: the fallback text was returned but no model produced it.
- **`handoff_available: true`** — show the "talk to a human" button (→ §2).
- **`id`** — keep it; it is what `/report` needs.

### PUT `/api/v1/assistant/interactions/{interaction}/report`

Report a bad answer.

```json
{ "report_status": "irrelevant", "report_reason": "الجواب ما إلو علاقة بسؤالي" }
```

- `report_status` (**required**) — exactly one of `unsafe`, `irrelevant`, `unfair`, `incorrect`.
  It is the classification, not a flag; anything else is **422**.
- `report_reason` — optional free text, max 1000.

Returns **200** with the same resource shape (no `reply`).

---

## 2. Human handoff (support thread)

Learner-only. This is *not* a mentor conversation — a learner with no mentor can still use it.

| method | path | what |
|---|---|---|
| POST | `/api/v1/support/requests` | open a request (returns the existing open one if any) |
| GET | `/api/v1/support/requests` | my requests (`?per_page=15`, max 100) |
| GET | `/api/v1/support/requests/{id}` | one request **with `messages`** |
| POST | `/api/v1/support/requests/{id}/messages` | reply (`{"body": "…"}`, max 4000) |
| GET | `/api/v1/support/avatar/{user}` | avatar binary of the agent (`sender_id` from a message) |

**POST `/api/v1/support/requests`** — body:

```json
{
  "reason": "learner_requested",
  "subject": "مشكلة بحسابي",
  "transcript": [
    { "role": "learner",   "body": "…" },
    { "role": "assistant", "body": "…" }
  ],
  "source_interaction_id": 41
}
```

- `reason`: `learner_requested` | `insufficient_context` (defaults server-side).
- `transcript`: max **40** entries, each `body` max 4000. The server stores **no** assistant
  history, so this is the only copy of what was said — send it if you want it kept.
- `source_interaction_id`: the `id` from `/assistant/ask`. Another learner's id is silently
  ignored (no 403 that would confirm it exists).

**201** response carries `id`, `status`, `reason`, `subject`, `transcript`, `assignee_name`,
`handoff_seconds` (countdown before revealing the thread — default 5), `messages: []`.

---

## 3. Mentor ↔ student conversations

Open to **both** mentors and students (`auth:sanctum` + `account.active` only). Authorisation is a
participant check inside the service, not a role gate — a non-participant gets **403**, a
non-existent id **404**.

| method | path | what |
|---|---|---|
| POST | `/api/v1/connections/{connection}/conversations` | create the conversation for a connection (no body) |
| GET | `/api/v1/conversations` | my conversations (20/page, `?page=`) |
| GET | `/api/v1/conversations/{id}` | one conversation |
| POST | `/api/v1/conversations/{id}/messages` | send (`{"body": "…", "message_type": "text"}`) |
| GET | `/api/v1/conversations/{id}/messages` | thread, newest first (50/page, `?page=`) |
| POST | `/api/v1/conversations/{id}/read` | mark unread as read → `{"marked_read": 3}` |
| GET | `/api/v1/conversations/{id}/status` | communication status |
| DELETE | `/api/v1/conversations/{id}` | hide from **my** list only (not a delete) |

`message_type` accepts `text` (default) or `chatbot`. `system` is **422** — only server-side code
may author it. `body` max 5000 (`communication.message_max_length`).

Message object:

```json
{
  "id": 812,
  "conversation_id": 34,
  "sender_id": 17,
  "sender_name": "…",
  "body": "…",
  "message_type": "text",
  "metadata": null,
  "read_at": null,
  "read_by": null,
  "created_at": "2026-11-01T09:20:00+00:00"
}
```

Compare `sender_id` with the logged-in user's id to pick the bubble side. List responses use the
`{success, message, data: [], meta: {current_page, last_page, per_page, total}}` envelope; single
resources use `{data: {...}}`.

### Chatbot messages (automated, inside a conversation)

| method | path | what |
|---|---|---|
| POST | `/api/v1/conversations/{id}/chatbot/messages` | post `{"body": "…", "metadata": {}, "source": "…"}` |
| GET | `/api/v1/conversations/{id}/chatbot/messages` | only the `message_type=chatbot` rows |

`message_type` cannot be set by the client here — the endpoint always writes `chatbot`.

---

## 4. Things that will bite the frontend

1. **No WebSocket / no broadcasting.** There is no `ShouldBroadcast` anywhere in the app and no
   Pusher/Echo config. Poll `GET /conversations/{id}/messages` and
   `GET /support/requests/{id}` (e.g. every 5–10 s) and stop polling when the tab is hidden.
2. **`/assistant/ask` can take up to a minute.** Set the client timeout ≥ 60 s for that one call
   only; do not raise it globally.
3. **The reply is not stored.** Refresh = lost conversation, unless you keep it client-side or
   pass it in `transcript` on handoff.
4. **401 vs 403 vs 404:** 401 = bad/expired token (re-login), 403 = wrong role or not a
   participant, 404 = no such resource *or* not yours.
5. **CORS:** the Vercel origin is already allowed on alwaysdata. If you add a new frontend
   domain, the backend allow-list must be updated — otherwise the preflight fails before any of
   the above is reached.

---

## 5. Assistant service wiring on the new host (fixed 2026-11-01)

`POST /api/v1/assistant/ask` answered **503 `ASSISTANT_UNAVAILABLE`** in ~0.3 s until the
alwaysdata `.env` was completed — an instant connection refusal, not a timeout: the transport
settings were missing, so Laravel fell back to `http://127.0.0.1:8010` where nothing listens
(the same bug Render had on 2026-10-01). The backend fix (ops, not frontend) was:

```dotenv
ASSISTANT_ENABLED=true
ASSISTANT_APPROVAL_REFERENCE=LOCAL-DEV-E2E-2026-09-30
ASSISTANT_SERVICE_URL=https://skillspan-intelligence.onrender.com
ASSISTANT_SERVICE_PATH=/api/v1/assistant/chat
ASSISTANT_SERVICE_TOKEN=<same value as DATA_SCIENCE_SERVICE_TOKEN>
ASSISTANT_SERVICE_TIMEOUT=120
```

then `php artisan config:clear`. Verified after the fix: **201**, `response_status: "succeeded"`,
`grounded: true`, real reply.

Diagnose a regression by the speed alone: a `503` in **under a second** is a connection refusal
(config/URL), a `503` after ~60 s is a genuine upstream timeout.

Everything else in this document (auth, conversations, chatbot, support) is unaffected.

---

## 6. Quick smoke test (no token)

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST \
  https://skillspan.alwaysdata.net/api/v1/assistant/ask -H "Accept: application/json"
# 401  → route exists on the new host (correct)
# 404  → wrong base URL
```

Verified against `https://skillspan.alwaysdata.net`: `/assistant/ask` → 401,
`/support/requests` → 401, `GET /conversations` → 401 (POST → 405, i.e. route exists).
