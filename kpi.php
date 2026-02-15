<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/* Load users */
$usersData = loadUsers($CONFIG['usersFile']);

/* Logout */
$action = strtolower((string)getParam('action', ''));
if ($action === 'logout') {
    doLogout();
}

/* Require login */
$me = requireSessionLogin($usersData);
$isAdmin = (bool)$me['is_admin'];

/* Admin User Management */
$page = (string)getParam('page', '');
if ($page === 'users') {
    handleUsersPage($CONFIG, $usersData, $me);
    exit;
}

/* Inputs */
$from   = (string)getParam('from', date('Y-m-d'));
$to     = (string)getParam('to', date('Y-m-d'));
$format = strtolower((string)getParam('format', 'html'));

/* Get available gateways from config */
$availableGateways = $CONFIG['gateways'] ?? [];

$q      = trim((string)getParam('q', ''));
$src    = trim((string)getParam('src', ''));
$dst    = trim((string)getParam('dst', ''));
$disp   = strtoupper(trim((string)getParam('disposition', '')));
$minDur = trim((string)getParam('mindur', ''));
$preset = trim((string)getParam('preset', ''));
$gateway = trim((string)getParam('gateway', ''));

if (!isValidDate($from) || !isValidDate($to)) fail("Invalid date (use YYYY-MM-DD)", 400);
if ($to < $from) fail("Invalid date range: To must be same or later than From", 400);

$filters = [
    'from' => $from,
    'to' => $to,
    'q' => $q,
    'src' => $src,
    'dst' => $dst,
    'disposition' => $disp,
    'mindur' => $minDur,
    'preset' => $preset,
    'gateway' => $gateway,
];

/* Fetch Extension KPIs */
$kpiData = fetchExtensionKPIs($CONFIG, $pdo, $me, $filters);

/* Calculate totals */
$totalCalls = 0;
$totalAnswered = 0;
$totalMissed = 0;
$totalAbandoned = 0;
$totalBusy = 0;
$totalBillsec = 0;

foreach ($kpiData as $ext) {
    $totalCalls += (int)($ext['total_calls'] ?? 0);
    $totalAnswered += (int)($ext['answered'] ?? 0);
    $totalMissed += (int)($ext['missed'] ?? 0);
    $totalAbandoned += (int)($ext['abandoned'] ?? 0);
    $totalBusy += (int)($ext['busy'] ?? 0);
    $totalBillsec += (int)($ext['total_billsec'] ?? 0);
}

require __DIR__ . '/ui/kpi.php';
