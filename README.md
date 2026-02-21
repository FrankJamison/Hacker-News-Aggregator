# Hacker News Aggregator

Live preview: https://hackernews.fcjamison.com/

A compact, developer-friendly web app that aggregates Hacker News stories.

- PHP renders the page and runs a backend subprocess.
- Python fetches/parses Hacker News HTML and emits JSON to stdout.

This repo is intentionally small and framework-light: it’s designed to be easy to read, easy to deploy on a basic PHP host, and easy to debug when “it works locally but not under Apache”.

## Features

- Fetches HN stories (first 5 pages by default).
- Filters:
  - `days` (default `7`, clamped to `1..30`)
  - `min_votes` (default `250`, clamped to `0..5000`)
- Sorts by votes (descending).
- Responsive, dark UI.

Example URLs:

- Default: `/`
- Last 3 days, 500+ votes: `/?days=3&min_votes=500`

## Architecture

Request/data flow:

1. Browser requests `/` (optionally with query params).
2. [index.php](index.php) clamps/validates input and runs the Python backend via `proc_open()`.
3. [scripts/hn_fetch.py](scripts/hn_fetch.py) downloads `https://news.ycombinator.com/news?p=1..N`, parses it with BeautifulSoup, filters, sorts, then prints UTF-8 JSON.
4. PHP decodes JSON and server-renders the list.

### Backend JSON shape

The Python script prints a single JSON object like:

```json
{
  "generated_at_utc": "2026-02-21 17:04:11",
  "days": 7,
  "min_votes": 250,
  "stories": [
    {
      "title": "Some title",
      "link": "https://example.com",
      "votes": 1234,
      "age_text": "3 hours ago"
    }
  ]
}
```

## Tech stack

- PHP 8+
- Python 3
- Python deps in [requirements.txt](requirements.txt): `requests`, `beautifulsoup4`
- UI styling in [css/styles.css](css/styles.css)

## Quick start

1. Install Python dependencies

```bash
python -m pip install -r requirements.txt
```

2. Run the PHP app (dev server)

```bash
php -S 127.0.0.1:8080
```

Open http://127.0.0.1:8080/

If you’re running via an Apache VirtualHost instead, open:

http://hackernewsaggregator.localhost/

## Local development (recommended setup)

### Prerequisites

- PHP 8+ (Apache+PHP or PHP built-in server)
- Python 3 + pip

### Use a virtual environment (optional but recommended)

```bash
python -m venv .venv

# Windows (PowerShell)
.\.venv\Scripts\Activate.ps1

# macOS/Linux
source .venv/bin/activate

python -m pip install -r requirements.txt
```

### Run the backend directly (debugging)

This isolates Python issues from PHP/web server issues:

```bash
python scripts/hn_fetch.py --days 3 --min-votes 500 --max-pages 5
```

If JSON prints to stdout, the backend is functioning.

## Configuration

### Query parameters

- `days`: integer, clamped to `1..30`
- `min_votes`: integer, clamped to `0..5000`

### Python interpreter selection (`HN_PYTHON`)

When running under Apache/XAMPP, PHP often does not inherit your interactive shell PATH.
If the page shows a backend error indicating Python cannot be found, set `HN_PYTHON` in the web server environment to an absolute interpreter path.

Apache example:

```apache
SetEnv HN_PYTHON "C:/Path/To/python.exe"
```

Restart Apache after changes.

How [index.php](index.php) selects Python:

- If `HN_PYTHON` is set, only that interpreter is used.
- Otherwise it tries candidates such as `py -3`, `py`, `python`, `python3`, and common absolute Windows install paths.

### Max pages

- The Python script supports `--max-pages` (clamped to `1..20`).
- The PHP page currently calls the backend with `maxPages = 5` (hard-coded in [index.php](index.php)).

## Deployment notes

### The one rule that avoids 90% of deployment bugs

Install Python packages into the _same Python interpreter_ that the web server process uses.

Practical checklist:

- Set `HN_PYTHON` to a known absolute interpreter path.
- Install deps using that same interpreter:

```bash
C:\Path\To\python.exe -m pip install -r requirements.txt
```

### Hosting considerations

- Some shared hosts disable `proc_open()` or block subprocess execution entirely.
- If subprocesses are blocked, you’ll need a different deployment approach (e.g., run the Python logic as a separate service and call it over HTTP).

## Troubleshooting

When the backend fails, the page prints the Python stderr output (and the command used) to help you diagnose quickly.

Common problems:

- Interpreter not found (Windows often exit code `9009`, Linux often `127`): set `HN_PYTHON`.
- Missing deps (`No module named requests` / `bs4`):

```bash
python -m pip install -r requirements.txt
python -c "import requests, bs4; print('ok')"
```

If your Apache/PHP is using a different interpreter than your shell, run the install with the exact `HN_PYTHON` path.

## Repo layout

- [index.php](index.php): input clamping, subprocess execution, JSON decode, HTML rendering
- [scripts/hn_fetch.py](scripts/hn_fetch.py): scraping + parsing + filtering + JSON output
- [css/styles.css](css/styles.css): UI styles
- [scripts/app.py](scripts/app.py): experimental Flask stub (not used by the PHP app)

## Security & reliability notes

- Output escaping: PHP renders dynamic values via `htmlspecialchars`.
- Subprocess execution: uses `proc_open()` with argv-array (no shell), which reduces quoting issues and injection risk.
- Encoding: Python writes UTF-8 bytes to stdout to avoid Windows codepage issues that can break `json_decode()`.
- Caching: PHP sends `no-store`/`no-cache` headers so refreshes always refetch; CSS is cache-busted using file mtime.
- HN fetching: the Python script retries the first page with a second User-Agent if it hits `403`/`429`.
