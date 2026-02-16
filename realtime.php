<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/* Load users */
$usersData = loadUsers($CONFIG['usersFile']);

/* Logout */
$action = strtolower((string)getParam('action', ''));
if ($action === 'logout') {
    doLogout();
}

/* Require login */
$me = requireSessionLogin($usersData);
$isAdmin = (bool)$me['is_admin'];

/* Admin User Management */
$page = (string)getParam('page', '');
if ($page === 'users') {
    handleUsersPage($CONFIG, $usersData, $me);
    exit;
}

/* API endpoint for realtime data */
if ($action === 'getdata') {
    header('Content-Type: application/json');

    // Read realtime data from service
    $dataFile = __DIR__ . '/data/asterisk-realtime-data.json';

    // Debug logging
    error_log("Realtime: Checking file: $dataFile");
    error_log("Realtime: File exists: " . (file_exists($dataFile) ? 'YES' : 'NO'));

    if (file_exists($dataFile)) {
        $fileAge = time() - filemtime($dataFile);
        error_log("Realtime: File age: $fileAge seconds");

        // Check if data is recent (updated within last 30 seconds - relaxed from 10)
        if ($fileAge > 30) {
            // Service is not running or stale data
            http_response_code(503);
            error_log("Realtime: File too old ($fileAge seconds)");
            echo json_encode([
                'status' => 'error',
                'error' => "Data is stale (age: {$fileAge}s)",
                'active_calls' => 0,
                'total_channels' => 0,
                'calls' => [],
                'timestamp' => time()
            ]);
        } else {
            // Fresh data
            error_log("Realtime: Returning fresh data");
            $data = file_get_contents($dataFile);
            echo $data;
        }
    } else {
        // File doesn't exist
        http_response_code(503);
        error_log("Realtime: File not found");
        echo json_encode([
            'status' => 'error',
            'error' => 'Data file not found',
            'active_calls' => 0,
            'total_channels' => 0,
            'calls' => [],
            'timestamp' => time()
        ]);
    }
    exit;
}

/* Debug endpoint - show raw file */
if ($action === 'debug') {
    header('Content-Type: text/plain');
    $dataFile = __DIR__ . '/data/asterisk-realtime-data.json';

    echo "=== PHP DIAGNOSTICS ===\n";
    echo "PHP User: " . (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : 'unknown') . "\n";
    echo "PHP open_basedir: " . ini_get('open_basedir') . "\n";
    echo "SELinux enabled: " . (file_exists('/usr/sbin/getenforce') ? shell_exec('/usr/sbin/getenforce 2>/dev/null') : 'unknown') . "\n";
    echo "\n=== FILE CHECK ===\n";
    echo "File path: $dataFile\n";
    echo "File exists (file_exists): " . (file_exists($dataFile) ? 'YES' : 'NO') . "\n";
    echo "File exists (is_file): " . (is_file($dataFile) ? 'YES' : 'NO') . "\n";
    echo "File readable (is_readable): " . (is_readable($dataFile) ? 'YES' : 'NO') . "\n";

    // Try to list data directory
    $dataDir = __DIR__ . '/data';
    echo "\n=== DATA DIRECTORY LISTING ===\n";
    echo "Directory: $dataDir\n";
    if (is_dir($dataDir)) {
        $files = @scandir($dataDir);
        if ($files) {
            foreach ($files as $file) {
                if ($file != '.' && $file != '..') {
                    echo "$file\n";
                }
            }
        } else {
            echo "Cannot read data directory\n";
        }
    } else {
        echo "Data directory does not exist\n";
    }

    if (file_exists($dataFile)) {
        echo "\n=== FILE DETAILS ===\n";
        echo "File size: " . filesize($dataFile) . " bytes\n";
        echo "Last modified: " . date('Y-m-d H:i:s', filemtime($dataFile)) . "\n";
        echo "File age: " . (time() - filemtime($dataFile)) . " seconds\n";
        echo "File permissions: " . substr(sprintf('%o', fileperms($dataFile)), -4) . "\n";
        echo "\n--- FILE CONTENTS ---\n";
        echo file_get_contents($dataFile);
    } else {
        echo "\n=== FILE NOT FOUND ===\n";
        echo "Try checking from shell: ls -la /tmp/asterisk-realtime-data.json\n";
    }
    exit;
}

/* Server-Sent Events stream for live updates */
if ($action === 'stream') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    $dataFile = __DIR__ . '/data/asterisk-realtime-data.json';
    $lastMtime = 0;

    // Send initial connection message
    echo "event: connected\n";
    echo "data: {\"status\":\"connected\"}\n\n";
    flush();

    // Stream updates for 60 seconds
    $endTime = time() + 60;
    while (time() < $endTime) {
        if (file_exists($dataFile)) {
            $currentMtime = filemtime($dataFile);
            $fileAge = time() - $currentMtime;

            // Send data if file was modified or every 5 seconds
            if ($currentMtime != $lastMtime || (time() % 5 == 0)) {
                if ($fileAge <= 10) {
                    // Fresh data
                    $data = file_get_contents($dataFile);
                    echo "event: update\n";
                    echo "data: " . $data . "\n\n";
                    flush();
                } else {
                    // Stale data
                    echo "event: error\n";
                    echo "data: {\"status\":\"error\",\"error\":\"Service not running\"}\n\n";
                    flush();
                }
                $lastMtime = $currentMtime;
            }
        } else {
            echo "event: error\n";
            echo "data: {\"status\":\"error\",\"error\":\"Data file not found\"}\n\n";
            flush();
        }

        sleep(1);
    }
    exit;
}

require __DIR__ . '/ui/realtime.php';
