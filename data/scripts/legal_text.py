"""Page text of downloaded PDFs, for checking that a cited passage is really in the source.

A quote counts as found when it is a substring of a page's text after both are normalised:
whitespace collapsed, soft hyphens and PDF hyphenation marks removed, and curly quotes and dashes
straightened. Nothing else is fuzzy - a quote that is not in the text is reported as not found.
"""

from __future__ import annotations

import re
from functools import lru_cache

import pypdfium2 as pdfium

from common import DATA

_TRANS = str.maketrans({"‘": "'", "’": "'", "“": '"', "”": '"', "–": "-", "—": "-",
                        " ": " "})


def norm(text: str) -> str:
    text = text.replace("￾", "").replace("­", "").replace("\u0002", "").translate(_TRANS)
    return " ".join(text.split())


@lru_cache(maxsize=None)
def pages(rel: str) -> tuple[str, ...]:
    doc = pdfium.PdfDocument(str(DATA / rel))
    try:
        return tuple(norm(doc[i].get_textpage().get_text_range()) for i in range(len(doc)))
    finally:
        doc.close()


def find(rel: str, quote: str, page: int | None = None) -> int | None:
    """1-based page where the quote appears (the given page first), or None."""
    q = norm(quote)
    ps = pages(rel)
    if page and 1 <= page <= len(ps) and q in ps[page - 1]:
        return page
    return next((i + 1 for i, t in enumerate(ps) if q in t), None)
