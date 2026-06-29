# Musky Root Config

Musky's web layer now uses one root JSON file for file-based runtime settings:

```text
<MUSKY-Repo>/musky_config.json
```

This replaces the old `web/config.php` file.

## What Loads It

- `web/bootstrap.php`
- `Functions/MuskyBootstrap.php`
- `Functions/MuskyConfig.php`

Most web pages now load `web/bootstrap.php`, which in turn loads the root JSON
and exposes compatibility globals/constants for older page code.

## What Stays Separate

- `nora_config.json`
  - Nora DB/API connection settings
  - Nora-side auth/rate-limit/log config
- database-backed values in `nora_config_store`
  - feature toggles and app data that already belong in the DB

## Main Sections

### `app`
- `timezone`

### `session`
- `timeout_seconds`
- `sqlite_path`

For new Docker/MariaDB-first installs, `sqlite_path` should usually stay blank.
It is now best treated as a legacy fallback path, not a required part of a new
deployment.

### `modules`
- `device_manager_enabled`
- `loaner_enabled`

### `network`
- `campus_ips`

### `identity`
- `allowed_email_domains`
- `staff_domains`
- `student_domains`
- `hdk_domains`
- `restricted_individual_domains`
- `teacher_email_domain`
- `username_lookup_domains`

### `email`
- `problem_report_to`
- `sender_address`

### `paths`
- `mosbasic_binary`
- `log_dir`
- `nora_template_dir`
- `mosbasic_drop_dir`

### `nora_api`
- `base_url`

### `mosyle`
- `api_base_url`
- `credentials_file`

### `debug`
- `nora_api_helper_log`
- `loaner_invlookup_log`
- `loaner_invlookup_status_log`

### `dev`
- `host_suffix`
- `default_test_user_email`
- `test_sqlite_path`

### `google_sso`
- `client_id`
- `client_secret`
- `redirect_uri`
- `service_keyfile`
- `impersonate_admin`
- `allowed_domains`
- `enable_debug_logs`
- `debug_log_dir`
- `disable_temp_debug_files`

## Google SSO Note

`web/SSO/Google/config.php` and `web/SSO/Google/config_google_service.php`
are now loader shims. They read from `musky_config.json`; they are no longer
the place to hand-edit live Google settings.

## Public Template

Use this file as the shareable starting point:

```text
<MUSKY-Repo>/musky_config.json.PUBLIC.TEMPLATE
```

Copy it to `musky_config.json` in a private deployment and replace the
placeholder values with your real environment settings.

`musky_config.json` itself is private deployment state and should stay out of
the public-export repo copy. Only the `.PUBLIC.TEMPLATE` file should travel as
the public starting point.

## Nora API Base URL

`Functions/Musky_API_Helper.php` now checks `nora_api.base_url` before trying
to build a same-host fallback URL.

This is the preferred place to define Musky's internal Nora API target when:

- Musky is running in Docker or behind a reverse proxy
- CLI/server-side calls should use an internal service name instead of the
  public hostname
- the deployment does not want runtime behavior tied to Devilbox-specific
  assumptions

Accepted forms:

- full helper form: `https://musky.example.org/api/NoraAPI.php?path=`
- NoraAPI file path: `https://musky.example.org/api/NoraAPI.php`
- host-only base: `https://musky.example.org`

If `nora_api.base_url` is blank, Musky falls back to the current request host
and finally `http://localhost`.

## Identity Domains

Musky now keeps its domain-role assumptions in the root config instead of
hardcoding them across Admin, DeviceManager, ClassExplorer, and PANDA.

The `identity` section covers:

- generic allowed email domains used by Musky UX/helpers
- which domains count as `staff`
- which domains count as `student`
- which domains count as `HDK`
- which domains are restricted from top-tier direct assignment in Admin
- which domain should be used when ClassExplorer builds teacher email addresses
- which domain order should be used when username-only lookups try candidate
  email addresses

Google SSO still enforces its own `google_sso.allowed_domains` list. In most
deployments, that list should stay aligned with `identity.allowed_email_domains`.

## Mosyle Runtime Paths

`Functions/LocationWebLink.sh` no longer walks host symlinks to discover its
Mosyle runtime dependencies.

It now reads:

- `paths.mosbasic_binary`
- `mosyle.api_base_url`
- `mosyle.credentials_file`

If `mosyle.credentials_file` is blank, the script falls back to a `.MosyleAPI`
file beside the configured `paths.mosbasic_binary`.

## Temp / Debug Drops

Musky still has a few intentionally noisy debug/temp log drops during the
current `0.8` rolling-beta phase. Those paths are now controlled by
`musky_config.json` instead of being hardcoded in page code:

- `debug.nora_api_helper_log`
- `debug.loaner_invlookup_log`
- `debug.loaner_invlookup_status_log`

Set a value to a writable log file path to keep the current behavior. Set a
value to an empty string if you want that specific drop disabled.

## Portability Notes

- Repo-internal helper paths such as `Functions/` are now derived automatically
  instead of being hardcoded in config.
- Shell scripts that live inside `Functions/` now resolve sibling helper files
  from their own directory instead of scraping PHP config.
- If you relocate external services or support folders, update
  `musky_config.json` instead of hunting through `web/`.
