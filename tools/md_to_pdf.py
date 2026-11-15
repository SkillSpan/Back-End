"""Render an Arabic (RTL) Markdown document to a print-ready PDF.

Why this exists
---------------
The handoff documents are Arabic, so pandoc/LaTeX would need a full RTL setup.
Headless Chrome already ships with the fonts and the bidi engine, so the whole
job is: Markdown -> HTML (with `dir="rtl"`) -> Chrome print-to-PDF.

Code is forced LTR inside `<code>`/`<pre>` — an identifier reversed by bidi is
worse than no formatting at all, because it looks plausible and is wrong.

Usage
-----
    python tools/md_to_pdf.py docs/SOMETHING.md docs/SOMETHING.pdf

Then check the page count and the per-page character counts it prints: a page
with almost nothing on it is an orphan page, and it is the one defect that is
easy to ship without noticing.
"""

from __future__ import annotations

import os
import re
import shutil
import subprocess
import sys
import tempfile

import markdown
import pymupdf

CHROME_CANDIDATES = [
    r"C:\Program Files\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
]

CSS = """
@page { size: A4; margin: 14mm 12mm; }
* { box-sizing: border-box; }
body {
  font-family: "Segoe UI", "Tahoma", "Arial", sans-serif;
  font-size: 10.5pt; line-height: 1.65; color: #1b1b1b; margin: 0;
}
h1 { font-size: 19pt; margin: 0 0 10px; color: #0f2f4f; border-bottom: 3px solid #0f2f4f; padding-bottom: 6px; }
h2 { font-size: 14.5pt; margin: 20px 0 8px; color: #0f2f4f; border-bottom: 1px solid #cfd8e3; padding-bottom: 3px; }
h3 { font-size: 12pt; margin: 15px 0 6px; color: #14496f; }
p, li { margin: 5px 0; }
ul, ol { padding-inline-start: 20px; }
code {
  font-family: "Consolas", "Courier New", monospace; font-size: 9pt;
  background: #f2f5f9; border: 1px solid #dde5ee; border-radius: 3px;
  padding: 0 3px; direction: ltr; unicode-bidi: embed; display: inline-block;
}
pre {
  background: #f7f9fc; border: 1px solid #dde5ee; border-radius: 4px;
  padding: 8px 10px; overflow-x: auto; margin: 7px 0;
  direction: ltr; text-align: left;
}
pre code { border: 0; background: none; padding: 0; display: block; line-height: 1.45; }
table { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 9.5pt; }
th, td { border: 1px solid #ccd6e0; padding: 4px 7px; text-align: right; vertical-align: top; }
th { background: #eaf1f8; font-weight: 600; }
tr:nth-child(even) td { background: #fafcfe; }
blockquote {
  margin: 8px 0; padding: 6px 12px; border-inline-start: 4px solid #f0b429;
  background: #fffaf0; color: #4a3b12;
}
hr { border: 0; border-top: 1px solid #d7dfe8; margin: 14px 0; }
a { color: #14496f; }
"""

def chrome_path() -> str:
    for candidate in CHROME_CANDIDATES:
        if os.path.exists(candidate):
            return candidate
    raise SystemExit("No Chrome or Edge found to print the PDF.")

def build_html(markdown_text: str, title: str) -> str:
    body = markdown.markdown(
        markdown_text,
        extensions=["tables", "fenced_code", "sane_lists", "attr_list"],
    )
    return (
        "<!DOCTYPE html><html dir=\"rtl\" lang=\"ar\"><head><meta charset=\"utf-8\">"
        f"<title>{title}</title><style>{CSS}</style></head><body>{body}</body></html>"
    )

def render(html_path: str, pdf_path: str) -> None:
    command = [
        chrome_path(),
        "--headless",
        "--disable-gpu",
        "--no-pdf-header-footer",
        "--run-all-compositor-stages-before-draw",
        "--virtual-time-budget=6000",
        f"--print-to-pdf={pdf_path}",
        f"file:///{html_path.replace(os.sep, '/')}",
    ]
    result = subprocess.run(command, capture_output=True, text=True)
    if not os.path.exists(pdf_path) or os.path.getsize(pdf_path) < 1024:
        raise SystemExit(f"Chrome did not write the PDF.\n{result.stdout}\n{result.stderr}")

def report(pdf_path: str) -> None:
    document = pymupdf.open(pdf_path)
    counts = []
    for page in document:
        counts.append(len(re.sub(r"\s+", "", page.get_text())))

    print(f"pages={len(counts)} chars_per_page={counts}")
    if counts:
        print(f"min={min(counts)} last={counts[-1]}")
        if len(counts) > 1 and min(counts) < 200:
            print("WARNING: a page has almost no text on it — probably an orphan page.")
    print(f"size={os.path.getsize(pdf_path)} bytes")

def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit("usage: python tools/md_to_pdf.py <input.md> <output.pdf>")

    source, target = sys.argv[1], sys.argv[2]
    with open(source, encoding="utf-8") as handle:
        text = handle.read()

    title = text.splitlines()[0].lstrip("# ").strip() if text.strip() else "document"
    html = build_html(text, title)

    with tempfile.TemporaryDirectory() as workdir:
        html_path = os.path.join(workdir, "document.html")
        pdf_path = os.path.join(workdir, "document.pdf")
        with open(html_path, "w", encoding="utf-8") as handle:
            handle.write(html)
        render(html_path, pdf_path)
        shutil.copyfile(pdf_path, target)

    report(target)

if __name__ == "__main__":
    main()
