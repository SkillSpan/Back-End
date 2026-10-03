# Frontend: why the assistant shows the WRONG error

**Date:** 2026-10-01
**Frontend:** `C:\Users\HP\Downloads\skillspan-dashboard-navigation-fixed\Front-end-main`
**Verdict:** the frontend **throws away the backend's error code**, then guesses the message from the
HTTP status alone. Every 503 renders as *"The assistant is not enabled or not configured"* — including
ones that have nothing to do with the flag.

This is why a **connection failure** looked like a **configuration problem** for hours.

---

## The bug in one picture

```
Laravel returns:  {"code": "ASSISTANT_UNAVAILABLE", "message": "...", "request_id": "..."}
                            ↓
api.js keeps:     {status: 503, message: "...", errors: {}}
                            ↑ `code` is DROPPED here
                            ↓
AssistantView:    if (status === 503) → "The assistant is not enabled or not configured on this
                                          environment yet."      ← hardcoded, wrong for 3 of 4 cases
```

The backend distinguishes **four different 503s**. The frontend shows **one sentence** for all of them.

---

## Defect 1 — `src/api.js` drops `code`

Around **line 178**:

```js
    throw {
      status: response.status,
      message: data.message || 'Something went wrong. Please try again.',
      errors: data.errors || {},
    };
```

`data.code` and `data.request_id` are never copied, so **no caller anywhere in the app can branch on
the code**. Every error handler in the codebase is forced to guess from `status`.

**Fix — one line:**

```js
    throw {
      status: response.status,
      code: data.code,                    // ← ADD
      requestId: data.request_id,         // ← ADD (useful for support/debugging)
      message: data.message || 'Something went wrong. Please try again.',
      errors: data.errors || {},
    };
```

`request_id` is worth keeping too — the backend already sets it on every error and it is the fastest
way to find the matching entry in `storage/logs`.

---

## Defect 2 — `describeAssistantError()` collapses the 503 family

In `src/components/learner/AssistantView.jsx`, **line 831**:

```js
function describeAssistantError(error) {
  const status = error?.status;
  const message = error?.message;
  if (status === 401) { … }
  if (status === 403) { … }
  if (status === 503) {
    return {
      explanation: "The assistant is not enabled or not configured on this environment yet.",
      canHelp: "No data was changed. Please try again later.",
    };
  }
  …
}
```

`status === 503` matches **four** different backend conditions. The text is only correct for two of
them, and it is **actively misleading** for the other two — it sends the reader off to check
`ASSISTANT_ENABLED`, which is not the problem.

**Fix — branch on the code first, status as fallback:**

```js
function describeAssistantError(error) {
  const status = error?.status;
  const code = error?.code;              // requires the api.js fix above
  const message = error?.message;

  switch (code) {
    case 'ASSISTANT_NOT_ENABLED':
      return {
        explanation: "The assistant is switched off on this environment.",
        canHelp: "An administrator needs to enable it. No data was changed.",
      };
    case 'ASSISTANT_APPROVAL_NOT_RECORDED':
      return {
        explanation: "The assistant is enabled but its governance approval reference is missing.",
        canHelp: "An administrator needs to record the approval. No data was changed.",
      };
    case 'ASSISTANT_NOT_CONFIGURED':
      return {
        explanation: "The assistant is enabled but not reachable — its address or service credential is wrong.",
        canHelp: "A deployment problem, not something you can fix. No data was changed.",
      };
    case 'ASSISTANT_UNAVAILABLE':
      return {
        explanation: "The assistant service is unreachable or took too long to answer.",
        canHelp: "Please try again in a few seconds. The first request after a quiet period is slow.",
      };
    case 'ASSISTANT_FAILED':
    case 'ASSISTANT_INVALID_RESPONSE':
      return {
        explanation: "The assistant service answered in a way we could not use.",
        canHelp: "Please try again. No data was changed.",
      };
    case 'STUDENT_PROFILE_NOT_FOUND':
      return {
        explanation: "The assistant needs your learner profile before it can answer.",
        canHelp: "Complete your profile and baseline assessment, then ask again.",
      };
    case 'ASSISTANT_INTENT_NOT_ALLOWED':
      return {
        explanation: "That question is outside what the assistant is allowed to answer.",
        canHelp: "Supported topics: readiness, skill gaps, roadmap, next best action, project recommendations, and bounded project help.",
      };
    default:
      break;
  }

  // …then the existing status-based fallbacks, unchanged…
  if (status === 401) { … }
  if (status === 403) { … }
  if (status === 503) { … }   // keep as the generic last resort
  …
}
```

---

## Why this matters (the actual cost)

| What the user saw | What the backend actually said | What the user did |
|---|---|---|
| "The assistant is not enabled" | `ASSISTANT_NOT_ENABLED` (503) | ✅ correctly checked the flag |
| "The assistant is not enabled" | `STUDENT_PROFILE_NOT_FOUND` (422) | — |
| "The assistant is not enabled" | **`ASSISTANT_UNAVAILABLE` (503)** — a *connection failure* | ❌ spent hours on the flag, which was already `true` |

The last row is the expensive one. `ASSISTANT_UNAVAILABLE` is thrown from **two different places** in
`AssistantClient` with the same code but different messages:

- line 150 `catch (ConnectionException)` → *"unavailable **or timed out**"* → **never reached the service**
- line 288 `$status >= 500` → *"failed to process the request"* → the service answered badly

The frontend discards both and prints "not enabled", so the real signal never reached the screen.

---

## The backend's full error vocabulary (so the mapping can be complete)

| `code` | HTTP | Meaning | Who can fix it |
|---|---|---|---|
| `ASSISTANT_NOT_ENABLED` | 503 | `ASSISTANT_ENABLED` is not `true` | config |
| `ASSISTANT_APPROVAL_NOT_RECORDED` | 503 | `ASSISTANT_APPROVAL_REFERENCE` is empty | config |
| `ASSISTANT_NOT_CONFIGURED` | 503 | service URL/token empty, **or** the service rejected our credential (401/403), **or** the service itself returned 503 | config |
| `ASSISTANT_UNAVAILABLE` | 503 | connection failure / timeout, **or** the service returned 5xx | infra |
| `ASSISTANT_FAILED` | 502 | unexpected transport failure | infra |
| `ASSISTANT_INVALID_RESPONSE` | 502 | unexpected status, or a body we could not parse | infra |
| `ASSISTANT_VALIDATION_ERROR` | 422 | the service rejected our payload shape | code |
| `ASSISTANT_INTENT_NOT_ALLOWED` | 422 | `intent` outside the six-value whitelist | user |
| `STUDENT_PROFILE_NOT_FOUND` | 422 | learner has no `student_profile` row | user |
| `LEARNER_ONLY` | 403 | caller is not a learner | user |
| `ASSISTANT_INTERACTION_NOT_FOUND` | 404 | report target does not exist | user |
| `ASSISTANT_REPORT_FAILED` | 502 | the report could not be recorded | infra |
| `ASSISTANT_PROVIDERS_UNAVAILABLE` | 200 | **soft failure** — see below | infra |

⚠️ **`ASSISTANT_PROVIDERS_UNAVAILABLE` arrives with HTTP 200.** The response is
`{reply: <fallback text>, response_status: "failed", provider_used: null}`. It is **not** an error
status, so no `catch` block will ever see it. `provider_used === null` is the only machine-readable
signal. `AssistantView` must check it **after** a successful call:

```js
const payload = unwrapApiData(response) || {};
const everyProviderFailed = payload.provider_used === null;
```

---

## Secondary note — `AssistantPanel.jsx` has the same gap

`src/components/learner/AssistantPanel.jsx` (around line 846) catches and shows `error.message`
directly, so it at least surfaces the backend's real text — but it also only special-cases
`status === 422`, so it cannot tell the 503s apart either. Apply the same `code` switch there.

---

## What the frontend team needs to change

1. **`src/api.js`** — add `code: data.code` (and ideally `requestId: data.request_id`) to the thrown
   error object. **One line.** Without this, nothing else is possible.
2. **`src/components/learner/AssistantView.jsx`** — branch on `error.code` in
   `describeAssistantError()` before falling back to `status`.
3. **`src/components/learner/AssistantPanel.jsx`** — same switch.
4. **Both** — handle `provider_used === null` on a **200** response.

No backend change is required. The backend already sends everything needed.
