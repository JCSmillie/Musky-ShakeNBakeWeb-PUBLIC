<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../../Functions/nora_connect.php';
date_default_timezone_set('America/New_York');

const MUSKY_APP_TOKEN_TTL_SECONDS = 2592000;

function musky_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = nora_connect();
    musky_app_ensure_schema($pdo);
    return $pdo;
}

function musky_now(): string
{
    return date('Y-m-d H:i:s');
}

function musky_app_ensure_schema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS musky_app_tokens (
            token CHAR(64) NOT NULL PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            display_name VARCHAR(255) DEFAULT NULL,
            user_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            last_used_at DATETIME DEFAULT NULL,
            revoked_at DATETIME DEFAULT NULL,
            KEY idx_musky_app_tokens_email (email),
            KEY idx_musky_app_tokens_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $done = true;
}

function musky_app_normalize_allowed_tools($allowed): array
{
    if (is_array($allowed)) {
        return array_values(array_filter(array_map('trim', $allowed), static fn($v) => $v !== ''));
    }

    if (is_string($allowed) && trim($allowed) !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $allowed)), static fn($v) => $v !== ''));
    }

    return [];
}

function musky_app_normalize_user(array $user): array
{
    $email = strtolower(trim((string)($user['email'] ?? '')));

    return [
        'email' => $email,
        'display_name' => trim((string)($user['display_name'] ?? $user['full_name'] ?? $user['name'] ?? $email)),
        'role' => trim((string)($user['role'] ?? '')),
        'building' => trim((string)($user['building'] ?? '')),
        'allowed_tools' => musky_app_normalize_allowed_tools($user['allowed_tools'] ?? []),
        'photo_url' => $user['photo_url'] ?? null,
        'full_name' => trim((string)($user['full_name'] ?? '')),
        'first_name' => trim((string)($user['first_name'] ?? '')),
        'last_name' => trim((string)($user['last_name'] ?? '')),
    ];
}

function musky_app_session_user(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['musky_user']) || !is_array($_SESSION['musky_user'])) {
        return null;
    }

    $user = musky_app_normalize_user($_SESSION['musky_user']);
    return $user['email'] !== '' ? $user : null;
}

function musky_app_cleanup(PDO $pdo): void
{
    $pdo->prepare("
        DELETE FROM musky_app_tokens
        WHERE revoked_at IS NOT NULL
           OR expires_at < NOW()
    ")->execute();
}

function musky_app_auth_from_token(?string $token): ?array
{
    $token = trim((string)$token);
    if ($token === '') {
        return null;
    }

    $pdo = musky_db();
    musky_app_cleanup($pdo);

    $stmt = $pdo->prepare("
        SELECT token, email, display_name, user_json, expires_at, revoked_at
        FROM musky_app_tokens
        WHERE token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !empty($row['revoked_at'])) {
        return null;
    }

    $expiresAt = strtotime((string)($row['expires_at'] ?? ''));
    if (!$expiresAt || $expiresAt < time()) {
        return null;
    }

    $user = json_decode((string)($row['user_json'] ?? ''), true);
    if (!is_array($user)) {
        $user = [
            'email' => $row['email'] ?? '',
            'display_name' => $row['display_name'] ?? '',
            'allowed_tools' => [],
        ];
    }
    $user = musky_app_normalize_user($user);

    $pdo->prepare("UPDATE musky_app_tokens SET last_used_at = NOW() WHERE token = ?")->execute([$token]);

    return $user['email'] !== '' ? $user : null;
}
