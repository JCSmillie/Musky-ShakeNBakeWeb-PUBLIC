# Musky + Nora Docker Quick Start

This is the intended fast path for a clean Musky deployment now:

- Nora runs in its own Docker stack and owns MariaDB.
- Musky runs in a separate Docker stack with its own Apache/PHP, MOSBasic, and Python 3.
- Musky talks to Nora through Nora's forwarded MariaDB port.
- First-time Musky setup happens from a browser page, not by hand-building SQLite files.

This guide assumes Docker Desktop is already installed on your Mac.

## 0) Grab the latest repos

```bash
cd ~/GitHub/Nora
git pull --ff-only

cd ~/GitHub/Musky-ShakeNBakeWeb
git pull --ff-only
```

If you do not have local clones yet:

```bash
cd ~/GitHub
git clone https://github.com/JCSmillie/Nora.git
git clone https://github.com/JCSmillie/Musky-ShakeNBakeWeb-PUBLIC.git Musky-ShakeNBakeWeb
```

## 1) Start Nora first

From the Nora repo:

```bash
cd ~/GitHub/Nora
cp .env.docker.example .env
```

Important Nora `.env` values for a Musky-backed install:

- `NORA_DB_PORT_FORWARD=7306`
- `NORA_USE_MUSKY=Y`
- `NORA_ENABLE_DEMO_DATA=1` for a fast first test, unless you already have live data/real imports ready

Leave `NORA_ENABLE_ERRANDS` alone for now.
Nora's current Docker bootstrap does not install the systemd-based NoraErrands helper inside the container, and Musky's base setup/login/DeviceManager flow does not require it.

Then build and launch Nora:

```bash
docker compose up --build -d
```

Recommended health check:

```bash
bash Setup/Nora.DockerHealthBanner.sh
```

What you want to see before moving on:

- Nora MariaDB is healthy
- Nora setup completed
- demo data or real device data exists

## 2) Start Musky

From the Musky repo:

```bash
cd ~/GitHub/Musky-ShakeNBakeWeb
cp .env.docker.example .env
```

The default Musky Docker config expects Nora to already be running locally with:

- Nora DB host: `host.docker.internal`
- Nora DB port: `7306`
- Nora DB name: `nora`
- Nora DB user: `nora`
- Nora DB password: `nora_password`

That default is meant for Docker Desktop on Mac and matches Nora's default compose setup.

Build and launch Musky:

```bash
docker compose up --build -d
```

Watch startup if you want:

```bash
docker compose logs -f musky
```

Musky will:

- build its own Apache/PHP container
- clone MOSBasic into the image
- install Python 3 in the Musky container
- generate `musky_config.json` and `nora_config.json` from the Musky `.env`
- wait for Nora MariaDB before starting Apache

## 3) Run Musky First Time Access

Open:

```text
http://localhost:8088/setup/first-time-access.php
```

This page will:

- confirm Musky can reach Nora MariaDB
- provision the Musky login tables in MariaDB
- seed the shared `nora_config_store` rows Musky expects on a clean setup
- import the default TagDecode map
- create a local Musky admin account

Use the admin email as the local username.

That keeps Musky's local-login path and the `musky_users` row aligned.

## 4) Verify the first login and first search

After the first-time page succeeds you should be signed in automatically.

From there you should have:

- a working local Musky admin
- access to the Musky hub
- access to Admin Panel
- access to DeviceManager

If Nora demo data is enabled, the first-time page will also show a sample Nora lookup value you can use immediately in DeviceManager.

If it does not show a sample lookup value yet:

- wait for Nora's first import/demo seed to finish
- or run real Nora imports before testing DeviceManager

## 5) Day-one scope vs later follow-up

This quick start is meant to get you to:

- functioning Nora Docker
- functioning Musky Docker
- local admin login
- basic DeviceManager search

Still follow up later with:

- Google SSO
- reverse proxy / TLS
- district-specific config-store values
- replacing demo data with real Nora imports and live Mosyle credentials

## Notes

- New installs should not need SQLite. The Docker setup is MariaDB-first.
- The older Devilbox bundle under `Docker/` is now reference/archive material, not the preferred path.
- If you move Nora off the default host port, update Musky `.env` `MUSKY_DB_HOST` / `MUSKY_DB_PORT` to match.
