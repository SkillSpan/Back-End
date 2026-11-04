"""Diff every /api/v1 path the frontend calls against the real Laravel route table.

Read-only. Prints the paths the frontend calls that the backend does not serve.

Extraction is deliberately literal-aware: only a COMPLETE string/template
literal is read, and `${...}` becomes `{}` only when it sits in a path-segment
position (`/talent/${id}`). A `${...}` that appends a query string
(`/talent${toQuery(x)}`) is dropped, because it is not part of the path.
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys

ROOT = r"E:\SkillSpan\Back-End-feature-authentication"
FRONT = os.environ.get(
    "FRONT_SRC",
    r"C:\Users\HP\Downloads\SkillSpan-Frontend-FULL-tested\skillspan-frontend\src",
)

LITERAL = re.compile(r"""(['"`])(/api/v1/[^'"`\n]*?)\1""")

# A literal that is only a path *prefix* (`path.startsWith('/api/v1/company')`)
# or that sits in a comment is not a request. Treating them as requests reports
# phantom missing routes.
NOT_A_CALL = re.compile(r"startsWith\(|^\s*(//|\*|/\*)")


def is_call(text: str, match: re.Match[str]) -> bool:
    line_start = text.rfind("\n", 0, match.start()) + 1
    line_end = text.find("\n", match.end())
    line = text[line_start: line_end if line_end != -1 else len(text)]
    return NOT_A_CALL.search(line) is None


def backend_routes() -> set[str]:
    out = subprocess.run(
        ["php", "artisan", "route:list", "--json"],
        cwd=ROOT, capture_output=True, text=True,
    ).stdout
    start = out.find("[")
    if start < 0:
        sys.exit("could not read the route table")
    paths = set()
    for route in json.loads(out[start:]):
        uri = route["uri"]
        if uri.startswith("api/"):
            paths.add(normalise("/" + uri))
    return paths


def normalise(path: str) -> str:
    path = path.split("?")[0]
    path = re.sub(r"\{[^}]*\}", "{}", path)
    # A fixture's literal id (`/projects/7/clone`, `/talent/L9`) is the same
    # route as `/projects/{}/clone`; comparing literals would report every test
    # fixture as a missing route.
    segments = [
        "{}" if re.fullmatch(r"\d+|[A-Za-z]\d+", segment) else segment
        for segment in path.split("/")
    ]
    return "/".join(segments).rstrip("/")


def resolve_templates(raw: str) -> str:
    """`/a/${id}/b` -> `/a/{}/b`; `/a${qs}` -> `/a`."""
    out = ""
    index = 0
    while index < len(raw):
        if raw.startswith("${", index):
            depth = 0
            cursor = index + 1
            while cursor < len(raw):
                if raw[cursor] == "{":
                    depth += 1
                elif raw[cursor] == "}":
                    depth -= 1
                    if depth == 0:
                        break
                cursor += 1
            out += "{}" if out.endswith("/") else ""
            index = cursor + 1
            continue
        out += raw[index]
        index += 1
    return out


def frontend_paths() -> dict[str, set[str]]:
    found: dict[str, set[str]] = {}
    for base, _, files in os.walk(FRONT):
        for name in files:
            if not name.endswith((".js", ".jsx")):
                continue
            with open(os.path.join(base, name), encoding="utf-8", errors="ignore") as handle:
                text = handle.read()
            for match in LITERAL.finditer(text):
                if not is_call(text, match):
                    continue
                path = normalise(resolve_templates(match.group(2)))
                if not path:
                    continue
                found.setdefault(path, set()).add(name)
    return found


def main() -> None:
    backend = backend_routes()
    frontend = frontend_paths()

    missing = {p: f for p, f in frontend.items() if p not in backend}
    # A literal that is a strict prefix of a real route is a concatenation base
    # (`BASE + '/projects'`), not a request of its own.
    prefixes = {p: f for p, f in missing.items() if any(r.startswith(p + "/") for r in backend)}
    missing = {p: f for p, f in missing.items() if p not in prefixes}

    print(f"backend api routes: {len(backend)}")
    print(f"frontend distinct paths: {len(frontend)}")
    print(f"concat bases (not requests): {len(prefixes)}")
    print()
    if not missing:
        print("MISSING: none — every path the frontend calls exists on the backend.")
        return

    print(f"MISSING ({len(missing)}):")
    for path in sorted(missing):
        print(f"  {path:55s} <- {', '.join(sorted(missing[path])[:3])}")


if __name__ == "__main__":
    main()
