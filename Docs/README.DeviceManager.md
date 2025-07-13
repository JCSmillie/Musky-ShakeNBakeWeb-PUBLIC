# Musky DeviceManager

## 🎯 Purpose

Musky DeviceManager is a web-based internal tool designed to help IT and Help Desk staff investigate, triage, and act on user and device activity using data from RADIUS, Mist, and other management systems.

It brings together disparate infrastructure data sources into a unified UI.

---

## 🧩 Key Features

- 🔍 **RADIUS Log Lookup** with session grouping, data usage, AP mapping
- 🛰 **Mist API Integration** to enrich RADIUS logs with AP names, coordinates, signal strength, and maps
- 📦 **Modular Tool Loader** — Run custom modules on any selected session or device
- 🛠️ **Developer Sandbox** — Write and test 3rd party modules inside `/Modules/`

---

## 🗂️ File Structure

```
web/DeviceManager/
├── index.php             # The main interface and module launcher
├── Modules/              # 3rd-party tool directory
│   ├── *.php             # Active modules
│   ├── *.php.DISABLED    # Hidden/inactive modules
│   ├── archived/         # Deprecated or historical modules
├── decode_tags.php       # Utility to convert RADIUS tag keys to readable names
├── .secrets              # API credentials for RADIUS queries
├── config.php            # Local configuration overrides
```

---

## 🧠 How It Works

When a user performs a lookup:

1. RADIUS logs are fetched via API (using `RADIUS_TOKEN` and `RADIUS_URL_TEMPLATE` from `.secrets`)
2. Data is parsed into structured PHP arrays and stored in `$_SESSION`
3. UI renders logs in HTML with optional grouping and debug views
4. If a module is selected, it receives all available session data automatically

---

## 🧪 Module System

Modules live in `Modules/` and are dynamically detected by `index.php`. Each one can:

- Access session data like `$_SESSION['parsed_lines']`
- Perform lookups, queries, or visualizations
- Be disabled (add `.DISABLED` to filename) or archived

See [`README.ModulesGuide.md`](README.ModulesGuide.md) for more.

---

## 🔐 Access & Security

- Only accessible from within the main DeviceManager UI
- Requires session variables and context to function properly
- Do not expose modules directly

---

## 🦡 Maintained by the Musky-DeviceManager Team
