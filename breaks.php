<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$usersData = loadUsers($CONFIG['usersFile']);

$action = strtolower((string)getParam('action', ''));
if ($action === 'logout') doLogout();

$me      = requireSessionLogin($usersData);
$isAdmin = (bool)$me['is_admin'];

$from   = (string)getParam('from', date('Y-m-d', strtotime('-6 days')));
$to     = (string)getParam('to',   date('Y-m-d'));
$format = strtolower((string)getParam('format', 'html'));

if (!isValidDate($from) || !isValidDate($to)) fail("Invalid date (use YYYY-MM-DD)", 400);
if ($to < $from) fail("Invalid date range: To must be same or later than From", 400);

/* Extension multi-select */
$extParam = $_GET['ext'] ?? '';
if (is_array($extParam)) {
    $ext = implode(',', array_filter(array_map('trim', $extParam), fn($e) => preg_match('/^[0-9]+$/', $e)));
} else {
    $ext = trim((string)$extParam);
    if ($ext !== '' && !preg_match('/^[0-9,\s]+$/', $ext)) $ext = '';
}
$selectedExts = $ext !== '' ? array_values(array_filter(preg_split('/[,\s]+/', $ext), fn($e) => $e !== '')) : [];

/* Known local extensions (for the dropdown) */
$availableExtensions = fetchKnownExtensions($pdo, $me, $from, $to);

/* Fetch all agent events then compute breaks */
$dailyAgentEvents = fetchDailyAgentEvents($pdo, $me, $from, $to);

/* Filter by selected extensions if any */
if (!empty($selectedExts)) {
    $dailyAgentEvents = array_intersect_key($dailyAgentEvents, array_flip($selectedExts));
}

$breaksData = computeBreaksData($dailyAgentEvents);
ksort($breaksData);

if ($format === 'excel') {
    streamExcelBreaks($breaksData, $from, $to);
    exit;
}

require __DIR__ . '/ui/breaks.php';
