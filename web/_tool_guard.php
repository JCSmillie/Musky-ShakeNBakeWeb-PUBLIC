<?php
declare(strict_types=1);

if (!function_exists('musky_tool_normalize_allowed')) {
    /**
     * Normalize allowed tool payloads into uppercase unique codes.
     *
     * Accepts either CSV strings or arrays.
     */
    function musky_tool_normalize_allowed($allowedRaw): array
    {
        if (is_string($allowedRaw)) {
            $allowedRaw = explode(',', $allowedRaw);
        } elseif (!is_array($allowedRaw)) {
            $allowedRaw = [];
        }

        $normalized = array_map(static function ($value): string {
            return strtoupper(trim((string)$value));
        }, $allowedRaw);

        $filtered = array_filter($normalized, static function (string $value): bool {
            return $value !== '';
        });

        return array_values(array_unique($filtered));
    }
}

if (!function_exists('musky_tool_has_any')) {
    /**
     * Return true if user has at least one required tool.
     */
    function musky_tool_has_any($allowedRaw, array $requiredTools, bool $allowAllTools = true): bool
    {
        $allowed = musky_tool_normalize_allowed($allowedRaw);
        $required = musky_tool_normalize_allowed($requiredTools);

        if ($allowAllTools && in_array('ALL_TOOLS', $allowed, true)) {
            return true;
        }

        foreach ($required as $tool) {
            if (in_array($tool, $allowed, true)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('musky_general_admin_access_tools')) {
    /**
     * Shared elevated tools for "GeneralAdminAccess".
     *
     * This is intentionally separate from true admin-role checks.
     */
    function musky_general_admin_access_tools(): array
    {
        return [
            'HDK-SUPERVISOR-LII',
            'HDK-SUPERVISOR-ADMIN',
        ];
    }
}

if (!function_exists('musky_require_any_tool')) {
    /**
     * Enforce access for one-of-many tool requirements.
     *
     * Options:
     * - response: json|text|html (default text)
     * - status: HTTP status code (default 403)
     * - message: fallback human-readable message
     * - payload: JSON payload (used when response=json)
     * - allow_all_tools: bool (default true)
     */
    function musky_require_any_tool($allowedRaw, array $requiredTools, array $options = []): array
    {
        $allowed = musky_tool_normalize_allowed($allowedRaw);
        $allowAllTools = array_key_exists('allow_all_tools', $options)
            ? (bool)$options['allow_all_tools']
            : true;

        if (musky_tool_has_any($allowed, $requiredTools, $allowAllTools)) {
            return $allowed;
        }

        $status = (int)($options['status'] ?? 403);
        $response = strtolower((string)($options['response'] ?? 'text'));
        $message = (string)($options['message'] ?? 'Access denied.');

        http_response_code($status);

        if ($response === 'json') {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }

            $payload = $options['payload'] ?? ['error' => 'forbidden', 'detail' => $message];
            if (!is_array($payload)) {
                $payload = ['error' => 'forbidden', 'detail' => $message];
            } elseif (!isset($payload['detail']) && !isset($payload['message'])) {
                $payload['detail'] = $message;
            }

            echo json_encode($payload);
            exit;
        }

        if ($response === 'html') {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=UTF-8');
            }
            echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
            exit;
        }

        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo $message;
        exit;
    }
}

if (!function_exists('musky_require_general_admin_access')) {
    /**
     * Enforce page access with a shared GeneralAdminAccess tool layer.
     *
     * - `pageTools`: page-specific tools (e.g., CLASS_MANAGER, LOANER_EXPLORER)
     * - Adds shared GeneralAdminAccess tools by default
     * - Adds ADMIN_PANEL by default
     * - Still honors ALL_TOOLS through musky_require_any_tool(...)
     *
     * Options:
     * - include_general_admin_tools: bool (default true)
     * - include_admin_panel: bool (default true)
     * - plus any options accepted by musky_require_any_tool(...)
     */
    function musky_require_general_admin_access($allowedRaw, array $pageTools, array $options = []): array
    {
        $includeGeneral = array_key_exists('include_general_admin_tools', $options)
            ? (bool)$options['include_general_admin_tools']
            : true;
        $includeAdminPanel = array_key_exists('include_admin_panel', $options)
            ? (bool)$options['include_admin_panel']
            : true;

        unset($options['include_general_admin_tools'], $options['include_admin_panel']);

        $required = musky_tool_normalize_allowed($pageTools);

        if ($includeGeneral) {
            $required = array_merge($required, musky_general_admin_access_tools());
        }

        if ($includeAdminPanel) {
            $required[] = 'ADMIN_PANEL';
        }

        $required = musky_tool_normalize_allowed($required);

        return musky_require_any_tool($allowedRaw, $required, $options);
    }
}
