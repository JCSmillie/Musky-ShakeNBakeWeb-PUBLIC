# MUSKY Project Security Overview

This document outlines the architecture and deployment details for MUSKY’s secure access control system, used across Gateway School District tools. It emphasizes session-based 2FA (Two-Factor Authentication) layered with optional Apache authentication and LDAP integration.

---

## 🔐 Core Security Features

- ✅ **2FA session enforcement** (`check_access.php`)
- ✅ **LDAP authentication** using Active Directory
- ✅ **Session timeout logic** (idle-based auto-expiry)
- ✅ **Post-login redirect** returns user to original path
- ✅ **Redirect logging** to `/tmp/login_redirect.log`
- ✅ **Security HTTP headers** via both Apache and PHP
- ✅ **Optional .htaccess support** for IP or LDAP group whitelisting

---

## 📦 Required Files

### `check_access.php`

Include this at the top of any protected page:

```php
<?php require_once '../check_access.php'; ?>
```

Responsibilities:
- Enforces presence of `$_SESSION['check_in']`
- Redirects to 2FA portal if missing, preserving original request URL via `?return=...`
- Implements 1-hour idle timeout via `$_SESSION['last_activity']`
- Sets fallback HTTP headers for browser security

📌 **Latest additions:**
- Verifies session start via `session_start()`
- Uses `$_SESSION['check_in']` (not just `logged_in`)
- Redirect-aware (`$_SERVER['REQUEST_URI']`)

---

### `login.php` (in `/secure/2fa-portal/`)

Handles:
- Username/password authentication via LDAP
- TOTP-based 2FA
- Redirect back to page user attempted to access (`?return=...`)
- Logs redirection targets and request URI to `/tmp/login_redirect.log`

🔐 **Key implementation points:**
- Must set: `$_SESSION['check_in'] = true;` upon successful login
- Validates and sanitizes the redirect target to avoid open redirects
- Example:
  ```php
  $return = urldecode($_GET['return'] ?? '/');
  if (!preg_match('/^\/[\w\-\/\.\?=&]+$/', $return)) {
      $return = '/';
  }
  header("Location: $return");
  ```

---

### `config.php` (2FA portal)

Defines LDAP bind credentials and TOTP DB path:
- `ldap_host`
- `ldap_bind_dn`
- `ldap_bind_password`
- `ldap_base_dn`
- `ldap_attribute` (e.g., `sAMAccountName`)
- `totp_db` (SQLite path)

---

## 🔐 Optional: `.htaccess` Security Layer

For environments without full Apache config access, use `.htaccess`:

```apache
AuthType Basic
AuthName "Musky Secure"
AuthUserFile /path/to/.htpasswd
Require valid-user

Order Deny,Allow
Deny from all
Allow from 10.0.0.0/8
Allow from 192.168.0.0/16

<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "no-referrer"
</IfModule>

<FilesMatch "^(config\.php|\.env|\.secrets)$">
    Require all denied
</FilesMatch>

Options -Indexes
```

---

## 🧠 Admin Recommendations

- 🧱 **Use Apache config instead of .htaccess** where possible (`AllowOverride None`)
- 💾 **Session timeout**: set `$_SESSION['last_activity']` and check age each request
- 🔒 **Redirect protection**: never allow full URLs from user input
- 📜 **Audit login_redirect.log** to debug redirect issues

---

## ✅ Minimum Setup Checklist

1. ✅ Protect each page with `require_once 'check_access.php';`
2. ✅ Make sure `login.php` sets `$_SESSION['check_in'] = true;`
3. ✅ Preserve intended destination via `?return=` and `$_SERVER['REQUEST_URI']`
4. ✅ Set headers in Apache OR fallback PHP block
5. ✅ Implement `expired.php` to handle timeout exits cleanly

---

## 🧪 Testing Tips

- Use `curl -I` to confirm HTTP headers are returned
- Access a subpage directly and verify you’re returned after login
- Confirm `/tmp/login_redirect.log` captures intended targets

---

## 📁 See Also

- `expired.php` – optional timeout page
- `login.withCheckIn.php` – patched example with comments
- `check_access.SECURE.php` – hardened access wrapper
