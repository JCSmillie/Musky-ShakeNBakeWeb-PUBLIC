<?php
// ============================================================================
// MUSKY CSRF HELPER
// ----------------------------------------------------------------------------
// PURPOSE
//   Provide one small shared place for synchronizer-token CSRF protection so
//   state-changing pages do not each invent their own slightly-different logic.
//
// DESIGN
//   - Tokens are session-backed.
//   - Tokens are scoped so unrelated forms/APIs do not all share one value.
//   - Validation is intentionally simple and readable.
//   - Failure path returns HTTP 403 with a short plain-text message because many
//     of these pages are classic PHP pages rather than JSON-first endpoints.
// ============================================================================

declare(strict_types=1);

/**
 * Ensure a session exists before we try to read/write CSRF state.
 */
function musky_csrf_ensure_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/**
 * Build the internal session bucket key for a given CSRF scope.
 */
function musky_csrf_scope_key(string $scope): string
{
    return 'musky_csrf_' . trim($scope);
}

/**
 * Return the stable CSRF token for a scope, creating it if needed.
 */
function musky_csrf_token(string $scope = 'default'): string
{
    musky_csrf_ensure_session();

    $key = musky_csrf_scope_key($scope);
    $token = $_SESSION[$key] ?? '';
    if (!is_string($token) || $token === '') {
        $token = bin2hex(random_bytes(32));
        $_SESSION[$key] = $token;
    }

    return $token;
}

/**
 * Pull a submitted CSRF token from common POST / JSON / header locations.
 *
 * This lets classic forms and fetch()-driven JSON endpoints use the same
 * validator without forcing every caller into one exact transport style.
 */
function musky_csrf_request_token(string $field = 'csrf_token'): ?string
{
    musky_csrf_ensure_session();

    $postToken = $_POST[$field] ?? null;
    if (is_string($postToken) && $postToken !== '') {
        return $postToken;
    }

    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (is_string($headerToken) && $headerToken !== '') {
        return $headerToken;
    }

    $raw = (string)file_get_contents('php://input');
    if ($raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $jsonToken = $json[$field] ?? null;
            if (is_string($jsonToken) && $jsonToken !== '') {
                return $jsonToken;
            }
        }
    }

    return null;
}

/**
 * Validate a token for a given scope.
 */
function musky_csrf_is_valid(string $scope = 'default', ?string $token = null): bool
{
    musky_csrf_ensure_session();

    $expected = musky_csrf_token($scope);
    $provided = $token ?? musky_csrf_request_token();

    return is_string($provided) && $provided !== '' && hash_equals($expected, $provided);
}

/**
 * Abort the request if the scoped token is missing or wrong.
 */
function musky_csrf_require(string $scope = 'default', ?string $token = null): void
{
    if (musky_csrf_is_valid($scope, $token)) {
        return;
    }

    http_response_code(403);
    echo 'Invalid or missing CSRF token.';
    exit;
}

