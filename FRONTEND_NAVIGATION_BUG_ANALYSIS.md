# Frontend Navigation Bug — Root Cause Analysis

**Analyzed build:** `skillspan-dashboard-navigation-fixed.zip` → `Front-end-main/`
**Files changed most recently (2026-10-01 10:46):** `src/App.jsx`, `src/components/dashboard/Dashboard.jsx`, `src/components/skillmatrix/SkillMatrix.jsx`
**Date:** 2026-10-01

> This report describes **frontend** defects only. Nothing here is a backend/API problem —
> the API layer (`src/api.js`) is correctly wired and all backend endpoints it calls exist and work.

---

## ملخّص سريع بالعربي (Quick summary in Arabic)

**المشكلة اللي بتقول "الـ navigation بايظ":** الـ sidebar بيتنقّل فعلاً في الـ URL، لكن الشاشة
بتفضل زي ما هي — المستخدم بيتعلّق على أول صفحة فتحها. الـ refresh "بيصلّحها" بس مؤقتاً، فبتبان
كأنها مشكلة cache، **وهي مش كده**.

**السبب في ملفين بس:**
1. `src/App.jsx` — الـ 8 routes كلهم بيعملوا render لـ لنفس الـ component (`WorkspacePage`)،
   فـ React بيعمل **reuse** للـ component ومش بيعمل remount.
2. `src/components/learner/LearnerWorkspace.jsx` سطر 23 —
   `const [view, setView] = useState(initialView)` بيقرأ الـ prop **مرة واحدة بس** وبيتجاهل أي
   تغيير بعده. فالـ prop الجديد بيتم رميه **في صمت**.

**الحل:** يا إما تشتق `view` من الـ URL بدل الـ state (الأفضل — تفاصيل في Bug 1 Option A)،
يا إما تضيف `key="..."` لكل route في `App.jsx` (أسرع تعديل — Option B).

⚠️ **وكمان:** الـ `/dashboard` كله داتا وهمية (`MOCK`) — مش بيعمل ولا API call. وقدّام
"Roadmap Progress" و "Next Best Action" **مالهمش Laravel route** بيعرّضهم للفرونت دلوقتي
(الـ algorithm نفسه موجود في FastAPI بس مقفول بـ flag) — التفاصيل في Bug 2.

---

## TL;DR

| # | Severity | Issue | File |
|---|---|---|---|
| 1 | 🔴 **Critical** | Sidebar navigation silently stops working — clicking any of the 8 workspace links keeps rendering the page you are already on | `src/App.jsx` + `src/components/learner/LearnerWorkspace.jsx` |
| 2 | 🟠 High | The entire `/dashboard` screen is hardcoded mock data — it calls no API at all | `src/components/dashboard/Dashboard.jsx` |
| 3 | 🟡 Medium | `/evidence` and `/record` show the Career Journey screen — data loss on refresh; misleading URL | `src/App.jsx` |
| 4 | 🟡 Medium | Sidebar "active" highlight is wrong for 3 routes | `src/components/learner/LearnerWorkspace.jsx` |
| 5 | 🔵 Low | No test covers navigation at all, which is why #1 shipped | `src/__tests__/` |

---

## Bug 1 🔴 — Sidebar navigation stops working (the reported "navigation" problem)

### Symptom

From any learner page, clicking a sidebar link (Career Journey, Projects, Assistant, Mentoring,
Talent, Add Evidence, My Record, Career Roles) **changes the URL but not the screen**. The user is
stuck on whatever page they first landed on. A hard refresh at the new URL shows the correct page,
which makes it look intermittent and cache-related — it is not.

It also works the *first* time if you arrive from `/dashboard`, because `/dashboard` is a
**different component** (`Dashboard`), so React is forced to unmount and mount. Every navigation
*after* that is broken.

### Root cause

React compares elements by **type + position in the tree**. If both are unchanged between renders,
React **reuses the component instance** — it does not remount it. The two files below combine to
make that reuse lose the navigation intent.

**Step 1 — `src/App.jsx` (lines 175–190): all 8 workspace routes render the same component type.**

```jsx
<Route path="/career-journey" element={<RequireAuth><WorkspacePage view="career-journey" /></RequireAuth>} />
<Route path="/projects"       element={<RequireAuth><WorkspacePage view="projects"       /></RequireAuth>} />
<Route path="/assistant"      element={<RequireAuth><WorkspacePage view="assistant"      /></RequireAuth>} />
<Route path="/mentor"         element={<RequireAuth><WorkspacePage view="mentor"         /></RequireAuth>} />
<Route path="/talent"         element={<RequireAuth><WorkspacePage view="talent"         /></RequireAuth>} />
<Route path="/evidence"       element={<RequireAuth><WorkspacePage view="evidence"       /></RequireAuth>} />
<Route path="/record"         element={<RequireAuth><WorkspacePage view="record"         /></RequireAuth>} />
<Route path="/career-roles"   element={<RequireAuth><WorkspacePage view="career-roles"   /></RequireAuth>} />
```

Every one of these is `<WorkspacePage>` at the same position under `<Routes>`. Only the prop
`view` differs. React sees "same type, same position" → **reuses the instance**, only updates props.

**Step 2 — `src/components/learner/LearnerWorkspace.jsx` (line 23): the prop is read exactly once.**

```jsx
export default function LearnerWorkspace({ initialView = 'career-journey', onNavigate, onLogout }) {
  const { authUser } = useAuth();
  const [view, setView] = useState(initialView);   // ← initialized from the prop, ONCE
  ...
```

`useState(initialValue)` reads its argument **only on the first render of a mounted instance**.
Every later render ignores it completely. So when React reuses the instance and passes a new
`initialView`, that new value is **silently discarded** — `view` state keeps its old value and the
old page keeps rendering.

This is the documented React behaviour ("avoid unnecessary state", "you might not need an
Effect"), not a library bug.

### Minimal reproduction

```jsx
function LearnerWorkspace({ initialView }) {
  const [view, setView] = React.useState(initialView);   // read once, then ignored
  return <div>rendered view = {view}</div>;
}

// Same element type at the same tree position -> React reconciles, does not remount.
function App({ route }) {
  const view = route === '/projects' ? 'projects' : 'career-journey';
  return <LearnerWorkspace initialView={view} />;
}
```

Navigate `'/career-journey'` → `'/projects'` and the output stays `rendered view = career-journey`.

### The fix — pick ONE (option A is preferred)

**Option A — derive the view from the URL; delete the duplicate state.**
One source of truth, back/forward buttons start working correctly, and deep links are always right.

First, in `src/App.jsx`, add the reverse map next to the existing `NAV_ROUTES` (they must stay in
sync — `NAV_ROUTES` maps `key → path`, this maps `path → key`):

```jsx
// src/App.jsx
export const VIEW_TO_PATH = {
  dashboard:        '/dashboard',
  'career-journey': '/career-journey',
  'career-roles':   '/career-roles',
  projects:         '/projects',
  assistant:        '/assistant',
  mentor:           '/mentor',
  talent:           '/talent',
  evidence:         '/evidence',
  record:           '/record',
};

// path -> view, derived so the two can never drift apart
export const PATH_TO_VIEW = Object.fromEntries(
  Object.entries(VIEW_TO_PATH).map(([key, path]) => [path, key])
);
```

Then `LearnerWorkspace` reads the view from the URL instead of a prop:

```jsx
// src/components/learner/LearnerWorkspace.jsx
import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { VIEW_TO_PATH, PATH_TO_VIEW } from '../../App';   // or move both maps to a shared module

export default function LearnerWorkspace({ onNavigate, onLogout }) {
  const location = useLocation();
  const navigate = useNavigate();

  // Derived from the URL — NOT state. Nothing can get out of sync.
  const view = PATH_TO_VIEW[location.pathname] ?? 'career-journey';

  const [projectDetails, setProjectDetails] = useState(false);
  // Reset the Projects sub-state whenever the route changes.
  useEffect(() => { setProjectDetails(false); }, [view]);

  const go = (key) => {
    if (key === 'roles') key = 'career-roles';          // sidebar key -> view key
    const path = VIEW_TO_PATH[key];
    if (!path) return;                                   // unmapped -> stay put (as today)
    if (path === location.pathname) return;              // no-op click
    onNavigate ? onNavigate(key) : navigate(path);
  };

  // ...rest unchanged, except `view` is now a derived const, so
  // delete the `const [view, setView] = useState(initialView)` line.
}
```

> ⚠️ `LearnerWorkspace.jsx` currently imports nothing from `App.jsx`. Importing `App.jsx` from a
> deep component creates a **circular import** (`App.jsx` → `LearnerWorkspace` → `App.jsx`). Put
> both maps in a small shared module instead — e.g. `src/navRoutes.js` — and import them from both
> files. React tolerates the cycle in practice, but bundlers and tests will be happier without it.

**Option B — force a remount with an explicit `key`.**
One line in `App.jsx`, keeps the existing internal state model. Less correct (throws away the data
already fetched inside `LearnerWorkspace` on every navigation) but the smallest possible diff.

```jsx
// src/App.jsx — add key={view} to each WorkspacePage
<Route path="/projects" element={<RequireAuth><WorkspacePage key="projects" view="projects" /></RequireAuth>} />
```

> If Option B is chosen, note the workspace re-fetches all 8 endpoints on **every** sidebar click
> (see `LearnerWorkspace.jsx` lines 34–57). Option A avoids that.

---

## Bug 2 🟠 — `/dashboard` is 100% mock data

`src/components/dashboard/Dashboard.jsx` renders a hardcoded `MOCK` object (line 10) and **makes no
API call whatsoever** — the only match for `useEffect|fetch|api.` in the whole file is the word
inside a comment. Its own header admits this:

```jsx
// NOTE: there is no backend endpoint yet for readiness score / skill gaps /
// recommended projects (see api.js - only auth + profile + readiness/latest
// exist so far, and readiness/latest doesn't return this shape).
```

**This comment is now out of date — the backend endpoints exist and work.** `src/api.js` already
exports the functions needed, and I verified every one of these routes against the live Laravel
route table:

| Dashboard field | Real backend source | Verified |
|---|---|---|
| Readiness score + role label | `GET /api/v1/readiness/latest` | ✅ exists |
| Active projects | `GET /api/v1/projects` | ✅ exists |
| Recommended projects (with match %) | `GET /api/v1/recommendations` | ✅ exists |
| Skill gaps | `GET /api/v1/skills/matrix` | ✅ exists |
| Intelligence / gap detail | `GET /api/v1/intelligence/latest` | ✅ exists |
| Record items | `GET /api/v1/evidence` | ✅ exists |
| Notifications / unread count | `GET /api/v1/notifications` + `/unread-count` | ✅ exists |
| "Next Best Action" / roadmap phase | ⚠️ **no endpoint exists** | ❌ **see below** |

> ⚠️ **Correction (2026-10-01, verified against the deployed FastAPI OpenAPI).** An earlier draft of
> this report said *"there is no roadmap endpoint at all"*. That was **wrong**, and the correction
> matters:
>
> - **FastAPI DOES have `POST /api/v1/roadmap`** — it is live and published (confirmed in
>   `https://skillspan-intelligence.onrender.com/openapi.json`).
> - **Laravel DOES have the client for it** — `IntelligenceClient::generateRoadmap()`, called from
>   `IntelligenceService` line 197, with `toRoadmapRequest()` mapping the internal payload onto the
>   service's `RoadmapRequest` contract (`learner` / `role` / `skills`).
> - **What is genuinely missing is only the HTTP surface:** there is no Laravel route that exposes
>   roadmap data to a *client*. `php artisan route:list` has no `roadmaps/*` and no
>   `next-best-action`. Roadmap generation runs **inside** `POST /api/v1/intelligence/calculate` as
>   an enrichment step — the result is consumed internally, not returned as its own resource.
> - **And it is flag-gated off:** `DATA_SCIENCE_ROADMAP_ENABLED` defaults to `false`
>   (`config/services.php` → `data_science.roadmap_enabled`), so generation is skipped entirely
>   today unless explicitly enabled.
>
> **So the accurate statement for the two dashboard tiles is:** they cannot be wired *today*, but
> the blocker is **not** a missing algorithm — it is (a) missing Laravel HTTP routes and (b) a
> disabled flag. Those are cheap to add, which is good news for the frontend. Confirm with the
> backend owner before promising a live roadmap widget.

**Recommendation:** stop maintaining two dashboards. `LearnerWorkspace` already assembles real data
from 8 endpoints. Either (a) make `/dashboard` render `<LearnerWorkspace view="career-journey" />`
so there is one data-driven screen, or (b) wire `Dashboard.jsx` to the calls above. Right now
`/dashboard` and `/career-journey` show *different* content for the *same* signed-in user, which is
confusing on its own.

---

## Bug 3 🟡 — `/evidence` and `/record` render the Career Journey screen

```jsx
// src/components/learner/LearnerWorkspace.jsx lines 82-83
if (view === 'evidence') return <CareerJourneyView readiness={readiness} />;
if (view === 'record')   return <CareerJourneyView readiness={readiness} />;
```

Both fall through to `CareerJourneyView`. Two separate problems:

1. **No dedicated view exists** for either — these are placeholders that were never filled in.
   The sidebar (`AppLayout.jsx` lines 24, 26) advertises "Add Evidence" and "My Record" as if they
   were real destinations.
2. **The URL lies.** `/evidence` shows a Career Journey page. A user who bookmarks or shares that
   URL gets the wrong screen, and it will be indexed/backed-up as such.

The real backend for both already exists (`POST/GET /api/v1/evidence`). Either build the views or
remove the two nav items until they exist — an advertised link that goes nowhere is worse than a
missing one.

---

## Bug 4 🟡 — Sidebar highlight is wrong for 3 routes

```jsx
// src/components/learner/LearnerWorkspace.jsx line 89
active={view === 'career-roles' ? 'roles' : view}
```

`AppLayout.jsx` matches `active === item.key` against keys:
`dashboard · career-journey · roles · skill-matrix · evidence · projects · record · assistant · mentor · talent`

Only `career-roles → roles` is remapped. The `view` values this component can hold are
`career-journey · projects · assistant · mentor · talent · evidence · record · career-roles`, so
`dashboard` is **never** passed as `active` from here — when the user is on `/dashboard`, the
*other* component (`Dashboard.jsx`) renders it. Low impact, but it is a symptom of the same
"two different navigations for one app" duplication as Bug 2.

`/skill-matrix` is also rendered by a **separate** page component (`SkillMatrixPage`), not by
`LearnerWorkspace`, so its sidebar highlight and its `onNavigate` wiring are a third independent
code path. Worth unifying while fixing Bug 1.

---

## Bug 5 🔵 — Nothing tests navigation

`src/__tests__/` contains `OtpVerification.test.jsx` and `api.test.js` only. There is **no test that
mounts the app at `/career-journey` and clicks through to `/projects`** — which is exactly the flow
that is broken. Bug 1 is invisible to the current suite by construction.

A regression test for Bug 1 is ~15 lines with `@testing-library/react` (already a devDependency):

```jsx
test('sidebar navigation changes the rendered view', async () => {
  window.history.pushState({}, '', '/career-journey');
  render(<App />);
  await userEvent.click(screen.getByRole('button', { name: /Projects/i }));
  expect(await screen.findByText(/projects/i)).toBeInTheDocument();
});
```

---

## Suggested priority

1. **Bug 1** — blocks the entire learner experience. One-line fix (Option B) or a small refactor (Option A).
2. **Bug 2** — ships fake numbers to real users; misleads the client reviewing the demo.
3. **Bug 3** — advertised links that do not exist.
4. **Bug 4** — cosmetic, fix alongside Bug 1.
5. **Bug 5** — add the test with the Bug 1 fix so it cannot regress.

---

## What is NOT broken (verified)

- `src/api.js` — the API layer, base URL, auth header and error handling are correct.
- `src/main.jsx` — `BrowserRouter` + `StrictMode` are set up correctly.
- `NAV_ROUTES` in `src/App.jsx` covers all 10 sidebar keys, so the *mapping* is complete — the
  breakage is purely the React state-reuse issue in Bug 1.
- `AppLayout` renders the correct `active` class mechanics; the values passed to it are what is off.
- The `RequireAuth` guard, `SessionExpired` takeover, and 401 handling are correctly structured.
