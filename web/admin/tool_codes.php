<?php
/**
 * Backward-compatible tool map include.
 *
 * Why this wrapper exists:
 * - Historically, pages in this project included `tool_codes.php` and expected
 *   a plain `code => label` array.
 * - We now keep richer policy metadata in `tool_access_helpers.php` so Admin
 *   can enforce:
 *   1) "new + level" tools are NOT assignable to groups
 *   2) top-tier tools are hidden for restricted individual email domains
 * - Returning the map from this wrapper preserves old include behavior while
 *   letting new logic live in one shared helper file.
 */
require_once __DIR__ . '/tool_access_helpers.php';

return musky_admin_tool_codes_map();
