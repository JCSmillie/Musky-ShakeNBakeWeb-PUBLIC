# ExampleVariables4Modules.php

## 📘 Purpose

This is a demonstration-only module showing the **types of variables** that are passed to modules from the DeviceManager platform after parsing a RADIUS log session.

It is not functional — its only job is to show developers and tech staff what data they can expect to work with when building their own modules.

---

## ✅ What's Inside

Example output includes:
- `DeviceMACAddress`
- `DeviceSerialNumber`
- `UserName`
- `UserAgent`
- `LastSeen`
- and more...

These values are typically parsed from the selected session and handed to modules via internal logic. This script renders them into a readable format for inspection.

---

## 🧪 Use Case

- You're building a module and want to know:
  > "What variables do I get for free?"

Run this script and you'll see the exact structure.

---

## 🔐 Access Notes

- Lives in `/DeviceManager/Modules/`
- Disabled by default (`.DISABLED`)
- Intended for internal use only — not for end users

---

Maintained by the 🦡 Musky-DeviceManager team.
