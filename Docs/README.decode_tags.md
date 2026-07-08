# decode_tags.php

## Purpose

`web/DeviceManager/decode_tags.php` renders human-friendly tag text in DeviceManager views.

Output compatibility is intentionally preserved:
- heading remains `Device Tags:`
- known tags render friendly text
- unknown tags render `Unknown Tag: <tag>`

## Current Data Source

Tag translation values are now managed in MariaDB via `nora_config_store`:
- `ConfigGroup = TagDecode`
- `ConfigSet = DEFAULT`
- `ConfigKey = raw tag`
- `ConfigValue = display text`

`decode_tags.php` now loads translations through shared helpers and keeps fallback compatibility.

## Files Involved

```text
web/DeviceManager/decode_tags.php
Functions/MuskyTagDecode.php
web/admin/NoraWeb.TagDecode.php
```

## Admin Management Page

Use:

```text
web/admin/NoraWeb.TagDecode.php
```

The page supports:
- migrate legacy defaults into DB
- add/update tag decode entries
- delete tag decode entries

All changes are activity logged as Musky actions.

## Migration Notes

Legacy hardcoded defaults were migrated into `TagDecode/DEFAULT` rows.

Seeding is idempotent:
- first run inserts missing rows
- later runs update existing rows without creating duplicates

## Fallback Behavior

If DB config is unavailable, helper code still supplies default mappings so UI behavior remains stable.

If a tag has no mapping, raw tag output is shown as unknown.
