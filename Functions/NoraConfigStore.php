<?php
// Safe, read-oriented helper for Nora-backed config values from Musky.
// This intentionally avoids the legacy nora_connect.php behavior that dies
// on failure; login and hold-aware pages should fail open instead.

function musky_nora_config_store_pdo(): ?PDO
{
    static $pdo = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if ($pdo === null) {
        return null;
    }

    $configPath = dirname(__DIR__) . '/nora_config.json';
    if (!is_file($configPath) || !is_readable($configPath)) {
        $pdo = null;
        return null;
    }

    $cfg = json_decode((string)file_get_contents($configPath), true);
    if (!is_array($cfg)) {
        $pdo = null;
        return null;
    }

    $host = $cfg['host'] ?? 'localhost';
    $user = $cfg['user'] ?? ($cfg['username'] ?? 'root');
    $pass = $cfg['pass'] ?? ($cfg['password'] ?? '');
    $db   = $cfg['name'] ?? ($cfg['database'] ?? 'nora');
    $port = $cfg['port'] ?? 3306;
    $dsn  = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        $pdo = null;
    }

    return $pdo instanceof PDO ? $pdo : null;
}

function musky_nora_config_store_table_exists(?PDO $pdo, string $table): bool
{
    if (!$pdo instanceof PDO) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT 1
              FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?
        ");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function musky_nora_active_config_row(string $group, string $key, string $set = 'DEFAULT'): ?array
{
    static $cache = [];

    $group = strtoupper(trim($group));
    $key = strtoupper(trim($key));
    $set = strtoupper(trim($set === '' ? 'DEFAULT' : $set));
    $cacheKey = "{$group}::{$key}::{$set}";

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $pdo = musky_nora_config_store_pdo();
    if (!musky_nora_config_store_table_exists($pdo, 'nora_config_store')) {
        $cache[$cacheKey] = null;
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT *
              FROM nora_config_store
             WHERE ConfigGroup = ?
               AND ConfigKey = ?
               AND ConfigSet = ?
               AND IsActive = 1
             ORDER BY UpdatedAt DESC, ConfigID DESC
             LIMIT 1
        ");
        $stmt->execute([$group, $key, $set]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cache[$cacheKey] = is_array($row) ? $row : null;
    } catch (Throwable $e) {
        $cache[$cacheKey] = null;
    }

    return $cache[$cacheKey];
}

function musky_nora_flag_value_is_enabled($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return ((int)$value) !== 0;
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return false;
    }

    $json = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        if (array_key_exists('enabled', $json)) {
            return musky_nora_flag_value_is_enabled($json['enabled']);
        }
        if (array_key_exists('maintenance_mode', $json)) {
            return musky_nora_flag_value_is_enabled($json['maintenance_mode']);
        }
        if (array_key_exists('value', $json)) {
            return musky_nora_flag_value_is_enabled($json['value']);
        }
    }

    return in_array(strtoupper($raw), ['1', 'TRUE', 'YES', 'ON', 'ENABLED', 'HOLD', 'ACTIVE'], true);
}

function musky_nora_maintenance_mode_enabled(string $set = 'LOGIN_GATE'): bool
{
    $row = musky_nora_active_config_row('MUSKY', 'MAINTENANCE_MODE', $set);
    if (!is_array($row)) {
        return false;
    }

    return musky_nora_flag_value_is_enabled($row['ConfigValue'] ?? null);
}
