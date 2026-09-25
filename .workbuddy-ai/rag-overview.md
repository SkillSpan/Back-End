# RAG in the chatbot service — what was built

**Status: retrieval is complete and inert.** The pipeline is written, wired and tested.
It grounds nothing yet, because the documentation corpus still does not exist. That is
the one thing that was blocked before and is still blocked now.

Scope: `E:\SkillSpan\chatbot` (the FastAPI chatbot service). The Laravel repo is unchanged
in this step.

---

## What was added

| File | Responsibility |
|---|---|
| `rag/chunking.py` | Paragraph packing → sentence splitting → hard-split fallback, with overlap. Pure: no network, no model |
| `rag/embeddings.py` | `Embedder` Protocol + `GeminiEmbedder`. `RETRIEVAL_DOCUMENT` and `RETRIEVAL_QUERY` kept as separate instances |
| `rag/index.py` | `VectorIndex`: build / save / load / cosine search. A JSON file, in-process |
| `rag/retrieval.py` | The request-path `Retriever`, and the failure policy |
| `rag/ingest.py` | CLI: `--stats` (free, no API), `--check` (verify the model), default (build + save) |
| `CORPUS.md` | What belongs in the corpus, who writes it, and what must never go in it |
| `corpus/` | The corpus itself. Empty, with a `.gitkeep` |

Modified: `main.py` (retrieval wired into `/chat`; `retrieval` added to `/health`),
`models.py` (`HealthResponse.retrieval`), `config.py` (RAG settings +
`retrieval_configured`), `prompt.py` (stale "RAG not wired up" docstring corrected),
`.env.example`, `.gitignore`, `render.yaml`, `README.md`, `conftest.py`, `pytest.ini`.

## The design decisions that matter

**Fail soft, always.** Retrieval is an enhancement, never a dependency. A missing index, a
stale index, an index built by another embedding model, or an embedding-provider outage all
return no context — and the chat turn still succeeds. A retrieval outage must never turn a
working chat into a 500. This is the single most important property in
`tests/test_retrieval.py`.

**A caller-supplied `context` wins.** Retrieval only fills the gap. This is what keeps the
change additive: an existing caller that sends its own context sees byte-identical behaviour.

**An index built by a different model is refused at load.** Mismatched vectors do not raise
— they return plausible nonsense, which is the worst possible failure mode for a system whose
whole job is grounding. So `index.json` records its model, and `/health` reports
`unavailable` rather than quietly retrieving garbage.

**Off by default.** With `RAG_ENABLED` unset, `/health` reports `retrieval: "off"` and `/chat`
behaves exactly as it did before retrieval existed. A deployment cannot accidentally ground in
a half-built corpus.

**No new vendor.** Embeddings use Gemini (`text-embedding-004`) via the `GEMINI_API_KEY` the
service already holds for chat.

## ⚠️ Two things worth escalating

**1. RAG creates a new external-data flow, so §12.5 applies to the corpus.** Before retrieval
the service sent Gemini only the user's typed message. After retrieval it also sends
**whatever is in the corpus**, on every question. That is a new insertion of data into a
third-party model, and §12.5 governs it. The corpus is therefore constrained like learner
data: no secrets, no credentials, no learner data, no internal-only material.

`CORPUS.md` is deliberately placed **outside** `corpus/`. Anything inside `corpus/` is
ingested and becomes grounding text — including a guidelines file, which would end up quoted
back as if it were platform documentation.

**2. Deployment gap.** `index.json` is gitignored (a derived artifact; committing it lets a
stale index outlive the docs it describes). A fresh Render instance has no index, so
`RAG_ENABLED=true` alone produces `retrieval: "unavailable"` and ungrounded answers. Once the
corpus exists, the build command must run `python -m rag.ingest`. Documented in `README.md`
and flagged in `render.yaml`.

Also fixed while there: `render.yaml` was missing `SERVICE_TOKEN`, which the README
instructed you to add but which was never actually in the Blueprint.

## Verification

```
88 passed        # pytest -q  (was 29 before this work)
```

- `python -m rag.ingest --stats` on a two-document corpus → reports 2 chunks from 2
  documents with a size distribution, writes nothing. Verified.
- The same command against the real, empty `corpus/` → exits 2 and states that nothing was
  built or changed. Verified.
- All modules import cleanly; `rag_enabled=False`, `retrieval_configured=False` with the
  current `.env`. Verified.

New test modules: `tests/test_chunking.py`, `tests/test_index.py`, `tests/test_retrieval.py`.
`conftest.py` now provides a deterministic `FakeEmbedder`, so the retrieval suites assert
*why* one chunk outranks another rather than snapshotting a model's output — and need no API
key or network.

## Still blocked — unchanged from before

1. **The corpus.** Content ownership. Nobody can write the Readiness Score weights except the
   people who own the Readiness Score. Until then this code retrieves nothing.
2. **The access architecture.** Whether the browser keeps calling the service directly.
   This still determines whether the Laravel §12.5 gate is real.
3. **Where learner data goes.** Still unanswered, so the Laravel `AssistantClient` remains
   deliberately unwired.

## To make retrieval actually work

1. Someone writes and approves documentation into `corpus/` (see `CORPUS.md`).
2. `python -m rag.ingest --stats` → confirm the chunking looks sane.
3. `python -m rag.ingest --check` → confirm the embedding model responds.
4. `python -m rag.ingest` → build the index.
5. `RAG_ENABLED=true`, restart, and confirm `/health` reports `retrieval: "ready"`.
