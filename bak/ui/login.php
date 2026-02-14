<?php
declare(strict_types=1);

namespace Supervisor\Lib;

use PDO;
use Throwable;

use function fail;
use function csvRow;
use function aclWhereByChannel;
use function rowAllowedByChannel;
use function findRecordingFile;
use function streamFile;

function buildAcl(array $me, array &$aclParams): string {
    $isAdmin = !empty($me['is_admin']);
    if ($isAdmin) return '1=1';
    $allowedExts = (array)($me['extensions'] ?? []);
    if (count($allowedExts) === 0) return '1=0';
    return aclWhereByChannel($allowedExts, $aclParams);
}

function buildWhere(array $CONFIG, array $me, array $filters, array &$params): string {
    $where = [];
    $params = [];

    $fromDt = $filters['from'] . ' 00:00:00';
    $toDt   = $filters['to']   . ' 23:59:59';

    $where[] = "calldate >= :fromDt";
    $where[] = "calldate <= :toDt";
    $params[':fromDt'] = $fromDt;
    $params[':toDt']   = $toDt;

    $aclParams = [];
    $where[] = buildAcl($me, $aclParams);
    $params = array_merge($params, $aclParams);

    $src = (string)($filters['src'] ?? '');
    if ($src !== '') {
        $where[] = "(
            src = :src_num
            OR channel LIKE :src_ch_sip
            OR channel LIKE :src_ch_psip
        )";
        $params[':src_num']     = $src;
        $params[':src_ch_sip']  = "SIP/{$src}-%";
        $params[':src_ch_psip'] = "PJSIP/{$src}-%";
    }

    $dst = (string)($filters['dst'] ?? '');
    if ($dst !== '') {
        $where[] = "(
            dst = :dst_num
            OR dstchannel LIKE :dst_ch_sip
            OR dstchannel LIKE :dst_ch_psip
        )";
        $params[':dst_num']     = $dst;
        $params[':dst_ch_sip']  = "SIP/{$dst}-%";
        $params[':dst_ch_psip'] = "PJSIP/{$dst}-%";
    }

    $disp = (string)($filters['disposition'] ?? '');
    if ($disp !== '') {
        if ($disp === 'NO ANSWER') {
            $where[] = "(disposition='NO ANSWER' OR disposition='NOANSWER')";
        } elseif ($disp === 'CONGESTION' || $disp === 'CONGESTED') {
            $where[] = "(disposition='CONGESTION' OR disposition='CONGESTED')";
        } else {
            $where[] = "disposition = :disp";
            $params[':disp'] = $disp;
        }
    }

    $mindur = (string)($filters['mindur'] ?? '');
    if ($mindur !== '') {
        $where[] = "billsec >= :mindur";
        $params[':mindur'] = (int)$mindur;
    }

    $q = (string)($filters['q'] ?? '');
    if ($q !== '') {
        $where[] = "(src LIKE :q1 OR dst LIKE :q2 OR clid LIKE :q3 OR uniqueid LIKE :q4 OR channel LIKE :q5 OR dstchannel LIKE :q6)";
        $like = '%' . $q . '%';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
        $params[':q4'] = $like;
        $params[':q5'] = $like;
        $params[':q6'] = $like;
    }

    return implode(' AND ', $where);
}

function fetchSummary(array $CONFIG, PDO $pdo, array $me, array $filters): array {
    $params = [];
    $whereSql = buildWhere($CONFIG, $me, $filters, $params);

    $cdrTable = $CONFIG['cdrTable'];

    $sumSql = "
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN disposition='ANSWERED' THEN 1 ELSE 0 END) AS answered,
      SUM(CASE WHEN disposition='BUSY' THEN 1 ELSE 0 END) AS busy,
      SUM(CASE WHEN disposition IN ('NO ANSWER','NOANSWER') THEN 1 ELSE 0 END) AS noanswer,
      SUM(CASE WHEN disposition='FAILED' THEN 1 ELSE 0 END) AS failed,
      SUM(CASE WHEN disposition IN ('CONGESTION','CONGESTED') THEN 1 ELSE 0 END) AS congested,
      SUM(billsec) AS total_billsec
    FROM `{$cdrTable}`
    WHERE {$whereSql}
    ";

    $st = $pdo->prepare($sumSql);
    $st->execute($params);
    return $st->fetch() ?: ['total'=>0,'answered'=>0,'busy'=>0,'noanswer'=>0,'failed'=>0,'congested'=>0,'total_billsec'=>0];
}

function fetchPageRows(array $CONFIG, PDO $pdo, array $me, array $filters): array {
    $params = [];
    $whereSql = buildWhere($CONFIG, $me, $filters, $params);

    $cdrTable = $CONFIG['cdrTable'];

    $sort = (string)($filters['sort'] ?? 'calldate');
    $dir  = (string)($filters['dir'] ?? 'desc');

    $pageNo = (int)($filters['page'] ?? 1);
    $per    = (int)($filters['per'] ?? 50);
    $offset = ($pageNo - 1) * $per;

    $sql = "
    SELECT calldate, clid, src, dst, channel, dstchannel, dcontext, disposition, duration, billsec, uniqueid, recordingfile
    FROM `{$cdrTable}`
    WHERE {$whereSql}
    ORDER BY {$sort} {$dir}
    LIMIT :lim OFFSET :off
    ";

    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':lim', $per, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();

    return $st->fetchAll() ?: [];
}

function streamCsv(array $CONFIG, PDO $pdo, array $me, array $filters): void {
    $params = [];
    $whereSql = buildWhere($CONFIG, $me, $filters, $params);

    $cdrTable = $CONFIG['cdrTable'];

    $sort = (string)($filters['sort'] ?? 'calldate');
    $dir  = (string)($filters['dir'] ?? 'desc');

    $from = (string)$filters['from'];
    $to   = (string)$filters['to'];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cdr_'.$from.'_to_'.$to.'.csv"');

    echo csvRow(['calldate','clid','src','dst','channel','dstchannel','dcontext','disposition','duration','billsec','uniqueid','recordingfile']);

    $csvSql = "
    SELECT calldate, clid, src, dst, channel, dstchannel, dcontext, disposition, duration, billsec, uniqueid, recordingfile
    FROM `{$cdrTable}`
    WHERE {$whereSql}
    ORDER BY {$sort} {$dir}
    ";

    $st = $pdo->prepare($csvSql);
    $st->execute($params);
    while ($r = $st->fetch()) {
        echo csvRow([
            $r['calldate'] ?? '',
            $r['clid'] ?? '',
            $r['src'] ?? '',
            $r['dst'] ?? '',
            $r['channel'] ?? '',
            $r['dstchannel'] ?? '',
            $r['dcontext'] ?? '',
            $r['disposition'] ?? '',
            $r['duration'] ?? '',
            $r['billsec'] ?? '',
            $r['uniqueid'] ?? '',
            $r['recordingfile'] ?? '',
        ]);
    }
    exit;
}

function handleRecordingRequest(array $CONFIG, PDO $pdo, array $me, string $action): void {
    $uid = (string)getParam('uid', '');
    if ($uid === '') fail("Missing uid", 400);

    $cdrTable = $CONFIG['cdrTable'];

    $st = $pdo->prepare("SELECT calldate, channel, dstchannel, recordingfile FROM `{$cdrTable}` WHERE uniqueid = :uid LIMIT 1");
    $st->execute([':uid' => $uid]);
    $row = $st->fetch();
    if (!$row) { http_response_code(404); echo "Not found\n"; exit; }

    if (empty($me['is_admin'])) {
        $allowedExts = (array)($me['extensions'] ?? []);
        $ch  = (string)($row['channel'] ?? '');
        $dch = (string)($row['dstchannel'] ?? '');
        if (!rowAllowedByChannel($allowedExts, $ch, $dch)) {
            http_response_code(403);
            echo "Forbidden\n";
            exit;
        }
    }

    $abs = findRecordingFile($CONFIG['recBaseDir'], $row['recordingfile'] ?? null, $row['calldate'] ?? null);
    if (!$abs) { http_response_code(404); echo "Recording not found\n"; exit; }

    streamFile($abs, $action === 'download' ? 'attachment' : 'inline');
}

