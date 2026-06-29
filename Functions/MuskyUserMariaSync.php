<?php
// ============================================================================
// MuskyUserMariaSync.php
// ----------------------------------------------------------------------------
// PURPOSE
//   Provide a bridge between Musky's historic SQLite user store and the newer
//   MariaDB-backed copy. This file now supports three related jobs:
//
//   1. MariaDB-first user storage for the riskiest auth/admin surfaces.
//   2. SQLite fallback when the MariaDB side is unavailable.
//   3. Best-effort dual-sync during the transition so either side can recover
//      the other if one store disappears temporarily.
//
// DESIGN NOTES
//   - This helper is intentionally non-fatal. If MariaDB is unavailable, Musky
//     login must still succeed through the existing SQLite path.
//   - We use the Nora/MariaDB config file because that is the stable shared
//     MySQL connection pattern already present in this codebase.
// ============================================================================

require_once __DIR__ . '/MuskyConfig.php';

/**
 * Locate the MariaDB config Musky already uses for Nora-aware pages.
 */
function musky_user_mysql_sync_config_path(): ?string
{
    $path = dirname(__DIR__) . '/nora_config.json';
    return (is_file($path) && is_readable($path)) ? $path : null;
}

/**
 * Open a safe MariaDB connection for background sync work.
 * Returns null instead of throwing so login flow is never blocked.
 */
function musky_user_mysql_sync_pdo(): ?PDO
{
    static $pdo = null;
    static $failed = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if ($failed) {
        return null;
    }

    $configPath = musky_user_mysql_sync_config_path();
    if (!$configPath) {
        $failed = true;
        return null;
    }

    $cfg = json_decode((string)@file_get_contents($configPath), true);
    if (!is_array($cfg)) {
        $failed = true;
        return null;
    }

    $host = $cfg['host'] ?? 'localhost';
    $user = $cfg['user'] ?? ($cfg['username'] ?? 'root');
    $pass = $cfg['pass'] ?? ($cfg['password'] ?? '');
    $db   = $cfg['name'] ?? ($cfg['database'] ?? 'nora');
    $port = (int)($cfg['port'] ?? 3306);

    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        return $pdo;
    } catch (Throwable $e) {
        $failed = true;
        return null;
    }
}

/**
 * Make sure the MariaDB side has the columns we care about. This is designed
 * to be additive so it can safely run against an existing musky_users table.
 */
function musky_user_mysql_sync_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS musky_users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            role VARCHAR(64) NULL,
            building VARCHAR(128) NULL,
            allowed_tools TEXT NULL,
            theme VARCHAR(64) NULL,
            default_loaner_pool VARCHAR(255) NULL,
            full_name VARCHAR(255) NULL,
            first_name VARCHAR(255) NULL,
            last_name VARCHAR(255) NULL,
            org_unit VARCHAR(255) NULL,
            title VARCHAR(255) NULL,
            department VARCHAR(255) NULL,
            location VARCHAR(255) NULL,
            google_id VARCHAR(255) NULL,
            is_suspended TINYINT(1) NOT NULL DEFAULT 0,
            photo_url TEXT NULL,
            last_login DATETIME NULL,
            last_login_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            source_db VARCHAR(32) NOT NULL DEFAULT 'sqlite',
            last_synced_at DATETIME NOT NULL,
            UNIQUE KEY uq_musky_users_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $existing = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM musky_users");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($row['Field'])) {
            $existing[strtolower((string)$row['Field'])] = true;
        }
    }

    $addColumn = static function (string $name, string $ddl) use ($pdo, $existing): void {
        if (isset($existing[strtolower($name)])) {
            return;
        }
        $pdo->exec("ALTER TABLE musky_users ADD COLUMN {$ddl}");
    };

    $addColumn('role', 'role VARCHAR(64) NULL');
    $addColumn('building', 'building VARCHAR(128) NULL');
    $addColumn('allowed_tools', 'allowed_tools TEXT NULL');
    $addColumn('theme', 'theme VARCHAR(64) NULL');
    $addColumn('default_loaner_pool', 'default_loaner_pool VARCHAR(255) NULL');
    $addColumn('full_name', 'full_name VARCHAR(255) NULL');
    $addColumn('first_name', 'first_name VARCHAR(255) NULL');
    $addColumn('last_name', 'last_name VARCHAR(255) NULL');
    $addColumn('org_unit', 'org_unit VARCHAR(255) NULL');
    $addColumn('title', 'title VARCHAR(255) NULL');
    $addColumn('department', 'department VARCHAR(255) NULL');
    $addColumn('location', 'location VARCHAR(255) NULL');
    $addColumn('google_id', 'google_id VARCHAR(255) NULL');
    $addColumn('is_suspended', 'is_suspended TINYINT(1) NOT NULL DEFAULT 0');
    $addColumn('photo_url', 'photo_url TEXT NULL');
    $addColumn('last_login', 'last_login DATETIME NULL');
    $addColumn('last_login_at', 'last_login_at DATETIME NULL');
    $addColumn('created_at', 'created_at DATETIME NULL');
    $addColumn('updated_at', 'updated_at DATETIME NULL');
    $addColumn('source_db', "source_db VARCHAR(32) NOT NULL DEFAULT 'sqlite'");
    $addColumn('last_synced_at', 'last_synced_at DATETIME NULL');

    // Group access is part of Musky's auth picture too, so we provision the
    // MySQL mirror table here alongside musky_users. This keeps admin/group
    // management pages from having to care which backend they are reading.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS musky_group_access (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            group_email VARCHAR(255) NOT NULL,
            allowed_tools TEXT NULL,
            source_db VARCHAR(32) NOT NULL DEFAULT 'sqlite',
            last_synced_at DATETIME NOT NULL,
            UNIQUE KEY uq_musky_group_access_email (group_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Legacy/internal login support tables. These are provisioned in MariaDB
    // so local-login/admin features can survive even if the SQLite file is
    // removed later.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL,
            password TEXT NULL,
            email VARCHAR(255) NULL,
            theme VARCHAR(64) NULL,
            created_at DATETIME NULL,
            UNIQUE KEY uq_users_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $ready = true;
}

/**
 * Reuse the same SQLite discovery behavior as the prefs layer:
 * config path first, then dev harness, then the legacy fallback DB.
 */
function musky_user_sqlite_source_path(): ?string
{
    global $SQLITE_PATH;

    $configuredSqlitePath = musky_root_config_string('session.sqlite_path', '');
    if ($configuredSqlitePath !== '') {
        $SQLITE_PATH = $configuredSqlitePath;
    }

    $productionFallbacks = array_values(array_filter([
        $configuredSqlitePath,
    ]));

    if (!empty($SQLITE_PATH)) {
        $dir = dirname($SQLITE_PATH);
        if (is_file($SQLITE_PATH) || (is_dir($dir) && is_writable($dir))) {
            return $SQLITE_PATH;
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['TESTMODE_SQLITE_PATH'])) {
        $testPath = (string)$_SESSION['TESTMODE_SQLITE_PATH'];
        $dir = dirname($testPath);
        if (is_file($testPath) || (is_dir($dir) && is_writable($dir))) {
            return $testPath;
        }
    }

    foreach ($productionFallbacks as $path) {
        $dir = dirname($path);
        if (is_file($path) || (is_dir($dir) && is_writable($dir))) {
            return $path;
        }
    }

    $legacy = dirname(__DIR__) . '/Data/musky_users.db';
    if (is_file($legacy) || (is_dir(dirname($legacy)) && is_writable(dirname($legacy)))) {
        return $legacy;
    }

    return null;
}

/**
 * Shared SQLite connection helper. When the file is missing but the directory
 * is writable, SQLite will create the DB and we then provision the minimum
 * Musky schema needed for fallback mode.
 */
function musky_user_sqlite_pdo(): ?PDO
{
    static $pdo = null;
    static $failed = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if ($failed) {
        return null;
    }

    $path = musky_user_sqlite_source_path();
    if (!$path) {
        $failed = true;
        return null;
    }

    try {
        $pdo = new PDO("sqlite:$path");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("PRAGMA busy_timeout = 3000");
        musky_user_sqlite_ensure_schema($pdo);
        return $pdo;
    } catch (Throwable $e) {
        $failed = true;
        return null;
    }
}

/**
 * SQLite-side schema provisioner for fallback mode.
 */
function musky_user_sqlite_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS musky_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE NOT NULL,
            role TEXT DEFAULT 'student',
            building TEXT DEFAULT 'unknown',
            allowed_tools TEXT DEFAULT '',
            theme TEXT DEFAULT 'musky-mode',
            default_loaner_pool TEXT,
            full_name TEXT,
            first_name TEXT,
            last_name TEXT,
            org_unit TEXT,
            title TEXT,
            department TEXT,
            location TEXT,
            google_id TEXT,
            is_suspended INTEGER DEFAULT 0,
            photo_url TEXT,
            last_login TEXT,
            last_login_at TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS musky_group_access (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_email TEXT UNIQUE NOT NULL,
            allowed_tools TEXT DEFAULT ''
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT,
            email TEXT,
            theme TEXT DEFAULT 'light-mode',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $columnsByTable = [
        'musky_users' => [
            'theme' => "theme TEXT DEFAULT 'musky-mode'",
            'default_loaner_pool' => 'default_loaner_pool TEXT',
            'full_name' => 'full_name TEXT',
            'first_name' => 'first_name TEXT',
            'last_name' => 'last_name TEXT',
            'org_unit' => 'org_unit TEXT',
            'title' => 'title TEXT',
            'department' => 'department TEXT',
            'location' => 'location TEXT',
            'google_id' => 'google_id TEXT',
            'is_suspended' => 'is_suspended INTEGER DEFAULT 0',
            'photo_url' => 'photo_url TEXT',
            'last_login' => 'last_login TEXT',
            'last_login_at' => 'last_login_at TEXT',
            'created_at' => 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
        ],
        'musky_group_access' => [
            'allowed_tools' => "allowed_tools TEXT DEFAULT ''",
        ],
        'users' => [
            'email' => 'email TEXT',
            'theme' => "theme TEXT DEFAULT 'light-mode'",
            'created_at' => 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
        ],
    ];

    foreach ($columnsByTable as $table => $columns) {
        $existing = [];
        foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[strtolower((string)($row['name'] ?? ''))] = true;
        }
        foreach ($columns as $column => $ddl) {
            if (!isset($existing[strtolower($column)])) {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $ddl");
            }
        }
    }

    $ready = true;
}

/**
 * Return the preferred live user-store handle.
 * MariaDB is preferred when available; SQLite remains the fallback.
 */
function musky_user_store_primary_pdo(): ?PDO
{
    $mysql = musky_user_mysql_sync_pdo();
    if ($mysql instanceof PDO) {
        try {
            musky_user_mysql_sync_ensure_schema($mysql);
            return $mysql;
        } catch (Throwable $e) {
        }
    }

    return musky_user_sqlite_pdo();
}

function musky_user_store_backend_name(?PDO $pdo): string
{
    if (!$pdo instanceof PDO) {
        return 'none';
    }

    try {
        return (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (Throwable $e) {
        return 'unknown';
    }
}

function musky_user_store_has_table(PDO $pdo, string $table): bool
{
    $driver = musky_user_store_backend_name($pdo);

    try {
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare("
                SELECT 1
                  FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = ?
                 LIMIT 1
            ");
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        }

        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function musky_user_store_has_column(PDO $pdo, string $table, string $column): bool
{
    $driver = musky_user_store_backend_name($pdo);

    try {
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare("
                SELECT 1
                  FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = ?
                   AND column_name = ?
                 LIMIT 1
            ");
            $stmt->execute([$table, $column]);
            return (bool)$stmt->fetchColumn();
        }

        foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (strcasecmp((string)($row['name'] ?? ''), $column) === 0) {
                return true;
            }
        }
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Load the current SQLite musky_users row when the caller did not already
 * hand us a fresh copy.
 */
function musky_user_sqlite_fetch_by_email(string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    $pdo = musky_user_sqlite_pdo();
    if (!$pdo instanceof PDO) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM musky_users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Normalize dates to a MariaDB-friendly DATETIME string when possible.
 */
function musky_user_mysql_sync_datetime($value): ?string
{
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return null;
    }

    try {
        return (new DateTime($value))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Background sync a single user from SQLite/session into MariaDB.
 *
 * @param string $email
 * @param array|null $sourceRow Optional fresh source row to avoid a second
 *                              SQLite read when caller already has it.
 */
function musky_user_mysql_sync_by_email(string $email, ?array $sourceRow = null): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    $pdo = musky_user_mysql_sync_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }

    try {
        musky_user_mysql_sync_ensure_schema($pdo);
    } catch (Throwable $e) {
        return false;
    }

    $sessionRow = (isset($_SESSION['musky_user']) && is_array($_SESSION['musky_user']))
        ? $_SESSION['musky_user']
        : [];

    $sqliteRow = is_array($sourceRow) ? $sourceRow : musky_user_sqlite_fetch_by_email($email);

    // Only let the live session enrich the row when we are syncing the same
    // actual user. During bulk imports, applying the logged-in admin session to
    // every legacy row would stamp everyone with the admin's name/photo/theme.
    $sessionEmail = strtolower(trim((string)($sessionRow['email'] ?? '')));
    $sessionOverlay = ($sessionEmail !== '' && $sessionEmail === $email) ? $sessionRow : [];

    $row = array_merge($sqliteRow ?? [], $sessionOverlay);
    $row['email'] = $email;

    if (empty($row)) {
        return false;
    }

    $lastLoginAt = musky_user_mysql_sync_datetime($row['last_login_at'] ?? null) ?: date('Y-m-d H:i:s');

    $payload = [
        'email'         => $email,
        'role'          => trim((string)($row['role'] ?? '')),
        'building'      => trim((string)($row['building'] ?? '')),
        'allowed_tools' => trim((string)($row['allowed_tools'] ?? '')),
        'theme'         => trim((string)($row['theme'] ?? '')),
        'default_loaner_pool' => trim((string)($row['default_loaner_pool'] ?? '')),
        'full_name'     => trim((string)($row['full_name'] ?? ($row['name'] ?? ''))),
        'first_name'    => trim((string)($row['first_name'] ?? '')),
        'last_name'     => trim((string)($row['last_name'] ?? '')),
        'org_unit'      => trim((string)($row['org_unit'] ?? '')),
        'title'         => trim((string)($row['title'] ?? '')),
        'department'    => trim((string)($row['department'] ?? '')),
        'location'      => trim((string)($row['location'] ?? '')),
        'google_id'     => trim((string)($row['google_id'] ?? '')),
        'is_suspended'  => !empty($row['is_suspended']) ? 1 : 0,
        'photo_url'     => trim((string)($row['photo_url'] ?? '')),
        'last_login'    => musky_user_mysql_sync_datetime($row['last_login'] ?? null),
        'last_login_at' => $lastLoginAt,
        'created_at'    => musky_user_mysql_sync_datetime($row['created_at'] ?? null),
        'updated_at'    => date('Y-m-d H:i:s'),
        'source_db'     => 'sqlite',
        'last_synced_at'=> date('Y-m-d H:i:s'),
    ];

    try {
        $stmt = $pdo->prepare("
            INSERT INTO musky_users (
                email, role, building, allowed_tools, theme, default_loaner_pool,
                full_name, first_name, last_name, org_unit, title,
                department, location, google_id, is_suspended, photo_url,
                last_login, last_login_at, created_at, updated_at,
            source_db, last_synced_at
            ) VALUES (
                :email, :role, :building, :allowed_tools, :theme, :default_loaner_pool,
                :full_name, :first_name, :last_name, :org_unit, :title,
                :department, :location, :google_id, :is_suspended, :photo_url,
                :last_login, :last_login_at, :created_at, :updated_at,
                :source_db, :last_synced_at
            )
            ON DUPLICATE KEY UPDATE
                role = VALUES(role),
                building = VALUES(building),
                allowed_tools = VALUES(allowed_tools),
                theme = VALUES(theme),
                default_loaner_pool = VALUES(default_loaner_pool),
                full_name = VALUES(full_name),
                first_name = VALUES(first_name),
                last_name = VALUES(last_name),
                org_unit = VALUES(org_unit),
                title = VALUES(title),
                department = VALUES(department),
                location = VALUES(location),
                google_id = VALUES(google_id),
                is_suspended = VALUES(is_suspended),
                photo_url = VALUES(photo_url),
                last_login = VALUES(last_login),
                last_login_at = VALUES(last_login_at),
                created_at = COALESCE(musky_users.created_at, VALUES(created_at)),
                updated_at = VALUES(updated_at),
                source_db = VALUES(source_db),
                last_synced_at = VALUES(last_synced_at)
        ");
        $stmt->execute($payload);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Pull the full SQLite musky_users list for admin sync/fallback scenarios.
 */
function musky_user_sqlite_fetch_all_users(): array
{
    $pdo = musky_user_sqlite_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }

    try {
        return $pdo->query("SELECT * FROM musky_users ORDER BY email")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Pull the SQLite group access list when present.
 */
function musky_user_sqlite_fetch_all_groups(): array
{
    $pdo = musky_user_sqlite_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }

    try {
        if (!musky_user_store_has_table($pdo, 'musky_group_access')) {
            return [];
        }

        return $pdo->query("SELECT * FROM musky_group_access ORDER BY group_email")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Sync a single group access row into MariaDB.
 */
function musky_user_mysql_sync_group_access(string $groupEmail, string $allowedTools): bool
{
    $groupEmail = strtolower(trim($groupEmail));
    if ($groupEmail === '') {
        return false;
    }

    $pdo = musky_user_mysql_sync_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }

    try {
        musky_user_mysql_sync_ensure_schema($pdo);
        $stmt = $pdo->prepare("
            INSERT INTO musky_group_access (
                group_email, allowed_tools, source_db, last_synced_at
            ) VALUES (
                :group_email, :allowed_tools, 'sqlite', NOW()
            )
            ON DUPLICATE KEY UPDATE
                allowed_tools = VALUES(allowed_tools),
                source_db = VALUES(source_db),
                last_synced_at = VALUES(last_synced_at)
        ");
        $stmt->execute([
            'group_email'   => $groupEmail,
            'allowed_tools' => trim($allowedTools),
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function musky_user_sqlite_upsert_group_access(string $groupEmail, string $allowedTools): bool
{
    $groupEmail = strtolower(trim($groupEmail));
    if ($groupEmail === '') {
        return false;
    }

    $pdo = musky_user_sqlite_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO musky_group_access (group_email, allowed_tools)
            VALUES (?, ?)
            ON CONFLICT(group_email) DO UPDATE SET allowed_tools = excluded.allowed_tools
        ");
        $stmt->execute([$groupEmail, trim($allowedTools)]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function musky_user_sqlite_delete_user(string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    $pdo = musky_user_sqlite_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM musky_users WHERE email = ?");
        $stmt->execute([$email]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function musky_user_sqlite_upsert_user_row(array $row): bool
{
    $email = strtolower(trim((string)($row['email'] ?? '')));
    if ($email === '') {
        return false;
    }

    $pdo = musky_user_sqlite_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO musky_users (
                email, role, building, allowed_tools, theme, default_loaner_pool,
                full_name, first_name, last_name, org_unit, title,
                department, location, google_id, is_suspended, photo_url,
                last_login, last_login_at, created_at
            ) VALUES (
                :email, :role, :building, :allowed_tools, :theme, :default_loaner_pool,
                :full_name, :first_name, :last_name, :org_unit, :title,
                :department, :location, :google_id, :is_suspended, :photo_url,
                :last_login, :last_login_at, COALESCE(:created_at, CURRENT_TIMESTAMP)
            )
            ON CONFLICT(email) DO UPDATE SET
                role = excluded.role,
                building = excluded.building,
                allowed_tools = excluded.allowed_tools,
                theme = excluded.theme,
                default_loaner_pool = excluded.default_loaner_pool,
                full_name = excluded.full_name,
                first_name = excluded.first_name,
                last_name = excluded.last_name,
                org_unit = excluded.org_unit,
                title = excluded.title,
                department = excluded.department,
                location = excluded.location,
                google_id = excluded.google_id,
                is_suspended = excluded.is_suspended,
                photo_url = excluded.photo_url,
                last_login = excluded.last_login,
                last_login_at = excluded.last_login_at
        ");
        $stmt->execute([
            'email'         => $email,
            'role'          => trim((string)($row['role'] ?? '')),
            'building'      => trim((string)($row['building'] ?? '')),
            'allowed_tools' => trim((string)($row['allowed_tools'] ?? '')),
            'theme'         => trim((string)($row['theme'] ?? 'musky-mode')),
            'default_loaner_pool' => trim((string)($row['default_loaner_pool'] ?? '')),
            'full_name'     => trim((string)($row['full_name'] ?? ($row['name'] ?? ''))),
            'first_name'    => trim((string)($row['first_name'] ?? '')),
            'last_name'     => trim((string)($row['last_name'] ?? '')),
            'org_unit'      => trim((string)($row['org_unit'] ?? '')),
            'title'         => trim((string)($row['title'] ?? '')),
            'department'    => trim((string)($row['department'] ?? '')),
            'location'      => trim((string)($row['location'] ?? '')),
            'google_id'     => trim((string)($row['google_id'] ?? '')),
            'is_suspended'  => !empty($row['is_suspended']) ? 1 : 0,
            'photo_url'     => trim((string)($row['photo_url'] ?? '')),
            'last_login'    => trim((string)($row['last_login'] ?? '')),
            'last_login_at' => trim((string)($row['last_login_at'] ?? date('Y-m-d H:i:s'))),
            'created_at'    => trim((string)($row['created_at'] ?? '')),
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Store the user's preferred default loaner pool in the live user store and
 * mirror it to the fallback side while Musky is still in mixed-backend mode.
 */
function musky_user_set_default_loaner_pool(string $email, ?string $poolName): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    $normalizedPool = trim((string)($poolName ?? ''));
    $normalizedPool = $normalizedPool !== '' ? $normalizedPool : null;

    $primary = musky_user_store_primary_pdo();
    if (!$primary instanceof PDO) {
        return false;
    }

    $backend = musky_user_store_backend_name($primary);
    $now = date('Y-m-d H:i:s');

    try {
        if ($backend === 'mysql') {
            musky_user_mysql_sync_ensure_schema($primary);
            $stmt = $primary->prepare("
                INSERT INTO musky_users (
                    email, default_loaner_pool, updated_at, created_at, source_db, last_synced_at
                ) VALUES (
                    :email, :default_loaner_pool, :updated_at, COALESCE(:created_at, NOW()), 'session', :last_synced_at
                )
                ON DUPLICATE KEY UPDATE
                    default_loaner_pool = VALUES(default_loaner_pool),
                    updated_at = VALUES(updated_at),
                    last_synced_at = VALUES(last_synced_at)
            ");
            $stmt->execute([
                'email' => $email,
                'default_loaner_pool' => $normalizedPool,
                'updated_at' => $now,
                'created_at' => $now,
                'last_synced_at' => $now,
            ]);
            musky_user_sqlite_upsert_user_row([
                'email' => $email,
                'default_loaner_pool' => $normalizedPool ?? '',
            ]);
        } else {
            musky_user_sqlite_ensure_schema($primary);
            $stmt = $primary->prepare("
                INSERT INTO musky_users (
                    email, default_loaner_pool, created_at
                ) VALUES (
                    :email, :default_loaner_pool, COALESCE(:created_at, CURRENT_TIMESTAMP)
                )
                ON CONFLICT(email) DO UPDATE SET
                    default_loaner_pool = excluded.default_loaner_pool
            ");
            $stmt->execute([
                'email' => $email,
                'default_loaner_pool' => $normalizedPool,
                'created_at' => $now,
            ]);
            musky_user_mysql_sync_by_email($email, [
                'email' => $email,
                'default_loaner_pool' => $normalizedPool ?? '',
            ]);
        }
    } catch (Throwable $e) {
        return false;
    }

    if (isset($_SESSION['musky_user']) && is_array($_SESSION['musky_user'])) {
        $sessionEmail = strtolower(trim((string)($_SESSION['musky_user']['email'] ?? '')));
        if ($sessionEmail === $email) {
            $_SESSION['musky_user']['default_loaner_pool'] = $normalizedPool ?? '';
            $GLOBALS['_musky_user_pref_cache'] = null;
        }
    }

    return true;
}

function musky_user_sqlite_fetch_all_oldskool_users(): array
{
    $pdo = musky_user_sqlite_pdo();
    if (!$pdo instanceof PDO || !musky_user_store_has_table($pdo, 'users')) {
        return [];
    }

    try {
        return $pdo->query("SELECT * FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function musky_user_mysql_sync_oldskool_user_row(array $row): bool
{
    $username = trim((string)($row['username'] ?? ''));
    if ($username === '') {
        return false;
    }

    $pdo = musky_user_mysql_sync_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }

    try {
        musky_user_mysql_sync_ensure_schema($pdo);
        $stmt = $pdo->prepare("
            INSERT INTO users (username, password, email, theme, created_at)
            VALUES (:username, :password, :email, :theme, :created_at)
            ON DUPLICATE KEY UPDATE
                password = VALUES(password),
                email = VALUES(email),
                theme = VALUES(theme),
                created_at = COALESCE(users.created_at, VALUES(created_at))
        ");
        $stmt->execute([
            'username'   => $username,
            'password'   => (string)($row['password'] ?? ''),
            'email'      => trim((string)($row['email'] ?? '')),
            'theme'      => trim((string)($row['theme'] ?? 'light-mode')),
            'created_at' => musky_user_mysql_sync_datetime($row['created_at'] ?? null),
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function musky_user_sqlite_upsert_oldskool_user_row(array $row): bool
{
    $username = trim((string)($row['username'] ?? ''));
    if ($username === '') {
        return false;
    }

    $pdo = musky_user_sqlite_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO users (username, password, email, theme, created_at)
            VALUES (:username, :password, :email, :theme, COALESCE(:created_at, CURRENT_TIMESTAMP))
            ON CONFLICT(username) DO UPDATE SET
                password = excluded.password,
                email = excluded.email,
                theme = excluded.theme
        ");
        $stmt->execute([
            'username'   => $username,
            'password'   => (string)($row['password'] ?? ''),
            'email'      => trim((string)($row['email'] ?? '')),
            'theme'      => trim((string)($row['theme'] ?? 'light-mode')),
            'created_at' => trim((string)($row['created_at'] ?? '')),
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Remove a mirrored user row from MariaDB when admin intentionally deletes it
 * from the legacy source.
 */
function musky_user_mysql_delete_user(string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    $pdo = musky_user_mysql_sync_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }

    try {
        musky_user_mysql_sync_ensure_schema($pdo);
        $stmt = $pdo->prepare("DELETE FROM musky_users WHERE email = ?");
        $stmt->execute([$email]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Full background sync for the admin panel. This is intentionally lightweight:
 * copy all current SQLite users/groups into MariaDB and report what happened.
 */
function musky_user_mysql_sync_all_from_sqlite(): array
{
    $result = [
        'mysql_ready'    => false,
        'users_seen'     => 0,
        'users_synced'   => 0,
        'groups_seen'    => 0,
        'groups_synced'  => 0,
        'legacy_users_seen' => 0,
        'legacy_users_synced' => 0,
    ];

    $pdo = musky_user_mysql_sync_pdo();
    if (!$pdo instanceof PDO) {
        return $result;
    }

    try {
        musky_user_mysql_sync_ensure_schema($pdo);
        $result['mysql_ready'] = true;
    } catch (Throwable $e) {
        return $result;
    }

    $users = musky_user_sqlite_fetch_all_users();
    $groups = musky_user_sqlite_fetch_all_groups();
    $legacyUsers = musky_user_sqlite_fetch_all_oldskool_users();
    $result['users_seen'] = count($users);
    foreach ($users as $row) {
        if (musky_user_mysql_sync_by_email((string)($row['email'] ?? ''), $row)) {
            $result['users_synced']++;
        }
    }

    $result['groups_seen'] = count($groups);
    foreach ($groups as $row) {
        if (musky_user_mysql_sync_group_access((string)($row['group_email'] ?? ''), (string)($row['allowed_tools'] ?? ''))) {
            $result['groups_synced']++;
        }
    }

    $result['legacy_users_seen'] = count($legacyUsers);
    foreach ($legacyUsers as $row) {
        if (musky_user_mysql_sync_oldskool_user_row($row)) {
            $result['legacy_users_synced']++;
        }
    }

    return $result;
}

/**
 * Read helpers for admin dashboards that want to inspect the MySQL mirror.
 */
function musky_user_mysql_fetch_all_users(): array
{
    $pdo = musky_user_mysql_sync_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }

    try {
        musky_user_mysql_sync_ensure_schema($pdo);
        return $pdo->query("SELECT * FROM musky_users ORDER BY email")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function musky_user_mysql_fetch_all_groups(): array
{
    $pdo = musky_user_mysql_sync_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }

    try {
        musky_user_mysql_sync_ensure_schema($pdo);
        return $pdo->query("SELECT * FROM musky_group_access ORDER BY group_email")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function musky_user_mysql_fetch_user_by_email(string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    $pdo = musky_user_mysql_sync_pdo();
    if (!$pdo instanceof PDO) {
        return null;
    }

    try {
        musky_user_mysql_sync_ensure_schema($pdo);
        $stmt = $pdo->prepare("SELECT * FROM musky_users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function musky_user_store_table_count(?PDO $pdo, string $table): int
{
    if (!$pdo instanceof PDO || !musky_user_store_has_table($pdo, $table)) {
        return 0;
    }

    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function musky_user_store_admin_exists(?PDO $pdo = null): bool
{
    $pdo = $pdo instanceof PDO ? $pdo : musky_user_store_primary_pdo();
    if (!$pdo instanceof PDO || !musky_user_store_has_table($pdo, 'musky_users')) {
        return false;
    }

    try {
        $sql = "
            SELECT 1
              FROM musky_users
             WHERE UPPER(COALESCE(allowed_tools, '')) LIKE '%ALL_TOOLS%'
                OR UPPER(COALESCE(allowed_tools, '')) LIKE '%ADMIN_PANEL%'
                OR UPPER(COALESCE(allowed_tools, '')) LIKE '%HDK-SUPERVISOR-ADMIN%'
             LIMIT 1
        ";
        return (bool)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function musky_user_store_local_login_exists(?PDO $pdo = null): bool
{
    $pdo = $pdo instanceof PDO ? $pdo : musky_user_store_primary_pdo();
    return musky_user_store_table_count($pdo, 'users') > 0;
}

function musky_first_time_access_required(?PDO $pdo = null): bool
{
    $pdo = $pdo instanceof PDO ? $pdo : musky_user_store_primary_pdo();
    if (!$pdo instanceof PDO) {
        return true;
    }

    if (!musky_user_store_local_login_exists($pdo)) {
        return true;
    }

    return !musky_user_store_admin_exists($pdo);
}

function musky_user_store_create_local_admin(string $email, string $password, array $options = []): array
{
    $email = strtolower(trim($email));
    $password = (string)$password;

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'A valid admin email address is required.'];
    }

    if (strlen($password) < 10) {
        return ['ok' => false, 'error' => 'Password must be at least 10 characters long.'];
    }

    $pdo = musky_user_store_primary_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Musky user store is unavailable.'];
    }

    $driver = musky_user_store_backend_name($pdo);
    $theme = trim((string)($options['theme'] ?? 'musky-mode'));
    $building = trim((string)($options['building'] ?? 'docker'));
    $role = trim((string)($options['role'] ?? 'admin'));
    $allowedTools = trim((string)($options['allowed_tools'] ?? 'ALL_TOOLS,ADMIN_PANEL,DEVICE_MANAGER'));
    $now = date('Y-m-d H:i:s');

    try {
        if ($driver === 'mysql') {
            musky_user_mysql_sync_ensure_schema($pdo);
        } else {
            musky_user_sqlite_ensure_schema($pdo);
        }

        $existingUserStmt = $pdo->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
        $existingUserStmt->execute([$email]);
        if ($existingUserStmt->fetchColumn()) {
            return ['ok' => false, 'error' => 'That local admin email already exists.'];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->beginTransaction();

        $userInsert = $pdo->prepare("INSERT INTO users (username, password, email, theme, created_at) VALUES (?, ?, ?, ?, ?)");
        $userInsert->execute([$email, $hash, $email, $theme, $now]);

        if ($driver === 'mysql') {
            $muskyInsert = $pdo->prepare("
                INSERT INTO musky_users (
                    email, role, building, allowed_tools, theme,
                    created_at, updated_at, source_db, last_synced_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, 'legacy', ?)
                ON DUPLICATE KEY UPDATE
                    role = VALUES(role),
                    building = VALUES(building),
                    allowed_tools = VALUES(allowed_tools),
                    theme = VALUES(theme),
                    updated_at = VALUES(updated_at),
                    source_db = VALUES(source_db),
                    last_synced_at = VALUES(last_synced_at)
            ");
            $muskyInsert->execute([$email, $role, $building, $allowedTools, $theme, $now, $now, $now]);
        } else {
            $insertOrIgnore = $pdo->prepare("
                INSERT OR IGNORE INTO musky_users (
                    email, role, building, allowed_tools, theme, created_at
                ) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $insertOrIgnore->execute([$email, $role, $building, $allowedTools, $theme]);

            $update = $pdo->prepare("
                UPDATE musky_users
                   SET role = ?, building = ?, allowed_tools = ?, theme = ?
                 WHERE email = ?
            ");
            $update->execute([$role, $building, $allowedTools, $theme, $email]);
        }

        $pdo->commit();

        if ($driver === 'mysql') {
            musky_user_sqlite_upsert_oldskool_user_row([
                'username' => $email,
                'password' => $hash,
                'email' => $email,
                'theme' => $theme,
            ]);
            musky_user_sqlite_upsert_user_row([
                'email' => $email,
                'role' => $role,
                'building' => $building,
                'allowed_tools' => $allowedTools,
                'theme' => $theme,
            ]);
        } else {
            musky_user_mysql_sync_oldskool_user_row([
                'username' => $email,
                'password' => $hash,
                'email' => $email,
                'theme' => $theme,
            ]);
            musky_user_mysql_sync_by_email($email, [
                'email' => $email,
                'role' => $role,
                'building' => $building,
                'allowed_tools' => $allowedTools,
                'theme' => $theme,
            ]);
        }

        $muskyUser = ($driver === 'mysql')
            ? musky_user_mysql_fetch_user_by_email($email)
            : musky_user_sqlite_fetch_by_email($email);

        return [
            'ok' => true,
            'email' => $email,
            'musky_user' => is_array($muskyUser) ? $muskyUser : [
                'email' => $email,
                'role' => $role,
                'building' => $building,
                'allowed_tools' => $allowedTools,
                'theme' => $theme,
            ],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not create the local admin account.'];
    }
}
