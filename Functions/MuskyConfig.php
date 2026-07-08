<?php
// ============================================================================
// MuskyConfig.php
// ----------------------------------------------------------------------------
// Shared config helpers for Musky's root JSON config as well as Nora's DB-
// backed config store. These helpers are intentionally non-fatal so pages can
// degrade gracefully when optional config layers are unavailable.
// ============================================================================

function musky_root_path(): string
{
    return dirname(__DIR__);
}

function musky_root_config_path(): ?string
{
    static $pathChecked = false;
    static $path = null;

    if ($pathChecked) {
        return $path;
    }

    $candidate = musky_root_path() . '/musky_config.json';
    if (is_file($candidate)) {
        $path = $candidate;
    }

    $pathChecked = true;
    return $path;
}

function musky_root_config(): array
{
    static $loaded = false;
    static $config = [];

    if ($loaded) {
        return $config;
    }

    $path = musky_root_config_path();
    if ($path && is_readable($path)) {
        $decoded = json_decode((string)@file_get_contents($path), true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }

    $loaded = true;
    return $config;
}

function musky_root_config_value($path, $default = null)
{
    $segments = is_array($path) ? $path : explode('.', trim((string)$path));
    $segments = array_values(array_filter(array_map(
        static fn($segment) => trim((string)$segment),
        $segments
    ), static fn($segment) => $segment !== ''));

    if (!$segments) {
        return $default;
    }

    $cursor = musky_root_config();
    foreach ($segments as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
            return $default;
        }
        $cursor = $cursor[$segment];
    }

    return $cursor;
}

function musky_root_config_string($path, string $default = ''): string
{
    $value = musky_root_config_value($path, $default);
    if (is_string($value)) {
        return $value;
    }
    if (is_scalar($value)) {
        return (string)$value;
    }
    return $default;
}

function musky_root_config_int($path, int $default = 0): int
{
    $value = musky_root_config_value($path, $default);
    if (is_int($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (int)$value;
    }
    return $default;
}

function musky_root_config_bool($path, bool $default = false): bool
{
    $value = musky_root_config_value($path, $default);
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return ((int)$value) !== 0;
    }
    if (is_string($value)) {
        $normalized = strtoupper(trim($value));
        if (in_array($normalized, ['1', 'TRUE', 'YES', 'ON', 'ENABLED'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'FALSE', 'NO', 'OFF', 'DISABLED'], true)) {
            return false;
        }
    }
    return $default;
}

function musky_root_config_array($path, array $default = []): array
{
    $value = musky_root_config_value($path, $default);
    return is_array($value) ? $value : $default;
}

function musky_dev_host_suffix(): string
{
    return musky_root_config_string('dev.host_suffix', '.local.host');
}

function musky_template_dir(): string
{
    return rtrim(
        musky_root_config_string('paths.nora_template_dir', '/usr/local/SmillieWare/Nora/Templates'),
        '/'
    );
}

function musky_mosbasic_drop_dir(): string
{
    return rtrim(
        musky_root_config_string('paths.mosbasic_drop_dir', '/usr/local/SmillieWare-CommonConfigz/MOSBasic-Drop'),
        '/'
    );
}

function musky_google_allowed_domains(): array
{
    $domains = musky_root_config_array('google_sso.allowed_domains', []);
    $domains = array_values(array_filter(array_map(
        static fn($value) => strtolower(trim((string)$value)),
        $domains
    ), static fn($value) => $value !== ''));

    return $domains;
}

function musky_normalize_email_domain(string $value): string
{
    $value = strtolower(trim($value));
    if ($value === '') {
        return '';
    }

    if (str_contains($value, '@')) {
        $value = (string)substr(strrchr($value, '@') ?: '', 1);
    }

    return ltrim($value, '@');
}

function musky_normalize_email_domains(array $values): array
{
    $domains = array_map(
        static fn($value) => musky_normalize_email_domain((string)$value),
        $values
    );
    $domains = array_values(array_filter($domains, static fn($value) => $value !== ''));
    return array_values(array_unique($domains));
}

function musky_identity_domain_list(string $key, array $default = []): array
{
    $configured = musky_root_config_array('identity.' . trim($key), $default);
    return musky_normalize_email_domains(is_array($configured) ? $configured : $default);
}

function musky_identity_domain_suffixes(string $key, array $default = []): array
{
    return array_map(
        static fn($domain) => '@' . $domain,
        musky_identity_domain_list($key, $default)
    );
}

function musky_identity_allowed_email_domains(): array
{
    $fallback = musky_google_allowed_domains();
    if (!$fallback) {
        $fallback = ['example.org'];
    }

    return musky_identity_domain_list('allowed_email_domains', $fallback);
}

function musky_identity_staff_domains(): array
{
    return musky_identity_domain_list('staff_domains', ['example.org']);
}

function musky_identity_student_domains(): array
{
    return musky_identity_domain_list('student_domains', ['example.net']);
}

function musky_identity_hdk_domains(): array
{
    return musky_identity_domain_list('hdk_domains', ['example.net']);
}

function musky_identity_restricted_individual_domains(): array
{
    $default = musky_identity_hdk_domains();
    if (!$default) {
        $default = ['example.net'];
    }

    return musky_identity_domain_list('restricted_individual_domains', $default);
}

function musky_identity_teacher_email_domain(): string
{
    $configured = musky_normalize_email_domain(
        musky_root_config_string('identity.teacher_email_domain', '')
    );
    if ($configured !== '') {
        return $configured;
    }

    $staffDomains = musky_identity_staff_domains();
    if ($staffDomains) {
        return $staffDomains[0];
    }

    $allowedDomains = musky_identity_allowed_email_domains();
    return $allowedDomains[0] ?? 'example.org';
}

function musky_identity_username_lookup_domains(): array
{
    $default = array_values(array_unique(array_merge(
        musky_identity_student_domains(),
        musky_identity_staff_domains()
    )));

    if (!$default) {
        $default = ['example.org'];
    }

    return musky_identity_domain_list('username_lookup_domains', $default);
}

function musky_identity_username_lookup_emails(string $username): array
{
    $username = strtolower(trim($username));
    if ($username === '') {
        return [];
    }

    if (str_contains($username, '@')) {
        return [$username];
    }

    return array_map(
        static fn($domain) => $username . '@' . $domain,
        musky_identity_username_lookup_domains()
    );
}

function musky_email_matches_domains(string $email, array $domains): bool
{
    $emailDomain = musky_normalize_email_domain($email);
    if ($emailDomain === '') {
        return false;
    }

    return in_array($emailDomain, musky_normalize_email_domains($domains), true);
}

function musky_email_is_staff(string $email): bool
{
    return musky_email_matches_domains($email, musky_identity_staff_domains());
}

function musky_email_is_student(string $email): bool
{
    return musky_email_matches_domains($email, musky_identity_student_domains());
}

function musky_email_is_hdk(string $email): bool
{
    return musky_email_matches_domains($email, musky_identity_hdk_domains());
}

function musky_mosbasic_binary_path(): string
{
    return musky_root_config_string('paths.mosbasic_binary', '/Users/Shared/MOSBasic/mosbasic');
}

function musky_mosyle_api_base_url(): string
{
    return rtrim(
        musky_root_config_string('mosyle.api_base_url', 'https://managerapi.mosyle.com/v2'),
        '/'
    );
}

function musky_mosyle_credentials_file(): string
{
    return musky_root_config_string('mosyle.credentials_file', '');
}

function musky_nora_config_path(): ?string
{
    static $pathChecked = false;
    static $path = null;

    if ($pathChecked) {
        return $path;
    }

    $candidate = musky_root_path() . '/nora_config.json';
    if (is_file($candidate)) {
        $path = $candidate;
    }

    $pathChecked = true;
    return $path;
}

function musky_config_pdo(): ?PDO
{
    static $pdoResolved = false;
    static $pdo = null;

    if ($pdoResolved) {
        return $pdo;
    }

    if (isset($GLOBALS['NORA_PDO']) && $GLOBALS['NORA_PDO'] instanceof PDO) {
        $pdo = $GLOBALS['NORA_PDO'];
        $pdoResolved = true;
        return $pdo;
    }

    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
        $pdoResolved = true;
        return $pdo;
    }

    $configPath = musky_nora_config_path();
    if (!$configPath || !is_readable($configPath)) {
        $pdoResolved = true;
        return null;
    }

    $cfg = json_decode((string)file_get_contents($configPath), true);
    if (!is_array($cfg)) {
        $pdoResolved = true;
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
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $GLOBALS['pdo'] = $pdo;
        $GLOBALS['NORA_PDO'] = $pdo;
    } catch (Throwable $e) {
        $pdo = null;
    }

    $pdoResolved = true;
    return $pdo;
}

function musky_active_config_value(string $key, ?string $group = null, ?PDO $pdo = null): ?string
{
    static $cache = [];

    $normalizedKey = strtoupper(trim($key));
    $normalizedGroup = $group !== null ? strtoupper(trim($group)) : null;
    $cacheKey = ($normalizedGroup ?? '*') . '::' . $normalizedKey;

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $pdo = $pdo instanceof PDO ? $pdo : musky_config_pdo();
    if (!$pdo instanceof PDO) {
        $cache[$cacheKey] = null;
        return null;
    }

    try {
        if ($normalizedGroup !== null && $normalizedGroup !== '') {
            $stmt = $pdo->prepare("
                SELECT ConfigValue
                  FROM nora_config_store
                 WHERE UPPER(ConfigGroup) = ?
                   AND UPPER(ConfigKey) = ?
                   AND IsActive = 1
                 ORDER BY UpdatedAt DESC, ConfigID DESC
                 LIMIT 1
            ");
            $stmt->execute([$normalizedGroup, $normalizedKey]);
        } else {
            $stmt = $pdo->prepare("
                SELECT ConfigValue
                  FROM nora_config_store
                 WHERE UPPER(ConfigKey) = ?
                   AND IsActive = 1
                 ORDER BY UpdatedAt DESC, ConfigID DESC
                 LIMIT 1
            ");
            $stmt->execute([$normalizedKey]);
        }

        $value = $stmt->fetchColumn();
        $cache[$cacheKey] = ($value !== false && $value !== null) ? trim((string)$value) : null;
    } catch (Throwable $e) {
        $cache[$cacheKey] = null;
    }

    return $cache[$cacheKey];
}

function musky_config_truthy(?string $value): bool
{
    if ($value === null) {
        return false;
    }

    return in_array(strtoupper(trim($value)), ['1', 'TRUE', 'YES', 'ON', 'ENABLED'], true);
}
