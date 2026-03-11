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
// Support multi-select: ext[] array or ext comma-separated string
$extParam = $_GET['ext'] ?? '';
if (is_array($extParam)) {
    $ext = implode(',', array_filter(array_map('trim', $extParam), fn($e) => preg_match('/^[0-9]+$/', $e)));
} else {
    $ext = trim((string)$extParam);
    if ($ext !== '' && !preg_match('/^[0-9,\s]+$/', $ext)) $ext = '';
}
$selectedExts = $ext !== '' ? array_filter(preg_split('/[,\s]+/', $ext), fn($e) => $e !== '') : [];

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
    'ext' => $ext,
    'disposition' => $disp,
    'mindur' => $minDur,
    'preset' => $preset,
    'gateway' => $gateway,
];

/* Get known local extensions from agent_event table */
$knownExtensions = fetchKnownExtensions($pdo, $me, $from, $to);
$availableExtensions = $knownExtensions;

/* Fetch Extension KPIs */
$kpiData = fetchExtensionKPIs($CONFIG, $pdo, $me, $filters);

/* Filter KPIs to only known local extensions from agent_event table,
   and add entries for extensions that have agent events but no CDR data */
if (!empty($knownExtensions)) {
    $kpiData = array_filter($kpiData, fn($row) => in_array($row['extension'], $knownExtensions));
    $kpiExts = array_column($kpiData, 'extension');
    foreach ($knownExtensions as $ke) {
        if (!in_array($ke, $kpiExts)) {
            $kpiData[] = [
                'extension' => $ke, 'total_calls' => 0, 'answered' => 0,
                'missed' => 0, 'abandoned' => 0, 'busy' => 0, 'failed' => 0,
                'total_billsec' => 0, 'avg_talk_time' => 0, 'avg_wait_time' => 0,
            ];
        }
    }
    $kpiData = array_values($kpiData);
}

if ($format === 'excel') {
    $dailyData = fetchDailyExtensionKPIs($CONFIG, $pdo, $me, $filters);
    streamExcelKpis($kpiData, $dailyData, $from, $to);
    exit;
}

/* Calculate totals */
$totalCalls = 0;
$totalAnswered = 0;
$totalMissed = 0;
$totalAbandoned = 0;
$totalBusy = 0;
$totalBillsec = 0;

foreach ($kpiData as $ext) {
    $totalCalls    += (int)($ext['total_calls']   ?? 0);
    $totalAnswered += (int)($ext['answered']       ?? 0);
    $totalMissed   += (int)($ext['missed']         ?? 0);
    $totalAbandoned+= (int)($ext['abandoned']      ?? 0);
    $totalBusy     += (int)($ext['busy']           ?? 0);
    $totalBillsec  += (int)($ext['total_billsec']  ?? 0);
}

require __DIR__ . '/ui/kpi.php';
