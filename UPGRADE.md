# Frissítés új verzióra / Upgrading to a new version

A parancsok a telepítési mappából futnak (a példában `/opt/openhdid`), a `docker` csoport tagjaként.
Az első sor a kiadás verziószáma: a kiadás mellékleteként letöltött fájlban már az adott verzió áll.

The commands run in the installation folder (`/opt/openhdid` in the examples), as a member of the
`docker` group. The first line is the release version: in the file attached to a release it is already set.

## Magyar

### 1. Előkészítés

```sh
v=1.14.1
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

Az 1.13.1-ről vagy régebbiről frissítve a 3. lépés új `compose.yaml`-je kötelező: a `worker` az elsőbbségi
sort (`HDID_PRIORITY_QUEUE=priority`) is hallgatja, új a `valkey-sessions` szolgáltatás (a munkamenetek saját
Valkey-je), és minden szolgáltatás memóriaplafont kap. A régi `compose.yaml` (`command: [worker, default]`)
mellett a `HDID_PRIORITY_QUEUE=priority` sorral a belépési kódok nem mennek ki: ilyenkor a kulcs maradjon üres.
A beépített Valkey-nél (`REDIS_HOST=valkey`) a munkamenet-Valkey a `docker compose up -d`-vel magától indul;
külső Valkey-nél előbb egy második, `maxmemory-policy allkeys-lru` politikájú példány kell, a címe a
`REDIS_SESSION_HOST`, `REDIS_SESSION_PORT`, `REDIS_SESSION_PASSWORD` és `REDIS_SESSION_DB` sorba kerül (addig a
munkamenetek a fő Valkey-ben maradnak, a Munkamenet-tár kártya sárga), a fő példányon pedig a `noeviction`
kötelező. A munkamenetek áthelyezése egyszer mindenkit kiléptet. Ellenőrizze a fő Valkey memóriáját is:
a beépített 256mb-os korláttal indul; ha most többet használ, előbb emelje az `OPENHDID_VALKEY_MAXMEMORY` és az
`OPENHDID_VALKEY_MEM_LIMIT` értékét:

```sh
docker compose exec valkey valkey-cli -a "$REDIS_PASSWORD" INFO memory | grep -E 'used_memory_human|maxmemory_human'
```

A kiadás többi teendője (STARTTLS, kéréskorlát, szerepkörök ellenőrzése, Kubernetes NetworkPolicy, demóadat
élesben) a CHANGELOG „Frissítés előtt” részében áll.

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

### Két alkalmazásgép (10k profil)

A két gép (A: elsődleges, `COMPOSE_PROFILES=primary`, ütemező és ügyféltörzs-feldolgozó; B: másodlagos) ugyanazt
a külső MariaDB-t, a két külső Valkey-t és az NFS-en közös `storage` kötetet használja. Előbb mindkét gépen az
1. lépés, utána ebben a sorrendben:

1. **Mentés a fájlok cseréje és a migráció előtt.** A `backup.sh` külső adatbázist nem ment (az `info.txt` `database=external` sora
   jelzi): a MariaDB-t a kiszolgáló saját eszközével kell menteni, a `storage` kötetet az NFS-kiszolgálón, a
   `.env`-et, a `db.env`-et és a `tls` mappát mindkét gépen (a `./backup.sh` mindkét gépen futtatható, ezeket
   menti; a `storage`-ot kétszer menti, ez nem árt).

   ```sh
   mariadb-dump --single-transaction -h <adatbázis> -u <migrációs fiók> -p <adatbázis neve> | gzip > openhdid-$(date +%F).sql.gz
   ./backup.sh
   ```
2. **Az új fájlok és kulcsok mindkét gépen:** a 3. lépés (a profilfájl itt a `profile-10k.yaml`) és a 4. lépés.
   A `REDIS_SESSION_*` sorok (a munkamenet-Valkey) mindkét gép `.env`-jében azonosak, és a B gép frissítése
   előtt kerülnek be; a frissítés egyszer mindenkit kiléptet. Külső Valkey-n a Valkey / Redis kártya sárga, amíg
   a `REDIS_PREFIX` az alapérték: az előtagot ne gördülő frissítés közben cserélje, hanem mindkét gépen egyszerre,
   leállított `web` és `worker` mellett, üres sorral, mert eltérő előtaggal a két gép sora, zárai és munkamenetei
   szétválnak.

   ```sh
   curl -fsSL -O "$base/compose.yaml" -O "$base/backup.sh" -O "$base/SHA256SUMS" -O "$base/profile-10k.yaml"
   curl -fsSL -o env.example "$base/env.example"
   sha256sum -c --ignore-missing SHA256SUMS
   chmod 755 backup.sh
   mkdir -p profiles/10k && mv profile-10k.yaml profiles/10k/compose.yaml
   ```

3. **B gép:** az 5. lépés (`OPENHDID_VERSION`, `docker compose pull`, `docker compose up -d`). Itt fut a
   `migrate`: az új migrációk a közös Valkey zárja alatt egyszer futnak le, utána a `web` és a `worker` az új
   image-dzsel indul. Ellenőrzés a B gépen: `docker compose ps` és a 6. lépés; a terheléselosztó élő-próbája
   (`/up`) zöld.
4. **A gép:** ugyanez. A `migrate` itt már nem talál teendőt; a `web`, a `worker`, a `sync-worker` és az
   ütemező az új image-dzsel indul.
5. **Ellenőrzés mindkét gépen:** `docker compose ps`, `hdid:health`; a Rendszerállapot fejlécében az új verzió.

**A két verzió együttfutása.** A 3. és a 4. lépés között az A gép régi verziója az új sémán fut. Ez akkor
megengedett, ha a kiadási jegyzet „Frissítés előtt” része nem mond mást (a migrációk bővítők: új tábla, oszlop,
index). Ha a jegyzet egyidejű átállást kér, előbb mindkét gépen `docker compose stop web worker`, aztán a B gép,
majd az A gép frissítése, és csak utána kap forgalmat bármelyik.

**Visszalépés.** Migráció nélkül: a régi `OPENHDID_VERSION` vissza, `docker compose up -d`, előbb az A, aztán a
B gépen. Migrációval: mindkét gépen `docker compose stop`; az adatbázis visszaállítása a frissítés előtti
mentésből a kiszolgáló eszközével; a régi `OPENHDID_VERSION` vissza mindkét gépen; az A gépen
`./backup.sh signout` (mindenki kilép, hogy a mentés óta visszavont belépések ne éledjenek újra; a közös Valkey
munkamenet- és gyorsítótár-adatbázisát üríti), végül `docker compose up -d` az A, majd a B gépen. A mentés óta
keletkezett adat elvész.

### Kubernetes (20k profil)

A manifesztek nem kiadási mellékletek: a kiadás címkéjéből jönnek, és abban a `base/kustomization.yaml`
`newTag` sora már a kiadás verziója. Előbb mentés: az adatbázis pillanatképe a menedzselt szolgáltatás eszközével
(erre épül a visszalépés). Az új verzió `.env`-kulcsai a `openhdid-env` Secretbe kerülnek (a 4. lépés listája
szerint), utána:

```sh
git clone --depth 1 --branch "v$v" https://github.com/adamfhun/openhdid.git "openhdid-$v"
cd "openhdid-$v"
kubectl -n openhdid create secret generic openhdid-env --from-env-file=/opt/openhdid/.env --dry-run=client -o yaml | kubectl apply -f -
kubectl -n openhdid delete job openhdid-migrate --ignore-not-found
kubectl kustomize kubernetes/overlays/20k | kubectl apply -l app.kubernetes.io/component=migrate -f -
kubectl -n openhdid wait --for=condition=complete job/openhdid-migrate --timeout=600s
kubectl apply -k kubernetes/overlays/20k
kubectl -n openhdid rollout status deploy/openhdid-web --timeout=600s
```

Előbb a migrációs Job fut le (a migrációs fiókkal, a közös Valkey zárja alatt), csak utána cseréli a teljes
`apply` a podokat: a webet gördülő frissítéssel (`maxUnavailable: 0`), az ütemezőt és az ügyféltörzs-feldolgozót
`Recreate` stratégiával. A 10k overlaynél a `kubernetes/overlays/10k` mappa. Ellenőrzés: `kubectl -n openhdid get
pods`, `kubectl -n openhdid exec deploy/openhdid-web -- php artisan hdid:health`; a Rendszerállapot fejlécében az
új verzió.

**Visszalépés.** Migráció nélkül: `kubectl -n openhdid rollout undo deploy/openhdid-web deploy/openhdid-worker
deploy/openhdid-sync-worker deploy/openhdid-scheduler`. Migrációval a `rollout undo` nem elég (a régi verzió az új
sémán futna): a podok leállítása (`kubectl -n openhdid scale deploy --all --replicas=0`), az adatbázis
visszaállítása a frissítés előtti mentésből a menedzselt szolgáltatás eszközével, mindenki kiléptetése, még a podok
indítása előtt, az alkalmazás saját parancsával egy egyszeri podban (a régi kiadás image-ével; ugyanaz, amit a
`backup.sh` hív: növeli minden fiók munkamenet-korszakát, törli a tokeneket, érvényteleníti az élő kódokat, és
`FLUSHDB`-vel üríti a két Valkey munkamenet- és gyorsítótár-adatbázisát; a végén kiírja, mi történt):

```sh
kubectl -n openhdid run openhdid-signout --rm -i --restart=Never \
  --image=ghcr.io/adamfhun/openhdid:<régi verzió> \
  --overrides='{"apiVersion":"v1","spec":{"automountServiceAccountToken":false,
    "securityContext":{"runAsNonRoot":true,"runAsUser":82,"runAsGroup":82,"seccompProfile":{"type":"RuntimeDefault"}},
    "containers":[{"name":"openhdid-signout","image":"ghcr.io/adamfhun/openhdid:<régi verzió>",
      "args":["artisan","hdid:sign-out-all","--force","--reason=backup_restore"],
      "envFrom":[{"secretRef":{"name":"openhdid-env"}},{"secretRef":{"name":"openhdid-db-env"}}],
      "securityContext":{"allowPrivilegeEscalation":false,"capabilities":{"drop":["ALL"]}},
      "volumeMounts":[{"name":"tmp","mountPath":"/tmp"}]}],
    "volumes":[{"name":"tmp","emptyDir":{"medium":"Memory","sizeLimit":"64Mi"}}]}}'
```

Végül a régi verzió manifesztjei a régi címkéből (a befejezett migrációs Job képe nem cserélhető, ezért előbb
törölni kell):

```sh
kubectl -n openhdid delete job openhdid-migrate --ignore-not-found
kubectl apply -k kubernetes/overlays/20k      # a régi címke klónjában
```

### Ha valami elakad

- A `pull` időtúllépéssel áll meg, de a gépről a `curl` eléri a ghcr.io-t: a Docker-démonnak külön proxybeállítás kell (üzemeltetési kézikönyv 6. fejezet).
- `permission denied … docker.sock`: a felhasználó nincs a `docker` csoportban, vagy a tagság új bejelentkezésig nem él.
- Egy szolgáltatás újraindulgat: `docker compose logs --tail=50 <szolgáltatás>`; az `openhdid: ERROR:` sor megnevezi az okot.
- `compose file "profiles/5k/compose.yaml" set by COMPOSE_FILE environment variable is invalid`: a `.env` `COMPOSE_FILE` sora olyan profilfájlra mutat, amely nincs letöltve; a 3. lépés tölti le (TROUBLESHOOTING.md 15. fejezet). A futó konténereket a hiba nem érinti.
- A `migrate` 64-es kóddal megáll, „demo data” hibával: éles környezetben demóadat van az adatbázisban (TROUBLESHOOTING.md 16. fejezet).
- Minden oldal 400 „Ismeretlen kiszolgálónév”, vagy sok 429/503 válasz: TROUBLESHOOTING.md 17. és 18. fejezet.
- A levelek nem mennek ki, a Levelezés kártya piros „TLS required” hibával: az SMTP-kiszolgáló nem kínál STARTTLS-t (TROUBLESHOOTING.md 19. fejezet).

## English

### 1. Preparation

```sh
v=1.14.1
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

When upgrading from 1.13.1 or older, the new `compose.yaml` of step 3 is mandatory: `worker` also listens to the
priority queue (`HDID_PRIORITY_QUEUE=priority`), there is a new `valkey-sessions` service (a Valkey of the
sessions' own), and every service gets a memory ceiling. With the old `compose.yaml` (`command: [worker, default]`)
and the `HDID_PRIORITY_QUEUE=priority` line, sign-in codes are not sent: keep the key empty in that case. With the
bundled Valkey (`REDIS_HOST=valkey`) the session Valkey starts by itself with `docker compose up -d`; with an
external Valkey set up a second instance with `maxmemory-policy allkeys-lru` first and put its address into
`REDIS_SESSION_HOST`, `REDIS_SESSION_PORT`, `REDIS_SESSION_PASSWORD` and `REDIS_SESSION_DB` (until then the
sessions stay on the main Valkey and the Session store tile is yellow); `noeviction` is mandatory on the main one.
Moving the sessions signs everybody out once. Check the main Valkey's memory too: the bundled one starts with a
256mb limit; if it uses more now, raise `OPENHDID_VALKEY_MAXMEMORY` and `OPENHDID_VALKEY_MEM_LIMIT` first:

```sh
docker compose exec valkey valkey-cli -a "$REDIS_PASSWORD" INFO memory | grep -E 'used_memory_human|maxmemory_human'
```

The other steps of this release (STARTTLS, request limit, reviewing the roles, Kubernetes NetworkPolicy, demo
data in production) are in the "Before upgrading" part of the CHANGELOG.

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

### Two application hosts (10k profile)

Both hosts (A: primary, `COMPOSE_PROFILES=primary`, scheduler and master-data worker; B: secondary) use the same
external MariaDB, the two external Valkeys and the `storage` volume shared on NFS. First step 1 on both hosts, then
in this order:

1. **Back up before replacing the files and before the migration.** `backup.sh` does not back up an external database (the `database=external`
   line of `info.txt` says so): back MariaDB up with the server's own tool, the `storage` volume on the NFS
   server, `.env`, `db.env` and the `tls` folder on both hosts (`./backup.sh` runs on both and saves those; it
   saves `storage` twice, which does no harm).

   ```sh
   mariadb-dump --single-transaction -h <database host> -u <migration account> -p <database name> | gzip > openhdid-$(date +%F).sql.gz
   ./backup.sh
   ```
2. **The new files and keys on both hosts:** step 3 (the profile file is `profile-10k.yaml` here) and step 4. The
   `REDIS_SESSION_*` lines (the session Valkey) are the same in both hosts' `.env` and go in before host B is
   upgraded; the upgrade signs everyone out once. On an external Valkey the Valkey / Redis card is yellow while
   `REDIS_PREFIX` is the default: do not change the prefix during a rolling upgrade, but on both hosts at once,
   with `web` and `worker` stopped and the queue empty, because with different prefixes the two hosts' queues,
   locks and sessions drift apart.

   ```sh
   curl -fsSL -O "$base/compose.yaml" -O "$base/backup.sh" -O "$base/SHA256SUMS" -O "$base/profile-10k.yaml"
   curl -fsSL -o env.example "$base/env.example"
   sha256sum -c --ignore-missing SHA256SUMS
   chmod 755 backup.sh
   mkdir -p profiles/10k && mv profile-10k.yaml profiles/10k/compose.yaml
   ```

3. **Host B:** step 5 (`OPENHDID_VERSION`, `docker compose pull`, `docker compose up -d`). `migrate` runs here: the
   new migrations run once under the shared Valkey lock, then `web` and `worker` start on the new image. Check host
   B: `docker compose ps` and step 6; the load balancer's liveness probe (`/up`) is green.
4. **Host A:** the same. `migrate` finds nothing left to do; `web`, `worker`, `sync-worker` and the scheduler start
   on the new image.
5. **Check both hosts:** `docker compose ps`, `hdid:health`; the system status header shows the new version.

**Both versions at once.** Between steps 3 and 4 the old version on host A runs on the new schema. This is allowed
unless the "Before upgrading" part of the release notes says otherwise (migrations add: new tables, columns,
indexes). If the notes ask for a simultaneous switch, run `docker compose stop web worker` on both hosts first,
upgrade host B, then host A, and only then let either take traffic.

**Going back.** Without migrations: set the old `OPENHDID_VERSION` back, `docker compose up -d`, on host A first,
then on B. With migrations: `docker compose stop` on both hosts; restore the database from the backup taken before
the upgrade with the server's tool; set the old `OPENHDID_VERSION` back on both hosts; on host A
`./backup.sh signout` (signs everybody out so that sign-ins revoked since the backup do not come back; it empties
the session and cache databases of the shared Valkey), finally `docker compose up -d` on host A, then on B. Data
written since the backup is lost.

### Kubernetes (20k profile)

The manifests are not release assets: they come from the release tag, where the `newTag` line of
`base/kustomization.yaml` already carries the release version. Back up first: a snapshot of the database with the
managed service's tool (going back relies on it). The new version's `.env` keys go into the `openhdid-env` Secret
(as listed by step 4), then:

```sh
git clone --depth 1 --branch "v$v" https://github.com/adamfhun/openhdid.git "openhdid-$v"
cd "openhdid-$v"
kubectl -n openhdid create secret generic openhdid-env --from-env-file=/opt/openhdid/.env --dry-run=client -o yaml | kubectl apply -f -
kubectl -n openhdid delete job openhdid-migrate --ignore-not-found
kubectl kustomize kubernetes/overlays/20k | kubectl apply -l app.kubernetes.io/component=migrate -f -
kubectl -n openhdid wait --for=condition=complete job/openhdid-migrate --timeout=600s
kubectl apply -k kubernetes/overlays/20k
kubectl -n openhdid rollout status deploy/openhdid-web --timeout=600s
```

The migration Job runs first (with the migration account, under the shared Valkey lock); only then does the full
`apply` replace the pods: the web with a rolling update (`maxUnavailable: 0`), the scheduler and the master-data
worker with the `Recreate` strategy. For the 10k overlay use `kubernetes/overlays/10k`. Check: `kubectl -n openhdid
get pods`, `kubectl -n openhdid exec deploy/openhdid-web -- php artisan hdid:health`; the system status header
shows the new version.

**Going back.** Without migrations: `kubectl -n openhdid rollout undo deploy/openhdid-web deploy/openhdid-worker
deploy/openhdid-sync-worker deploy/openhdid-scheduler`. With migrations `rollout undo` is not enough (the old
version would run on the new schema): stop the pods (`kubectl -n openhdid scale deploy --all --replicas=0`),
restore the database from the backup taken before the upgrade with the managed service's tool, sign everybody out
before any pod starts, with the application's own command in a one-off pod (the image of the old release; the same
command `backup.sh` runs: it moves every account's session epoch on, deletes the tokens, uses up the live codes and
empties the session and cache databases of both Valkeys with `FLUSHDB`; it reports what it did):

```sh
kubectl -n openhdid run openhdid-signout --rm -i --restart=Never \
  --image=ghcr.io/adamfhun/openhdid:<old version> \
  --overrides='{"apiVersion":"v1","spec":{"automountServiceAccountToken":false,
    "securityContext":{"runAsNonRoot":true,"runAsUser":82,"runAsGroup":82,"seccompProfile":{"type":"RuntimeDefault"}},
    "containers":[{"name":"openhdid-signout","image":"ghcr.io/adamfhun/openhdid:<old version>",
      "args":["artisan","hdid:sign-out-all","--force","--reason=backup_restore"],
      "envFrom":[{"secretRef":{"name":"openhdid-env"}},{"secretRef":{"name":"openhdid-db-env"}}],
      "securityContext":{"allowPrivilegeEscalation":false,"capabilities":{"drop":["ALL"]}},
      "volumeMounts":[{"name":"tmp","mountPath":"/tmp"}]}],
    "volumes":[{"name":"tmp","emptyDir":{"medium":"Memory","sizeLimit":"64Mi"}}]}}'
```

Finally apply the old version's manifests from the old tag (the image of the finished migration Job cannot be
changed, so delete it first):

```sh
kubectl -n openhdid delete job openhdid-migrate --ignore-not-found
kubectl apply -k kubernetes/overlays/20k      # in a clone of the old tag
```

### If something gets stuck

- `pull` times out although `curl` reaches ghcr.io from the host: the Docker daemon needs its own proxy setting (operations manual, chapter 6).
- `permission denied … docker.sock`: the user is not in the `docker` group, or the membership needs a new login.
- A service keeps restarting: `docker compose logs --tail=50 <service>`; the `openhdid: ERROR:` line names the cause.
- `compose file "profiles/5k/compose.yaml" set by COMPOSE_FILE environment variable is invalid`: the `COMPOSE_FILE` line of `.env` names a profile file that has not been downloaded; step 3 downloads it (TROUBLESHOOTING.md, chapter 15). Running containers are not affected.
- `migrate` stops with exit code 64 and a "demo data" error: the production database holds demo data (TROUBLESHOOTING.md, chapter 16).
- Every page answers 400 "Unknown host name", or many 429/503 answers: TROUBLESHOOTING.md, chapters 17 and 18.
- Mail is not sent, the Mail tile is red with "TLS required": the SMTP server offers no STARTTLS (TROUBLESHOOTING.md, chapter 19).
