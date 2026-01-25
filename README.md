# 2026 Hacker News Project

Hi — I built this project as a compact, real-world web app that showcases two things recruiters and engineers care about:

1) **A clean user experience** (fast, readable, responsive UI)
2) **Pragmatic engineering** (safe templating, resilient backend execution, predictable deploy story)

It’s a PHP page that renders a filtered list of Hacker News stories, backed by a Python script that fetches and parses HN HTML, then returns JSON to PHP.

## Why I built it

- I wanted a portfolio piece that demonstrates **cross-language integration** (PHP calling Python), not just “CRUD + framework.”
- I focused on practical production issues: **PATH differences under Apache**, dependency install messaging, correct output encoding, and safe escaping.

## What it does

- Fetches stories from Hacker News (up to 5 pages by default).
- Filters by:
  - **Days back** (`days`, default 7; clamped to 1..30)
  - **Minimum votes** (`min_votes`, default 250; clamped to 0..5000)
- Sorts results by vote count (descending).
- Renders a clean, “app-like” list with a polished dark theme.

Example URLs:

- Default: `http://2026hackernewsproject.localhost/`
- Last 3 days, 500+ votes: `http://2026hackernewsproject.localhost/?days=3&min_votes=500`

## Tech stack

- **PHP 8+**: server-rendered HTML + process execution (`proc_open`) + strict output escaping
- **Python 3**: HTTP fetch + parsing + filtering
- **requests** + **beautifulsoup4**: HTTP and HTML parsing
- **Vanilla CSS**: modern, responsive UI built with CSS variables

Dependencies are tracked in `requirements.txt`:

```bash
python -m pip install -r requirements.txt
```

## Architecture (data flow)

There are two runtime pieces:

- Frontend: `index.php`
- Backend script: `scripts/hn_fetch.py`

Request flow:

1) Browser requests `/` (optionally with `?days=…&min_votes=…`).
2) `index.php` validates/clamps input and starts a Python subprocess.
3) `scripts/hn_fetch.py` fetches and parses HTML from `https://news.ycombinator.com/news?p=1..N`.
4) Python prints UTF-8 JSON to stdout.
5) PHP decodes JSON and renders the story list.

This intentionally keeps the PHP surface area small and makes the backend script independently runnable (useful for debugging and future API work).

## Design + UI decisions

I designed the UI to feel like a lightweight “reader” app:

- **Design system via CSS variables** (`:root`) so the theme is coherent and easy to adjust.
- **Readable typography + spacing** for scanning headlines quickly.
- **Accessible focus states** (`:focus-visible` ring) so keyboard users aren’t second-class.
- **Reduced motion support** via `@media (prefers-reduced-motion: reduce)`.
- **Responsive layout**: cards scale cleanly from mobile to desktop.

Styling lives in `css/styles.css`.

## Engineering decisions (what I did on purpose)

### Safe server rendering

- All user and dynamic output is escaped through a dedicated helper (`e()` wraps `htmlspecialchars`).
- Links open in a new tab with `rel="noreferrer"`.

### Resilient Python execution from PHP

Real deployments frequently fail because the Apache service user doesn’t have the same PATH as your interactive shell. I handled that explicitly:

- PHP prefers `HN_PYTHON` if set (via server env), otherwise tries a candidate list (`py -3`, `python`, `python3`, and common absolute paths).
- Uses `proc_open()` **array argv form** so Windows paths with spaces work reliably.
- Captures stdout/stderr and returns helpful error messages to the page (plus install hints when deps are missing).

### Predictable backend output

- Python emits **UTF-8 bytes** (`sys.stdout.buffer.write(...)`) to avoid Windows/Apache encoding issues that can break `json_decode`.
- Backend prints a stable JSON schema:

```json
{
  "generated_at_utc": "YYYY-MM-DD HH:MM:SS",
  "days": 7,
  "min_votes": 250,
  "stories": [
    { "title": "…", "link": "…", "votes": 1234, "age_text": "3 hours ago" }
  ]
}
```

### Performance guardrails

- Limits the number of pages fetched (`--max-pages`, default 5).
- Stops early when it detects pages no longer contain stories inside the cutoff window.

### Caching strategy

- I intentionally send **no-cache** headers in PHP so refreshes always fetch fresh results.
- The CSS link gets a cache-busting version string based on file modification time.

## Local setup (Windows + XAMPP)

### 1) Install Python dependencies

From the project root:

```bash
python -m pip install -r requirements.txt
```

### 2) Ensure Apache can find Python

If Apache/PHP can’t find your Python, set `HN_PYTHON` to the full interpreter path.

Example (XAMPP VirtualHost / Apache config):

```apache
SetEnv HN_PYTHON "C:/Path/To/python.exe"
```

Restart Apache after changing env vars.

### 3) Serve the project

Point an Apache VirtualHost to this folder and browse:

`http://2026hackernewsproject.localhost/`

Note: `.localhost` typically resolves to `127.0.0.1` without editing your hosts file.

## Production notes

- This project does not require a virtualenv, but **you must install Python packages into the exact Python interpreter Apache will run**.
- On Linux, `HN_PYTHON=/usr/bin/python3` is a common, reliable option.
- On shared hosting, `proc_open()` or Python package installs may be restricted; VPS is the most reliable deployment path.

## Troubleshooting

If the UI shows a backend error, the page prints stderr details to speed up diagnosis.

Common issues:

- **Interpreter not found** (exit code 127 / 9009): set `HN_PYTHON` to an absolute Python path.
- **Missing Python deps** (`No module named requests` or `bs4`):

```bash
python -m pip install -r requirements.txt
```

Quick verification (run with the same Python you configured / Apache uses):

```bash
python -c "import requests; import bs4; print('ok')"
```

If your host requires a user install:

```bash
python -m pip install --user -r requirements.txt
```

If you can run Python but installs keep going into the wrong user's site-packages (common with `sudo` or when Apache runs under a different user), use a project-local install instead:

```bash
python3 -m pip install --target pydeps -r requirements.txt
```

The PHP runner automatically adds `pydeps/` to `PYTHONPATH` when it exists.

If you still see missing-module errors, it almost always means Apache/PHP is running a different Python than your shell. Set `HN_PYTHON` to an absolute interpreter path and install deps using that exact interpreter.

## What I’d improve next

- Add a lightweight server-side cache (e.g., cache JSON for 2–5 minutes) to reduce repeated scraping.
- Add automated tests for parsing edge cases (HN markup changes).
- Add an optional JSON endpoint (same payload, but `Content-Type: application/json`) for reuse.
- Add more filters (domain allow/deny list, title keyword search) while keeping validation strict.
