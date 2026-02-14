<?php
declare(strict_types=1);

namespace Supervisor\Lib;

use PDO;
use Throwable;

/**
 * CDR querying + grouping
 *
 * Group key:
 *   grpkey = COALESCE(NULLIF(linkedid,''), uniqueid)
 *
 * Presets:
 *   inbound:  FIRST LEG channel/dstchannel matches gateway prefix
 *   outbound: FIRST LEG is local extension channel, AND group contains gateway leg anywhere
 *   missed:   inbound + group has NO answered leg
 *
 * Notes:
 * - gateway is a prefix like "PJSIP/we" (no dash) OR "PJSIP/we-" (ok)
 * - local extensions are determined from allowedExts array (digits)
 */

function grpKeySql(): string {
    return "COALESCE(NULLIF(linkedid,''), uniqueid)";
}

function normalizeGateway(string $gw): string {
    $gw = trim($gw);
    if ($gw === '') return '';
    $gw = str_replace("\0", '', $gw);
    // remove trailing wildcard
    $gw = rtrim($gw, '%');
    // remove trailing dash if user included dash
    $gw = rtrim($gw, '-');
    return $gw;
}

function gatewayLike(string $gw): string {
    // We want: "PJSIP/we-%"
    $gw = normalizeGateway($gw);
    if ($gw === '') return '';
    return $gw . "-%";
}

function buildAllowedExtLikeClauses(array $allowedExts, array &$params, string $prefix = 'ext'): string {
    // Matches local extension channels:
    //  PJSIP/100-...
    //  SIP/100-...
    //  Local/100@...
    $parts = [];
    $i = 0;
    foreach ($allowedExts as $e) {
        $e = (string)$e;
        if ($e === '' || !preg_match('/^[0-9]+$/', $e)) continue;

        $p1 = ":{$prefix}_pjsip_$i";
        $p2 = ":{$prefix}_sip_$i";
        $p3 = ":{$prefix}_local_$i";
        $params[$p1] = "PJSIP/{$e}-%";
        $params[$p2] = "SIP/{$e}-%";
        $params[$p3] = "Local/{$e}@%";

        $parts[] = "(channel LIKE $p1 OR dstchannel LIKE $p1 OR channel LIKE $p2 OR dstchannel LIKE $p2 OR channel LIKE $p3 OR dstchannel LIKE $p3)";
        $i++;
    }
    if (!$parts) return "(1=0)";
    return "(" . implode(" OR ", $parts) . ")";
}

function buildGatewayClause(string $gateway, array &$params, string $paramName = 'gw'): string {
    $like = gatewayLike($gateway);
    if ($like === '') return "(1=1)";
    $p1 = ":{$paramName}_ch";
    $p2 = ":{$paramName}_dch";
    $params[$p1] = $like;
    $params[$p2] = $like;
    return "(channel LIKE $p1 OR dstchannel LIKE $p2)";
}

function requireBindAll(PDO $pdo, string $sql, array $params): void {
    // Simple sanity check (debug friendly): ensure all :names appearing are present in params
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
 * Build base WHERE for legs
 */
function buildLegWhere(array $filters, array $acl, array &$params): string {
    $where = [];

    $where[] = "calldate >= :fromDt";
    $where[] = "calldate <= :toDt";
    $params[':fromDt'] = (string)$filters['fromDt'];
    $params[':toDt']   = (string)$filters['toDt'];

    // ACL clause passed in (already contains its own params merged before call)
    $where[] = $acl['where'];
    foreach ($acl['params'] as $k => $v) $params[$k] = $v;

    // Search q over common fields
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(src LIKE :q1 OR dst LIKE :q2 OR clid LIKE :q3 OR uniqueid LIKE :q4 OR channel LIKE :q5 OR dstchannel LIKE :q6)";
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
        $params[':q4'] = $like;
        $params[':q5'] = $like;
        $params[':q6'] = $like;
    }

    // src / dst digits filters
    $src = trim((string)($filters['src'] ?? ''));
    if ($src !== '' && preg_match('/^[0-9]+$/', $src)) {
        $where[] = "(src = :src_num OR channel LIKE :src_ch1 OR channel LIKE :src_ch2)";
        $params[':src_num'] = $src;
        $params[':src_ch1'] = "SIP/{$src}-%";
        $params[':src_ch2'] = "PJSIP/{$src}-%";
    }

    $dst = trim((string)($filters['dst'] ?? ''));
    if ($dst !== '' && preg_match('/^[0-9]+$/', $dst)) {
        $where[] = "(dst = :dst_num OR dstchannel LIKE :dst_ch1 OR dstchannel LIKE :dst_ch2)";
        $params[':dst_num'] = $dst;
        $params[':dst_ch1'] = "SIP/{$dst}-%";
        $params[':dst_ch2'] = "PJSIP/{$dst}-%";
    }

    // disposition leg filter (optional)
    $disp = strtoupper(trim((string)($filters['disposition'] ?? '')));
    if ($disp !== '' && preg_match('/^[A-Z_ ]+$/', $disp)) {
        if ($disp === 'NO ANSWER') {
            $where[] = "(disposition='NO ANSWER' OR disposition='NOANSWER')";
        } elseif ($disp === 'CONGESTION') {
            $where[] = "(disposition='CONGESTION' OR disposition='CONGESTED')";
        } else {
            $where[] = "disposition = :disp";
            $params[':disp'] = $disp;
        }
    }

    // min billsec leg filter (optional)
    $minDur = trim((string)($filters['mindur'] ?? ''));
    if ($minDur !== '' && ctype_digit($minDur)) {
        $where[] = "billsec >= :mindur";
        $params[':mindur'] = (int)$minDur;
    }

    return implode(" AND ", $where);
}

/**
 * Apply preset at GROUP LEVEL (correct classification).
 *
 * Returns extra SQL that can be appended to the group selection query (WHERE ... AND <extra>).
 */
function buildPresetGroupWhere(string $preset, string $gateway, array $allowedExts, string $cdrTable, string $legWhereSql, array &$params): string {
    $preset = strtolower(trim($preset));
    if ($preset === '') return "1=1";

    $grp = grpKeySql();

    // Subquery giving the FIRST leg per group (by earliest calldate).
    // NOTE: Use min(calldate) to identify start time, then join back to pick the row(s).
    $firstLegSub = "
        SELECT g.grpkey, MIN(g.calldate) AS first_calldate
        FROM (
            SELECT {$grp} AS grpkey, calldate
            FROM `{$cdrTable}`
            WHERE {$legWhereSql}
        ) g
        GROUP BY g.grpkey
    ";

    $gwClauseFirst = buildGatewayClause($gateway, $params, 'gw_first');
    $gwClauseAny   = buildGatewayClause($gateway, $params, 'gw_any');

    // any-answered leg in group
    $answeredAny = "
        EXISTS (
            SELECT 1 FROM `{$cdrTable}` a
            WHERE {$grp} = grp.grpkey
              AND a.disposition = 'ANSWERED'
        )
    ";

    // FIRST leg is gateway?
    $firstIsGateway = "
        EXISTS (
            SELECT 1
            FROM ({$firstLegSub}) fl
            JOIN `{$cdrTable}` f ON {$grp} = fl.grpkey AND f.calldate = fl.first_calldate
            WHERE fl.grpkey = grp.grpkey
              AND ({$gwClauseFirst})
        )
    ";

    // FIRST leg is local ext?
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

    // group touches gateway anywhere?
    $groupHasGatewayAny = "
        EXISTS (
            SELECT 1 FROM `{$cdrTable}` g2
            WHERE {$grp} = grp.grpkey
              AND ({$gwClauseAny})
        )
    ";

    if ($preset === 'inbound') {
        // trunk initiated (FIRST leg is gateway)
        return "({$firstIsGateway})";
    }

    if ($preset === 'outbound') {
        // extension initiated (FIRST leg is local ext) AND gateway exists somewhere
        return "({$firstIsExt} AND {$groupHasGatewayAny})";
    }

    if ($preset === 'missed') {
        // missed = inbound AND no answered anywhere
        return "({$firstIsGateway} AND NOT {$answeredAny})";
    }

    return "1=1";
}

/**
 * Fetch summary for grouped calls view (group=call)
 */
function fetchSummary(PDO $pdo, array $filters, array $acl, array $allowedExts, string $cdrTable): array {
    $params = [];
    $legWhereSql = buildLegWhere($filters, $acl, $params);

    $preset = (string)($filters['preset'] ?? '');
    $gateway = (string)($filters['gateway'] ?? '');
    $presetGroupWhere = buildPresetGroupWhere($preset, $gateway, $allowedExts, $cdrTable, $legWhereSql, $params);

    $grp = grpKeySql();

    // Build grouped view then compute answered/missed at group-level
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
          WHERE {$legWhereSql}
          GROUP BY {$grp}
        ) grp
        WHERE {$presetGroupWhere}
    ";

    requireBindAll($pdo, $sql, $params);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total' => (int)($r['total_groups'] ?? 0),
        'answered' => (int)($r['answered_groups'] ?? 0),
        'missed' => (int)($r['missed_groups'] ?? 0),
        'total_billsec' => (int)($r['total_billsec'] ?? 0),
    ];
}

/**
 * Fetch page rows (group=call)
 */
function fetchPageRows(PDO $pdo, array $filters, array $acl, array $allowedExts, string $cdrTable): array {
    $params = [];
    $legWhereSql = buildLegWhere($filters, $acl, $params);

    $preset = (string)($filters['preset'] ?? '');
    $gateway = (string)($filters['gateway'] ?? '');
    $presetGroupWhere = buildPresetGroupWhere($preset, $gateway, $allowedExts, $cdrTable, $legWhereSql, $params);

    $grp = grpKeySql();

    $pageNo = max(1, (int)($filters['page'] ?? 1));
    $per    = (int)($filters['per'] ?? 50);
    if ($per < 10) $per = 10;
    if ($per > 200) $per = 200;
    $offset = ($pageNo - 1) * $per;

    // Sorting on grouped rows
    $sort = (string)($filters['sort'] ?? 'start_calldate');
    $dir  = strtolower((string)($filters['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
    $allowedSort = ['start_calldate','src','dst','status','total_billsec','legs'];
    if (!in_array($sort, $allowedSort, true)) $sort = 'start_calldate';

    // grouped rows with representative (min calldate) values
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
        WHERE {$legWhereSql}
        GROUP BY {$grp}
      ) grp
      WHERE {$presetGroupWhere}
      ORDER BY {$sort} {$dir}
      LIMIT :lim OFFSET :off
    ";

    $params[':lim'] = $per;
    $params[':off'] = $offset;

    requireBindAll($pdo, $sql, $params);
    $st = $pdo->prepare($sql);

    // bind ints
    foreach ($params as $k => $v) {
        if ($k === ':lim' || $k === ':off') {
            $st->bindValue($k, (int)$v, PDO::PARAM_INT);
        } else {
            $st->bindValue($k, $v);
        }
    }

    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Count groups (for pagination)
 */
function countGroups(PDO $pdo, array $filters, array $acl, array $allowedExts, string $cdrTable): int {
    $params = [];
    $legWhereSql = buildLegWhere($filters, $acl, $params);

    $preset = (string)($filters['preset'] ?? '');
    $gateway = (string)($filters['gateway'] ?? '');
    $presetGroupWhere = buildPresetGroupWhere($preset, $gateway, $allowedExts, $cdrTable, $legWhereSql, $params);

    $grp = grpKeySql();

    $sql = "
      SELECT COUNT(*) AS c
      FROM (
        SELECT {$grp} AS grpkey
        FROM `{$cdrTable}`
        WHERE {$legWhereSql}
        GROUP BY {$grp}
      ) grp
      WHERE {$presetGroupWhere}
    ";

    requireBindAll($pdo, $sql, $params);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return (int)($r['c'] ?? 0);
}

/**
 * Transitions (legs) for a given group key.
 */
function fetchTransitions(PDO $pdo, string $grpkey, array $filters, array $acl, string $cdrTable): array {
    $grpkey = trim($grpkey);
    if ($grpkey === '') return [];

    $params = [':grpkey' => $grpkey];
    $legWhereSql = buildLegWhere($filters, $acl, $params);

    $grp = grpKeySql();

    $sql = "
      SELECT calldate, src, dst, channel, dstchannel, disposition, billsec, uniqueid, recordingfile
      FROM `{$cdrTable}`
      WHERE {$legWhereSql}
        AND {$grp} = :grpkey
      ORDER BY calldate ASC
    ";

    requireBindAll($pdo, $sql, $params);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Extension grouping (group=ext)
 * - inbound/outbound presets apply using same group-level rules, but the aggregation is per extension.
 */
function fetchByExtension(PDO $pdo, array $filters, array $acl, array $allowedExts, string $cdrTable): array {
    $params = [];
    $legWhereSql = buildLegWhere($filters, $acl, $params);

    $preset = (string)($filters['preset'] ?? '');
    $gateway = (string)($filters['gateway'] ?? '');
    $presetGroupWhere = buildPresetGroupWhere($preset, $gateway, $allowedExts, $cdrTable, $legWhereSql, $params);

    $pageNo = max(1, (int)($filters['page'] ?? 1));
    $per    = (int)($filters['per'] ?? 50);
    if ($per < 10) $per = 10;
    if ($per > 200) $per = 200;
    $offset = ($pageNo - 1) * $per;

    $grp = grpKeySql();

    // We attribute a group to an extension based on FIRST local extension found in the group (by time)
    // This is a practical heuristic for “grouping by extension” view.
    $extParams = [];
    $extClause = buildAllowedExtLikeClauses($allowedExts, $extParams, 'anyext');
    foreach ($extParams as $k => $v) $params[$k] = $v;

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
            SELECT
              CASE
                WHEN f.channel REGEXP '^(PJSIP|SIP)/[0-9]+' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(f.channel,'/',-1),'-',1)
                WHEN f.dstchannel REGEXP '^(PJSIP|SIP)/[0-9]+' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(f.dstchannel,'/',-1),'-',1)
                WHEN f.channel LIKE 'Local/%' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(f.channel,'/',-1),'@',1)
                WHEN f.dstchannel LIKE 'Local/%' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(f.dstchannel,'/',-1),'@',1)
                ELSE ''
              END
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
          WHERE {$legWhereSql}
          GROUP BY {$grp}
        ) grp
        WHERE {$presetGroupWhere}
      ) x
      WHERE x.ext <> ''
      GROUP BY x.ext
      ORDER BY calls DESC
      LIMIT :lim OFFSET :off
    ";

    $params[':lim'] = $per;
    $params[':off'] = $offset;

    requireBindAll($pdo, $sql, $params);
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        if ($k === ':lim' || $k === ':off') $st->bindValue($k, (int)$v, PDO::PARAM_INT);
        else $st->bindValue($k, $v);
    }
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

