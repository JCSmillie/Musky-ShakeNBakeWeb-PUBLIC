# Nora Config Store

Musky's admin config store page is:

```text
web/admin/NoraWeb.ConfigStore.php
```

It manages active settings in Nora's `nora_config_store` table. The page supports multiple credential sets per provider, one active value per `ConfigGroup + ConfigKey`, secret-value masking, and basic audit fields.

## Security Hardening

As of `2026-06-03`, Config Store write paths require a scoped CSRF token for:

- save / update
- activate
- delete
- Mosyle credential test

This keeps admin config mutations and external credential-test submits from being triggerable by a cross-site form post while a privileged Musky session is open.

## Install SQL

The installer is:

```text
web/admin/NoraWeb.ConfigStore.install.sql
```

It creates `nora_config_store` and now seeds blank active Mosyle placeholders:

- `MOSYLE.API_KEY`
- `MOSYLE.API_USERNAME`
- `MOSYLE.API_PASSWORD`

The seed rows are marked secret. Re-running the install SQL updates metadata only and does not overwrite real `ConfigValue` secrets.

## Mosyle Credential Tester

The Config Store page includes a Mosyle Credential Tester panel.

Leave fields blank to test the active DB values from `MOSYLE`. Fill one or more fields to test temporary override values without saving them.

The tester checks:

1. Mosyle `/v2/login` with API key/accessToken, username, and password.
2. Bearer token presence in the login response.
3. Mosyle `/v2/listusers` using the bearer token and `page_size = 1`.

The result shows whether each input was present, where it came from, whether login returned a bearer token, and whether `listusers` returned `OK`. It does not print the secret values.

## Expected Mosyle Rows

Use `ConfigGroup = MOSYLE`, `ConfigSet = DEFAULT`, and active rows for:

| Key | Secret | Purpose |
| --- | --- | --- |
| `API_KEY` | Yes | Mosyle `accessToken` / API key |
| `API_USERNAME` | Yes | Mosyle login username |
| `API_PASSWORD` | Yes | Mosyle login password |

These DB values are also the preferred Mosyle source for Nora's `NoraQuery.UserCheck.php`. Nora currently retains a temporary local/file fallback for Mosyle only; IIQ config for the new UserCheck flow is DB-only.

## Troubleshooting

- Missing input means the row is absent, inactive, blank, or points at an unreadable file path.
- A successful login with failed `listusers` usually means the login credentials can mint a bearer token but the API key/accessToken or bearer context is not accepted by the users endpoint.
- Failed tests are warning results. They do not save or change credentials.

## DeviceManager TagDecode Group

DeviceManager tag translations now use this same table model:

- `ConfigGroup = TagDecode`
- `ConfigSet = DEFAULT`
- `ConfigKey = device tag`
- `ConfigValue = friendly display text`

Manage these rows from:

```text
web/admin/NoraWeb.TagDecode.php
```
