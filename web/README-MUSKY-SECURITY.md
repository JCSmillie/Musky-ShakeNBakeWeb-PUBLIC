# MUSKY Project Security Overview

This document outlines the structure, usage, and deployment of the integrated security system used in MUSKY tools developed at Gateway School District. It focuses on session-based 2FA enforcement via PHP and LDAP, designed to be portable and maintainable.

---

## 🔐 Features

- **2FA session enforcement** using a centralized `check_access.php`
- **LDAP authentication** backed by Active Directory
- **Time-based session expiration** with automatic re-authentication
- **Logging of successful logins, session expirations, and redirects**
- **Portable architecture**: enable or disable 2FA from the main `config.php`
- **Support for internal or external 2FA portals**

---

## 🔧 Files Involved

### `check_access.php`
This file is included at the top of any MUSKY page you want to protect:
```php
<?php require_once '../check_access.php'; ?>
```

It enforces 2FA sessions, handles session expiry, logs access events, and redirects to the configured login portal when needed.

### `config.php` (project root)
Defines global project settings, including:
- `$ENABLE_2FA` - Enable or disable 2FA enforcement
- `$TWO_FA_PORTAL_URL` - URL to 2FA login system (local or remote)
- `$TWO_FA_CONFIG_PATH` - Path to 2FA portal config file
- `$SESSION_TIMEOUT` - Session expiration in seconds
- `$SESSION_LOG_PATH` - Log file path for session events

### `config.php` (2FA Portal)
Located in the 2FA portal directory (e.g. `/secure/2fa-portal/`). This config defines:
- LDAP connection details (host, bind DN, password)
- Path to the SQLite TOTP database
- Portal-specific timeouts

---

## ✅ How to Add 2FA to a MUSKY Page

1. Include `check_access.php` at the top:
```php
<?php require_once '../check_access.php'; ?>
```
2. Ensure the project-level `config.php` contains a valid path to the 2FA portal config:
```php
$TWO_FA_CONFIG_PATH = '/path/to/2fa-portal/config.php';
```
3. Set `$ENABLE_2FA = true;` to activate enforcement.

---

## 🛡️ Security Notes

- `check_access.php` starts with `<?php` and contains **no whitespace or output outside PHP**
- Files are logged to `$SESSION_LOG_PATH`, including:
  - LOGIN SUCCESS
  - LOGIN REDIRECT (unauthenticated)
  - SESSION EXPIRED
- Access to the 2FA portal should be secured with TLS and/or IP restrictions.
- Sessions are tracked by PHP and expire via idle timeout (default: 30 minutes)

---

## 🧪 Debugging Tips

- Use `head -n 1 check_access.php | cat -A` to verify no invisible whitespace or BOM
- Use `die("CHECK_ACCESS")` at the top of `check_access.php` to verify it executes
- Avoid including `check_access.php` inside HTML or `file_get_contents()` — must be parsed as PHP

---

## 🧼 Clean Inclusion Example
```php
<?php
require_once '../check_access.php';
require_once '../config.php';
?>
```
Make sure this code is at the **top of your page**, before any output.

---

## 📦 Future Enhancements

- Optional IP allowlisting via `$CAMPUS_IPS`
- Per-user 2FA exemptions or group-based overrides
- 2FA bypass tokens for automated scripts
- Optional Slack notifications for first-time logins or security anomalies

---

Maintained by Jesse Smillie for Gateway School District. Contributions welcome!
