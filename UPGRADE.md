# Frissítés új verzióra / Upgrading to a new version

A parancsok a telepítési mappából futnak (a példában `/opt/openhdid`), a `docker` csoport tagjaként.
Az első sor a kiadás verziószáma: a kiadás mellékleteként letöltött fájlban már az adott verzió áll.

The commands run in the installation folder (`/opt/openhdid` in the examples), as a member of the
`docker` group. The first line is the release version: in the file attached to a release it is already set.

## Magyar

### 1. Előkészítés

```sh
v=1.13.1
cd /opt/openhdid
base=https://github.com/adamfhun/openhdid/releases/download/v$v
```

Olvassa el a kiadási jegyzetet: `https://github.com/adamfhun/openhdid/releases/tag/v$v`.

### 2. Mentés

```sh
./backup.sh
./backup.sh list
```

Ha még nincs `backup.sh` (az 1.4.1 előtti telepítéseken), előbb töltse le, ugyanezzel a verzióval:

```sh
curl -fsSL -O "$base/backup.sh" && chmod 755 backup.sh
```

### 3. Az új kiadás fájljai, ellenőrzőösszeggel

A `.env`, a `db.env` és a `compose.override.yaml` nem cserélődik, azok a saját beállítások. A használati
profil fájlja a kiadás része: a `.env` `COMPOSE_FILE` sora mutat rá, az 5k profilnál a `profile-5k.yaml`
melléklet a `profiles/5k/compose.yaml` helyre kerül (a 10k profilnál a `profile-10k.yaml` a
`profiles/10k/compose.yaml` helyre, mindkét gépen).

```sh
curl -fsSL -O "$base/compose.yaml" -O "$base/backup.sh" -O "$base/SHA256SUMS" -O "$base/profile-5k.yaml"
curl -fsSL -o env.example "$base/env.example"
sha256sum -c --ignore-missing SHA256SUMS
chmod 755 backup.sh
mkdir -p profiles/5k && mv profile-5k.yaml profiles/5k/compose.yaml
```

Az ellenőrzés minden fájlra `OK`-t ad. A profilfájl nélkül minden `docker compose` parancs a
`compose file "profiles/5k/compose.yaml" set by COMPOSE_FILE environment variable is invalid` hibával áll meg
(TROUBLESHOOTING.md 15. fejezet).

### 4. Új telepítési kulcsok

Az új `env.example` azon kulcsai, amelyek a `.env`-ből hiányoznak. Üres kimenet: nincs új kulcs.
Az új kulcsoknak alapértékük van; csak akkor kell felvenni őket, ha a kiadási jegyzet vagy az igény ezt kéri.

```sh
comm -13 <(grep -oE '^[A-Z0-9_]+' .env | sort -u) <(grep -oE '^[A-Z0-9_]+' env.example | sort -u)
```

Az 1.7.0 előtti telepítésen a `COMPOSE_FILE` jelenik meg újként: felvétele
(`COMPOSE_FILE=compose.yaml:profiles/5k/compose.yaml`) a használati profilt kapcsolja be, és a 3. lépés
profilfájlja kell hozzá. A kulcs nélkül a telepítés a `compose.yaml` alapértékeivel fut tovább (egy feldolgozó,
az image PHP-FPM-alapértékei), és a Rendszerállapot Kapacitás kártyája nem mutat profilt.

### 5. Frissítés

```sh
sed -i "s/^OPENHDID_VERSION=.*/OPENHDID_VERSION=$v/" .env
docker compose pull
docker compose up -d
docker compose ps
```

A `migrate` lefut és kilép, utána a többi szolgáltatás az új image-dzsel indul újra; egy webpéldánynál
ez néhány másodperc kiesés.

### 6. Ellenőrzés

```sh
docker compose exec web php artisan hdid:health
```

A panelen a Rendszerállapot fejlécében az új verzió áll.

### Visszalépés

Ha a kiadási jegyzet szerint nem volt migráció, elég a régi verziót visszaírni:

```sh
sed -i "s/^OPENHDID_VERSION=.*/OPENHDID_VERSION=<régi verzió>/" .env
docker compose up -d
```

Migrációval a frissítés előtti mentés kell; ez a régi verziót is visszaállítja és elindítja:

```sh
./backup.sh list
./backup.sh restore <a frissítés előtti mentés neve>
```

### Ha valami elakad

- A `pull` időtúllépéssel áll meg, de a gépről a `curl` eléri a ghcr.io-t: a Docker-démonnak külön proxybeállítás kell (üzemeltetési kézikönyv 6. fejezet).
- `permission denied … docker.sock`: a felhasználó nincs a `docker` csoportban, vagy a tagság új bejelentkezésig nem él.
- Egy szolgáltatás újraindulgat: `docker compose logs --tail=50 <szolgáltatás>`; az `openhdid: ERROR:` sor megnevezi az okot.
- `compose file "profiles/5k/compose.yaml" set by COMPOSE_FILE environment variable is invalid`: a `.env` `COMPOSE_FILE` sora olyan profilfájlra mutat, amely nincs letöltve; a 3. lépés tölti le (TROUBLESHOOTING.md 15. fejezet). A futó konténereket a hiba nem érinti.

## English

### 1. Preparation

```sh
v=1.13.1
cd /opt/openhdid
base=https://github.com/adamfhun/openhdid/releases/download/v$v
```

Read the release notes: `https://github.com/adamfhun/openhdid/releases/tag/v$v`.

### 2. Backup

```sh
./backup.sh
./backup.sh list
```

Without `backup.sh` (installations older than 1.4.1), download it first, from the same release:

```sh
curl -fsSL -O "$base/backup.sh" && chmod 755 backup.sh
```

### 3. The release files, verified

`.env`, `db.env` and `compose.override.yaml` are your own settings and are not replaced. The usage profile
file is part of the release: the `COMPOSE_FILE` line of `.env` points to it; with the 5k profile the
`profile-5k.yaml` asset goes to `profiles/5k/compose.yaml` (with the 10k profile `profile-10k.yaml` goes to
`profiles/10k/compose.yaml`, on both hosts).

```sh
curl -fsSL -O "$base/compose.yaml" -O "$base/backup.sh" -O "$base/SHA256SUMS" -O "$base/profile-5k.yaml"
curl -fsSL -o env.example "$base/env.example"
sha256sum -c --ignore-missing SHA256SUMS
chmod 755 backup.sh
mkdir -p profiles/5k && mv profile-5k.yaml profiles/5k/compose.yaml
```

Every file reports `OK`. Without the profile file every `docker compose` command stops with
`compose file "profiles/5k/compose.yaml" set by COMPOSE_FILE environment variable is invalid`
(TROUBLESHOOTING.md, chapter 15).

### 4. New deployment keys

The keys of the new `env.example` that `.env` does not have yet. No output: no new key. New keys come
with defaults; add them only when the release notes or your needs call for it.

```sh
comm -13 <(grep -oE '^[A-Z0-9_]+' .env | sort -u) <(grep -oE '^[A-Z0-9_]+' env.example | sort -u)
```

On installations older than 1.7.0 `COMPOSE_FILE` shows up as new: adding it
(`COMPOSE_FILE=compose.yaml:profiles/5k/compose.yaml`) switches the usage profile on and needs the profile
file of step 3. Without the key the installation keeps running on the defaults of `compose.yaml` (one worker,
the image's PHP-FPM defaults), and the Capacity card of the system status shows no profile.

### 5. Upgrade

```sh
sed -i "s/^OPENHDID_VERSION=.*/OPENHDID_VERSION=$v/" .env
docker compose pull
docker compose up -d
docker compose ps
```

`migrate` runs and exits, then the other services restart on the new image; with one web instance
this is a few seconds of downtime.

### 6. Check

```sh
docker compose exec web php artisan hdid:health
```

The system status page shows the new version in its header.

### Going back

Without migrations (see the release notes), setting the old version back is enough:

```sh
sed -i "s/^OPENHDID_VERSION=.*/OPENHDID_VERSION=<old version>/" .env
docker compose up -d
```

With migrations, restore the backup taken before the upgrade; it brings back and starts the old version too:

```sh
./backup.sh list
./backup.sh restore <name of the backup taken before the upgrade>
```

### If something gets stuck

- `pull` times out although `curl` reaches ghcr.io from the host: the Docker daemon needs its own proxy setting (operations manual, chapter 6).
- `permission denied … docker.sock`: the user is not in the `docker` group, or the membership needs a new login.
- A service keeps restarting: `docker compose logs --tail=50 <service>`; the `openhdid: ERROR:` line names the cause.
- `compose file "profiles/5k/compose.yaml" set by COMPOSE_FILE environment variable is invalid`: the `COMPOSE_FILE` line of `.env` names a profile file that has not been downloaded; step 3 downloads it (TROUBLESHOOTING.md, chapter 15). Running containers are not affected.
