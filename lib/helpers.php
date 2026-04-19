<?php
declare(strict_types=1);

function fail(string $msg, int $code = 500): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: {$msg}\n";
    exit;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function getParam(string $name, $default = null) {
    return $_GET[$name] ?? $default;
}

function postParam(string $name, $default = null) {
    return $_POST[$name] ?? $default;
}

function isValidDate(string $d): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    $p = explode('-', $d);
    return checkdate((int)$p[1], (int)$p[2], (int)$p[0]);
}

function fmtFromTableStyle(string $ymd): string { return $ymd . ' 00:00:00'; }
function fmtToTableStyle(string $ymd): string { return $ymd . ' 23:59:59'; }

function csvRow(array $fields): string {
    $out = [];
    foreach ($fields as $f) {
        $f = (string)$f;
        if (strpos($f, '"') !== false || strpos($f, ',') !== false || strpos($f, "\n") !== false || strpos($f, "\r") !== false) {
            $f = '"' . str_replace('"', '""', $f) . '"';
        }
        $out[] = $f;
    }
    return implode(',', $out) . "\n";
}

function normalizeExtList(string $csv): array {
    $csv = trim($csv);
    if ($csv === '') return [];
    $parts = preg_split('/[,\s]+/', $csv);
    $set = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p === '') continue;
        if (!preg_match('/^[0-9]+$/', $p)) continue;
        $set[$p] = true;
    }
    return array_keys($set);
}

function buildUrl(array $overrides = []): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    return '?' . http_build_query($q);
}

function fmtTime(int $sec): string {
    $h = (int)floor($sec / 3600);
    $m = (int)floor(($sec % 3600) / 60);
    $s = $sec % 60;
    return ($h > 0) ? sprintf('%02d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
}

function sortLink(string $col, string $label, string $currentSort, string $currentDir): string {
    $dir = 'asc';
    if ($currentSort === $col && $currentDir === 'asc') $dir = 'desc';
    $arrow = ($currentSort === $col) ? ($currentDir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a href="' . h(buildUrl(['sort'=>$col,'dir'=>$dir,'page'=>1])) . '">' . h($label . $arrow) . '</a>';
}

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
        'path'     => '/',
    ]);
    session_start();
}

function parseKeyValueLines(string $raw): array {
    $result = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($raw)) as $line) {
        if ($line === '' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $result[trim($k)] = trim($v);
    }
    return $result;
}

function fmtAgeShort(?int $seconds): string {
    if ($seconds === null || $seconds < 0) return 'Unknown';
    if ($seconds < 60) return $seconds . 's ago';
    if ($seconds < 3600) return floor($seconds / 60) . 'm ago';
    if ($seconds < 86400) return floor($seconds / 3600) . 'h ago';
    return floor($seconds / 86400) . 'd ago';
}

function getRealtimeServiceStatus(PDO $pdo, string $serviceName = 'asterisk-realtime-websocket.service'): array {
    $status = [
        'service' => $serviceName,
        'active_state' => 'unknown',
        'sub_state' => '',
        'main_pid' => '',
        'since' => '',
        'systemctl_available' => false,
        'agent_event_count' => 0,
        'agent_event_max' => null,
        'agent_event_age_sec' => null,
        'last_login_event_max' => null,
        'last_login_event_age_sec' => null,
        'last_break_event_max' => null,
        'last_break_event_age_sec' => null,
        'login_stale' => false,
        'break_stale' => false,
        'has_stale_updates' => false,
        'stale_messages' => [],
        'health_class' => 'warn',
        'health_text' => 'Unknown',
        'summary' => 'Service status unavailable',
    ];

    if (function_exists('shell_exec')) {
        $cmd = 'systemctl show --no-pager --property=ActiveState,SubState,ExecMainPID,ActiveEnterTimestamp ' . escapeshellarg($serviceName) . ' 2>/dev/null';
        $raw = @shell_exec($cmd);
        if (is_string($raw) && trim($raw) !== '') {
            $info = parseKeyValueLines($raw);
            $status['systemctl_available'] = true;
            $status['active_state'] = strtolower((string)($info['ActiveState'] ?? 'unknown'));
            $status['sub_state'] = (string)($info['SubState'] ?? '');
            $status['main_pid'] = (string)($info['ExecMainPID'] ?? '');
            $status['since'] = (string)($info['ActiveEnterTimestamp'] ?? '');
        }
    }

    try {
        $sql = "
            SELECT
                COUNT(*) AS cnt,
                MAX(event_time) AS max_event_time,
                MAX(CASE WHEN event_type IN ('LOGIN','LOGOUT') THEN event_time END) AS max_login_event_time,
                MAX(CASE WHEN event_type IN ('PAUSE','UNPAUSE') THEN event_time END) AS max_break_event_time
            FROM agent_event
        ";
        $row = $pdo->query($sql)->fetch();
        if ($row) {
            $status['agent_event_count'] = (int)($row['cnt'] ?? 0);
            $status['agent_event_max'] = $row['max_event_time'] ?: null;
            $status['last_login_event_max'] = $row['max_login_event_time'] ?: null;
            $status['last_break_event_max'] = $row['max_break_event_time'] ?: null;

            if (!empty($row['max_event_time'])) {
                $ts = strtotime((string)$row['max_event_time']);
                if ($ts !== false) {
                    $status['agent_event_age_sec'] = max(0, time() - $ts);
                }
            }

            if (!empty($row['max_login_event_time'])) {
                $ts = strtotime((string)$row['max_login_event_time']);
                if ($ts !== false) {
                    $status['last_login_event_age_sec'] = max(0, time() - $ts);
                }
            }

            if (!empty($row['max_break_event_time'])) {
                $ts = strtotime((string)$row['max_break_event_time']);
                if ($ts !== false) {
                    $status['last_break_event_age_sec'] = max(0, time() - $ts);
                }
            }
        }
    } catch (Throwable $e) {
        // agent_event table may not exist yet; keep defaults
    }

    $isActive = $status['active_state'] === 'active';
    $ageSec = $status['agent_event_age_sec'];

    if ($isActive && $ageSec !== null && $ageSec <= 900) {
        $status['health_class'] = 'ok';
        $status['health_text'] = 'Healthy';
        $status['summary'] = 'Service running and agent events are fresh';
    } elseif ($isActive && $ageSec !== null && $ageSec <= 86400) {
        $status['health_class'] = 'warn';
        $status['health_text'] = 'Delayed';
        $status['summary'] = 'Service running but latest agent event is delayed';
    } elseif ($isActive && $ageSec !== null) {
        $status['health_class'] = 'bad';
        $status['health_text'] = 'Stale';
        $status['summary'] = 'Service running but agent_event data is stale';
    } elseif ($isActive) {
        $status['health_class'] = 'warn';
        $status['health_text'] = 'No data';
        $status['summary'] = 'Service running but no agent_event data found';
    } else {
        $status['health_class'] = 'bad';
        $status['health_text'] = 'Down';
        $status['summary'] = 'Service is not active';
    }

    $status['agent_event_age_text'] = fmtAgeShort($status['agent_event_age_sec']);
    $status['last_login_event_age_text'] = fmtAgeShort($status['last_login_event_age_sec']);
    $status['last_break_event_age_text'] = fmtAgeShort($status['last_break_event_age_sec']);

    $status['login_stale'] = ($status['last_login_event_age_sec'] === null || $status['last_login_event_age_sec'] > 86400);
    $status['break_stale'] = ($status['last_break_event_age_sec'] === null || $status['last_break_event_age_sec'] > 86400);

    if ($status['login_stale']) {
        $status['stale_messages'][] = empty($status['last_login_event_max'])
            ? 'No login updates found'
            : 'Login updates are older than 24 hours';
    }
    if ($status['break_stale']) {
        $status['stale_messages'][] = empty($status['last_break_event_max'])
            ? 'No break updates found'
            : 'Break updates are older than 24 hours';
    }

    $status['has_stale_updates'] = !empty($status['stale_messages']);
    return $status;
}
