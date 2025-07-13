# RadiusUserIDLookup.php

## 🔍 What This Module Does

This tool provides a detailed, human-readable view of **RADIUS login activity** for any specified user ID. It helps IT and Help Desk staff quickly identify:

- **When** a user authenticated  
- **Which access point** (AP) they connected to  
- **What SSID** they used  
- **How much data** was transferred  
- And, optionally, **Mist-enriched context** like AP names

---

## ⚙️ How It Works

### 1. API Query
- On first load, the script pulls live RADIUS log data using a secure API token and URL stored in a local `.secrets` JSON file.
- It queries using the specified `?uid=username` passed in the URL (or loaded from session memory).

### 2. Caching
- Data is stored in PHP `$_SESSION` memory for performance. This means reloading the page avoids unnecessary API calls unless a new user is requested.

### 3. Data Enrichment
- The raw RADIUS data contains MAC addresses like:
  ```
  Called_Station_Id: "74-3E-2B-71-FF-18:GSD"
  ```
- The script extracts the **WAP MAC** and cross-references it against a Mist-generated JSON map (`bssid_to_apname_map.json`) to display a friendly AP name like `Ramsey-FrontHallway`.

---

## 🧪 Debug Mode

Clicking the **🐛 Show Debug Info** button reveals:
- Hidden columns: IP address, WAP MAC, Client name
- A formatted JSON blob of the raw log data

This mode is useful for advanced troubleshooting or confirming what Mist data matched.

---

## 🟡 Badge Legend

Each row in the table begins with a colored dot:
- 🟡 = Authentication (Packet_Type 1)  
- 🟢 = Accounting (Packet_Type 4)  
- 🔘 = Other (everything else)

---

## 🔐 Access and Security

This script is:
- Located inside the `DeviceManager/Modules/` path  
- Intentionally named with `.DISABLED` so it's ignored by the autoloader  
- Only works properly when launched from inside the full DeviceManager UI  

It **should not be linked to directly**, and it relies on internal session state.

---

## 🗂 Related Files

- `.secrets` — Stores `RADIUS_TOKEN` and `RADIUS_URL_TEMPLATE`
- `bssid_to_apname_map.json` — Mist-exported map of BSSID → Friendly AP name
- `mac_to_apname_map.json` — (Optional) fallback MAC → AP mapping
- JSON debug maps are loaded from:  
  `../../DataSources/`

---

## 🛠 Future Enhancements

- Add "mask for newbz" mode to simplify UI for Level 1 techs  
- Highlight unknown or ghost MAC addresses  
- Export to CSV/JSON  
- Mist coordinate overlays

---

Maintained by the 🦡 Musky-DeviceManager team.
