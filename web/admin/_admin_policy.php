<?php
declare(strict_types=1);

if (!function_exists('musky_admin_policy_normalize_allowed_tools')) {
    function musky_admin_policy_normalize_allowed_tools($allowedTools): array
    {
        if (function_exists('musky_app_normalize_allowed_tools')) {
            return musky_app_normalize_allowed_tools($allowedTools);
        }

        if (is_string($allowedTools)) {
            $allowedTools = explode(',', $allowedTools);
        } elseif (!is_array($allowedTools)) {
            $allowedTools = [];
        }

        $normalized = array_map(static function ($value) {
            return strtoupper(trim((string)$value));
        }, $allowedTools);

        return array_values(array_unique(array_filter($normalized, static function ($value) {
            return $value !== '';
        })));
    }
}

if (!function_exists('musky_admin_equivalent_from_allowed')) {
    function musky_admin_equivalent_from_allowed($allowedTools, string $role = ''): bool
    {
        $allowed = musky_admin_policy_normalize_allowed_tools($allowedTools);
        $roleNorm = strtolower(trim($role));

        return in_array('ALL_TOOLS', $allowed, true)
            || in_array('ADMIN_PANEL', $allowed, true)
            || in_array('PANDA_ADMIN', $allowed, true)
            || in_array('HDK-SUPERVISOR-ADMIN', $allowed, true)
            || in_array('HDK-SUPERVISOR-LII', $allowed, true)
            || in_array($roleNorm, ['admin', 'superadmin', 'itadmin', 'muskyadmin'], true);
    }
}

if (!function_exists('musky_admin_equivalent_from_auth_user')) {
    function musky_admin_equivalent_from_auth_user(array $authUser): bool
    {
        $allowed = $authUser['allowed_tools'] ?? [];
        $role = (string)($authUser['role'] ?? '');
        return musky_admin_equivalent_from_allowed($allowed, $role);
    }
}
