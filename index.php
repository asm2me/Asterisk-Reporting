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

/* Recording actions */
$action = strtolower((string)getParam('action', ''));
if ($action === 'play' || $action === 'download') {
    handleRecordingRequest($CONFIG, $pdo, $me, $action);
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

/* Set default gateway to first available if not specified */
if ($gateway === '' && !empty($availableGateways)) {
    $gateway = $availableGateways[0];
}

$pageNo = max(1, (int)getParam('page', 1));
$per    = (int)getParam('per', 50);
if ($per < 10) $per = 10;
if ($per > 200) $per = 200;

$sort   = (string)getParam('sort', 'calldate');
$dir    = strtolower((string)getParam('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

if (!isValidDate($from) || !isValidDate($to)) fail("Invalid date (use YYYY-MM-DD)", 400);
if ($to < $from) fail("Invalid date range: To must be same or later than From", 400);

if ($disp !== '' && !preg_match('/^[A-Z_ ]+$/', $disp)) $disp = '';
if ($src  !== '' && !preg_match('/^[0-9]+$/', $src)) $src = '';
if ($dst  !== '' && !preg_match('/^[0-9]+$/', $dst)) $dst = '';
if ($minDur !== '' && (!ctype_digit($minDur) || (int)$minDur < 0)) $minDur = '';

$allowedSort = ['calldate','src','dst','disposition','duration','billsec'];
if (!in_array($sort, $allowedSort, true)) $sort = 'calldate';

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
    'page' => $pageNo,
    'per' => $per,
    'sort' => $sort,
    'dir' => $dir,
];

$summary = fetchSummary($CONFIG, $pdo, $me, $filters);
$total   = (int)($summary['total'] ?? 0);
$maxConcurrent = fetchMaxConcurrentCalls($CONFIG, $pdo, $me, $filters);

$pages = max(1, (int)ceil($total / $per));
if ($pageNo > $pages) $pageNo = $pages;
$filters['page'] = $pageNo;

if ($format === 'csv') {
    streamCsv($CONFIG, $pdo, $me, $filters);
    exit;
}

$rows = fetchPageRows($CONFIG, $pdo, $me, $filters);

require __DIR__ . '/ui/report.php';

