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

