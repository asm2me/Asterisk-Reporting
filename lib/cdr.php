<?php
declare(strict_types=1);

namespace Supervisor\Lib;

use PDO;

function grpKeySql(): string {
    return "COALESCE(NULLIF(linkedid,''), uniqueid)";
}

function normalizeGateway(string $gw): string {
    $gw = trim($gw);
    if ($gw === '') return '';
    $gw = str_replace("\0", '', $gw);
    $gw = rtrim($gw, '%');
    $gw = rtrim($gw, '-');
    return $gw;
}

function gatewayLike(string $gw): string {
    $gw = normalizeGateway($gw);
    if ($gw === '') return '';
    return $gw . "-%";
}

/**
 * Prefix placeholders in ACL where/params so we can embed the same ACL multiple times.
 */
function prefixWhereAndParams(string $where, array $params, string $prefix): array {
    $newWhere = $where;
    $newParams = [];

    foreach ($params as $k => $v) {
        $name = ltrim((string)$k, ':');
        $newKey = ':' . $prefix . '_' . $name;
        $newWhere = str_replace(':' . $name, $newKey, $newWhere);
        $newParams[$newKey] = $v;
    }

    return ['where' => $newWhere, 'params' => $newParams];
}

function buildGatewayClause(string $gateway, array &$params, string $paramBase): string {
    $like = gatewayLike($gateway);
    if ($like === '') return "(1=1)";
    $p1 = ":{$paramBase}_ch";
    $p2 = ":{$paramBase}_dch";
    $params[$p1] = $like;
    $params[$p2] = $like;
    return "(channel LIKE {$p1} OR dstchannel LIKE {$p2})";
}

/**
 * Allowed extension channel/dstchannel LIKE clauses.
 * Supports:
 *  - PJSIP/100-...
 *  - SIP/100-...
 *  - Local/100@...
 */
function buildAllowedExtLikeClauses(array $allowedExts, array &$params, string $prefix): string {
    $parts = [];
    $i = 0;

    foreach ($allowedExts as $e) {
        $e = (string)$e;
        if ($e === '' || !preg_match('/^[0-9]+$/', $e)) continue;

        $p1 = ":{$prefix}_pjsip_{$i}";
        $p2 = ":{$prefix}_sip_{$i}";
        $p3 = ":{$prefix}_local_{$i}";

        $params[$p1] = "PJSIP/{$e}-%";
        $params[$p2] = "SIP/{$e}-%";
        $params[$p3] = "Local/{$e}@%";

        $parts[] =
            "(channel LIKE {$p1} OR dstchannel LIKE {$p1} " .
            "OR channel LIKE {$p2} OR dstchannel LIKE {$p2} " .
            "OR channel LIKE {$p3} OR dstchannel LIKE {$p3})";
        $i++;
    }

    if (!$parts) return "(1=0)";
    return "(" . implode(" OR ", $parts) . ")";
}

/**
 * Collect placeholders in SQL and verify they exist in params.
 */
function requireBindAll(string $sql, array $params): void {
    if (preg_match_all('/:\w+/', $sql, $m)) {
        $need = array_unique($m[0]);
        foreach ($need as $n) {
            if (!array_key_exists($n, $params)) {
                throw new \RuntimeException("SQL parameter missing: {$n}");
            }
        }
    }
}

/**
 * Return ONLY the params that appear in this SQL.
 */
function paramsForSql(string $sql, array $params): array {
    if (!preg_match_all('/:\w+/', $sql, $m)) return [];
    $need = array_unique($m[0]);
    $out = [];
    foreach ($need as $n) {
        if (array_key_exists($n, $params)) $out[$n] = $params[$n];
    }
    return $out;
}

/**
 * Bind all parameters explicitly and execute.
 * This avoids PDO/MariaDB "HY093 invalid parameter number" that can occur with execute($params).
 */
function prepareBindExecute(PDO $pdo, string $sql, array $params): \PDOStatement {
    $st = $pdo->prepare($sql);
    $bind = paramsForSql($sql, $params);

    foreach ($bind as $k => $v) {
        // bind ints safely for LIMIT/OFFSET and numeric filters
        if ($k === ':lim' || $k === ':off' || is_int($v)) {
            $st->bindValue($k, (int)$v, PDO::PARAM_INT);
        } else {
            $st->bindValue($k, (string)$v, PDO::PARAM_STR);
        }
    }

    $st->execute();
    return $st;
}

/**
 * Build leg-level WHERE clause with placeholder prefix (w1_, w2_, etc.)
 * Prevents duplicated placeholders when embedded multiple times.
 */
function buildLegWhere(array $filters, array $acl, array &$params, string $prefix): string {
    $where = [];

    $fromKey = ':' . $prefix . '_fromDt';
    $toKey   = ':' . $prefix . '_toDt';

    $where[] = "calldate >= {$fromKey}";
    $where[] = "calldate <= {$toKey}";
    $params[$fromKey] = (string)$filters['fromDt'];
    $params[$toKey]   = (string)$filters['toDt'];

    $aclWhere  = (string)($acl['where'] ?? '1=1');
    $aclParams = (array)($acl['params'] ?? []);
    $aclBlock = prefixWhereAndParams($aclWhere, $aclParams, $prefix . '_acl');
    $where[] = '(' . $aclBlock['where'] . ')';
    foreach ($aclBlock['params'] as $k => $v) $params[$k] = $v;

    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $q1 = ':' . $prefix . '_q1';
        $q2 = ':' . $prefix . '_q2';
        $q3 = ':' . $prefix . '_q3';
        $q4 = ':' . $prefix . '_q4';
        $q5 = ':' . $prefix . '_q5';
        $q6 = ':' . $prefix . '_q6';
        $where[] = "(src LIKE {$q1} OR dst LIKE {$q2} OR clid LIKE {$q3} OR uniqueid LIKE {$q4} OR channel LIKE {$q5} OR dstchannel LIKE {$q6})";
        $params[$q1] = $like;
        $params[$q2] = $like;
        $params[$q3] = $like;
        $params[$q4] = $like;
        $params[$q5] = $like;
        $params[$q6] = $like;
    }

    $src = trim((string)($filters['src'] ?? ''));
    if ($src !== '' && preg_match('/^[0-9]+$/', $src)) {
        $pNum = ':' . $prefix . '_src_num';
        $pCh1 = ':' . $prefix . '_src_ch1';
        $pCh2 = ':' . $prefix . '_src_ch2';
        $where[] = "(src = {$pNum} OR channel LIKE {$pCh1} OR channel LIKE {$pCh2})";
        $params[$pNum] = $src;
        $params[$pCh1] = "SIP/{$src}-%";
        $params[$pCh2] = "PJSIP/{$src}-%";
    }

    $dst = trim((string)($filters['dst'] ?? ''));
    if ($dst !== '' && preg_match('/^[0-9]+$/', $dst)) {
        $pNum = ':' . $prefix . '_dst_num';
        $pCh1 = ':' . $prefix . '_dst_ch1';
        $pCh2 = ':' . $prefix . '_dst_ch2';
        $where[] = "(dst = {$pNum} OR dstchannel LIKE {$pCh1} OR dstchannel LIKE {$pCh2})";
        $params[$pNum] = $dst;
        $params[$pCh1] = "SIP/{$dst}-%";
        $params[$pCh2] = "PJSIP/{$dst}-%";
    }

    $disp = strtoupper(trim((string)($filters['disposition'] ?? '')));
    if ($disp !== '' && preg_match('/^[A-Z_ ]+$/', $disp)) {
        if ($disp === 'NO ANSWER') {
            $where[] = "(disposition='NO ANSWER' OR disposition='NOANSWER')";
        } elseif ($disp === 'CONGESTION') {
            $where[] = "(disposition='CONGESTION' OR disposition='CONGESTED')";
        } else {
            $p = ':' . $prefix . '_disp';
            $where[] = "disposition = {$p}";
            $params[$p] = $disp;
        }
    }

    $minDur = trim((string)($filters['mindur'] ?? ''));
    if ($minDur !== '' && ctype_digit($minDur)) {
        $p = ':' . $prefix . '_mindur';
        $where[] = "billsec >= {$p}";
        $params[$p] = (int)$minDur;
    }

    return implode(' AND ', $where);
}

/**
 * Group-level preset filter; uses its own legWhere (w2_) for first-leg subquery.
 */
function buildPresetGroupWhere(
    string $preset,
    string $gateway,
    array $allowedExts,
    string $cdrTable,
    string $legWhereForFirstLeg,
    array &$params
): string {
    $preset = strtolower(trim($preset));
    if ($preset === '') return "1=1";

    $grp = grpKeySql();

    $firstLegSub = "
        SELECT g.grpkey, MIN(g.calldate) AS first_calldate
        FROM (
            SELECT {$grp} AS grpkey, calldate
            FROM `{$cdrTable}`
            WHERE {$legWhereForFirstLeg}
        ) g
        GROUP BY g.grpkey
    ";

    $gwClauseFirst = buildGatewayClause($gateway, $params, 'gw_first');
    $gwClauseAny   = buildGatewayClause($gateway, $params, 'gw_any');

    $answeredAny = "
        EXISTS (
            SELECT 1 FROM `{$cdrTable}` a
            WHERE {$grp} = grp.grpkey
              AND a.disposition = 'ANSWERED'
        )
    ";

    $firstIsGateway = "
        EXISTS (
            SELECT 1
            FROM ({$firstLegSub}) fl
            JOIN `{$cdrTable}` f ON {$grp} = fl.grpkey AND f.calldate = fl.first_calldate
            WHERE fl.grpkey = grp.grpkey
              AND ({$gwClauseFirst})
        )
    ";

    $extParams = [];
    $firstIsExtClause = buildAllowedExtLikeClauses($allowedExts, $extParams, 'firstext');
    foreach ($extParams as $k => $v) $params[$k] = $v;

    $firstIsExt = "
        EXISTS (
            SELECT 1
            FROM ({$firstLegSub}) fl
            JOIN `{$cdrTable}` f ON {$grp} = fl.grpkey AND f.calldate = fl.first_calldate
            WHERE fl.grpkey = grp.grpkey
              AND {$firstIsExtClause}
        )
    ";

    $groupHasGatewayAny = "
        EXISTS (
            SELECT 1 FROM `{$cdrTable}` g2
            WHERE {$grp} = grp.grpkey
              AND ({$gwClauseAny})
        )
    ";

    if ($preset === 'inbound')  return "({$firstIsGateway})";
    if ($preset === 'outbound') return "({$firstIsExt} AND {$groupHasGatewayAny})";
    if ($preset === 'missed')   return "({$firstIsGateway} AND NOT {$answeredAny})";

    return "1=1";
}

/**
 * Summary for group=call
 */
function fetchSummary(PDO $pdo, array $filters, array $acl, array $allowedExts, string $cdrTable): array {
    $params = [];

    $legWhereMain     = buildLegWhere($filters, $acl, $params, 'w1');
    $legWhereFirstLeg = buildLegWhere($filters, $acl, $params, 'w2');

    $preset  = (string)($filters['preset'] ?? '');
    $gateway = (string)($filters['gateway'] ?? '');
    $presetGroupWhere = buildPresetGroupWhere($preset, $gateway, $allowedExts, $cdrTable, $legWhereFirstLeg, $params);

    $grp = grpKeySql();

    $sql = "
        SELECT
          COUNT(*) AS total_groups,
          SUM(CASE WHEN grp.answered_any = 1 THEN 1 ELSE 0 END) AS answered_groups,
          SUM(CASE WHEN grp.answered_any = 0 THEN 1 ELSE 0 END) AS missed_groups,
          SUM(grp.total_billsec) AS total_billsec
        FROM (
          SELECT
            {$grp} AS grpkey,
            MAX(CASE WHEN disposition='ANSWERED' THEN 1 ELSE 0 END) AS answered_any,
            SUM(billsec) AS total_billsec
          FROM `{$cdrTable}`
          WHERE {$legWhereMain}
          GROUP BY {$grp}
        ) grp
        WHERE {$presetGroupWhere}
    ";

    requireBindAll($sql, $params);
    $st = prepareBindExecute($pdo, $sql, $params);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total' => (int)($r['total_groups'] ?? 0),
        'answered' => (int)($r['answered_groups'] ?? 0),
        'missed' => (int)($r['missed_groups'] ?? 0),
        'total_billsec' => (int)($r['total_billsec'] ?? 0),
    ];
}

function countGroups(PDO $pdo, array $filters, array $acl, array $allowedExts, string $cdrTable): int {
    $params = [];

    $legWhereMain     = buildLegWhere($filters, $acl, $params, 'w1');
    $legWhereFirstLeg = buildLegWhere($filters, $acl, $params, 'w2');

    $preset  = (string)($filters['preset'] ?? '');
    $gateway = (string)($filters['gateway'] ?? '');
    $presetGroupWhere = buildPresetGroupWhere($preset, $gateway, $allowedExts, $cdrTable, $legWhereFirstLeg, $params);

    $grp = grpKeySql();

    $sql = "
      SELECT COUNT(*) AS c
      FROM (
        SELECT {$grp} AS grpkey
        FROM `{$cdrTable}`
        WHERE {$legWhereMain}
        GROUP BY {$grp}
      ) grp
      WHERE {$presetGroupWhere}
    ";

    requireBindAll($sql, $params);
    $st = prepareBindExecute($pdo, $sql, $params);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return (int)($r['c'] ?? 0);
}

function fetchPageRows(PDO $pdo, array $filters, array $acl, array $allowedExts, string $cdrTable): array {
    $params = [];

    $legWhereMain     = buildLegWhere($filters, $acl, $params, 'w1');
    $legWhereFirstLeg = buildLegWhere($filters, $acl, $params, 'w2');

    $preset  = (string)($filters['preset'] ?? '');
    $gateway = (string)($filters['gateway'] ?? '');
    $presetGroupWhere = buildPresetGroupWhere($preset, $gateway, $allowedExts, $cdrTable, $legWhereFirstLeg, $params);

    $grp = grpKeySql();

    $pageNo = max(1, (int)($filters['page'] ?? 1));
    $per    = (int)($filters['per'] ?? 50);
    if ($per < 10) $per = 10;
    if ($per > 200) $per = 200;
    $offset = ($pageNo - 1) * $per;

    $sort = (string)($filters['sort'] ?? 'start_calldate');
    $dir  = strtolower((string)($filters['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
    $allowedSort = ['start_calldate','src','dst','status','total_billsec','legs'];
    if (!in_array($sort, $allowedSort, true)) $sort = 'start_calldate';

    $sql = "
      SELECT
        grp.grpkey,
        grp.start_calldate,
        grp.clid,
        grp.src,
        grp.dst,
        grp.channel,
        grp.dstchannel,
        CASE WHEN grp.answered_any=1 THEN 'ANSWERED' ELSE 'MISSED' END AS status,
        grp.total_billsec,
        grp.legs
      FROM (
        SELECT
          {$grp} AS grpkey,
          MIN(calldate) AS start_calldate,
          SUBSTRING_INDEX(GROUP_CONCAT(clid ORDER BY calldate ASC SEPARATOR '\n'), '\n', 1) AS clid,
          SUBSTRING_INDEX(GROUP_CONCAT(src ORDER BY calldate ASC SEPARATOR '\n'), '\n', 1) AS src,
          SUBSTRING_INDEX(GROUP_CONCAT(dst ORDER BY calldate ASC SEPARATOR '\n'), '\n', 1) AS dst,
          SUBSTRING_INDEX(GROUP_CONCAT(channel ORDER BY calldate ASC SEPARATOR '\n'), '\n', 1) AS channel,
          SUBSTRING_INDEX(GROUP_CONCAT(dstchannel ORDER BY calldate ASC SEPARATOR '\n'), '\n', 1) AS dstchannel,
          MAX(CASE WHEN disposition='ANSWERED' THEN 1 ELSE 0 END) AS answered_any,
          SUM(billsec) AS total_billsec,
          COUNT(*) AS legs
        FROM `{$cdrTable}`
        WHERE {$legWhereMain}
        GROUP BY {$grp}
      ) grp
      WHERE {$presetGroupWhere}
      ORDER BY {$sort} {$dir}
      LIMIT :lim OFFSET :off
    ";

    $params[':lim'] = (int)$per;
    $params[':off'] = (int)$offset;

    requireBindAll($sql, $params);
    $st = prepareBindExecute($pdo, $sql, $params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetchTransitions(PDO $pdo, string $grpkey, array $filters, array $acl, string $cdrTable): array {
    $grpkey = trim($grpkey);
    if ($grpkey === '') return [];

    $params = [':grpkey' => $grpkey];
    $legWhere = buildLegWhere($filters, $acl, $params, 'w1');

    $grp = grpKeySql();

    $sql = "
      SELECT calldate, src, dst, channel, dstchannel, disposition, billsec, uniqueid, recordingfile
      FROM `{$cdrTable}`
      WHERE {$legWhere}
        AND {$grp} = :grpkey
      ORDER BY calldate ASC
    ";

    requireBindAll($sql, $params);
    $st = prepareBindExecute($pdo, $sql, $params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetchByExtension(PDO $pdo, array $filters, array $acl, array $allowedExts, string $cdrTable): array {
    $params = [];

    $legWhereMain     = buildLegWhere($filters, $acl, $params, 'w1');
    $legWhereFirstLeg = buildLegWhere($filters, $acl, $params, 'w2');

    $preset  = (string)($filters['preset'] ?? '');
    $gateway = (string)($filters['gateway'] ?? '');
    $presetGroupWhere = buildPresetGroupWhere($preset, $gateway, $allowedExts, $cdrTable, $legWhereFirstLeg, $params);

    $pageNo = max(1, (int)($filters['page'] ?? 1));
    $per    = (int)($filters['per'] ?? 50);
    if ($per < 10) $per = 10;
    if ($per > 200) $per = 200;
    $offset = ($pageNo - 1) * $per;

    $grp = grpKeySql();

    $extParams = [];
    $extClause = buildAllowedExtLikeClauses($allowedExts, $extParams, 'anyext');
    foreach ($extParams as $k => $v) $params[$k] = $v;

    $extractExtExpr = "
        CASE
          WHEN f.channel LIKE 'PJSIP/%' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(f.channel,'/',-1),'-',1)
          WHEN f.channel LIKE 'SIP/%'   THEN SUBSTRING_INDEX(SUBSTRING_INDEX(f.channel,'/',-1),'-',1)
          WHEN f.channel LIKE 'Local/%' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(f.channel,'/',-1),'@',1)
          WHEN f.dstchannel LIKE 'PJSIP/%' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(f.dstchannel,'/',-1),'-',1)
          WHEN f.dstchannel LIKE 'SIP/%'   THEN SUBSTRING_INDEX(SUBSTRING_INDEX(f.dstchannel,'/',-1),'-',1)
          WHEN f.dstchannel LIKE 'Local/%' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(f.dstchannel,'/',-1),'@',1)
          ELSE ''
        END
    ";

    $sql = "
      SELECT
        x.ext,
        MAX(x.start_calldate) AS last_calldate,
        COUNT(*) AS calls,
        SUM(CASE WHEN x.answered_any=1 THEN 1 ELSE 0 END) AS answered_calls,
        SUM(CASE WHEN x.answered_any=0 THEN 1 ELSE 0 END) AS missed_calls,
        SUM(x.total_billsec) AS total_billsec
      FROM (
        SELECT
          grp.grpkey,
          grp.start_calldate,
          grp.answered_any,
          grp.total_billsec,
          (
            SELECT {$extractExtExpr}
            FROM `{$cdrTable}` f
            WHERE {$grp} = grp.grpkey
              AND {$extClause}
            ORDER BY f.calldate ASC
            LIMIT 1
          ) AS ext
        FROM (
          SELECT
            {$grp} AS grpkey,
            MIN(calldate) AS start_calldate,
            MAX(CASE WHEN disposition='ANSWERED' THEN 1 ELSE 0 END) AS answered_any,
            SUM(billsec) AS total_billsec
          FROM `{$cdrTable}`
          WHERE {$legWhereMain}
          GROUP BY {$grp}
        ) grp
        WHERE {$presetGroupWhere}
      ) x
      WHERE x.ext <> ''
      GROUP BY x.ext
      ORDER BY calls DESC, last_calldate DESC
      LIMIT :lim OFFSET :off
    ";

    $params[':lim'] = (int)$per;
    $params[':off'] = (int)$offset;

    requireBindAll($sql, $params);
    $st = prepareBindExecute($pdo, $sql, $params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function countExtensions(PDO $pdo, array $filters, array $acl, array $allowedExts, string $cdrTable): int {
    $params = [];

    $legWhereMain     = buildLegWhere($filters, $acl, $params, 'w1');
    $legWhereFirstLeg = buildLegWhere($filters, $acl, $params, 'w2');

    $preset  = (string)($filters['preset'] ?? '');
    $gateway = (string)($filters['gateway'] ?? '');
    $presetGroupWhere = buildPresetGroupWhere($preset, $gateway, $allowedExts, $cdrTable, $legWhereFirstLeg, $params);

    $grp = grpKeySql();

    $extParams = [];
    $extClause = buildAllowedExtLikeClauses($allowedExts, $extParams, 'cnt_ext');
    foreach ($extParams as $k => $v) $params[$k] = $v;

    $extractExtExpr = "
        CASE
          WHEN f.channel LIKE 'PJSIP/%' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(f.channel,'/',-1),'-',1)
          WHEN f.channel LIKE 'SIP/%'   THEN SUBSTRING_INDEX(SUBSTRING_INDEX(f.channel,'/',-1),'-',1)
          WHEN f.channel LIKE 'Local/%' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(f.channel,'/',-1),'@',1)
          WHEN f.dstchannel LIKE 'PJSIP/%' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(f.dstchannel,'/',-1),'-',1)
          WHEN f.dstchannel LIKE 'SIP/%'   THEN SUBSTRING_INDEX(SUBSTRING_INDEX(f.dstchannel,'/',-1),'-',1)
          WHEN f.dstchannel LIKE 'Local/%' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(f.dstchannel,'/',-1),'@',1)
          ELSE ''
        END
    ";

    $sql = "
      SELECT COUNT(*) AS c
      FROM (
        SELECT
          (
            SELECT {$extractExtExpr}
            FROM `{$cdrTable}` f
            WHERE {$grp} = grp.grpkey
              AND {$extClause}
            ORDER BY f.calldate ASC
            LIMIT 1
          ) AS ext
        FROM (
          SELECT {$grp} AS grpkey
          FROM `{$cdrTable}`
          WHERE {$legWhereMain}
          GROUP BY {$grp}
        ) grp
        WHERE {$presetGroupWhere}
      ) x
      WHERE x.ext <> ''
      GROUP BY x.ext
    ";

    requireBindAll($sql, $params);
    $st = prepareBindExecute($pdo, $sql, $params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return count($rows);
}

function fetchLegByUniqueid(PDO $pdo, string $uniqueid, array $filters, array $acl, string $cdrTable): ?array {
    $uniqueid = trim($uniqueid);
    if ($uniqueid === '') return null;

    $params = [':uid' => $uniqueid];
    $legWhere = buildLegWhere($filters, $acl, $params, 'w1');

    $sql = "
      SELECT calldate, uniqueid, recordingfile
      FROM `{$cdrTable}`
      WHERE {$legWhere}
        AND uniqueid = :uid
      ORDER BY calldate DESC
      LIMIT 1
    ";

    requireBindAll($sql, $params);
    $st = prepareBindExecute($pdo, $sql, $params);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

