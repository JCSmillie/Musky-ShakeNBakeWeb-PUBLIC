<?php
// -----------------------------------------------------------------------------
// NORA API Gateway (Phase 2.6 - Stabilized + Clean Deprecation Tracking)
// -----------------------------------------------------------------------------
// GOALS:
//  ✅ Keep ALL existing functionality working (no breaking changes)
//  ✅ Restore internal/trusted IP bypass auth behavior
//  ✅ Add backwards-compatibility for legacy NoraAPI.php call styles
//  ✅ Track deprecated usage via:
//      - HTTP header only (NO JSON payload changes)
//      - log_api endpoint suffix: " [DEPRECATED]"
// -----------------------------------------------------------------------------

ini_set('display_errors', 0);
ini_set('html_errors', 0);
ini_set('log_errors', 1);
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/New_York');

const NORA_API_MAX_JSON_BODY_BYTES = 1048576;
const NORA_API_DEFAULT_DEVICE_BATCH_LIMIT = 50;
const NORA_API_DEFAULT_ERRAND_CREATE_WINDOW_SECONDS_TRUSTED = 60;
const NORA_API_DEFAULT_ERRAND_CREATE_MAX_PER_WINDOW_TRUSTED = 0;
const NORA_API_DEFAULT_ERRAND_CREATE_WINDOW_SECONDS_EXTERNAL = 60;
const NORA_API_DEFAULT_ERRAND_CREATE_MAX_PER_WINDOW_EXTERNAL = 10;

// ---------- Error Handlers ----------
function nora_api_log_server_error(string $kind, string $message, ?string $file = null, ?int $line = null): void {
    $location = ($file !== null && $line !== null) ? " in {$file}:{$line}" : '';
    error_log("[NoraAPI] {$kind}: {$message}{$location}");
}

function nora_api_log_auth_event(string $event, array $context = []): void {
    error_log("[NoraAPI][AUTH] {$event} " . json_encode($context, JSON_UNESCAPED_SLASHES));
}

function nora_api_server_error_response(): void {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'error' => 'internal',
        'message' => 'Server error'
    ], JSON_PRETTY_PRINT);
    exit;
}

function nora_api_client_error_response(int $status, string $error, string $message, array $extra = []): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode(array_merge([
        'error' => $error,
        'message' => $message,
    ], $extra), JSON_PRETTY_PRINT);
    exit;
}

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    nora_api_log_server_error('php_error', (string)$errstr, (string)$errfile, (int)$errline);
    nora_api_server_error_response();
});
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        nora_api_log_server_error(
            'fatal',
            (string)($e['message'] ?? ''),
            isset($e['file']) ? (string)$e['file'] : null,
            isset($e['line']) ? (int)$e['line'] : null
        );
        nora_api_server_error_response();
    }
});

// ---------- DB ----------
require_once __DIR__ . '/../bootstrap.php';

function nora_api_is_list_array(array $value): bool {
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

function nora_api_normalize_datetime_value($value): ?string {
    if ($value === null) {
        return null;
    }

    if (is_int($value) || is_float($value)) {
        $intValue = (int)$value;
        return $intValue > 0 ? date('Y-m-d H:i:s', $intValue) : null;
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    if (ctype_digit($raw)) {
        $intValue = (int)$raw;
        if (strlen($raw) >= 10 && $intValue > 0) {
            return date('Y-m-d H:i:s', $intValue);
        }
    }

    return $raw;
}

function nora_api_pick_newer_datetime_value($primary, $secondary): ?string {
    $primaryNorm = nora_api_normalize_datetime_value($primary);
    $secondaryNorm = nora_api_normalize_datetime_value($secondary);

    if ($primaryNorm === null) {
        return $secondaryNorm;
    }
    if ($secondaryNorm === null) {
        return $primaryNorm;
    }

    $primaryTs = strtotime($primaryNorm);
    $secondaryTs = strtotime($secondaryNorm);
    if ($primaryTs === false) {
        return $secondaryNorm;
    }
    if ($secondaryTs === false) {
        return $primaryNorm;
    }

    return $secondaryTs > $primaryTs ? $secondaryNorm : $primaryNorm;
}

function nora_api_merge_config(array $base, array $override): array {
    foreach ($override as $key => $value) {
        if (
            is_array($value)
            && isset($base[$key])
            && is_array($base[$key])
            && !nora_api_is_list_array($value)
            && !nora_api_is_list_array($base[$key])
        ) {
            $base[$key] = nora_api_merge_config($base[$key], $value);
            continue;
        }

        $base[$key] = $value;
    }

    return $base;
}

function nora_api_load_config(): array {
    $config = [];
    $path = dirname(__DIR__, 2) . '/nora_config.json';
    $loadedPaths = [];

    if (is_readable($path)) {
        $loaded = json_decode(file_get_contents($path), true);
        if (is_array($loaded)) {
            $config = nora_api_merge_config($config, $loaded);
            $loadedPaths[] = $path;
        }
    }

    if (!$config) {
        throw new RuntimeException('NORA config file could not be loaded.');
    }

    if (empty($config['host']) || empty($config['name']) || empty($config['user'])) {
        nora_api_log_server_error(
            'config_warning',
            'NORA config is missing one or more required DB keys. Loaded paths: ' . implode(', ', $loadedPaths)
        );
    }

    return $config;
}

$config = nora_api_load_config();
$dsn = sprintf(
    "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
    $config['host'], $config['port'] ?? 3306, $config['name']
);
$pdo = new PDO($dsn, $config['user'], $config['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// ---------- Auth ----------
function nora_api_list_config_value($value): array {
    if (is_string($value)) {
        $value = array_filter(array_map('trim', explode(',', $value)));
    }

    if (!is_array($value)) {
        return [];
    }

    $out = [];
    foreach ($value as $item) {
        if (!is_string($item)) {
            continue;
        }

        $item = trim($item);
        if ($item !== '') {
            $out[] = $item;
        }
    }

    return $out;
}

function nora_api_auth_config(array $config): array {
    $noraApi = $config['noraAPI'] ?? [];
    if (!is_array($noraApi)) {
        return [];
    }

    $auth = $noraApi['auth'] ?? $noraApi;
    return is_array($auth) ? $auth : [];
}

function nora_api_ip_in_cidr(string $ip, string $cidr): bool {
    if (!str_contains($cidr, '/')) {
        return hash_equals($cidr, $ip);
    }

    [$range, $prefix] = explode('/', $cidr, 2);
    $ipPacked = @inet_pton($ip);
    $rangePacked = @inet_pton($range);
    if ($ipPacked === false || $rangePacked === false || strlen($ipPacked) !== strlen($rangePacked)) {
        return false;
    }

    $prefixBits = filter_var($prefix, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => strlen($ipPacked) * 8]
    ]);
    if ($prefixBits === false) {
        return false;
    }

    $fullBytes = intdiv((int)$prefixBits, 8);
    $remainingBits = (int)$prefixBits % 8;

    if ($fullBytes > 0 && substr($ipPacked, 0, $fullBytes) !== substr($rangePacked, 0, $fullBytes)) {
        return false;
    }

    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($ipPacked[$fullBytes]) & $mask) === (ord($rangePacked[$fullBytes]) & $mask);
}

function nora_api_trusted_internal_match(array $config, string $ip): array {
    $authConfig = nora_api_auth_config($config);
    $trustedIps = nora_api_list_config_value($authConfig['trusted_internal_ips'] ?? []);
    $trustedCidrs = nora_api_list_config_value($authConfig['trusted_internal_cidrs'] ?? []);

    foreach ($trustedIps as $trustedIp) {
        if (hash_equals($trustedIp, $ip)) {
            return ['trusted' => true, 'match_type' => 'exact_ip', 'match' => $trustedIp];
        }
    }

    foreach ($trustedCidrs as $cidr) {
        if (nora_api_ip_in_cidr($ip, $cidr)) {
            return ['trusted' => true, 'match_type' => 'cidr', 'match' => $cidr];
        }
    }

    return ['trusted' => false, 'match_type' => null, 'match' => null];
}

function nora_api_db_fallback_auth_enabled(array $config): bool {
    $authConfig = nora_api_auth_config($config);
    return !empty($authConfig['allow_db_fallback_auth']);
}

function nora_api_int_config_value(array $config, array $path, int $default, int $min, int $max): int {
    $value = $config;
    foreach ($path as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return $default;
        }
        $value = $value[$key];
    }

    $intValue = filter_var($value, FILTER_VALIDATE_INT);
    if ($intValue === false) {
        return $default;
    }

    return max($min, min($max, (int)$intValue));
}

function nora_api_device_batch_limit(array $config): int {
    return nora_api_int_config_value(
        $config,
        ['noraAPI', 'limits', 'devices_max_serials'],
        NORA_API_DEFAULT_DEVICE_BATCH_LIMIT,
        1,
        250
    );
}

function nora_api_errand_create_limit_profile(array $auth): string {
    $authType = (string)($auth['auth_type'] ?? '');
    if (in_array($authType, ['trusted_internal_ip', 'db_fallback'], true)) {
        return 'trusted_internal';
    }

    return 'external';
}

function nora_api_errand_create_limits(array $config, array $auth): array {
    $profile = nora_api_errand_create_limit_profile($auth);
    $defaultWindow = $profile === 'trusted_internal'
        ? NORA_API_DEFAULT_ERRAND_CREATE_WINDOW_SECONDS_TRUSTED
        : NORA_API_DEFAULT_ERRAND_CREATE_WINDOW_SECONDS_EXTERNAL;
    $defaultMax = $profile === 'trusted_internal'
        ? NORA_API_DEFAULT_ERRAND_CREATE_MAX_PER_WINDOW_TRUSTED
        : NORA_API_DEFAULT_ERRAND_CREATE_MAX_PER_WINDOW_EXTERNAL;

    return [
        'profile' => $profile,
        'window_seconds' => nora_api_int_config_value(
            $config,
            ['noraAPI', 'limits', 'errand_create', $profile . '_window_seconds'],
            $defaultWindow,
            1,
            3600
        ),
        'max_per_window' => nora_api_int_config_value(
            $config,
            ['noraAPI', 'limits', 'errand_create', $profile . '_max_per_window'],
            $defaultMax,
            0,
            500
        ),
    ];
}

function nora_api_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    $cache[$key] = ((int)$stmt->fetchColumn()) > 0;
    return $cache[$key];
}

function nora_api_log_time_column(PDO $pdo): ?string {
    static $resolved = false;
    static $column = null;

    if ($resolved) {
        return $column;
    }

    foreach (['timestamp', 'created_at'] as $candidate) {
        if (nora_api_column_exists($pdo, 'nora_api_logs', $candidate)) {
            $column = $candidate;
            break;
        }
    }

    $resolved = true;
    return $column;
}

function nora_api_recent_errand_create_count(PDO $pdo, array $auth, int $windowSeconds): ?int {
    $timeColumn = nora_api_log_time_column($pdo);
    if ($timeColumn === null) {
        nora_api_log_server_error(
            'rate_limit_warning',
            'Errand create rate limiting skipped because nora_api_logs has no timestamp/created_at column.'
        );
        return null;
    }

    $where = [
        'endpoint IN (?, ?)',
        "{$timeColumn} >= ?",
        'status_code >= 200',
        'status_code < 300',
    ];
    $params = [
        '/errand/create',
        '/errand/create [DEPRECATED]',
        date('Y-m-d H:i:s', time() - $windowSeconds),
    ];

    if (!empty($auth['client_id'])) {
        $where[] = 'client_id = ?';
        $params[] = (int)$auth['client_id'];
    } else {
        $where[] = 'ip_address = ?';
        $params[] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    $sql = 'SELECT COUNT(*) FROM nora_api_logs WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function nora_api_enforce_errand_create_limit(PDO $pdo, array $config, array $auth): void {
    $limits = nora_api_errand_create_limits($config, $auth);
    if ((int)$limits['max_per_window'] <= 0) {
        return;
    }

    $recentCount = nora_api_recent_errand_create_count($pdo, $auth, $limits['window_seconds']);
    if ($recentCount === null || $recentCount < $limits['max_per_window']) {
        return;
    }

    $retryAfter = max(1, (int)$limits['window_seconds']);
    if (!headers_sent()) {
        header('Retry-After: ' . $retryAfter);
    }

    $rateLimitKey = !empty($auth['client_id'])
        ? 'client_id:' . (int)$auth['client_id']
        : 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    nora_api_log_auth_event('errand_create_rate_limited', array_merge(
        [
            'limit_profile' => $limits['profile'],
            'window_seconds' => $limits['window_seconds'],
            'max_per_window' => $limits['max_per_window'],
            'recent_success_count' => $recentCount,
            'rate_limit_key' => $rateLimitKey,
            'client_id' => $auth['client_id'] ?? null,
            'client_name' => $auth['client_name'] ?? null,
            'auth_type' => $auth['auth_type'] ?? null,
        ],
        nora_api_auth_log_context('/errand/create')
    ));

    nora_api_client_error_response(
        429,
        'rate_limited',
        'Errand creation rate limit exceeded',
        [
            'retry_after_seconds' => $retryAfter,
            'limit_profile' => $limits['profile'],
        ]
    );
}

function nora_api_errand_client_column(PDO $pdo): ?string {
    foreach (['APIClientID', 'ApiClientID', 'api_client_id', 'CreatedByAPIClientID', 'created_by_api_client_id'] as $column) {
        if (nora_api_column_exists($pdo, 'nora_errands', $column)) {
            return $column;
        }
    }

    return null;
}

function nora_api_parse_permissions($value): array {
    if ($value === null) {
        return [];
    }

    if (is_array($value)) {
        $items = $value;
    } else {
        $text = trim((string)$value);
        if ($text === '') {
            return [];
        }

        $decoded = json_decode($text, true);
        $items = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $text);
    }

    $out = [];
    foreach ($items as $item) {
        if (!is_scalar($item)) {
            continue;
        }

        $scope = strtolower(trim((string)$item));
        if ($scope !== '') {
            $out[$scope] = true;
        }
    }

    return array_keys($out);
}

function nora_api_scope_aliases(string $scope): array {
    $scope = strtolower($scope);
    $aliases = [
        'errand_create' => ['errand_create', 'create_errands', 'create_errand'],
        'errand_status' => ['errand_status', 'query_status', 'status_query'],
        'errand_status_all' => ['errand_status_all', 'query_status_all', 'status_query_all'],
        'errand_status_verbose' => ['errand_status_verbose', 'verbose_status', 'query_status_verbose'],
        'device_lookup' => ['device_lookup', 'device_read', 'devices'],
        'user_device_lookup' => ['user_device_lookup', 'user_lookup', 'user_devices'],
    ];

    return $aliases[$scope] ?? [$scope];
}

function nora_api_auth_permissions(array $auth): array {
    return nora_api_parse_permissions($auth['permissions'] ?? []);
}

function nora_api_has_scope(array $auth, string $scope): bool {
    $authType = (string)($auth['auth_type'] ?? '');
    if (in_array($authType, ['trusted_internal_ip', 'db_fallback'], true)) {
        return true;
    }

    $permissions = nora_api_auth_permissions($auth);
    if (in_array('*', $permissions, true) || in_array('all', $permissions, true)) {
        return true;
    }

    foreach (nora_api_scope_aliases($scope) as $alias) {
        if (in_array(strtolower($alias), $permissions, true)) {
            return true;
        }
    }

    return false;
}

function nora_api_require_scope(array $auth, string $scope, string $endpoint): void {
    if (nora_api_has_scope($auth, $scope)) {
        return;
    }

    nora_api_log_auth_event('scope_denied', array_merge(
        [
            'required_scope' => $scope,
            'endpoint' => $endpoint,
            'client_id' => $auth['client_id'] ?? null,
            'client_name' => $auth['client_name'] ?? null,
            'auth_type' => $auth['auth_type'] ?? null,
        ],
        nora_api_auth_log_context($endpoint)
    ));

    nora_api_client_error_response(403, 'insufficient_scope', 'API client is not allowed to use this route');
}

function nora_api_auth_log_context(string $path): array {
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $requestPath = (string)(parse_url($requestUri, PHP_URL_PATH) ?? '');
    $queryKeys = array_keys($_GET ?? []);
    $requestHeaders = function_exists('getallheaders') ? getallheaders() : [];
    $authHeaderPresent = !empty($_SERVER['HTTP_AUTHORIZATION'])
        || !empty($_SERVER['Authorization'])
        || !empty($requestHeaders['Authorization'])
        || !empty($requestHeaders['authorization']);

    return [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'path' => $path,
        'request_path' => $requestPath,
        'query_keys' => $queryKeys,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'authorization_header_present' => $authHeaderPresent,
        'api_token_query_present' => !empty($_GET['api_token']),
    ];
}

function nora_api_extract_bearer_token_from_request(): ?string {
    $authorization = '';

    if (function_exists('getallheaders')) {
        foreach ((getallheaders() ?: []) as $key => $value) {
            if (strtolower((string)$key) === 'authorization') {
                $authorization = trim((string)$value);
                break;
            }
        }
    }

    if ($authorization === '') {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'Authorization'] as $serverKey) {
            $serverValue = trim((string)($_SERVER[$serverKey] ?? ''));
            if ($serverValue !== '') {
                $authorization = $serverValue;
                break;
            }
        }
    }

    if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        $token = trim((string)($matches[1] ?? ''));
        return $token !== '' ? $token : null;
    }

    if (!empty($_GET['api_token'])) {
        $token = trim((string)$_GET['api_token']);
        return $token !== '' ? $token : null;
    }

    return null;
}

function auth_client(PDO $pdo): array {
    $token = nora_api_extract_bearer_token_from_request();

    if (!$token)
        return ['status'=>'fail','reason'=>'no token'];

    $permissionsSelect = nora_api_column_exists($pdo, 'nora_api_clients', 'permissions')
        ? 'permissions'
        : "'' AS permissions";
    $stmt = $pdo->prepare("SELECT id,client_name,{$permissionsSelect} FROM nora_api_clients WHERE token=? AND status='enabled'");
    $stmt->execute([$token]);
    $r = $stmt->fetch();

    if (!$r)
        return ['status'=>'fail','reason'=>'invalid token'];

    $pdo->prepare("UPDATE nora_api_clients SET usage_count=usage_count+1,last_used=NOW() WHERE id=?")
        ->execute([$r['id']]);

    return [
        'status'=>'ok',
        'auth_type'=>'token',
        'client_id'=>$r['id'],
        'client_name'=>$r['client_name'],
        'permissions'=>nora_api_parse_permissions($r['permissions'] ?? '')
    ];
}

function nora_api_content_type_is_json(string $contentType): bool {
    $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
    return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
}

function nora_api_request_content_type(): string {
    return (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
}

function nora_api_read_json_body(): array {
    $contentType = nora_api_request_content_type();
    if (!nora_api_content_type_is_json($contentType)) {
        nora_api_log_server_error(
            'bad_request',
            'Rejected JSON route request with missing or unsupported Content-Type: ' . ($contentType !== '' ? $contentType : '(missing)')
        );
        nora_api_client_error_response(
            415,
            'unsupported_media_type',
            'Content-Type must be application/json'
        );
    }

    $raw = file_get_contents('php://input');
    if ($raw === false) {
        nora_api_log_server_error('bad_request', 'Unable to read JSON request body.');
        nora_api_client_error_response(400, 'invalid_json', 'Unable to read request body');
    }

    if (strlen($raw) > NORA_API_MAX_JSON_BODY_BYTES) {
        nora_api_log_server_error('bad_request', 'Rejected oversized JSON request body.');
        nora_api_client_error_response(
            413,
            'request_too_large',
            'Request body is too large'
        );
    }

    if (trim($raw) === '') {
        return [];
    }

    try {
        $objectProbe = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        nora_api_log_server_error('bad_request', 'Rejected malformed JSON request body: ' . $e->getMessage());
        nora_api_client_error_response(400, 'invalid_json', 'Invalid JSON request body');
    }

    if (!is_object($objectProbe)) {
        nora_api_log_server_error('bad_request', 'Rejected JSON request body that was not an object.');
        nora_api_client_error_response(400, 'invalid_json_body', 'JSON request body must be an object');
    }

    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

function nora_api_log_scalar_or_type($value) {
    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
        return $value;
    }

    if (is_string($value)) {
        $value = trim($value);
        return strlen($value) <= 80 ? $value : '[present]';
    }

    return '[' . gettype($value) . ']';
}

function nora_api_log_present(array $payload, string $key): bool {
    return array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '';
}

function nora_api_log_sorted_keys(array $payload): array {
    $keys = array_map('strval', array_keys($payload));
    sort($keys);
    return $keys;
}

function nora_api_log_extra_category($value) {
    if ($value === null || $value === '') {
        return null;
    }

    if (is_array($value)) {
        return [
            'type' => 'array',
            'keys' => nora_api_log_sorted_keys($value),
        ];
    }

    if (!is_string($value)) {
        return '[' . gettype($value) . ']';
    }

    $trimmed = trim($value);
    $knownCategories = ['INV_LOOKUP', 'IIQTICKETSYNC', 'IIQUSERSYNC', 'RETIRED-DEVICE', 'SKYWARDFEE'];
    if (
        in_array($trimmed, $knownCategories, true)
        || (preg_match('/^[A-Z][A-Z0-9_:-]{0,79}$/', $trimmed) && preg_match('/[_:-]/', $trimmed))
    ) {
        return $trimmed;
    }

    try {
        $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) {
            if (nora_api_is_list_array($decoded)) {
                return [
                    'type' => 'json_list',
                    'count' => count($decoded),
                ];
            }

            return [
                'type' => 'json_object',
                'keys' => nora_api_log_sorted_keys($decoded),
            ];
        }
    } catch (JsonException $e) {
        // Not JSON; fall through to a presence-only summary.
    }

    return '[present]';
}

function nora_api_redact_log_value(string $key, $value, int $depth = 0) {
    $lowerKey = strtolower($key);
    if (preg_match('/token|authorization|password|pass|secret|serial|udid|email|owner|submitter|asset|name/', $lowerKey)) {
        return is_array($value) ? '[redacted array]' : '[redacted]';
    }

    if ($depth >= 3) {
        return '[truncated]';
    }

    if (is_array($value)) {
        $out = [];
        $count = 0;
        foreach ($value as $childKey => $childValue) {
            if ($count >= 20) {
                $out['__truncated__'] = count($value) - $count;
                break;
            }
            $out[$childKey] = nora_api_redact_log_value((string)$childKey, $childValue, $depth + 1);
            $count++;
        }
        return $out;
    }

    return nora_api_log_scalar_or_type($value);
}

function nora_api_log_payload_summary(string $endpoint, $payload): array {
    if (!is_array($payload)) {
        return ['payload_type' => gettype($payload)];
    }

    if ($endpoint === '/errand/create') {
        return [
            'summary_type' => 'errand_create_request',
            'fields' => nora_api_log_sorted_keys($payload),
            'has_required_fields' => [
                'serial' => nora_api_log_present($payload, 'serial'),
                'udid' => nora_api_log_present($payload, 'udid'),
                'submitter' => nora_api_log_present($payload, 'submitter'),
            ],
            'has_owner' => nora_api_log_present($payload, 'owner'),
            'priority' => nora_api_log_scalar_or_type($payload['priority'] ?? null),
            'flags' => [
                'mosbasic' => nora_api_log_scalar_or_type($payload['mosbasic'] ?? null),
                'slack' => nora_api_log_scalar_or_type($payload['slack'] ?? null),
                'iiq' => nora_api_log_scalar_or_type($payload['iiq'] ?? null),
                'nora' => nora_api_log_scalar_or_type($payload['nora'] ?? null),
                'custom_request' => nora_api_log_scalar_or_type($payload['custom_request'] ?? ($payload['custom'] ?? null)),
            ],
            'extra1_category' => nora_api_log_extra_category($payload['extra1'] ?? null),
            'extra_fields_present' => [
                'extra1' => nora_api_log_present($payload, 'extra1'),
                'extra2' => nora_api_log_present($payload, 'extra2'),
                'extra3' => nora_api_log_present($payload, 'extra3'),
                'extra4' => nora_api_log_present($payload, 'extra4'),
                'extra5' => nora_api_log_present($payload, 'extra5'),
                'extra6' => nora_api_log_present($payload, 'extra6'),
            ],
        ];
    }

    if ($endpoint === '/errand/status') {
        return [
            'summary_type' => 'errand_status_request',
            'errand_id' => isset($payload['id']) ? (int)$payload['id'] : null,
            'verbose' => !empty($payload['verbose']),
        ];
    }

    if ($endpoint === '/devices') {
        $serials = $payload['serial'] ?? [];
        return [
            'summary_type' => 'devices_request',
            'serial_count' => is_array($serials) ? count($serials) : 0,
        ];
    }

    if ($endpoint === '/device/resolve') {
        return [
            'summary_type' => 'device_resolve_request',
            'asset_tag_present' => nora_api_log_present($payload, 'asset_tag'),
        ];
    }

    if ($endpoint === '/user/resolve') {
        return [
            'summary_type' => 'user_resolve_request',
            'user_id_present' => nora_api_log_present($payload, 'id'),
        ];
    }

    if ($endpoint === '/user/devices') {
        return [
            'summary_type' => 'user_devices_request',
            'identifier_type' => nora_api_log_scalar_or_type($payload['identifier_type'] ?? null),
        ];
    }

    return [
        'summary_type' => 'generic_request',
        'payload' => nora_api_redact_log_value('', $payload),
    ];
}

function nora_api_log_response_summary(string $endpoint, $response): array {
    if (!is_array($response)) {
        return ['response_type' => gettype($response)];
    }

    if ($endpoint === '/errand/create') {
        return [
            'summary_type' => 'errand_create_response',
            'status' => nora_api_log_scalar_or_type($response['status'] ?? null),
            'errand_id' => isset($response['errand_id']) ? (int)$response['errand_id'] : null,
            'included_identity_fields' => [
                'serial' => array_key_exists('serial', $response),
                'udid' => array_key_exists('udid', $response),
                'submitter' => array_key_exists('submitter', $response),
            ],
        ];
    }

    if ($endpoint === '/errand/status') {
        return [
            'summary_type' => 'errand_status_response',
            'errand_id' => isset($response['ErrandID']) ? (int)$response['ErrandID'] : null,
            'status' => nora_api_log_scalar_or_type($response['Status'] ?? null),
            'task_priority' => nora_api_log_scalar_or_type($response['TaskPriority'] ?? null),
            'complete_time_present' => !empty($response['CompleteDateTime']),
            'response_field_count' => count($response),
            'contains_extra_fields' => !empty(array_intersect(
                ['ExtraDataField01','ExtraDataField02','ExtraDataField03','ExtraDataField04','ExtraDataField05','ExtraDataField06'],
                array_keys($response)
            )),
        ];
    }

    if ($endpoint === '/devices') {
        $devices = $response['devices'] ?? [];
        if (!is_array($devices)) {
            $devices = [];
        }

        $ownerDataPresent = false;
        $udidDataPresent = false;
        foreach ($devices as $device) {
            if (!is_array($device)) {
                continue;
            }
            $ownerDataPresent = $ownerDataPresent
                || !empty($device['owner_id'])
                || !empty($device['owner_mosyle_id'])
                || !empty($device['owner_email'])
                || !empty($device['owner_name']);
            $udidDataPresent = $udidDataPresent || !empty($device['deviceudid']);
        }

        return [
            'summary_type' => 'devices_response',
            'device_count' => count($devices),
            'owner_data_present' => $ownerDataPresent,
            'udid_data_present' => $udidDataPresent,
        ];
    }

    if ($endpoint === '/device/resolve') {
        $device = is_array($response['device'] ?? null) ? $response['device'] : [];
        return [
            'summary_type' => 'device_resolve_response',
            'device_found' => $device !== [],
            'has_serial' => !empty($device['serial_number']),
            'has_owner' => !empty($device['owner_id']) || !empty($device['owner_email']) || !empty($device['owner_name']),
            'has_udid' => !empty($device['deviceudid']),
        ];
    }

    if ($endpoint === '/user/resolve') {
        $user = is_array($response['user'] ?? null) ? $response['user'] : [];
        return [
            'summary_type' => 'user_resolve_response',
            'user_found' => $user !== [],
            'has_email' => !empty($user['email']),
            'has_mosyle_id' => !empty($user['mosyle_id']),
        ];
    }

    if ($endpoint === '/user/devices') {
        return [
            'summary_type' => 'user_devices_response',
            'exists' => !empty($response['exists']),
            'identifier_type' => nora_api_log_scalar_or_type($response['identifier_type'] ?? null),
            'active_count' => isset($response['active_count']) ? (int)$response['active_count'] : null,
            'former_count' => isset($response['former_count']) ? (int)$response['former_count'] : null,
        ];
    }

    return [
        'summary_type' => 'generic_response',
        'response' => nora_api_redact_log_value('', $response),
    ];
}

function nora_api_encode_log_value($value, int $maxBytes = 4096): string {
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        return '{"error":"log_json_encode_failed"}';
    }

    if (strlen($json) > $maxBytes) {
        return substr($json, 0, $maxBytes - 15) . '...[truncated]';
    }

    return $json;
}

function log_api(PDO $pdo,$client_id,$endpoint,$status,$payload,$response,$deprecated=false){
    try {
        $baseEndpoint = $endpoint;
        $safePayload = nora_api_log_payload_summary($baseEndpoint, $payload);
        $safeResponse = nora_api_log_response_summary($baseEndpoint, $response);

        if ($deprecated) {
            $endpoint .= ' [DEPRECATED]';
        }
        $stmt=$pdo->prepare("
          INSERT INTO nora_api_logs
            (client_id,endpoint,ip_address,method,status_code,request_payload,response_snippet)
          VALUES (?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $client_id,
            $endpoint,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            $status,
            nora_api_encode_log_value($safePayload),
            substr(nora_api_encode_log_value($safeResponse), 0, 255)
        ]);
    } catch (Throwable $e) {
        nora_api_log_server_error(
            'api_log_failed',
            'endpoint=' . $endpoint . ' status=' . (string)$status . ' message=' . $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
    }
}

// ---------- Routing ----------
$path = $_GET['path'] ?? trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
$path = preg_replace('#^.*/api/#', '/', $path);
$path = '/' . ltrim($path, '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ----------------------------------------------------
// Backwards Compatibility Layer (Legacy NoraAPI.php Calls)
// ----------------------------------------------------
// Supports legacy forms without changing JSON response bodies:
//   /api/NoraAPI.php/errand/create
//   /api/NoraAPI.php?cmd=RunTask   (routes to /errand/create)
// ----------------------------------------------------
$deprecatedCall = false;
$rawUri = $_SERVER['REQUEST_URI'] ?? '';

if (str_contains($rawUri, 'NoraAPI.php') || isset($_GET['cmd'])) {

    $deprecatedCall = true;

    // If legacy cmd used, map cmd -> modern path (DO NOT force method here)
    if (isset($_GET['cmd'])) {
        switch ($_GET['cmd']) {
            case 'RunTask':
                $path = '/errand/create';
                break;
            default:
                // Unknown legacy command -> allow normal 404 handling below
                break;
        }
    }

    // Normalize /NoraAPI.php/... -> /...
    $path = preg_replace('#^/NoraAPI\.php#', '', $path);
    $path = '/' . ltrim($path, '/');

    // Header-only deprecation indicator (NO JSON mutation)
    header('X-Nora-Deprecated: true');
}

// ---------- Trusted Check ----------
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$trustedInternalMatch = nora_api_trusted_internal_match($config, $ip);

// ---------- Auth Flow ----------
if ($trustedInternalMatch['trusted']) {
    $auth = [
        'status'=>'ok',
        'auth_type'=>'trusted_internal_ip',
        'client_id'=>null,
        'client_name'=>'Trusted Internal Service',
        'permissions'=>['*']
    ];
} else {
    $auth = auth_client($pdo);
}

// ---------- Explicit DB fallback for local/dev compatibility ----------
if ($auth['status'] !== 'ok' && nora_api_db_fallback_auth_enabled($config)) {
    try {
        $fallbackReason = (string)($auth['reason'] ?? 'unknown');
        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
            $config['host'], $config['port'] ?? 3306, $config['name']
        );
        $testPDO = new PDO($dsn, $config['user'], $config['pass']);
        $auth = ['status'=>'ok','auth_type'=>'db_fallback','permissions'=>['*']];
        nora_api_log_auth_event('db_fallback_auth_used', array_merge(
            ['reason' => $fallbackReason],
            nora_api_auth_log_context($path ?? '')
        ));
    } catch (Throwable $e) {}
}

if ($auth['status'] !== 'ok') {
    nora_api_log_auth_event('auth_failed', array_merge(
        ['reason' => (string)($auth['reason'] ?? 'unknown')],
        nora_api_auth_log_context($path ?? '')
    ));
    http_response_code(403);
    echo json_encode(['error'=>'auth_failed'],JSON_PRETTY_PRINT);
    exit;
}

// ============================================================================
// ROUTES
// ============================================================================
try {

    // ======================================================================
    // POST /errand/create
    // ======================================================================
    if ($path === '/errand/create' && $method === 'POST') {
        nora_api_require_scope($auth, 'errand_create', '/errand/create');
        nora_api_enforce_errand_create_limit($pdo, $config, $auth);

        $body = nora_api_read_json_body();

        $serial    = $body['serial']    ?? null;
        $udid      = $body['udid']      ?? null;
        $submitter = $body['submitter'] ?? null;

        if (!$serial || !$udid || !$submitter) {
            http_response_code(400);
            echo json_encode([
                'error'=>'missing_required_fields',
                'message'=>'Required: serial, udid, submitter'
            ], JSON_PRETTY_PRINT);
            exit;
        }

        // --- FIXED EXTRA2 HANDLING ---
        $extra2 = $body['extra2'] ?? null;
        if (is_array($extra2)) {
            $extra2 = json_encode($extra2, JSON_UNESCAPED_SLASHES);
        }

        // Build task array
        $task = [
            'TaskPriority'        => $body['priority'] ?? 0,
            'TaskRepeat'          => 'FALSE',
            'TaskRepeatHowMany'   => null,
            'Status'              => 'submitted',

            'MOSBasicRequest'     => $body['mosbasic'] ?? 'FALSE',
            'SlackRequest'        => strtoupper($body['slack'] ?? 'FALSE'),
            'IIQRequest'          => strtoupper($body['iiq'] ?? 'FALSE'),
            'NoraRequest'         => strtoupper($body['nora'] ?? 'FALSE'),
            'CustomRequest'       => $body['custom_request'] ?? $body['custom'] ?? 'FALSE',

            'UDID'                => $udid,
            'DeviceSerial'        => $serial,
            'AssetTag'            => null,
            'Submitter'           => $submitter,
            'DeviceOwner'         => $body['owner'] ?? null,

            'ExtraDataField01'    => $body['extra1'] ?? '{"note":"None"}',
            'ExtraDataField02'    => $extra2,
            'ExtraDataField03'    => $body['extra3'] ?? null,
            'ExtraDataField04'    => $body['extra4'] ?? null,
            'ExtraDataField05'    => $body['extra5'] ?? null,
            'ExtraDataField06'    => $body['extra6'] ?? null,
        ];

        $clientColumn = nora_api_errand_client_column($pdo);
        if ($clientColumn !== null && !empty($auth['client_id'])) {
            $task[$clientColumn] = (int)$auth['client_id'];
        }

        $insertColumns = array_keys($task);
        $sql = "INSERT INTO nora_errands
            (" . implode(',', $insertColumns) . ")
            VALUES
            (:" . implode(',:', $insertColumns) . ")";

        $pdo->prepare($sql)->execute($task);
        $id = $pdo->lastInsertId();

        $resp = [
            'status'=>'queued',
            'errand_id'=>$id,
            'serial'=>$serial,
            'udid'=>$udid,
            'submitter'=>$submitter
        ];

        log_api($pdo,$auth['client_id'] ?? null,'/errand/create',200,$body,$resp,$deprecatedCall);
        echo json_encode($resp,JSON_PRETTY_PRINT);
        exit;
    }

    // ======================================================================
    // GET /errand/status/:id
    // ======================================================================
    if (preg_match('#^/errand/status/(\d+)$#',$path,$m) && $method==='GET') {
        nora_api_require_scope($auth, 'errand_status', '/errand/status');

        $id=(int)$m[1];
        $verbose=!empty($_GET['verbose']);
        if ($verbose) {
            nora_api_require_scope($auth, 'errand_status_verbose', '/errand/status');
        }

        $st=$pdo->prepare("SELECT * FROM nora_errands WHERE ErrandID=?");
        $st->execute([$id]);
        $row=$st->fetch();

        if(!$row){
            http_response_code(404);
            echo json_encode(['error'=>'not_found'],JSON_PRETTY_PRINT);
            exit;
        }

        $clientColumn = nora_api_errand_client_column($pdo);
        if ($clientColumn !== null && !empty($auth['client_id']) && !nora_api_has_scope($auth, 'errand_status_all')) {
            $rowClientId = (int)($row[$clientColumn] ?? 0);
            if ($rowClientId > 0 && $rowClientId !== (int)$auth['client_id']) {
                nora_api_log_auth_event('errand_ownership_denied', [
                    'errand_id' => $id,
                    'client_id' => $auth['client_id'] ?? null,
                    'errand_client_id' => $rowClientId,
                ]);
                nora_api_client_error_response(403, 'forbidden', 'API client is not allowed to read this errand');
            }
        }

        $resp = $verbose ? $row : [
            'ErrandID'=>$row['ErrandID'],
            'TaskPriority'=>$row['TaskPriority'],
            'Status'=>$row['Status'],
            'Submitter'=>$row['Submitter'],
            'DeviceSerial'=>$row['DeviceSerial'],
            'SubmissionDateTime'=>$row['SubmissionDateTime'],
            'CompleteDateTime'=>$row['CompleteDateTime']
        ];

        log_api($pdo,$auth['client_id'] ?? null,'/errand/status',200,['id'=>$id,'verbose'=>$verbose ? 1 : 0],$resp,$deprecatedCall);
        echo json_encode($resp,JSON_PRETTY_PRINT);
        exit;
    }

    // ======================================================================
    // GET /device/resolve?asset_tag=...
    // ======================================================================
    if ($path === '/device/resolve' && $method === 'GET') {
        nora_api_require_scope($auth, 'device_lookup', '/device/resolve');

        $assetTag = trim((string)($_GET['asset_tag'] ?? $_GET['asset'] ?? ''));
        if ($assetTag === '') {
            http_response_code(400);
            echo json_encode([
                'error' => 'missing_asset_tag',
                'message' => 'Query parameter ?asset_tag= is required'
            ], JSON_PRETTY_PRINT);
            exit;
        }

        if (strlen($assetTag) > 64) {
            http_response_code(400);
            echo json_encode([
                'error' => 'invalid_asset_tag',
                'message' => 'Asset tag is too long'
            ], JSON_PRETTY_PRINT);
            exit;
        }

        if (!nora_api_column_exists($pdo, 'devices', 'asset_tag')) {
            http_response_code(501);
            echo json_encode([
                'error' => 'feature_unavailable',
                'message' => 'Device asset-tag lookup is not available in this database'
            ], JSON_PRETTY_PRINT);
            exit;
        }

        $deviceNoraStatus = nora_api_column_exists($pdo, 'devices', 'nora_status')
            ? 'd.nora_status'
            : "'ACTIVE'";
        $ownerStatus = nora_api_column_exists($pdo, 'owners', 'status')
            ? 'o.status'
            : "'ACTIVE'";

        $stmt = $pdo->prepare("
            SELECT
                d.id,
                d.serial_number,
                d.deviceudid,
                d.asset_tag,
                d.owner_id,
                d.status AS source_status,
                {$deviceNoraStatus} AS nora_status,
                o.mosyle_id AS owner_mosyle_id,
                o.email     AS owner_email,
                o.full_name AS owner_name,
                {$ownerStatus} AS owner_status,
                d.last_seen,
                d.date_enroll
            FROM devices d
            LEFT JOIN owners o ON d.owner_id = o.id
            WHERE UPPER(d.asset_tag) = UPPER(?)
            LIMIT 2
        ");
        $stmt->execute([$assetTag]);
        $rows = $stmt->fetchAll();

        if (!$rows) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found'], JSON_PRETTY_PRINT);
            exit;
        }

        if (count($rows) > 1) {
            http_response_code(409);
            echo json_encode([
                'error' => 'ambiguous_asset_tag',
                'message' => 'More than one device matched that asset tag'
            ], JSON_PRETTY_PRINT);
            exit;
        }

        $r = $rows[0];
        $resp = [
            'device' => [
                'id' => isset($r['id']) ? (int)$r['id'] : null,
                'serial_number' => $r['serial_number'],
                'deviceudid' => $r['deviceudid'],
                'asset_tag' => $r['asset_tag'],
                'owner_id' => $r['owner_id'],
                'source_status' => $r['source_status'],
                'nora_status' => $r['nora_status'],
                'owner_mosyle_id' => $r['owner_mosyle_id'],
                'owner_email' => $r['owner_email'],
                'owner_name' => $r['owner_name'],
                'owner_status' => $r['owner_status'],
                'last_seen' => $r['last_seen'],
                'date_enroll' => nora_api_normalize_datetime_value($r['date_enroll'] ?? null),
            ]
        ];

        log_api($pdo, $auth['client_id'] ?? null, '/device/resolve', 200, ['asset_tag' => $assetTag], $resp, $deprecatedCall);
        echo json_encode($resp, JSON_PRETTY_PRINT);
        exit;
    }

    // ======================================================================
    // GET /user/resolve?id=...
    // ======================================================================
    if ($path === '/user/resolve' && $method === 'GET') {
        nora_api_require_scope($auth, 'user_device_lookup', '/user/resolve');

        $userId = trim((string)($_GET['id'] ?? $_GET['user_id'] ?? ''));
        if ($userId === '') {
            http_response_code(400);
            echo json_encode([
                'error' => 'missing_user_id',
                'message' => 'Query parameter ?id= is required'
            ], JSON_PRETTY_PRINT);
            exit;
        }

        if (strlen($userId) > 128) {
            http_response_code(400);
            echo json_encode([
                'error' => 'invalid_user_id',
                'message' => 'User id is too long'
            ], JSON_PRETTY_PRINT);
            exit;
        }

        $selectColumns = ['o.id', 'o.email', 'o.full_name', 'o.user_type', 'o.grade'];
        $lookupColumns = [];
        foreach (['mosyle_id', 'user_id', 'username', 'district_id', 'student_id', 'employee_id'] as $column) {
            if (nora_api_column_exists($pdo, 'owners', $column)) {
                $selectColumns[] = 'o.' . $column;
                $lookupColumns[] = $column;
            }
        }

        $where = [];
        $params = [];
        if (ctype_digit($userId)) {
            $where[] = 'o.id = ?';
            $params[] = (int)$userId;
        }

        foreach ($lookupColumns as $column) {
            $where[] = 'LOWER(CAST(o.' . $column . ' AS CHAR)) = LOWER(?)';
            $params[] = $userId;
        }

        if (!$where) {
            http_response_code(501);
            echo json_encode([
                'error' => 'feature_unavailable',
                'message' => 'User-id lookup is not available in this database'
            ], JSON_PRETTY_PRINT);
            exit;
        }

        $sql = "
            SELECT " . implode(', ', $selectColumns) . "
            FROM owners o
            WHERE " . implode(' OR ', $where) . "
            LIMIT 2
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        if (!$rows) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found'], JSON_PRETTY_PRINT);
            exit;
        }

        if (count($rows) > 1) {
            http_response_code(409);
            echo json_encode([
                'error' => 'ambiguous_user_id',
                'message' => 'More than one user matched that id'
            ], JSON_PRETTY_PRINT);
            exit;
        }

        $user = $rows[0];
        if (isset($user['id'])) {
            $user['id'] = (int)$user['id'];
        }

        $resp = ['user' => $user];
        log_api($pdo, $auth['client_id'] ?? null, '/user/resolve', 200, ['id' => $userId], $resp, $deprecatedCall);
        echo json_encode($resp, JSON_PRETTY_PRINT);
        exit;
    }

    // ======================================================================
    // GET /user/{email}/devices   ← NEW ROUTE
    // ======================================================================
    if (preg_match('#^/user/.+#', $path) && $method === 'GET') {
        nora_api_require_scope($auth, 'user_device_lookup', '/user/devices');

        require_once __DIR__ . '/NoraAPI.User.php';

        $cleanRoute = ltrim($path, '/');

        // NOTE: No JSON mutation here (header already sent if deprecated)
        handleUserRoutes($cleanRoute, $method);
        exit;
    }

    // ======================================================================
    // GET /devices
    // ======================================================================
    if ($path === '/devices' && $method === 'GET') {
        nora_api_require_scope($auth, 'device_lookup', '/devices');

        $serialParam = $_GET['serial'] ?? null;

        if (!$serialParam) {
            http_response_code(400);
            echo json_encode([
                'error'   => 'missing_serial',
                'message' => 'Query parameter ?serial= is required'
            ], JSON_PRETTY_PRINT);
            exit;
        }

        $serials = array_values(array_filter(array_map('trim',
            preg_split('/\s*,\s*/', $serialParam)
        )));

        if (empty($serials)) {
            http_response_code(400);
            echo json_encode([
                'error'   => 'invalid_serial',
                'message' => 'No valid serial numbers provided'
            ], JSON_PRETTY_PRINT);
            exit;
        }

        $maxSerials = nora_api_device_batch_limit($config);
        if (count($serials) > $maxSerials) {
            http_response_code(400);
            echo json_encode([
                'error' => 'serial_batch_too_large',
                'message' => 'Too many serial numbers requested',
                'max_serials' => $maxSerials
            ], JSON_PRETTY_PRINT);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($serials), '?'));

        $deviceNoraStatus = nora_api_column_exists($pdo, 'devices', 'nora_status')
            ? 'd.nora_status'
            : "'ACTIVE'";
        $ownerStatus = nora_api_column_exists($pdo, 'owners', 'status')
            ? 'o.status'
            : "'ACTIVE'";

        $sql = "
            SELECT
                d.serial_number,
                d.asset_tag,
                d.deviceudid,
                d.owner_id,
                d.status AS source_status,
                {$deviceNoraStatus} AS nora_status,
                o.mosyle_id AS owner_mosyle_id,
                o.email     AS owner_email,
                o.full_name AS owner_name,
                {$ownerStatus} AS owner_status,
                d.last_seen,
                d.date_enroll
            FROM devices d
            LEFT JOIN owners o ON d.owner_id = o.id
            WHERE d.serial_number IN ($placeholders)
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($serials);
        $rows = $stmt->fetchAll();

        $historyBySerial = [];
        if ($rows) {
            $historySerials = [];
            foreach ($rows as $r) {
                $serial = trim((string)($r['serial_number'] ?? ''));
                if ($serial !== '') {
                    $historySerials[$serial] = $serial;
                }
            }

            if ($historySerials) {
                $historyPlaceholders = implode(',', array_fill(0, count($historySerials), '?'));
                $historySql = "
                    SELECT serial_number, owner_name, owner_email, owner_userid, last_seen
                      FROM device_history
                     WHERE serial_number IN ($historyPlaceholders)
                     ORDER BY COALESCE(snapshot_time, imported_at, import_time, last_seen) DESC, id DESC
                ";
                $historyStmt = $pdo->prepare($historySql);
                $historyStmt->execute(array_values($historySerials));
                foreach ($historyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $historyRow) {
                    $serial = trim((string)($historyRow['serial_number'] ?? ''));
                    if ($serial === '' || isset($historyBySerial[$serial])) {
                        continue;
                    }
                    $historyBySerial[$serial] = [
                        'owner_userid' => trim((string)($historyRow['owner_userid'] ?? '')),
                        'owner_email' => trim((string)($historyRow['owner_email'] ?? '')),
                        'owner_name' => trim((string)($historyRow['owner_name'] ?? '')),
                        'last_seen' => nora_api_normalize_datetime_value($historyRow['last_seen'] ?? null),
                    ];
                }
            }
        }

        $devices = [];
        foreach ($rows as $r) {
            $serial = trim((string)($r['serial_number'] ?? ''));
            $historyOwner = $historyBySerial[$serial] ?? [];
            $ownerMosyleId = trim((string)($r['owner_mosyle_id'] ?? ''));
            if ($ownerMosyleId === '') {
                $ownerMosyleId = trim((string)($historyOwner['owner_userid'] ?? ''));
            }
            $ownerEmail = trim((string)($r['owner_email'] ?? ''));
            if ($ownerEmail === '') {
                $ownerEmail = trim((string)($historyOwner['owner_email'] ?? ''));
            }
            $ownerName = trim((string)($r['owner_name'] ?? ''));
            if ($ownerName === '') {
                $ownerName = trim((string)($historyOwner['owner_name'] ?? ''));
            }
            $lastSeen = nora_api_pick_newer_datetime_value(
                $r['last_seen'] ?? null,
                $historyOwner['last_seen'] ?? null
            );

            $devices[] = [
                'serial_number'    => $serial,
                'asset_tag'        => trim((string)($r['asset_tag'] ?? '')),
                'deviceudid'       => $r['deviceudid'],
                'owner_id'         => $r['owner_id'],
                'source_status'    => $r['source_status'],
                'nora_status'      => $r['nora_status'],
                'owner_mosyle_id'  => $ownerMosyleId,
                'owner_email'      => $ownerEmail,
                'owner_name'       => $ownerName,
                'owner_status'     => $r['owner_status'],
                'last_seen'        => $lastSeen,
                'date_enroll'      => nora_api_normalize_datetime_value($r['date_enroll'] ?? null),
            ];
        }

        $resp = ['devices' => $devices];

        log_api(
            $pdo,
            $auth['client_id'] ?? null,
            '/devices',
            200,
            ['serial' => $serials],
            $resp,
            $deprecatedCall
        );

        echo json_encode($resp, JSON_PRETTY_PRINT);
        exit;
    }

    // Default route
    http_response_code(404);
    echo json_encode(['error'=>'not_found','path'=>$path],JSON_PRETTY_PRINT);
}
catch(Throwable $e){
    nora_api_log_server_error('exception', $e->getMessage(), $e->getFile(), $e->getLine());
    nora_api_server_error_response();
}
?>
