# OpenHDID

**Ügyfélszolgálati hívóazonosítás.** Az ügyintéző a hívó ügyfelet biztonsági kérdésekkel, PIN-kóddal, bediktált egyszeri kóddal vagy IVR-kóddal azonosítja; az ügyfél a saját azonosító adatait egy önkiszolgáló portálon kezeli; a telefonközpont és a mobilalkalmazás kiszolgálója aláírt gépi felületen kapcsolódik.

[English](README.md) · [Dokumentáció](#dokumentáció) · [Kiadások](https://github.com/adamfhun/openhdid/releases) · [Konténer-image](https://github.com/adamfhun/openhdid/pkgs/container/openhdid)

Az OpenHDID futtatásra kész konténer-image-ként jelenik meg. Ez a repó a teljes forráskódot, az image alapjául szolgáló `Dockerfile`-t, a Compose-telepítést és a friss dokumentációt tartalmazza.

## Funkciók

- **Munkatársi panel** (`/admin`): hívások vezérlőpultja élő hívósorral, ügyfélkeresés (Ctrl/Cmd+K), azonosítás kérdésekkel, PIN-nel, bediktált kóddal vagy indokolt kézi azonosítással, nem fogadott hívások, kimutatások (HTML, XLSX), auditnapló, rendszerállapot. A menü és minden művelet a felhasználó jogosultságaihoz igazodik.
- **Ügyfélportál** (`/`): jelszó nélküli belépés e-mailes hivatkozással vagy SMS-kóddal, választható Entra ID / ADFS egyszeri bejelentkezés, biztonsági válaszok, PIN, telefonszámok, hírek. Prémium és normál szint, saját megjelenéssel.
- **Gépi felületek** (`/api/v1/...`): híváseseményeket és IVR-ellenőrzést fogad a telefonközponttól, egyszeri IVR-kódot ad a mobilalkalmazás kiszolgálójának. Partnerenkénti kulcsok, választható HMAC-aláírás visszajátszás elleni védelemmel, forgalomkorlát, OpenAPI-leírás a rendszergazdáknak.
- **Ügyféltörzs (EMD) átvétele**: munkatársi és ügyfélfiókok hitelesített XLSX/CSV-exportból, JSON API-ból vagy feltöltött fájlból. Próbafuttatás és védelem a csonka forrás ellen.
- **Üzenetküldés**: minden e-mail (Exchange webszolgáltatás vagy SMTP) és SMS (Ozeki NG) üzenetsoron megy, szerkeszthető sablonokból, státuszlistával. A titkot hordozó üzenetek törzse a küldés után törlődik.
- **Biztonságos alapbeállítások**: egyetlen belépési szabály minden belépési útra, folyamatos jogosultság-ellenőrzés, fokozódó zárolás, atomi egyszeri kódok, auditnapló, szigorú biztonsági fejlécek, kizárólag TLS 1.3.
- **Magyar és angol** felület; SMS magyarul.

## Gyors indítás

Feltételek: Linux-kiszolgáló Docker Engine 27+ és Docker Compose 2.24+ verzióval, 2 vCPU, 4 GB RAM, egy DNS-név és hozzá TLS-tanúsítvány (teljes lánc és kulcs PEM-formátumban).

```sh
mkdir openhdid && cd openhdid
base=https://github.com/adamfhun/openhdid/releases/latest/download
curl -fsSL -O "$base/compose.yaml"
curl -fsSL -o .env "$base/env.example"
curl -fsSL -o db.env "$base/db.env.example"
chmod 600 .env db.env

# 1. Beállítások: APP_URL, jelszavak a .env és a db.env fájlban, OPENHDID_VERSION.
# 2. Új alkalmazáskulcs; a kimenetet a .env APP_KEY sorába írja, és őrizze meg védett helyen is:
docker compose run --rm --no-deps web key

# 3. A saját tanúsítvány (teljes lánc) és kulcs, a konténer felhasználója (uid 82) számára olvashatóan:
mkdir -p tls && cp /utvonal/fullchain.pem /utvonal/privkey.pem tls/
sudo chown 82:82 tls/*.pem && sudo chmod 400 tls/privkey.pem

# 4. Indítás: adatbázis, migrációk, web, háttérfolyamatok, ütemező.
docker compose up -d
docker compose ps

# 5. Az első rendszergazda (jelszót kér):
docker compose exec web php artisan hdid:make-admin admin@example.org --name="Rendszergazda"
```

Ezután lépjen be a `https://<kiszolgáló>/admin` címen, és folytassa az *Adminisztráció › Rendszer › Beállítások* oldalon (csomagok, elérhetőségek, arculat, belépési módok). A telepítési útmutató minden lépést leír.

**Kipróbálás** tanúsítvány és külső rendszerek nélkül: a `.env` fájlban `APP_ENV=demo`, `OPENHDID_TLS=selfsigned` és `OPENHDID_DEMO_DATA=true`. A migráció ekkor demófiókokat és hívásokat tölt be, az e-mailek és SMS-ek a naplóba kerülnek. Éles üzemben a demó módot soha ne használja.

## Működés

| Szolgáltatás | Parancs | Feladat |
|---|---|---|
| `web` | `web` | nginx (mainline, TLS 1.3) és PHP-FPM: portál, munkatársi panel, API-k |
| `worker` | `worker default` | e-mail, SMS, életjel |
| `sync-worker` | `worker sync` | a panelről indított ügyféltörzs-átvétel (legfeljebb 20 perc) |
| `scheduler` | `scheduler` | az ütemező, a cron helyett; pontosan egy példány fusson |
| `migrate` | `migrate` | minden indításkor egyszer: megvárja az adatbázist, migrál |
| `mariadb` | – | beépített MariaDB LTS (`db` profil); külső kiszolgáló is használható |
| `valkey` | – | közös gyorsítótár, munkamenet és üzenetsor több webpéldányhoz (`redis` profil) |

- **Beállítások**: a telepítési értékek környezeti változók (`.env`), az üzleti paraméterek a panelen szerkeszthetők és az adatbázisban élnek. Bármely változó fájlból is jöhet (`DB_PASSWORD_FILE=/run/secrets/db_password`), Docker- vagy Kubernetes-titokként. Módosítás után: `docker compose up -d`.
- **Naplók**: a szabványos kimenetre kerülnek, az access log tömör JSON (URL, lekérdezés és süti nélkül). Olvasás: `docker compose logs -f web`. A Compose-fájl forgatja őket; központi naplógyűjtéshez Docker naplózó-meghajtó kell.
- **Rendszerállapot**: `GET /health` (az `OPENHDID_MONITORING_ALLOW` címeiről), `docker compose exec web php artisan hdid:health`, valamint a *Rendszerállapot* oldal.
- **Frissítés**: új `OPENHDID_VERSION`, mentés, majd `docker compose pull && docker compose up -d`. A migrációk automatikusan és csak előre futnak.

## Konténer-image

`ghcr.io/adamfhun/openhdid`, `linux/amd64` és `linux/arm64` architektúrára.

| Címke | Jelentés |
|---|---|
| `1.2.3` | egy kiadás, soha nem íródik felül; élesben ezt (vagy a digestjét) rögzítse |
| `1.2`, `1` | az adott ág legfrissebb kiadása |
| `latest` | a legfrissebb stabil kiadás |
| `1.3.0-rc.1` | előzetes kiadás, csak a pontos címkén |

A verziózás a [Semantic Versioning](https://semver.org/lang/hu/) szabályait követi. A platform frissítése (PHP, nginx, OpenSSL, Alpine) alkalmazásváltozás nélkül új javítóverzió, így egy kiadott címke soha nem változik. A kiadási jegyzet felsorolja az összetevők verzióit.

Az image csak a futtatáshoz szükséges részeket tartalmazza:
- Alpine Linux;
- PHP-FPM a szükséges kiterjesztésekkel;
- forrásból fordított nginx a headers-more modullal;
- az alkalmazás az éles függőségeivel.

Nem privilegizált felhasználóként (uid 82) fut, csak olvasható gyökér-fájlrendszerrel. A `Dockerfile` minden alapimage-et és letöltést digesttel vagy ellenőrzőösszeggel rögzít.

Minden image aláírt, és származási (build provenance) és SBOM-igazolást hordoz:

```sh
gh attestation verify oci://ghcr.io/adamfhun/openhdid:1.0.1 --owner adamfhun
cosign verify ghcr.io/adamfhun/openhdid:1.0.1 \
  --certificate-identity-regexp '^https://github.com/adamfhun/openhdid/\.github/workflows/release\.yml@refs/tags/v' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
```

## Skálázás

Egy kiszolgáló az alapszolgáltatásokkal néhány ezer ügyfelet és napi több ezer hívást kiszolgál. Nagyobb terheléshez:

1. Emelje a web szolgáltatás `OPENHDID_FPM_MAX_CHILDREN` értékét, és adjon több memóriát a MariaDB-nek (`OPENHDID_DB_BUFFER_POOL`).
2. A munkamenet, a gyorsítótár és az üzenetsor kerüljön Valkey-re (`COMPOSE_PROFILES=db,redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`), és indítson több feldolgozót.
3. Több webpéldány terheléselosztó mögött:
   - ott `OPENHDID_TLS=off`, a `TRUSTED_PROXIES` a terheléselosztó címe;
   - a `storage` kötet közös tárhelyen;
   - `APP_MAINTENANCE_DRIVER=cache`;
   - az ütemezőből továbbra is pontosan egy fusson.
4. Az adatbázis kerüljön menedzselt vagy replikált MariaDB-re.

A méretezést, a Kubernetes-megfeleltetést és a skálázás előtti mérést az üzemeltetési kézikönyv írja le.

## Dokumentáció

A [`docs/`](docs) mappában, magyarul, HTML- és PDF-formában; a PDF-ek minden kiadáshoz csatolva is elérhetők:

- `openhdid-telepitesi-utmutato`: telepítési útmutató (feltételek, beállítási referencia, HTTPS, adatbázis, első lépések, külső rendszerek, átvételi próbák)
- `openhdid-uzemeltetesi-kezikonyv`: üzemeltetési kézikönyv (naplók, ütemező, rendszerállapot, mentés és visszaállítás, frissítés, skálázás, biztonság)
- `felhasznaloi-kezikonyv`: felhasználói kézikönyv ügyintézőknek és rendszergazdáknak

## Biztonság

Az alapbeállítások az OWASP ASVS 5.0 és az OWASP Docker Security Cheat Sheet ajánlásait követik; a telepítési útmutató sorolja fel a védelmeket és a nyitott tételeket. A sérülékenységet kérjük, ne nyilvános hibajegyben, hanem a GitHub privát bejelentési felületén jelezze (**Security › Report a vulnerability**). Biztonsági javítás a legfrissebb minor kiadáshoz készül.

## Fordítás forrásból

```sh
docker build -t openhdid:local .
```

Fejlesztéshez PHP 8.3+ (8.5-tel tesztelve), Composer 2 és Node.js LTS kell: `composer install`, `npm ci --ignore-scripts && npm run build`, `php artisan test`.

A repó minden kiadáskor a karbantartók fejlesztési repójából frissül. Hibajegyet szívesen fogadunk. A változtatások a fejlesztési repóban készülnek és a következő kiadással jelennek meg, ezért beolvasztási kérést (pull request) itt közvetlenül nem olvasztunk be.

## Licenc

Copyright © 2026 az OpenHDID szerzői.

Az OpenHDID szabad szoftver: terjeszthető és módosítható a Free Software Foundation által kiadott GNU Affero General Public License 3. vagy (választása szerint) bármely későbbi változatának feltételei szerint. Abban a reményben terjesztjük, hogy hasznos lesz, de MINDENFÉLE GARANCIA NÉLKÜL, az ELADHATÓSÁGRA vagy VALAMELY CÉLRA VALÓ ALKALMASSÁGRA vonatkozó hallgatólagos garanciát is beleértve. A részletek a [LICENSE](LICENSE) fájlban (angolul, ez a hiteles szöveg).

Ha módosított változatot üzemeltet hálózaton elérhető szolgáltatásként, a licenc szerint a felhasználóknak fel kell ajánlania annak forráskódját. Ehhez állítsa a `HDID_SOURCE_URL` értékét a közzétett forrásra; a portál és a panel erre hivatkozik.

Az OpenHDID a Laravel, Filament, Livewire, Vue, Tailwind CSS, nginx, headers-more, PHP és Alpine Linux összetevőkre épül, mindegyik a saját licence szerint; minden image SBOM-ja felsorolja az összetevőket.
