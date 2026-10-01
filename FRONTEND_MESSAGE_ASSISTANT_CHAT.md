# رسالة للفرونت — الشات (Assistant Chat)

---

## 🇸🇾 بالعربي (النسخة الأساسية)

أهلاً شباب 👋

خلّصنا الباك-إند تبع الشات بالكامل، وهو **مجرَّب حيّاً وشغّال**. الشي الوحيد الباقي عندكن بالفرونت، وهوي مش صغير: **الشات حالياً ديمو ستاتيك — ما بيبعت ولا طلب شبكة أبداً.**

### شنو المشكلة بالزبط

لما المستخدم يكتب سؤال ويضغط إرسال، الكود ما بيعمل أي `fetch` ولا `axios`. بس بيستنى **1.6 ثانية** (عشان يبيّن "عم يفكّر") وبعدين بيرجّع **نفس الجملة الثابتة**، مهما كان السؤال:

> "I have received your message. To see structured responses, use the scenario preview buttons below. In a live integration, I would respond with your verified career context here."

يعني الجملة يلي بتشوفوها هي **مكتوبة hardcoded بالفرونت**، مش جاية من السيرفر. عشان هيك الإجابة نفسها دايماً.

### وين بالزبط

| الملف | السطر | الدالة |
|---|---|---|
| `src/components/AssistantPanel.tsx` | **662** | `sendMessage()` |
| `src/components/AssistantView.tsx` | **619** | نفس النمط |

الكود المسؤول (من `AssistantPanel.tsx`):

```tsx
function sendMessage(text: string) {
  if (!text.trim()) return;
  /* … user message added, input cleared, setIsTyping(true) … */
  setTimeout(() => {                      // ← مؤقّت، مش طلب شبكة
    setIsTyping(false);
    const reply: Message = {
      id: `a-${Date.now()}`, role: "assistant", type: "text",
      timestamp: new Date().toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" }),
      text: "I have received your message. To see structured responses, use the scenario preview buttons below. In a live integration, I would respond with your verified career context here.",  // ← hardcoded
    };
    setMessages(prev => [...prev, reply]);
  }, 1600);
}
```

⚠️ ملاحظة مهمة: **ما في طبقة API بالفرونت نهائياً** — لا `axios`، لا `fetch`، لا `.env`، ولا `import.meta.env`. ملفات `src/` كلها: `App.tsx`, `components/`, `imports/`, `index.css`, `main.tsx`, `vite-env.d.ts`. يعني **ما في شبكة أبداً** بهذا المشروع — هو نموذج UI بس.

### الإندبوينت الصحيح

```
POST /api/v1/assistant/ask
```

**المصادقة:** `auth:sanctum` + `account.active` + `role:learner`
→ لازم **Bearer token** لمستخدم **learner** مسجّل. توكن أدمن بينرفض (لأنه `role:learner`).

**شرط مسبق:** المستخدم لازم يكون عندو `student_profile`. إذا ما عندو، الـ API بيرجّع
`422 STUDENT_PROFILE_NOT_FOUND` — هاد **طبيعي ومتوقّع، مش بَغ**.

**جسم الطلب:**
```json
{
  "question": "…سؤال المستخدم…",
  "intent": "explain_readiness"
}
```

**الرد:** HTTP **201**، والإجابة بمكانها `data.reply`.

**الـ `intent`** هو **قائمة مسموحة مقفلة من 6 قيم** (وهوي كمان بوابة النطاق — سؤال خارج هالقيم بيرجّع `422`):

| intent | متى |
|---|---|
| `explain_readiness` | شرح جاهزية المستخدم |
| `explain_skill_gap` | شرح فجوة المهارات |
| `recommend_next_step` | اقتراح الخطوة التالية |
| `explain_project_match` | شرح ليش هالمشروع مناسب |
| `explain_baseline` | شرح نتيجة الـ baseline |
| `general_guidance` | إرشاد عام |

### اقتراح عملي لسؤال واحد حاسم 🔴

**قبل ما تبدأوا الشغل الفعلي، جرّبوا الإندبوينت من Postman/curl بتوكن learner حقيقي.** السبب: حالياً الباك-إند **دائماً** بيبعت `context` للخدمة، والخدمة لما تستقبل `context` غير فارغ **بتستعملو كما هو وبتتخطّى البحث بقاعدة المعرفة**. النتيجة العملية: جواب الشات رح يكون *"I don't have enough information…"* لحالات كتير — وهاد **سلوك مقصود ومتفَق عليه** (القيم المخزّنة هي المرجع، وقاعدة REC-07 بتمنع إعادة صياغة تانية)، مش بَغ عندكن.

يعني: الـ wiring تبعكن صح ورح يشتغل، بس **النص يلي رح يطلع بالمستخدم حالياً محدود**. وهذا قرار منتج، لازم يوصلكن قبل ما تبلّشوا عشان ما تضيّعوا وقتكن بتصحيح "بَغ" مش موجود. التفاصيل الكاملة + سجل التحقق الحيّ موجودين بـ `ASSISTANT_CHAT_FRONTEND_HANDOFF.md` §5 و §7.

### شنو بدنا منكن

1. استبدلوا الـ `setTimeout` بـ `fetch` حقيقي للـ `POST /api/v1/assistant/ask`.
2. ابنوا طبقة API صغيرة (ملف واحد) — ما في شي هلق، وهاد رح تحتاجوه لكل الفيتشرز مش بس الشات.
3. احفظوا الـ `intent` المرجّع مع كل رسالة — رح تحتاجوه لزر "Report" (`PUT /api/v1/assistant/interactions/{id}/report`).
4. اربطوا الـ `reply` بـ `data.reply`، والباقي (`response_status`, `provider_used`) اختياري.

إذا في أي شي مو واضح — ابعتولي وأنا جاهز. موفقين 🚀

---

## 🇬🇧 English version (for reference / handover)

Hi team 👋

The assistant **backend is complete and verified live end-to-end**. The only thing left is on the
frontend, and it is not small: **the chat is currently a static demo — it never issues a network
request.**

### What is actually wrong

When the user types a question and hits send, the code makes no `fetch`/`axios` call. It waits
**1.6 seconds** (to look like "thinking") and then returns **the same hardcoded sentence**,
regardless of the question:

> "I have received your message. To see structured responses, use the scenario preview buttons below. In a live integration, I would respond with your verified career context here."

That string lives in the frontend, not the backend. That is why the reply is always identical.

### Where it lives

| File | Line | Function |
|---|---|---|
| `src/components/AssistantPanel.tsx` | **662** | `sendMessage()` |
| `src/components/AssistantView.tsx` | **619** | same pattern |

```tsx
function sendMessage(text: string) {
  if (!text.trim()) return;
  /* … user message added, input cleared, setIsTyping(true) … */
  setTimeout(() => {                      // ← a timer, not a request
    setIsTyping(false);
    const reply: Message = {
      id: `a-${Date.now()}`, role: "assistant", type: "text",
      timestamp: new Date().toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" }),
      text: "I have received your message. …",   // ← hardcoded
    };
    setMessages(prev => [...prev, reply]);
  }, 1600);
}
```

⚠️ Note: **there is no API layer in this project at all** — no `axios`, no `fetch`, no `.env`, no
`import.meta.env`. `src/` contains only `App.tsx`, `components/`, `imports/`, `index.css`,
`main.tsx`, `vite-env.d.ts`. Nothing in the codebase does networking yet.

### The real endpoint

```
POST /api/v1/assistant/ask
```

**Auth:** `auth:sanctum` + `account.active` + `role:learner` → requires a **learner** Bearer token.
An admin token is refused.

**Precondition:** the learner must have a `student_profile`; otherwise `422 STUDENT_PROFILE_NOT_FOUND`
(expected, not a bug).

**Request:**
```json
{ "question": "…", "intent": "explain_readiness" }
```

**Response:** HTTP **201**; the answer is at `data.reply`.

**`intent`** is a **closed six-value whitelist** and also the scope gate (an out-of-scope intent
returns `422`): `explain_readiness`, `explain_skill_gap`, `recommend_next_step`,
`explain_project_match`, `explain_baseline`, `general_guidance`.

### 🔴 One thing to know before you start

**Test the endpoint once with a real learner token before estimating the work.** The backend
**always** sends a `context` snapshot, and the assistant service treats a non-null `context` as
"use verbatim, skip retrieval". So the reply text will often be *"I don't have enough information…"*.
That is a **deliberate, agreed trade-off** (stored values are authoritative; REC-07 forbids a second
paraphrase) — **not a frontend bug**. Your wiring will work; the *reply prose* is currently limited
by design. See `ASSISTANT_CHAT_FRONTEND_HANDOFF.md` §5 and §7 for the full detail and the live
verification log.

### What we need from you

1. Replace the `setTimeout` with a real `fetch` to `POST /api/v1/assistant/ask`.
2. Add a small API layer (one file) — nothing exists today, and you will need it for every feature.
3. Keep the returned `intent` with each message — the Report action needs it
   (`PUT /api/v1/assistant/interactions/{id}/report`).
4. Map `data.reply` to the bubble; `response_status` / `provider_used` are optional.

Ping me with any questions. Good luck 🚀
