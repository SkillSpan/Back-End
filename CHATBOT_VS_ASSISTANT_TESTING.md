# Testing the "bot" — two different endpoints, do not confuse them

**Date:** 2026-10-01
**Target:** `https://back-end-zdip.onrender.com`
**Fixture:** `conversation_id = 1`, mentor `user_id=11`, student `user_id=12`, outsider `user_id=15`

There are **two** things people call "the bot", and only one of them is an AI. Everything below was
executed against the live API — the status codes are what the server actually returned.

---

## The one-line difference

| | `POST /conversations/{id}/chatbot/messages` | `POST /assistant/ask` |
|---|---|---|
| What it is | an **automation channel** — posts an automated message *into* a conversation | the **AI assistant** (US-REC-01) |
| Does it generate a reply? | **No.** It stores what you send, tagged `chatbot`. | **Yes** — forwards to the FastAPI service and returns its prose |
| Who may call it | either **participant** of the conversation | **`role:learner`** only, and the account needs a `student_profile` |
| Works today? | ✅ **Yes** | ❌ **No — blocked by 3 gates** (see below) |

If you want a bot that *answers questions*, you want `/assistant/ask`. If you want to inject a
scripted "the assistant says…" message into a thread, you want the chatbot endpoint.

---

## 1. Chatbot messages — works now ✅

### Endpoint

```
POST {{base_url}}/api/v1/conversations/{{conversation_id}}/chatbot/messages
Authorization: Bearer {{learner_token}}      ← must be a PARTICIPANT of that conversation
Accept: application/json
Content-Type: application/json
```

### Payloads to send (all verified)

**Minimal:**
```json
{ "body": "تذكير: أكمل خطوة التقييم" }
```

**Full:**
```json
{
  "body": "تذكير: أكمل خطوة التقييم",
  "source": "reminder_engine",
  "metadata": { "step": 2, "due": "2026-10-05" }
}
```
→ `201`
```json
{
  "data": {
    "id": 10,
    "conversation_id": 1,
    "sender_id": 12,
    "body": "تذكير: أكمل خطوة التقييم",
    "message_type": "chatbot",
    "metadata": { "due": "2026-10-05", "step": 2, "source": "reminder_engine" },
    "read_at": null, "read_by": null,
    "created_at": "2026-10-01T12:43:28+00:00"
  }
}
```

**Read them back:**
```
GET {{base_url}}/api/v1/conversations/{{conversation_id}}/chatbot/messages
```
→ `200`, returns **only** `message_type=chatbot` rows (human messages are filtered out).

### Field reference

| Field | Required | Rules | Notes |
|---|---|---|---|
| `body` | **yes** | string, `max:5000` | the message text |
| `source` | no | string, `max:100` | merged into `metadata.source` |
| `metadata` | no | object | stored as-is; `source` is added to it |

### What happens when you send something wrong

| You send | Result |
|---|---|
| `{"body":""}` | `422` — *"The body field is required."* |
| `{"body":"<5001 chars>"}` | `422` — *"must not be greater than 5000 characters."* |
| `{"body":"x","message_type":"text"}` | `201` — but stored as **`chatbot`** anyway |
| `{"body":"x","source":"reminder_engine"}` | `201`, `metadata.source = "reminder_engine"` |
| no `Authorization` / empty token | `401 Unauthenticated` |
| valid token of a **non-participant** | `403 UNAUTHORIZED_CONVERSATION` |

⚠️ **`message_type` is deliberately not accepted.** `SendChatbotMessageRequest` has no rule for it, so
it is silently dropped and the row is always written as `chatbot`. This is a security property: it
stops a caller from impersonating a human `text` message. Verified — sending
`"message_type":"text"` still returns `"message_type":"chatbot"`.

⚠️ `source` **overrides** the default. Without it you get `metadata: {"source":"chatbot"}`.

---

## 2. The AI assistant — blocked, here is exactly why 🤖

### Endpoint

```
POST {{base_url}}/api/v1/assistant/ask
```

**Body:**
```json
{
  "intent": "explain_readiness",
  "question": "Why is my readiness score low?",
  "recommendation_id": null,
  "project_id": null
}
```

`intent` is a **closed six-value whitelist** — this *is* the scope gate:

```
explain_readiness
explain_skill_gap
explain_roadmap
explain_next_best_action
explain_project_recommendation
project_bounded_help
```

`question` is required, `min:3`, `max:2000`.

### The gates, in the order they fire

I tested each one live:

| # | Gate | Live response | Status |
|---|---|---|---|
| 1 | `role:learner` middleware | mentor token → **`403 LEARNER_ONLY`** | active |
| 2 | needs a `student_profile` row | student token → **`422 STUDENT_PROFILE_NOT_FOUND`** | active |
| 3 | §12.5 governance: `ASSISTANT_ENABLED` **and** `ASSISTANT_APPROVAL_REFERENCE` | **`503 ASSISTANT_NOT_ENABLED`** | ✅ **now OPEN on Render** |
| 4 | service URL / path / token | **`503 ASSISTANT_UNAVAILABLE`** | ❌ **still wrong** |
| 5 | FastAPI payload contract | ~~`422 extra_forbidden`~~ → **`200`** | ✅ **FIXED by the DS owner** |

A bad intent is caught before any of that:
```
{"intent":"write_my_cv", "question":"..."}
→ 422 ASSISTANT_INTENT_NOT_ALLOWED
```

### ✅ Correction — the contract mismatch is GONE

An earlier report said Laravel's 4-field payload was rejected by production. **That is no longer
true.** Re-tested today with Laravel's exact payload and the correct token:

```bash
curl -X POST "https://skillspan-intelligence.onrender.com/api/v1/assistant/chat" \
  -H "Authorization: Bearer <DATA_SCIENCE_SERVICE_TOKEN>" \
  -H 'Content-Type: application/json' \
  -d '{"user_id":12,"message":"What is a skill gap?","intent":"explain_skill_gap","context":"{\"readiness\":70}"}'
```
→ **`200`**
```json
{"status":"answered","reply":"…","grounded":true,"sources":["laravel_context"],
 "provider_used":"groq","prompt_version":"v2"}
```

⚠️ **`sources: ["laravel_context"]` is the important part** — it proves the service **grounds on the
`context` Laravel sends**, it does not do its own retrieval. So `context` and `intent` must stay in
the payload. Do not strip them.

### The one thing still broken — three env vars on Render

`ASSISTANT_UNAVAILABLE` ("…unavailable **or timed out**") is the **connection-failure** branch in
`AssistantClient` (line 150), *not* the HTTP-5xx branch (that one says "failed to process the
request"). So Laravel never reached the service at all — the URL is wrong.

Render is almost certainly still running the **local default**, `http://127.0.0.1:8010`, where
nothing listens:

| Variable | Must be |
|---|---|
| `ASSISTANT_SERVICE_URL` | `https://skillspan-intelligence.onrender.com` |
| `ASSISTANT_SERVICE_PATH` | `/api/v1/assistant/chat` |
| `ASSISTANT_SERVICE_TOKEN` | **the same value as `DATA_SCIENCE_SERVICE_TOKEN`** |
| `ASSISTANT_SERVICE_TIMEOUT` | `120` (see the cold-start note) |

⚠️ **The two tokens are different values** — verified by hash. The local
`ASSISTANT_SERVICE_TOKEN` returns **`401 Invalid or missing service authentication token`** against
the live service; only `DATA_SCIENCE_SERVICE_TOKEN` returns `200`. Copy the DS one.

The auth header is **`Authorization: Bearer <token>`** — confirmed by brute-forcing six header names
(`X-Service-Token`, `X-API-Key`, `X-Internal-Token`, `Service-Token`, `X-Service-Auth` all → `401`).
Laravel already uses `->withToken()`, so its header is correct.

⚠️ **Cold start: the first request took 33.2 seconds.** The Render service sleeps; waking it is slow.
`ASSISTANT_SERVICE_TIMEOUT=60` technically covers it but leaves no headroom — set `120`, and tell the
frontend the first request after an idle period is slow.

### Path proof

| Request | Result |
|---|---|
| `POST /chat` (the Laravel default path) | **`404 Not Found`** |
| `POST /api/v1/assistant/chat` with no token | **`401 Invalid or missing service authentication token`** |
| …with `ASSISTANT_SERVICE_TOKEN` | `401` ← wrong token |
| …with `DATA_SCIENCE_SERVICE_TOKEN` + Laravel's 4-field payload | **`200`** ✅ |

Full analysis: `ASSISTANT_NOT_ENABLED_DIAGNOSIS.md`.


---

## Quick start in Postman

1. Import `postman_environment_mentor_e2e.json`.
2. **Select it** from the environment dropdown (top-right) — importing is not selecting.
3. `conversation_id` = `1`, and `learner_token` is the student, who **is** a participant.

```bash
# the chatbot endpoint, verified working
curl -X POST "https://back-end-zdip.onrender.com/api/v1/conversations/1/chatbot/messages" \
  -H "Authorization: Bearer 30|xjvEprcI27mxkVlPhZXkBwRJAVtLQgY02TuNht3e2e4417a3" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"body":"تذكير: أكمل خطوة التقييم","source":"reminder_engine","metadata":{"step":2}}'
```
