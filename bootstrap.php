<?php
declare(strict_types=1);

/**
 * bootstrap.php
 * Exposes:
 *   $usersData, $me, $isAdmin, $allowedExts, $acl, $pdo, $settings
 */

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/users_store.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/cdr.php';

/* ---- verify required functions exist (avoid hard fatals) ---- */
if (!function_exists('Supervisor\\Lib\\loadUsers')) {
    Supervisor\Lib\fail(
        "Missing function Supervisor\\\\Lib\\\\loadUsers(). " .
        "Check lib/users_store.php has: namespace Supervisor\\Lib; and function loadUsers()."
    );
}
if (!function_exists('Supervisor\\Lib\\startSecureSession')) {
    Supervisor\Lib\fail(
        "Missing function Supervisor\\\\Lib\\\\startSecureSession(). " .
        "Check lib/auth.php has: namespace Supervisor\\Lib; and function startSecureSession()."
    );
}
if (!function_exists('Supervisor\\Lib\\requireSessionLogin')) {
    Supervisor\Lib\fail(
        "Missing function Supervisor\\\\Lib\\\\requireSessionLogin(). " .
        "Check lib/auth.php has: namespace Supervisor\\Lib; and function requireSessionLogin()."
    );
}

/* -------------------- local config -------------------- */
$usersFile = __DIR__ . '/config_users.json';
$usersData = Supervisor\Lib\loadUsers($usersFile);

$settings = [
    'cdr_db'    => getenv('CDR_DB') ?: 'asteriskcdrdb',
    'cdr_table' => getenv('CDR_TABLE') ?: 'cdr',
    'timezone'  => getenv('APP_TZ') ?: 'UTC',
    'gateways'  => [],
];

if (isset($usersData['gateways']) && is_array($usersData['gateways'])) {
    foreach ($usersData['gateways'] as $g) {
        $g = trim((string)$g);
        if ($g !== '') $settings['gateways'][] = $g;
    }
}

/* -------------------- auth -------------------- */
Supervisor\Lib\startSecureSession();

$action = strtolower((string)($_GET['action'] ?? ''));
if ($action === 'logout') {
    Supervisor\Lib\doLogout();
}

$me = Supervisor\Lib\requireSessionLogin($usersData);
$isAdmin = (bool)$me['is_admin'];
$allowedExts = (array)$me['extensions'];

/* -------------------- ACL where -------------------- */
$acl = [
    'where'  => '1=1',
    'params' => [],
];

if (!$isAdmin) {
    if (count($allowedExts) === 0) {
        $acl['where'] = '1=0';
    } else {
        $parts = [];
        $i = 0;
        foreach ($allowedExts as $e) {
            $e = trim((string)$e);
            if ($e === '' || !preg_match('/^[0-9]+$/', $e)) continue;

            $p1 = ":acl_ch_sip_$i";   $p2 = ":acl_dch_sip_$i";
            $p3 = ":acl_ch_pjsip_$i"; $p4 = ":acl_dch_pjsip_$i";

            $parts[] = "(channel LIKE $p1 OR dstchannel LIKE $p2 OR channel LIKE $p3 OR dstchannel LIKE $p4)";
            $acl['params'][$p1] = "SIP/{$e}-%";
            $acl['params'][$p2] = "SIP/{$e}-%";
            $acl['params'][$p3] = "PJSIP/{$e}-%";
            $acl['params'][$p4] = "PJSIP/{$e}-%";
            $i++;
        }
        $acl['where'] = $parts ? ('(' . implode(' OR ', $parts) . ')') : '1=0';
    }
}

/* -------------------- DB creds ONLY from /etc/freepbx.conf -------------------- */
function parseFreepbxConfText(string $txt): array {
    $out = [];
    if (preg_match_all('/\\$amp_conf\\s*\\[\\s*"([^"]+)"\\s*\\]\\s*=\\s*"([^"]*)"/', $txt, $m, PREG_SET_ORDER)) {
        foreach ($m as $mm) $out[$mm[1]] = $mm[2];
    }
    return $out;
}

function getDbCredsFromFreepbxConf(string $path = '/etc/freepbx.conf'): array {
    if (!is_readable($path)) {
        Supervisor\Lib\fail("freepbx.conf not readable: {$path}");
    }
    $txt = file_get_contents($path);
    if ($txt === false) Supervisor\Lib\fail("Cannot read {$path}");
    $amp = parseFreepbxConfText($txt);

    $host = $amp['AMPDBHOST'] ?? $amp['AMPDBHOSTNAME'] ?? 'localhost';
    $user = $amp['AMPDBUSER'] ?? '';
    $pass = $amp['AMPDBPASS'] ?? null;

    if ($user === '' || $pass === null) {
        Supervisor\Lib\fail("DB creds missing in {$path} (need AMPDBUSER + AMPDBPASS)");
    }
    return ['host'=>(string)$host,'user'=>(string)$user,'pass'=>(string)$pass];
}

$dbc = getDbCredsFromFreepbxConf('/etc/freepbx.conf');

/* -------------------- PDO connect -------------------- */
$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $dbc['host'], $settings['cdr_db']);

try {
    $pdo = new PDO($dsn, $dbc['user'], $dbc['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (\Throwable $e) {
    Supervisor\Lib\fail("DB connection failed: " . $e->getMessage());
}

