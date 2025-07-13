# decode_tags.php

## 🧾 Purpose

`decode_tags.php` is a utility script that maps obscure RADIUS field codes to human-readable labels.

For example:
```
NP_Policy_Name     => Network Policy Name
Framed_IP_Address  => Assigned IP
Calling_Station_Id => Client MAC
```

---

## ⚙️ How It Works

- Uses a hardcoded tag-to-label map (`$decodeTags`)
- Other scripts (like the main `index.php` interface) include this to translate headers
- Helps keep the RADIUS log display user-friendly

---

## 📍 Location

```
web/DeviceManager/decode_tags.php
```

You can include it in any module or tool that needs readable field names.

---

## ✅ Sample Usage

```php
require_once("decode_tags.php");
$label = $decodeTags['NAS_IP_Address'];  // "RADIUS Server IP"
```

---

This keeps your interface human-friendly while still showing raw data when needed.
