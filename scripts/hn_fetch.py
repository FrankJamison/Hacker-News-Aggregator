#!/usr/bin/env python3
"""Fetch and filter Hacker News stories.

This script is designed to be called from PHP and print JSON to stdout.
It uses requests + BeautifulSoup for HTTP + HTML parsing.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import time
from typing import Any, Optional


def _missing_dependency_message(module: str) -> str:
    project_root = os.path.abspath(os.path.join(
        os.path.dirname(__file__), os.pardir))
    req_path = os.path.join(project_root, "requirements.txt")
    py = sys.executable or "python3"
    return (
        "Backend dependency missing: "
        + module
        + "\n"
        + f"Python interpreter: {py}\n"
        + f"Requirements file: {req_path}\n"
        + "Install Python deps for this project (using the SAME python that runs the script):\n"
        + f"  {py} -m pip install -r \"{req_path}\"\n"
        + "If you are on shared hosting and need a user install, try:\n"
        + f"  {py} -m pip install --user -r \"{req_path}\"\n"
        + "If PHP runs a different Python than your SSH shell, set HN_PYTHON (in Apache/PHP env) to the full path of the Python you want,\n"
        + "then install deps using that exact interpreter (example):\n"
        + "  /usr/bin/python3 -m pip install --user -r requirements.txt\n"
    )


try:
    import requests
except ModuleNotFoundError:
    sys.stderr.write(_missing_dependency_message("requests"))
    raise SystemExit(1)

try:
    from bs4 import BeautifulSoup
except ModuleNotFoundError:
    sys.stderr.write(_missing_dependency_message("bs4 (beautifulsoup4)"))
    raise SystemExit(1)

HN_NEWS_URL = "https://news.ycombinator.com/news"


def normalize_hn_link(href: str) -> str:
    if not href:
        return href
    if href.startswith("item?") or href.startswith("from?"):
        return "https://news.ycombinator.com/" + href
    if href.startswith("/"):
        return "https://news.ycombinator.com" + href
    return href


def parse_age_seconds(age_text: str) -> Optional[int]:
    age_text = age_text.strip().lower()
    if not age_text:
        return None

    m = re.match(r"^(\d+)\s+(minute|hour|day)s?\s+ago$",
                 age_text, flags=re.IGNORECASE)
    if not m:
        return None

    value = int(m.group(1))
    unit = m.group(2).lower()

    if unit == "minute":
        return value * 60
    if unit == "hour":
        return value * 3600
    if unit == "day":
        return value * 86400
    return None


def http_get(url: str, user_agent: str, timeout: int = 15) -> tuple[int, str]:
    try:
        r = requests.get(
            url,
            headers={
                "User-Agent": user_agent,
                "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                "Accept-Language": "en-US,en;q=0.9",
                "Cache-Control": "no-cache",
                "Pragma": "no-cache",
            },
            timeout=timeout,
        )
        return int(r.status_code), r.text or ""
    except Exception:
        return 0, ""


def fetch_hn_stories(days: int, min_votes: int, max_pages: int) -> list[dict[str, Any]]:
    cutoff_seconds = days * 86400

    ua1 = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
    ua2 = "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0"

    results: list[dict[str, Any]] = []
    retried_first = False

    for page in range(1, max_pages + 1):
        url = HN_NEWS_URL + "?p=" + str(page)
        status, body = http_get(url, ua1)

        if page == 1 and not retried_first and status in (403, 429):
            retried_first = True
            status, body = http_get(url, ua2)

        if status != 200 or not body:
            break

        soup = BeautifulSoup(body, "html.parser")
        rows = soup.find_all("tr", class_="athing")
        if not rows:
            break

        any_within_cutoff = False

        for row in rows:
            title_link = row.select_one("span.titleline a")
            if not title_link:
                continue

            title = title_link.get_text(strip=True)
            href = normalize_hn_link(title_link.get("href") or "")

            meta_row = row.find_next_sibling("tr")
            if not meta_row:
                continue

            score_el = meta_row.select_one("span.score")
            votes_text = score_el.get_text(strip=True) if score_el else ""
            m = re.match(r"^(\d+)", votes_text)
            votes = int(m.group(1)) if m else 0

            age_el = meta_row.select_one("span.age")
            age_text = age_el.get_text(strip=True) if age_el else ""

            age_seconds = parse_age_seconds(age_text)
            if age_seconds is None:
                continue

            if age_seconds <= cutoff_seconds:
                any_within_cutoff = True
            else:
                continue

            if votes < min_votes:
                continue

            results.append(
                {
                    "title": title,
                    "link": href,
                    "votes": votes,
                    "age_text": age_text,
                }
            )

        if not any_within_cutoff:
            break

    results.sort(key=lambda s: int(s.get("votes") or 0), reverse=True)
    return results


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--days", type=int, default=7)
    ap.add_argument("--min-votes", type=int, default=250)
    ap.add_argument("--max-pages", type=int, default=5)
    args = ap.parse_args(argv)

    days = max(1, min(30, int(args.days)))
    min_votes = max(0, min(5000, int(args.min_votes)))
    max_pages = max(1, min(20, int(args.max_pages)))

    stories = fetch_hn_stories(
        days=days, min_votes=min_votes, max_pages=max_pages)

    payload = {
        "generated_at_utc": time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime()),
        "days": days,
        "min_votes": min_votes,
        "stories": stories,
    }

    # IMPORTANT: Always emit UTF-8 bytes.
    # When this script runs under Apache/PHP on Windows, stdout may use a non-UTF-8
    # code page. Writing bytes avoids encoding mismatches that make PHP json_decode fail.
    out = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    sys.stdout.buffer.write(out)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main(sys.argv[1:]))
    except KeyboardInterrupt:
        raise
    except Exception as e:
        sys.stderr.write(f"Backend error: {e}\n")
        raise SystemExit(1)
