<?php
declare(strict_types=1);

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
    $LOGIN_ERROR = $err;
    require __DIR__ . '/../ui/login.php';
    exit;
}

function handleUsersPage(array $CONFIG, array $usersData, array $me): void {
    if (empty($me['is_admin'])) fail("Forbidden", 403);

    startSecureSession();
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
                    saveUsers($CONFIG['usersFile'], $usersData);
                    $msg = 'User added';
                }
            } elseif ($uaction === 'update') {
                if (!isset($usersData['users'][$uname])) {
                    $msg = 'User not found';
                } else {
                    $usersData['users'][$uname]['is_admin'] = $isAdm;
                    $usersData['users'][$uname]['extensions'] = normalizeExtList($extCsv);
                    saveUsers($CONFIG['usersFile'], $usersData);
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
                    saveUsers($CONFIG['usersFile'], $usersData);
                    $msg = 'Password changed';
                }
            } elseif ($uaction === 'delete') {
                if ($uname === 'admin') {
                    $msg = 'Refusing to delete admin';
                } elseif (!isset($usersData['users'][$uname])) {
                    $msg = 'User not found';
                } else {
                    unset($usersData['users'][$uname]);
                    saveUsers($CONFIG['usersFile'], $usersData);
                    $msg = 'User deleted';
                }
            } else {
                $msg = 'Unknown action';
            }
        }

        $usersData = loadUsers($CONFIG['usersFile']);
        $editUser = '';
    }

    $edit = null;
    if ($editUser !== '' && isset($usersData['users'][$editUser])) $edit = $usersData['users'][$editUser];

    header('Content-Type: text/html; charset=utf-8');
    $USERS_DATA = $usersData;
    $ME = $me;
    $CSRF = $csrf;
    $MSG = $msg;
    $EDIT_USER = $editUser;
    $EDIT_REC = $edit;
    require __DIR__ . '/../ui/users.php';
    exit;
}

