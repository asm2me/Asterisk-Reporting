<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db_creds.php';          // ✅ global getDbCreds()
require_once __DIR__ . '/lib/users_store.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/acl.php';
require_once __DIR__ . '/lib/recordings.php';
require_once __DIR__ . '/lib/cdr.php';

// Load project configuration from config.json
$configFile = __DIR__ . '/config.json';
$projectConfig = [];
if (file_exists($configFile)) {
    $configJson = file_get_contents($configFile);
    $projectConfig = json_decode($configJson, true) ?: [];
}

$CONFIG = [
    'cdrDb'      => getenv('CDR_DB') ?: ($projectConfig['database']['cdrDb'] ?? 'asteriskcdrdb'),
    'cdrTable'   => getenv('CDR_TABLE') ?: ($projectConfig['database']['cdrTable'] ?? 'cdr'),
    'recBaseDir' => getenv('REC_BASEDIR') ?: ($projectConfig['asterisk']['recordings']['baseDir'] ?? '/var/spool/asterisk/monitor'),
    'usersFile'  => __DIR__ . '/config_users.json',
    'assetsUrl'  => $projectConfig['ui']['assetsUrl'] ?? 'assets',
    'gateways'   => $projectConfig['asterisk']['gateways'] ?? ['PJSIP/we'],
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

