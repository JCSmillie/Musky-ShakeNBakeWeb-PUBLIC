# Loaner Explorer V2

This document covers the current MUSKY loaner workflow.

The old `web/Loaners/` manager and `web/loaner_constants.php` are retired.
Loaner work now lives in `web/LoanerExplorer/LoanerExplorer.php`.

## Entry Point

- `web/LoanerExplorer/LoanerExplorer.php`

This page is linked from the MUSKY launch hub as **Loaner Explorer V2**.

## What It Uses

Loaner Explorer reads from NORA-backed data instead of static pool constants.

- NORA tables used by the explorer: `iiq_loaners`, `devices`, `owners`, and `device_history`.
- Helper endpoints used by the page: `web/LoanerExplorer/fetch_loaner_pools.php`, `web/LoanerExplorer/fetch_loaner_devices.php`, `web/LoanerExplorer/Loaner_INVLookup.php`, `web/LoanerExplorer/Loaner_INVLookupStatus.php`, and `web/HelperPages/fetch_device_health.php`.

## Permissions

Any of these tool grants can open the page:

- `LOANER_EXPLORER`
- `CLASS_MANAGER`
- `EXPERIMENTAL`
- `EXPERIMINTAL`
- `ADMIN_PANEL`
- `ALL_TOOLS`

## Current Behavior

- Lists loaner pools from NORA with counts.
- Loads enriched device rows for the selected pool.
- Shows assignment and health-oriented details from the current NORA-backed device state.
- Supports the current Nora `INV_LOOKUP` flow used by Loaner Explorer and related tools.
- Lets users save a default loaner pool preference.

## Important Difference From The Old Manager

- No dependency on `web/loaner_constants.php`
- No dependency on the retired `web/Loaners/` pages
- Pool choices come from live NORA data, not a local PHP constant map

## Related Files

- `web/index.php`
- `web/LoanerExplorer/LoanerExplorer.php`
- `web/LoanerExplorer/fetch_loaner_pools.php`
- `web/LoanerExplorer/fetch_loaner_devices.php`
