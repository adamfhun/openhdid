# Hibakeresés / Troubleshooting

Másolható diagnosztikai parancsok a konténeres telepítéshez. A telepítési mappából futnak (a példában
`/opt/openhdid`), a `docker` csoport tagjaként. Titkot egyik sem ír ki: a tokenek, jelszavak értéke helyett
legfeljebb a hosszuk látszik. Részletek: üzemeltetési kézikönyv 6. fejezet.

Copyable diagnostic commands for the container installation, run in the installation folder (`/opt/openhdid`
in the examples) as a member of the `docker` group. None of them prints a secret: at most the length of a
token or password is shown. Details: operations manual, chapter 6 (Hungarian).

## 1. Állapot / Overall state

Mi fut, mi indul újra, és mit mond a rendszerállapot. / What runs, what restarts, and what the status check says.

```sh
docker compose ps
docker compose logs --tail=50 web
docker compose exec web php artisan hdid:health
```

## 2. Naplók / Logs

Minden folyamat a konténer kimenetére ír. A webkiszolgáló sorai tömör JSON-ok (access log), a többi sor hiba
vagy figyelmeztetés. / Every process writes to the container output; the web server's lines are compact JSON
(access log), the other lines are errors or warnings.

```sh
docker compose logs -f --tail=200 web                                          # élő követés / follow
docker compose logs --since 30m web worker sync-worker scheduler               # az utolsó fél óra / last 30 minutes
docker compose logs --since 1d --no-log-prefix web worker sync-worker scheduler | grep -v '^{'   # hibák, figyelmeztetések / errors, warnings
docker compose logs --since 1h --no-log-prefix web | grep '"status":5'         # 5xx válaszok / 5xx answers
docker compose logs --no-log-prefix migrate                                    # a legutóbbi migráció / last migration
docker compose logs mariadb --tail=50
```

Részletesebb alkalmazásnapló egy hibakeresés idejére: a `.env`-ben `LOG_LEVEL=debug`, utána `docker compose up -d`;
a végén vissza `warning`-ra. / More detailed application logging for a debugging session: `LOG_LEVEL=debug` in
`.env`, then `docker compose up -d`; set it back to `warning` afterwards.

A felhasználói műveletek (belépések, elutasítások, beállítások, átvételek) az auditnaplóban vannak: a panelen
Rendszer › Auditnapló, parancssorból lásd lent. / User actions are in the audit log: System › Audit log in the
panel, or from the command line below.

## 3. Újrainduló szolgáltatás / A service keeps restarting

Az indítószkript `openhdid: ERROR:` sora megnevezi az okot. Futó `web` nélkül is ellenőrizhető a tanúsítvány,
és futtathatók az `artisan` parancsok.

The start script's `openhdid: ERROR:` line names the cause. The certificate can be checked and `artisan`
commands run without a running `web`.

```sh
docker compose logs --tail=100 web worker scheduler migrate | grep -i 'error'
docker compose run --rm --no-deps web tls-check
docker compose run --rm --no-deps web artisan hdid:health
```

## 4. Image-letöltés és proxy / Image pull and proxy

A Docker-démon nem örökli a felhasználó proxyját. Az első két sor megmutatja, van-e proxy, és látja-e a démon;
a két `curl` közül a `HTTP/2 401` a jó válasz (IPv4, illetve IPv6).

The Docker daemon does not inherit the user's proxy. The first two lines show whether there is a proxy and
whether the daemon sees it; for the two `curl` calls `HTTP/2 401` is the good answer (IPv4 and IPv6).

```sh
env | grep -i proxy
docker info | grep -i proxy
curl -4 -sSI https://ghcr.io/v2/ | head -1
curl -6 -sSI https://ghcr.io/v2/ | head -1
```

Proxy a démonnak (és eltávolítása). / Proxy for the daemon (and removing it):

```sh
sudo mkdir -p /etc/systemd/system/docker.service.d
sudo tee /etc/systemd/system/docker.service.d/http-proxy.conf >/dev/null <<EOF
[Service]
Environment="HTTP_PROXY=${http_proxy:-$HTTP_PROXY}" "HTTPS_PROXY=${https_proxy:-$HTTPS_PROXY}" "NO_PROXY=localhost,127.0.0.1,${no_proxy:-$NO_PROXY}"
EOF
sudo systemctl daemon-reload && sudo systemctl restart docker

# eltávolítás / removal
sudo rm /etc/systemd/system/docker.service.d/http-proxy.conf
sudo systemctl daemon-reload && sudo systemctl restart docker
```

## 5. Docker-jogosultság / Docker permissions

`permission denied … docker.sock`: a felhasználó nincs a `docker` csoportban, vagy a tagság új bejelentkezésig
nem él. Ha a kliens rossz socketet keres, a `DOCKER_HOST` vagy egy docker context a ludas.

`permission denied … docker.sock`: the user is not in the `docker` group, or the membership needs a new login.
If the client looks for a wrong socket, `DOCKER_HOST` or a docker context is to blame.

```sh
id
echo "$DOCKER_HOST"
docker context ls
sudo usermod -aG docker "$(id -un)"   # utána új bejelentkezés / then log in again
```

## 6. Fájlok tulajdonosa / File ownership

A telepítési mappa, a `tls`, a `ca`, a `.env` és a `db.env` az üzemeltetőé; a `tls` kulcsa a konténer
felhasználójáé (82), 400-as joggal. Az utolsó sor a konténer szemszögéből mutatja.

The installation folder, `tls`, `ca`, `.env` and `db.env` belong to the operator; the key in `tls` belongs to the
container user (82), mode 400. The last line shows it from the container's view.

```sh
ls -ld . tls ca .env db.env compose.yaml
ls -ln tls ca
docker compose exec web sh -c 'stat -c "%n %u:%a" /etc/openhdid/tls/*.pem'
```

## 7. Névfeloldás a konténerből / Name resolution inside the container

Nem kell minden nevet felvenni. A konténer a Docker beépített DNS-én át a gazdagép DNS-kiszolgálóit kérdezi,
tehát ami a belső DNS-ben van, azt magától feloldja. A gazdagép `/etc/hosts` bejegyzéseit viszont nem látja.
Ezt a négy sor eldönti egy névre:

Not every name needs an entry. The container asks the host's DNS servers through Docker's embedded DNS, so a name
in the internal DNS resolves by itself. Entries in the host's `/etc/hosts`, however, are not visible to it. These
four lines decide it for one name:

```sh
name=emd.ceg.local
getent hosts "$name"                                   # a gazdagép (a /etc/hosts-szal együtt) / the host (with /etc/hosts)
grep -w "$name" /etc/hosts                             # csak itt van? / only here?
cat /etc/resolv.conf                                   # a gazdagép DNS-kiszolgálói / the host's DNS servers
docker compose exec web php -r 'echo gethostbyname($argv[1]), PHP_EOL;' "$name"   # a konténer (a név maga = nem oldja fel) / the container (the name itself = unresolved)
```

**Ha a név csak a gazdagép `/etc/hosts`-ában van** (teszt gépen gyakori): `extra_hosts` a `compose.yaml` mellé tett
`compose.override.yaml`-ban, minden külső rendszert hívó szolgáltatásnak; utána `docker compose up -d`. Tartósan
jobb, ha a név bekerül a belső DNS-be. / **If the name is only in the host's `/etc/hosts`** (common on a test
machine): `extra_hosts` in a `compose.override.yaml` next to `compose.yaml`, for every service that calls external
systems; then `docker compose up -d`. In the long run, add the name to the internal DNS.

```yaml
x-hosts: &hosts
  extra_hosts:
    - "adfs.ceg.local:10.0.0.5"
    - "emd.ceg.local:10.0.0.6"

services:
  web: *hosts
  worker: *hosts
  sync-worker: *hosts
  scheduler: *hosts
```

**Ha a gazdagép helyi feloldót használ** (a `resolv.conf`-ban csak `127.0.0.x` áll, például systemd-resolved), vagy
a belső DNS csak VPN-en át érhető el: a Dockernek egyszer, központilag kell megadni a belső DNS-kiszolgálót, és
egyetlen nevet sem kell egyenként felvenni. Ha már van `/etc/docker/daemon.json`, a `dns` kulcsot abba vegye fel.
/ **If the host uses a local resolver** (only `127.0.0.x` in `resolv.conf`, e.g. systemd-resolved), or the internal
DNS is only reachable over a VPN: give Docker the internal DNS server once, centrally, and no name needs an entry.
If `/etc/docker/daemon.json` already exists, add the `dns` key to it.

```sh
cat /etc/docker/daemon.json 2>/dev/null        # ha van: a "dns" kulcsot ebbe kell felvenni / if present: add "dns" to it
[ -e /etc/docker/daemon.json ] || echo '{ "dns": ["10.0.0.1", "10.0.0.2"] }' | sudo tee /etc/docker/daemon.json
sudo systemctl restart docker                  # a konténerek is újraindulnak / containers restart too
docker compose up -d --force-recreate
```

## 8. Ügyféltörzs elérhetősége / Master data (EMD) reachability

Minden beállított ügyféltörzs-cím a konténerből: DNS, TCP és HTTPS, a nyers hibaüzenettel
(cURL 6: DNS, 7: elutasítva, 28: időtúllépés, 60: tanúsítvány).

Every configured master-data address from inside the container: DNS, TCP and HTTPS with the raw error
(cURL 6: DNS, 7: refused, 28: timeout, 60: certificate).

```sh
docker compose exec web php artisan tinker --execute 'foreach (["login_url","refresh_url","id_list_url","api_url"] as $k) { $u = config("hdid.sync.$k"); if (! $u) { echo "$k: nincs beállítva
"; continue; } $h = parse_url($u, PHP_URL_HOST); $p = parse_url($u, PHP_URL_PORT) ?: (parse_url($u, PHP_URL_SCHEME) === "http" ? 80 : 443); $ip = filter_var($h, FILTER_VALIDATE_IP) ? $h : gethostbyname($h); echo "$k: $h:$p
  DNS:   ".($ip === $h && ! filter_var($h, FILTER_VALIDATE_IP) ? "NEM OLDÓDIK FEL" : $ip)."
"; $s = @fsockopen($ip, $p, $en, $es, 5); echo "  TCP:   ".($s ? "kapcsolódik" : "HIBA ($es)")."
"; try { $r = Illuminate\Support\Facades\Http::timeout(10)->connectTimeout(5)->get($u); echo "  HTTPS: válaszol (HTTP ".$r->status().")
"; } catch (Throwable $e) { echo "  HTTPS: ".preg_replace("/ \(see .*\$/s", "", $e->getMessage())."
"; } }'
```

## 9. Ügyféltörzs-belépés / Master data (EMD) login

Ugyanaz a belépés, mint az alkalmazásé: hogyan érkezett meg a felhasználónév és a jelszó (hossz, `=` a végén,
szóköz vagy idézőjel), és a válasz szerkezete a tokenek értéke nélkül. A második sor a tokenfrissítést próbálja.

The same login as the application's: how the user name and the password arrived (length, trailing `=`, space
or quote), and the structure of the answer without token values. The second line tries the token refresh.

```sh
docker compose exec web php artisan tinker --execute '$u = config("hdid.sync.username"); $p = (string) config("hdid.sync.password"); echo "Username: ".json_encode($u)."
Password: ".strlen($p)." karakter; =-re végződik: ".(str_ends_with($p, "=") ? "igen" : "nem")."; szóköz vagy idézőjel benne: ".(preg_match("/[\s\"']/", $p) ? "IGEN" : "nem")."
Kérés: POST ".config("hdid.sync.login_url")."
  ".json_encode(["Password" => "***", "Username" => $u], JSON_UNESCAPED_UNICODE)."
"; $r = Illuminate\Support\Facades\Http::acceptJson()->asJson()->withoutRedirecting()->timeout(20)->post(config("hdid.sync.login_url"), ["Password" => $p, "Username" => $u]); echo "Válasz: HTTP ".$r->status().", ".$r->header("Content-Type")."
"; $walk = function ($v, $path) use (&$walk) { if (is_array($v)) { foreach ($v as $k => $x) { $walk($x, $path === "" ? (string) $k : "$path.$k"); } return; } echo "  $path: ".gettype($v).(is_string($v) ? " (".strlen($v)." karakter)" : "")."
"; }; $j = $r->json(); if (is_array($j)) { $walk($j, ""); } else { echo "  nem JSON: ".mb_substr(trim(strip_tags($r->body())), 0, 200)."
"; }'
docker compose exec web php artisan hdid:emd-sync-token
```

## 10. Tanúsítvány-lánc és belső CA / Certificate chain and internal CA

„unable to get local issuer certificate”: a lánc utolsó `i:` sora a hiányzó kiadó. A CA-tanúsítvány PEM-ben a
`ca` mappába, utána újraindítás (az `up -d` nem elég), végül a napló mutatja, hány CA-t vett fel.

"unable to get local issuer certificate": the last `i:` line of the chain is the missing issuer. The CA certificate
goes into `ca` as PEM, then a restart (`up -d` is not enough); the log shows how many CAs were taken.

```sh
host=emd.ceg.local   # a vizsgált kiszolgáló / the server to check
openssl s_client -connect "$host:443" -servername "$host" -showcerts </dev/null 2>/dev/null | grep -E '^ *[0-9]+ s:|^ *i:'

openssl x509 -inform der -in ceg-root-ca.cer -out ca/ceg-root-ca.crt   # DER -> PEM, ha kell / if needed
chmod 644 ca/*.crt
docker compose restart web worker sync-worker scheduler
docker compose logs web | grep 'extra CA'
```

## 11. Egyszeri bejelentkezés (ADFS, Entra) / Single sign-on (ADFS, Entra)

Minden beállított szolgáltató felfedezési címe a konténerből: státusz, kibocsátó, kulcsok száma.

The discovery address of every configured provider from inside the container: status, issuer, number of keys.

```sh
docker compose exec web php artisan tinker --execute 'foreach (App\Enums\PrincipalType::cases() as $t) { foreach (App\Auth\Oidc\OidcProvider::cases() as $p) { $c = $p->configFor($t); if (! $c->isConfigured()) { echo "{$t->value}/{$p->value}: nincs beállítva
"; continue; } echo "{$t->value}/{$p->value}: {$c->discoveryUrl}
"; try { $r = Illuminate\Support\Facades\Http::timeout(10)->connectTimeout(5)->get($c->discoveryUrl); $d = $r->json(); echo "  felfedezés: HTTP ".$r->status().(is_array($d) ? ", issuer ".($d["issuer"] ?? "-") : ", nem JSON")."
"; if (is_array($d) && isset($d["jwks_uri"])) { $k = Illuminate\Support\Facades\Http::timeout(10)->get($d["jwks_uri"])->json("keys"); echo "  kulcsok: ".(is_array($k) ? count($k)." db" : "nem olvasható")."
"; } } catch (Throwable $e) { echo "  HIBA: ".preg_replace("/ \(see .*\$/s", "", $e->getMessage())."
"; } } }'
```

Ha a felfedezés DNS-hibát ad: lásd a névfeloldás pontját fent. / On a DNS error: see the name resolution section above.

## 12. Belépés elutasítva / Sign-in refused

A felület szándékosan semleges; a valódi ok az auditnaplóban (`reason`: `invalid_credentials`,
`account_locked`, `no_permission`, `method_disabled`, `no_external_record`, …). Elfelejtett első rendszergazdai
jelszónál a `make-admin` újrafuttatása új jelszót állít.

The interface is neutral on purpose; the real reason is in the audit log (`reason`). For a forgotten first
administrator password, re-running `make-admin` sets a new one.

```sh
docker compose exec web php artisan tinker --execute 'App\Models\AuditLog::query()->where("event", "login.rejected")->latest()->take(5)->get()->each(fn ($e) => print($e->created_at." ".json_encode($e->context, JSON_UNESCAPED_UNICODE).PHP_EOL));'
docker compose exec web php artisan hdid:make-admin admin@example.org
```

## 13. Levél és SMS / Mail and SMS

Próbaküldés az ügyfelek érintése nélkül, a sor megkerülésével. / A test message that bypasses the queue and
touches no client.

```sh
docker compose exec web php artisan hdid:test-mail cim@example.org --now
docker compose exec web php artisan hdid:test-sms +36301234567 --now
```

## 14. Mentés és visszaállítás / Backup and restore

```sh
./backup.sh
./backup.sh list
./backup.sh restore
```

A „Már fut egy mentés vagy visszaállítás (folyamat: N)” üzenetnél az a futás valóban dolgozik
(`ps -p N`); egy megszakadt futás (újraindítás, `kill -9`) zárját a következő futás magától feloldja.
Ha a visszaállítás „nem várt fájl” miatt áll meg, a mentés beállítás-archívuma olyat tartalmaz, amit
a szkript nem ír bele: ilyen mentést ne állítson vissza. / "A backup or restore is already running
(process: N)" means that run is really working (`ps -p N`); the lock of an interrupted run (reboot,
`kill -9`) is lifted by the next run. A restore stopping on an "unexpected file" means the backup's
configuration archive holds something the script never writes: do not restore that backup.
