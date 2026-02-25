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

    // Extension filter: match calls where this extension is src OR dst OR either channel
    $ext = trim((string)($filters['ext'] ?? ''));
    if ($ext !== '' && preg_match('/^[0-9]+$/', $ext)) {
        $where[] = "(
            src = :ext_src
            OR dst = :ext_dst
            OR channel LIKE :ext_ch_sip
            OR channel LIKE :ext_ch_psip
            OR dstchannel LIKE :ext_dch_sip
            OR dstchannel LIKE :ext_dch_psip
        )";
        $params[':ext_src']      = $ext;
        $params[':ext_dst']      = $ext;
        $params[':ext_ch_sip']   = "SIP/{$ext}-%";
        $params[':ext_ch_psip']  = "PJSIP/{$ext}-%";
        $params[':ext_dch_sip']  = "SIP/{$ext}-%";
        $params[':ext_dch_psip'] = "PJSIP/{$ext}-%";
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

/**
 * Returns all distinct numeric extensions (src values) seen in the CDR
 * within the given date range, respecting ACL and excluding gateway channels.
 */
function fetchAvailableExtensions(array $CONFIG, PDO $pdo, array $me, string $from, string $to): array {
    $cdrTable = $CONFIG['cdrTable'];
    $params   = [':fromDt' => $from . ' 00:00:00', ':toDt' => $to . ' 23:59:59'];

    $aclParams = [];
    $acl = buildAcl($me, $aclParams);
    $params = array_merge($params, $aclParams);

    $gwExcludeConds = [];
    foreach (($CONFIG['gateways'] ?? []) as $idx => $gw) {
        $gwPat = rtrim(str_replace("\0", '', (string)$gw), '-%') . '-%';
        $key = ':extlist_gw_' . (int)$idx;
        $gwExcludeConds[] = "channel NOT LIKE {$key}";
        $params[$key] = $gwPat;
    }
    $gwExcludeSql = $gwExcludeConds ? ('AND ' . implode(' AND ', $gwExcludeConds)) : '';

    $sql = "
    SELECT DISTINCT src
    FROM `{$cdrTable}`
    WHERE calldate >= :fromDt AND calldate <= :toDt
      AND {$acl}
      AND src REGEXP '^[0-9]{3,6}$'
      {$gwExcludeSql}
    ORDER BY src
    LIMIT 500
    ";

    try {
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, (string)$v, PDO::PARAM_STR);
        }
        $st->execute();
        return array_column($st->fetchAll() ?: [], 'src');
    } catch (Throwable $e) {
        return [];
    }
}

function fetchSummary(array $CONFIG, PDO $pdo, array $me, array $filters): array {
    $params = [];
    $whereSql = buildWhere($CONFIG, $me, $filters, $params);
    $cdrTable = $CONFIG['cdrTable'];

    // Group by linkedid first so each multi-leg call counts as ONE call.
    // A queue call with 3 CDR legs would otherwise inflate answered/total.
    $sumSql = "
    SELECT
      COUNT(*)                                                             AS total,
      SUM(any_bridged)                                                     AS answered,
      SUM(is_busy)                                                         AS busy,
      SUM(CASE WHEN any_bridged = 0 AND any_queue = 0 THEN 1 ELSE 0 END)  AS missed,
      SUM(CASE WHEN any_bridged = 0 AND any_queue = 1 THEN 1 ELSE 0 END)  AS abandoned,
      SUM(is_noanswer)                                                     AS noanswer,
      SUM(is_failed)                                                       AS failed,
      SUM(is_congested)                                                    AS congested,
      SUM(grp_billsec)                                                     AS total_billsec
    FROM (
      SELECT
        COALESCE(linkedid, uniqueid) AS grp_id,
        MAX(CASE WHEN disposition = 'ANSWERED' AND billsec > 0
                      AND (channel REGEXP '^(PJSIP|SIP)/[0-9]+'
                           OR dstchannel REGEXP '^(PJSIP|SIP)/[0-9]+')
                 THEN 1 ELSE 0 END)                                      AS any_bridged,
        MAX(CASE WHEN dcontext LIKE '%ext-queues%'
                 THEN 1 ELSE 0 END)                                      AS any_queue,
        MAX(CASE WHEN disposition = 'BUSY'
                 THEN 1 ELSE 0 END)                                      AS is_busy,
        MAX(CASE WHEN disposition IN ('NO ANSWER','NOANSWER')
                 THEN 1 ELSE 0 END)                                      AS is_noanswer,
        MAX(CASE WHEN disposition = 'FAILED'
                 THEN 1 ELSE 0 END)                                      AS is_failed,
        MAX(CASE WHEN disposition IN ('CONGESTION','CONGESTED')
                 THEN 1 ELSE 0 END)                                      AS is_congested,
        SUM(billsec)                                                       AS grp_billsec
      FROM `{$cdrTable}`
      WHERE {$whereSql}
      GROUP BY COALESCE(linkedid, uniqueid)
    ) AS grp
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

    // Single-pass derived table replaces 3 correlated subqueries.
    // Groups by linkedid, computes leg_count and last_bridged_dst in one scan,
    // then joins back to get the full representative row.
    // Recommended indexes: calldate, linkedid, uniqueid
    $sql = "
    SELECT t1.calldate, t1.clid, t1.src, t1.dst, t1.channel, t1.dstchannel, t1.dcontext, t1.disposition,
           t1.duration, t1.billsec, t1.uniqueid, t1.recordingfile,
           grp.grp_id AS linkedid,
           grp.leg_count,
           grp.any_bridged,
           grp.any_queue,
           t1.disposition AS last_leg_status,
           grp.last_bridged_dst
    FROM (
        SELECT
            COALESCE(linkedid, uniqueid) AS grp_id,
            COUNT(*) AS leg_count,
            MAX(CASE WHEN disposition = 'ANSWERED' AND billsec > 0
                          AND (channel REGEXP '^(PJSIP|SIP)/[0-9]+'
                               OR dstchannel REGEXP '^(PJSIP|SIP)/[0-9]+')
                     THEN 1 ELSE 0 END) AS any_bridged,
            MAX(CASE WHEN dcontext LIKE '%ext-queues%'
                     THEN 1 ELSE 0 END) AS any_queue,
            SUBSTRING_INDEX(MAX(CONCAT(
                DATE_FORMAT(calldate, '%Y-%m-%d %H:%i:%s'), '|', uniqueid
            )), '|', -1) AS main_uniqueid,
            SUBSTRING_INDEX(IFNULL(MAX(CASE WHEN dstchannel IS NOT NULL AND dstchannel != ''
                THEN CONCAT(DATE_FORMAT(calldate, '%Y-%m-%d %H:%i:%s'), '|', uniqueid, '|', dst)
                ELSE NULL END), ''), '|', -1) AS last_bridged_dst
        FROM `{$cdrTable}`
        WHERE {$whereSql}
        GROUP BY COALESCE(linkedid, uniqueid)
    ) grp
    INNER JOIN `{$cdrTable}` t1 ON t1.uniqueid = grp.main_uniqueid
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

/**
 * Batch-fetch call legs for all linkedids on a page in a single query.
 * Returns an array keyed by linkedid, each value an array of leg rows.
 * Replaces N individual fetchCallLegs() calls with one round-trip.
 */
function fetchCallLegsForRows(array $CONFIG, PDO $pdo, array $linkedIds): array {
    $linkedIds = array_values(array_filter(array_unique($linkedIds), fn($v) => $v !== ''));
    if (empty($linkedIds)) return [];

    $cdrTable = $CONFIG['cdrTable'];
    $placeholders = implode(',', array_fill(0, count($linkedIds), '?'));

    $sql = "
    SELECT calldate, clid, src, dst, channel, dstchannel, dcontext, disposition, duration, billsec, uniqueid, recordingfile,
           COALESCE(linkedid, uniqueid) AS linkedid
    FROM `{$cdrTable}`
    WHERE COALESCE(linkedid, uniqueid) IN ({$placeholders})
    ORDER BY COALESCE(linkedid, uniqueid), calldate ASC, uniqueid ASC
    ";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($linkedIds);
        $grouped = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $grouped[$row['linkedid']][] = $row;
        }
        return $grouped;
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

    // Exclude inbound calls from gateway trunks: those rows have the external
    // caller's number as src (e.g. 0501234567), which is not an extension.
    // We exclude them by checking that channel does NOT match any configured
    // gateway prefix. Outbound and internal calls are unaffected regardless of
    // whether they use PJSIP, SIP, Local, or other channel types.
    $gwExcludeConds = [];
    foreach (($CONFIG['gateways'] ?? []) as $idx => $gw) {
        $gwPat = rtrim(str_replace("\0", '', (string)$gw), '-%') . '-%';
        $key = ':kpi_gw_' . (int)$idx;
        $gwExcludeConds[] = "channel NOT LIKE {$key}";
        $params[$key] = $gwPat;
    }
    $gwExcludeSql = $gwExcludeConds ? ('AND ' . implode(' AND ', $gwExcludeConds)) : '';

    // Two-level grouping: first collapse multi-leg calls into one row per
    // (extension, linkedid), then aggregate per extension.
    // This prevents a single multi-leg call inflating answered/total counts.
    $sql = "
    SELECT
        extension,
        COUNT(*)                                                              AS total_calls,
        SUM(any_bridged)                                                      AS answered,
        SUM(CASE WHEN any_bridged = 0 AND any_queue = 0 THEN 1 ELSE 0 END)   AS missed,
        SUM(CASE WHEN any_bridged = 0 AND any_queue = 1 THEN 1 ELSE 0 END)   AS abandoned,
        SUM(is_busy)                                                          AS busy,
        SUM(is_failed)                                                        AS failed,
        SUM(grp_billsec)                                                      AS total_billsec,
        SUM(grp_duration)                                                     AS total_duration,
        AVG(CASE WHEN any_bridged = 1 THEN grp_billsec  ELSE NULL END)       AS avg_talk_time,
        AVG(CASE WHEN any_bridged = 1 THEN grp_wait     ELSE NULL END)       AS avg_wait_time,
        MAX(grp_billsec)                                                      AS max_call_duration,
        MIN(CASE WHEN any_bridged = 1 AND grp_billsec > 0
                 THEN grp_billsec ELSE NULL END)                              AS min_call_duration
    FROM (
        SELECT
            src                                                               AS extension,
            COALESCE(linkedid, uniqueid)                                      AS grp_id,
            MAX(CASE WHEN disposition = 'ANSWERED' AND billsec > 0
                     THEN 1 ELSE 0 END)                                      AS any_bridged,
            MAX(CASE WHEN dcontext LIKE '%ext-queues%'
                     THEN 1 ELSE 0 END)                                      AS any_queue,
            MAX(CASE WHEN disposition = 'BUSY'  THEN 1 ELSE 0 END)           AS is_busy,
            MAX(CASE WHEN disposition = 'FAILED' THEN 1 ELSE 0 END)          AS is_failed,
            SUM(billsec)                                                      AS grp_billsec,
            SUM(duration)                                                     AS grp_duration,
            SUM(CASE WHEN disposition = 'ANSWERED' AND billsec > 0
                     THEN (duration - billsec) ELSE 0 END)                   AS grp_wait
        FROM `{$cdrTable}`
        WHERE {$whereSql}
          AND src REGEXP '^[0-9]+$'
          {$gwExcludeSql}
        GROUP BY src, COALESCE(linkedid, uniqueid)
    ) AS ext_grp
    GROUP BY extension
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

// ---------------------------------------------------------------------------
// Excel (XLSX) export helpers
// ---------------------------------------------------------------------------

/**
 * Convert a 0-based column index to an Excel column letter (A, B, … Z, AA …).
 */
function xlsxCol(int $idx): string {
    $s = '';
    for ($n = $idx + 1; $n > 0; $n = intdiv($n, 26)) {
        $s = chr(65 + (--$n % 26)) . $s;
    }
    return $s;
}

/**
 * Build a ZIP archive in memory without the ZipArchive extension.
 * Requires only gzdeflate() from the zlib extension (always available).
 * $files is an associative array: ['path/in/zip' => 'file contents', ...]
 */
function xlsxBuildZip(array $files): string {
    $central = '';
    $output  = '';
    $offset  = 0;

    foreach ($files as $name => $data) {
        $crc   = crc32($data);
        $uSize = strlen($data);
        $comp  = gzdeflate($data, 6);
        if ($comp === false || strlen($comp) >= $uSize) {
            $comp   = $data;
            $method = 0; // STORE
        } else {
            $method = 8; // DEFLATE
        }
        $cSize   = strlen($comp);
        $nameLen = strlen($name);

        $local = pack('VvvvvvVVVvv',
            0x04034b50, 20, 0, $method, 0, 0, $crc, $cSize, $uSize, $nameLen, 0
        ) . $name . $comp;

        $output  .= $local;
        $central .= pack('VvvvvvvVVVvvvvvVV',
            0x02014b50, 0x0314, 20, 0, $method, 0, 0,
            $crc, $cSize, $uSize, $nameLen, 0, 0, 0, 0, 0, $offset
        ) . $name;

        $offset += strlen($local);
    }

    $cdSize   = strlen($central);
    $numFiles = count($files);
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $numFiles, $numFiles, $cdSize, $offset, 0);

    return $output . $central . $end;
}

/**
 * Build and stream a minimal XLSX file (no ZipArchive extension required).
 * $rows is an array of arrays; int/float values → numeric cells, strings → inline-string cells.
 * Header row is rendered bold.
 */
function streamXlsx(array $headers, array $rows, string $filename): void {
    $ws  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $ws .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $ws .= '<row r="1">';
    foreach ($headers as $ci => $h) {
        $col = xlsxCol($ci);
        $esc = htmlspecialchars((string)$h, ENT_XML1, 'UTF-8');
        $ws .= "<c r=\"{$col}1\" t=\"inlineStr\" s=\"1\"><is><t>{$esc}</t></is></c>";
    }
    $ws .= '</row>';
    $rowNum = 2;
    foreach ($rows as $row) {
        $ws .= "<row r=\"{$rowNum}\">";
        foreach (array_values($row) as $ci => $val) {
            $col = xlsxCol($ci);
            if (is_int($val) || is_float($val)) {
                $ws .= "<c r=\"{$col}{$rowNum}\"><v>{$val}</v></c>";
            } else {
                $esc = htmlspecialchars((string)($val ?? ''), ENT_XML1, 'UTF-8');
                $ws .= "<c r=\"{$col}{$rowNum}\" t=\"inlineStr\"><is><t>{$esc}</t></is></c>";
            }
        }
        $ws .= '</row>';
        $rowNum++;
    }
    $ws .= '</sheetData></worksheet>';

    $zip = xlsxBuildZip([
        '[Content_Types].xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
            '<Default Extension="xml"  ContentType="application/xml"/>'.
            '<Override PartName="/xl/workbook.xml"          ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'.
            '<Override PartName="/xl/styles.xml"            ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.
            '</Types>',
        '_rels/.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'.
            '</Relationships>',
        'xl/workbook.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'.
            ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.
            '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'.
            '</workbook>',
        'xl/_rels/workbook.xml.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'.
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"   Target="styles.xml"/>'.
            '</Relationships>',
        'xl/styles.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.
            '<fonts count="2">'.
              '<font><sz val="11"/><name val="Calibri"/></font>'.
              '<font><b/><sz val="11"/><name val="Calibri"/></font>'.
            '</fonts>'.
            '<fills count="2">'.
              '<fill><patternFill patternType="none"/></fill>'.
              '<fill><patternFill patternType="gray125"/></fill>'.
            '</fills>'.
            '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'.
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'.
            '<cellXfs count="2">'.
              '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'.
              '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'.
            '</cellXfs>'.
            '</styleSheet>',
        'xl/worksheets/sheet1.xml' => $ws,
    ]);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Content-Length: ' . strlen($zip));
    echo $zip;
    exit;
}

/**
 * Export CDR rows as XLSX (same scope as CSV export, no pagination).
 */
function streamExcel(array $CONFIG, PDO $pdo, array $me, array $filters): void {
    $params = [];
    $whereSql = buildWhere($CONFIG, $me, $filters, $params);
    $cdrTable = $CONFIG['cdrTable'];
    $sort = (string)($filters['sort'] ?? 'calldate');
    $dir  = (string)($filters['dir']  ?? 'desc');

    $sql = "SELECT calldate, clid, src, dst, channel, dstchannel, dcontext, disposition, duration, billsec, uniqueid, recordingfile
            FROM `{$cdrTable}` WHERE {$whereSql} ORDER BY {$sort} {$dir}";

    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        if (is_int($v)) $st->bindValue($k, $v, PDO::PARAM_INT);
        else             $st->bindValue($k, (string)$v, PDO::PARAM_STR);
    }
    $st->execute();

    $headers = ['Call Date','CLID','Src','Dst','Channel','Dst Channel','Context','Disposition','Duration','Billsec','UniqueID','Recording'];
    $rows = [];
    while ($r = $st->fetch()) {
        $rows[] = [
            (string)($r['calldate']     ?? ''),
            (string)($r['clid']         ?? ''),
            (string)($r['src']          ?? ''),
            (string)($r['dst']          ?? ''),
            (string)($r['channel']      ?? ''),
            (string)($r['dstchannel']   ?? ''),
            (string)($r['dcontext']     ?? ''),
            (string)($r['disposition']  ?? ''),
            (int)   ($r['duration']     ?? 0),
            (int)   ($r['billsec']      ?? 0),
            (string)($r['uniqueid']     ?? ''),
            (string)($r['recordingfile']?? ''),
        ];
    }

    $from = (string)$filters['from'];
    $to   = (string)$filters['to'];
    streamXlsx($headers, $rows, "cdr_{$from}_to_{$to}.xlsx");
}

/**
 * Export KPI data array as XLSX.
 */
function streamExcelKpis(array $kpiData, string $from, string $to): void {
    $headers = ['Extension','Total Calls','Answered','Missed','Abandoned','Busy','Failed','Answer Rate %','Avg Wait (sec)','Avg Talk (sec)','Total Talk (sec)'];
    $rows = [];
    foreach ($kpiData as $ext) {
        $total  = (int)($ext['total_calls'] ?? 0);
        $ans    = (int)($ext['answered']    ?? 0);
        $rate   = $total > 0 ? round(($ans / $total) * 100, 1) : 0.0;
        $rows[] = [
            (string)($ext['extension']    ?? ''),
            $total,
            $ans,
            (int)($ext['missed']          ?? 0),
            (int)($ext['abandoned']       ?? 0),
            (int)($ext['busy']            ?? 0),
            (int)($ext['failed']          ?? 0),
            $rate,
            (int)($ext['avg_wait_time']   ?? 0),
            (int)($ext['avg_talk_time']   ?? 0),
            (int)($ext['total_billsec']   ?? 0),
        ];
    }
    streamXlsx($headers, $rows, "kpi_{$from}_to_{$to}.xlsx");
}

