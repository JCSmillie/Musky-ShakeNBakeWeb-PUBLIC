# RetireiPad.php

## 📱 What This Module Does

This module automates the process of **retiring or offboarding an iPad** from your environment.

It is intended for use by Help Desk or tech staff when a student or staff member:
- Leaves the district
- Returns a damaged or lost iPad
- Upgrades their hardware

---

## ⚙️ How It Works

When this tool is run:

1. 🔐 **Authorization & Session Checks**  
   It ensures the user has a valid session via the DeviceManager platform.

2. 📋 **Looks Up the Device**  
   Based on a provided serial number, asset tag, or MAC address, it identifies the iPad record.

3. 🧹 **Performs Cleanup Tasks**  
   This may include:
   - Removing from management tools (Jamf, Intune, etc.)
   - Updating inventory flags
   - Marking the device as retired or to be recycled
   - Optional email or Slack notification

4. 🧾 **Records the Retirement**  
   Logs or database entries may be updated to show when and why the device was retired.

---

## 🔐 Access and Safety

This script is:
- Located in `DeviceManager/Modules/`
- Disabled by default (`.DISABLED`) so it doesn’t run until fully reviewed
- Expected to be invoked **only inside DeviceManager**

---

## 🧪 Status

This module may still require:
- Connection to real device inventory or MDM system
- Logging integration
- Confirmation dialogs or safety nets

---

## 📂 Related Files

- Device metadata (used for lookup) may come from:
  - Jamf or other MDM exports
  - CSV import, SQL backend, or Mist API
- Logs or dashboards that track retired assets

---

## 🛠 Future Features

- Add confirmation screens before final retire
- Slack/email audit trail
- Auto-assign replacement loaner tracking

---

Maintained by the 🦡 Musky-DeviceManager team.
