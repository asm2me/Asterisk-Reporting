<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php'; // provides: $pdo, $me, $isAdmin, $allowedExts, $acl, $usersData, $settings

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

// AJAX transitions endpoint
if ($action === 'transitions') {
    header('Content-Type: application/json; charset=utf-8');
    $grpkey = trim((string)($_GET['grpkey'] ?? ''));
    if ($grpkey === '') { echo json_encode(['ok'=>false,'error'=>'Missing grpkey']); exit; }

    // keep date range the same as current filter so ACL/date applies
    $from = (string)($_GET['from'] ?? date('Y-m-d'));
    $to   = (string)($_GET['to'] ?? date('Y-m-d'));
    $filtersTmp = [
        'fromDt' => $from . ' 00:00:00',
        'toDt'   => $to . ' 23:59:59',
        'from'   => $from,
        'to'     => $to,
        'q'      => (string)($_GET['q'] ?? ''),
        'src'    => (string)($_GET['src'] ?? ''),
        'dst'    => (string)($_GET['dst'] ?? ''),
        'disposition' => (string)($_GET['disposition'] ?? ''),
        'mindur' => (string)($_GET['mindur'] ?? ''),
        'preset' => (string)($_GET['preset'] ?? ''),
        'gateway'=> (string)($_GET['gateway'] ?? ''),
        'group'  => (string)($_GET['group'] ?? 'call'),
        'page'   => 1,
        'per'    => 200,
        'sort'   => 'start_calldate',
        'dir'    => 'asc',
        'format' => 'html',
    ];

    try {
        $legs = Supervisor\Lib\fetchTransitions($pdo, $grpkey, $filtersTmp, $acl, $settings['cdr_table']);
        echo json_encode(['ok'=>true,'legs'=>$legs], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// --- Inputs ---
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

// --- CSV export ---
if (strtolower($format) === 'csv') {
    streamCsv($pdo, $filters, $acl, $allowedExts, $settings['cdr_table']);
}

// --- summary + data ---
$summary = Supervisor\Lib\fetchSummary($pdo, $filters, $acl, $allowedExts, $settings['cdr_table']);
$totalGroups = Supervisor\Lib\countGroups($pdo, $filters, $acl, $allowedExts, $settings['cdr_table']);

$pages = max(1, (int)ceil($totalGroups / max(1, (int)$per)));
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

