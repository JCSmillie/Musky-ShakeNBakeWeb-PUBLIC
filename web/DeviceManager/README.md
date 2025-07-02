# 3rd Party Module System – MUSKY Device Manager

This module system allows `DeviceManager/index.php` to dynamically load individual 3rd-party scripts based on user interaction. Modules no longer run automatically, improving performance and clarity.

---

## 📦 How It Works

### Device Lookup Flow
- Runs `mosbasic getinfomini` to gather data
- Output parsed into `$lines` array (key=value)
- `$lines` stored in `$_SESSION['parsed_lines']`

### Displaying the Drawer
- Drawer appears **only after** a device is successfully looked up
- A list of module buttons is shown, one for each file in `/Modules/`

### On Click
- A module is requested using `run_module.php?name=ModuleName`
- `run_module.php`:
  - Loads `$lines` from session
  - Runs the selected module
  - Outputs its content directly

---

## 🗂 File Overview

| File                         | Purpose                                                        |
|------------------------------|----------------------------------------------------------------|
| `index.php`                  | Main UI and logic controller                                   |
| `load_modules_interactive.php` | Displays list of module names with buttons (➕)              |
| `run_module.php`             | Executes a single module securely with `$lines` loaded        |
| `/Modules/*.php`             | Individual 3rd party scripts – must check for `$lines`        |

---

## ✅ How to Write a Module

```php
<?php
if (!isset($lines) || !is_array($lines)) {
    echo "⚠️ No device data.";
    return;
}

// Example:
echo "<h3>Device Serial: " . htmlspecialchars($lines['DeviceSerialNumber'] ?? 'N/A') . "</h3>";
?>
```

---

## 🔒 Security
- Modules are only loaded via AJAX
- Names are sanitized with regex and `basename()`
- `$lines` is session-based and not re-computed on module call
