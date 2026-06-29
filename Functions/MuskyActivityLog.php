<?php
// ============================================================================
// MuskyActivityLog.php
// ----------------------------------------------------------------------------
// Central activity/audit logging for Musky web activity.
// New installs should prefer Nora/MariaDB. SQLite remains a legacy fallback.
// ============================================================================

require_once __DIR__ . '/MuskyConfig.php';
require_once __DIR__ . '/MuskyUserMariaSync.php';

function musky_activity_db_path(): ?string
{
    global $SQLITE_PATH;

    $configuredSqlitePath = musky_root_config_string('session.sqlite_path', '');
    if ($configuredSqlitePath !== '') {
        $SQLITE_PATH = $configuredSqlitePath;
    }

    if (!empty($SQLITE_PATH)) {
        $dir = dirname($SQLITE_PATH);
        if (is_dir($dir) && is_writable($dir)) {
            return $SQLITE_PATH;
        }
        if (is_file($SQLITE_PATH) && is_writable($SQLITE_PATH)) {
            return $SQLITE_PATH;
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['TESTMODE_SQLITE_PATH'])) {
        return $_SESSION['TESTMODE_SQLITE_PATH'];
    }

    $legacy = dirname(__DIR__) . '/Data/musky_users.db';
    if (is_file($legacy) && is_writable($legacy)) {
        return $legacy;
    }
    if (!is_file($legacy) && is_dir(dirname($legacy)) && is_writable(dirname($legacy))) {
        return $legacy;
    }

    return null;
}

function musky_activity_db(): ?PDO
{
    static $db = null;
    static $failed = false;

    if ($db instanceof PDO) {
        return $db;
    }
    if ($failed) {
        return null;
    }

    $mysql = musky_user_mysql_sync_pdo();
    if ($mysql instanceof PDO) {
        try {
            musky_activity_ensure_schema($mysql);
            $db = $mysql;
            return $db;
        } catch (Throwable $e) {
            // Fall through to the historical SQLite path if MariaDB setup fails.
        }
    }

    $path = musky_activity_db_path();
    if (!$path) {
        $failed = true;
        return null;
    }

    try {
        $db = new PDO("sqlite:$path");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout = 3000');
        musky_activity_ensure_schema($db);
        return $db;
    } catch (Throwable $e) {
        $failed = true;
        return null;
    }
}

function musky_activity_ensure_schema(PDO $db): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $driver = 'sqlite';
    try {
        $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (Throwable $e) {
        $driver = 'sqlite';
    }

    if ($driver === 'mysql') {
        $db->exec("
            CREATE TABLE IF NOT EXISTS musky_activity_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                event_time DATETIME NOT NULL,
                event_type VARCHAR(32) NOT NULL,
                user_email VARCHAR(255) NULL,
                user_name VARCHAR(255) NULL,
                page_path VARCHAR(255) NULL,
                action_name VARCHAR(255) NULL,
                target_serials TEXT NULL,
                target_asset_tags TEXT NULL,
                request_method VARCHAR(16) NULL,
                ip_address VARCHAR(64) NULL,
                user_agent TEXT NULL,
                session_id VARCHAR(255) NULL,
                extra_json LONGTEXT NULL,
                KEY idx_musky_activity_time (event_time),
                KEY idx_musky_activity_user (user_email, event_time),
                KEY idx_musky_activity_type (event_type, event_time),
                KEY idx_musky_activity_page (page_path, event_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        $db->exec("
            CREATE TABLE IF NOT EXISTS musky_activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_time TEXT NOT NULL,
                event_type TEXT NOT NULL,
                user_email TEXT,
                user_name TEXT,
                page_path TEXT,
                action_name TEXT,
                target_serials TEXT,
                target_asset_tags TEXT,
                request_method TEXT,
                ip_address TEXT,
                user_agent TEXT,
                session_id TEXT,
                extra_json TEXT
            )
        ");

        $db->exec("CREATE INDEX IF NOT EXISTS idx_musky_activity_time ON musky_activity_log(event_time DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_musky_activity_user ON musky_activity_log(user_email, event_time DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_musky_activity_type ON musky_activity_log(event_type, event_time DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_musky_activity_page ON musky_activity_log(page_path, event_time DESC)");
    }

    $ready = true;
}

function musky_activity_csv($value): string
{
    if (is_array($value)) {
        $value = array_values(array_filter(array_map(static function ($v) {
            return trim((string)$v);
        }, $value), static fn($v) => $v !== ''));
        return implode(',', $value);
    }

    return trim((string)$value);
}

function musky_activity_current_user_email(): string
{
    return trim((string)(
        $_SESSION['musky_user']['email'] ??
        $_SESSION['username'] ??
        $_SERVER['REMOTE_USER'] ??
        ''
    ));
}

function musky_activity_current_user_name(): string
{
    $musky = $_SESSION['musky_user'] ?? [];
    $name = trim((string)(
        $musky['full_name'] ??
        $musky['name'] ??
        trim(($musky['first_name'] ?? '') . ' ' . ($musky['last_name'] ?? '')) ??
        $_SESSION['username'] ??
        ''
    ));

    return $name;
}

function musky_activity_log(array $data): bool
{
    $db = musky_activity_db();
    if (!$db) {
        return false;
    }

    $extra = $data['extra_json'] ?? ($data['extra'] ?? null);
    if (is_array($extra) || is_object($extra)) {
        $extra = json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } elseif ($extra !== null) {
        $extra = (string)$extra;
    }

    $eventType = strtoupper(trim((string)($data['event_type'] ?? 'ACTION')));
    $pagePath = (string)($data['page_path'] ?? ($_SERVER['REQUEST_URI'] ?? ''));
    $pagePath = strtok($pagePath, '?') ?: $pagePath;

    try {
        $stmt = $db->prepare("
            INSERT INTO musky_activity_log (
                event_time,
                event_type,
                user_email,
                user_name,
                page_path,
                action_name,
                target_serials,
                target_asset_tags,
                request_method,
                ip_address,
                user_agent,
                session_id,
                extra_json
            ) VALUES (
                :event_time,
                :event_type,
                :user_email,
                :user_name,
                :page_path,
                :action_name,
                :target_serials,
                :target_asset_tags,
                :request_method,
                :ip_address,
                :user_agent,
                :session_id,
                :extra_json
            )
        ");

        return $stmt->execute([
            ':event_time'       => $data['event_time'] ?? date('Y-m-d H:i:s'),
            ':event_type'       => $eventType,
            ':user_email'       => trim((string)($data['user_email'] ?? musky_activity_current_user_email())),
            ':user_name'        => trim((string)($data['user_name'] ?? musky_activity_current_user_name())),
            ':page_path'        => $pagePath,
            ':action_name'      => trim((string)($data['action_name'] ?? '')),
            ':target_serials'   => musky_activity_csv($data['target_serials'] ?? ''),
            ':target_asset_tags'=> musky_activity_csv($data['target_asset_tags'] ?? ''),
            ':request_method'   => trim((string)($data['request_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? ''))),
            ':ip_address'       => trim((string)($data['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''))),
            ':user_agent'       => trim((string)($data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''))),
            ':session_id'       => trim((string)($data['session_id'] ?? session_id())),
            ':extra_json'       => $extra,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function musky_activity_should_log_page_view(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return false;
    }

    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
    if ($path === '') {
        return false;
    }

    if (str_starts_with($path, '/api/')) {
        return false;
    }

    if (!empty($_GET['api'])) {
        return false;
    }

    $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    if ($xhr === 'xmlhttprequest') {
        return false;
    }

    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (str_contains($accept, 'application/json')) {
        return false;
    }

    $dest = strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
    if ($dest && !in_array($dest, ['document', 'iframe', 'empty'], true)) {
        return false;
    }

    return true;
}

function musky_activity_log_page_view(array $extra = []): bool
{
    if (!musky_activity_should_log_page_view()) {
        return false;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
    if ($path === '') {
        return false;
    }

    $now = time();
    $lastMap = $_SESSION['_musky_pageview_log'] ?? [];
    $lastAt = (int)($lastMap[$path] ?? 0);
    if ($lastAt > 0 && ($now - $lastAt) < 15) {
        return false;
    }

    $lastMap[$path] = $now;
    $_SESSION['_musky_pageview_log'] = $lastMap;

    return musky_activity_log([
        'event_type' => 'PAGE_VIEW',
        'page_path'  => $path,
        'extra'      => $extra,
    ]);
}

function musky_activity_log_login(string $provider, array $extra = []): bool
{
    $extra['provider'] = $provider;
    return musky_activity_log([
        'event_type' => 'LOGIN',
        'action_name'=> strtoupper($provider) . '_LOGIN',
        'page_path'  => '/auth/login.php',
        'extra'      => $extra,
    ]);
}

function musky_activity_log_logout(array $extra = []): bool
{
    return musky_activity_log([
        'event_type' => 'LOGOUT',
        'action_name'=> 'LOGOUT',
        'page_path'  => '/logout.php',
        'extra'      => $extra,
    ]);
}

function musky_activity_get_last_login(string $email): ?string
{
    $email = trim(strtolower($email));
    if ($email === '') {
        return null;
    }

    $db = musky_activity_db();
    if (!$db) {
        return null;
    }

    try {
        $stmt = $db->prepare("
            SELECT event_time
              FROM musky_activity_log
             WHERE user_email = :email
               AND event_type = 'LOGIN'
             ORDER BY event_time DESC, id DESC
             LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $value = $stmt->fetchColumn();
        return $value ? (string)$value : null;
    } catch (Throwable $e) {
        return null;
    }
}

function musky_activity_get_user_auth_events(string $email, int $limit = 250): array
{
    $email = trim(strtolower($email));
    if ($email === '') {
        return [];
    }

    $db = musky_activity_db();
    if (!$db) {
        return [];
    }

    $limit = max(10, min(1000, $limit));

    try {
        $stmt = $db->prepare("
            SELECT
                id,
                event_time,
                event_type,
                action_name,
                page_path,
                request_method,
                ip_address,
                user_agent,
                session_id,
                extra_json
            FROM musky_activity_log
            WHERE user_email = :email
              AND event_type IN ('LOGIN', 'LOGOUT')
            ORDER BY event_time DESC, id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([':email' => $email]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function musky_activity_get_user_tracked_active_seconds(string $email, int $idleCapSeconds = 300): int
{
    $email = trim(strtolower($email));
    if ($email === '') {
        return 0;
    }

    $db = musky_activity_db();
    if (!$db) {
        return 0;
    }

    $idleCapSeconds = max(30, $idleCapSeconds);

    try {
        $stmt = $db->prepare("
            SELECT event_time
              FROM musky_activity_log
             WHERE user_email = :email
               AND event_type IN ('LOGIN','PAGE_VIEW','ACTION','LOGOUT')
             ORDER BY event_time ASC, id ASC
        ");
        $stmt->execute([':email' => $email]);
        $times = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $total = 0;
        $prevTs = null;

        foreach ($times as $raw) {
            $ts = strtotime((string)$raw);
            if ($ts === false) {
                continue;
            }

            if ($prevTs !== null) {
                $gap = $ts - $prevTs;
                if ($gap > 0) {
                    $total += min($gap, $idleCapSeconds);
                }
            }

            $prevTs = $ts;
        }

        return max(0, (int)$total);
    } catch (Throwable $e) {
        return 0;
    }
}
