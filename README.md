<img src="https://github.com/JCSmillie/Musky-ShakeNBakeWeb-PUBLIC/blob/main/mascot.png?raw=true" alt="Musky our fearless mascot." width="200"/>

# What is Musky?

Musky is a home-grown support tool built to help school IT staff, help desk
students, and other front-line support people work iPad and device issues
quickly without needing to live inside every backend system all day.

The project started as a practical answer to real queue pain:

1. assign or check a device in the ticket/workflow system
2. jump to Mosyle to finish the device-side work
3. get blocked when the user or device data is not ready yet

The earliest version was just a couple of shell-driven web helpers. Musky is
the larger follow-up: a web front end that can read live device state, kick off
trusted backend workflows, and present the result in a way that is useful to
staff and approachable for student helpers.

Musky went live at Gateway School District in April 2025. The current tree is
best described as a rolling `0.8.x` beta. It is very real, very used, and still
being actively hardened and cleaned up as it grows.

## Current status

- Current repo version: `0.8.1.1`
- Primary deployment style today: LAN or tightly restricted internal access
- Current auth model: Google SSO plus an optional legacy/local fallback path
- Current data/action backbone: Nora-backed lookups, errands, and admin tools

Musky is no longer just "a page to find an iPad." It is now a small suite of
support surfaces around device lookup, classroom context, loaner visibility,
charge workflows, inventory, and admin diagnostics.

## What Musky does now

### Device support

- `web/DeviceManager/index.php`
  - Nora-first asset-tag lookup flow
  - refresh via `INV_LOOKUP` errands
  - wipe, lost mode, sound, location, reboot, and related action launchers
  - ticket/problem intake entrypoints
- `web/DeviceManager/MyDevices.php`
  - self-service "devices assigned to me" view
- `web/public/DeviceVitals.php`
  - public serial-based facts-only view with anonymous-safe device vitals
  - authenticated users get a richer sidebar/action experience

### Classroom and loaner views

- `web/ClassExplorer/ClassExplorer.php`
  - classroom-focused view for seeing devices tied to classes/teachers
- `web/LoanerExplorer/LoanerExplorer.php`
  - Loaner Explorer V2
  - NORA-backed pool/device view
  - replaces the older `web/Loaners/` and `web/loaner_constants.php` model

### Admin and operational tooling

- `web/admin/NoraWeb.Dashboard.php`
  - Nora queue and system status dashboard
- `web/admin/NoraWeb.ErrandsList.php`
  - errands monitor / console
- `web/admin/NoraWeb.ConfigStore.php`
  - DB-backed active config management
- `web/admin/NoraWeb.TagDecode.php`
  - tag decoding admin UI
- `web/admin/MuskyActivityDashboard.php`
  - Musky usage and activity monitoring
- `web/Inventory/index.php`
  - admin inventory dashboard

### PANDA and charge workflows

- `web/PANDA/`
  - charge queue
  - charge history
  - coverage rules
  - charge decisions and follow-through actions

### User/session features

- Google SSO support
- MUSKY session enforcement through `web/check_access.php`
- theme/user preference panes under `web/Preferences/`
- per-user activity tracking and login/page/action logging

### Optional enrichment

- Mist/Wi-Fi enrichment helpers and data-map tooling under:
  - `Functions/Utility/`
  - `web/DataSources/`

## What changed since the older 2025 README snapshot

The September 2025-era README was behind the actual code. Since then, Musky
picked up a good chunk of new surface area and cleanup work:

- Nora-first lookup/refresh flow is now the normal device lookup model
- Loaner Explorer V2 replaced the older loaner manager approach
- Class Explorer is live
- My Devices and public DeviceVitals are both real pages now
- PANDA expanded into a fuller charge workflow area
- admin dashboards now include Nora status, errands, activity, inventory, and
  config-store management
- tag decode mappings moved into the Nora config-store model
- root `musky_config.json` became the shared runtime config source for Musky
- Google SSO wiring and documentation were cleaned up
- large security-hardening passes were completed around CSRF, helper endpoints,
  stale app leftovers, and public/private surface separation

## Why Musky exists

Musky exists to turn "I know the right backend tool, but this will still take
too many clicks and too much context" into something faster and safer.

The project is opinionated on purpose. It is not trying to be a generic MDM
replacement. It is trying to make common school support workflows easier for
the people who actually have to do them at speed.

## What Musky is not

- It is not a full replacement for Mosyle, Nora, IncidentIQ, or your SIS.
- It is not a polished internet-scale product yet.
- It is not a "point it at the public internet and forget about it" app.

For now, the sensible deployment posture is still:

- keep Musky behind normal org auth
- prefer LAN or tightly restricted network exposure
- keep Nora/API surfaces even more restricted where possible

## Core dependencies

Musky is a web app, but it leans on a few surrounding systems:

- PHP / Apache-style web hosting for the `web/` tree
- Nora data and errands for most current lookup/refresh/action flows
- Mosyle for device actions and status
- optional Google SSO
- optional Mist data enrichment
- optional PANDA / inventory / local district-specific integrations

The preferred container path now is:

- `Docs/README.DockerQuickStart.md`

There is also older Docker/Devilbox reference material in:

- `Docker/README.md`

## Deployment notes

Expose the `web/` directory as the app's document root. Do not expose the repo
root directly.

Important practical rule:

- `web/` is the web surface
- `Functions/`, config files, docs, and other repo internals should stay out of
  direct public web access

That is one of the bigger places where the older README had drifted. Musky now
expects relative includes from `web/` back into `../Functions/`; it does not
need `Functions/` published as its own open web directory.

## Base install outline

1. Set up the web host or container environment.
2. Make sure Nora, Mosyle credentials, and any local dependencies are ready.
3. Deploy the repo so the app serves from `web/`.
4. Review and fill in the private runtime config files.
5. Review access restrictions before exposing the app to real users.

If you want a container-oriented bootstrap path, start with:

- `Docs/README.DockerQuickStart.md`

## Config files to review

### Root Musky runtime config

- `musky_config.json`
- `musky_config.json.PUBLIC.TEMPLATE`
- `Docs/README.MuskyConfig.md`

This is the main Musky runtime config source now. It covers things such as:

- session timeout
- base file paths
- debug/temp paths
- Google SSO settings
- allowed identity domains and domain-role mapping
- Nora base URL hints used by helper calls
- Mosyle runtime path hints

### Nora / backend config

- `nora_config.json`

This is still deployment-specific and is used for Nora/MariaDB/API-related
settings used by Musky's Nora-backed features.

### Other branding/content knobs

- `web/mascot.png`
- `web/musky_favicon.png`

### DB-backed admin-managed config

- tag decode mappings are managed from:
  - `web/admin/NoraWeb.TagDecode.php`
- active Nora/Mosyle/config-store values are managed from:
  - `web/admin/NoraWeb.ConfigStore.php`

## Security notes

The current app flow is built around:

- `web/check_access.php`
- MUSKY session enforcement
- Google SSO support
- scoped CSRF protections for write actions
- helper endpoint hardening work completed during the 2026 cleanup passes

Start here for the current security posture:

- `web/README-MUSKY-SECURITY.md`
- `Docs/README.NoraAPI.SecurityAudit.md`

Musky can still sit behind `.htaccess`, reverse-proxy restrictions, or other
upstream network controls, and that is still recommended.

## Main pages

- `web/index.php` - Musky hub / launcher
- `web/DeviceManager/index.php` - main device support page
- `web/DeviceManager/MyDevices.php` - devices assigned to current user
- `web/public/DeviceVitals.php` - public-safe serial lookup page
- `web/LoanerExplorer/LoanerExplorer.php` - current loaner view
- `web/ClassExplorer/ClassExplorer.php` - classroom device view
- `web/PANDA/PANDA_ChargeQueue.php` - PANDA charge queue
- `web/Preferences/index.php` - user preferences

## Docs worth reading first

- `Docs/index.md`
- `Docs/README.DeviceManager.md`
- `Docs/README.Loaners.md`
- `Docs/README.NoraErrands.MUSKY.md`
- `Docs/README.MuskyConfig.md`
- `web/SSO/Google/README.EnableGoogleSSO.md`
- `Docs/README.DockerQuickStart.md`

## Roadmap / known unfinished edges

Musky is useful today, but it is not "done." A few live themes:

- continued public-release cleanup and de-GSD cleanup
- more docs
- more polish around portable install expectations
- continued Nora/API hardening
- more cleanup of experimental or superseded paths
- eventual replacement/modernization of older helper-script workflows
- future packaging for easier outside-district adoption

## A note on how Musky was built

The original shell-side tooling was written by hand. A large amount of the PHP
side was built iteratively with ChatGPT assistance and then shaped, corrected,
and tested in place against real-world workflows.

That is not hidden here because it is part of the project's actual story. This
repo is the result of a working operator teaching a growing codebase how to do
real work.

## Contact

This project is maintained by Jesse C. Smillie.
