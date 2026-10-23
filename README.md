# OpenHDID

**Caller identification for customer service desks.** Agents identify callers by security questions, PIN (callers recognised by a registered number), a one-time identification code or an IVR code; clients manage their identification data on a self-service portal; the telephone system and a mobile back end talk to the application over a signed machine API.

[Magyar leírás](README.hu.md) · [Documentation](#documentation) · [Releases](https://github.com/adamfhun/openhdid/releases) · [Container image](https://github.com/adamfhun/openhdid/pkgs/container/openhdid)

OpenHDID is published as a ready-to-run container image. This repository holds the complete source code, the `Dockerfile` the image is built from, the Compose deployment and the current documentation.

## Features

- **Staff panel** (`/admin`): call dashboard with live queue, client search (Ctrl/Cmd+K), identification by questions, PIN, one-time identification code or manual override with justification, missed calls, reports (HTML, XLSX), audit log, system status. The menu and every action follow the user's permissions.
- **Client portal** (`/`): passwordless login by e-mail link or SMS code, optional Entra ID / ADFS single sign-on, security answers, PIN, phone numbers, news. Premium and standard tiers with their own look.
- **Machine APIs** (`/api/v1/...`): call events and IVR checks for the telephone system, one-time IVR codes for the mobile back end; per-partner keys, optional HMAC request signing with replay protection, rate limits, OpenAPI documents for administrators.
- **Enterprise Master Data (EMD) sync**: staff and client accounts from an authenticated XLSX/CSV export, a JSON API or a file upload, with dry runs and guards against a truncated source.
- **Messaging**: every e-mail (Exchange Web Services or SMTP) and SMS (Ozeki NG) goes through a queue, from editable templates, with a status list; secret-bearing message bodies are erased after sending.
- **Security by default**: one login rule for every sign-in path, continuous permission checks, progressive lockouts, atomic one-time codes, audit trail, strict security headers, TLS 1.3 only.
- **Hungarian and English** user interface; SMS in Hungarian.

## Quick start

Requirements: a Linux host with Docker Engine 27+ and Docker Compose 2.24+ (Podman is not supported), sized to the usage profile (5k profile: 4 vCPU, 8 GB RAM, 80 GB disk; 2 vCPU and 4 GB are enough for a trial), a DNS name and a TLS certificate for it (full chain and key in PEM).

```sh
mkdir openhdid && cd openhdid
base=https://github.com/adamfhun/openhdid/releases/latest/download
curl -fsSL -O "$base/compose.yaml"
curl -fsSL -o .env "$base/env.example"
curl -fsSL -o db.env "$base/db.env.example"
curl -fsSL -O "$base/backup.sh"
curl -fsSL --create-dirs -o profiles/5k/compose.yaml "$base/profile-5k.yaml"   # the usage profile COMPOSE_FILE in .env points to
chmod 600 .env db.env
chmod 755 backup.sh

# 1. Settings: APP_URL, passwords in .env and db.env (OPENHDID_VERSION in the downloaded file
#    is already this release's number).
# 2. The certificate and internal-CA directories owned by you (Docker would otherwise create them
#    as root), then pull the image from ghcr.io (it also produces the application key):
mkdir -p tls ca
docker compose pull

# 3. A new application key and a Valkey password (without the image: echo "base64:$(openssl rand -base64 32)"
#    and openssl rand -hex 24); paste the two printed lines into APP_KEY and REDIS_PASSWORD in .env,
#    and keep a protected copy of the application key:
docker compose run --rm --no-deps web key

# 4. Your certificate (full chain) and key, readable by the container user (uid 82):
cp /path/to/fullchain.pem /path/to/privkey.pem tls/
sudo chown 82:82 tls/*.pem && sudo chmod 400 tls/privkey.pem
#    Rootless Docker: uid 82 maps to a subordinate uid on the host, so do not chown on the host;
#    set the owner inside the container's user namespace instead (installation guide, "Rootless Docker"):
#    docker compose run --rm --no-deps --user 0 --cap-add CHOWN -v "$PWD/tls":/t --entrypoint sh web \
#      -c 'chown 82:82 /t/*.pem && chmod 444 /t/fullchain.pem && chmod 400 /t/privkey.pem'

# 5. Start: database, migrations, web, workers, scheduler.
docker compose up -d
docker compose ps

# 6. The first administrator (asks for a password):
docker compose exec web php artisan hdid:make-admin admin@example.org --name="Administrator"
```

Then sign in at `https://<your host>/admin` and continue with *Administration › System › Settings* (packages, contact details, branding, sign-in methods). The installation guide walks through every step.

**Trying it out** without a certificate or external systems: set `APP_ENV=demo`, `OPENHDID_TLS=selfsigned` and `OPENHDID_DEMO_DATA=true` in `.env`. The migration then loads demo accounts and calls, and e-mail and SMS are written to the log. Never use the demo mode in production.

## How it runs

| Service | Command | Purpose |
|---|---|---|
| `web` | `web` | nginx (mainline, TLS 1.3) and PHP-FPM: portal, staff panel, APIs |
| `worker` | `worker default` | e-mail, SMS, heartbeats |
| `sync-worker` | `worker sync` | EMD sync started from the panel (runs up to 20 minutes) |
| `scheduler` | `scheduler` | the Laravel scheduler, replaces cron; run exactly one |
| `migrate` | `migrate` | one-off at every start: waits for the database, applies migrations |
| `mariadb` | – | bundled MariaDB LTS (profile `db`); an external server works as well |
| `valkey` | – | cache, sessions and queue (profile `redis`, on by default); an external Valkey works too |

- **Configuration** lives in environment variables (`.env`); business settings are edited in the panel and stored in the database. Any variable can come from a file instead (`DB_PASSWORD_FILE=/run/secrets/db_password`) for Docker or Kubernetes secrets. After a change: `docker compose up -d`.
- **Logs** go to standard output in a compact JSON access log (no URLs, queries or cookies): `docker compose logs -f web`. The Compose file rotates them (`json-file` driver, on Docker and Podman alike); ship them to your log platform with the engine's logging driver.
- **Health**: `GET /health` (from the addresses in `OPENHDID_MONITORING_ALLOW`), `docker compose exec web php artisan hdid:health`, and the *System status* page.
- **Upgrades**: back up (`./backup.sh`), set the new `OPENHDID_VERSION`, then `docker compose pull && docker compose up -d`. Migrations run automatically and only move forward. Step by step, with copyable commands: [UPGRADE.md](UPGRADE.md) (also attached to every release, with its version filled in).
- **Troubleshooting**: copyable diagnostic commands (state, proxy, permissions, master-data reachability and login, certificate chain, single sign-on, why a sign-in was refused): [TROUBLESHOOTING.md](TROUBLESHOOTING.md), also attached to every release.
- **Backup and restore** in one command, without root: `./backup.sh` (into the `backup` folder, time-stamped: database, storage volume, settings, tls), `./backup.sh list`, `./backup.sh restore [name]`. Daily cron: `30 2 * * * /opt/openhdid/backup.sh --quiet`; copy the backups off the host, and restore only from storage that no unauthorised party can write to. After a restore everyone signs in again.

## Container image

`ghcr.io/adamfhun/openhdid` for `linux/amd64` and `linux/arm64`.

| Tag | Meaning |
|---|---|
| `1.2.3` | one release, never rewritten; pin this (or its digest) in production |
| `1.2`, `1` | the newest release of that line |
| `latest` | the newest stable release |
| `1.3.0-rc.1` | a pre-release, only under its exact tag |

Versions follow [Semantic Versioning](https://semver.org). A platform update (PHP, nginx, OpenSSL, Alpine) without application changes is a new patch release, so a published tag never changes. The release notes list the component versions.

The image contains only what runs the application: Alpine Linux, PHP-FPM with the required extensions, nginx built from source with the headers-more module, and the application with its production dependencies. It runs as an unprivileged user (uid 82) with a read-only root file system, and every base image and download in the `Dockerfile` is pinned by digest or checksum.

Every image is signed and carries a build provenance and an SBOM attestation. Set `v` to the version you install (the `OPENHDID_VERSION` of your `.env`):

```sh
v=1.13.1
gh attestation verify oci://ghcr.io/adamfhun/openhdid:$v --owner adamfhun
cosign verify ghcr.io/adamfhun/openhdid:$v \
  --certificate-identity-regexp '^https://github.com/adamfhun/openhdid/\.github/workflows/release\.yml@refs/tags/v' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
```

The signature check needs cosign 3 or later. The images are signed in the Sigstore bundle format, which cosign 2 does not look for: it reports "no signatures found".

## Usage profiles and scaling

A deployment is sized to one of three usage profiles; `.env` selects the profile, and the Capacity tile of the system status (`HDID_SIZING_PROFILE`) compares the measured peak of active accounts with the profile's design point, turning yellow at 80 %.

| Profile | Clients (concurrent portal users) | Topology | Files |
|---|---|---|---|
| `5k` | 5,000 (250–400) | one host, 4 vCPU / 8 GB, bundled MariaDB and Valkey, 2 workers | `profiles/5k/compose.yaml` (the release asset `profile-5k.yaml`; `COMPOSE_FILE=compose.yaml:profiles/5k/compose.yaml`) |
| `10k` | 10,000 (500–800) | two application hosts behind a load balancer (high availability), external MariaDB and Valkey, NFS for the `storage` volume | `profiles/10k/compose.yaml` on both hosts (the release asset `profile-10k.yaml`; `COMPOSE_PROFILES=primary` on one of them), or `kubernetes/overlays/10k` |
| `20k` | 20,000 (1,000–1,600) | Kubernetes: 3–6 web pods, 3 workers, managed MariaDB and Valkey, RWX volume | `kubernetes/overlays/20k` (`kubectl apply -k`) |

Every profile assumes 20–30 agents; sessions, cache and queue run on Valkey in all three. With two hosts or Kubernetes, TLS ends at the load balancer or the ingress (`OPENHDID_TLS=off`, `TRUSTED_PROXIES` set to the balancer), the `storage` volume is shared, exactly one scheduler runs, and the migration runs on one host at a time under the shared Valkey lock. The load balancer's liveness probe is `/up`, monitoring uses `/health`.

The installation guide and the operations manual describe the sizing of the profiles, the Kubernetes deployment and how to measure before scaling.

## Documentation

In [`docs/`](docs), in Hungarian, as PDF (also attached to every release):

- `openhdid-telepitesi-utmutato` – installation guide: requirements, configuration reference, HTTPS, database, first steps, external systems, acceptance tests
- `openhdid-uzemeltetesi-kezikonyv` – operations manual: logs, scheduler, health checks, backup and restore, upgrades, scaling, security
- `felhasznaloi-kezikonyv` – user manual for agents and administrators

## Security

The defaults follow OWASP ASVS 5.0 and the OWASP Docker Security Cheat Sheet; the installation guide lists the controls and the open items. Please report vulnerabilities privately through GitHub (**Security › Report a vulnerability**), not in a public issue. Security fixes are made for the newest minor release.

## Building from source

```sh
docker build -t openhdid:local .
```

Development needs PHP 8.4+ (8.5 tested), Composer 2 and Node.js LTS: `composer install`, `npm ci --ignore-scripts && npm run build`, `php artisan test`.

This repository is published from the maintainers' development repository with every release. Issues are welcome; changes are applied upstream and appear with the next release, so pull requests are not merged here directly.

## License

Copyright © 2026 the OpenHDID authors.

OpenHDID is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. It is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the [LICENSE](LICENSE) file.

If you run a modified version for users over a network, the license requires you to offer them its source code; set `HDID_SOURCE_URL` to your published source, and the portal and panel link to it.

OpenHDID builds on Laravel, Filament, Livewire, Vue, Tailwind CSS, nginx, headers-more, PHP and Alpine Linux, each under its own license; the SBOM attached to every image lists all components.
