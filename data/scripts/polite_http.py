"""Polite HTTP for the dataset track (brief rule 5), shared by discovery (D1) and downloads (D2).

Every request made by the pipeline goes through PoliteSession, which:
  - identifies itself with a descriptive User-Agent (no personal contact details);
  - obeys robots.txt for that User-Agent, per RFC 9309: a missing robots.txt (4xx) allows all,
    an unreachable one (5xx / network error) is treated as "disallow everything";
  - waits at least MIN_INTERVAL seconds between requests to the same host;
  - retries transient failures (429, 5xx, connection errors) with exponential backoff and
    honours Retry-After.
It never follows a login, never solves a CAPTCHA and never disables TLS verification: a source
that needs any of that is a manual step, not something to route around.
"""

from __future__ import annotations

import time
import urllib.robotparser
from dataclasses import dataclass
from html.parser import HTMLParser
from urllib.parse import urljoin, urlsplit

import requests
import truststore
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

# Verify TLS with the operating system's certificate verifier instead of Python's bundled one.
# Several Indian government sites (egazette.gov.in, nclcil.in) do not send their full certificate
# chain; browsers and the Windows verifier fetch the missing intermediate, Python's default does
# not, and fails. This keeps verification fully ON - an expired or forged certificate is still
# rejected (tested against expired.badssl.com on 2026-09-25) - it only verifies the way a browser does.
truststore.inject_into_ssl()

UA_TOKEN = "CoalShield-DataTrack"
USER_AGENT = f"{UA_TOKEN}/0.1 (SIH26024 academic prototype; research use; max 1 request/s per host)"
MIN_INTERVAL = 1.0  # seconds between requests to one host
TIMEOUT = 30        # seconds per request


class RobotsDisallowed(Exception):
    """robots.txt forbids this URL for our User-Agent."""


@dataclass
class Probe:
    """What a HEAD (or header-only GET) says about a URL, without downloading its body."""
    url: str
    final_url: str | None
    status: int | None
    size_bytes: int | None
    content_type: str | None
    last_modified: str | None
    method: str
    error: str | None = None


class PoliteSession:
    def __init__(self, min_interval: float = MIN_INTERVAL, timeout: float = TIMEOUT):
        self.min_interval = min_interval
        self.timeout = timeout
        self._last_hit: dict[str, float] = {}
        self._robots: dict[str, urllib.robotparser.RobotFileParser | None] = {}
        self.session = requests.Session()
        self.session.headers["User-Agent"] = USER_AGENT
        retry = Retry(
            total=4, connect=3, read=3, backoff_factor=2,
            status_forcelist=(429, 500, 502, 503, 504),
            allowed_methods=frozenset({"HEAD", "GET"}),
            respect_retry_after_header=True, raise_on_status=False,
        )
        adapter = HTTPAdapter(max_retries=retry)
        self.session.mount("https://", adapter)
        self.session.mount("http://", adapter)

    # --- pacing -------------------------------------------------------------------------
    def _wait(self, host: str) -> None:
        last = self._last_hit.get(host)
        if last is not None:
            gap = time.monotonic() - last
            if gap < self.min_interval:
                time.sleep(self.min_interval - gap)
        self._last_hit[host] = time.monotonic()

    # --- robots.txt ---------------------------------------------------------------------
    def robots_allows(self, url: str) -> tuple[bool, str]:
        parts = urlsplit(url)
        origin = f"{parts.scheme}://{parts.netloc}"
        if origin not in self._robots:
            parser = urllib.robotparser.RobotFileParser()
            robots_url = origin + "/robots.txt"
            self._wait(parts.netloc)
            try:
                resp = self.session.get(robots_url, timeout=self.timeout)
                if resp.status_code >= 500:
                    self._robots[origin] = None           # unreachable: disallow all
                elif resp.status_code >= 400:
                    parser.parse([])                      # no robots.txt: allow all
                    self._robots[origin] = parser
                else:
                    parser.parse(resp.text.splitlines())
                    self._robots[origin] = parser
            except requests.RequestException:
                self._robots[origin] = None
        parser = self._robots[origin]
        if parser is None:
            return False, "robots.txt unreachable - treated as disallow (RFC 9309)"
        if parser.can_fetch(UA_TOKEN, url):
            return True, "allowed"
        return False, "disallowed by robots.txt"

    # --- requests -----------------------------------------------------------------------
    def request(self, method: str, url: str, **kwargs) -> requests.Response:
        ok, why = self.robots_allows(url)
        if not ok:
            raise RobotsDisallowed(f"{url}: {why}")
        self._wait(urlsplit(url).netloc)
        kwargs.setdefault("timeout", self.timeout)
        kwargs.setdefault("allow_redirects", True)
        return self.session.request(method, url, **kwargs)

    def get(self, url: str, **kwargs) -> requests.Response:
        return self.request("GET", url, **kwargs)

    def probe(self, url: str) -> Probe:
        """Status, final URL, size and type of a URL, without downloading the body.

        HEAD first. Any HEAD that is not a success, or omits the size, is retried as a 1-byte
        ranged GET, whose Content-Range carries the full size. That matters: dgms.gov.in and
        egazette.gov.in answer HEAD with a 404 page for files a GET serves normally, so trusting
        HEAD alone reports real documents as missing.
        """
        try:
            resp = self.request("HEAD", url)
            method = "HEAD"
            size = resp.headers.get("Content-Length")
            if resp.status_code >= 400 or size is None:
                resp = self.request("GET", url, headers={"Range": "bytes=0-0"}, stream=True)
                method = "GET(range)"
                total = resp.headers.get("Content-Range", "").rpartition("/")[2]
                size = total if total.isdigit() else resp.headers.get("Content-Length")
                if resp.status_code == 200:   # server ignored Range: report its full length
                    size = resp.headers.get("Content-Length")
                resp.close()
            return Probe(url, resp.url, resp.status_code,
                         int(size) if size and str(size).isdigit() else None,
                         resp.headers.get("Content-Type"), resp.headers.get("Last-Modified"),
                         method)
        except RobotsDisallowed as exc:
            return Probe(url, None, None, None, None, None, "robots", error=str(exc))
        except requests.RequestException as exc:
            return Probe(url, None, None, None, None, None, "error",
                         error=f"{type(exc).__name__}: {exc}"[:300])


class _LinkParser(HTMLParser):
    def __init__(self, base: str):
        super().__init__()
        self.base, self.links, self._href, self._text = base, [], None, []

    def handle_starttag(self, tag, attrs):
        if tag == "a":
            self._href = dict(attrs).get("href")
            self._text = []

    def handle_data(self, data):
        if self._href is not None:
            self._text.append(data)

    def handle_endtag(self, tag):
        if tag == "a" and self._href is not None:
            self.links.append((urljoin(self.base, self._href), " ".join("".join(self._text).split())))
            self._href = None


def extract_links(html: str, base_url: str) -> list[tuple[str, str]]:
    """(absolute URL, link text) for every <a href> on a page."""
    parser = _LinkParser(base_url)
    parser.feed(html)
    return parser.links
