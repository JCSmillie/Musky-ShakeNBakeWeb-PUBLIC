# Loaner Device Manager

The **Loaner Device Manager** is a browser-based internal tool used by Gateway IT to manage loaner iPads across departments. It provides real-time visibility into device states, enables mass actions like remote wiping or assignment verification, and supports operational IT workflows for maintenance and troubleshooting.

---

## 📁 Project Location

```
<THISPROJECT>/web/Loaners/
```

---

## 🎯 Purpose

This tool helps IT staff:

- Identify which iPads are available, in use, misassigned, or need wiping
- Perform mass actions like assignment verification or remote wipe
- View up-to-date device metadata pulled from Mosyle and IIQ
- Submit issue reports with automatic screenshots and contextual data

---

## 🧭 Quick Start Guide

### 1. **Launch the Tool**

Open `index.php` in a browser to access the dashboard.

### 2. **Choose a Pool**

Use the dropdown menu in the sidebar to select a pool (e.g., `CSE`, `RAM`, `UP`). Pools represent logical groups of iPads by department or location.

### 3. **Review Device Table**

After selecting a pool, you'll see a table with:

- **Asset Tag**
- **Assigned User**
- **Last Check-In Time**
- **iOS Update Status**
- **On/Off Campus Status**
- **Assignment Health** (e.g., `Good`, `Mismatch`, `Unassigned`)

### 4. **Perform Actions via Sidebar**

Options include:
- ✅ **Verify Assignment** — validate if user/device pairings are correct
- 💣 **Mass Wipe Selected** — remotely wipe selected iPads
- 🔄 **Reload Data**
- 🪄 **More Data** — reveal additional technical details (UDID, Serial, etc.)
- 🐞 **Toggle Debug Info** — reveal backend parsing and fetch logs
- ✉️ **Message User** *(planned for future)*

### 5. **Submit a Problem Report**

Click **"Problem?"** to:
- Enter a short issue description
- Automatically capture a screenshot
- Attach asset, serial, IP, user, and pool info
- Send the package via email and/or Slack (if configured)

### 6. **Switch Theme**

Choose from:
- Light Mode
- Dark Mode
- Musky Mode (custom green aesthetic)

---

## 🔧 Implementation Details

- **Live Loading**: Background fetch shows progress via animated spinner and `MUSKY-BACKCHANNEL` updates
- **CSV Parsing**: Device data is only parsed after a clear delimiter line (`====================`)
- **Security**: Backend uses safe background processes — no direct `shell_exec` from frontend
- **Dynamic UI**: Sidebar buttons are dynamically enabled based on selections and state
- **Styling**: Clean, responsive layout with animation support and theming

---

## 📦 File Structure

| File                        | Description |
|-----------------------------|-------------|
| `index.php`                 | Primary frontend and view logic |
| `loaner_constants.php`      | Contains pool mappings and global constants |
| `loaner_utils.php`          | Loads and processes device CSV data |
| `loaner_helpers.php`        | Generates HTML fragments and formats content |
| `fetch_loanerdata.php`      | AJAX-accessible PHP that returns validated device data |
| `sidebar.php`               | HTML + JS for the sidebar controls |
| `loaner_styles.css`         | CSS for table, sidebar, buttons, and themes |
| `LoanerData.sh`             | External data source script that interfaces with Mosyle/IIQ |

> **Note:** All `.sh` shell scripts (e.g., `LoanerData.sh`) live under:  
> ```
> <THISPROJECT>/Functions/
> ```

---

## 🚨 Troubleshooting Tips

- **No data shown?** — Try reselecting a pool or clicking **Reload Data**
- **Backend errors?** — Ensure `LoanerData.sh` is executing and producing valid CSV
- **Need more detail?** — Use **Toggle Debug** to inspect backend fetch logs

---

## 📌 Known Notes

- `LoanerData.sh` must output a valid CSV with a proper marker (`====================`)
- `MUSKY-BACKCHANNEL` lines are shown in frontend but excluded from parsing
- Problem reports depend on Slack and/or email integration being correctly configured
- Table currently reloads on full fetch; AJAX-based in-place updates are planned for RC2

---

## 🏁 Version: RC1 (Released April 14, 2025)

### ✅ Major Features

- Spinner and real-time loading feedback via `MUSKY-BACKCHANNEL`
- Background-safe CSV parsing pipeline
- Fully redesigned sidebar UI and workflow buttons
- Secure remote wipe and assignment check features
- Problem reporting with screenshot and full metadata
- Multiple theme options with custom CSS

> Full details available in `Loaner_RC1_Changelog.txt`.

---

## 👥 Maintainers

Developed and maintained by the Gateway IT Musky Dev Team.

---
