# DebugVariableDump.php

## 🛠️ Purpose

This module is a standalone debugging utility for Musky DeviceManager developers and advanced users. It prints out key session or request data so that module authors can quickly inspect:

- Parsed RADIUS session variables
- POST/GET request input
- Internal state before writing a custom module

---

## 🔍 How It Works

When run from within DeviceManager, it accesses and displays the contents of:

- `$_SESSION['parsed_lines']` — Parsed RADIUS variables from a selected user/device
- `$_POST` — If used inside a form submission context
- Other `$_SESSION` values that might be relevant to device or user state

The script is meant to be run in a browser and prints output in a basic HTML table or dump.

---

## 🔐 Access

- Lives in `/DeviceManager/Modules/`
- Disabled by default using `.DISABLED`
- Safe to re-enable during module development
- Should NOT be publicly exposed

---

## 📌 Use Cases

- Building a new module and need to see what's available?
- Want to confirm a field (like `DeviceMACAddress`) is present before using it?
- Great — use this.

---

Maintained by Jesse C Smillie
