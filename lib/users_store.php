<?php
declare(strict_types=1);

namespace Supervisor\Lib;

/**
 * Users + settings store (JSON)
 *
 * Expected JSON structure:
 * {
 *   "gateways": ["PJSIP/we"],
 *   "users": {
 *     "admin": {
 *       "password_hash": "$2y$...",
 *       "is_admin": true,
 *       "extensions": ["100","101","102"]
 *     }
 *   }
 * }
 */

function loadUsers(string $path): array {
    if (!is_readable($path)) fail("Users config not readable: {$path}");
    $raw = file_get_contents($path);
    if ($raw === false) fail("Cannot read users config: {$path}");

    $data = json_decode($raw, true);
    if (!is_array($data)) fail("Users config invalid JSON");

    if (!isset($data['users']) || !is_array($data['users'])) {
        $data['users'] = [];
    }
    if (!isset($data['gateways']) || !is_array($data['gateways'])) {
        $data['gateways'] = [];
    }

    // Normalize users structure
    foreach ($data['users'] as $u => $rec) {
        if (!is_array($rec)) $rec = [];
        if (!isset($rec['password_hash'])) $rec['password_hash'] = '';
        if (!isset($rec['is_admin'])) $rec['is_admin'] = false;
        if (!isset($rec['extensions']) || !is_array($rec['extensions'])) $rec['extensions'] = [];
        $data['users'][$u] = $rec;
    }

    // Ensure admin exists (do NOT set password automatically)
    if (!isset($data['users']['admin'])) {
        $data['users']['admin'] = [
            'password_hash' => '',
            'is_admin' => true,
            'extensions' => [],
        ];
    } else {
        $data['users']['admin']['is_admin'] = true;
    }

    return $data;
}

function saveUsers(string $path, array $data): void {
    if (!isset($data['users']) || !is_array($data['users'])) $data['users'] = [];
    if (!isset($data['gateways']) || !is_array($data['gateways'])) $data['gateways'] = [];

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) fail("Failed to encode users JSON");

    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json) === false) fail("Failed to write temp users file: {$tmp}");
    if (!rename($tmp, $path)) fail("Failed to replace users file");
}

/**
 * Utility: ensure username rules
 */
function isValidUsername(string $u): bool {
    $u = trim($u);
    if ($u === '') return false;
    return (bool)preg_match('/^[A-Za-z0-9_\-\.]{3,32}$/', $u);
}

/**
 * Update helpers used by admin UI (optional)
 */
function setUser(array &$usersData, string $username, string $passwordHash, bool $isAdmin, array $extensions): void {
    if (!isset($usersData['users']) || !is_array($usersData['users'])) $usersData['users'] = [];
    $usersData['users'][$username] = [
        'password_hash' => $passwordHash,
        'is_admin' => $isAdmin,
        'extensions' => array_values($extensions),
    ];
}

function deleteUser(array &$usersData, string $username): void {
    if ($username === 'admin') return;
    if (isset($usersData['users'][$username])) unset($usersData['users'][$username]);
}

