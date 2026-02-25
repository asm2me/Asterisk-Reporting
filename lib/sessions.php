<?php
declare(strict_types=1);

/**
 * User session logging — login/logout times, session count, session duration.
 * Data is stored in data/sessions.json (one JSON record per session).
 */

define('SESSIONS_FILE', __DIR__ . '/../data/sessions.json');

// ─── Load / Save ─────────────────────────────────────────────────────────────

function loadSessionLog(): array {
    if (!file_exists(SESSIONS_FILE)) return ['sessions' => []];
    $fp = fopen(SESSIONS_FILE, 'r');
    if (!$fp) return ['sessions' => []];
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode((string)$raw, true);
    return is_array($data) && isset($data['sessions']) ? $data : ['sessions' => []];
}

function saveSessionLog(array $data): void {
    $dir = dirname(SESSIONS_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $tmp = SESSIONS_FILE . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    // Atomic replace
    rename($tmp, SESSIONS_FILE);
}

// ─── Record events ───────────────────────────────────────────────────────────

function recordLogin(string $username): void {
    $logId = bin2hex(random_bytes(8));
    $data  = loadSessionLog();
    $data['sessions'][] = [
        'id'        => $logId,
        'username'  => $username,
        'login_at'  => date('Y-m-d H:i:s'),
        'logout_at' => null,
        'duration'  => null,
        'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    // Prune entries older than 30 days to keep the file small
    $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
    $data['sessions'] = array_values(array_filter(
        $data['sessions'],
        static fn($s) => ($s['login_at'] ?? '') >= $cutoff
    ));
    saveSessionLog($data);

    // Store in PHP session so logout can close the record
    $_SESSION['session_log_id'] = $logId;
    $_SESSION['login_at_ts']    = time();
}

function recordLogout(): void {
    if (empty($_SESSION['session_log_id'])) return;

    $logId    = (string)$_SESSION['session_log_id'];
    $loginTs  = (int)($_SESSION['login_at_ts'] ?? time());
    $duration = time() - $loginTs;

    $data = loadSessionLog();
    foreach ($data['sessions'] as &$s) {
        if ($s['id'] === $logId) {
            $s['logout_at'] = date('Y-m-d H:i:s');
            $s['duration']  = $duration;
            break;
        }
    }
    unset($s);
    saveSessionLog($data);
}

// ─── Queries ─────────────────────────────────────────────────────────────────

/**
 * Return per-user summary for a date window.
 * $since / $until are 'Y-m-d H:i:s' strings (or empty for no limit).
 *
 * Returns array keyed by username:
 *   ['sessions_count', 'total_duration', 'first_login', 'last_logout', 'sessions']
 */
function getSessionSummary(array $sessions, string $since = '', string $until = ''): array {
    $summary = [];
    foreach ($sessions as $s) {
        $lat = $s['login_at'] ?? '';
        if ($since !== '' && $lat < $since) continue;
        if ($until !== '' && $lat > $until) continue;

        $u = $s['username'] ?? '';
        if (!isset($summary[$u])) {
            $summary[$u] = [
                'username'        => $u,
                'sessions_count'  => 0,
                'total_duration'  => 0,
                'first_login'     => $lat,
                'last_logout'     => '',
                'sessions'        => [],
            ];
        }
        $summary[$u]['sessions_count']++;
        $summary[$u]['total_duration'] += (int)($s['duration'] ?? 0);
        if ($lat < $summary[$u]['first_login']) $summary[$u]['first_login'] = $lat;
        $lo = $s['logout_at'] ?? '';
        if ($lo > $summary[$u]['last_logout']) $summary[$u]['last_logout'] = $lo;
        $summary[$u]['sessions'][] = $s;
    }
    // Sort by username
    ksort($summary);
    return array_values($summary);
}
