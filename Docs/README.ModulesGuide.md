# Musky DeviceManager – 3rd Party Modules Guide

## 🎯 What Are "Modules"?

Modules are standalone PHP scripts that extend the functionality of the Musky DeviceManager system. They receive structured data from the UI and can use that information to:

- Query external systems (Jamf, Mist, AD, RADIUS)
- Trigger workflows (retire devices, report abuse, locate iPads)
- Display rich info to help desk or admin staff

They are pluggable, share a consistent interface, and live entirely within the project tree.

---

## 📂 Where Modules Are Stored

All modules live under:

```
<<PROJECT>>/web/DeviceManager/Modules/
```

This folder is scanned by `index.php` to display a list of available tools for the currently selected user/session/device.

---

## 🚀 How Modules Are Triggered

The DeviceManager UI passes context to each module when it's selected. This includes:

- `$_SESSION['parsed_lines']` — structured RADIUS log values
- `$_SESSION['selected_record']` — single log row
- `$_SESSION['last_lookup']['USERID']` — username from query
- `$_POST`, `$_GET` — if the module is interactive

Then, the script is run as a normal PHP file and outputs HTML (or JSON if desired).

---

## 📛 Naming Conventions

The filename controls whether a module is active:

- `MyModule.php` — ✅ Active and visible to the UI
- `MyModule.php.DISABLED` — ❌ Hidden from UI, not auto-loaded
- `MyModule.php.ARCHIVED` — 🔒 Deprecated, preserved for reference only

Always keep experimental or dev-only modules `.DISABLED` until tested.

---

## 🧪 Example Modules

- `RadiusUserIDLookup.php`:  
  Receives a user ID, pulls RADIUS logs, and overlays Mist AP names and locations.

- `RetireiPad.php`:  
  Receives a device record (serial or MAC), flags it in inventory and optionally notifies staff.

- `DebugVariableDump.php`:  
  Developer helper that dumps what data is available to the current module.

---

## 💡 Tips for Writing Modules

- Start by inspecting `$_SESSION['parsed_lines']` — it's your goldmine.
- Always check if a variable exists before using it (`isset(...)`).
- You can safely include your own JSON helpers, API clients, etc.
- Avoid touching files outside the sandbox (`DataSources/`, `Modules/`).
- Keep output clean and self-contained — modules live in an iframe or scoped panel.

---

## ✅ Suggested Workflow

1. Copy `ExampleVariables4Modules.php.DISABLED` to a new file
2. Rename it to something descriptive: `MyReportTool.php.DISABLED`
3. Load it in the UI via `index.php`, select a user, and test
4. Once verified, drop the `.DISABLED` and it's live!

---

Modules are a lightweight, powerful way to bring your infrastructure together under one pane of glass.

🦡 Brought to you by the Musky-DeviceManager team.
