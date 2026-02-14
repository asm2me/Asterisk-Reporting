<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php'; // provides: $pdo, $me, $isAdmin, $allowedExts, $acl, $usersData, $settings

// recording helpers + endpoint support
require_once __DIR__ . '/lib/recordings.php';

// --- small local CSV streamer (cannot be missing) ---
function streamCsv(PDO $pdo, array $filters, array $acl, array $allowedExts, string $cdrTable): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cdr_'.$filters['from'].'_to_'.$filters['to'].'_'.$filters['group'].'.csv"');

    $group = (string)$filters['group'];

    if ($group === 'ext') {
        echo "ext,last_calldate,calls,answered_calls,missed_calls,total_billsec\n";
        $rows = Supervisor\Lib\fetchByExtension($pdo, $filters, $acl, $allowedExts, $cdrTable);
        foreach ($rows as $r) {
            echo Supervisor\Lib\csvRow([
                $r['ext'] ?? '',
                $r['last_calldate'] ?? '',
                $r['calls'] ?? 0,
                $r['answered_calls'] ?? 0,
                $r['missed_calls'] ?? 0,
                $r['total_billsec'] ?? 0,
            ]);
        }
        exit;
    }

    // default: grouped calls
    echo "grpkey,start_calldate,clid,src,dst,channel,dstchannel,status,total_billsec,legs\n";
    $rows = Supervisor\Lib\fetchPageRows($pdo, $filters, $acl, $allowedExts, $cdrTable);
    foreach ($rows as $r) {
        echo Supervisor\Lib\csvRow([
            $r['grpkey'] ?? '',
            $r['start_calldate'] ?? '',
            $r['clid'] ?? '',
            $r['src'] ?? '',
            $r['dst'] ?? '',
            $r['channel'] ?? '',
            $r['dstchannel'] ?? '',
            $r['status'] ?? '',
            $r['total_billsec'] ?? 0,
            $r['legs'] ?? 0,
        ]);
    }
    exit;
}

// --- actions ---
$action = strtolower((string)($_GET['action'] ?? ''));

// --- Inputs (needed for actions too) ---
$from = (string)($_GET['from'] ?? date('Y-m-d'));
$to   = (string)($_GET['to'] ?? date('Y-m-d'));

$preset  = (string)($_GET['preset'] ?? '');
$gateway = (string)($_GET['gateway'] ?? '');
$group   = (string)($_GET['group'] ?? 'call'); // call|ext
$q       = (string)($_GET['q'] ?? '');
$src     = (string)($_GET['src'] ?? '');
$dst     = (string)($_GET['dst'] ?? '');
$disp    = (string)($_GET['disposition'] ?? '');
$mindur  = (string)($_GET['mindur'] ?? '');

$pageNo  = max(1, (int)($_GET['page'] ?? 1));
$per     = (int)($_GET['per'] ?? 50);
$sort    = (string)($_GET['sort'] ?? 'start_calldate');
$dir     = (string)($_GET['dir'] ?? 'desc');
$format  = (string)($_GET['format'] ?? 'html');

// if no gateway selected in UI, use first configured gateway (if exists)
if (trim($gateway) === '' && !empty($settings['gateways'][0])) {
    $gateway = (string)$settings['gateways'][0];
}

$filters = [
    'from' => $from,
    'to'   => $to,
    'fromDt' => $from . ' 00:00:00',
    'toDt'   => $to   . ' 23:59:59',

    'preset' => $preset,
    'gateway'=> $gateway,
    'group'  => $group,

    'q' => $q,
    'src' => $src,
    'dst' => $dst,
    'disposition' => $disp,
    'mindur' => $mindur,

    'page' => $pageNo,
    'per'  => $per,
    'sort' => $sort,
    'dir'  => $dir,
    'format' => $format,
];

// AJAX transitions endpoint
if ($action === 'transitions') {
    header('Content-Type: application/json; charset=utf-8');
    $grpkey = trim((string)($_GET['grpkey'] ?? ''));
    if ($grpkey === '') { echo json_encode(['ok'=>false,'error'=>'Missing grpkey']); exit; }

    $filtersTmp = $filters;
    $filtersTmp['page'] = 1;
    $filtersTmp['per']  = 200;
    $filtersTmp['sort'] = 'start_calldate';
    $filtersTmp['dir']  = 'asc';
    $filtersTmp['format'] = 'html';

    try {
        $legs = Supervisor\Lib\fetchTransitions($pdo, $grpkey, $filtersTmp, $acl, $settings['cdr_table']);
        echo json_encode(['ok'=>true,'legs'=>$legs], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// Recording playback endpoint (secure + ACL + date-range constrained)
if ($action === 'recording') {
    $uniqueid = trim((string)($_GET['uniqueid'] ?? ''));
    if ($uniqueid === '') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Missing uniqueid\n";
        exit;
    }

    // Constrain lookup by current date range and ACL
    $leg = Supervisor\Lib\fetchLegByUniqueid($pdo, $uniqueid, $filters, $acl, $settings['cdr_table']);
    if (!$leg) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found\n";
        exit;
    }

    $recBase = getenv('RECORDINGS_DIR') ?: '/var/spool/asterisk/monitor';
    $abs = Supervisor\Lib\findRecordingFile((string)$recBase, (string)($leg['recordingfile'] ?? ''), (string)($leg['calldate'] ?? ''));
    if (!$abs) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Recording not found\n";
        exit;
    }

    Supervisor\Lib\streamFile($abs, 'inline');
    exit;
}

// --- CSV export ---
if (strtolower($format) === 'csv') {
    streamCsv($pdo, $filters, $acl, $allowedExts, $settings['cdr_table']);
}

// --- summary + data ---
$summary = Supervisor\Lib\fetchSummary($pdo, $filters, $acl, $allowedExts, $settings['cdr_table']);

// IMPORTANT: correct pager totals for group=ext
if ($group === 'ext') {
    $totalItems = Supervisor\Lib\countExtensions($pdo, $filters, $acl, $allowedExts, $settings['cdr_table']);
} else {
    $totalItems = Supervisor\Lib\countGroups($pdo, $filters, $acl, $allowedExts, $settings['cdr_table']);
}

$pages = max(1, (int)ceil($totalItems / max(1, (int)$per)));
if ($pageNo > $pages) { $pageNo = $pages; $filters['page'] = $pageNo; }

$rows = [];
$extRows = [];
if ($group === 'ext') {
    $extRows = Supervisor\Lib\fetchByExtension($pdo, $filters, $acl, $allowedExts, $settings['cdr_table']);
} else {
    $rows = Supervisor\Lib\fetchPageRows($pdo, $filters, $acl, $allowedExts, $settings['cdr_table']);
}

// --- render UI ---
$ui = __DIR__ . '/ui/report.php';
if (!is_readable($ui)) Supervisor\Lib\fail("UI missing: {$ui}");

/** variables for ui/report.php */
$me_for_ui = $me;
$isAdmin_for_ui = $isAdmin;
$allowedExts_for_ui = $allowedExts;
$settings_for_ui = $settings;

require $ui;

