# Nora Errands Reference

Last reviewed: 2026-05-01

This is the callable errand catalog for Nora's `nora_errands` worker. It is based
on the current dispatch code in `NoraWork.RunErrands.php` and the subhandlers in
`SubHandlers/`.

## Worker Model

The worker processes one row at a time from `nora_errands` where:

| Field | Expected value |
| --- | --- |
| `Status` | `submitted` |
| `TaskPriority` | Eligible for the current scheduler window |

Priority windows:

| Priority | When it runs |
| --- | --- |
| `-1` | Maintenance hours only, 23:00-04:59 |
| `0` | Top of the hour |
| `1` | Hour and half-hour |
| `2` | Quarter-hour marks |
| `3` | Five-minute marks |
| `4` | Every minute |
| `5` | Immediate whenever the worker sees it |

Rows with `Status='Unknown'` older than 15 minutes are requeued to `submitted`.
Rows are processed highest priority first, then oldest `SubmissionDateTime`.

The worker dispatches every request column that is non-empty and not `FALSE`.
For normal use, queue one primary request type per row unless you intentionally
want a combined action.

| Request field | Handler | How action is selected |
| --- | --- | --- |
| `MOSBasicRequest` | `SubHandlers/MOSBasicHandler.php` | The value of `MOSBasicRequest` |
| `SlackRequest` | `SubHandlers/NoraSlackHandler.php` | Set exactly `TRUE`; payload is in `ExtraDataField01` |
| `IIQRequest` | `SubHandlers/NoraSubHandler_IIQ.php` | `ExtraDataField05` |
| `NoraRequest` | `SubHandlers/NoraSubHandler_Nora.php` | `ExtraDataField01` |
| `CustomRequest` | `SubHandlers/NoraSubHandler_Custom.php` | The value of `CustomRequest` |

## Common Fields

These fields are shared by most errands:

| Field | Use |
| --- | --- |
| `TaskPriority` | Scheduler priority. Use `5` for immediate work. |
| `Status` | Queue state. New errands should start as `submitted`. |
| `Submitter` | System, user, or workflow that queued the errand. |
| `DeviceSerial` | Device serial. Required by all MOSBasic errands and many IIQ errands. Use `N/A` only for non-device errands. |
| `UDID` | Required by single-device Mosyle bulk actions. Use `N/A` only for non-device errands. |
| `DeviceOwner` | Optional display/audit value. |
| `ExtraDataField01` | Primary payload. Often JSON. |
| `ExtraDataField02` | Secondary payload or flags. |
| `ExtraDataField03` | Third payload value for actions that need it. |
| `ExtraDataField04` | Usually written by handlers as a user-facing result. |
| `ExtraDataField05` | IIQ action name. |
| `ExtraDataField06` | Usually written by handlers as raw output or audit JSON. |

The standalone `NoraWork.SubmitErrand.php` CLI only exposes `--extra1` and
`--extra2`. Errands that require `ExtraDataField03` or `ExtraDataField05`, and
custom verbs such as `CustomRequest=SkywardFees`, must be queued by Musky/API,
a purpose-built script, or a direct DB insert.

## MOSBasic Errands

Set `MOSBasicRequest` to one of the action names below.

All MOSBasic errands require:

| Field | Required value |
| --- | --- |
| `MOSBasicRequest` | Action name |
| `DeviceSerial` | Non-empty serial number |

If `DeviceSerial` is empty, `MOSBasicHandler.php` tries to read `serial` or
`serial_number` from `ExtraDataField01` JSON before failing.

MOSBasic config dependencies:

| Dependency | Used by |
| --- | --- |
| `/usr/local/SmillieWare/mosbasic/mosbasic` | Classic lost-mode commands |
| `nora_config.json` `MoysleAPI` or `MosyleAPI` with `MOSYLE_API_key` | Extended Mosyle API errands |
| `nora_api_tokens.api_name='mosyle'` bearer token | Extended Mosyle API errands |
| `device_history` | Multi-device UDID lookup and tag lookup |
| `owners.mosyle_id` | `ASSIGNME` validation |

### Classic MOSBasic CLI Actions

These shell out to the MOSBasic CLI with `DeviceSerial`.

| `MOSBasicRequest` | MOSBasic command | Extra data |
| --- | --- | --- |
| `LOSTON` | `lostmodeon` | None required beyond `DeviceSerial` |
| `LOSTOFF` | `lostmodeoff` | None required beyond `DeviceSerial` |
| `LOSTSOUND` | `annoy` | None required beyond `DeviceSerial` |
| `LOSTLOCATIONREQUEST` | `locate` | None required beyond `DeviceSerial` |

### Extended Mosyle API Actions

These are handled directly through the Mosyle API and do not call the MOSBasic
CLI.

| `MOSBasicRequest` | What it does | Required extra data |
| --- | --- | --- |
| `ASSIGNME` | Assigns a device to a Mosyle user. | `ExtraDataField01` JSON: `{"id":"<mosyle_user_id>","serial_number":"<serial>"}` |
| `SETMETAGS` | Adds or replaces Mosyle tags for a device. | `ExtraDataField01` JSON: `{"serial":"<serial>","tags":"<tag-or-csv-tags>"}` |
| `WIPEME` | Bulk wipe via Mosyle `wipe_devices`. | Single-device: `UDID`. Multi-device: `ExtraDataField01` JSON array or `{"serials":["SER1","SER2"]}`. |
| `REBOOTME` | Bulk restart via Mosyle `restart_devices`. | Same single/multi data pattern as `WIPEME`. |
| `LIMBOME` | Move devices to Mosyle limbo via `change_to_limbo`. | Same single/multi data pattern as `WIPEME`. |
| `OFFME` | Shut down devices via `shutdown_devices`. | Same single/multi data pattern as `WIPEME`. |
| `CLEARCOMMANDSALL` | Clear all Mosyle commands via `clear_commands`. | Same single/multi data pattern as `WIPEME`. |
| `CLEARCOMMANDSPENDING` | Clear pending Mosyle commands via `clear_pending_commands`. | Same single/multi data pattern as `WIPEME`. |
| `CLEARCOMMANDSFAILED` | Clear failed Mosyle commands via `clear_failed_commands`. | Same single/multi data pattern as `WIPEME`. |

`ASSIGNME` normalizes `id` if an email address is supplied, keeping only the
local-part before `@` or `%40`.

`SETMETAGS` modes:

| Mode | How to request it | Behavior |
| --- | --- | --- |
| Add | Leave `ExtraDataField02` blank or without `REPLACETAGS` | Reads current tags from `device_history`, appends the incoming tag if missing, then sends the merged tag string. |
| Replace | Put `REPLACETAGS` anywhere in `ExtraDataField02` | Sends the supplied `tags` value as the full tag string. In replace mode, the `tags` key may intentionally be empty to clear tags. |

Bulk action modes:

| Mode | Required fields |
| --- | --- |
| Single device | Non-empty `DeviceSerial` and `UDID`. |
| Multi-device | Non-empty `DeviceSerial` plus `ExtraDataField01` as `["SER1","SER2"]` or `{"serials":["SER1","SER2"]}`. Nora resolves each UDID from `device_history`. |

`WIPEME` supports optional flags in `ExtraDataField02`:

| Flag | Effect |
| --- | --- |
| `NORTS` | Disable Return to Service. |
| `NOVPP` | Do not revoke VPP licenses. |

Flags may be combined, for example `NORTS,NOVPP`.

## Slack Errands

Set `SlackRequest` exactly to `TRUE`.

| Field | Required value |
| --- | --- |
| `SlackRequest` | `TRUE` |
| `ExtraDataField01` | JSON payload |

Payload:

```json
{
  "slack_channel": "#mdm_activity",
  "custom_message": "Device action completed."
}
```

`slack_channel` is optional if `SLACK.DEFAULT_CHANNEL` is configured.
`custom_message` defaults to `(no message)` if blank.

Config dependencies in `nora_config_store`:

| ConfigGroup | ConfigKey | Required |
| --- | --- | --- |
| `SLACK` | `ERRAND_ENABLED` | Yes |
| `SLACK` | `BOT_TOKEN` | Yes |
| `SLACK` | `DEFAULT_CHANNEL` | Yes, unless payload provides `slack_channel` |
| `SLACK` | `USERNAME` | No, defaults to `NoraBot` |

Legacy `nora_config.json` `slack_app` values can be auto-bootstrapped into
`nora_config_store` if the DB rows are missing.

## IIQ Errands

Set `IIQRequest` to `TRUE` and put the action in `ExtraDataField05`.

All IIQ errands require:

| Field | Required value |
| --- | --- |
| `IIQRequest` | `TRUE` |
| `ExtraDataField05` | One of the supported IIQ actions |

The IIQ subhandler also requires IIQ errands to be enabled:

| ConfigGroup | ConfigKey | Required |
| --- | --- | --- |
| `IIQ` | `ERRAND_ENABLED` | Yes |

The compatibility fallback key `IIQ_ERRAND_ENABLED` is also honored.

Supported actions:

| `ExtraDataField05` | What it does | Required extra data |
| --- | --- | --- |
| `CREATE` | Creates an IIQ ticket. | `ExtraDataField01` JSON with `ForUsername`, `AssetTag`, `Issue`, `IssueDescription`. |
| `ADDNOTE-MUSKYCHARGES` | Adds the standard Musky charges internal note to a ticket. | `ExtraDataField01=<note text>`, `ExtraDataField02=<TicketId>`. |
| `ADDNOTE-CUSTOM` | Adds a custom internal note to a ticket. | `ExtraDataField01=<note text or note JSON>`, `ExtraDataField02=<TicketId>`. |
| `RESOLUTE-MUSKYCHARGES` | Adds the Musky charges resolution action to a ticket. | `ExtraDataField02=<TicketId>`, `ExtraDataField03=<charge amount>`. |
| `ASSIGN-DEVICE` | Assigns an IIQ asset to a user. | `ExtraDataField02=<serial>`, `ExtraDataField03=<user GUID, email, or username>`. |
| `UNASSIGN-DEVICE` | Clears the owner from an IIQ asset. | `ExtraDataField02=<serial>`. |
| `CHECKIN-DEVICE` | Adds an IIQ asset verification/check-in record. | `ExtraDataField02=<serial>`, `ExtraDataField03=<verification comment or note JSON>`. |
| `RETIRE-DEVICE-IIQ` | Runs IIQ retirement steps: verify, clear owner, set retired status, set retired location/room. | `ExtraDataField02=<serial>`. |

`CREATE` payload:

```json
{
  "ForUsername": "jsmith@example.org",
  "AssetTag": "GSD12345",
  "Issue": "Hardware Repair",
  "IssueDescription": "Device has a cracked screen."
}
```

Note-style fields for `ADDNOTE-CUSTOM` and `CHECKIN-DEVICE` may be plain text
or JSON. Accepted JSON keys are:

| Key | Behavior |
| --- | --- |
| `note` | Used as note text |
| `message` | Used as note text |
| `text` | Used as note text |
| `body` | Used as note text |
| `comment` | Used as note text |
| `comments` | Used as note text |
| `lines` | Array of strings, joined with newlines |

Additional IIQ config dependencies:

| ConfigGroup | ConfigKey | Used by |
| --- | --- | --- |
| `IIQ_CHECKIN_DEVICE` | `VERIFIED_BY_USER_ID` | `CHECKIN-DEVICE` |
| `IIQ_CHECKIN_DEVICE` | `LOCATION_ID` | `CHECKIN-DEVICE` |
| `IIQ_RETIRE_DEVICE` | `STATUS_ID` | `RETIRE-DEVICE-IIQ` |
| `IIQ_RETIRE_DEVICE` | `LOCATION_ID` | `RETIRE-DEVICE-IIQ` |
| `IIQ_RETIRE_DEVICE` | `ROOM_ID` | `RETIRE-DEVICE-IIQ` |
| `IIQ_RETIRE_DEVICE` | `VERIFIED_BY_USER_ID` | Optional for `RETIRE-DEVICE-IIQ`; code has a fallback user ID. |

`RESOLUTE-MUSKYCHARGES` reads legacy IIQ API settings from `nora_config.json`
under `iiqAPI`: `IIQsiteid`, `IIQbaseurl`, and `iiqAPItoken`.

## Internal Nora Errands

Set `NoraRequest` to `TRUE`. The action name is `ExtraDataField01`; arguments
go in `ExtraDataField02`.

| `ExtraDataField01` | What it does | Required extra data |
| --- | --- | --- |
| `MOSYLE_PULL` | Intended to run a Mosyle pull script. | `ExtraDataField02=<script args>`. Current code points to `Nora.MosylePull.php`, which is missing in this checkout, so this action will fail until that file exists or the handler is corrected. |
| `REFRESH_MOSYLE_TOKEN` | Runs `NoraSeed.MosyleTokenUpdater.php`. | No action-specific extra data. |
| `USERCHECK` | Runs `NoraQuery.UserCheck.php` and marks the errand complete only if it returns `TRUE`. | `ExtraDataField02=<username-or-email>`. |
| `INV_LOOKUP` | Runs `NoraFeed.MosylePullBySerial.php` to pull/import Mosyle inventory by serial or group. | `ExtraDataField02=<serials>` or `ExtraDataField02=--group "GroupName"`. |
| `IIQUSERSYNC` | Runs `NoraSeed.StudentIDfromIIQ.php` to fill owner district ID data from IIQ. | `ExtraDataField02=<email address>`. |
| `IIQTICKETSYNC` | Runs `NoraSeed.TicketInfofromIIQ.php` to sync IIQ ticket tidbits into Nora tables. | `ExtraDataField02=<ticket number>`. |

Internal Nora dependencies vary by script. Common requirements are
`nora_config.json`, Nora DB access, Mosyle token/config for Mosyle actions, and
IIQ config for IIQ sync actions.

## Custom Errands

The current custom subhandler only dispatches one custom verb.

### SkywardFees

Set `CustomRequest` to `SkywardFees`.

| Field | Required value |
| --- | --- |
| `CustomRequest` | `SkywardFees` |
| `ExtraDataField01` | Verb string. Current docs and usage use `create`; the helper logs the verb but does not branch on it. |
| `ExtraDataField02` | JSON payload that becomes the Skyward query string. |

Typical payload:

```json
{
  "StudentNumber": "123456",
  "FeeCode": "TRIMPORT",
  "Amount": 10,
  "Comment": "Charge applied by NORA"
}
```

Config dependencies in `nora_config_store`:

| ConfigGroup | ConfigKey | Required |
| --- | --- | --- |
| `SKYWARD` | `ERRAND_ENABLED` | Yes |
| `SKYWARD` | `BASE_URL` | Yes |
| `SKYWARD` | `AUTH_TOKEN` | Yes |

Compatibility fallback keys `SKYWARD_ERRAND_ENABLED`, `SKYWARD_BASE_URL`, and
`SKYWARD_AUTH_TOKEN` are honored. Older `nora_config.json` `SkywardFees`
settings can be auto-bootstrapped into `nora_config_store`.

## Workflow Scripts That Queue Errands

These scripts are not single errand actions, but they create one or more
`nora_errands` rows.

### Device Retirement

`NoraTeamWork.RetireDevice.php` queues a retirement sequence by serial or asset
tag:

```bash
php NoraTeamWork.RetireDevice.php <serial-or-asset-tag> --debug
php NoraTeamWork.RetireDevice.php --serial <serial> --debug
php NoraTeamWork.RetireDevice.php --asset-tag <asset-tag> --debug
```

Queued steps:

| Step | Queued errand |
| --- | --- |
| IIQ retirement | `IIQRequest=TRUE`, `ExtraDataField05=RETIRE-DEVICE-IIQ`, `ExtraDataField02=<serial>` |
| Lost mode off | `MOSBasicRequest=LOSTOFF` |
| Wipe | `MOSBasicRequest=WIPEME`, `ExtraDataField02=NORTS` |
| Retirement tag | `MOSBasicRequest=SETMETAGS`, tag from `IIQ_RETIRE_DEVICE.RETIRE_TAG` |

## Present But Not Currently Reachable

These names appear in helper files or older notes, but are not currently callable
from `NoraWork.RunErrands.php` through the active subhandler dispatch paths.

| Name | Why it is not currently callable |
| --- | --- |
| `CustomRequest=Email` | `SubHandlers/NoraHelper_Email.php` exists, but `NoraSubHandler_Custom.php` only dispatches `SkywardFees`. |
| `LOSTCHARGER` | Mentioned in IIQ header/older notes, but not in the current IIQ action allow-list. |

## Source Files

Primary files reviewed:

| File | Role |
| --- | --- |
| `NoraWork.RunErrands.php` | Queue selection and handler dispatch. |
| `NoraWork.SubmitErrand.php` | Standalone CLI submitter limitations and common insert fields. |
| `SubHandlers/MOSBasicHandler.php` | MOSBasic action dispatch. |
| `SubHandlers/NoraHelper_MOSBasicExtraErrands.php` | Extended Mosyle API errands. |
| `SubHandlers/NoraSlackHandler.php` | Slack errand behavior and config. |
| `SubHandlers/NoraSubHandler_IIQ.php` | IIQ action allow-list and field requirements. |
| `SubHandlers/NoraSubHandler_Nora.php` | Internal Nora action allow-list. |
| `SubHandlers/NoraSubHandler_Custom.php` | Custom action dispatch. |
| `SubHandlers/NoraHelper_SkywardFees.GSD.php` | Skyward payload/config behavior (GSD-only module). |
| `NoraTeamWork.RetireDevice.php` | Compound retirement workflow queuing. |
