<?php
declare(strict_types=1);

namespace Supervisor\Lib;

/**
 * Session auth (login/logout)
 * Depends on:
 *  - helpers.php (fail(), h(), postParam())
 *  - users_store.php (usersData structure)
 */

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    // Avoid warnings if headers already sent
    if (!headers_sent()) {
        session_set_cookie_params([
            'httponly' => true,
            'secure'   => $secure,
            'samesite' => 'Lax',
            'path'     => '/',
        ]);
    }
    session_start();
}

function doLogout(): void {
    startSecureSession();
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    session_destroy();
    $base = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    header('Location: ' . $base);
    exit;
}

/**
 * Returns:
 *  ['username'=>string,'is_admin'=>bool,'extensions'=>string[]]
 */
function requireSessionLogin(array $usersData): array {
    startSecureSession();

    // Already logged in?
    if (!empty($_SESSION['auth_user']) && is_string($_SESSION['auth_user'])) {
        $u = $_SESSION['auth_user'];
        if (isset($usersData['users'][$u]) && is_array($usersData['users'][$u])) {
            $rec = $usersData['users'][$u];

            $isAdmin = !empty($rec['is_admin']);
            $exts = [];

            if (isset($rec['extensions']) && is_array($rec['extensions'])) {
                foreach ($rec['extensions'] as $e) {
                    $e = trim((string)$e);
                    if ($e !== '' && preg_match('/^[0-9]+$/', $e)) $exts[$e] = true;
                }
            }

            return [
                'username' => $u,
                'is_admin' => (bool)$isAdmin,
                'extensions' => array_values(array_keys($exts)),
            ];
        }

        // user removed from config
        unset($_SESSION['auth_user']);
    }

    $err = '';

    // Handle login post
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)postParam('login', '') === '1') {
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

                // Keep current GET query on redirect (so filters persist after login)
                $base = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
                $qs = '';
                if (!empty($_GET)) $qs = '?' . http_build_query($_GET);

                header('Location: ' . $base . $qs);
                exit;
            }
        }
    }

    // Render login page
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

