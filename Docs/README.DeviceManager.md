# Musky DeviceManager

## Purpose

`web/DeviceManager/index.php` is Musky's operator page for device lookup and action dispatch.

The current page is Nora-first and asset-tag-driven:
- search input only accepts real 5-digit asset tags
- current Nora data is shown first when available
- `INV_LOOKUP` is then submitted for fresh data
- only the device data region is refreshed (no full-page reload)

## Primary Lookup Rules

1. Input must be exactly 5 numeric digits.
2. Username searching is intentionally not supported on this page.
3. Lookup resolves through Nora data and then refreshes from Nora after `INV_LOOKUP` completes.

## Action Buttons (Current)

These actions are shown in the DeviceManager sidebar (state-dependent):
- `Report Problem with this Device`
- `Wipe Device`
- `Enable Lost Mode` / `Disable Lost Mode`
- `Assign Device`
- `Restart Device`
- `Power Wash & Wax`
- `Device Report`
- `Play Sound` and `Show Location` (when Lost Mode is enabled and device is recently seen)
- `Look Up Again`
- `DEBUG`

## Shared Action Wiring

DeviceManager now reuses sidebar helper mappings so action URL changes can be centralized:
- `web/DeviceManager/sidebar.php` provides shared action URL/popup helpers
- DeviceManager includes those helpers and uses them for:
  - Report Problem
  - Restart Device (Power Reboot method)
  - Power Wash & Wax

## Report Problem Endpoint Security

The report-problem destination (`web/DeviceManager/make_ticket.php`) is now secured for direct URL access and not just launcher flow:
- boots `web/check_access.php`
- enforces tools via `_tool_guard.php` (`YOUR_DEVICE`, `DEVICE_MANAGER`, `ADMIN_PANEL`, `ALL_TOOLS`)
- requires a valid CSRF token for form submission
- validates serial format before processing/writing
- blocks non-admin usage unless the serial belongs to the current logged-in user in Nora

## Action Security

DeviceManager now treats sidebar actions and refresh errands as state-changing operations with their own CSRF scopes:

- sidebar action form posts use `device_manager_actions`
- AJAX `INV_LOOKUP` submission uses `device_manager_inv_lookup`

That means:

- searching by asset tag remains a normal read-only page flow
- action buttons cannot be cross-site posted successfully without a valid session-backed token
- the legacy MOSBasic fallback commands are still present for continuity, but they now sit behind the same login, tool, and CSRF gates as the Nora-first action path

## Tag Decoding

`web/DeviceManager/decode_tags.php` still renders the same tag output format, but the tag map is now DB-backed:
- table: `nora_config_store`
- group: `TagDecode`
- set: `DEFAULT`
- admin page: `web/admin/NoraWeb.TagDecode.php`

For details, see [`README.decode_tags.md`](README.decode_tags.md).

## Theme Behavior

DeviceManager uses `Functions/LoggedInUserPrefs.php` theme preferences and normalizes legacy aliases to supported classes:
- `light-mode`
- `dark-mode`
- `musky-mode`
- `gator-time-mode`

## File Structure (Current Core)

```text
web/DeviceManager/
├── index.php                  # Main DeviceManager page and API endpoints
├── sidebar.php                # Shared action URL/popup helper source
├── decode_tags.php            # Tag rendering include (DB-backed map source)
├── device_report.php          # Detailed single-device report
├── MyDevices.php              # Per-user self-service device page
├── make_ticket.php            # Device issue/ticket intake endpoint
└── Modules/                   # Optional 3rd-party modules
```

## Notes for Module Authors

- 3rd-party module behavior is intentionally preserved.
- Modules are discovered from `web/DeviceManager/Modules/*.php`.
- DeviceManager continues to expose parsed lookup context to modules as before.

## Related Admin Docs

- [`README.NoraConfigStore.md`](README.NoraConfigStore.md)
- [`README.decode_tags.md`](README.decode_tags.md)
