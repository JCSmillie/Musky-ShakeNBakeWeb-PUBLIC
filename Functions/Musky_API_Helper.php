<?php
// ============================================================================
// MUSKY - Musky_API_Helper.php
// ----------------------------------------------------------------------------
// Shared helper functions for calling NORA's API from inside Musky.
// All calls route through a configurable NoraAPI base URL.
// ============================================================================

require_once __DIR__ . '/MuskyConfig.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Normalize a configured NoraAPI base URL into the helper's expected shape.
 */
function musky_nora_normalize_base_url(?string $baseUrl): string
{
    $baseUrl = trim((string)$baseUrl);
    if ($baseUrl === '') {
        return '';
    }

    if (str_ends_with($baseUrl, '?path=')) {
        return $baseUrl;
    }

    if (str_ends_with($baseUrl, '?path')) {
        return $baseUrl . '=';
    }

    $trimmed = rtrim($baseUrl, '/');
    if (str_ends_with($trimmed, '/api/NoraAPI.php')) {
        return $trimmed . '?path=';
    }

    return $trimmed . '/api/NoraAPI.php?path=';
}

/**
 * Base URL for NoraAPI calls made by Musky itself.
 */
function musky_nora_base_url(): string
{
    foreach ([
        musky_root_config_string('nora_api.base_url', ''),
        getenv('MUSKY_NORA_API_BASE_URL') ?: '',
        getenv('NORA_API_BASE_URL') ?: '',
    ] as $candidate) {
        $normalized = musky_nora_normalize_base_url($candidate);
        if ($normalized !== '') {
            return $normalized;
        }
    }

    $scheme = 'http';
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['REQUEST_SCHEME']) && strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)) {
        $scheme = 'https';
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
    if ($host === '') {
        $host = 'localhost';
    }

    return $scheme . '://' . $host . '/api/NoraAPI.php?path=';
}

function musky_nora_read_token_file(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '' || !is_readable($path) || !is_file($path)) {
        return '';
    }

    return trim((string)file_get_contents($path));
}

function musky_nora_store_last_response_meta(array $meta): void
{
    $GLOBALS['musky_nora_api_last_response'] = $meta;
}

function musky_nora_last_response_meta(): array
{
    $meta = $GLOBALS['musky_nora_api_last_response'] ?? null;
    return is_array($meta) ? $meta : [];
}

function musky_nora_config(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $config = [];
    $path = dirname(__DIR__) . '/nora_config.json';
    if (!is_readable($path)) {
        return $config;
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (is_array($decoded)) {
        $config = $decoded;
    }

    return $config;
}

/**
 * Resolve a NoraAPI bearer token for helper-based internal calls.
 *
 * Priority:
 *  1. Session token, when an authenticated user has one.
 *  2. Environment token, for service-style deployments.
 *  3. Environment token-file path.
 *  4. Root nora_config.json noraAPI.service_client token-file path.
 *  5. Legacy noraAPI token-file path.
 */
function musky_nora_resolve_bearer_token(): array
{
    $sessionToken = trim((string)($_SESSION['musky_user']['nora_token'] ?? ''));
    if ($sessionToken !== '') {
        return ['token' => $sessionToken, 'source' => 'session'];
    }

    foreach (['MUSKY_NORA_API_TOKEN', 'NORA_API_TOKEN'] as $envKey) {
        $token = trim((string)getenv($envKey));
        if ($token !== '') {
            return ['token' => $token, 'source' => 'env:' . $envKey];
        }
    }

    foreach (['MUSKY_NORA_API_TOKEN_FILE', 'NORA_API_TOKEN_FILE'] as $envKey) {
        $token = musky_nora_read_token_file(getenv($envKey) ?: '');
        if ($token !== '') {
            return ['token' => $token, 'source' => 'env_file:' . $envKey];
        }
    }

    $config = musky_nora_config();
    $noraApi = $config['noraAPI'] ?? [];
    if (is_array($noraApi)) {
        $serviceClient = $noraApi['service_client'] ?? [];
        if (is_array($serviceClient)) {
            foreach (['token_file', 'token_path', 'tokenFile', 'tokenPath'] as $key) {
                $token = musky_nora_read_token_file($serviceClient[$key] ?? '');
                if ($token !== '') {
                    return ['token' => $token, 'source' => 'config:noraAPI.service_client.' . $key];
                }
            }
        }

        foreach (['token_file', 'token_path', 'tokenFile', 'tokenPath', 'token'] as $key) {
            $token = musky_nora_read_token_file($noraApi[$key] ?? '');
            if ($token !== '') {
                return ['token' => $token, 'source' => 'config:noraAPI.' . $key];
            }
        }
    }

    return ['token' => '', 'source' => 'missing'];
}

/**
 * Fetch bearer token for backwards-compatible callers.
 */
function musky_nora_get_bearer_token(): string {
    $resolved = musky_nora_resolve_bearer_token();
    return (string)$resolved['token'];
}

/**
 * Extract a Nora errand id from mixed legacy/modern response shapes.
 */
function musky_nora_extract_errand_id($response): ?int
{
    if (!is_array($response)) {
        return null;
    }

    foreach (['errand_id', 'ErrandID', 'errandId', 'id'] as $key) {
        if (!array_key_exists($key, $response)) {
            continue;
        }

        $raw = $response[$key];
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }

        if (is_string($raw) && ctype_digit($raw)) {
            $id = (int)$raw;
            if ($id > 0) {
                return $id;
            }
        }
    }

    return null;
}

/**
 * Extract a normalized errand status from mixed response shapes.
 */
function musky_nora_extract_status($response): string
{
    if (!is_array($response)) {
        return '';
    }

    foreach (['status', 'Status'] as $key) {
        if (!array_key_exists($key, $response)) {
            continue;
        }

        return strtolower(trim((string)$response[$key]));
    }

    return '';
}

function musky_nora_debug_payload_summary(array $payload): string
{
    $keys = array_keys($payload);
    sort($keys);

    $extra2 = $payload['extra2'] ?? null;
    $serialCount = null;
    if (is_string($extra2)) {
        $decoded = json_decode($extra2, true);
        if (is_array($decoded) && isset($decoded['serials']) && is_array($decoded['serials'])) {
            $serialCount = count($decoded['serials']);
        }
    } elseif (is_array($extra2) && isset($extra2['serials']) && is_array($extra2['serials'])) {
        $serialCount = count($extra2['serials']);
    }

    return json_encode([
        'keys' => $keys,
        'extra1' => is_scalar($payload['extra1'] ?? null) ? (string)$payload['extra1'] : null,
        'serial_count' => $serialCount,
    ], JSON_UNESCAPED_SLASHES);
}

function musky_debug_log_path(string $configPath, string $default = ''): string
{
    return trim(musky_root_config_string($configPath, $default));
}

function musky_append_debug_log(?string $path, string $message): void
{
    $path = trim((string)$path);
    if ($path === '') {
        return;
    }

    @file_put_contents($path, $message, FILE_APPEND);
}

/**
 * POST JSON helper
 */
function musky_nora_api_post_json(string $path, array $payload, int $timeout = 20): ?array
{
    $base  = musky_nora_base_url();
    $url   = $base . $path;   // DO NOT STRIP LEADING SLASH
    $tokenInfo = musky_nora_resolve_bearer_token();
    $token = (string)$tokenInfo['token'];
    $tokenSource = (string)$tokenInfo['source'];

    $debugFile = musky_debug_log_path('debug.nora_api_helper_log', '/tmp/nora_api_musky_helper.log');

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => array_filter([
            'Content-Type: application/json',
            $token !== '' ? "Authorization: Bearer {$token}" : null,
        ]),
    ]);

    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $info    = curl_getinfo($ch);

    if ($raw === false) {
        musky_nora_store_last_response_meta([
            'http_code' => (int)($info['http_code'] ?? 0),
            'curl_error' => $curlErr,
            'token_source' => $tokenSource,
            'path' => $path,
            'url' => $url,
        ]);
        musky_append_debug_log(
            $debugFile,
            "POST FAIL URL={$url}\nERROR={$curlErr}\n\n",
        );
        curl_close($ch);
        return null;
    }

    $headerSize = $info['header_size'] ?? 0;
    $header     = substr($raw, 0, $headerSize);
    $body       = substr($raw, $headerSize);
    $httpCode   = (int)($info['http_code'] ?? 0);

    musky_append_debug_log(
        $debugFile,
        "================ POST REQUEST ================\n" .
        "URL: {$url}\n" .
        "Auth token source: {$tokenSource}\n" .
        "Payload summary: " . musky_nora_debug_payload_summary($payload) . "\n" .
        "CURL ERROR: {$curlErr}\n" .
        "STATUS: " . ($httpCode ?: '???') . "\n" .
        "--- RESPONSE HEADERS ---\n{$header}\n" .
        "--- RESPONSE BODY BYTES ---\n" . strlen($body) . "\n\n"
    );

    curl_close($ch);

    $data = json_decode($body, true);
    if (!is_array($data)) {
        musky_nora_store_last_response_meta([
            'http_code' => $httpCode,
            'curl_error' => $curlErr,
            'token_source' => $tokenSource,
            'path' => $path,
            'url' => $url,
            'header' => $header,
            'body' => $body,
            'json_ok' => false,
        ]);
        musky_append_debug_log(
            $debugFile,
            "!!! POST returned NON-JSON for URL {$url}\n" .
            "Body bytes: " . strlen($body) . "\n\n"
        );
        return null;
    }

    musky_nora_store_last_response_meta([
        'http_code' => $httpCode,
        'curl_error' => $curlErr,
        'token_source' => $tokenSource,
        'path' => $path,
        'url' => $url,
        'header' => $header,
        'body' => $body,
        'json_ok' => true,
        'data' => $data,
    ]);

    return $data;
}

/**
 * GET JSON helper
 */
function musky_nora_api_get_json(string $path, array $query = [], int $timeout = 10): ?array
{
    $base  = musky_nora_base_url();
    $url   = $base . $path;   // DO NOT STRIP LEADING SLASH
    $tokenInfo = musky_nora_resolve_bearer_token();
    $token = (string)$tokenInfo['token'];
    $tokenSource = (string)$tokenInfo['source'];

    if (!empty($query)) {
        $url .= '&' . http_build_query($query);
    }

    $debugFile = musky_debug_log_path('debug.nora_api_helper_log', '/tmp/nora_api_musky_helper.log');

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => array_filter([
            $token !== '' ? "Authorization: Bearer {$token}" : null,
        ]),
    ]);

    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);

    if ($raw === false) {
        musky_nora_store_last_response_meta([
            'http_code' => 0,
            'curl_error' => $curlErr,
            'token_source' => $tokenSource,
            'path' => $path,
            'url' => $url,
        ]);
        musky_append_debug_log(
            $debugFile,
            "GET FAIL URL={$url}\nERROR={$curlErr}\n\n",
        );
        curl_close($ch);
        return null;
    }

    $info       = curl_getinfo($ch);
    $headerSize = $info['header_size'] ?? 0;
    $header     = substr($raw, 0, $headerSize);
    $body       = substr($raw, $headerSize);
    $httpCode   = (int)($info['http_code'] ?? 0);

    musky_append_debug_log(
        $debugFile,
        "================ GET REQUEST ================\n" .
        "URL: {$url}\n" .
        "Auth token source: {$tokenSource}\n" .
        "STATUS: " . ($httpCode ?: '???') . "\n" .
        "--- RESPONSE HEADERS ---\n{$header}\n" .
        "--- RESPONSE BODY BYTES ---\n" . strlen($body) . "\n\n"
    );

    curl_close($ch);

    $data = json_decode($body, true);
    if (!is_array($data)) {
        musky_nora_store_last_response_meta([
            'http_code' => $httpCode,
            'curl_error' => $curlErr,
            'token_source' => $tokenSource,
            'path' => $path,
            'url' => $url,
            'header' => $header,
            'body' => $body,
            'json_ok' => false,
        ]);
        file_put_contents(
            $debugFile,
            "!!! GET returned NON-JSON for URL {$url}\n" .
            "Body bytes: " . strlen($body) . "\n\n",
            FILE_APPEND
        );
        return null;
    }

    musky_nora_store_last_response_meta([
        'http_code' => $httpCode,
        'curl_error' => $curlErr,
        'token_source' => $tokenSource,
        'path' => $path,
        'url' => $url,
        'header' => $header,
        'body' => $body,
        'json_ok' => true,
        'data' => $data,
    ]);

    return $data;
}
