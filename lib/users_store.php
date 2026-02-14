<?php
declare(strict_types=1);

function loadUsers(string $path): array {
    if (!is_readable($path)) fail("Users config not readable: {$path}");
    $raw = file_get_contents($path);
    if ($raw === false) fail("Cannot read users config: {$path}");
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['users']) || !is_array($data['users'])) {
        fail("Users config invalid JSON (expected {users:{...}})");
    }
    return $data;
}

function saveUsers(string $path, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) fail("Failed to encode users JSON");
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json) === false) fail("Failed to write temp users file: {$tmp}");
    if (!rename($tmp, $path)) fail("Failed to replace users file");
}

