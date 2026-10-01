# E2E Conversation Test Data (Postman)

**Created:** 2026-10-01
**Why:** the database had **no mentors, no connections and no conversations at all**
(`professional_profiles`, `mentor_student_connections`, `conversations` were all empty),
so there was no `conversation_id` to hand over. This fixture creates a self-contained pair.

**Database:** `b6aducvvb7pz4ebha27u` (clever-cloud) — the shared DB. The live Render API reads the
same DB, so these ids and tokens work against **both** `http://127.0.0.1:8000` and
`https://back-end-zdip.onrender.com`.

---

## The ids

| What | Value |
|---|---|
| **`conversation_id`** | **`1`** |
| `connection_id` | `1` |
| mentor `user_id` | `11` |
| student `user_id` | `12` |
| outsider `user_id` | `15` (deliberately **not** a participant — for 403 tests) |
| conversation `status` | `active` |
| `retention_expires_at` | 2027-10-01 |

## Credentials (if you'd rather log in and get a fresh token)

| Role | Email | Password |
|---|---|---|
| mentor | `e2e-mentor@example.com` | `E2eMentor123!` |
| student | `e2e-student@example.com` | `E2eStudent123!` |
| outsider | `e2e-outsider@example.com` | `E2eOutsider123!` |

## Tokens (ready to paste — `Bearer <token>`)

```
mentor_token   = 29|d0zlKkf0HWXIkoWiYmEMLq3IMDQ8JWCBjRz5rbbvdd3ccc14
student_token  = 30|xjvEprcI27mxkVlPhZXkBwRJAVtLQgY02TuNht3e2e4417a3
outsider_token = 36|ae1f9125cd04d0ed84cf1351f14b19b092035d9c
```

---

## Verified requests

All of these were run against the **live** API and returned the status shown.

| # | Method | Path | Auth | Result |
|---|---|---|---|---|
| 1 | `GET` | `/api/v1/conversations` | mentor | `200` |
| 2 | `GET` | `/api/v1/conversations/1` | mentor | `200` |
| 3 | `GET` | `/api/v1/conversations/1/status` | mentor | `200` |
| 4 | `GET` | `/api/v1/conversations/1/messages` | mentor | `200` |
| 5 | `POST` | `/api/v1/conversations/1/messages` | mentor | `201` |
| 6 | `POST` | `/api/v1/conversations/1/messages` | student | `201` |
| 7 | `POST` | `/api/v1/conversations/1/read` | mentor | `200` |
| 8 | `POST` | `/api/v1/conversations/1/chatbot/messages` | mentor | `201` |

### Headers (every request)

```
Accept: application/json
Authorization: Bearer <mentor_token | student_token>
Content-Type: application/json      ← only on POST
```

### Send a message — `POST /api/v1/conversations/1/messages`

```json
{ "body": "Hello from the E2E fixture — mentor speaking." }
```

→ `201`
```json
{"data":{"id":1,"conversation_id":1,"sender_id":11,"body":"Hello from the E2E fixture — mentor speaking.",
"message_type":"text","metadata":null,"read_at":null,"read_by":null,"created_at":"2026-10-01T09:37:59+00:00"}}
```

### Chatbot message — `POST /api/v1/conversations/1/chatbot/messages`

```json
{ "body": "Automated chatbot note." }
```

→ `201`. Note `message_type` comes back as **`chatbot`** and `metadata` as
`{"source":"chatbot"}` — the endpoint forces that type, you cannot set it yourself.

### Create another conversation — `POST /api/v1/connections/1/conversations`

No body needed. → `201`.

⚠️ **It is idempotent by design.** `ConversationService::createConversation()` first looks for an
existing `active` conversation on that connection and **returns it instead of creating a second
one** — so calling this twice gives you `conversation_id = 1` both times, not `1` then `2`. If you
want a second conversation, archive/close the first (or use a different connection).

---

## Authorization rules worth testing — all verified live

The outsider token (`user_id = 15`, an active/verified account that is simply **not** on connection 1)
was used to exercise every guard. Results are the actual live responses:

| Request as outsider | Result |
|---|---|
| `GET /api/v1/conversations/1` | `403 UNAUTHORIZED_CONVERSATION` |
| `GET /api/v1/conversations/1/status` | `403 UNAUTHORIZED_CONVERSATION` |
| `GET /api/v1/conversations/1/messages` | `403 UNAUTHORIZED_CONVERSATION` |
| `POST /api/v1/conversations/1/messages` | `403 UNAUTHORIZED_CONVERSATION` |
| `POST /api/v1/conversations/1/read` | `403 UNAUTHORIZED_CONVERSATION` |
| `POST /api/v1/conversations/1/chatbot/messages` | `403 UNAUTHORIZED_CONVERSATION` |
| `POST /api/v1/connections/1/conversations` | `403 **NOT_A_PARTICIPANT**` |
| `GET /api/v1/conversations` (his own list) | `200`, `data: []` |

⚠️ **Note the two different codes.** Reading/writing inside a conversation gives
`UNAUTHORIZED_CONVERSATION`, but *creating* one on the connection gives `NOT_A_PARTICIPANT`. Both are
`403`, both mean "you're not in this connection", but they come from different methods
(`getConversation()` vs `createConversation()`). A frontend that switches on `error.code` must
handle both or one path will show the wrong message.

The outsider's own list returning `200` with `data: []` is the right behaviour — the guard is
per-resource, not a blanket lock-out.

Other rules:

- **Connection must be live.** Only `status IN (pending, active)` is allowed; a `disconnected` or
  `archived` connection gives `422 CONNECTION_NOT_ACTIVE`.
- **Conversation must be `active`.** Otherwise `sendMessage()` / `sendChatbotMessage()` return
  `422 CONVERSATION_NOT_ACTIVE`.
- **Account must be active.** `account.active` middleware rejects `suspended` / soft-deleted users
  with `403 ACCOUNT_DISABLED` and **revokes their tokens** in the process.
- The routes only need `auth:sanctum` + `account.active` — **no mentor role is required** to read
  or post in a conversation. (There is no `mentor` role in the `roles` table at all: only
  `learner`, `admin`, `company_admin`, `university_admin`. Mentor identity is the
  `ProfessionalProfile` with `type=mentor` + `verification_status=verified`.)

---

## How this was created

`storage/app/_e2e_conversation_fixture.php` (gitignored) — idempotent, re-runnable, and it prints
fresh tokens each time.

```bash
php artisan tinker --execute="require base_path('storage/app/_e2e_conversation_fixture.php');"
```

Two gotchas it encodes, both of which would otherwise fail silently:

- `users.status` and `users.email_verified_at` are **not in `$fillable`**, so `User::create()`
  drops them silently → written explicitly with `forceFill()`.
- `ProfessionalProfile.verification_status` is **not in `$fillable`** either — and it is exactly
  what `EnsureUserIsMentor` gates on. Written explicitly.
- `User` is looked up with `withTrashed()` first, because a soft-deleted row still owns the unique
  index on `users.email`.
- `ProfessionalProfile` has **no `SoftDeletes`** — so no `withTrashed()` / `restore()` on it.

---

## Cleanup (one block, when you're done)

Covers **all three** fixture accounts — mentor `11`, student `12`, and the outsider `15`. The
outsider was inserted by raw SQL (`storage/app/_e2e_outsider.sql`) rather than by the fixture
script, so it is matched by email here too.

```bash
php artisan tinker --execute="
DB::transaction(function () {
    \$emails = ['e2e-mentor@example.com','e2e-student@example.com','e2e-outsider@example.com'];
    \$ids = App\Models\User::withTrashed()->whereIn('email', \$emails)->pluck('id');
    \$convIds = DB::table('conversations')->whereIn('created_by', \$ids)->pluck('id');

    DB::table('messages')->whereIn('conversation_id', \$convIds)->delete();
    DB::table('conversations')->whereIn('created_by', \$ids)->delete();
    DB::table('mentor_student_connections')->whereIn('mentor_id', \$ids)->orWhereIn('student_id', \$ids)->delete();
    DB::table('personal_access_tokens')->whereIn('tokenable_id', \$ids)->delete();
    DB::table('user_role')->whereIn('user_id', \$ids)->delete();
    DB::table('notifications')->whereIn('notifiable_id', \$ids)->delete();
    App\Models\ProfessionalProfile::whereIn('user_id', \$ids)->delete();
    App\Models\User::withTrashed()->whereIn('id', \$ids)->forceDelete();

    echo 'removed ' . \$ids->count() . ' fixture user(s)' . PHP_EOL;
});
"
```

**Dry-run verified** — it resolves to exactly 3 users, 1 connection, 1 conversation, and the
messages/tokens/profile attached to them, and it does **not** touch any other account.

⚠️ **Do not widen the email list.** The database also holds real accounts created by hand during
testing — `ahmadandasaad9@gmail.com` (user 13), `mana@gmail.com` (user 14), and
`ahmedsaqallah22@gmail.com` (user 2, admin). Only the three `e2e-*@example.com` addresses are ours.

⚠️ **Messages on conversation 1** were created while verifying the endpoints above (text, system,
chatbot, and the unread round-trip). They live only inside this fixture's conversation, so the
cleanup block removes them with it.
