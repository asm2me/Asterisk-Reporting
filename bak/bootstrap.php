<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db_creds.php';          // ✅ global getDbCreds()
require_once __DIR__ . '/lib/users_store.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/acl.php';
require_once __DIR__ . '/lib/recordings.php';
require_once __DIR__ . '/lib/cdr.php';

$CONFIG = [
    'cdrDb'      => getenv('CDR_DB') ?: 'asteriskcdrdb',
    'cdrTable'   => getenv('CDR_TABLE') ?: 'cdr',
    'recBaseDir' => getenv('REC_BASEDIR') ?: '/var/spool/asterisk/monitor',
    'usersFile'  => __DIR__ . '/config_users.json',
    'assetsUrl'  => 'assets',
];

startSecureSession();

$dbc = getDbCreds();  // ✅ global function

$dsn = sprintf(
    'mysql:host=%s;%sdbname=%s;charset=utf8mb4',
    $dbc['host'],
    (!empty($dbc['port']) ? ('port='.(int)$dbc['port'].';') : ''),
    $CONFIG['cdrDb']
);

try {
    $pdo = new PDO($dsn, $dbc['user'], $dbc['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    fail("DB connection failed: " . $e->getMessage(), 500);
}

