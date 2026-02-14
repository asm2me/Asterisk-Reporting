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

