<?php
declare(strict_types=1);

function buildAcl(array $me, array &$aclParams): string {
    if (!empty($me['is_admin'])) return '1=1';
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

    // Preset filtering (simple leg-level logic)
    $preset = strtolower(trim((string)($filters['preset'] ?? '')));
    $gateway = trim((string)($filters['gateway'] ?? ''));

    if ($preset !== '' && $preset !== 'all' && $gateway !== '') {
        // Build gateway pattern (normalize gateway string)
        $gwPattern = str_replace("\0", '', $gateway);
        $gwPattern = rtrim($gwPattern, '%');
        $gwPattern = rtrim($gwPattern, '-');

        if ($gwPattern !== '') {
            $gwPattern = $gwPattern . '-%';

            if ($preset === 'inbound') {
                // Inbound: calls FROM gateway (source)
                $where[] = "channel LIKE :gw_preset";
                $params[':gw_preset'] = $gwPattern;
            } elseif ($preset === 'outbound') {
                // Outbound: calls TO gateway (destination)
                $where[] = "dstchannel LIKE :gw_preset";
                $params[':gw_preset'] = $gwPattern;
            } elseif ($preset === 'missed') {
                // Missed: ALL missed calls involving gateway (not bridged, excluding queue abandoned)
                $where[] = "(channel LIKE :gw_preset_ch OR dstchannel LIKE :gw_preset_dch)";
                $where[] = "(dstchannel IS NULL OR dstchannel = '' OR dst = 's')";
                $where[] = "dcontext NOT LIKE '%ext-queues%'";
                $params[':gw_preset_ch'] = $gwPattern;
                $params[':gw_preset_dch'] = $gwPattern;
            } elseif ($preset === 'missed_in') {
                // Missed inbound: calls FROM gateway (not bridged, excluding queue abandoned)
                $where[] = "channel LIKE :gw_preset";
                $where[] = "(dstchannel IS NULL OR dstchannel = '' OR dst = 's')";
                $where[] = "dcontext NOT LIKE '%ext-queues%'";
                $params[':gw_preset'] = $gwPattern;
            } elseif ($preset === 'missed_out') {
                // Missed outbound: calls FROM internal TO gateway (not bridged, excluding queue abandoned)
                $where[] = "channel NOT LIKE :gw_preset_ch";
                $where[] = "(dstchannel IS NULL OR dstchannel = '' OR dst = 's')";
                $where[] = "dcontext NOT LIKE '%ext-queues%'";
                $params[':gw_preset_ch'] = $gwPattern;
            } elseif ($preset === 'internal') {
                // Internal: exclude gateway calls (both source and destination)
                $where[] = "channel NOT LIKE :gw_preset_ch";
                $where[] = "dstchannel NOT LIKE :gw_preset_dch";
                $params[':gw_preset_ch'] = $gwPattern;
                $params[':gw_preset_dch'] = $gwPattern;
            }
        }
    }

    // Abandoned preset (doesn't require gateway)
    if ($preset === 'abandoned') {
        $where[] = "(dstchannel IS NULL OR dstchannel = '' OR dst = 's')";
        $where[] = "dcontext LIKE '%ext-queues%'";
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
      SUM(CASE WHEN (dstchannel IS NOT NULL AND dstchannel != '' AND dst != 's') THEN 1 ELSE 0 END) AS answered,
      SUM(CASE WHEN disposition='BUSY' THEN 1 ELSE 0 END) AS busy,
      SUM(CASE WHEN (dstchannel IS NULL OR dstchannel = '' OR dst = 's') AND (dcontext NOT LIKE '%ext-queues%' OR dcontext IS NULL) THEN 1 ELSE 0 END) AS missed,
      SUM(CASE WHEN (dstchannel IS NULL OR dstchannel = '' OR dst = 's') AND dcontext LIKE '%ext-queues%' THEN 1 ELSE 0 END) AS abandoned,
      SUM(CASE WHEN disposition IN ('NO ANSWER','NOANSWER') THEN 1 ELSE 0 END) AS noanswer,
      SUM(CASE WHEN disposition='FAILED' THEN 1 ELSE 0 END) AS failed,
      SUM(CASE WHEN disposition IN ('CONGESTION','CONGESTED') THEN 1 ELSE 0 END) AS congested,
      SUM(billsec) AS total_billsec
    FROM `{$cdrTable}`
    WHERE {$whereSql}
    ";

    $st = $pdo->prepare($sumSql);
    foreach ($params as $k => $v) {
        if (is_int($v)) {
            $st->bindValue($k, $v, PDO::PARAM_INT);
        } else {
            $st->bindValue($k, (string)$v, PDO::PARAM_STR);
        }
    }
    $st->execute();
    return $st->fetch() ?: ['total'=>0,'answered'=>0,'busy'=>0,'missed'=>0,'abandoned'=>0,'noanswer'=>0,'failed'=>0,'congested'=>0,'total_billsec'=>0];
}

function fetchMaxConcurrentCalls(array $CONFIG, PDO $pdo, array $me, array $filters): int {
    $params = [];
    $whereSql = buildWhere($CONFIG, $me, $filters, $params);
    $cdrTable = $CONFIG['cdrTable'];
    $gateway = trim((string)($filters['gateway'] ?? ''));

    // If no gateway specified, use first from config
    if ($gateway === '') {
        $gateways = $CONFIG['gateways'] ?? [];
        if (!empty($gateways)) {
            $gateway = $gateways[0];
        }
    }

    // Build gateway pattern
    if ($gateway !== '') {
        $gwPattern = str_replace("\0", '', $gateway);
        $gwPattern = rtrim($gwPattern, '%');
        $gwPattern = rtrim($gwPattern, '-');
        $gwPattern = $gwPattern . '-%';
    } else {
        // No gateway, return 0
        return 0;
    }

    // Fetch all trunk calls with start/end times
    $sql = "
    SELECT
        calldate,
        DATE_ADD(calldate, INTERVAL duration SECOND) as endtime
    FROM `{$cdrTable}`
    WHERE {$whereSql}
      AND (channel LIKE :max_conc_gw_ch OR dstchannel LIKE :max_conc_gw_dch)
    ORDER BY calldate
    ";

    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        if (is_int($v)) {
            $st->bindValue($k, $v, PDO::PARAM_INT);
        } else {
            $st->bindValue($k, (string)$v, PDO::PARAM_STR);
        }
    }
    $st->bindValue(':max_conc_gw_ch', $gwPattern, PDO::PARAM_STR);
    $st->bindValue(':max_conc_gw_dch', $gwPattern, PDO::PARAM_STR);
    $st->execute();

    $calls = $st->fetchAll();
    if (empty($calls)) return 0;

    // Create events array (start and end events)
    $events = [];
    foreach ($calls as $call) {
        $events[] = ['time' => strtotime($call['calldate']), 'type' => 'start'];
        $events[] = ['time' => strtotime($call['endtime']), 'type' => 'end'];
    }

    // Sort events by time
    usort($events, function($a, $b) {
        if ($a['time'] === $b['time']) {
            // If same time, process 'end' before 'start'
            return $a['type'] === 'end' ? -1 : 1;
        }
        return $a['time'] - $b['time'];
    });

    // Calculate max concurrent calls
    $currentConcurrent = 0;
    $maxConcurrent = 0;

    foreach ($events as $event) {
        if ($event['type'] === 'start') {
            $currentConcurrent++;
            $maxConcurrent = max($maxConcurrent, $currentConcurrent);
        } else {
            $currentConcurrent--;
        }
    }

    return $maxConcurrent;
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
    SELECT t1.calldate, t1.clid, t1.src, t1.dst, t1.channel, t1.dstchannel, t1.dcontext, t1.disposition,
           t1.duration, t1.billsec, t1.uniqueid, t1.recordingfile,
           COALESCE(t1.linkedid, t1.uniqueid) as linkedid,
           (SELECT COUNT(*) FROM `{$cdrTable}` t2
            WHERE COALESCE(t2.linkedid, t2.uniqueid) = COALESCE(t1.linkedid, t1.uniqueid)) as leg_count,
           t1.disposition as last_leg_status,
           (SELECT t4.dst FROM `{$cdrTable}` t4
            WHERE COALESCE(t4.linkedid, t4.uniqueid) = COALESCE(t1.linkedid, t1.uniqueid)
              AND (t4.dstchannel IS NOT NULL AND t4.dstchannel != '')
            ORDER BY t4.calldate DESC, t4.uniqueid DESC
            LIMIT 1) as last_bridged_dst
    FROM `{$cdrTable}` t1
    WHERE {$whereSql}
      AND t1.uniqueid = (
        SELECT t3.uniqueid FROM `{$cdrTable}` t3
        WHERE COALESCE(t3.linkedid, t3.uniqueid) = COALESCE(t1.linkedid, t1.uniqueid)
        ORDER BY t3.calldate DESC, t3.uniqueid DESC
        LIMIT 1
      )
    ORDER BY {$sort} {$dir}
    LIMIT :lim OFFSET :off
    ";

    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        if (is_int($v)) {
            $st->bindValue($k, $v, PDO::PARAM_INT);
        } else {
            $st->bindValue($k, (string)$v, PDO::PARAM_STR);
        }
    }
    $st->bindValue(':lim', $per, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll() ?: [];
}

function fetchCallLegs(array $CONFIG, PDO $pdo, string $linkedid): array {
    if ($linkedid === '') return [];

    $cdrTable = $CONFIG['cdrTable'];

    $sql = "
    SELECT calldate, clid, src, dst, channel, dstchannel, dcontext, disposition, duration, billsec, uniqueid, recordingfile,
           COALESCE(linkedid, uniqueid) as linkedid
    FROM `{$cdrTable}`
    WHERE COALESCE(linkedid, uniqueid) = :linkedid
    ORDER BY calldate ASC, uniqueid ASC
    ";

    try {
        $st = $pdo->prepare($sql);
        $st->execute([':linkedid' => $linkedid]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
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
    foreach ($params as $k => $v) {
        if (is_int($v)) {
            $st->bindValue($k, $v, PDO::PARAM_INT);
        } else {
            $st->bindValue($k, (string)$v, PDO::PARAM_STR);
        }
    }
    $st->execute();
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

function fetchGateways(array $CONFIG, PDO $pdo): array {
    $cdrTable = $CONFIG['cdrTable'];

    // Get unique channel prefixes (gateway names)
    $sql = "
    SELECT DISTINCT
        SUBSTRING_INDEX(SUBSTRING_INDEX(channel, '/', 2), '/', -1) AS gateway
    FROM `{$cdrTable}`
    WHERE channel LIKE 'SIP/%' OR channel LIKE 'PJSIP/%' OR channel LIKE 'DAHDI/%' OR channel LIKE 'IAX2/%'
    UNION
    SELECT DISTINCT
        SUBSTRING_INDEX(SUBSTRING_INDEX(dstchannel, '/', 2), '/', -1) AS gateway
    FROM `{$cdrTable}`
    WHERE dstchannel LIKE 'SIP/%' OR dstchannel LIKE 'PJSIP/%' OR dstchannel LIKE 'DAHDI/%' OR dstchannel LIKE 'IAX2/%'
    ORDER BY gateway
    LIMIT 100
    ";

    try {
        $st = $pdo->query($sql);
        $gateways = [];
        while ($row = $st->fetch()) {
            $gw = trim((string)($row['gateway'] ?? ''));
            if ($gw !== '' && !in_array($gw, $gateways, true)) {
                $gateways[] = $gw;
            }
        }
        return $gateways;
    } catch (Throwable $e) {
        return [];
    }
}

function fetchExtensionKPIs(array $CONFIG, PDO $pdo, array $me, array $filters): array {
    $params = [];
    $whereSql = buildWhere($CONFIG, $me, $filters, $params);
    $cdrTable = $CONFIG['cdrTable'];

    // Get KPIs per extension (from src field)
    $sql = "
    SELECT
        src as extension,
        COUNT(*) as total_calls,
        SUM(CASE WHEN (dstchannel IS NOT NULL AND dstchannel != '' AND dst != 's') THEN 1 ELSE 0 END) as answered,
        SUM(CASE WHEN (dstchannel IS NULL OR dstchannel = '' OR dst = 's') AND (dcontext NOT LIKE '%ext-queues%' OR dcontext IS NULL) THEN 1 ELSE 0 END) as missed,
        SUM(CASE WHEN (dstchannel IS NULL OR dstchannel = '' OR dst = 's') AND dcontext LIKE '%ext-queues%' THEN 1 ELSE 0 END) as abandoned,
        SUM(CASE WHEN disposition='BUSY' THEN 1 ELSE 0 END) as busy,
        SUM(CASE WHEN disposition='FAILED' THEN 1 ELSE 0 END) as failed,
        SUM(billsec) as total_billsec,
        SUM(duration) as total_duration,
        AVG(CASE WHEN (dstchannel IS NOT NULL AND dstchannel != '' AND dst != 's') THEN billsec ELSE NULL END) as avg_talk_time,
        AVG(CASE WHEN (dstchannel IS NOT NULL AND dstchannel != '' AND dst != 's') THEN (duration - billsec) ELSE NULL END) as avg_wait_time,
        MAX(billsec) as max_call_duration,
        MIN(CASE WHEN (dstchannel IS NOT NULL AND dstchannel != '' AND dst != 's') AND billsec > 0 THEN billsec ELSE NULL END) as min_call_duration
    FROM `{$cdrTable}`
    WHERE {$whereSql}
      AND src REGEXP '^[0-9]+$'
    GROUP BY src
    ORDER BY total_calls DESC
    ";

    try {
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            if (is_int($v)) {
                $st->bindValue($k, $v, PDO::PARAM_INT);
            } else {
                $st->bindValue($k, (string)$v, PDO::PARAM_STR);
            }
        }
        $st->execute();
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

