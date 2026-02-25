<?php
function aclWhereByChannel(array $exts, array &$params): string {
    $out=[];
    foreach ($exts as $i=>$e) {
        $out[]="(
            channel LIKE :c1_$i OR dstchannel LIKE :c2_$i OR
            channel LIKE :c3_$i OR dstchannel LIKE :c4_$i
        )";
        $params[":c1_$i"]="SIP/$e-%";
        $params[":c2_$i"]="SIP/$e-%";
        $params[":c3_$i"]="PJSIP/$e-%";
        $params[":c4_$i"]="PJSIP/$e-%";
    }
    return '(' . implode(' OR ', $out) . ')';
}

/**
 * Check whether a CDR row's channel/dstchannel belongs to one of the
 * allowed extensions. Mirrors the SQL logic in aclWhereByChannel().
 */
function rowAllowedByChannel(array $exts, string $ch, string $dch): bool {
    foreach ($exts as $e) {
        $e = (string)$e;
        if (
            strpos($ch,  "SIP/{$e}-")   === 0 || strpos($dch, "SIP/{$e}-")   === 0 ||
            strpos($ch,  "PJSIP/{$e}-") === 0 || strpos($dch, "PJSIP/{$e}-") === 0
        ) {
            return true;
        }
    }
    return false;
}

