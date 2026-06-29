# MUSKY — Class Explorer
*(Updated 2025-10-18)*

## Overview
The **Class Explorer** module provides teachers and administrators with a unified interface to view iPad assignments and device metadata for student classes, directly from the **NORA** database.

Teachers can view their assigned classes and corresponding student devices. Administrators have extended controls to select buildings, teachers, and classes, with an option to spoof a teacher's view.

---

## Key Features

### 🔐 Authentication
- Enforced via `check_access.php`
- Requires one of: `EXPERIMENTAL`, `EXPERIMINTAL`, `ADMIN_PANEL`, or `ALL_TOOLS`

### 🧑‍🏫 Teacher & Admin Modes
- Teachers see their own classes.
- Admins can browse by **Building → Teacher → Class**
- Admins can **spoof** teacher access using `?as=teacher@example.org`

### 🏫 Building & Class Sorting
- Sorted by school type: **Elementary → Middle → High**
- HOMEROOM classes always appear first.
- High and Middle schools are forced to the bottom.

### 💡 User Interface
- Modern responsive layout with right-side sidebar
- Sidebar actions: Report Problem, Soft Reboot, Device Report, Refresh, Debug
- Tag visualization as orange pills
- Tooltips for last check-in times
- Tablet emoji beside asset tag
- Hide uncontrollable iPads toggle
- Mascot (`mascot.png`, 100px) anchored bottom-right

### 🧰 Debug & Logging
- Logs to `$LOG_PATH/musky_class_debug.log` or `/tmp/musky_class_debug.log`
- Logs include user, timestamp, class, and devices acted on
- Debug button (admin-only) shows JSON for selected or all devices

### 📋 Backends
#### `fetch_filters.php`
Provides dropdown data for buildings, teachers, and classes.  
- Enforces session + permission check  
- Supports spoof  
- Orders HOMEROOM classes first and schools by tier

#### `fetch_class_devices.php`
Fetches student/device info for the selected class.  
- Reads usernames, emails, and tags from `extra_data`
- Works even if `owner_id` is null  
- Returns missing-student list  
- Supports `?debug=1` for raw data

---

## File Summary

| File | Description |
|------|--------------|
| `ClassExplorer.php` | Main UI and logic for viewing classes |
| `fetch_filters.php` | Handles class and teacher list population |
| `fetch_class_devices.php` | Handles device list and debug output |
| `theme.css` | Shared site styling |
| `mascot.png` | MUSKY mascot graphic |

---

## Logging Example
```
[2025-10-18 01:41:22] <fetch_devices> ✅ Connected to Nora; class=9601325f...
[2025-10-18 01:41:22] <fetch_devices> Returned 15 device rows; missing 3
[2025-10-18 01:41:22] <fetch_devices> User=guy.example.1@example.org | Action=Device Report | Class="HOMEROOM 001"
```

---

## Security Notes
- All access paths require authentication
- Spoofing restricted to admin users
- Direct external access returns HTTP 403

---

## Dependencies
- PHP ≥ 8.1
- PDO (MySQL/MariaDB)
- MUSKY framework core modules

---

## Maintainers
**SmillieWare / MUSKY Project**  
Maintained by *Jesse Smillie* and *ChatGPT*
