<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function run_python_backend(int $days, int $minVotes, int $maxPages): array
{
    $pythonBin = null;
    if (isset($_SERVER['HN_PYTHON']) && is_string($_SERVER['HN_PYTHON'])) {
        $pythonBin = $_SERVER['HN_PYTHON'];
    } else {
        $pythonBin = getenv('HN_PYTHON');
    }

    // NOTE: Use proc_open() array command form so Windows paths/spaces work reliably and
    // so we can support the Python Launcher ("py -3") without going through a shell.
    // Each candidate is an argv-array where the first item is the executable.
    $pythonCandidates = [];
    if (is_string($pythonBin) && trim($pythonBin) !== '') {
        $pythonCandidates[] = [trim($pythonBin)];
    } else {
        // Many hosts run PHP with a restricted PATH.
        // Try Windows-friendly candidates first, then common Linux locations.
        $pythonCandidates = [
            ['py', '-3'],
            ['py'],
            ['python'],
            ['python3'],
            ['/usr/bin/python3'],
            ['/usr/local/bin/python3'],
        ];

        // On Windows, Apache/PHP often runs without your interactive PATH.
        // Try to locate common absolute python.exe install locations.
        if (PHP_OS_FAMILY === 'Windows') {
            $found = [];

            $addIfFile = static function (string $path) use (&$found): void {
                $p = trim($path);
                if ($p === '') {
                    return;
                }
                if (is_file($p)) {
                    $found[$p] = true;
                }
            };

            foreach (glob('C:\\Python*\\python.exe') ?: [] as $p) {
                $addIfFile($p);
            }
            foreach (glob('C:\\Program Files\\Python*\\python.exe') ?: [] as $p) {
                $addIfFile($p);
            }
            foreach (glob('C:\\Program Files (x86)\\Python*\\python.exe') ?: [] as $p) {
                $addIfFile($p);
            }

            $localAppData = getenv('LOCALAPPDATA');
            if (is_string($localAppData) && trim($localAppData) !== '') {
                $pattern = rtrim($localAppData, "\\/") . '\\Programs\\Python\\Python*\\python.exe';
                foreach (glob($pattern) ?: [] as $p) {
                    $addIfFile($p);
                }
            }

            $userProfile = getenv('USERPROFILE');
            if (is_string($userProfile) && trim($userProfile) !== '') {
                $pattern = rtrim($userProfile, "\\/") . '\\.pyenv\\pyenv-win\\versions\\*\\python.exe';
                foreach (glob($pattern) ?: [] as $p) {
                    $addIfFile($p);
                }
            }

            if (count($found) > 0) {
                $abs = [];
                foreach (array_keys($found) as $p) {
                    $abs[] = [$p];
                }
                // Prefer absolute interpreters over PATH/py launcher.
                $pythonCandidates = array_merge($abs, $pythonCandidates);
            }
        }
    }

    $scriptPath = __DIR__ . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'hn_fetch.py';

    if (!is_file($scriptPath)) {
        return ['ok' => false, 'error' => 'Missing backend script at scripts/hn_fetch.py'];
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $lastError = null;
    $lastStderr = null;
    $lastStdout = null;
    $lastCmd = null;

    $isMissingInterpreter = static function (int $exitCode, string $stderr): bool {
        $s = strtolower(trim($stderr));
        if ($exitCode === 127 || $exitCode === 9009) {
            return true;
        }
        if ($s === '') {
            return false;
        }

        // Common “interpreter not found” messages (Linux + Windows).
        return str_contains($s, 'command not found')
            || str_contains($s, 'not found')
            || str_contains($s, 'no installed python found')
            || str_contains($s, 'is not recognized as an internal or external command')
            || str_contains($s, 'the system cannot find the path specified');
    };

    foreach ($pythonCandidates as $candidateArgv) {
        $cmd = array_merge(
            $candidateArgv,
            [
                $scriptPath,
                '--days',
                (string) $days,
                '--min-votes',
                (string) $minVotes,
                '--max-pages',
                (string) $maxPages,
            ]
        );

        $lastCmd = implode(' ', array_map(static fn(string $p): string => (str_contains($p, ' ') ? '"' . $p . '"' : $p), $cmd));

        $proc = proc_open($cmd, $descriptors, $pipes, __DIR__);
        if (!is_resource($proc)) {
            $lastError = 'Failed to start Python process';
            continue;
        }

        // Close stdin immediately (we don't send input).
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $lastStdout = (string) $stdout;

        $exitCode = proc_close($proc);
        if ($exitCode !== 0) {
            $lastError = 'Python backend exited with code ' . (string) $exitCode;
            $lastStderr = trim((string) $stderr);

            // If we explicitly set HN_PYTHON, don't try fallbacks.
            if (count($pythonCandidates) === 1) {
                break;
            }

            // Try next candidate only for likely “python not found”.
            if ($isMissingInterpreter($exitCode, (string) $lastStderr)) {
                continue;
            }
            break;
        }

        $rawOut = (string) $stdout;
        // Some environments may prepend a UTF-8 BOM or whitespace.
        $rawOut = preg_replace('/^\xEF\xBB\xBF/', '', $rawOut) ?? $rawOut;

        $data = json_decode($rawOut, true);
        if (!is_array($data)) {
            // If extra text was printed, try to extract the first JSON object.
            $start = strpos($rawOut, '{');
            $end = strrpos($rawOut, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $candidateJson = substr($rawOut, $start, $end - $start + 1);
                $data2 = json_decode($candidateJson, true);
                if (is_array($data2)) {
                    return ['ok' => true, 'data' => $data2];
                }
            }

            $lastError = 'Python backend returned invalid JSON';
            $lastStderr = trim((string) $stderr);
            break;
        }

        return ['ok' => true, 'data' => $data];
    }

    return [
        'ok' => false,
        'error' => $lastError ?? 'Python backend failed',
        'stderr' => trim((string) ($lastStderr ?? ''))
            . (($lastCmd !== null && $lastCmd !== '') ? "\nCommand: {$lastCmd}" : '')
            . (($lastStdout !== null && trim($lastStdout) !== '') ? "\nStdout (first 300 chars): " . substr(trim($lastStdout), 0, 300) : ''),
    ];
}

$days = isset($_GET['days']) ? (int) $_GET['days'] : 7;
if ($days < 1) {
    $days = 1;
}
if ($days > 30) {
    $days = 30;
}

$minVotes = isset($_GET['min_votes']) ? (int) $_GET['min_votes'] : 250;
if ($minVotes < 0) {
    $minVotes = 0;
}
if ($minVotes > 5000) {
    $minVotes = 5000;
}

$backend = run_python_backend($days, $minVotes, 5);
$backendError = null;
$backendErrorDetails = null;
$backendHint = null;

if (isset($backend['ok']) && $backend['ok'] === true && isset($backend['data']) && is_array($backend['data'])) {
    $payload = $backend['data'];
    $stories = isset($payload['stories']) && is_array($payload['stories']) ? $payload['stories'] : [];
    $generatedAt = isset($payload['generated_at_utc']) && is_string($payload['generated_at_utc'])
        ? $payload['generated_at_utc']
        : gmdate('Y-m-d H:i:s');
} else {
    $stories = [];
    $generatedAt = gmdate('Y-m-d H:i:s');
    $backendError = isset($backend['error']) ? (string) $backend['error'] : 'Unknown backend error';
    $backendErrorDetails = isset($backend['stderr']) ? (string) $backend['stderr'] : null;

    $details = strtolower((string) ($backendErrorDetails ?? ''));
    if (
        str_contains($details, "no module named 'bs4'")
        || str_contains($details, 'no module named bs4')
        || str_contains($details, "no module named 'requests'")
        || str_contains($details, 'module not found')
        || str_contains($details, 'modulenotfounderror')
    ) {
        $backendHint = "Hint: install Python deps with: python3 -m pip install -r requirements.txt (or --user). "
            . "If Apache/PHP uses a different Python than your shell, set HN_PYTHON to that Python's full path, then run that same interpreter's pip.";
    }
}

$cssPath = __DIR__ . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'styles.css';
$cssVer = is_file($cssPath) ? (string) filemtime($cssPath) : (string) time();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Hacker News (Last <?= e((string) $days) ?> Days)</title>
    <link rel="stylesheet" href="css/styles.css?v=<?= e($cssVer) ?>" />
</head>

<body>
    <div class="container">
        <header class="header">
            <h1>Hacker News</h1>
            <p class="subhead">Top stories from the last <?= e((string) $days) ?> days</p>
            <p class="meta">
                Minimum votes: <strong><?= e((string) $minVotes) ?></strong> ·
                Generated <time datetime="<?= e(gmdate('c')) ?>"><?= e($generatedAt) ?> UTC</time>
            </p>
        </header>

        <main>
            <?php if ($backendError !== null): ?>
                <p class="empty">
                    Backend error: <?= e($backendError) ?>
                    <?php if ($backendErrorDetails): ?>
                        <br />
                        <small><?= e($backendErrorDetails) ?></small>
                    <?php endif; ?>
                    <?php if ($backendHint): ?>
                        <br />
                        <small><?= e($backendHint) ?></small>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if (count($stories) > 0): ?>
                <ol class="story-list">
                    <?php foreach ($stories as $story): ?>
                        <li class="story">
                            <a class="story-link" href="<?= e($story['link']) ?>" target="_blank" rel="noreferrer">
                                <span class="votes"><?= e((string) $story['votes']) ?></span>
                                <span class="title"><?= e($story['title']) ?></span>
                                <span class="chevron" aria-hidden="true">→</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php else: ?>
                <p class="empty">No stories matched your filter.</p>
            <?php endif; ?>
        </main>
    </div>
</body>

</html>