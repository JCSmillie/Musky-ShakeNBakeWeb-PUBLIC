<?php
// ============================================================================
// MuskyFirstTimeSetup.php
// ----------------------------------------------------------------------------
// Helper functions for the Docker-friendly first-time Musky bootstrap flow.
// This is intentionally MariaDB-first: new setups should not need SQLite.
// ============================================================================

require_once __DIR__ . '/MuskyActivityLog.php';
require_once __DIR__ . '/MuskyTagDecode.php';
require_once __DIR__ . '/MuskyUserMariaSync.php';

function musky_first_time_pdo(): ?PDO
{
    $pdo = musky_user_mysql_sync_pdo();
    if (!$pdo instanceof PDO) {
        return null;
    }

    try {
        musky_user_mysql_sync_ensure_schema($pdo);
        musky_activity_ensure_schema($pdo);
    } catch (Throwable $e) {
        return null;
    }

    return $pdo;
}

function musky_first_time_config_store_exists(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
              FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?
             LIMIT 1
        ");
        $stmt->execute(['nora_config_store']);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function musky_first_time_ensure_config_store(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nora_config_store (
            ConfigID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ConfigGroup VARCHAR(100) NOT NULL,
            ConfigKey VARCHAR(120) NOT NULL,
            ConfigSet VARCHAR(100) NOT NULL DEFAULT 'DEFAULT',
            ValueType ENUM('text','json') NOT NULL DEFAULT 'text',
            ConfigValue LONGTEXT NOT NULL,
            IsActive TINYINT(1) NOT NULL DEFAULT 1,
            IsSecret TINYINT(1) NOT NULL DEFAULT 0,
            DescriptionText VARCHAR(255) DEFAULT NULL,
            UpdatedBy VARCHAR(190) DEFAULT NULL,
            CreatedBy VARCHAR(190) DEFAULT NULL,
            CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (ConfigID),
            UNIQUE KEY uq_nora_config_group_key_set (ConfigGroup, ConfigKey, ConfigSet),
            KEY idx_nora_config_group (ConfigGroup),
            KEY idx_nora_config_active (ConfigGroup, ConfigKey, IsActive),
            KEY idx_nora_config_secret (IsSecret)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function musky_first_time_seed_base_config(PDO $pdo, string $actor = 'first_time_access'): array
{
    musky_first_time_ensure_config_store($pdo);

    $result = [
        'base_rows_upserted' => 0,
        'tagdecode' => [
            'inserted' => 0,
            'updated' => 0,
            'errors' => [],
        ],
    ];

    $rows = [
        [
            'group' => 'MOSYLE',
            'key' => 'API_KEY',
            'set' => 'DEFAULT',
            'type' => 'text',
            'value' => '',
            'secret' => 1,
            'description' => 'Mosyle API accessToken/API key for Nora and Musky Mosyle calls.',
        ],
        [
            'group' => 'MOSYLE',
            'key' => 'API_USERNAME',
            'set' => 'DEFAULT',
            'type' => 'text',
            'value' => '',
            'secret' => 1,
            'description' => 'Mosyle API username used to mint bearer tokens.',
        ],
        [
            'group' => 'MOSYLE',
            'key' => 'API_PASSWORD',
            'set' => 'DEFAULT',
            'type' => 'text',
            'value' => '',
            'secret' => 1,
            'description' => 'Mosyle API password used to mint bearer tokens.',
        ],
        [
            'group' => 'MUSKY',
            'key' => 'MAINTENANCE_MODE',
            'set' => 'LOGIN_GATE',
            'type' => 'text',
            'value' => 'FALSE',
            'secret' => 0,
            'description' => 'Controls whether Musky login is held in maintenance mode.',
        ],
        [
            'group' => 'MUSKY',
            'key' => 'PANDA_CHARGES_ENABLED',
            'set' => 'DEFAULT',
            'type' => 'text',
            'value' => 'FALSE',
            'secret' => 0,
            'description' => 'Enables the PANDA charge workflow surfaces when explicitly turned on.',
        ],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO nora_config_store
            (ConfigGroup, ConfigKey, ConfigSet, ValueType, ConfigValue, IsActive, IsSecret, DescriptionText, CreatedBy, UpdatedBy)
        VALUES
            (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            ValueType = VALUES(ValueType),
            ConfigValue = VALUES(ConfigValue),
            IsActive = VALUES(IsActive),
            IsSecret = VALUES(IsSecret),
            DescriptionText = VALUES(DescriptionText),
            UpdatedBy = VALUES(UpdatedBy)
    ");

    foreach ($rows as $row) {
        $stmt->execute([
            $row['group'],
            $row['key'],
            $row['set'],
            $row['type'],
            $row['value'],
            $row['secret'],
            $row['description'],
            $actor,
            $actor,
        ]);
        $result['base_rows_upserted']++;
    }

    $result['tagdecode'] = musky_tag_decode_seed_defaults($pdo, $actor);
    return $result;
}

function musky_first_time_find_sample_asset_tag(PDO $pdo): ?string
{
    $queries = [
        "
            SELECT asset_tag
              FROM devices
             WHERE asset_tag REGEXP '^[0-9]{5}$'
             ORDER BY asset_tag
             LIMIT 1
        ",
        "
            SELECT asset_tag
             FROM device_history
             WHERE asset_tag REGEXP '^[0-9]{5}$'
             ORDER BY snapshot_time DESC
             LIMIT 1
        ",
        "
            SELECT asset_tag
              FROM devices
             WHERE COALESCE(asset_tag, '') <> ''
             ORDER BY asset_tag
             LIMIT 1
        ",
        "
            SELECT asset_tag
              FROM device_history
             WHERE COALESCE(asset_tag, '') <> ''
             ORDER BY snapshot_time DESC
             LIMIT 1
        ",
    ];

    foreach ($queries as $sql) {
        try {
            $value = $pdo->query($sql)->fetchColumn();
            if (is_string($value)) {
                $value = trim($value);
                if ($value !== '') {
                    return $value;
                }
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    return null;
}

function musky_first_time_status(): array
{
    $pdo = musky_first_time_pdo();
    $status = [
        'db_ready' => $pdo instanceof PDO,
        'config_store_ready' => false,
        'first_time_required' => true,
        'local_login_count' => 0,
        'musky_user_count' => 0,
        'admin_exists' => false,
        'sample_asset_tag' => null,
    ];

    if (!$pdo instanceof PDO) {
        return $status;
    }

    $status['config_store_ready'] = musky_first_time_config_store_exists($pdo);
    $status['local_login_count'] = musky_user_store_table_count($pdo, 'users');
    $status['musky_user_count'] = musky_user_store_table_count($pdo, 'musky_users');
    $status['admin_exists'] = musky_user_store_admin_exists($pdo);
    $status['first_time_required'] = musky_first_time_access_required($pdo);
    $status['sample_asset_tag'] = musky_first_time_find_sample_asset_tag($pdo);

    return $status;
}

function musky_first_time_bootstrap(string $email, string $password, string $actor = 'first_time_access'): array
{
    $pdo = musky_first_time_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Could not connect to Nora MariaDB from Musky.'];
    }

    $seed = musky_first_time_seed_base_config($pdo, $actor);
    $admin = musky_user_store_create_local_admin($email, $password, [
        'role' => 'admin',
        'building' => 'docker',
        'allowed_tools' => 'ALL_TOOLS,ADMIN_PANEL,DEVICE_MANAGER',
        'theme' => 'musky-mode',
    ]);

    if (!$admin['ok']) {
        return $admin + ['seed' => $seed];
    }

    return [
        'ok' => true,
        'seed' => $seed,
        'admin' => $admin,
        'sample_asset_tag' => musky_first_time_find_sample_asset_tag($pdo),
    ];
}

function musky_first_time_log_user_in(array $muskyUser, string $username): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    session_regenerate_id(true);
    $_SESSION['username'] = $username;
    $_SESSION['logged_in'] = true;
    $_SESSION['check_in'] = true;
    $_SESSION['last_active'] = time();
    $_SESSION['was_logged'] = true;
    $_SESSION['musky_user'] = $muskyUser;
}
