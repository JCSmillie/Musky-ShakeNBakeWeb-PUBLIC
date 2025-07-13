# 🧰 Utility Scripts — README

This directory contains standalone utility scripts used for integration, data gathering, and processing tasks across various APIs and systems. Each tool is self-contained and documented below in a consistent format.

---

## 📡 MistDataGrab.Step1.DumpData.php

**Written: JCS - 7/10/25 **

**Purpose:**  
Pulls all relevant inventory and stats data from Mist's cloud API across all accessible sites in the organization.

**What it does:**  
- Prompts for Mist API Token and Org ID
- Discovers all Sites under that Org
- Queries each site for:
  - Access Point inventory
  - Device statistics
  - Client connection history
- Stores output in versioned JSON files per site
- Creates `.breadcrumb` file with:
  - Token and Org info
  - Dump summary for reference or automation

**Output files:**
- `org_inventory.json`
- `stats_devices_<site_id>.json`
- `stats_clients_<site_id>.json`
- `site_list.json`
- `.breadcrumb`

---

## 🧱 MistDataGrab.Step2.BuildBSIDMApfromDumps.php

**Written: JCS - 7/10/25 **

**Purpose:**  
Parses dumped Mist data to build mapping files used in authentication and wireless debugging tools.

**What it does:**  
- Loads previously saved dump files from Step 1
- Extracts all base AP MACs and expands out BSSID virtual MAC ranges (e.g., `10–1F`)
- Associates:
  - MAC → AP Name
  - BSSID → AP Name
  - BSSID → Channel (if known)
  - BSSID → IP (future-ready)
- Updates `.breadcrumb` with summary

**Output files:**
- `mac_to_apname_map.json`
- `bssid_to_apname_map.json`
- `bssid_to_channel_map.json`
- `.breadcrumb`

---

## 📘 .breadcrumb (auto-generated)

**Written: JCS - 7/10/25 **

**Purpose:**  
Lightweight metadata tracker used by all utility scripts in this directory.

**What it contains:**
- Most recent API token used
- Org/Site identifiers
- Summary of pulled data (counts, sources)
- Useful for chaining multi-step tools or scripting automation

---

---

## 🛠️ Mist API Utility Scripts (V2)

These tools are part of the Musky-ShakeNBakeWeb system and are designed to extract, enrich, and correlate data from the Mist API for integration into the broader Musky-DeviceManager platform.  I do beleive that there is far more data pickable from the files I generated and that there is more API to explore..  MAybe another late night we'll try again.  --JCS 7/11/25
**Written: JCS - 7/10/25 **
---

### 📁 GenerateBreadcrumb.php
**Purpose:**  
Creates a `.breadcrumb` file that contains:
- `token`: Your Mist API Token  
- `org_id`: Your Mist Org ID  

**Usage:**  
```bash
php GenerateBreadcrumb.php
```

Follows an interactive prompt format. Required before running any other MistDataGrabV2 scripts.

---

### 📥 MistDataGrabV2.Step1.DumpData.php
**Purpose:**  
Connects to Mist API and recursively dumps all relevant site, device, client, map, and radio insight data into `raw_dumps/`.

**Usage:**  
```bash
php MistDataGrabV2.Step1.DumpData.php
```

Automatically discovers endpoints including:
- `stats/clients`, `clients/search`, `clients/count`
- `devices`, `stats/devices`
- `insights/rogues`, `insights/honeypot`
- `maps`, `stats/maps/:map_id/clients`
- `stats/devices/:device_id/clients`, etc.

---

### 🧠 MistDataGrabV2.Step2.BuildRadiusEnrichmentData.php
**Purpose:**  
Processes `raw_dumps/` and generates enrichment JSON maps to support Radius-based device tracking.

**Output Files:**
- `mac_to_apname_map.json`
- `bssid_to_apname_map.json`
- `bssid_to_channel_map.json`
- `client_lastseen_map.json`

**Usage:**  
```bash
php MistDataGrabV2.Step2.BuildRadiusEnrichmentData.php
```

---

### 🧭 MistDataGrabV2.Step3.BuildRadiusEnrichmentData.php
**Purpose:**  
An evolved alternative to Step2, uses extended endpoint data to build a **more complete** enrichment set for RadiusUserIDLookup.

**Same output files as Step2**, but supports:
- AP name resolution
- BSSID mapping
- Last seen data from clients/search and deeper per-device stats.

**Usage:**  
```bash
php MistDataGrabV2.Step3.BuildRadiusEnrichmentData.php
```

---

### 🛰️ MistDataGrabV2.Step4.BuildRadioObservationMap.php
**Purpose:**  
Parses `insights_honeypot` data across all sites and builds:
- `radio_observation_map.json`: Maps observed BSSIDs + SSIDs + AP locations + channel data.

**Usage:**
```bash
php MistDataGrabV2.Step4.BuildRadioObservationMap.php
```

---

### 🗺️ MistDataGrabV2.Step5.BuildClientLocationMap.php
**Purpose:**  
Correlates device MACs with `x`, `y`, `map_id`, and AP info from map-level and device-level `clients` stats.

**Output:**
- `client_location_map.json` – provides best guess at client physical location.

**Usage:**
```bash
php MistDataGrabV2.Step5.BuildClientLocationMap.php
```

---

## 🔮 Guidelines for Future Tools

When adding new scripts to this directory:
- Follow the format: `Feature.Step#.Action.php`
- Include doc blocks and echo progress clearly
- Output structured JSON when possible
- Update this README with a new section using the same layout:
  - Name
  - Purpose
  - What it does
  - Output files

This ensures long-term usability and teamwork readiness.
---
## ✍️ Authors

Maintained by Jesse and friends with support from ChatGPT. Built out of need, curiosity, and a desire to leave breadcrumbs for the next brave soul.
---