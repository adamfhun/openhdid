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

A Docker-démon nem örökli a felhasználó proxyját. Az első két sor megmutatja, van-e proxy (a jelszó helyett
`***`), és látja-e a démon; a két `curl` közül a `HTTP/2 401` a jó válasz (IPv4, illetve IPv6).

The Docker daemon does not inherit the user's proxy. The first two lines show whether there is a proxy (with
`***` for a password) and whether the daemon sees it; for the two `curl` calls `HTTP/2 401` is the good answer
(IPv4 and IPv6).

```sh
env | grep -i proxy | sed -E 's#//[^@/]*@#//***@#'
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

Az alkalmazás kimenő kérései (ügyféltörzs, Exchange, Ozeki SMS, ADFS, Entra) ettől függetlenül a `.env`
`HDID_HTTP_PROXY` kulcsán mennek, a `HDID_NO_PROXY` kivételeivel; a konténer `HTTP_PROXY` változóit nem
használják. Mit használ az alkalmazás (a jelszó nélkül):

The application's outbound requests (master data, Exchange, Ozeki SMS, ADFS, Entra) use `HDID_HTTP_PROXY` in
`.env` instead, except for the `HDID_NO_PROXY` hosts; they ignore the container's `HTTP_PROXY` variables. What
the application uses (without the password):

```sh
docker compose exec web php artisan tinker --execute '$c = app(App\System\HealthChecks::class)->outboundProxy(); echo $c->status->value, ": ", $c->detail, PHP_EOL;'
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

Rootless Dockernél nincs `docker` csoport: a démon a felhasználó egysége, a kliensnek a saját socket kell.

Under rootless Docker there is no `docker` group: the daemon is the user's unit and the client needs its socket.

```sh
systemctl --user status docker
export DOCKER_HOST="unix:///run/user/$(id -u)/docker.sock"
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

Rootless Dockernél a konténer 82-es felhasználója a gazdagépen a `/etc/subuid` tartomány `kezdő + 81` eleme
(például `app:165536:65536` → `165617`); a `sudo chown 82:82` ott idegen fiókhoz rendeli a kulcsot. A tulajdonost a
konténer névterén belül kell beállítani (a telepítési útmutató „Rootless Docker” része):

Under rootless Docker the container's uid 82 is `start + 81` of the `/etc/subuid` range on the host
(for example `app:165536:65536` → `165617`); `sudo chown 82:82` there hands the key to a foreign account. Set the
owner inside the container's user namespace (see "Rootless Docker" in the installation guide):

```sh
grep "^$(id -un):" /etc/subuid /etc/subgid
docker compose run --rm --no-deps --user 0 --cap-add CHOWN -v "$PWD/tls":/t --entrypoint sh web \
  -c 'chown 82:82 /t/fullchain.pem /t/privkey.pem && chmod 444 /t/fullchain.pem && chmod 400 /t/privkey.pem'
ls -ln tls   # a leképezett szám a helyes / the mapped number is right
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
`compose.override.yaml`-ban, minden külső rendszert hívó szolgáltatásnak; a `.env` `COMPOSE_FILE` sora miatt a
Compose ezt a fájlt magától nem olvassa, ezért a sor végére kell írni
(`COMPOSE_FILE=compose.yaml:profiles/5k/compose.yaml:compose.override.yaml`, 10k-nál `profiles/10k/compose.yaml`);
utána `docker compose config --quiet && docker compose up -d`. Tartósan jobb, ha a név bekerül a belső DNS-be. / **If the name is only in the host's `/etc/hosts`** (common on a test
machine): `extra_hosts` in a `compose.override.yaml` next to `compose.yaml`, for every service that calls external
systems; because of the `COMPOSE_FILE` line of `.env`, Compose does not read that file on its own, so add it to the
end of the line (`COMPOSE_FILE=compose.yaml:profiles/5k/compose.yaml:compose.override.yaml`, for 10k
`profiles/10k/compose.yaml`); then `docker compose config --quiet && docker compose up -d`. In the long run, add the
name to the internal DNS.

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
(cURL 6: DNS, 7: elutasítva, 28: időtúllépés, 60: tanúsítvány). Proxyn át elért címnél (`HDID_HTTP_PROXY`)
a nevet a proxy oldja fel, ezért ott csak az út és a HTTPS látszik; a 7-es hiba ekkor a proxyra vonatkozik,
a `CONNECT tunnel failed, response 407` jelszót kér, a `403` tiltott címet, az `5xx` el nem ért (belső) címet
jelent, ez utóbbi a `HDID_NO_PROXY` listába való.

Every configured master-data address from inside the container: DNS, TCP and HTTPS with the raw error
(cURL 6: DNS, 7: refused, 28: timeout, 60: certificate). For an address reached through the proxy
(`HDID_HTTP_PROXY`) the proxy resolves the name, so only the route and HTTPS are shown; error 7 then concerns
the proxy, `CONNECT tunnel failed, response 407` means it wants a password, `403` a forbidden address, `5xx`
an address it cannot reach (an internal one, which belongs into `HDID_NO_PROXY`).

```sh
docker compose exec web php artisan tinker --execute 'foreach (["login_url","refresh_url","id_list_url","api_url"] as $k) { $u = config("hdid.sync.$k"); if (! $u) { echo "$k: nincs beállítva
"; continue; } $h = parse_url($u, PHP_URL_HOST); $p = parse_url($u, PHP_URL_PORT) ?: (parse_url($u, PHP_URL_SCHEME) === "http" ? 80 : 443); echo "$k: $h:$p
"; if (App\Support\OutboundProxy::routes($u)) { echo "  Út:    proxyn át (".App\Support\OutboundProxy::display().")
"; } else { $ip = filter_var($h, FILTER_VALIDATE_IP) ? $h : gethostbyname($h); echo "  DNS:   ".($ip === $h && ! filter_var($h, FILTER_VALIDATE_IP) ? "NEM OLDÓDIK FEL" : $ip)."
"; $s = @fsockopen($ip, $p, $en, $es, 5); echo "  TCP:   ".($s ? "kapcsolódik" : "HIBA ($es)")."
"; } try { $r = Illuminate\Support\Facades\Http::timeout(10)->connectTimeout(5)->get($u); echo "  HTTPS: válaszol (HTTP ".$r->status().")
"; } catch (Throwable $e) { echo "  HTTPS: ".preg_replace("/ \(see .*\$/s", "", $e->getMessage())."
"; } }'
```

Ha az ügyféltörzs HTTP 4xx/5xx hibával válaszol, a válasz törzse a naplóban „EMD export refused” vagy „EMD ID
list query refused” sorként olvasható. HTTP 400 egynél több kijelölt ID-nál: az export kéréstörzsében
`"ids": [{{ ids }}]` kell `"ids": "{{ ids }}"` helyett.

When the master data API answers HTTP 4xx/5xx, its response body is in the log as an "EMD export refused" or
"EMD ID list query refused" line. HTTP 400 with more than one selected ID: write `"ids": [{{ ids }}]` instead of
`"ids": "{{ ids }}"` in the export payload.

```sh
docker compose logs worker | grep 'EMD .* refused'
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

ADFS-belépésnél a `login.rejected` és a `login.succeeded` bejegyzés (parancs a következő pontban) megnevezi a
fiókot kötő igényt (`email_claim`, `email_token`), a kihagyott igényeket az okkal (`claims_passed_over`) és az
access token állapotát (`access_token`); `no_email_claim` okkal a keresett igényeket és a két tokenben talált
igények nevét is (`claims_checked`, `id_token_claims`, `access_token_claims`), értékek nélkül. A keresett igényeket
a `USER_ADFS_EMAIL_CLAIM` / `CLIENT_ADFS_EMAIL_CLAIM` adja meg (üresen `emailaddress,email,upn,preferred_username,unique_name`).
A tokenek teljes tartalmához (fejléc és tartalom az érkezett alakban és visszafejtve, aláírás nélkül) ideiglenesen `HDID_ADFS_DEBUG=true` a `.env`-ben, `docker compose up -d`, egy belépés,
majd `docker compose logs web | grep 'ADFS debug'`; utána vissza `false`-ra (a tokenek személyes adatot tartalmaznak).

Ha a döntő igény a `upn` (`USER_ADFS_EMAIL_CLAIM=email` mellett `no_email_claim`), és az `access_token_claims`
csak `apptype`, `appid`, `authmethod`, `ver`, `scp`, `sub` és időbélyegek: az access token az ADFS userinfo-végpontjának
szól (`aud`: `urn:microsoft:userinfo`), a Web API kiadási szabályai nem futnak. Kell egy Web API az ADFS-alkalmazáscsoportban
(`E-Mail-Addresses` → `E-Mail Address` szabály, a HDID kliense `openid profile email` hatókörrel engedélyezve), és az
azonosítója a `USER_ADFS_RESOURCE` / `CLIENT_ADFS_RESOURCE`. Lépésenként: OpenHDID telepítési útmutató, „ADFS beállítása lépésenként”.

For an ADFS sign-in the `login.rejected` and `login.succeeded` entries (command in the next section) name the claim
that bound the account (`email_claim`, `email_token`), the claims passed over with the reason (`claims_passed_over`)
and the access token's state (`access_token`); with the reason `no_email_claim` also the claims looked for and the
claim names found in both tokens (`claims_checked`, `id_token_claims`, `access_token_claims`), never their values.
`USER_ADFS_EMAIL_CLAIM` / `CLIENT_ADFS_EMAIL_CLAIM` name the claims looked for (empty:
`emailaddress,email,upn,preferred_username,unique_name`). For the full token content (header and payload as received and decoded, without the signature) set `HDID_ADFS_DEBUG=true`
in `.env` for a while, `docker compose up -d`, sign in once, then `docker compose logs web | grep 'ADFS debug'`;
set it back to `false` afterwards (the tokens hold personal data).

If the deciding claim is `upn` (`no_email_claim` with `USER_ADFS_EMAIL_CLAIM=email`) and `access_token_claims` holds only
`apptype`, `appid`, `authmethod`, `ver`, `scp`, `sub` and timestamps, the access token is meant for the ADFS userinfo
endpoint (`aud`: `urn:microsoft:userinfo`) and the Web API's issuance rules do not run. Add a Web API to the ADFS
application group (rule `E-Mail-Addresses` → `E-Mail Address`, the HDID client permitted with `openid profile email`) and
set its identifier as `USER_ADFS_RESOURCE` / `CLIENT_ADFS_RESOURCE`. Step by step: OpenHDID installation guide (Hungarian),
"ADFS beállítása lépésenként".

## 12. Belépés elutasítva / Sign-in refused

A felület szándékosan semleges; a valódi ok az auditnaplóban (`reason`: `invalid_credentials`,
`account_locked`, `no_permission`, `method_disabled`, `no_external_record`, …). Elfelejtett első rendszergazdai
jelszónál a `make-admin` újrafuttatása új jelszót állít (bekapcsolt kétlépcsős belépésnél `--force`-szal; a futás
naplózott, és a többi főrendszergazda e-mailt kap). Az összes parancs: `php artisan list hdid`, egy parancs
súgója: `php artisan help hdid:make-admin`.

The interface is neutral on purpose; the real reason is in the audit log (`reason`). For a forgotten first
administrator password, re-running `make-admin` sets a new one (with two-factor sign-in on, add `--force`; the run
is audited and e-mailed to the other super-admins). All commands: `php artisan list hdid`; the help of one:
`php artisan help hdid:make-admin`.

```sh
docker compose exec web php artisan tinker --execute 'App\Models\AuditLog::query()->where("event", "login.rejected")->latest()->take(5)->get()->each(fn ($e) => print($e->created_at." ".json_encode($e->context, JSON_UNESCAPED_UNICODE).PHP_EOL));'
docker compose exec web php artisan hdid:make-admin admin@example.org
```

Kétlépcsős belépésnél a hibás kódok az `mfa.failed`, a zárolás az `account.mfa_locked` eseményben látszik.
Elveszett második faktor: a Felhasználók listán „Kétlépcsős belépés nullázása”, vagy a lenti első parancs; ha senki
nem tud belépni (például csak SMS engedélyezett, és az átjáró nem működik), a második kikapcsolja.

With two-factor sign-in, wrong codes show as `mfa.failed`, the lock as `account.mfa_locked`. A lost second
factor: "Reset second factor" on the Users list, or the first command below; when nobody can sign in (for
example only SMS is allowed and the gateway is down), the second switches two-factor sign-in off.

```sh
docker compose exec web php artisan hdid:mfa-reset admin@example.org
docker compose exec web php artisan hdid:mfa-reset --off
```

## 13. Levél és SMS / Mail and SMS

Próbaküldés az ügyfelek érintése nélkül, a sor megkerülésével. / A test message that bypasses the queue and
touches no client.

```sh
docker compose exec web php artisan hdid:test-mail cim@example.org --now
docker compose exec web php artisan hdid:test-sms +36301234567 --now
```

Az EWS hibaszövege a HTTP-státusszal kezdődik, és megmondja, mit válaszolt az Exchange. / The EWS
error starts with the HTTP status and says what Exchange answered.

- `HTTP 401 … the server offers: Negotiate, NTLM` – a kiszolgáló csak Windows-hitelesítést kínál:
  `EWS_AUTH=ntlm` és `EWS_USERNAME=TARTOMANY\felhasznalo` (1.8.1-től; az 1.8.0 image libcurl-je már
  NTLM-képes volt, de a kérés hitelesítés nélkül ment ki, és `ntlm` mellett is ugyanez a 401 jött;
  régebbi image-dzsel a basic engedélyezése az EWS virtuális könyvtáron vagy SMTP-összekötő:
  `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT=587`, STARTTLS). Ha `ntlm` mellett 1.8.1-től is 401 jön:
  a tartományvezérlő utasította el a fiókot (név alakja, jelszó, letiltott vagy NTLM-tiltott fiók).
  `HTTP 401 … Basic` basic mellett: jelszó, postaláda; Exchange Online-nál `oauth`. / The server offers
  Windows authentication only: `EWS_AUTH=ntlm` and `EWS_USERNAME=DOMAIN\user` (from 1.8.1; the 1.8.0
  image already had an NTLM-capable libcurl, but the request went out without credentials and the same
  401 came with `ntlm` set; with an older image enable basic authentication on the EWS virtual
  directory, or use an SMTP connector: `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT=587`, STARTTLS). A 401
  with `ntlm` from 1.8.1 on: the domain controller refused the account (name form, password, disabled
  or NTLM-restricted account). `HTTP 401 … Basic` with basic set: password, mailbox; Exchange Online:
  `oauth`.
- `HTTP 404` vagy `HTML answer (sign-in page or proxy?)` – rossz `EWS_URL` (helyesen
  `https://<kiszolgáló>/EWS/Exchange.asmx`), vagy egy bejelentkező oldal, proxy válaszolt. / Wrong
  `EWS_URL` (`https://<host>/EWS/Exchange.asmx`), or a sign-in page or proxy answered.
- `EWS ErrorNonExistentMailbox`, `ErrorAccessDenied` stb. – az Exchange válaszkódja és szövege; a
  szolgáltatásfióknak küldési joga és postaládája kell. / Exchange's own response code and text; the
  service account needs a mailbox and the right to send.
- `could not be reached` – hálózat, tűzfal, kimenő proxy (`HDID_HTTP_PROXY`), belső CA (`ca` mappa,
  utána `docker compose restart web worker`). / Network, firewall, outbound proxy, internal CA.

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

Visszaállítás után mindenki kilép (a mobilalkalmazásban is), a munkamenet- és a gyorsítótár a Valkey-ben is
üres; külső adatbázis külön visszaállítása után ugyanez: `./backup.sh signout` (mindkettő az alkalmazás
`hdid:sign-out-all` parancsát futtatja; kézzel csak leállított `worker`, `sync-worker` és `scheduler` mellett:
`docker compose run --rm --no-deps web artisan hdid:sign-out-all`). A kiléptetés nyoma az auditnaplóban: / After
a restore everyone is signed out (in the mobile app too), the session store and the cache are empty, in Valkey
too; after restoring an external database separately do the same with `./backup.sh signout` (both run the
application's `hdid:sign-out-all` command; by hand only with `worker`, `sync-worker` and `scheduler` stopped:
`docker compose run --rm --no-deps web artisan hdid:sign-out-all`). The sign-out in the audit log:

```sh
docker compose exec web php artisan tinker --execute 'App\Models\AuditLog::query()->where("event", "access.revoked_all")->latest()->take(3)->get()->each(fn ($e) => print($e->created_at." ".json_encode($e->context).PHP_EOL));'
```

## 15. Használati profil fájlja / Usage profile file

```
compose file "profiles/5k/compose.yaml" set by COMPOSE_FILE environment variable is invalid: stat …/profiles/5k/compose.yaml: no such file or directory
```

A `.env` `COMPOSE_FILE` sora a használati profil kiegészítő fájljára mutat, amely nincs a telepítési mappában.
A profil fájlja is a kiadás melléklete (`profile-5k.yaml` → `profiles/5k/compose.yaml`, `profile-10k.yaml` →
`profiles/10k/compose.yaml`), és frissítésnél a `compose.yaml`-lal együtt kell letölteni (UPGRADE.md 3. lépés);
tipikusan akkor hiányzik, ha a `COMPOSE_FILE` kulcs frissítéskor, új kulcsként került a `.env`-be. Minden
`docker compose` parancs ezzel a hibával áll meg, a futó konténereket nem érinti.

The `COMPOSE_FILE` line of `.env` names the usage profile's override file, which is not in the installation
folder. The profile file is a release asset too (`profile-5k.yaml` → `profiles/5k/compose.yaml`,
`profile-10k.yaml` → `profiles/10k/compose.yaml`) and is downloaded together with `compose.yaml` on every
upgrade (UPGRADE.md, step 3); it is typically missing when `COMPOSE_FILE` was added to `.env` as a new key
during an upgrade. Every `docker compose` command stops with this error; running containers are not affected.

```sh
v=$(sed -n 's/^OPENHDID_VERSION=//p' .env)
base=https://github.com/adamfhun/openhdid/releases/download/v$v
curl -fsSL -O "$base/profile-5k.yaml" -O "$base/SHA256SUMS"
sha256sum -c --ignore-missing SHA256SUMS
mkdir -p profiles/5k && mv profile-5k.yaml profiles/5k/compose.yaml
docker compose config --quiet && docker compose up -d
```

Profil nélkül is futtatható a telepítés: a `COMPOSE_FILE` sor törlésével a `compose.yaml` alapértékei élnek
(egy feldolgozó, az image PHP-FPM-alapértékei), és a Rendszerállapot Kapacitás kártyája nem mutat profilt. /
The installation also runs without a profile: delete the `COMPOSE_FILE` line and the defaults of `compose.yaml`
apply (one worker, the image's PHP-FPM defaults), and the Capacity card of the system status shows no profile.

```
openhdid: ERROR: COMPOSE_FILE points to a file that cannot be read: compose.yaml:profiles/5k/compose.yaml
```

Ezt a konténer mondja (a `migrate` 64-es kóddal kilép, a többi szolgáltatás nem indul el), és csak az 1.8.0-s
és régebbi image-et érinti: a `.env` a konténerek `env_file`-ja is, így a `COMPOSE_FILE` bejut a konténerbe,
és az indítószkript titokfájl-mutatónak (`NÉV_FILE`) nézte. Az 1.8.1 image kihagyja. Régebbi image-dzsel a
profil a `compose.override.yaml` néven is használható, amelyet a Compose magától olvas, és nem jut a
konténerbe:

This comes from the container (`migrate` exits with code 64, the other services do not start) and affects
images 1.8.0 and older only: `.env` is the containers' `env_file` too, so `COMPOSE_FILE` reaches the container,
and the start script took it for a secret-file pointer (`NAME_FILE`). The 1.8.1 image skips it. With an older
image the profile also works as `compose.override.yaml`, which Compose reads on its own and does not pass into
the container:

```sh
sed -i '/^COMPOSE_FILE=/d' .env
cp profiles/5k/compose.yaml compose.override.yaml      # ha már van saját compose.override.yaml, abba kell beolvasztani
docker compose config --quiet && docker compose up -d
```

## 16. Demóadat éles környezetben / Demo data in production

A `migrate` 64-es kóddal kilép, a hiba szerint az adatbázisban demóadat van (`.test` tartományú fiókok és
ügyféltörzstételek, demóhívások), ezért a `web`, a `worker` és az ütemező nem indul. Éles telepítéshez üres
adatbázis kell; a demókötetet a feltöltött fájlokkal együtt törölni kell. / `migrate` exits with code 64, the
error says the database holds demo data (accounts and master-data records under `.test`, demo calls), so
`web`, `worker` and the scheduler do not start. A production installation needs an empty database; remove the
demo volumes, uploaded files included.

```sh
docker compose logs migrate | grep -i 'demo data'
docker compose down -v          # a kötetek törlődnek / the volumes are deleted
docker compose up -d
docker compose exec web php artisan hdid:make-admin admin@example.org --name="Rendszergazda"
```

Ha csak néhány valódi fióknak van `.test` címe (téves felismerés), nem kell törölni: átmenetileg
`APP_ENV=staging`, a címek javítása, majd vissza `production`. / If only a few real accounts carry a `.test`
address (a false match), there is no need to delete anything: set `APP_ENV=staging` for a while, fix the
addresses, then go back to `production`.

## 17. „Ismeretlen kiszolgálónév” (400) / "Unknown host name" (400)

Az alkalmazás csak az `APP_URL` gépnevére, a `localhost` névre és az `OPENHDID_EXTRA_HOSTS` neveire szóló
kérést fogadja. / The application only accepts requests for the host of `APP_URL`, `localhost` and the names
in `OPENHDID_EXTRA_HOSTS`.

```sh
grep -E '^(APP_URL|OPENHDID_EXTRA_HOSTS)=' .env
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: masik.example.org' https://hdid.example.org/up -k
```

Javítás a `.env`-ben, majd `docker compose up -d`. Az `OPENHDID_EXTRA_HOSTS` nevei gépi hívásra valók
(telefonközpont, mobil háttérrendszer): böngészőből a panel és a portál csak az `APP_URL` címén működik. /
Fix `.env`, then `docker compose up -d`. The names in `OPENHDID_EXTRA_HOSTS` are for machine calls (call
centre, mobile backend): in a browser the panel and the portal work on the `APP_URL` address only.

## 18. Sok 429 vagy 503 / Many 429 or 503 answers

A 429 („Túl sok kérés érkezett erről a címről…”, `Retry-After: 5`) az nginx kliens-címenkénti korlátja
(alapból 20 kérés/s, 100-as löket, 16 egyidejű kérés; IPv6-on a /64-es hálózat a kulcs; a mentesített hálózatokra nem hat); az 503 („A
szolgáltatás most túlterhelt…”, `Retry-After: 10`) azt jelenti, hogy nincs szabad PHP-FPM folyamat, és a
várakozó sor is tele. / A 429 ("Too many requests from this address…", `Retry-After: 5`) is nginx's limit per
client address (20 requests/s, a burst of 100, 16 concurrent requests by default; the /64 network is the key on
IPv6; exempt networks are not limited); a 503 ("The service is busy right now…", `Retry-After: 10`) means no PHP-FPM process is free and the
waiting queue is full too.

```sh
docker compose logs --since 1h --no-log-prefix web | grep -E 'limiting (requests|connections)' | tail -20
docker compose logs --since 1h --no-log-prefix web | grep -c '"status":429'
docker compose logs --since 1h --no-log-prefix web | grep -c '"status":503'
docker compose logs web | grep -E 'nginx: (at most|no request)'      # a korlát az indításkor / the limit at start
```

- Minden kérés ugyanarról a címről látszik (az access log `ip` mezője az átjáró vagy az elosztó címe): előbb a
  valódi kliens-cím (rootless Docker: `slirp4netns`; terheléselosztó: `OPENHDID_TLS=off` és `TRUSTED_PROXIES`;
  IPv6-NAT). / Every request seems to come from one address (the `ip` field is the gateway or the load
  balancer): restore the real client address first.
- Vállalati NAT mögötti felhasználók: `HDID_RATE_LIMIT_EXEMPT_NETWORKS=203.0.113.0/24` (elemenként legfeljebb
  /8, illetve /32), majd `docker compose up -d`; érvénytelen elemmel a konténer nem indul. / Users behind a
  company NAT: `HDID_RATE_LIMIT_EXEMPT_NETWORKS=203.0.113.0/24` (at most /8 or /32 per entry), then
  `docker compose up -d`; an invalid entry stops the container.
- Csak mérő- vagy tesztpéldányon: `OPENHDID_NGINX_RATE_LIMIT=0`, `OPENHDID_NGINX_CONN_LIMIT=0`. / Only on a
  load-test or test instance: `OPENHDID_NGINX_RATE_LIMIT=0`, `OPENHDID_NGINX_CONN_LIMIT=0`.
- Tartós 503: a Rendszerállapot Kapacitás kártyája; több PHP-FPM folyamat (`OPENHDID_FPM_MAX_CHILDREN`) csak
  nagyobb `OPENHDID_WEB_MEM_LIMIT` mellett, vagy a következő használati profil. / Lasting 503: the Capacity
  tile; more PHP-FPM processes only with a larger `OPENHDID_WEB_MEM_LIMIT`, or the next usage profile.

## 19. SMTP: „TLS required” / SMTP: "TLS required"

SMTP-n a STARTTLS kötelező (`MAIL_REQUIRE_TLS`, alapból `true`): ha a kiszolgáló nem kínálja, a levél nem megy
ki, a Levelezés kártya piros. / STARTTLS is mandatory on SMTP (`MAIL_REQUIRE_TLS`, `true` by default): when the
server does not offer it, the mail is not sent and the Mail tile is red.

```sh
grep -E '^MAIL_(MAILER|HOST|PORT|SCHEME|REQUIRE_TLS)=' .env
docker compose exec web php artisan hdid:test-mail cim@example.org --now
```

Megoldás: STARTTLS a kiszolgálón, vagy `MAIL_SCHEME=smtps` és `MAIL_PORT=465`; `MAIL_REQUIRE_TLS=false` csak
titkosítás nélküli helyi próbakiszolgálóhoz. Utána `docker compose up -d`. / Fix: STARTTLS on the server, or
`MAIL_SCHEME=smtps` with `MAIL_PORT=465`; `MAIL_REQUIRE_TLS=false` only for an unencrypted local test server.
Then `docker compose up -d`.

## 20. Valkey memória és munkamenet-tár / Valkey memory and session store

```sh
docker compose exec valkey valkey-cli -a "$REDIS_PASSWORD" INFO memory | grep -E 'used_memory_human|maxmemory_human|maxmemory_policy'
docker compose exec valkey valkey-cli -a "$REDIS_PASSWORD" INFO stats | grep evicted_keys
docker compose exec valkey-sessions valkey-cli -a "$REDIS_PASSWORD" INFO memory | grep -E 'used_memory_human|maxmemory_human|maxmemory_policy'
docker compose exec valkey-sessions valkey-cli -a "$REDIS_PASSWORD" INFO stats | grep evicted_keys
```

A fő Valkey politikája `noeviction` legyen (kiszorításnál a korlátszámlálók, a zárak és a várakozó feladatok
csendben elvesznének), a munkamenet-Valkey-é `allkeys-lru`. 80 % fölött az `OPENHDID_VALKEY_MAXMEMORY` és az
`OPENHDID_VALKEY_MEM_LIMIT` (a munkamenet-tárnál az `OPENHDID_VALKEY_SESSIONS_*`) emelése, majd
`docker compose up -d`. A munkamenet-tár kiszorításai áradatra vagy kicsi tárra utalnak; a kiszorított
munkamenetek tulajdonosai újra belépnek. / The main Valkey's policy must be `noeviction` (eviction would
silently drop rate counters, locks and queued jobs), the session Valkey's `allkeys-lru`. Above 80 % raise
`OPENHDID_VALKEY_MAXMEMORY` and `OPENHDID_VALKEY_MEM_LIMIT` (`OPENHDID_VALKEY_SESSIONS_*` for the session
store), then `docker compose up -d`. Evictions in the session store mean a flood or a store too small; owners of
evicted sessions sign in again.

Ha az ütemező és a sor kártyája „másik telepítés” miatt piros, egy másik telepítés ugyanazt a Valkey-t és
kulcselőtagot használja: saját Valkey, vagy legalább saját adatbázis, ACL-felhasználó és
`REDIS_PREFIX`/`CACHE_PREFIX` kell (az előtag csak üres sor mellett cserélhető: üzemeltetési kézikönyv 6.
fejezet). / If the scheduler and queue tiles are red because of "another installation", another installation
uses the same Valkey and key prefix: give each its own Valkey, or at least its own database, ACL user and
`REDIS_PREFIX`/`CACHE_PREFIX` (change the prefix only while the queue is empty: operations manual, chapter 6).
