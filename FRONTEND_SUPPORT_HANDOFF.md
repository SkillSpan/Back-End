# رسالة لفريق الفرونت — زرار "تحويل للدعم الفني"

## الفكرة

لما الشات ما يعرفش يجاوب، بدل ما المستخدم يقفل ويضيع، بنعرض له زرار يحوّله **لدعم بشري** (منتور
أو أدمن). الباك إند جاهز ومتنشر على `https://back-end-zdip.onrender.com` — ناقص الزرار بس.

**متبعتوش رابط سيرفس الشات للفرونت خالص** (`skillspan-intelligence.onrender.com`) — ده سيرفر-لسيرفر.
الفرونت بيحكي مع Laravel بس.

---

## ١. إمتى تعرض الزرار؟ — حقل واحد

في رد `POST /api/v1/assistant/ask` فيه ٣ حقول جديدة:

```json
{
  "data": {
    "id": 42,
    "reply": "عذراً، لا تتوفر معلومات...",
    "provider_used": "groq",
    "answer_status": "insufficient_context",
    "grounded": false,
    "handoff_available": true
  }
}
```

| الحقل | المعنى |
|---|---|
| `handoff_available` | **`true` ← اعرض الزرار.** ده الحقل الوحيد اللي تقرأه |
| `answer_status` | `answered` / `insufficient_context` / `null` |
| `grounded` | هل الجواب مستند على الوثائق |

⚠️ **ممنوع تعمل string match على `reply`.** الرد بيتولّد كل مرة بصيغة مختلفة وبأي لغة. الحقل ده
موجود بالظبط عشان ما تعملش كده.

⚠️ `handoff_available` بتكون `false` في حالتين — وده **مقصود**:
- لما كل الـ providers تكون واقعة (`provider_used: null`) — مش منطقي نعرض دعم بشري لسؤال الشات
  ما فكّرش فيه أصلاً.
- لما `answer_status` مش موجود خالص (نسخة سيرفس قديمة).

---

## ٢. العدّاد

لما المستخدم يدوس على الزرار:

1. ابعت `POST /api/v1/support/requests` (تحت).
2. خُد `handoff_seconds` من الرد (افتراضي **5**).
3. اعرض «جاري تحويلك للدعم الفني...» مع عدّاد تنازلي.
4. بعد ما يخلّص، افتح شاشة المحادثة.

---

## ٣. الـ endpoints

كلها محتاجة:
```
Authorization: Bearer <sanctum token>
Accept: application/json
```
ومتاحة لـ `role: learner` بس.

### (أ) إنشاء طلب دعم

```
POST /api/v1/support/requests
```

**Body — كله اختياري:**

```json
{
  "reason": "insufficient_context",
  "subject": "سؤال عن سعر الاشتراك",
  "source_interaction_id": 42,
  "transcript": [
    { "role": "learner",   "body": "كام سعر الاشتراك الشهري؟" },
    { "role": "assistant", "body": "عذراً، لا تتوفر معلومات..." }
  ]
}
```

| الحقل | ملاحظات |
|---|---|
| `reason` | `insufficient_context` (الافتراضي) أو `learner_requested` |
| `subject` | اختياري، أقصى 180 حرف. لو بعتّه فاضي، الباك إند بيستنتجه من أول سؤال |
| `transcript` | **ده المهم** — المحادثة اللي بتتحوّل للدعم. أقصى **40** رسالة، كل رسالة أقصى **4000** حرف |
| `transcript[].role` | `learner` أو `assistant` بس |
| `transcript[].body` | **مطلوب** لو بعت `transcript` |
| `source_interaction_id` | `data.id` من رد الـ `ask` |

**الرد `201`:**

```json
{
  "data": {
    "id": 7,
    "status": "pending",
    "reason": "insufficient_context",
    "subject": "سؤال عن سعر الاشتراك",
    "assigned_to": 3,
    "assignee_name": "أحمد محمد",
    "source_interaction_id": 42,
    "transcript": [ /* نفس اللي بعتّه */ ],
    "handoff_seconds": 5,
    "messages": [],
    "last_message_at": null,
    "resolved_at": null,
    "created_at": "2026-10-07T11:05:00+00:00"
  }
}
```

✅ **الطلب idempotent** — لو المستخدم داس مرتين، أو عنده طلب مفتوح أصلاً، الباك إند بيرجّع **نفس
الطلب** مش واحد جديد. فمتقلقش من double-click ومتعملش أي guard من عندك.

✅ **التحويل بيحصل لوحده** — لو الطالب عنده منتور مقبول، الطلب بيتحوّله فورًا (`assigned_to` +
`assignee_name` بيبقوا مليانين). لو لأ، بيفضل `pending` وبيظهر في طابور الأدمن.

### (ب) قائمة الطلبات

```
GET /api/v1/support/requests
```

مترقّمة: `data` + `meta` + `links` (زي أي endpoint مترقّم تاني في المشروع).

### (ج) تفاصيل الطلب + الرسائل

```
GET /api/v1/support/requests/{id}
```

نفس شكل (أ) + `data.messages` فيه كل رسائل المحادثة.

### (د) إرسال رسالة

```
POST /api/v1/support/requests/{id}/messages
```

**Body:**
```json
{ "body": "لسا محتاج مساعدة في النقطة دي" }
```

**الرد `201`:** رسالة واحدة (شكلها تحت في §٤).

---

## ٤. ⚠️ أهم فخ: `message_type`

شكل الرسالة:

```json
{
  "id": 1,
  "support_request_id": 7,
  "sender_id": 5,
  "sender_name": "أحمد محمد",
  "body": "تم تحويلك إلى فريق الدعم الفني.",
  "message_type": "system",
  "read_at": null,
  "created_at": "2026-10-07T11:05:01+00:00"
}
```

> **⚠️ افرع على `message_type` الأول، وبعدين `sender_id`. مش العكس.**

- `message_type: "system"` = رسالة النظام (علامة التحويل). **`sender_id` بتاعها = نفس id الطالب**،
  فلو فرعت على `sender_id` الأول حتحسبها رسالة بتاعته وتنسبها له غلط.
- `message_type: "text"` = رسالة عادية. قارن `sender_id` بـ `data.user_id` بتاع الطلب عشان تعرف
  مين المرسل.
- `read_at` → علامة «مقروء». `null` = لسا ما اتقرتش.

---

## ٥. الأخطاء

| Status | `code` | المعنى |
|---|---|---|
| 401 | `{message}` | مفيش توكن |
| 403 | `LEARNER_ONLY` | مش طالب |
| 404 | `SUPPORT_REQUEST_NOT_FOUND` | الطلب مش موجود **أو مش بتاعه** (نفس الرد عمدًا) |
| 422 | `SUPPORT_REQUEST_CLOSED` | الطلب اتقفل — مينفعش تبعت رسالة |
| 422 | `VALIDATION_ERROR` | البيانات غلط (`errors` جواها التفاصيل) |
| 500 | `SUPPORT_HANDOFF_FAILED` | خطأ غير متوقع |
| 503 | `ASSISTANT_NOT_ENABLED` | الشات نفسه مقفول (§12.5) |

كل الردود فيها هيدر **`X-Request-ID`** — ابعته في أي report.

---

## ٦. الحالات

```
pending  →  assigned  →  resolved
                     ↘  closed
```

- `pending` = مستني حد ياخده
- `assigned` = منتور/أدمن مسؤول عنه
- `resolved` / `closed` = خلص — إرسال رسالة جديدة بيرجّع `422 SUPPORT_REQUEST_CLOSED`

---

## ٧. مثال سريع

```ts
const res = await fetch(`${API}/api/v1/assistant/ask`, {
  method: "POST",
  headers: {
    "Content-Type": "application/json",
    "Accept": "application/json",
    "Authorization": `Bearer ${token}`,
  },
  body: JSON.stringify({ intent: "explain_readiness", question: text }),
});

const { data } = await res.json();

if (data.handoff_available) {
  showTransferButton(() => createSupportRequest(data));
}
```

```ts
async function createSupportRequest(interaction) {
  const res = await fetch(`${API}/api/v1/support/requests`, {
    method: "POST",
    headers: { /* نفس الهيدرز */ },
    body: JSON.stringify({
      reason: "insufficient_context",
      source_interaction_id: interaction.id,
      transcript: messages.map(m => ({
        role: m.from === "user" ? "learner" : "assistant",
        body: m.text,
      })),
    }),
  });

  const { data } = await res.json();          // 201
  startCountdown(data.handoff_seconds, () => openThread(data.id));
}
```

---

## ٨. اللي مش مطلوب منكم

- **متستنتجوش رفض من نص الرد.** استخدموا `handoff_available`.
- **متعملوش idempotency من عندكم** للـ POST — الباك إند بيعملها.
- **متبعتوش `reason` غير من القيمتين** — غير كده 422.
- **متزودوش الـ transcript عن 40 رسالة** — 422 برسالة واضحة.
