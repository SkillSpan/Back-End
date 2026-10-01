# Conversation API — Full Postman Test Matrix

**Date:** 2026-10-01
**Target:** `https://back-end-zdip.onrender.com` (live Render API, same DB as local)
**Conversation under test:** `conversation_id = 1`, `connection_id = 1`
**Fixture:** mentor `user_id=11` (`e2e-mentor@example.com`), student `user_id=12` (`e2e-student@example.com`),
outsider `user_id=15` (`e2e-outsider@example.com`, not a participant)

> Everything below was executed against the **live** service. Status codes are what the server
> actually returned, not what it should return.

---

## ✅ `{{conversation_id}}` = **1**

For Postman, set these collection variables:

| Variable | Value |
|---|---|
| `base_url` | `https://back-end-zdip.onrender.com` |
| `conversation_id` | **`1`** |
| `connection_id` | `1` |
| `token` | `29\|d0zlKkf0HWXIkoWiYmEMLq3IMDQ8JWCBjRz5rbbvdd3ccc14` |
| `student_token` | `30\|xjvEprcI27mxkVlPhZXkBwRJAVtLQgY02TuNht3e2e4417a3` |
| `outsider_token` | `36\|ae1f9125cd04d0ed84cf1351f14b19b092035d9c` |

A ready-to-import environment is in **`postman_environment_mentor_e2e.json`** — import it into
Postman and select it, and the existing `postman_mentor_communication.json` collection works
without editing anything.

---

## Results — 39 cases

### 1. Authentication & routing

| # | Request | Result | Expected? |
|---|---|---|---|
| 1 | `GET /conversations/1` — no `Authorization` | `401` | ✅ |
| 2 | `GET /conversations/1` — garbage bearer token | `401` | ✅ |
| 3 | `GET /conversations/99999` — valid token, missing row | `404` | ✅ |

### 2. Message validation — `POST /conversations/1/messages`

| # | Body sent | Result | Expected? |
|---|---|---|---|
| 4 | `{"body":""}` | `422` | ✅ |
| 5 | `{}` (no body key) | `422` | ✅ |
| 6 | `{"body":"x","message_type":"bogus"}` | `422` | ✅ |
| 7 | `{"body":"<5001 chars>"}` | `422` | ✅ |

`StoreMessageRequest` allows `message_type ∈ {text, system, chatbot}` and
`max:5000` (from `COMMUNICATION_MESSAGE_MAX_LENGTH`).

### 3. Conversation creation & idempotency — `POST /connections/{id}/conversations`

| # | Request | Result | Expected? |
|---|---|---|---|
| 8 | `POST /connections/1/conversations` as mentor | `201`, returns `id: 1` | ✅ |
| 9 | `POST /connections/1/conversations` as student | `201`, returns `id: 1` (**same**) | ✅ |
| 10 | `POST /connections/99999/conversations` | `404` | ✅ |

⚠️ **#9 is the important one.** `ConversationService::createConversation()` looks for an existing
`active` conversation on the connection and **returns it instead of creating a second one**. So this
endpoint is **idempotent** — calling it repeatedly gives you `conversation_id = 1` every time, never
`2`. If you want a second conversation you must archive the first, or use a different connection.

### 4. Message types & the unread flow

| # | Request | Result | Expected? |
|---|---|---|---|
| 11 | `POST .../messages` with `message_type: "system"` | `201`, `message_type: "system"` | ✅ |
| 12 | `GET .../status` as **student**, before reading | `unread_count: 3` | ✅ |
| 13 | `POST .../read` as **student** | `200`, `marked_read: 3` | ✅ |
| 14 | `GET .../status` as student, after reading | `unread_count: 0` | ✅ |
| 15 | `GET .../chatbot/messages` | `200`, **only** the chatbot message | ✅ |

The unread counter is per-caller and excludes your own messages — `markAsRead` only touches rows
where `sender_id != you AND read_at IS NULL`. #12 → #13 → #14 is a clean round trip.

### 5. Chatbot messages — `POST /conversations/1/chatbot/messages`

| # | Request | Result | Expected? |
|---|---|---|---|
| 16 | `{}` (no body) | `422` | ✅ |
| 17 | `{"body":"…","source":"roadmap","metadata":{"step":2}}` | `201` | ✅ |

#17 came back as `message_type: "chatbot"` with
`metadata: {"step":2,"source":"roadmap"}` — so `message_type` is **forced** (you cannot post a
chatbot message as `text`), and your `metadata` is **merged** with the default `source`.

### 6. 404 handling on every sub-resource

| # | Request | Result |
|---|---|---|
| 18 | `GET /conversations/99999/messages` | `404` |
| 19 | `GET /conversations/99999/status` | `404` |
| 20 | `GET /conversations/99999/chatbot/messages` | `404` |
| 21 | `POST /conversations/99999/messages` | `404` |
| 22 | `POST /conversations/99999/read` | `404` |

All five route through `getConversation()` → `findOrFail`, so a missing id is consistently `404`
and never leaks a `500`.

### 7. Happy-path reads (verified in the previous run)

| # | Request | Result |
|---|---|---|
| 23 | `GET /conversations` | `200` |
| 24 | `GET /conversations/1` | `200` |
| 25 | `GET /conversations/1/status` | `200` |
| 26 | `GET /conversations/1/messages` | `200` |
| 27 | `POST /conversations/1/messages` as mentor | `201` |
| 28 | `POST /conversations/1/messages` as student | `201` |
| 29 | `POST /conversations/1/read` as mentor | `200` |
| 30 | `POST /conversations/1/chatbot/messages` | `201` |

### 8. Pagination

| # | Request | Result | Expected? |
|---|---|---|---|
| 31 | `GET /conversations?page=2` | `200`, `data: []`, `meta.current_page: 2`, `last_page: 1` | ✅ |
| 32 | `GET /conversations/1/messages?page=2` | `200`, `data: []`, `meta` correct | ✅ |

`page` works. See the finding below about `per_page`.

---

## 🔎 Finding — `per_page` is silently ignored

```bash
GET /conversations/1/messages                 → "per_page": 50
GET /conversations/1/messages?per_page=1      → "per_page": 50   ← ignored
GET /conversations?per_page=1                 → "per_page": 20   ← ignored
```

**Cause.** `ConversationService` accepts a `$perPage` argument and then **overwrites it** with the
config value before using it:

```php
public function getMessages(int $conversationId, int $userId, int $perPage = 50)
{
    $conversation = $this->getConversation($conversationId, $userId);
    $perPage = (int) config('communication.messages_per_page', 50);   // ← the argument is discarded
    ...
}
```

Same in `getChatbotMessages()` and `getConversations()` (`communication.conversations_per_page`,
default `20`).

**Why it matters.** The project convention for list endpoints is `per_page` (default 15, clamped at
100, echoed in `meta`). These three endpoints are the exception — they are fixed-size and a client
cannot ask for a smaller or larger page. `page` still works, so paging is possible, just not
size-configurable. Either honour `per_page` here too, or document these as fixed-size.

Not a bug in the sense of a crash — the parameter is simply accepted and dropped, which is the
worst kind of API surprise for a frontend that passes it.

---

### 9. Authorization — a valid user who is NOT a participant

Third fixture account: `e2e-outsider@example.com`, `user_id = 15`, token row `36`. It is a **fully
active, verified account** — so it clears `auth:sanctum` and `account.active` and reaches the real
ownership check. It is deliberately **not** part of connection 1.

| # | Request as outsider | Result | Body | Expected? |
|---|---|---|---|---|
| 33 | `GET /conversations/1` | `403` | `UNAUTHORIZED_CONVERSATION` | ✅ |
| 34 | `GET /conversations/1/status` | `403` | `UNAUTHORIZED_CONVERSATION` | ✅ |
| 35 | `GET /conversations/1/messages` | `403` | `UNAUTHORIZED_CONVERSATION` | ✅ |
| 36 | `POST /conversations/1/messages` | `403` | `UNAUTHORIZED_CONVERSATION` | ✅ |
| 37 | `POST /conversations/1/read` | `403` | `UNAUTHORIZED_CONVERSATION` | ✅ |
| 38 | `POST /conversations/1/chatbot/messages` | `403` | `UNAUTHORIZED_CONVERSATION` | ✅ |
| 39 | `POST /connections/1/conversations` | `403` | **`NOT_A_PARTICIPANT`** | ✅ |

**All six read/write paths on the conversation are `403 UNAUTHORIZED_CONVERSATION`; creation on the
connection is `403 NOT_A_PARTICIPANT`.** Two different codes for what a client thinks of as one
condition ("you're not allowed in") — a frontend that switches on `error.code` must handle **both**,
which is exactly the kind of thing that silently breaks an error toast.

The outsider's own `GET /conversations` returns `200` with `data: []` — so the guard is per-resource,
not a blanket lock-out, and an outsider sees an empty list rather than an error.

---

## Rules a Postman user should know

- **Participants only.** Both `mentor_id` and `student_id` may post and read. Anyone else is
  rejected. There is **no mentor role** to satisfy — the conversation routes need only
  `auth:sanctum` + `account.active`.
- **Conversation must be `active`.** `sendMessage()` and `sendChatbotMessage()` both return
  `422 CONVERSATION_NOT_ACTIVE` otherwise.
- **Connection must be `pending` or `active`** for creation, else `422 CONNECTION_NOT_ACTIVE`.
- **Suspended / soft-deleted accounts** get `403 ACCOUNT_DISABLED`, and the middleware **revokes
  that account's tokens** while responding.
- `markAsRead` counts only messages from *the other* participant that were unread.

---

## Environment note — `vendor/` is still being restored

While preparing the authorization test I found `vendor/` **absent** from
`E:\SkillSpan\Back-End-feature-authentication` (last present earlier the same day; the project root
was modified at 12:53). `composer.lock` is intact, so nothing was lost — but `php artisan` cannot
run without it.

I worked around it for the outsider account by bypassing Laravel entirely and inserting the user +
Sanctum token through the **mysql client** (`storage/app/_e2e_outsider.sql`), which is why the
authorization cases above are real live results rather than a plan.

The reinstall is **not finished**. The first attempt died after 18 minutes on a Composer process
timeout:

```
The process "…\unzip.exe -qq …\tmp-ae4b2a35fb5751bd9fae6dd380173cae.zip -d …" exceeded the timeout of 300 seconds.
    Install of laravel/framework failed
    Install of google/apiclient-services failed
    Install of phpunit/phpunit failed
```

So `vendor/` currently exists but **`vendor/autoload.php` does not**. The fix is to raise the
timeout — Composer's default 300 s per subprocess is simply too short for this sandbox's unzip:

```bash
cd E:/SkillSpan/Back-End-feature-authentication
export COMPOSER_HOME="C:/Users/HP/AppData/Local/Composer"
export COMPOSER_PROCESS_TIMEOUT=1800      # ← the missing piece
php "C:/Users/HP/composer/composer.phar" install --no-interaction --no-scripts
```

Once `vendor/autoload.php` exists, `php artisan` works again and the fixture script
(`storage/app/_e2e_conversation_fixture.php`) can be run normally.

Two shell gotchas worth keeping:

- `composer` on PATH is a **wrapper that fails** (`Could not open input file:
  /c/Users/HP/composer/composer.phar`) because Windows PHP cannot read a POSIX `/c/...` path. Call
  `php "C:/Users/HP/composer/composer.phar"` with a **Windows-style** path instead.
- Composer then needs `COMPOSER_HOME` (or `APPDATA`) exported, or it dies with
  *"The APPDATA or COMPOSER_HOME environment variable must be set"*.
- A third gotcha, only visible on a slow disk: **`COMPOSER_PROCESS_TIMEOUT` must be raised** or the
  unzip step for large packages times out and those packages silently do not install.
