# Changelog

## [Unreleased] – 2025-05-07

### Added
- Theme is now loaded from SQLite (`/etc/httpd/2Fa/MUSKY2FA.db`) per user session.
- Drawer UI for 3rd Party Modules, toggled by a ➕/➖ button.
- Interactive module list displayed only after a successful device lookup.
- `run_module.php` to safely load and execute individual modules on demand.
- `load_modules_interactive.php` to show list of available modules with ➕ buttons.

### Removed
- Removed automatic execution of all `/Modules/*.php` from `index.php`.
- Removed theme selection dropdown and POST-based theme saving.

### Fixed
- Fixed `$lines` not being available to AJAX-loaded modules.
- Prevented duplicate definition of `toggleModules()` function.
- Ensured `$lines` is only generated and used after valid device lookup.
