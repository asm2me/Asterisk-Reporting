<?php
/**
 * Supervisor CDR Report (PHP 7.x/8.x)
 * - Session Login + Logout
 * - ACL by SIP/PJSIP channel/dstchannel (per-user extensions)
 * - Secure recordings play/download (ACL enforced)
 * - Admin-only User Management (?page=users)
 * - CSV export
 * - Responsive UI (including responsive stacked table on mobile)
 * - From/To date pickers + display same datetime format as table (YYYY-MM-DD HH:MM:SS)
 * - Src filter matches (src number OR channel SIP/PJSIP)
 * - Dst filter matches (dst number OR dstchannel SIP/PJSIP)  ✅ same field
 * - Totals chart (Chart.js self-hosted) + color map/legend
 *
 * Files:
 *   ./config_users.json
 *   ./chart.umd.min.js
 */

declare(strict_types=1);

/* -------------------- SETTINGS -------------------- */
$cdrDb      = getenv('CDR_DB') ?: 'asteriskcdrdb';
$cdrTable   = getenv('CDR_TABLE') ?: 'cdr';
$recBaseDir = getenv('REC_BASEDIR') ?: '/var/spool/asterisk/monitor';
$usersFile  = __DIR__ . '/config_users.json';

/* -------------------- helpers -------------------- */
function fail(string $msg, int $code = 500): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: {$msg}\n";
    exit;
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function getParam(string $name, $default = null) { return $_GET[$name] ?? $default; }
function postParam(string $name, $default = null) { return $_POST[$name] ?? $default; }

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

/* -------------------- USERS CONFIG -------------------- */
function loadUsers(string $path): array {
    if (!is_readable($path)) fail("Users config not readable: {$path}");
    $raw = file_get_contents($path);
    if ($raw === false) fail("Cannot read users config: {$path}");
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['users']) || !is_array($data['users'])) {
        fail("Users config invalid JSON (expected {users:{...}})");
    }
    return $data;
}
function saveUsers(string $path, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) fail("Failed to encode users JSON");
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json) === false) fail("Failed to write temp users file: {$tmp}");
    if (!rename($tmp, $path)) fail("Failed to replace users file");
}

/* -------------------- SESSION AUTH -------------------- */
function startSecureSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
        'path'     => '/',
    ]);
    session_start();
}
function doLogout(): void {
    startSecureSession();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $base);
    exit;
}

function requireSessionLogin(array $usersData): array {
    startSecureSession();

    if (!empty($_SESSION['auth_user']) && is_string($_SESSION['auth_user'])) {
        $u = $_SESSION['auth_user'];
        if (isset($usersData['users'][$u])) {
            $rec = $usersData['users'][$u];
            $isAdmin = !empty($rec['is_admin']);
            $exts = [];
            if (isset($rec['extensions']) && is_array($rec['extensions'])) {
                foreach ($rec['extensions'] as $e) {
                    $e = trim((string)$e);
                    if ($e !== '' && preg_match('/^[0-9]+$/', $e)) $exts[$e] = true;
                }
            }
            return ['username'=>$u, 'is_admin'=>$isAdmin, 'extensions'=>array_keys($exts)];
        }
        unset($_SESSION['auth_user']);
    }

    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)postParam('login', '') === '1') {
        $u = trim((string)postParam('username', ''));
        $p = (string)postParam('password', '');

        if ($u === '' || $p === '' || !isset($usersData['users'][$u])) {
            $err = 'Invalid username or password';
        } else {
            $rec = $usersData['users'][$u];
            $hash = $rec['password_hash'] ?? '';
            if (!is_string($hash) || $hash === '' || !password_verify($p, $hash)) {
                $err = 'Invalid username or password';
            } else {
                session_regenerate_id(true);
                $_SESSION['auth_user'] = $u;
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . (empty($_GET) ? '' : ('?' . http_build_query($_GET))));
                exit;
            }
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html>
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Supervisor CDR - Login</title>
      <style>
        body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:#0b1220;color:#e8eefc}
        .wrap{max-width:420px;margin:0 auto;padding:28px}
        .card{background:rgba(15,26,48,.8);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px}
        h1{margin:0 0 10px 0;font-size:18px}
        .muted{color:#9fb0d0;font-size:12px}
        input{width:100%;box-sizing:border-box;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);
              color:#e8eefc;border-radius:10px;padding:10px;font-size:13px;margin-top:8px;outline:none}
        button{margin-top:12px;background:rgba(68,209,157,.14);border:1px solid rgba(68,209,157,.25);
               color:#e8eefc;padding:10px 12px;border-radius:12px;cursor:pointer;width:100%;font-size:13px}
        .err{margin-top:10px;color:#ff6b7a;font-size:13px}
      </style>
    </head>
    <body>
      <div class="wrap">
        <div class="card">
          <h1>Supervisor CDR</h1>
          <div class="muted">Please sign in.</div>
          <?php if ($err !== ''): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
          <form method="post">
            <input type="hidden" name="login" value="1">
            <input name="username" placeholder="Username" autocomplete="username" required>
            <input type="password" name="password" placeholder="Password" autocomplete="current-password" required>
            <button type="submit">Login</button>
          </form>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}

/* -------------------- DB creds (FreePBX/Issabel INDEPENDENT) -------------------- */
/**
 * Parse /etc/freepbx.conf without including it.
 * Supports lines like:
 *   $amp_conf["AMPDBUSER"] = "freepbxuser";
 *   $amp_conf['AMPDBPASS'] = "secret";
 *   $amp_conf["AMPDBHOST"] = "localhost";
 *   $amp_conf["AMPDBPORT"] = "3306";   (optional)
 *   $amp_conf["AMPDBNAME"] = "asterisk"; (optional)
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

/**
 * Parse /etc/amportal.conf (key=value)
 * Supports:
 *   AMPDBHOST=localhost
 *   AMPDBUSER=freepbxuser
 *   AMPDBPASS=secret
 *   AMPDBNAME=asterisk   (optional)
 *   AMPDBPORT=3306       (optional)
 */
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
        // Strip optional surrounding quotes
        if ((strlen($v) >= 2) && (($v[0] === '"' && $v[strlen($v)-1] === '"') || ($v[0] === "'" && $v[strlen($v)-1] === "'"))) {
            $v = substr($v, 1, -1);
        }
        $cfg[$k] = $v;
    }
    return $cfg;
}

/**
 * Return DB creds without requiring FreePBX/Issabel runtime.
 * Prefers /etc/freepbx.conf, falls back to /etc/amportal.conf.
 */
function getDbCreds(): array {
    $freepbx  = '/etc/freepbx.conf';
    $amportal = '/etc/amportal.conf';

    // Prefer /etc/freepbx.conf (newer FreePBX)
    $fc = parseFreepbxConfText($freepbx);
    if (!empty($fc)) {
        $host = $fc['AMPDBHOST'] ?? $fc['AMPDBHOSTNAME'] ?? 'localhost';
        $user = $fc['AMPDBUSER'] ?? null;
        $pass = array_key_exists('AMPDBPASS', $fc) ? $fc['AMPDBPASS'] : null;
        $port = $fc['AMPDBPORT'] ?? null;
        $name = $fc['AMPDBNAME'] ?? null;

        if ($user !== null && $pass !== null) {
            return [
                'host'   => (string)$host,
                'user'   => (string)$user,
                'pass'   => (string)$pass,
                'port'   => ($port !== null && ctype_digit((string)$port)) ? (int)$port : null,
                'dbname' => ($name !== null && $name !== '') ? (string)$name : null,
                'source' => $freepbx,
            ];
        }
    }

    // Fallback /etc/amportal.conf (older Issabel/FreePBX)
    $ac = parseAmportalConf($amportal);
    if (!empty($ac)) {
        $host = $ac['AMPDBHOST'] ?? 'localhost';
        $user = $ac['AMPDBUSER'] ?? null;
        $pass = array_key_exists('AMPDBPASS', $ac) ? $ac['AMPDBPASS'] : null;
        $port = $ac['AMPDBPORT'] ?? null;
        $name = $ac['AMPDBNAME'] ?? null;

        if ($user !== null && $pass !== null) {
            return [
                'host'   => (string)$host,
                'user'   => (string)$user,
                'pass'   => (string)$pass,
                'port'   => ($port !== null && ctype_digit((string)$port)) ? (int)$port : null,
                'dbname' => ($name !== null && $name !== '') ? (string)$name : null,
                'source' => $amportal,
            ];
        }
    }

    fail("Could not read DB credentials from /etc/freepbx.conf or /etc/amportal.conf");
}

/* -------------------- recordings (secure) -------------------- */
function sanitizeRelativePath(string $p): string {
    $p = str_replace("\0", '', $p);
    $p = str_replace("\\", "/", $p);
    $p = preg_replace('#/+#', '/', $p);
    $p = ltrim($p, '/');
    while (strpos($p, '../') !== false) $p = str_replace('../', '', $p);
    return $p;
}
function findRecordingFile(string $baseDir, ?string $recordingfile, ?string $calldate): ?string {
    if ($recordingfile === null) return null;
    $recordingfile = trim((string)$recordingfile);
    if ($recordingfile === '') return null;

    $baseDir = rtrim($baseDir, '/');

    if ($recordingfile[0] === '/') {
        $real = realpath($recordingfile);
        if ($real && strpos($real, $baseDir . '/') === 0 && is_file($real)) return $real;
        return null;
    }

    $rel = sanitizeRelativePath($recordingfile);
    $try = $baseDir . '/' . $rel;
    $real = realpath($try);
    if ($real && strpos($real, $baseDir . '/') === 0 && is_file($real)) return $real;

    if ($calldate && preg_match('/^\d{4}-\d{2}-\d{2}/', $calldate)) {
        $yyyy = substr($calldate, 0, 4);
        $mm   = substr($calldate, 5, 2);
        $dd   = substr($calldate, 8, 2);
        $dir  = $baseDir . "/{$yyyy}/{$mm}/{$dd}";
        $bn = basename($rel);
        foreach ([$bn, "$bn.wav", "$bn.mp3", "$bn.gsm", "$bn.WAV", "$bn.MP3", "$bn.GSM"] as $name) {
            $p = $dir . '/' . $name;
            $r = realpath($p);
            if ($r && strpos($r, $baseDir . '/') === 0 && is_file($r)) return $r;
        }
    }
    return null;
}
function streamFile(string $absPath, string $mode): void {
    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    $mime = 'application/octet-stream';
    if ($ext === 'wav') $mime = 'audio/wav';
    elseif ($ext === 'mp3') $mime = 'audio/mpeg';
    elseif ($ext === 'gsm') $mime = 'audio/gsm';

    $size = filesize($absPath);
    $filename = basename($absPath);

    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');

    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        $start = (int)$m[1];
        $end = ($m[2] !== '') ? (int)$m[2] : ($size - 1);
        if ($start <= $end && $end < $size) {
            http_response_code(206);
            header("Content-Type: {$mime}");
            header("Content-Range: bytes {$start}-{$end}/{$size}");
            header("Content-Length: " . ($end - $start + 1));
            header('Content-Disposition: ' . ($mode === 'attachment' ? 'attachment' : 'inline') . '; filename="' . $filename . '"');

            $fh = fopen($absPath, 'rb');
            if ($fh) {
                fseek($fh, $start);
                $left = $end - $start + 1;
                while ($left > 0 && !feof($fh)) {
                    $chunk = fread($fh, min(8192, $left));
                    if ($chunk === false) break;
                    echo $chunk;
                    $left -= strlen($chunk);
                }
                fclose($fh);
            }
            exit;
        }
    }

    header("Content-Type: {$mime}");
    header("Content-Length: {$size}");
    header('Content-Disposition: ' . ($mode === 'attachment' ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    readfile($absPath);
    exit;
}

/* -------------------- ACL helpers (channel matching) -------------------- */
function aclWhereByChannel(array $allowedExts, array &$aclParams): string {
    $parts = [];
    foreach (array_values($allowedExts) as $i => $e) {
        $p1 = ":ch_sip_$i";  $p2 = ":dch_sip_$i";
        $p3 = ":ch_psip_$i"; $p4 = ":dch_psip_$i";

        $parts[] = "(channel LIKE $p1 OR dstchannel LIKE $p2 OR channel LIKE $p3 OR dstchannel LIKE $p4)";

        $aclParams[$p1] = "SIP/{$e}-%";
        $aclParams[$p2] = "SIP/{$e}-%";
        $aclParams[$p3] = "PJSIP/{$e}-%";
        $aclParams[$p4] = "PJSIP/{$e}-%";
    }
    return '(' . implode(' OR ', $parts) . ')';
}
function rowAllowedByChannel(array $allowedExts, string $channel, string $dstchannel): bool {
    foreach ($allowedExts as $e) {
        if (strpos($channel, "SIP/$e-") === 0) return true;
        if (strpos($dstchannel, "SIP/$e-") === 0) return true;
        if (strpos($channel, "PJSIP/$e-") === 0) return true;
        if (strpos($dstchannel, "PJSIP/$e-") === 0) return true;
    }
    return false;
}

/* -------------------- bootstrap -------------------- */
$usersData = loadUsers($usersFile);

$action = strtolower((string)getParam('action', ''));
if ($action === 'logout') doLogout();

$me = requireSessionLogin($usersData);
$isAdmin = (bool)$me['is_admin'];

/* -------------------- DB connect -------------------- */
$dbc = getDbCreds();
$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $dbc['host'], $cdrDb);
try {
    $pdo = new PDO($dsn, $dbc['user'], $dbc['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    fail("DB connection failed: " . $e->getMessage());
}

/* -------------------- User management (admin-only) -------------------- */
$page = (string)getParam('page', '');
if ($page === 'users') {
    if (!$isAdmin) fail("Forbidden", 403);

    if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    $csrf = (string)$_SESSION['csrf'];

    $msg = '';
    $editUser = trim((string)getParam('edit', ''));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $inCsrf = (string)postParam('csrf', '');
        if (!hash_equals($csrf, $inCsrf)) fail("Bad CSRF", 400);

        $uaction = (string)postParam('uaction', '');
        $uname   = trim((string)postParam('username', ''));
        $extCsv  = (string)postParam('extensions', '');
        $isAdm   = postParam('is_admin', '') === '1';

        $newPass1 = (string)postParam('newpass1', '');
        $newPass2 = (string)postParam('newpass2', '');

        if ($uname === '' || !preg_match('/^[A-Za-z0-9_\-\.]{3,32}$/', $uname)) {
            $msg = 'Invalid username (3-32 chars: letters/numbers/_-.)';
        } else {
            if ($uaction === 'add') {
                if (isset($usersData['users'][$uname])) {
                    $msg = 'User already exists';
                } elseif ($newPass1 === '' || $newPass2 === '') {
                    $msg = 'Password required for new user';
                } elseif ($newPass1 !== $newPass2) {
                    $msg = 'Passwords do not match';
                } else {
                    $usersData['users'][$uname] = [
                        'password_hash' => password_hash($newPass1, PASSWORD_DEFAULT),
                        'is_admin' => $isAdm,
                        'extensions' => normalizeExtList($extCsv),
                    ];
                    saveUsers($usersFile, $usersData);
                    $msg = 'User added';
                }
            } elseif ($uaction === 'update') {
                if (!isset($usersData['users'][$uname])) {
                    $msg = 'User not found';
                } else {
                    $usersData['users'][$uname]['is_admin'] = $isAdm;
                    $usersData['users'][$uname]['extensions'] = normalizeExtList($extCsv);
                    saveUsers($usersFile, $usersData);
                    $msg = 'User updated';
                }
            } elseif ($uaction === 'changepass') {
                if (!isset($usersData['users'][$uname])) {
                    $msg = 'User not found';
                } elseif ($newPass1 === '' || $newPass2 === '') {
                    $msg = 'Enter password twice';
                } elseif ($newPass1 !== $newPass2) {
                    $msg = 'Passwords do not match';
                } else {
                    $usersData['users'][$uname]['password_hash'] = password_hash($newPass1, PASSWORD_DEFAULT);
                    saveUsers($usersFile, $usersData);
                    $msg = 'Password changed';
                }
            } elseif ($uaction === 'delete') {
                if ($uname === 'admin') {
                    $msg = 'Refusing to delete admin';
                } elseif (!isset($usersData['users'][$uname])) {
                    $msg = 'User not found';
                } else {
                    unset($usersData['users'][$uname]);
                    saveUsers($usersFile, $usersData);
                    $msg = 'User deleted';
                }
            } else {
                $msg = 'Unknown action';
            }
        }

        $usersData = loadUsers($usersFile);
        $editUser = '';
    }

    $edit = null;
    if ($editUser !== '' && isset($usersData['users'][$editUser])) $edit = $usersData['users'][$editUser];

    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html>
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>User Management</title>
      <style>
        body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:#0b1220;color:#e8eefc}
        .wrap{max-width:1050px;margin:0 auto;padding:20px}
        .top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
        .card{background:rgba(15,26,48,.8);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:14px;margin-bottom:12px}
        h1{margin:0 0 8px 0;font-size:18px}
        .muted{color:#9fb0d0;font-size:12px}
        table{width:100%;border-collapse:collapse;margin-top:10px}
        th,td{border-bottom:1px solid rgba(255,255,255,.08);padding:10px;font-size:13px;text-align:left;vertical-align:top}
        input,textarea,select{width:100%;box-sizing:border-box;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);color:#e8eefc;border-radius:10px;padding:10px;font-size:13px}
        textarea{min-height:48px}
        .btn{display:inline-block;background:rgba(122,162,255,.14);border:1px solid rgba(122,162,255,.25);color:#e8eefc;padding:10px 12px;border-radius:12px;text-decoration:none}
        .btn.danger{background:rgba(255,107,122,.14);border-color:rgba(255,107,122,.25)}
        button{background:rgba(68,209,157,.14);border:1px solid rgba(68,209,157,.25);color:#e8eefc;padding:10px 12px;border-radius:12px;cursor:pointer}
        .danger{background:rgba(255,107,122,.14);border-color:rgba(255,107,122,.25)}
        .mini{padding:7px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.06);color:#e8eefc;text-decoration:none;font-size:12px}
        .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        @media(max-width:900px){.grid2{grid-template-columns:1fr}}
      </style>
    </head>
    <body>
    <div class="wrap">
      <div class="top">
        <div>
          <h1>User Management</h1>
          <div class="muted">Only <b>admin</b> users can access this page. Logged in as <b><?= h($me['username']) ?></b>.</div>
          <?php if ($msg !== ''): ?>
            <div class="card" style="margin-top:10px;"><b><?= h($msg) ?></b></div>
          <?php endif; ?>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <a class="btn" href="<?= h(buildUrl(['page'=>null,'edit'=>null])) ?>">← Back to report</a>
          <a class="btn danger" href="<?= h(buildUrl(['action'=>'logout','page'=>null,'edit'=>null])) ?>">🚪 Logout</a>
        </div>
      </div>

      <div class="grid2">
        <div class="card">
          <b><?= $edit ? 'Edit User: ' . h($editUser) : 'Add User' ?></b>
          <div class="muted" style="margin-top:6px;">Extensions: comma/space separated digits (e.g. 1000,1001).</div>

          <form method="post" style="margin-top:10px;">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="uaction" value="<?= $edit ? 'update' : 'add' ?>">

            <label class="muted">Username</label>
            <input name="username" <?= $edit ? 'readonly' : '' ?> value="<?= h($editUser) ?>" placeholder="agent1">

            <div style="height:10px"></div>

            <label class="muted">Allowed Extensions</label>
            <textarea name="extensions" placeholder="1000,1001"><?= h($edit ? implode(', ', (array)($edit['extensions'] ?? [])) : '') ?></textarea>

            <div style="height:10px"></div>

            <label class="muted">
              <input type="checkbox" name="is_admin" value="1" <?= ($edit && !empty($edit['is_admin'])) ? 'checked' : '' ?>>
              Admin
            </label>

            <?php if (!$edit): ?>
              <div style="height:10px"></div>
              <label class="muted">Password</label>
              <input type="password" name="newpass1" placeholder="new password">
              <div style="height:8px"></div>
              <label class="muted">Repeat Password</label>
              <input type="password" name="newpass2" placeholder="repeat password">
            <?php endif; ?>

            <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
              <button type="submit"><?= $edit ? 'Save Changes' : 'Add User' ?></button>
              <?php if ($edit): ?>
                <a class="mini" href="<?= h(buildUrl(['page'=>'users','edit'=>null])) ?>">Cancel</a>
              <?php endif; ?>
            </div>
          </form>

          <?php if ($edit): ?>
            <div style="height:14px"></div>
            <b>Change Password</b>
            <form method="post" style="margin-top:10px;">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="uaction" value="changepass">
              <input type="hidden" name="username" value="<?= h($editUser) ?>">

              <label class="muted">New Password</label>
              <input type="password" name="newpass1" placeholder="new password">
              <div style="height:8px"></div>
              <label class="muted">Repeat New Password</label>
              <input type="password" name="newpass2" placeholder="repeat new password">

              <div style="margin-top:12px;">
                <button type="submit">Change Password</button>
              </div>
            </form>
          <?php endif; ?>
        </div>

        <div class="card">
          <b>Existing Users</b>
          <table>
            <thead>
              <tr><th>User</th><th>Admin</th><th>Extensions</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($usersData['users'] as $u => $info): ?>
              <tr>
                <td><?= h($u) ?></td>
                <td><?= !empty($info['is_admin']) ? 'Yes' : 'No' ?></td>
                <td class="muted"><?= h(implode(', ', (array)($info['extensions'] ?? []))) ?></td>
                <td style="white-space:nowrap;">
                  <a class="mini" href="<?= h(buildUrl(['page'=>'users','edit'=>$u])) ?>">Edit</a>
                  <?php if ($u !== 'admin'): ?>
                    <form method="post" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="uaction" value="delete">
                      <input type="hidden" name="username" value="<?= h($u) ?>">
                      <button class="danger" type="submit" onclick="return confirm(<?= json_encode("Delete user {$u}?") ?>);">Delete</button>
                    </form>
                  <?php else: ?>
                    <span class="muted">protected</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <div class="muted" style="margin-top:10px;">
            Tip: Edit a user to change their extensions or password.
          </div>
        </div>
      </div>

    </div>
    </body>
    </html>
    <?php
    exit;
}

/* -------------------- REPORT BELOW -------------------- */

/* ACL (by channel/dstchannel SIP/PJSIP) */
$allowedExts = $me['extensions'];
$aclWhere = '';
$aclParams = [];
if ($isAdmin) {
    $aclWhere = '1=1';
} else {
    if (count($allowedExts) === 0) $aclWhere = '1=0';
    else $aclWhere = aclWhereByChannel($allowedExts, $aclParams);
}

/* Inputs */
$action = strtolower((string)getParam('action', ''));
$uid    = (string)getParam('uid', '');

$from   = (string)getParam('from', date('Y-m-d'));
$to     = (string)getParam('to', date('Y-m-d'));
$format = strtolower((string)getParam('format', 'html'));

$q      = trim((string)getParam('q', ''));
$src    = trim((string)getParam('src', ''));
$dst    = trim((string)getParam('dst', ''));
$disp   = strtoupper(trim((string)getParam('disposition', '')));
$minDur = trim((string)getParam('mindur', ''));

$pageNo = max(1, (int)getParam('page', 1));
$per    = (int)getParam('per', 50);
if ($per < 10) $per = 10;
if ($per > 200) $per = 200;

$sort   = (string)getParam('sort', 'calldate');
$dir    = strtolower((string)getParam('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

if (!isValidDate($from) || !isValidDate($to)) fail("Invalid date (use YYYY-MM-DD)");
if ($to < $from) fail("Invalid date range: To must be same or later than From", 400);

if ($disp !== '' && !preg_match('/^[A-Z_ ]+$/', $disp)) $disp = '';
if ($src  !== '' && !preg_match('/^[0-9]+$/', $src)) $src = '';
if ($dst  !== '' && !preg_match('/^[0-9]+$/', $dst)) $dst = '';
if ($minDur !== '' && (!ctype_digit($minDur) || (int)$minDur < 0)) $minDur = '';

$allowedSort = ['calldate','src','dst','disposition','duration','billsec'];
if (!in_array($sort, $allowedSort, true)) $sort = 'calldate';

$fromDt = $from . ' 00:00:00';
$toDt   = $to   . ' 23:59:59';

/* Recording action (ACL protected by channel/dstchannel) */
if ($action === 'play' || $action === 'download') {
    if ($uid === '') fail("Missing uid", 400);

    $st = $pdo->prepare("SELECT calldate, channel, dstchannel, recordingfile FROM `{$cdrTable}` WHERE uniqueid = :uid LIMIT 1");
    $st->execute([':uid' => $uid]);
    $row = $st->fetch();
    if (!$row) { http_response_code(404); echo "Not found\n"; exit; }

    if (!$isAdmin) {
        $ch  = (string)($row['channel'] ?? '');
        $dch = (string)($row['dstchannel'] ?? '');
        if (!rowAllowedByChannel($allowedExts, $ch, $dch)) {
            http_response_code(403);
            echo "Forbidden\n";
            exit;
        }
    }

    $abs = findRecordingFile($recBaseDir, $row['recordingfile'] ?? null, $row['calldate'] ?? null);
    if (!$abs) { http_response_code(404); echo "Recording not found\n"; exit; }
    streamFile($abs, $action === 'download' ? 'attachment' : 'inline');
}

/* Build WHERE */
$where = [];
$params = [];

$where[] = "calldate >= :fromDt";
$where[] = "calldate <= :toDt";
$params[':fromDt'] = $fromDt;
$params[':toDt']   = $toDt;

/* ACL */
$where[] = $aclWhere;
$params = array_merge($params, $aclParams);

/* Src filter: src number OR channel */
if ($src !== '') {
    $where[] = "(
        src = :src_num
        OR channel LIKE :src_ch_sip
        OR channel LIKE :src_ch_psip
    )";
    $params[':src_num']     = $src;
    $params[':src_ch_sip']  = "SIP/{$src}-%";
    $params[':src_ch_psip'] = "PJSIP/{$src}-%";
}

/* Dst filter: dst number OR dstchannel */
if ($dst !== '') {
    $where[] = "(
        dst = :dst_num
        OR dstchannel LIKE :dst_ch_sip
        OR dstchannel LIKE :dst_ch_psip
    )";
    $params[':dst_num']     = $dst;
    $params[':dst_ch_sip']  = "SIP/{$dst}-%";
    $params[':dst_ch_psip'] = "PJSIP/{$dst}-%";
}

/* disposition filter */
if ($disp !== '') {
    if ($disp === 'NO ANSWER') {
        $where[] = "(disposition='NO ANSWER' OR disposition='NOANSWER')";
    } elseif ($disp === 'CONGESTION' || $disp === 'CONGESTED') {
        $where[] = "(disposition='CONGESTION' OR disposition='CONGESTED')";
    } else {
        $where[] = "disposition = :disp";
        $params[':disp'] = $disp;
    }
}

if ($minDur !== '') { $where[] = "billsec >= :mindur"; $params[':mindur'] = (int)$minDur; }

/* Search q */
if ($q !== '') {
    $where[] = "(src LIKE :q1 OR dst LIKE :q2 OR clid LIKE :q3 OR uniqueid LIKE :q4 OR channel LIKE :q5 OR dstchannel LIKE :q6)";
    $like = '%' . $q . '%';
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
    $params[':q5'] = $like;
    $params[':q6'] = $like;
}

$whereSql = implode(' AND ', $where);

/* Summary (includes congested) */
$sumSql = "
SELECT
  COUNT(*) AS total,
  SUM(CASE WHEN disposition='ANSWERED' THEN 1 ELSE 0 END) AS answered,
  SUM(CASE WHEN disposition='BUSY' THEN 1 ELSE 0 END) AS busy,
  SUM(CASE WHEN disposition IN ('NO ANSWER','NOANSWER') THEN 1 ELSE 0 END) AS noanswer,
  SUM(CASE WHEN disposition='FAILED' THEN 1 ELSE 0 END) AS failed,
  SUM(CASE WHEN disposition IN ('CONGESTION','CONGESTED') THEN 1 ELSE 0 END) AS congested,
  SUM(billsec) AS total_billsec
FROM `{$cdrTable}`
WHERE {$whereSql}
";
$sumSt = $pdo->prepare($sumSql);
$sumSt->execute($params);
$summary = $sumSt->fetch() ?: ['total'=>0,'answered'=>0,'busy'=>0,'noanswer'=>0,'failed'=>0,'congested'=>0,'total_billsec'=>0];

$total = (int)$summary['total'];
$pages = max(1, (int)ceil($total / $per));
if ($pageNo > $pages) $pageNo = $pages;
$offset = ($pageNo - 1) * $per;

/* Data page */
$dataSql = "
SELECT calldate, clid, src, dst, channel, dstchannel, dcontext, disposition, duration, billsec, uniqueid, recordingfile
FROM `{$cdrTable}`
WHERE {$whereSql}
ORDER BY {$sort} {$dir}
LIMIT :lim OFFSET :off
";
$dataSt = $pdo->prepare($dataSql);
foreach ($params as $k => $v) $dataSt->bindValue($k, $v);
$dataSt->bindValue(':lim', $per, PDO::PARAM_INT);
$dataSt->bindValue(':off', $offset, PDO::PARAM_INT);
$dataSt->execute();

/* CSV */
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cdr_'.$from.'_to_'.$to.'.csv"');
    echo csvRow(['calldate','clid','src','dst','channel','dstchannel','dcontext','disposition','duration','billsec','uniqueid','recordingfile']);

    $csvSql = "
    SELECT calldate, clid, src, dst, channel, dstchannel, dcontext, disposition, duration, billsec, uniqueid, recordingfile
    FROM `{$cdrTable}`
    WHERE {$whereSql}
    ORDER BY {$sort} {$dir}
    ";
    $csvSt = $pdo->prepare($csvSql);
    $csvSt->execute($params);
    while ($r = $csvSt->fetch()) {
        echo csvRow([
            $r['calldate'] ?? '',
            $r['clid'] ?? '',
            $r['src'] ?? '',
            $r['dst'] ?? '',
            $r['channel'] ?? '',
            $r['dstchannel'] ?? '',
            $r['dcontext'] ?? '',
            $r['disposition'] ?? '',
            $r['duration'] ?? '',
            $r['billsec'] ?? '',
            $r['uniqueid'] ?? '',
            $r['recordingfile'] ?? '',
        ]);
    }
    exit;
}

/* UI helpers */
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

$answered     = (int)$summary['answered'];
$busy         = (int)$summary['busy'];
$noanswer     = (int)$summary['noanswer'];
$failed       = (int)$summary['failed'];
$congested    = (int)$summary['congested'];
$totalBillsec = (int)$summary['total_billsec'];

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Supervisor CDR</title>

<style>
  :root{
    --bg:#0b1220;--card:#0f1a30;--muted:#9fb0d0;--text:#e8eefc;--line:rgba(255,255,255,.08);
    --accent:#7aa2ff;--ok:#44d19d;--warn:#ffcc66;--bad:#ff6b7a;
  }
  *{box-sizing:border-box}
  body{
    margin:0;
    background:linear-gradient(180deg,#070b14 0%, #0b1220 40%, #0b1220 100%);
    color:var(--text);
    font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;
  }
  .wrap{max-width:1200px;margin:0 auto;padding:18px;}
  @media(min-width:900px){.wrap{padding:22px;}}

  .topbar{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px;flex-wrap:wrap;}
  h1{margin:0;font-size:20px;}

  .sub{color:var(--muted);font-size:12px;margin-top:6px;display:flex;gap:8px;flex-wrap:wrap}
  .pill{display:inline-flex;padding:2px 8px;border-radius:999px;background:rgba(255,255,255,.06);font-size:12px;color:var(--muted);border:1px solid var(--line)}

  .btn{display:inline-flex;align-items:center;gap:8px;background:rgba(122,162,255,.12);border:1px solid rgba(122,162,255,.25);
       color:var(--text);padding:10px 12px;border-radius:12px;text-decoration:none;font-size:13px;white-space:nowrap;}
  .btn:hover{background:rgba(122,162,255,.18)}
  .btn.danger{background:rgba(255,107,122,.14);border-color:rgba(255,107,122,.25)}
  .btn.danger:hover{background:rgba(255,107,122,.22)}

  .card{background:rgba(15,26,48,.75);border:1px solid var(--line);border-radius:16px;padding:12px;}
  .k{font-size:12px;color:var(--muted);}
  .v{font-size:18px;margin-top:6px;font-weight:650;}

  .grid{display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:12px;}
  @media(min-width:520px){.grid{grid-template-columns:repeat(2, minmax(0,1fr));}}
  @media(min-width:900px){.grid{grid-template-columns:repeat(3, minmax(0,1fr));}}
  @media(min-width:1100px){.grid{grid-template-columns:repeat(6, minmax(0,1fr));}}

  .filters{display:grid;grid-template-columns:1fr;gap:10px}
  @media(min-width:520px){.filters{grid-template-columns:repeat(2, minmax(0,1fr));}}
  @media(min-width:900px){.filters{grid-template-columns:repeat(3, minmax(0,1fr));}}
  @media(min-width:1100px){.filters{grid-template-columns:repeat(6, minmax(0,1fr));}}

  label{display:block;font-size:12px;color:var(--muted);margin-bottom:6px}
  input,select{width:100%;background:rgba(255,255,255,.06);border:1px solid var(--line);color:var(--text);border-radius:12px;padding:10px;font-size:13px;outline:none}

  .muted{color:var(--muted);font-size:12px}
  .dtHint{margin-top:6px;color:var(--muted);font-size:12px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}

  .actions{display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
  button{background:rgba(68,209,157,.12);border:1px solid rgba(68,209,157,.25);color:var(--text);padding:10px 12px;border-radius:12px;font-size:13px;cursor:pointer;white-space:nowrap}
  button:hover{background:rgba(68,209,157,.18)}
  .actionsWide{grid-column:auto;}
  @media(min-width:900px){.actionsWide{grid-column:span 3;}}
  @media(min-width:1100px){.actionsWide{grid-column:span 2;}}

  table{width:100%;border-collapse:separate;border-spacing:0;min-width:980px;}
  thead th{position:sticky;top:0;background:rgba(15,26,48,.92);border-bottom:1px solid var(--line);
       font-size:12px;color:var(--muted);text-align:left;padding:10px;white-space:nowrap}
  tbody td{border-bottom:1px solid var(--line);padding:10px;font-size:13px;vertical-align:top}
  tbody tr:hover{background:rgba(255,255,255,.03)}
  a{color:var(--accent);text-decoration:none} a:hover{text-decoration:underline}

  .disp{font-size:12px;padding:3px 8px;border-radius:999px;display:inline-flex;border:1px solid var(--line);background:rgba(255,255,255,.04);white-space:nowrap}
  .disp.ok{border-color:rgba(68,209,157,.25);color:var(--ok);background:rgba(68,209,157,.08)}
  .disp.warn{border-color:rgba(255,204,102,.25);color:var(--warn);background:rgba(255,204,102,.08)}
  .disp.bad{border-color:rgba(255,107,122,.25);color:var(--bad);background:rgba(255,107,122,.08)}

  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;color:rgba(232,238,252,.85);word-break:break-all}

  .tableWrap{padding:0;overflow:auto;-webkit-overflow-scrolling:touch}
  audio{width:220px;max-width:100%}

  .pager{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:12px;flex-wrap:wrap}
  .pager .left{color:var(--muted);font-size:12px}
  .pager .right{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .mini{padding:8px 10px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.06);color:var(--text);font-size:12px;white-space:nowrap}

  /* --- Responsive table (mobile stacked rows) --- */
  @media (max-width: 900px){
    table{min-width:0 !important;}
    thead{display:none;}
    tbody tr{
      display:block;
      border-bottom:1px solid var(--line);
      padding:10px 10px 2px 10px;
    }
    tbody td{
      display:flex;
      gap:10px;
      justify-content:space-between;
      border-bottom:none;
      padding:8px 0;
    }
    tbody td::before{
      content: attr(data-label);
      color: var(--muted);
      font-size:12px;
      font-weight:600;
      padding-right:10px;
      white-space:nowrap;
    }
    tbody td[data-label="Recording"]{
      flex-direction:column;
      align-items:flex-start;
    }
    audio{width:100%;}
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div>
      <h1>Supervisor CDR</h1>
      <div class="sub">
        <span class="pill">User: <b><?= h($me['username']) ?></b><?= $isAdmin ? ' (admin)' : '' ?></span>
        <span class="pill">From <?= h($from) ?> to <?= h($to) ?></span>
        <?php if (!$isAdmin): ?><span class="pill">Allowed: <?= h(implode(', ', $me['extensions'])) ?></span><?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <?php if ($isAdmin): ?><a class="btn" href="<?= h(buildUrl(['page'=>'users'])) ?>">👤 User Management</a><?php endif; ?>
      <a class="btn" href="<?= h(buildUrl(['format'=>'csv','page'=>1])) ?>">⬇ Export CSV</a>
      <a class="btn danger" href="<?= h(buildUrl(['action'=>'logout'])) ?>">🚪 Logout</a>
    </div>
  </div>

  <!-- Totals cards -->
  <div class="grid">
    <div class="card"><div class="k">Total Calls</div><div class="v"><?= (int)$total ?></div></div>
    <div class="card"><div class="k">Answered</div><div class="v" style="color:var(--ok)"><?= (int)$answered ?></div></div>
    <div class="card"><div class="k">No Answer</div><div class="v" style="color:var(--warn)"><?= (int)$noanswer ?></div></div>
    <div class="card"><div class="k">Busy</div><div class="v" style="color:var(--bad)"><?= (int)$busy ?></div></div>
    <div class="card"><div class="k">Failed</div><div class="v" style="color:var(--bad)"><?= (int)$failed ?></div></div>
    <div class="card"><div class="k">Congested</div><div class="v" style="color:var(--bad)"><?= (int)$congested ?></div></div>
  </div>

  <!-- Totals Bar Chart card -->
  <div class="card" style="margin-bottom:12px;">
    <div class="k" style="margin-bottom:10px;">Totals Bar Chart</div>
    <div style="height:260px;"><canvas id="chartTotals"></canvas></div>
    <div id="chartColorMap" class="muted" style="margin-top:10px; display:flex; flex-wrap:wrap;"></div>
    <div class="muted" style="margin-top:10px;">
      Total=<?= (int)$total ?> |
      Answered=<?= (int)$answered ?> |
      NoAnswer=<?= (int)$noanswer ?> |
      Busy=<?= (int)$busy ?> |
      Failed=<?= (int)$failed ?> |
      Congested=<?= (int)$congested ?> |
      TalkTime=<?= h(fmtTime($totalBillsec)) ?>
    </div>
  </div>

  <!-- Filters -->
  <div class="card" style="margin-bottom:12px;">
    <form method="get" action="">
      <div class="filters">

        <div>
          <label>From</label>
          <input type="date" id="fromDate" name="from" value="<?= h($from) ?>" required>
          <div class="dtHint" id="fromPretty"><?= h(fmtFromTableStyle($from)) ?></div>
        </div>

        <div>
          <label>To</label>
          <input type="date" id="toDate" name="to" value="<?= h($to) ?>" required>
          <div class="dtHint" id="toPretty"><?= h(fmtToTableStyle($to)) ?></div>
        </div>

        <div><label>Search</label><input name="q" value="<?= h((string)$q) ?>" placeholder="src, dst, clid, uniqueid, channel"></div>

        <div><label>Src (number OR channel)</label><input name="src" value="<?= h((string)$src) ?>" placeholder="1001"></div>
        <div><label>Dst (number OR dstchannel)</label><input name="dst" value="<?= h((string)$dst) ?>" placeholder="2000"></div>

        <div>
          <label>Disposition</label>
          <select name="disposition">
            <option value="">Any</option>
            <?php foreach (['ANSWERED','NO ANSWER','BUSY','FAILED','CONGESTION'] as $opt): ?>
              <option value="<?= h($opt) ?>" <?= ($disp===$opt)?'selected':''; ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div><label>Min billsec</label><input name="mindur" value="<?= h((string)$minDur) ?>" placeholder="0"></div>
        <div>
          <label>Per page</label>
          <select name="per">
            <?php foreach ([25,50,100,200] as $n): ?>
              <option value="<?= (int)$n ?>" <?= ($per===$n)?'selected':''; ?>><?= (int)$n ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Sort</label>
          <select name="sort">
            <?php foreach (['calldate'=>'Call Date','src'=>'Src','dst'=>'Dst','disposition'=>'Disposition','duration'=>'Duration','billsec'=>'Billsec'] as $k=>$lbl): ?>
              <option value="<?= h($k) ?>" <?= ($sort===$k)?'selected':''; ?>><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Direction</label>
          <select name="dir">
            <option value="desc" <?= ($dir==='desc')?'selected':''; ?>>DESC</option>
            <option value="asc"  <?= ($dir==='asc')?'selected':''; ?>>ASC</option>
          </select>
        </div>

        <div class="actions actionsWide">
          <button type="submit">Apply</button>
          <a class="btn" href="<?= h(buildUrl(['q'=>null,'src'=>null,'dst'=>null,'disposition'=>null,'mindur'=>null,'page'=>1,'format'=>'html'])) ?>">Reset</a>
        </div>
      </div>
      <input type="hidden" name="format" value="html">
    </form>
  </div>

  <!-- Table -->
  <div class="card tableWrap">
    <table>
      <thead>
        <tr>
          <th><?= sortLink('calldate','Call Date',$sort,$dir) ?></th>
          <th>CLID</th>
          <th><?= sortLink('src','SRC',$sort,$dir) ?></th>
          <th><?= sortLink('dst','DST',$sort,$dir) ?></th>
          <th>Channel</th>
          <th>DstChannel</th>
          <th>Context</th>
          <th><?= sortLink('disposition','Disposition',$sort,$dir) ?></th>
          <th><?= sortLink('billsec','Billsec',$sort,$dir) ?></th>
          <th class="mono">UniqueID</th>
          <th>Recording</th>
        </tr>
      </thead>
      <tbody>
        <?php
          $shown = 0;
          while ($r = $dataSt->fetch()):
            $shown++;
            $d = strtoupper((string)($r['disposition'] ?? ''));
            $cls = 'warn';
            if ($d === 'ANSWERED') $cls = 'ok';
            elseif ($d === 'BUSY' || $d === 'FAILED' || $d === 'CONGESTION' || $d === 'CONGESTED') $cls = 'bad';

            $uidVal = (string)($r['uniqueid'] ?? '');
            $recVal = trim((string)($r['recordingfile'] ?? ''));
            $hasRec = ($uidVal !== '' && $recVal !== '');
            $playUrl = buildUrl(['action'=>'play','uid'=>$uidVal]);
            $dlUrl   = buildUrl(['action'=>'download','uid'=>$uidVal]);
        ?>
          <tr>
            <td data-label="Call Date"><?= h((string)($r['calldate'] ?? '')) ?></td>
            <td data-label="CLID"><?= h((string)($r['clid'] ?? '')) ?></td>
            <td data-label="SRC"><?= h((string)($r['src'] ?? '')) ?></td>
            <td data-label="DST"><?= h((string)($r['dst'] ?? '')) ?></td>
            <td data-label="Channel" class="mono"><?= h((string)($r['channel'] ?? '')) ?></td>
            <td data-label="DstChannel" class="mono"><?= h((string)($r['dstchannel'] ?? '')) ?></td>
            <td data-label="Context"><?= h((string)($r['dcontext'] ?? '')) ?></td>
            <td data-label="Disposition"><span class="disp <?= h($cls) ?>"><?= h((string)($r['disposition'] ?? '')) ?></span></td>
            <td data-label="Billsec"><?= h((string)($r['billsec'] ?? '0')) ?></td>
            <td data-label="UniqueID" class="mono"><?= h($uidVal) ?></td>
            <td data-label="Recording">
              <?php if ($hasRec): ?>
                <div class="mono" style="opacity:.7; margin-bottom:6px;"><?= h($recVal) ?></div>
                <audio controls preload="none" src="<?= h($playUrl) ?>"></audio><br>
                <a href="<?= h($playUrl) ?>" target="_blank">Listen</a> ·
                <a href="<?= h($dlUrl) ?>">Download</a>
              <?php else: ?>
                <span class="pill">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endwhile; ?>
        <?php if ($shown === 0): ?>
          <tr><td colspan="11" style="color:var(--muted);padding:16px;">No records for this filter.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pager -->
  <div class="pager">
    <div class="left">Showing <?= (int)$shown ?> of <?= (int)$total ?> · Page <?= (int)$pageNo ?> / <?= (int)$pages ?></div>
    <div class="right">
      <?php $prev = max(1, $pageNo - 1); $next = min($pages, $pageNo + 1); ?>
      <a class="mini" href="<?= h(buildUrl(['page'=>1])) ?>">⟪ First</a>
      <a class="mini" href="<?= h(buildUrl(['page'=>$prev])) ?>">‹ Prev</a>
      <span class="mini">Page <?= (int)$pageNo ?></span>
      <a class="mini" href="<?= h(buildUrl(['page'=>$next])) ?>">Next ›</a>
      <a class="mini" href="<?= h(buildUrl(['page'=>$pages])) ?>">Last ⟫</a>
    </div>
  </div>

</div>

<!-- Chart.js (LOCAL) -->
<script src="chart.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // --- Date picker constraints: To >= From (no "today" restriction) + show table-style datetime under each ---
  const from = document.getElementById('fromDate');
  const to   = document.getElementById('toDate');
  const fromPretty = document.getElementById('fromPretty');
  const toPretty   = document.getElementById('toPretty');

  function refreshPretty(){
    if (fromPretty) fromPretty.textContent = (from && from.value ? (from.value + ' 00:00:00') : '');
    if (toPretty)   toPretty.textContent   = (to && to.value ? (to.value + ' 23:59:59') : '');
  }

  if (from && to) {
    // init constraints
    if (from.value) to.min = from.value;
    if (to.value) from.max = to.value;
    refreshPretty();

    from.addEventListener('change', () => {
      to.min = from.value || '';
      if (to.value && from.value && to.value < from.value) to.value = from.value;
      refreshPretty();
    });

    to.addEventListener('change', () => {
      from.max = to.value || '';
      if (from.value && to.value && from.value > to.value) from.value = to.value;
      refreshPretty();
    });
  }

  // --- Chart ---
  if (typeof Chart === 'undefined') {
    console.log('Chart.js not loaded (chart.umd.min.js missing or blocked).');
    return;
  }

  const totals = <?= json_encode([
      'total'      => (int)$total,
      'answered'   => (int)$answered,
      'noanswer'   => (int)$noanswer,
      'busy'       => (int)$busy,
      'failed'     => (int)$failed,
      'congested'  => (int)$congested,
  ], JSON_NUMERIC_CHECK) ?>;

  const labels = ['Total','Answered','No Answer','Busy','Failed','Congested'];
  const values = [totals.total, totals.answered, totals.noanswer, totals.busy, totals.failed, totals.congested];

  const colorMap = {
    'Total':     { bg: 'rgba(122,162,255,0.35)', border: 'rgba(122,162,255,0.95)' }, // blue
    'Answered':  { bg: 'rgba(68,209,157,0.35)', border: 'rgba(68,209,157,0.95)' },   // green
    'No Answer': { bg: 'rgba(255,204,102,0.35)', border: 'rgba(255,204,102,0.95)' }, // amber
    'Busy':      { bg: 'rgba(255,107,122,0.35)', border: 'rgba(255,107,122,0.95)' }, // red
    'Failed':    { bg: 'rgba(255,107,122,0.25)', border: 'rgba(255,107,122,0.85)' }, // red light
    'Congested': { bg: 'rgba(255,94,0,0.30)',    border: 'rgba(255,94,0,0.95)' },     // orange
  };

  const backgroundColors = labels.map(l => (colorMap[l] ? colorMap[l].bg : 'rgba(255,255,255,0.2)'));
  const borderColors     = labels.map(l => (colorMap[l] ? colorMap[l].border : 'rgba(255,255,255,0.6)'));

  const mapEl = document.getElementById('chartColorMap');
  if (mapEl) {
    mapEl.innerHTML = labels.map(l => {
      const c = colorMap[l] || { border: 'rgba(255,255,255,0.6)' };
      return `
        <span style="display:inline-flex;align-items:center;gap:8px;margin:6px 14px 0 0;">
          <span style="width:12px;height:12px;border-radius:3px;background:${c.border};display:inline-block;"></span>
          <span>${l}</span>
        </span>
      `;
    }).join('');
  }

  const el = document.getElementById('chartTotals');
  if (!el) return;

  new Chart(el, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Calls',
        data: values,
        backgroundColor: backgroundColors,
        borderColor: borderColors,
        borderWidth: 2,
        borderRadius: 10
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${ctx.parsed.y}` } }
      },
      scales: {
        y: { beginAtZero: true, ticks: { precision: 0 } },
        x: { ticks: { maxRotation: 0 } }
      }
    }
  });
});
</script>

</body>
</html>

