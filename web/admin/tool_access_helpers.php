<?php
/**
 * Musky Admin Tool Access Helpers
 *
 * IMPORTANT DESIGN INTENT
 * -----------------------
 * This file is intentionally inside `web/admin` because the project direction
 * from 2026-05-09 is to define new tool levels in Admin first, then let other
 * pages adopt those levels over time.
 *
 * In other words:
 * - Admin is the source of truth for tool *codes* and policy metadata.
 * - Feature pages can consume these helpers later as they are upgraded.
 *
 * Why this exists instead of hard-coding checkboxes in one page:
 * - Keeps all level names and policy rules in one place.
 * - Gives us reusable helper functions other pages can call later.
 * - Makes policy rules enforceable server-side (not only hidden in HTML).
 */

if (!function_exists('musky_root_config_array')) {
    require_once __DIR__ . '/../../Functions/MuskyConfig.php';
}

if (!function_exists('musky_admin_tool_catalog')) {
    /**
     * Canonical tool catalog for Admin.
     *
     * Each entry defines both the display label and policy flags.
     * The policy flags are what drive filtering in user/group assignment UIs.
     *
     * Field guide:
     * - label: human-readable text shown in Admin.
     * - group_assignable: if false, code must NEVER appear in group checkbox UI.
     * - individual_assignable: if false, code is hidden from user checkbox UI.
     * - top_tier: marks "Admin Controls/TOP TIER LV SUPPORT" tools.
     * - new_level: marks newly introduced levels from the 2026-05-09 spec.
     * - deprecated: legacy code retained for compatibility/readability only.
     */
    function musky_admin_tool_catalog(): array
    {
        return [
            // -----------------------------------------------------------------
            // EVERYONE (baseline)
            // -----------------------------------------------------------------
            'YOUR_DEVICE' => [
                'label' => 'Your Device Tools',
                'group_assignable' => true,
                'individual_assignable' => true,
                'top_tier' => false,
                'new_level' => false,
                'deprecated' => false,
            ],

            // -----------------------------------------------------------------
            // HELP DESK STUDENTS
            // -----------------------------------------------------------------
            'DEVICE_MANAGER' => [
                'label' => 'Device Management',
                'group_assignable' => true,
                'individual_assignable' => true,
                'top_tier' => false,
                'new_level' => false,
                'deprecated' => false,
            ],
            'HDK_TRAINEE' => [
                'label' => 'HDK Trainee (RO)',
                // NEW (+) level rule: never assignable to groups.
                'group_assignable' => false,
                'individual_assignable' => true,
                'top_tier' => false,
                'new_level' => true,
                'deprecated' => false,
            ],
            'HDK-SUPERVISOR-LI' => [
                'label' => 'HDK Supervisor Level I (RO)',
                // NEW (+) level rule: never assignable to groups.
                'group_assignable' => false,
                'individual_assignable' => true,
                'top_tier' => false,
                'new_level' => true,
                'deprecated' => false,
            ],
            'PANDA_CASHIER' => [
                'label' => 'Musky Charges Creator',
                'group_assignable' => true,
                'individual_assignable' => true,
                'top_tier' => false,
                'new_level' => false,
                'deprecated' => false,
            ],
            'PANDA_VIEWER' => [
                'label' => 'Musky Charges Viewer',
                'group_assignable' => true,
                'individual_assignable' => true,
                'top_tier' => false,
                'new_level' => false,
                'deprecated' => false,
            ],
            'LOANER_MGMT' => [
                'label' => 'Can Do Loaners',
                'group_assignable' => true,
                'individual_assignable' => true,
                'top_tier' => false,
                'new_level' => false,
                'deprecated' => false,
            ],

            // -----------------------------------------------------------------
            // ADULT IT FACULTY
            // -----------------------------------------------------------------
            'HDK-SUPERVISOR' => [
                // Kept for backwards compatibility with old rows, but hidden from
                // assignment to encourage migration to the new LI/LII/ADMIN tiers.
                'label' => 'HDK Supervisor (Legacy / Deprecated)',
                'group_assignable' => false,
                'individual_assignable' => false,
                'top_tier' => false,
                'new_level' => false,
                'deprecated' => true,
            ],
            'HDK-SUPERVISOR-LII' => [
                'label' => 'HDK Supervisor Level II (R/W)',
                // NEW (+) level rule: never assignable to groups.
                'group_assignable' => false,
                'individual_assignable' => true,
                'top_tier' => false,
                'new_level' => true,
                'deprecated' => false,
            ],
            'HDK-SUPERVISOR-ADMIN' => [
                'label' => 'HDK Supervisor Admin (Adult R/W)',
                // NEW (+) level rule: never assignable to groups.
                'group_assignable' => false,
                'individual_assignable' => true,
                'top_tier' => false,
                'new_level' => true,
                'deprecated' => false,
            ],
            'CLASS_MANAGER' => [
                'label' => 'Class Management',
                'group_assignable' => true,
                'individual_assignable' => true,
                'top_tier' => false,
                'new_level' => false,
                'deprecated' => false,
            ],
            'PANDA_MANAGER' => [
                'label' => 'Musky Charges Manager',
                'group_assignable' => true,
                'individual_assignable' => true,
                'top_tier' => false,
                'new_level' => false,
                'deprecated' => false,
            ],

            // -----------------------------------------------------------------
            // ADMIN CONTROLS / TOP TIER LV SUPPORT
            // -----------------------------------------------------------------
            'ADMIN_PANEL' => [
                'label' => 'Admin Access Panel',
                'group_assignable' => true,
                'individual_assignable' => true,
                'top_tier' => true,
                'new_level' => false,
                'deprecated' => false,
            ],
            'EXPERIMENTAL' => [
                'label' => 'Experimental Tools',
                'group_assignable' => true,
                'individual_assignable' => true,
                'top_tier' => true,
                'new_level' => false,
                'deprecated' => false,
            ],
            'PANDA_ADMIN' => [
                'label' => 'Musky Charges Admin',
                'group_assignable' => true,
                'individual_assignable' => true,
                'top_tier' => true,
                'new_level' => false,
                'deprecated' => false,
            ],
            'ALL_TOOLS' => [
                'label' => 'Everything (God Mode)',
                'group_assignable' => true,
                'individual_assignable' => true,
                'top_tier' => true,
                'new_level' => false,
                'deprecated' => false,
            ],
        ];
    }
}

if (!function_exists('musky_admin_tool_codes_map')) {
    /**
     * Return plain code => label mapping.
     *
     * This lets legacy call sites keep their old expectation while we gain
     * metadata-driven behavior elsewhere.
     */
    function musky_admin_tool_codes_map(): array
    {
        $map = [];
        foreach (musky_admin_tool_catalog() as $code => $meta) {
            $map[$code] = (string)($meta['label'] ?? $code);
        }
        return $map;
    }
}

if (!function_exists('musky_admin_is_restricted_individual_email')) {
    /**
     * Identify the email domain that must not receive top-tier tools directly.
     */
    function musky_admin_is_restricted_individual_email(string $email): bool
    {
        return musky_email_matches_domains($email, musky_identity_restricted_individual_domains());
    }
}

if (!function_exists('musky_admin_individual_assignable_tool_map_for_email')) {
    /**
     * Tool list allowed in the *individual user* checkbox section for a user.
     *
     * Rules enforced:
     * - hidden if `individual_assignable` is false
     * - hidden if top-tier AND email is on restricted domain list
     */
    function musky_admin_individual_assignable_tool_map_for_email(string $email): array
    {
        $restricted = musky_admin_is_restricted_individual_email($email);
        $map = [];

        foreach (musky_admin_tool_catalog() as $code => $meta) {
            if (empty($meta['individual_assignable'])) {
                continue;
            }
            if ($restricted && !empty($meta['top_tier'])) {
                continue;
            }
            $map[$code] = (string)($meta['label'] ?? $code);
        }

        return $map;
    }
}

if (!function_exists('musky_admin_group_assignable_tool_map')) {
    /**
     * Tool list allowed in the *group* checkbox section.
     *
     * Rules enforced:
     * - only codes with `group_assignable = true`
     * - this automatically excludes all NEW (+) levels
     */
    function musky_admin_group_assignable_tool_map(): array
    {
        $map = [];

        foreach (musky_admin_tool_catalog() as $code => $meta) {
            if (empty($meta['group_assignable'])) {
                continue;
            }
            $map[$code] = (string)($meta['label'] ?? $code);
        }

        return $map;
    }
}

if (!function_exists('musky_admin_sanitize_tool_selection')) {
    /**
     * Server-side policy enforcement for submitted checkbox tool lists.
     *
     * Why we do this even though the UI hides options:
     * - A browser can still submit forged POST bodies.
     * - Policy must be enforced in PHP before writing to DB.
     *
     * $scope values:
     * - `user`  => enforce individual assignment rules
     * - `group` => enforce group assignment rules
     */
    function musky_admin_sanitize_tool_selection(array $requestedCodes, string $scope, string $email = ''): array
    {
        $catalog = musky_admin_tool_catalog();
        $scope = strtolower(trim($scope));
        $restricted = musky_admin_is_restricted_individual_email($email);

        $clean = [];
        foreach ($requestedCodes as $rawCode) {
            $code = trim((string)$rawCode);
            if ($code === '' || !isset($catalog[$code])) {
                continue;
            }

            $meta = $catalog[$code];

            if ($scope === 'group' && empty($meta['group_assignable'])) {
                continue;
            }

            if ($scope === 'user') {
                if (empty($meta['individual_assignable'])) {
                    continue;
                }
                if ($restricted && !empty($meta['top_tier'])) {
                    continue;
                }
            }

            if (!in_array($code, $clean, true)) {
                $clean[] = $code;
            }
        }

        return $clean;
    }
}

if (!function_exists('musky_admin_extra_membership_codes_from_csv')) {
    /**
     * Parse CSV -> membership codes for the on-card "tag chips" display.
     *
     * We intentionally skip YOUR_DEVICE because it is universal baseline access
     * and usually creates noise when shown as a badge on every user.
     */
    function musky_admin_extra_membership_codes_from_csv(string $allowedToolsCsv): array
    {
        $memberships = [];
        foreach (explode(',', $allowedToolsCsv) as $rawCode) {
            $code = trim((string)$rawCode);
            if ($code === '' || $code === 'YOUR_DEVICE') {
                continue;
            }

            if (!in_array($code, $memberships, true)) {
                $memberships[] = $code;
            }
        }

        return $memberships;
    }
}
