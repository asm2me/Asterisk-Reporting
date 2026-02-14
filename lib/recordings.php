<?php
declare(strict_types=1);

namespace Supervisor\Lib;

/**
 * Recording helpers (secure path resolve + HTTP Range streaming)
 */

function sanitizeRelativePath(string $p): string {
    $p = str_replace("\0", '', $p);
    $p = str_replace("\\", "/", $p);
    $p = preg_replace('#/+#', '/', $p);
    $p = ltrim($p, '/');
    while (strpos($p, '../') !== false) $p = str_replace('../', '', $p);
    return $p;
}

function findRecordingFile(string $baseDir, ?string $recordingfile, ?string $calldate): ?string {
    if ($recordingfile === null) return null;
    $recordingfile = trim((string)$recordingfile);
    if ($recordingfile === '') return null;

    if (strlen($recordingfile) > 512) return null;
    if (preg_match('/[\x00-\x1F\x7F]/', $recordingfile)) return null;

    $baseDir = rtrim($baseDir, '/');

    // Absolute path: allow only inside baseDir
    if ($recordingfile[0] === '/') {
        $real = realpath($recordingfile);
        if ($real && strpos($real, $baseDir . '/') === 0 && is_file($real)) return $real;
        return null;
    }

    // Relative path under baseDir
    $rel = sanitizeRelativePath($recordingfile);
    $try = $baseDir . '/' . $rel;
    $real = realpath($try);
    if ($real && strpos($real, $baseDir . '/') === 0 && is_file($real)) return $real;

    // Common Asterisk monitor layout: YYYY/MM/DD/<file>
    if ($calldate && preg_match('/^\d{4}-\d{2}-\d{2}/', $calldate)) {
        $yyyy = substr($calldate, 0, 4);
        $mm   = substr($calldate, 5, 2);
        $dd   = substr($calldate, 8, 2);
        $dir  = $baseDir . "/{$yyyy}/{$mm}/{$dd}";
        $bn = basename($rel);

        foreach ([$bn, "$bn.wav", "$bn.mp3", "$bn.gsm", "$bn.WAV", "$bn.MP3", "$bn.GSM"] as $name) {
            $p = $dir . '/' . $name;
            $r = realpath($p);
            if ($r && strpos($r, $baseDir . '/') === 0 && is_file($r)) return $r;
        }
    }

    return null;
}

function streamFile(string $absPath, string $mode = 'inline'): void {
    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    $mime = 'application/octet-stream';
    if ($ext === 'wav') $mime = 'audio/wav';
    elseif ($ext === 'mp3') $mime = 'audio/mpeg';
    elseif ($ext === 'gsm') $mime = 'audio/gsm';

    $size = filesize($absPath);
    $filename = basename($absPath);

    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');

    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        $start = (int)$m[1];
        $end = ($m[2] !== '') ? (int)$m[2] : ($size - 1);
        if ($start <= $end && $end < $size) {
            http_response_code(206);
            header("Content-Type: {$mime}");
            header("Content-Range: bytes {$start}-{$end}/{$size}");
            header("Content-Length: " . ($end - $start + 1));
            header('Content-Disposition: ' . ($mode === 'attachment' ? 'attachment' : 'inline') . '; filename="' . $filename . '"');

            $fh = fopen($absPath, 'rb');
            if ($fh) {
                fseek($fh, $start);
                $left = $end - $start + 1;
                while ($left > 0 && !feof($fh)) {
                    $chunk = fread($fh, min(8192, $left));
                    if ($chunk === false) break;
                    echo $chunk;
                    $left -= strlen($chunk);
                }
                fclose($fh);
            }
            exit;
        }
    }

    header("Content-Type: {$mime}");
    header("Content-Length: {$size}");
    header('Content-Disposition: ' . ($mode === 'attachment' ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    readfile($absPath);
    exit;
}

