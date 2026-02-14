<?php
declare(strict_types=1);

/**
 * FreePBX/Issabel-independent DB credential reader.
 * Reads /etc/freepbx.conf (preferred) or /etc/amportal.conf (fallback) as TEXT.
 * Does NOT include or require any FreePBX code.
 */

function parseFreepbxConfText(string $path): array {
    if (!is_readable($path)) return [];
    $raw = file_get_contents($path);
    if ($raw === false) return [];

    $out = [];
    // Match: $amp_conf["KEY"] = "VALUE";
    $re = '/\$amp_conf\[\s*(["\'])([A-Z0-9_]+)\1\s*\]\s*=\s*(["\'])(.*?)\3\s*;/m';
    if (preg_match_all($re, $raw, $m, PREG_SET_ORDER)) {
        foreach ($m as $mm) {
            $key = $mm[2];
            $val = stripcslashes($mm[4]); // handles \" etc
            $out[$key] = $val;
        }
    }
    return $out;
}

function parseAmportalConf(string $path): array {
    $cfg = [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return $cfg;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;

        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));

        // strip surrounding quotes
        if (strlen($v) >= 2) {
            $q0 = $v[0];
            $q1 = $v[strlen($v) - 1];
            if (($q0 === '"' && $q1 === '"') || ($q0 === "'" && $q1 === "'")) {
                $v = substr($v, 1, -1);
            }
        }
        $cfg[$k] = $v;
    }
    return $cfg;
}

function getDbCreds(): array {
    $freepbx  = '/etc/freepbx.conf';
    $amportal = '/etc/amportal.conf';

    // Prefer FreePBX conf
    $fc = parseFreepbxConfText($freepbx);
    if (!empty($fc)) {
        $host = $fc['AMPDBHOST'] ?? $fc['AMPDBHOSTNAME'] ?? 'localhost';
        $user = $fc['AMPDBUSER'] ?? null;
        $pass = array_key_exists('AMPDBPASS', $fc) ? $fc['AMPDBPASS'] : null;
        $port = $fc['AMPDBPORT'] ?? null;

        if ($user !== null && $pass !== null) {
            return [
                'host'   => (string)$host,
                'user'   => (string)$user,
                'pass'   => (string)$pass,
                'port'   => ($port !== null && ctype_digit((string)$port)) ? (int)$port : null,
                'source' => $freepbx,
            ];
        }
    }

    // Fallback Issabel/amportal
    $ac = parseAmportalConf($amportal);
    if (!empty($ac)) {
        $host = $ac['AMPDBHOST'] ?? 'localhost';
        $user = $ac['AMPDBUSER'] ?? null;
        $pass = array_key_exists('AMPDBPASS', $ac) ? $ac['AMPDBPASS'] : null;
        $port = $ac['AMPDBPORT'] ?? null;

        if ($user !== null && $pass !== null) {
            return [
                'host'   => (string)$host,
                'user'   => (string)$user,
                'pass'   => (string)$pass,
                'port'   => ($port !== null && ctype_digit((string)$port)) ? (int)$port : null,
                'source' => $amportal,
            ];
        }
    }

    // Always return array or throw/exit
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: Could not read DB credentials from /etc/freepbx.conf or /etc/amportal.conf\n";
    exit;
}

