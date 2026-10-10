# Changelog / Változásnapló

All notable changes of OpenHDID releases. The format follows [Keep a Changelog](https://keepachangelog.com/), versions follow [Semantic Versioning](https://semver.org). Every entry is given in Hungarian and English.

Az OpenHDID kiadásainak lényeges változásai. Minden bejegyzés magyarul és angolul is szerepel.

## [1.4.5] – 2026-10-10

### Magyar
Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- ÜZ-01: a beállítások oldal csak az ott módosított mezőket menti; egy korábban megnyitott oldal mentése nem írja vissza azt, amit közben más mentett, és mentés után az oldal a tárolt értékeket mutatja.
- NYT-02: a domainek összevetése előtt az e-mail-címből és a domainlistából eltűnnek a láthatatlan és szóköz jellegű karakterek, a „Név <cím>” és a „mailto:” alak a címre egyszerűsödik; a besorolatlan kihagyott sor a vizsgált domaint, a futás a használt domainlistákat mutatja.

Részletek:
- Hibajavítás: egyetlen munkatársi domain mellett egy próbafuttatás minden sort „A domain egyik besorolási listában sem szerepel” okkal kihagyott, mert az átvétel által olvasott munkatársi domainlista üres volt. A beállítások oldal mentéskor minden mezőt elküldött, és a tárolttól eltérőt kiírta, így egy korábban megnyitott oldal mentése visszaírta a közben más által (vagy más lapon) mentett értéket. Mostantól csak az oldalon módosított mezők íródnak.
- Megelőzés: az e-mail-cím és a domainlista az összevetés előtt megtisztul a láthatatlan és szóköz jellegű karakterektől (például a táblázatból jövő nem törő vagy nulla szélességű szóköz), a „Név <cím>” és a „mailto:” alak a címre egyszerűsödik; a tisztítás a már tárolt domainlistára is hat. Ha egy korábban átvett tétel e-mail-címe ilyen karaktert vitt, a következő átvétel a tisztított címre javítja; ez címváltozásnak számít, a fiók meglévő belépései megszűnnek.
- A futás részletei (Ügyféltörzs-átvételek) az „Érkező rekordok domainszűrés után” blokkban megmutatják a futás munkatársi és ügyfél-domainjeit, a besorolatlan kihagyott sor a vizsgált domaint (`domain=…`).
- ID-lista (Beállítások › Ügyféltörzs (EMD)): a hosszú név nagyjából 65–70 karakter után több sorba tördelődik, keskeny képernyőn hamarabb, így nem tolja ki oldalra a többi oszlopot; a sor többi értéke függőlegesen középre igazodik. A demóadatok ID-listája egy hosszú nevű szervezettel bővült, ezen látszik a tördelés.
- A dokumentumok verziója 1.4.5.

### English
Rule changes in one sentence each (with the specification's identifiers):
- ÜZ-01: the settings page saves only the fields changed on it; saving a page opened earlier no longer writes back what someone saved in the meantime, and after saving the page shows the stored values.
- NYT-02: before domains are compared, whitespace-like and invisible characters are removed from the e-mail address and the domain lists, and a "Name <address>" or "mailto:" form is reduced to the address; an unclassified skipped row shows the compared domain, and the run records the domain lists it used.

Details:
- Bug fix: with a single staff domain, a dry run skipped every row as "the domain is on neither classification list", because the staff domain list the sync read was empty. The settings page sent every field on save and wrote whatever differed from the stored value, so saving a page opened earlier wrote back a value someone had saved in the meantime (or in another tab). Now only the fields changed on the page are written.
- Prevention: the e-mail address and the domain lists are cleaned of whitespace-like and invisible characters before comparing (for example a no-break or zero-width space from a spreadsheet), and a "Name <address>" or "mailto:" form is reduced to the address; the cleaning also applies to domain lists already stored. When a record imported earlier carried such a character in its e-mail, the next sync corrects it to the cleaned address; this counts as an address change, so the account's existing sign-ins end.
- The run details (Master data syncs) show the run's staff and client domains in the "Incoming records after domain filtering" block, and an unclassified skipped row its compared domain (`domain=…`).
- ID list (Settings › Master data (EMD)): a long name wraps after about 65–70 characters, earlier on a narrow screen, so it no longer pushes the other columns out of view; the other values of the row are centred vertically. The demo ID list gained an organisation with a long name that shows the wrapping.
- The documents carry version 1.4.5.

## [1.4.4] – 2026-10-10

### Magyar
**Frissítés előtt:** két új, választható telepítési kulcs: `HDID_HTTP_PROXY` és `HDID_NO_PROXY` (üresen minden marad a régiben). Ha a konténerek eddig a környezetből (például a Docker kliens `proxies` beállításából) kapott `HTTP_PROXY`/`HTTPS_PROXY` változón keresztül értek ki, ezt mostantól a `HDID_HTTP_PROXY` adja: a worker és az ütemező eddig követte a környezet proxyját, a web nem, az alkalmazás mostantól egyiket sem használja.

Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- ÜZ-07: a telepítés megadhat egy kimenő proxyt, amelyen az alkalmazás minden kimenő kérése (ügyféltörzs, Exchange, SMS-átjáró, ADFS, Entra) megy, a felsorolt belső kiszolgálók és maga a szerver kivételével; felhasználónévvel és jelszóval is, Windows-hitelesítésű (NTLM) proxy nélkül (megrendelői döntés); proxy nélkül minden kérés közvetlenül megy.

Részletek:
- Új telepítési kulcsok: `HDID_HTTP_PROXY` (például `http://proxy.example.org:3128`, jelszóval `http://felhasznalo:jelszo@proxy.example.org:3128`, a különleges karakterek %-kódolva) és `HDID_NO_PROXY` (vesszővel: név az aldomainjeivel, IP-cím, `10.0.0.0/8` alakú tartomány; a `localhost`, a `127.0.0.0/8` és a `::1` mindig közvetlen). A web-, a worker- és az ütemező-konténer egyformán használja; az SMTP, az adatbázis és a Valkey nem HTTP, az image letöltése továbbra is a Docker-démon proxyján megy.
- Hibás `HDID_HTTP_PROXY` címmel (nem támogatott séma, hiányzó gép, útvonal) a konténer nem indul; az érték, mivel jelszót tartalmazhat, nem kerül a naplóba.
- Új Rendszerállapot-kártya: „Kimenő proxy” (a cím jelszó nélkül és a kivételek; hibás címnél piros).
- Az ügyféltörzs kapcsolódási hibája proxyn át a proxyt is megnevezi, és megmondja, hogy a proxy nem érhető el, felhasználónevet és jelszót kér (407), nem engedi a címet (403), vagy nem éri el a címet (5xx: belső kiszolgáló a `HDID_NO_PROXY`-ba).
- `TROUBLESHOOTING.md`: a 4. pont mutatja, milyen proxyt használ az alkalmazás, a 8. pont ügyféltörzs-ellenőrzése proxyn át elért címnél az utat és a HTTPS-próbát adja; a felhasználó proxyváltozóit kiíró parancs a jelszót `***`-gal takarja.
- A Docker-démon proxyja és az alkalmazás proxyja külön beállítás; az OpenHDID telepítési útmutató és üzemeltetési kézikönyv mindkettőt leírja.
- A dokumentumok verziója 1.4.4.

### English
**Before upgrading:** two new, optional deployment keys: `HDID_HTTP_PROXY` and `HDID_NO_PROXY` (empty keeps everything as before). If the containers reached out through `HTTP_PROXY`/`HTTPS_PROXY` taken from the environment (for example the Docker client's `proxies` setting), `HDID_HTTP_PROXY` now has to give it: the worker and the scheduler used to follow the environment's proxy and the web container did not; the application now uses neither.

Rule changes in one sentence each (with the specification's identifiers):
- ÜZ-07: a deployment can name an outbound proxy for every outbound request of the application (master data, Exchange, SMS gateway, ADFS, Entra), except the listed internal servers and the server itself; with a user name and password too, without Windows (NTLM) proxy sign-in (owner decision); without one every request goes direct.

Details:
- New deployment keys: `HDID_HTTP_PROXY` (for example `http://proxy.example.org:3128`, with a password `http://user:password@proxy.example.org:3128`, special characters percent-encoded) and `HDID_NO_PROXY` (comma-separated: a name with its subdomains, an IP address, a range such as `10.0.0.0/8`; `localhost`, `127.0.0.0/8` and `::1` always go direct). The web, worker and scheduler containers use it alike; SMTP, the database and Valkey are not HTTP, and pulling the image still goes through the Docker daemon's proxy.
- With an unusable `HDID_HTTP_PROXY` (unsupported scheme, no host, a path) the container does not start; the value is never logged, as it may carry a password.
- New status tile: "Outbound proxy" (the address without the password and the exceptions; red for an unusable address).
- A master-data connection failure through the proxy names the proxy too and tells whether the proxy is unreachable, asks for a user name and password (407), refuses the address (403) or cannot reach it (5xx: an internal server belongs into `HDID_NO_PROXY`).
- `TROUBLESHOOTING.md`: point 4 shows which proxy the application uses, the master-data check of point 8 gives the route and the HTTPS test for an address reached through the proxy; the command printing the user's proxy variables masks the password with `***`.
- The Docker daemon's proxy and the application's proxy are separate settings; the OpenHDID installation guide and operations manual (Hungarian) describe both.
- The documents carry version 1.4.4.

## [1.4.3] – 2026-10-10

### Magyar
**Frissítés előtt:** az indításkori ellenőrzés szigorúbb. Élesben (`APP_ENV=production`) a konténer nem indul üres `DB_PASSWORD`-del, Redis-t használó beállítás mellett üres `REDIS_PASSWORD`-del, vagy mindenkit megbízhatónak vevő `TRUSTED_PROXIES` értékkel (`*`, `**`, `0.0.0.0/0`, `::/0`); ismeretlen `APP_ENV` értékkel (például `prod`) semmilyen környezetben. Az ok a `docker compose logs web` `openhdid: ERROR:` sorában áll. A mobilalkalmazás kiszolgálójának kulcsánál az aláírás kötelező lett: egy aláíró titok nélkül kiadott mobilkulcs minden kérését elutasítja a rendszer (`401`, `signature_required`), amíg a Rendszer › API kulcsok › Aláíró titok művelettel titkot nem kap; ezt a mobil kiszolgáló üzemeltetőjével egyeztetve, a frissítéssel egy időben kell megtenni (a Rendszerállapot addig piros).

Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- ADM-02.4: ha a hívás elkerül az ügyintézőtől (elengedi, a vezérlőpultról is, vagy egy kolléga átveszi), az ő kérdés-válasz munkamenete ezen a híváson lezárul, és a nyitva maradt oldaláról döntés nem rögzíthető.
- NYT-03: ha az ügyféltörzs megváltoztatja, ki áll a fiók mögött (új e-mail-cím, újra kiadott tétel, ütköző cím), a fiók minden meglévő belépése megszűnik (munkamenetek, „emlékezz rám”, mobil bejelentkezés, élő hivatkozások és kódok).
- BEL-01.1: a panelen megjelenített belépési hivatkozást az eseménynapló megjelöli, a vele tett belépés bejegyzése megnevezi a munkatársat.
- BEL-01.2: az egyszerre küldött SMS-kód-tippek is egyenként számítanak a kódonkénti és a fiókzárolási korlátba.
- BEL-01.5, BEL-04.2: az „Emlékezz rám” kikapcsolása a korábban kiadott sütiket is azonnal érvényteleníti.
- BEL-01: a belépési cím pontosan egyezzen a fiókéval (kis- és nagybetű kivételével); a válasz ideje sem árulja el, van-e jogosult fiók.
- BEL-01.4: a mobil ID token aláírásának átkódolása nem teszi újjá a tokent.
- AZO-01: az egyszeri azonosító kód és az IVR-kód egymás között is egyedi.
- AZO-03: a foglalt PIN miatti elutasítás naplózott, fiókonként napi öt után aznap a PIN nem módosítható.
- NYT-13.2: az ID-lista soronkénti gombja rögzített jelentésű (Kijelölés, Levétel); a „csak ezek” a listáról átmenetileg lekerült, kijelölt ID-ket is leveszi; a beillesztés legfeljebb 100 000 karakter.
- CC-03, MOB-01: a mobilalkalmazás kiszolgálójának kulcsa az aláíró titokkal együtt készül, minden kérése aláírt, a titka nem vehető le (megrendelői döntés).
- CC-01: a telefonközpont válaszai (hívásesemény, hívás állapota, szám szerinti lekérdezés) nem tartalmazzák a szolgáltatási szintet; a név marad (megrendelői döntés).

Részletek:
- Biztonsági felülvizsgálat négy párhuzamos áttekintéssel (az új funkciók, a mentés és a konténer, a belépési utak, a gépi felületek és az azonosítás); kritikus vagy magas súlyú hiba nem volt, minden javítás előbb elbukó teszttel készült.
- A hívás másik ügyfélre helyezése az azonosítás oldalról a hívás zárján belül fut, így egy közben történt átvétel nem írható felül.
- A CSRF-kivétel bearer-kérésnél munkatársi munkamenet mellett sem érvényes, és nem léptet be „emlékezz rám” sütiből.
- A dokumentációs oldalak (`/docs/*`) tartalombiztonsági szabályzata a teljes `unpkg.com` helyett csak a rögzített Stoplight Elements csomagútvonalat engedi.
- `backup.sh`: a beállítás-archívumban csak a várt fájlok lehetnek, hivatkozás nélkül (egy módosított mentés nem írhatja felül például magát a szkriptet); az adatbázis sandbox módban töltődik vissza; a TLS-kulcs a 700-as `backup` mappában készül elő, a `tls` mappa nem nyílik meg más helyi felhasználónak; minden elutasító ellenőrzés a leállítás előtt fut, és a sikertelen leállítás vagy biztonsági mentés megállítja a visszaállítást; a visszaállítás üríti a munkameneteket és a gyorsítótárat (mindenkinek újra be kell lépnie); a héjban exportált `COMPOSE_PROJECT_NAME`, `COMPOSE_FILE`, `COMPOSE_PROFILES` nem téríti el; a megszakadt futás zárját felismeri; a visszaállítás előtti biztonsági mentésekből a legutóbbi 3 marad. Az ellenőrzőösszeg üzenete pontosabb: a sérülést mutatja ki, a szándékos módosítást nem.
- A demóadatok ID-listát is tartalmaznak (húsz szervezet, öt kijelölve), így a kipróbálás során az ID-lista táblázata is látható.
- Gépi felület: a `tier` mező kikerült a `/api/v1/callcenter/calls`, a `calls/{call_id}` és a `lookup` válaszából (a hívásnál és a híváshoz rendelt ügyfélnél is). Leképezetlen hívósornál a hívás szintje a felismert ügyfélé volt, így a kulcs birtokosa megtudhatta, ki prémium ügyfél.
- A mobil és a telefonközponti felületleírás (`/docs/mobile`, `/docs/ivr`) aláírási része teljes: hatókör szerint kötelező vagy feltételes fejlécek, az aláírt szöveg pontos felépítése, elutasítási okok üzenetekkel, tesztvektor, a titok kiesés nélküli cseréje, kódminták (shell, Node.js, PHP, Python, Java, C#). A `hdid:api-key:create … mobile_backend` a titkot is kiírja.
- A felhasználói kézikönyv minden képernyőképe frissült, új kép mutatja az ID-lista táblázatát. A dokumentumok verziója 1.4.3.

### English
**Before upgrading:** the startup check is stricter. In production (`APP_ENV=production`) the container does not start with an empty `DB_PASSWORD`, with an empty `REDIS_PASSWORD` while Redis is used, or with a `TRUSTED_PROXIES` value that trusts everyone (`*`, `**`, `0.0.0.0/0`, `::/0`); with an unknown `APP_ENV` value (for example `prod`) in no environment at all. The reason is on the `openhdid: ERROR:` line of `docker compose logs web`. Signing is now mandatory for the mobile app backend key: every request of a mobile key issued without a signing secret is refused (`401`, `signature_required`) until it gets one under System › API keys › Signing secret; do this together with the operator of the mobile backend, at the time of the upgrade (system status stays red until then).

Rule changes in one sentence each (with the specification's identifiers):
- ADM-02.4: when a call leaves the agent (released, from the dashboard too, or taken over by a colleague), their question-and-answer session on it ends, and no verdict can be recorded from the page left open.
- NYT-03: when the master data changes who is behind an account (new e-mail, reissued record, conflicting address), every existing sign-in of the account ends (sessions, remember-me, mobile sign-in, live links and codes).
- BEL-01.1: a login link shown in the panel is marked in the audit log, and the sign-in made with it names the staff member.
- BEL-01.2: SMS code guesses sent at the same moment count one by one towards the per-code and the lockout limits.
- BEL-01.5, BEL-04.2: switching remember-me off also invalidates the cookies issued before.
- BEL-01: the sign-in address must match the account exactly (case aside); the answer time does not tell whether an eligible account exists either.
- BEL-01.4: re-encoding the signature of a mobile ID token does not make it a new token.
- AZO-01: one-time identification codes and IVR codes are unique across both kinds.
- AZO-03: a PIN refused as already in use is audited, and after five in a day the account's PIN cannot be changed until the next day.
- NYT-13.2: the ID list row buttons have a fixed meaning (Select, Deselect); "only these" also deselects selected IDs that are off the list for now; a paste is limited to 100,000 characters.
- CC-03, MOB-01: a mobile app backend key comes with its signing secret, every request is signed, and the secret cannot be removed (owner decision).
- CC-01: the call-center responses (call event, call state, lookup by number) no longer carry the service level; the name stays (owner decision).

Details:
- Security review with four parallel passes (new features, backup and container, sign-in paths, machine interfaces and identification); nothing critical or high was found, and every fix started with a failing test.
- Moving a call to another client from the identification page runs inside the call lock, so a take-over in between cannot be overwritten.
- The CSRF exemption for bearer requests no longer applies with a staff session either, and it never signs anyone in from a remember-me cookie.
- The content security policy of the documentation pages (`/docs/*`) admits only the pinned Stoplight Elements package path instead of all of `unpkg.com`.
- `backup.sh`: the configuration archive may hold only the expected files, no links (a tampered backup cannot overwrite, for example, the script itself); the database is loaded in sandbox mode; the TLS key is prepared inside the 700 `backup` folder, the `tls` folder never opens up to other local users; every refusing check runs before anything stops, and a failed stop or safety backup halts the restore; a restore empties sessions and the cache (everyone signs in again); `COMPOSE_PROJECT_NAME`, `COMPOSE_FILE` and `COMPOSE_PROFILES` exported in the shell no longer redirect it; a lock left by an interrupted run is recognised; the latest 3 safety backups are kept. The checksum message is more precise: it detects damage, not deliberate changes.
- The demo data includes an ID list (twenty organisations, five selected), so the ID list table can be tried out too.
- Machine API: the `tier` field is gone from the responses of `/api/v1/callcenter/calls`, `calls/{call_id}` and `lookup` (for the call and its matched client). With an unmapped queue the call's level was the matched client's, so a key holder could learn who is a premium client.
- The signing part of the mobile and call-center interface documents (`/docs/mobile`, `/docs/ivr`) is complete: headers required or conditional by scope, the exact signed text, rejection reasons with messages, a test vector, changing the secret without an interruption, code samples (shell, Node.js, PHP, Python, Java, C#). `hdid:api-key:create … mobile_backend` prints the secret too.
- Every screenshot of the user manual (Hungarian) is new, and a new one shows the ID list table. The documents carry version 1.4.3.

## [1.4.2] – 2026-10-09

### Magyar
Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- NYT-11: ha az ügyféltörzs nem érhető el (belépés, ID-lista, export), a hibaüzenet megnevezi a kiszolgálót és az okot (DNS, elutasított kapcsolat, időtúllépés, ellenőrizhetetlen tanúsítvány) teendő-tanáccsal, a kérés adatai nélkül.
- NYT-13: az ID-lista lekérdezésénél a belépés hibája is az ID-lista állapotában és az eseménynaplóban látszik.
- NYT-13.2: az ID-lista a Beállítások › Ügyféltörzs (EMD) fülön lapozott, kereshető táblázat; a kijelölés azonnal érvényes, soronként, az oldal bejelölt soraira tömegesen, vagy beillesztett ID-kkel (hozzáadás vagy „csak ezek”); levétel előtt megerősítés a nevekkel.
- NYT-13.3: a beállítások bármilyen sorrendben menthetők, a `{{ ids }}`-s export hiányzó ID-lista vagy kijelölés mellett is; az oldal felsorolja, mi hiányzik, és az átvétel addig nem indul. A kijelölés auditja műveletenként egy összesítő esemény.
- NYT-11: ha a belépésre adott válasz nem a várt alakú, az üzenet a HTTP-státuszt és a kapott mezők nevét mondja (értékek nélkül), vagy azt, hogy a válasz nem JSON.

Részletek:
- A három kapcsolódási hiba eddig általános szöveg volt („az ügyféltörzs API-ja nem érhető el”), mert a nyers kivétel a kérés hozzáférési adatait is hordozhatja. Most csak a cURL-hibakód és a cím hosztja, portja kerül ki belőle, magyarázattal (például „a név a konténerből nem oldható fel (DNS); belső névhez … extra_hosts”). Az üzemeltetési kézikönyvek egy bemásolható diagnosztikát is adnak, amely a konténer szemszögéből minden ügyféltörzs-címet ellenőriz (DNS, TCP, HTTPS), és a belépés kézi próbáját.
- ID-lista: a jelölőnégyzet-lista helyén lapozott táblázat (név, ID, kijelölve, listán), keresés névre és ID-re, szűrők, CSV-export; „Kijelölés”, „Levétel” (megerősítéssel), tömeges művelet az oldal bejelölt soraira, „Kijelölés ID-k alapján” hozzáadás vagy „csak ezek” módban. A lekérő gomb a táblázat fejlécébe került. Az auditnaplóban a kijelölés változása mostantól műveletenként egy `sync.id_list.selection_changed` esemény (darabszám, legfeljebb 100 ID) a korábbi tételenkénti `sync_id_list_item.updated` sorok helyett; aki ezekre szűr, annak ez változás.
- Mentési sorrend: a `{{ ids }}`-s export akkor is menthető, ha az ID-lista címe, kéréstörzse, letöltése vagy a kijelölés még hiányzik; a szakasz és a mentés utáni figyelmeztetés felsorolja a hiányzó lépéseket, az átvétel addig nem indul.
- Üzemeltetési kézikönyv: a `ca` mappába tett belső CA után `docker compose restart web worker sync-worker scheduler` kell; a kézikönyv eddig tévesen `docker compose up -d`-t írt, ami változatlan beállításnál nem indít újra. A lánc kiolvasása és a PEM/DER-átalakítás is leírva.
- Dokumentumok: mostantól mind a termék verzióját viselik (1.4.2), külön dokumentumverzió nincs.
- Új melléklet: `TROUBLESHOOTING.md`, másolható diagnosztikai parancsok egy helyen (állapot, naplók, proxy, Docker-jogosultság, fájltulajdonos, ügyféltörzs-elérés és -belépés, tanúsítvány-lánc és belső CA, ADFS/Entra-felfedezés, a belépés elutasításának oka, levél és SMS, mentés); titkot egyik sem ír ki.
- Belső nevek feloldása (telepítési útmutató 6.7, `TROUBLESHOOTING.md`): a konténer a gazdagép DNS-kiszolgálóit kérdezi, a gazdagép `/etc/hosts`-át nem látja; `extra_hosts` csak az ott szereplő nevekhez kell, helyi feloldós vagy VPN-es gazdagépnél a belső DNS-t a `/etc/docker/daemon.json` `dns` kulcsa adja. A DNS-hiba üzenete ugyanezt tanácsolja.
- Új melléklet: `UPGRADE.md`, a frissítés lépései másolható parancsokkal, a kiadás verziójával kitöltve (mentés, letöltés ellenőrzőösszeggel, az új telepítési kulcsok listája, frissítés, ellenőrzés, visszalépés).

### English
Rule changes in one sentence each (with the specification's identifiers):
- NYT-11: when the master-data system cannot be reached (login, ID list, export), the message names the host and the reason (DNS, refused connection, timeout, unverifiable certificate) with a hint, without the request's data.
- NYT-13: a failed login during the ID list query also shows in the ID list status and the audit log.
- NYT-13.2: the ID list on Settings › Master data (EMD) is a paged, searchable table; selection takes effect at once, per row, in bulk for the checked rows of the page, or by pasted IDs (add, or "only these"); a deselection asks first, naming the IDs.
- NYT-13.3: settings save in any order, an export with `{{ ids }}` too while the ID list or the selection is still missing; the page lists what is missing and the sync does not run until then. A selection change is audited as one summary event.
- NYT-11: an unusable login answer is reported with its HTTP status and the received field names (no values), or as not being JSON.

Details:
- The three connection errors were a generic text ("the EMD API is not reachable"), because the raw exception can carry the request's credentials. Now only the cURL error code and the address's host and port are taken from it, with an explanation (for example "the name does not resolve inside the container (DNS); an internal name needs … extra_hosts"). The operations manuals add a copy-paste diagnostic that checks every master-data address from inside the container (DNS, TCP, HTTPS), and a manual login test.
- ID list: a paged table replaces the checkbox list (name, ID, selected, listed), with search by name and ID, filters, CSV export; Select, Deselect (with a confirmation), bulk actions on the checked rows of the page, and Select by IDs in add or "only these" mode. The fetch button moved to the table header. In the audit log a selection change is now one `sync.id_list.selection_changed` event per action (count, at most 100 IDs) instead of one `sync_id_list_item.updated` row per item; filters on the latter need updating.
- Save order: an export with `{{ ids }}` saves even while the ID list address, payload, fetch or selection is missing; the section and a warning after saving list the missing steps, and the sync does not run until then.
- Operations manual (Hungarian): after adding an internal CA to the ca folder, `docker compose restart web worker sync-worker scheduler` is needed; the manual wrongly said `docker compose up -d`, which does not restart unchanged services. Reading the chain and converting PEM/DER are described too.
- Documents now all carry the product version (1.4.2); there are no separate document versions.
- New release asset: `TROUBLESHOOTING.md`, copyable diagnostic commands in one place (state, logs, proxy, Docker permissions, file ownership, master-data reachability and login, certificate chain and internal CA, ADFS/Entra discovery, why a sign-in was refused, mail and SMS, backup); none prints a secret.
- Internal names (installation guide 6.7, `TROUBLESHOOTING.md`): the container asks the host's DNS servers but does not see the host's `/etc/hosts`; `extra_hosts` is only needed for names listed there, and a host with a local resolver or VPN DNS gives Docker the internal DNS through the `dns` key of `/etc/docker/daemon.json`. The DNS error message says the same.
- New release asset: `UPGRADE.md`, the upgrade steps as copyable commands with the release version filled in (backup, verified download, list of new deployment keys, upgrade, check, going back).

## [1.4.1] – 2026-10-09

### Magyar
Szabályváltozások egy mondatban: nincs, az alkalmazás működése nem változott.

Részletek:
- Új melléklet: `backup.sh`, mentés és visszaállítás egy paranccsal. A szkript a saját mappájában `backup/ÉÉÉÉ-HH-NN_óóppmm` mappába ment (adatbázis konzisztens pillanatképként, storage kötet, beállítások, tls; 700/600-as jogok, ellenőrzőösszegek, a legutóbbi 14 marad), a `restore` megerősítés és biztonsági mentés után visszaállítja a teljes telepítést a mentett verzióval, a `--quiet` cronhoz való. Root nem kell (sem sudo, sem root jogú konténer): a tls kulcsát az alkalmazás saját felhasználója olvassa és írja, így rootful és rootless Dockerrel is a konténer által látott tulajdonos áll vissza. A kiadás füsttesztje a teljes kört lefuttatja.
- Telepítési útmutató 1.12: a `backup.sh` letöltése; a tanúsítvány elhelyezése sudo nélkül, rootless Dockerrel is (ott a `sudo chown 82:82` nem jó). Üzemeltetési kézikönyv 1.7: a mentés fejezete a szkriptre épül, új gépre történő visszaállítással.

### English
Rule changes in one sentence each: none, the application's behaviour is unchanged.

Details:
- New release asset: `backup.sh`, backup and restore in one command. The script writes into `backup/YYYY-MM-DD_hhmmss` next to itself (database as a consistent snapshot, storage volume, settings, tls; 700/600 permissions, checksums, the latest 14 kept); `restore` brings back the whole installation with the saved version after a confirmation and a safety backup; `--quiet` suits cron. No root is needed (neither sudo nor a root container): the application's own user reads and writes the TLS key, so rootful and rootless Docker alike get back the owner the container sees. The release smoke test runs the full round trip.
- Installation guide 1.12 (Hungarian): downloading `backup.sh`; placing the certificate without sudo, also under rootless Docker (where `sudo chown 82:82` is wrong). Operations manual 1.7: the backup chapter builds on the script, including a restore onto a new machine.

## [1.4.0] – 2026-10-09

### Magyar
Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- ÜZ-06: a „Forráskód” hivatkozás a láblécekben a `SHOW_SC` telepítési kulccsal kikapcsolható; alapértéke `true`, tehát a meglévő telepítések változatlanul mutatják.
- BEL-01: a kiküldött belépési hivatkozás panelbeli megjelenítése az új „E-mail link megjelenítése a panelen küldés után” beállításon áll (alapból ki), nem a debug módon; bekapcsolva a Rendszerállapot figyelmeztet.
- BEL-03: a csomagnevek összevetése egységes: kis- és nagybetű, ékezet és felesleges szóköz nem számít, minden más karakter megkülönböztet; ugyanez él a listákban, a kapcsolatoknál és az adatbázis-szűrőkben.
- ADM-01: a főügyfél implicit csomagjának kiürítése is megerősítést kér; a megerősítés megnevezi a választott csomagot, a prémium lista neveit és név szerint a megszűnő kapcsolatokat, és csak akkor jelenik meg, ha kapcsolat valóban megszűnik.
- ADM-02a: a hívás elengedése egy kattintás; megerősítést csak folyamatban lévő kérdés-válasz munkamenet mellett kér. Megerősítés csak visszafordíthatatlan, kifelé ható, költséges vagy más munkáját érintő műveletnél jelenik meg, a következmény megnevezésével; az újranyitás, a figyelmeztetés visszakapcsolása és a várakozó üzenet visszavonása megerősítés nélkül megy.
- NYT-10: a kéréstörzs hibás JSON-ja sor- és oszlopszámmal és a hiba fajtájával utasítódik el; az idézőjelen kívüli helyőrzőre külön üzenet jön.
- NYT-11: az Ügyféltörzs-átvételek oldala a futtató gombok mellett mutatja az EMD API-token állapotát.

Részletek:
- Új telepítési kulcs `SHOW_SC` (alapértéke `true`): `false` esetén a portál és a panel lábléce nem mutatja a „Forráskód” hivatkozást akkor sem, ha a `HDID_SOURCE_URL` meg van adva; az AGPL-3.0 13. pontja szerint a forrást ekkor más úton kell felajánlani. Telepítési útmutató 1.11 és a `.env.example` leírja.
- Javítás: a `/docs/ivr`, `/docs/mobile` és `/docs/api` felületleírás-oldalak fehér lapot adtak, mert a tartalombiztonsági szabályzat tiltotta a megjelenítő (Stoplight Elements) `unpkg.com` forrását; a szabályzat ezeken az oldalakon engedi, minden más oldalon változatlan. Az útmutató kimondja, hogy a megjelenítőhöz a böngészőnek internet kell, zárt hálózaton a JSON letölthető.
- Javítások a próbatelepítés nyomán: az `APP_DEBUG=true` éles elutasítása megmondja a kiutat (hibakeresésre `APP_ENV=staging`, utána visszaállítás; az üzemeltetési kézikönyv 5. fejezete leírja); a főügyfél csomagváltásának megerősítése megnevezi a választott csomagot, a prémium lista neveit és név szerint a megszűnő kapcsolatokat, és a kiürített implicit csomag is változásnak számít; a kéréstörzs idézőjelen kívül írt helyőrzőjére (`"ids": {{ ids }}`) külön hibaüzenet jön az általános „érvénytelen JSON” helyett, egyéb hibánál a feldolgozó üzenete; az Ügyféltörzs-átvételek oldal alcíme a futtató gombok mellett az EMD API-token állapotát mutatja (utolsó sikeres frissítés, feltehetően érvényes-e, hiba oka).
- Megerősítő ablakok felülvizsgálata. Hibajavítás: az ügyfél adatlapjának és a Beállításoknak a Mentés gombja, valamint az azonosító oldal hívás-elengedése akkor is megerősítő ablakot nyitott, ha nem volt mit megerősíteni (innen a „0 aktív kapcsolat megszűnik” ablak); a Filament egyedi címmel vagy leírással a feltételtől függetlenül megnyitja az ablakot, ezért a feltétel most az ablak megnyitását is vezérli. Megerősítés nélkül, egy kattintással megy: a hívás elengedése a vezérlőpultról és kérdés-válasz munkamenet nélkül, az újranyitás (ügyfél, munkatárs), a figyelmeztetés visszakapcsolása, a várakozó üzenet visszavonása. A megmaradt megerősítések megnevezik a következményt (PIN törlése, munkatárs lezárása, újraküldés, éles átvétel, sablon visszaállítása, API-kulcs visszavonása és aláírás eltávolítása).
- Tesztkörnyezeti belépés debug mód nélkül: a „Belépési link küldése” értesítése a hivatkozást is mutatja, ha a Beállítások › Ügyfél belépés új kapcsolója be van kapcsolva; új rendszerállapot-csempe („Belépési hivatkozás előnézete”) sárga, amíg be van kapcsolva. Csomagnév-egységesítés egy helyen (`PackageName`): az ügyféltörzsből ékezettel vagy másképp írt csomagnév is a listához talál, az adatbázis-szűrők a táblában lévő pontos alakokat kapják. A kéréstörzs JSON-hibáinál sor- és oszlopszám, a hiba fajtájával (felesleges vessző a záró jel előtt, aposztróf, idézőjel nélküli kulcs vagy érték, lezáratlan szöveg, hiányzó vessző vagy kettőspont).
- Telepítési útmutató 1.11 és üzemeltetési kézikönyv 1.6 az első AlmaLinux-próbatelepítés tanulságaival: a Podman és a python `podman-compose` nem támogatott (`KeyError: 'mariadb'` a profilhoz kötött `depends_on` miatt), Docker CE telepítése a Docker saját tárolójából; a `docker` csoporttagság új bejelentkezést kér; a Docker-démon proxybeállítása systemd drop-innal, ha a `pull` időtúllépéssel elhal; a `web` újraindulása esetén `tls-check` és `artisan` futó `web` nélkül; a semleges „hibás felhasználónév vagy jelszó” mögötti valódi ok az auditnaplóból, a `hdid:make-admin` újrafuttatása jelszót állít. A környezeti változók táblázata változónként írja le a levelezés, az SMS és az ügyféltörzs-átvétel kulcsait (eddig egy-egy összevont sor volt); az `.env.example` megjegyzései ugyanezt mondják; a tartományi felhasználónév (`DOMAIN\user`) idézőjelezésének szabálya kipróbálva és leírva. Az image nem változott.

### English
Rule changes in one sentence each (with the specification's identifiers):
- ÜZ-06: the "Source code" footer link can be switched off with the `SHOW_SC` deployment key; it defaults to `true`, so existing installations keep showing it.
- BEL-01: showing a sent client login link in the panel is now the new "Magic link preview in panel" setting (off by default) instead of debug mode; the system status page warns while it is on.
- BEL-03: package names are compared in one normalized form: case, accents and extra spaces do not matter, every other character does; the same applies to the lists, the links and the database filters.
- ADM-01: clearing a sponsor's implicit package also asks for confirmation; the dialog names the chosen package, the premium list and the ending links by client name, and appears only when a link really ends.
- ADM-02a: releasing a call is one click; it asks only while a question-and-answer session is in progress. Confirmations appear only for irreversible, outward-facing, costly or someone else's work, naming the consequence; reopening, unmuting the link warning and cancelling a queued message go without a dialog.
- NYT-10: an invalid request-body JSON is refused with line, column and the kind of mistake; a placeholder outside quotes gets its own message.
- NYT-11: the EMD sync runs page shows the EMD API token state next to the run buttons.

Details:
- New deployment key `SHOW_SC` (default `true`): with `false` the portal and panel footers omit the "Source code" link even when `HDID_SOURCE_URL` is set; under AGPL-3.0 section 13 the source must then be offered another way. Installation guide 1.11 and `.env.example` describe it.
- Fix: the `/docs/ivr`, `/docs/mobile` and `/docs/api` API documentation pages rendered blank because the content security policy blocked the renderer's (Stoplight Elements) `unpkg.com` origin; the policy admits it on those pages only, everywhere else it is unchanged. The guide notes that the renderer needs internet access from the browser; on a closed network the JSON can be downloaded.
- Fixes from the trial installation: the production refusal of `APP_DEBUG=true` names the way out (`APP_ENV=staging` for a debugging session, revert afterwards; operations manual chapter 5); the sponsor package-change confirmation names the chosen package, the premium list and the ending links by client name, and a cleared implicit package counts as a change; a placeholder written outside quotes in a request body (`"ids": {{ ids }}`) gets its own message instead of the generic "invalid JSON", other faults show the parser's message; the EMD sync runs page shows the EMD API token state as its subheading next to the run buttons (last successful refresh, presumably valid, or the error).
- Confirmation dialogs reviewed. Fix: the Save button of the client form and of Settings, and releasing a call on the identify page, opened a confirmation dialog even with nothing to confirm (hence the "0 active links end" dialog); Filament opens the modal whenever a custom heading or description is set, regardless of the condition, so the condition now also controls whether the modal opens. One click, no dialog: releasing a call from the dashboard or without a question-and-answer session, reopening (client, staff), unmuting the link warning, cancelling a queued message. The remaining confirmations name the consequence (clearing a PIN, closing a staff account, retrying a message, the live sync, resetting a template, revoking an API key and removing its signature).
- Test-environment sign-in without debug mode: the "Send a login link" notification shows the link itself when the new Settings › Client login switch is on; a new status tile ("Login link preview") stays yellow while it is on. Package names are normalized in one place (`PackageName`): a name spelled with accents or stray spaces in the directory still matches the lists, and the database filters receive the exact spellings found in the table. JSON faults in request bodies are reported with line and column and the kind of mistake (trailing comma, apostrophes, bare key or value, unclosed string, missing comma or colon).
- Installation guide 1.11 and operations manual 1.6 (Hungarian) with the lessons of the first AlmaLinux trial installation: Podman and the python `podman-compose` are not supported (`KeyError: 'mariadb'` on the profile-bound `depends_on`), Docker CE comes from Docker's own repository; `docker` group membership needs a new login; the Docker daemon's proxy is set with a systemd drop-in when `pull` times out; `tls-check` and `artisan` run without a running `web` when it keeps restarting; the real reason behind the neutral "wrong e-mail or password" message comes from the audit log, and re-running `hdid:make-admin` sets a new password. The environment-variable table now describes the mail, SMS and master-data sync keys one by one (previously one collapsed row each); the `.env.example` comments say the same; the quoting rule for domain user names (`DOMAIN\user`) was tested and documented. The image is unchanged.

## [1.3.2] – 2026-10-08

### Magyar
Szabályváltozások egy mondatban: nincs, a működés nem változott.

Részletek:
- A kiadás `env.example` melléklete az `OPENHDID_VERSION` sorban a kiadás verziószámát hordozza (a kiadási folyamat írja be; az 1.2.0 óta `1.2.0` állt benne), így a telepítőnek nem kell kikeresnie az aktuális verziót. Telepítési útmutató 1.10 és a README-k ennek megfelelően; a két dokumentum fejlécének „Kapcsolódó” sora a jelenlegi dokumentumverziókat mondja. Az image nem változott.
- A `compose.yaml` naplózó-meghajtója `json-file` a Docker saját `local` meghajtója helyett: a Podman a `local`-t „invalid log driver” hibával utasította el, a `json-file`-t `k8s-file`-ként kezeli; a forgatás Dockeren változatlan (szolgáltatásonként 10 × 20 MB). Telepítési útmutató 1.10: Podman-megjegyzések (kipróbálva csak Dockerrel; docker-compose szolgáltató a Podman socketjén, rootless `podman unshare chown`, 80-as port); üzemeltetési kézikönyv 1.5 a meghajtó nevével.

### English
Rule changes in one sentence each: none, behaviour is unchanged.

Details:
- The release's `env.example` asset carries the release's version number in `OPENHDID_VERSION` (written by the release process; it had said `1.2.0` since 1.2.0), so installers no longer have to look up the current version. Installation guide 1.10 (Hungarian) and the READMEs follow; the "Related" header row of both documents names the current document versions. The image is unchanged.
- The `compose.yaml` logging driver is `json-file` instead of Docker's own `local`: Podman rejected `local` with "invalid log driver" and treats `json-file` as `k8s-file`; rotation on Docker is unchanged (10 × 20 MB per service). Installation guide 1.10 adds Podman notes (tested only with Docker; docker-compose provider over the Podman socket, rootless `podman unshare chown`, port 80), operations manual 1.5 names the driver.

## [1.3.1] – 2026-10-08

### Magyar
Szabályváltozások egy mondatban: nincs, a működés nem változott.

Részletek:
- Telepítési útmutató 1.9 és a README-k: a `tls` és `ca` mappa létrehozása és a `docker compose pull` az alkalmazáskulcs lépése elé került, mert a kulcsot az image készíti (a `docker compose run … web key` első futása töltötte le az image-et a ghcr.io-ról, és a hiányzó csatolt mappákat a Docker root tulajdonnal hozta létre, amitől a tanúsítvány másolása elbukhatott); image nélküli alternatíva a kulcsra: `echo "base64:$(openssl rand -base64 32)"`. A `compose.yaml` fejléc-megjegyzése és a `.env.example` ugyanezt mondja; az image nem változott.

### English
Rule changes in one sentence each: none, behaviour is unchanged.

Details:
- Installation guide 1.9 (Hungarian) and the READMEs: creating the `tls` and `ca` directories and `docker compose pull` now precede the application-key step, because the key is produced by the image (the first `docker compose run … web key` pulled the image from ghcr.io, and Docker created the missing bind-mount directories as root, so copying the certificate could fail); an image-free alternative for the key: `echo "base64:$(openssl rand -base64 32)"`. The `compose.yaml` header comment and `.env.example` say the same; the image is unchanged.

## [1.3.0] – 2026-10-07

### Magyar
Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- ÜZ-01: egy csomagnév csak egy listán szerepelhet; a két listán közös név mentése hiba.
- ÜZ-01: olyan csomaglista-változás, amely nyitott ügyfelet hozzáférés nélkül hagyna, hiba; egy csomag a normál listán át vezethető ki, vagy a viselők lezárása után.
- ÜZ-01: főügyfelek kiesése a szerepből és szintváltás csak megerősítés után menthető, a számokkal és névmintával; a hatásbecslés a nyitott ügyfeleket nézi, csoportonként legfeljebb tíz nevet mutat, és a mentés pillanatának állapotából készül.
- NYT-06.3: a főügyfélség kizárólag az implicit prémium csomagon áll; elvesztésekor a kapcsolatok azonnal megszűnnek (az óránkénti feladat csak védőháló), a kapcsolt ügyfelek nem zárulnak le.
- NYT-06: a magától megszűnt kapcsolatnál a Kapcsolt ügyfelek fülön személy helyett az ok látszik („megszűnt: a főügyfél már nem implicit prémium”).
- ADM-01: a főügyfél implicit csomagjának nem prémiumra írása az adatlapon megerősítést kér a megszűnő kapcsolatok számával; megerősítés nélkül (Enterrel beküldve) nem menthető.
- NYT-03: az ügyféltörzsben változó e-mail-címet a fiók követi, munkatársnál is; egy e-mail egyszerre egy fiókhoz tartozhat, ütközésnél a régi cím marad és a fiók nem tud belépni, amíg rendezik.
- NYT-04.3: a besorolás (ügyfél–munkatárs) váltása ugyanannyi türelmi futást kap, mint a hiányzás.

Részletek:
- Beállítások › Csomagok: a mentés előre kiszámolja a hatást. Ugyanaz a csomagnév mindkét listán, illetve nyitott ügyfelet hozzáférés nélkül hagyó változás nem menthető (a nevek felsorolásával); a főügyfelek kiesése a szerepből (a megszűnő kapcsolatok és az érintett kapcsolt ügyfelek számával) és a szintváltások megerősítést kérnek; a kapcsolatok a mentéskor azonnal megszűnnek. A főügyfél implicit csomagjának átírása az adatlapon is megerősítést kér.
- Ügyfélkapcsolatok: a főügyfélség kizárólag az implicit prémium csomagon áll. Ha a főügyfél implicit csomagja már nem prémium (átvétel, szerkesztés vagy a csomaglista változása), a kapcsolatai magától megszűnnek „a főügyfél már nem implicit prémium” okkal; a kapcsolt ügyfelek nem zárulnak le, jogosultságuk a saját csomagjuk szerinti; az ügyféltörzsből így visszatérő főügyfél csak miatta lezárt kapcsolt ügyfelei újranyílnak, ha a tételük jelen van. Új óránkénti feladat a csomaglista változására.
- Ügyféltörzs-átvétel: a munkatárs e-mail-változását is követi a fiók; ha az új cím már másik fiókon áll, a régi cím marad, a futás nem bukik és a fiók nem zárul le, az ütközést a futás részletei, az eseménynapló, a napló és a Rendszerállapot jelzi (a belépés az eltérés miatt elutasítva az ütközés rendezéséig). A besorolás (ügyfél–munkatárs) váltása ugyanannyi türelmi futást kap, mint a hiányzás; utána a régi fiók lezárul, visszaváltáskor újranyílik. Új migráció (kapcsolat-megszűnés oka, függőben lévő besorolás).
- A beépített MariaDB fiókkészítő szkriptje a `*_FILE` titkokat is kezeli (`MARIADB_ROOT_PASSWORD_FILE`, `DB_PASSWORD_FILE`, `DB_USERNAME_FILE` a `db.env`-ben; a futási fiók `DB_PASSWORD_FILE` kulcsa a `.env`-ben, amelyet a compose a MariaDB-nek is átad). A titokfájlt a `mariadb` és a `migrate` konténerbe is csatolni kell. Telepítési útmutató 1.7.
- Telepítési útmutató 1.6: a mellékletek letöltési alapcíme a mindig friss `releases/latest/download` (a `releases/download/v<verzió>` előtag önmagában 404-et ad, és két kiadás között elavult); a verziópéldák semlegesek, a kiadások böngészhető oldala és az adott verzió választása le van írva. Az image nem változott.

### English
Rule changes in one sentence each (with the specification's identifiers):
- ÜZ-01: a package name may be on one list only; saving a name shared by both lists is an error.
- ÜZ-01: a package-list change that would leave an open client without access is an error; a package is retired through the standard list, or after its wearers were closed.
- ÜZ-01: sponsors losing their role and tier moves are saved only after confirmation with the counts and name samples; the estimate looks at open clients, shows at most ten names per group and reflects the state at the moment of saving.
- NYT-06.3: sponsorship rests solely on the implicit premium package; losing it ends the links at once (the hourly task is only a safety net) and never closes the linked clients.
- NYT-06: a link that ended on its own shows the reason instead of a person on the Linked clients tab ("ended: the sponsor is no longer implicit premium").
- ADM-01: changing a sponsor's implicit package to a non-premium one on the client form asks for confirmation with the number of ending links; without confirmation (Enter submit) it cannot be saved.
- NYT-03: an e-mail changed in the directory is followed by the account, for staff too; one e-mail belongs to one account at a time, on a conflict the old address stays and the account cannot sign in until it is resolved.
- NYT-04.3: a classification change (client/staff) gets the same grace runs as a missing record.

Details:
- Settings › Packages: saving estimates the impact first. The same package name on both lists, or a change that would leave an open client without access, cannot be saved (the names are listed); sponsors losing their role (with the number of ending links and indirectly affected linked clients) and tier moves require confirmation; links end immediately on save. Changing a sponsor's implicit package on the client form asks for confirmation too.
- Client links: sponsorship rests solely on the implicit premium package. When a sponsor's implicit package stops being premium (sync, edit or a package-list change), its links end automatically with the reason "sponsor no longer implicit premium"; the linked clients are not closed and keep whatever their own packages entitle them to; a sponsor returning from the directory like that reopens the dependents closed only because of it, if their records are present. New hourly task for package-list changes.
- EMD sync: a staff e-mail change now follows the directory too; when the new address already belongs to another account, the old address is kept, the run does not fail and the account is not closed, and the conflict is shown in the run details, the audit log, the log and the system status (login is refused for the mismatch until it is resolved). A classification change (client/staff) gets the same grace runs as a missing record before the old account closes; flipping back reopens it. New migration (link end reason, pending classification).
- The bundled MariaDB account script now resolves `*_FILE` secrets too (`MARIADB_ROOT_PASSWORD_FILE`, `DB_PASSWORD_FILE`, `DB_USERNAME_FILE` in `db.env`; the runtime account's `DB_PASSWORD_FILE` from `.env`, which compose also passes to MariaDB). Mount the secret file into the `mariadb` and `migrate` containers as well. Installation guide 1.7.
- Installation guide 1.6: the asset download base is the always-current `releases/latest/download` (the bare `releases/download/v<version>` prefix returns 404 and went stale between releases); version examples are neutral, and the browsable releases page and picking a specific version are described. The image is unchanged.

## [1.2.1] – 2026-10-06

### Magyar
- A támogatott PHP legalacsonyabb verziója 8.4 (a `composer.json` `^8.4`-et kér); a 8.3 támogatása megszűnt, mert a rögzített függőségek (Symfony 8) PHP 8.4.1-et követelnek. A kép továbbra is PHP 8.5-öt futtat, az image tartalma nem változott.
- OpenHDID telepítési útmutató 1.5, a lépések szó szerinti próbatelepítése után: a telepítési mappa tulajdonosa `"$(id -un)"` (a `$USER` nem minden shellben létezik); a `tls-check` csak `OPENHDID_TLS=on` módban értelmes; a `/health` a gazdagépről csak a Docker-híd átjárócímének engedélyezésével (`OPENHDID_MONITORING_ALLOW`) érhető el; bekötetlen ügyféltörzs-API mellett a `hdid:health` kilépési kódja 2; a demó mód ismert eltérései leírva; a példák az aktuális kiadást nevezik.
- Platform: a MariaDB 12.3.3 és a build Composer 2.10.3 image-digestje a frissen újraépített képekre mutat; az ellenőrző Trivy 0.75.0. Alkalmazáskód nem változott.

### English
- The minimum supported PHP version is 8.4 (`composer.json` requires `^8.4`); PHP 8.3 is no longer supported because the locked dependencies (Symfony 8) require PHP 8.4.1. The image still runs PHP 8.5 and its contents are unchanged.
- Installation guide 1.5 (Hungarian), after running every step literally: the install directory is owned by `"$(id -un)"` (`$USER` is not set in every shell); `tls-check` only applies to `OPENHDID_TLS=on`; `/health` is reachable from the host only after allowing the Docker bridge gateway address in `OPENHDID_MONITORING_ALLOW`; without an EMD API login `hdid:health` exits with code 2; the known differences of demo mode are documented; the examples name the current release.
- Platform: the MariaDB 12.3.3 and build-time Composer 2.10.3 image digests point at the freshly rebuilt images; the Trivy scanner is 0.75.0. No application code changed.

## [1.2.0] – 2026-10-06

### Magyar
- Ügyféltörzs-átvétel, dinamikus ID-lista: az export kéréstörzsének új `{{ ids }}` helyőrzője egy másik ügyféltörzs-lekérdezés (például a szervezetek listája) kijelölt ID-iből áll össze, vesszővel elválasztva, szóköz és ismétlés nélkül, növekvő sorrendben (`3,12,40`). A lekérdezés POST az exporttal azonos tokennel az új `EMD_SYNC_ID_LIST_URL` címre, saját kéréstörzzsel, amelyben a `{{ now }}` is használható.
- A válasz feldolgozása beállítható, nincs a kódba égetve: az ID, a név és a státuszkód helye pontokkal tagolt útvonal. Az alapértékek (`result.data.id`, `result.data.name`, a HTTP-státuszkód) a `{"result":[{"id":31,"data":{"id":31,"name":"…"}}, …]}` alakú válaszhoz illeszkednek. A lista csak 2xx státuszkóddal fogadható el, és ID szerint egyedi.
- A Beállítások › Ügyféltörzs (EMD) fülön a kéréstörzsek mellett jelölőnégyzetes, magyar ábécérendes, névre valós időben szűrhető ID-lista jelenik meg „ID-lista lekérése az API-ból” gombbal. Új ID soha nem kerül automatikusan a kijelöltek közé. A kimaradó ID-hez (levett jelölés vagy a listából kikerült elem) tartozó tételeket az átvétel nem hozza, a kapcsolódó fiókok a beállított számú kihagyott átvétel után lezárulnak. Ha a mentés kijelölt ID-t hagyna ki, megerősítést kér a nevekkel és ezzel a következménnyel.
- A listából kikerült, kijelölt ID megtartja a jelölését. Ha visszatér, újabb döntés nélkül ismét a kérésbe kerül, és a hiány miatt lezárt fiókjai újranyílnak. Ez szándékos (egy átmeneti hiány ne törölje a döntést); a Beállítások oldal és a dokumentumok is kimondják.
- Sikertelen vagy hibás ID-lista-lekérdezésnél (nem 2xx státuszkód, nem JSON válasz, üres lista, szóközt, vesszőt vagy idézőjelet tartalmazó ID), illetve ha egyetlen kijelölt ID sem szerepel a listán, az átvétel a végpont hívása előtt megáll, és fiók nem zárul le. A `{{ ids }}` helyőrző a cím, az ID-lista kéréstörzse és legalább egy kijelölt elem nélkül nem menthető.
- Az ID-lista és az export státuszkódja minden futás részletei között („Ügyféltörzs-kérések”) és a futás auditjában megmarad, sikertelen futásnál is, az elküldött ID-kkel együtt. Az ID-lista elemei nem törlődnek: az új, átnevezett, kikerült és visszatért elem, valamint a kijelölés változása (a módosító munkatárssal) az auditnaplóba kerül, a panelről indított lekérdezés a státuszkódokkal.
- A két kéréstörzset, az ID-lista beállításait és a kijelölést az ügyféltörzs-átvétel kezelésére jogosult munkatárs (`sync.manage`, alapból rendszergazda és főrendszergazda) szerkesztheti. Az export kéréstörzse eddig csak főrendszergazdai volt.
- Biztonság, munkamenetek: a „Kilépés minden eszközről” mostantól az ügyfél minden böngészős munkamenetét megszünteti, a munkamenetek tárolásától függetlenül (eddig az adatbázisos tárolóban az ügyfélmunkamenetek megmaradtak). Abszolút munkamenet-korlát: munkatárs 12, ügyfél 24 óra (beállítható). Az „Emlékezz rám” kapcsolható, alapból ki, bekapcsolva 30 nap; a link és az SMS-kód sosem emlékezik. Az SSO eddig kérés nélkül 400 napos emlékező sütit adott: ezeket a frissítés érvényteleníti (legközelebb újra be kell lépni).
- Biztonság, zárolás és napló: 5 egymást követő hibás jelszó (munkatárs) vagy SMS-kód (ügyfél) után az a belépési út 120 percre zárolódik (beállítható; az SSO és a link közben működik), a munkatárs feloldhatja. Minden sikertelen belépés `login.rejected`, a zárolás `account.locked`, a feloldás `account.unlocked` auditbejegyzés; az elutasított belépés nem kerül a hibanaplóba.
- Belépési hivatkozás: köztes oldal az ügyfél saját szintjének címén (`/login/link`, `/premium/login/link`), a gomb lépteti be, így a levelezőrendszerek linkellenőrzője nem használja el; a token a cím `#` utáni részében utazik. Az érvényesség 3 nap helyett 12 óra (a régi alapértéket és a tárolt levélsablonokat migráció frissíti).
- Mobilos Entra-bejelentkezés: az ID token a kibocsátástól 15 percig és csak egyszer váltható be, semleges hibaüzenettel; a munkatársi mobil tokencsere (`/api/v1/auth/user/entra/token`) megszűnt. Az OIDC-ellenőrzés 60 mp óraeltérést tűr és az `azp`-t is nézi.
- Javítás: a `hdid:make-admin` paranccsal az ügyféltörzs bekötése előtt létrehozott rendszergazdát két átvétel lezárta (a helyi ügyféltörzstételt hiányzónak vette), és újranyitás után sem tudott belépni. A helyi tételt az átvétel mostantól kihagyja, és ha az e-mail-cím az ügyféltörzsben is megjelenik, a fiókot átköti rá (`account.rebound` audit).
- Belépési SMS-kód: egy telefonszámra percenként 1, óránként 3 kód mehet ki (beállítható); a felette érkező kérést a rendszer csendben elutasítja és `message.refused` eseménnyel naplózza. A portál egy újraküldést kínál, utána e-mailes belépési linket (csak ha a tartalék kapcsoló és az e-mail link belépés is be van kapcsolva) vagy az ügyfélszolgálatot. A többi SMS-t a keret nem érinti. Hibás kód csak élő kód mellett számít a zárolásba, a zárolt ügyfél nem kap új kódot, és a válasz ilyenkor is semleges, az ok az auditnaplóban.
- Minden kimenő e-mail és SMS végeredménye auditbejegyzés az ügyfélnél (`message.sent`, `message.failed`).
- A panel belépőjén a zárolt és a kikapcsolt jelszavas belépés is a címenkénti korlátba számít; a lezárt vagy jogosultság nélküli fiók helyes jelszava nem számít hibás jelszónak. Egy őr munkamenetének lejárata nem lépteti ki a másikat (ügyintéző, aki ugyanabban a böngészőben a portált is használja). A belépési link köztes oldala frissítés után is megtalálja a linket.
- Vezérlőpult: a hívás felvétele, átvétele, elengedése és a nem fogadott hívás kezeltnek jelölése az azonosítási jogot is kéri. Kézi azonosítás csomag nélkül maradt ügyfélre nem rögzíthető. Az ügyfél sorának törlése előbb lezárja a fiókot (a főügyfél kapcsolt ügyfeleivel). A panelen átírt telefonszám új számnak számít, a régi megerősítése nem öröklődik. A gépi API elutasított kérései címenként és okonként percenként egy auditsort írnak.
- Ügyféltörzs-átvétel, időkorlát-védelem: a panelről feltöltött CSV/XLSX-et is a háttérfolyamat dolgozza fel (eddig a webkérésben futott, és egy nagy fájlnál a PHP időkorlátja félbevágta, a futás „Futó” maradt, a zár egy óráig blokkolta a következő átvételt). Az átvétel 120 mp alatti `max_execution_time` mellett el sem indul; a PHP által félbeszakított futás „Sikertelen” lesz az okkal, a zár felszabadul. Új „PHP időkorlát” rendszerállapot-kártya; a kép `php.ini`-je 300 mp-et ad (`request_terminate_timeout` 330 s), az indításkori ellenőrzés 120 alatt leáll.
- Ügyféltörzs-átvétel, előrejelzés: látható, mely fiókok zárulnak le a következő átvételnél, ha a tételük továbbra is hiányzik — az Ügyféltörzs (EMD) listán jelvény, szűrő és „A következő átvételnél lezárul” fül, a futás eredményében darabszám és névminta (próbafuttatásnál is), a rendszerállapoton sárga jelzés, az Ügyfelek és Munkatársak listán azonos nevű fül a gyűjtőszámmal.
- Fordítások: a Hívások lista „Összes” füle, a `sorban` átvételi státusz és a „hiányzik az ügyféltörzsből” lezárási ok magyarul. A lábléc-beállítás segédszövege már nem ígér Markdown-értelmezést.
- Függőségek, biztonsági javítások: Filament 5.9.0 (CVE-2026-104181, a többtényezős hitelesítés kezelőműveletei jelszó-újraellenőrzés nélkül; a HDID nem használja az MFA-t, de a javított verzió megy ki), league/commonmark 2.10.3 (GHSA-97jj-33gv-5xf9, GHSA-3q6v-r5mr-hxv8: HTML-szűrő megkerülése és négyzetes idejű feldolgozás a táblázat-bővítményben; a hírek Markdownját ez rendereli), Livewire 4.4.7 és a Symfony 8.1 javítóverziói; a build-függőségekben a source-map-js 1.2.2 (GHSA-68fv-2mgg-jv7q).
- Frissítés: új adatbázis-migrációk (`sync_id_list_items`, a fiókok munkamenet- és zárolási oszlopai, a belépési link érvényessége, a kimenő üzenetek címzett-indexe), amelyeket a `migrate` szolgáltatás magától lefuttat. Az új `EMD_SYNC_ID_LIST_URL` kulcs csak a `{{ ids }}` használatához kell, nélküle minden a korábbiak szerint működik. A `USER_ENTRA_MOBILE_CLIENT_ID` kulcs megszűnt (munkatársi mobil tokencsere nincs).

### English
- EMD sync, dynamic ID list: the export payload's new `{{ ids }}` placeholder is built from the selected IDs of another EMD query (e.g. the list of organizations), comma-separated, without spaces or repeats, in ascending order (`3,12,40`). The query is a POST with the export's token to the new `EMD_SYNC_ID_LIST_URL`, with its own payload that supports `{{ now }}`.
- The response is read through settings, nothing is hard-coded: the ID, name and status code are dotted paths. The defaults (`result.data.id`, `result.data.name`, the HTTP status code) match a `{"result":[{"id":31,"data":{"id":31,"name":"…"}}, …]}` response. The list is accepted only with a 2xx status and is unique by ID.
- Settings › EMD tab: next to the payloads, the ID list as checkboxes in Hungarian alphabetical order with a live name filter and a "Fetch the ID list from the API" button. A new ID is never selected automatically. The records of an ID left out (unticked or gone from the list) stop arriving, and the linked accounts close after the configured number of missed runs. Saving a selection that drops selected IDs asks for confirmation, naming them and this consequence.
- A selected ID that leaves the list keeps its selection. When it returns, it is sent again without a new decision, and its accounts closed as missing reopen. This is intentional (a temporary gap must not erase the decision); the settings page and the documents say so.
- A failed or malformed ID list query (non-2xx status, non-JSON response, empty list, an ID with spaces, commas or quotes), or no selected ID on the list, stops the sync before the endpoint is called, and no account is closed. `{{ ids }}` cannot be saved without the URL, the ID list payload and at least one selected entry.
- The status codes of the ID list query and the export, with the IDs sent, are kept on every run's details ("EMD requests") and in its audit entry, failed runs included. ID list entries are never deleted: additions, renames, removals, returns and selection changes (with the staff member) go to the audit log, panel-started queries with their status codes.
- Both payloads, the ID list settings and the selection are edited by staff with the EMD sync permission (`sync.manage`, by default admins and super-admins). The export payload used to be super-admin only.
- Security, sessions: "sign out everywhere" now ends every browser session of a client whatever the session store (the database store used to keep client sessions). Absolute session lifetime: staff 12, clients 24 hours (configurable). Remember-me can be switched on, off by default, 30 days when on; the e-mail link and the SMS code never remember. Single sign-on used to set a 400-day remember cookie unasked: the upgrade invalidates those (users sign in once more).
- Security, lockout and audit: 5 wrong passwords (staff) or SMS codes (clients) in a row lock that login path for 120 minutes (configurable; SSO and the e-mail link keep working), and staff can unlock it. Every failed login is a `login.rejected`, a lock `account.locked`, an unlock `account.unlocked` audit entry; refused logins no longer go to the error log.
- Login link: a landing page under the client's own entry (`/login/link`, `/premium/login/link`) signs in with a button press, so mail scanners cannot use the link up; the token travels in the URL fragment. Validity is 12 hours instead of 3 days (a migration updates the old default and stored e-mail templates).
- Mobile Entra sign-in: an ID token is accepted once and only within 15 minutes of its issue, with a neutral error; the staff mobile token exchange (`/api/v1/auth/user/entra/token`) is gone. OIDC validation tolerates 60 s of clock skew and checks `azp`.
- Fix: an administrator created with `hdid:make-admin` before the directory was connected was closed by the second sync (its local directory record counted as missing) and could not sign in even after reopening. The sync now skips local records, and when the e-mail appears in the directory it rebinds the account to that row (`account.rebound` audit).
- Login SMS code: one code a minute and three an hour per phone number (configurable); requests beyond that are refused silently and audited as `message.refused`. The login page offers one re-send, then an e-mailed login link (only while both the fallback switch and the e-mail link login are on) or support. Other text messages are not limited. A wrong code counts towards the lockout only while a live code exists, a locked client gets no new code, and the answer stays neutral with the reason in the audit log.
- The outcome of every outgoing e-mail and SMS is an audit entry on the client (`message.sent`, `message.failed`).
- Panel login: the locked and the disabled password branches count towards the per-address limit; a correct password on a closed or permission-less account is not counted as a wrong password. One guard's session expiry no longer signs the other out (staff who also use the portal in the same browser). The login link's landing page survives a reload.
- Dashboard: taking, taking over, releasing and handling calls require the identification permission. Manual identification is refused for a client without an entitled package. Deleting a client row closes the account first (with a sponsor's linked clients). An edited phone number counts as a new number, the old confirmation does not carry over. Rejected machine-API requests write one audit row per address and reason per minute.
- EMD sync, time-limit protection: a CSV/XLSX uploaded on the panel is now processed by the worker too (it used to run inside the web request, where PHP's time limit cut a large file short, the run stayed "running" and the lock blocked the next sync for an hour). A sync refuses to start under a `max_execution_time` below 120 s; a run PHP cuts short anyway is marked failed with the reason and the lock is released. New "PHP time limit" health tile; the image's `php.ini` sets 300 s (`request_terminate_timeout` 330 s) and the start-up check stops below 120.
- EMD sync, forecast: the accounts the next sync closes if their records stay absent are visible — badge, filter and "Closes at the next sync" tab on the EMD records list, count and name sample on every run (trial runs too), a yellow health note, and a tab with the count on the Clients and Staff lists.
- Translations: the "All" tab of the Calls list, the `queued` sync status and the "missing from the directory" close reason in Hungarian. The footer setting no longer promises Markdown.
- Dependencies, security fixes: Filament 5.9.0 (CVE-2026-104181, multi-factor management actions without password re-authentication; HDID does not use MFA, but ships the fixed version), league/commonmark 2.10.3 (GHSA-97jj-33gv-5xf9, GHSA-3q6v-r5mr-hxv8: raw-HTML filter bypass and quadratic-time table extension; it renders the news Markdown), Livewire 4.4.7 and the Symfony 8.1 patch releases; source-map-js 1.2.2 among the build dependencies (GHSA-68fv-2mgg-jv7q).
- Upgrade: new database migrations (`sync_id_list_items`, the session and lockout columns of accounts, the login link validity, the recipient index of outbound messages), run by the `migrate` service. The new `EMD_SYNC_ID_LIST_URL` key is needed only for `{{ ids }}`; without it everything works as before. The `USER_ENTRA_MOBILE_CLIENT_ID` key is gone (there is no staff mobile token exchange).

## [1.1.1] – 2026-09-28

### Magyar
- Ügyféltörzs-átvétel: az export kéréstörzsében egy szövegértékbe írt `{{ now }}` helyőrző helyére a küldés időpontja kerül UTC szerint (`2026-09-28T12:03:15.000Z` alakban); ismeretlen helyőrzőt a mentés elutasít, és az átvétel a végpont hívása előtt megáll.
- Platform: PHP 8.5.11 (korábban 8.5.10), frissített Composer build-image.

### English
- EMD sync: a `{{ now }}` placeholder inside a text value of the export payload is replaced with the time of sending in UTC (as `2026-09-28T12:03:15.000Z`); an unknown placeholder is rejected on save and stops the sync before the endpoint is called.
- Platform: PHP 8.5.11 (previously 8.5.10), refreshed Composer build image.

## [1.1.0] – 2026-09-27

### Magyar
- A kiadási workflow az SBOM-igazolást az elavult actions/attest-sbom helyett az actions/attest lépéssel készíti (sbom-path bemenet, változatlan tartalom).
- Vezérlőpult: az „Azonosítás” / „Átveszem” gomb csak attól a kollégától veszi át a hívást, akire a gomb rajzolásakor szólt; a közben más kézbe került hívásnál figyelmeztetés jön, csendes átvétel nincs.
- Azonosítás oldal: senki által nem tartott, még csengő hívás nem helyezhető át másik ügyfélhez az oldal címéből; előbb fel kell venni a vezérlőpulton.
- Ügyféladatlap: a Telefonszámok fülön a szám felvétele, szerkesztése és törlése az adatlapon is működik (nem csak a szerkesztő oldalon); a Válaszok fülről a választ csak az ügyfelek kezelésére jogosult munkatárs távolíthatja el.
- Ügyféltörzs-átvétel: több tétel azonos e-mail-címmel egy ügyfelet jelent, az első kerül át, a többi kihagyott sor („ismételt e-mail-cím”); a kézzel felvett telefonszám, amelyet az ügyféltörzs is hoz, ügyféltörzs-forrású és megerősített számmá válik.
- Főügyfél újranyitása nem nyitja újra azt a kapcsolt ügyfelet, akit közben az ügyféltörzs-átvétel hiányzónak jelölt; az lezárva marad, amíg a tétel vissza nem tér.
- Portál: az egyszeri azonosító kód oldala nem mutat egy korábbi azonosítást az új, felhasználatlanul lejárt kód sikereként; a túl sok próbálkozás (429) magyarul jelenik meg; már felvett szám ismételt megadásakor „már szerepel a listáján” a visszajelzés; a telefonszámos üzenetek és a demó-hír magázó formában.
- PIN csak rögzített telefonszámmal: a telefonos menü előbb a hívószám alapján ismeri fel a hívót; PIN csak telefonszámmal rendelkező ügyfélnek adható, az utolsó szám törlése a PIN-t is törli, és minden PIN-felület kimondja a feltételt.
- Telefonszám-megerősítés három úton (SMS-kód, ügyfélszolgálati megerősítés, azonosított hívás új kapcsolóval), minden számnál látszik, mi erősítette meg (új migráció).
- Megosztott telefonszámok: figyelmeztetés és tudomásulvétel más ügyfél számának felvételekor, új „Megosztott telefonszámok” oldal és rendszerállapot-kártya, „megosztott” jelölés az adatlapon; a portál csak beállítás mellett jelzi az ügyfélnek.
- A „bediktált kód” új neve „egyszeri azonosító kód”; a mobilalkalmazás IVR-kódja a tárcsázóból küldendő DTMF-kód.

### English
- The release workflow produces the SBOM attestation with actions/attest instead of the deprecated actions/attest-sbom (sbom-path input, same content).
- Dashboard: the "Identify" / "Take over" button only takes the call over from the colleague it was drawn for; a call that changed hands meanwhile yields a warning instead of a silent take-over.
- Identification page: a ringing call nobody holds cannot be moved to another client from the page address; it has to be taken on the dashboard first.
- Client page: adding, editing and deleting phone numbers works on the Phone numbers tab of the client page itself (not only on the edit page); an answer can be removed from the Answers tab only with the client management permission.
- EMD sync: several rows sharing one e-mail address are one client, the first row wins and the repeats become skipped rows ("repeated e-mail address"); a hand-added phone number that the directory also carries becomes a verified directory number.
- Reopening a sponsor no longer reopens a linked client the EMD sync marked missing in the meantime; it stays closed until the record returns.
- Portal: the one-time identification code page no longer shows an earlier acceptance as the success of a new code that expired unused; rate-limit errors (429) are translated; adding a number already on the list says so instead of "added"; phone-number messages and the demo news item use the formal register.
- PIN only with a registered phone number: the phone menu recognises the caller by number first; a PIN can be set only for a client with a number, removing the last number removes the PIN, and every PIN surface says so.
- Phone number verification by three routes (SMS code, helpdesk confirmation, identified call behind a new switch); every number shows what verified it (new migration).
- Shared phone numbers: a warning and acknowledgement when adding a number another client carries, a new "Shared phone numbers" page and status tile, a "shared" marker on the client page; the portal tells the client only behind a setting.
- The "dictated code" is now the "one-time identification code"; the mobile app's IVR code is a DTMF code sent from the dialler.

## [1.0.1] – 2026-09-24

### Magyar
- A főrendszergazda szerepkört csak főrendszergazda adhatja ki, és főrendszergazda fiókját csak főrendszergazda szerkesztheti, zárhatja le vagy törölheti.
- A szerepkör-seeder frissítéskor csak bővít: a Szerepkörök oldalon visszavont vagy hozzáadott jogosultság megmarad.
- Az ügyféltörzs-átvétel az ismétlődő külső azonosítót egyszer számolja (az ismétlés kihagyott sor); a lapozó JSON API ismétlődő lapja hibával, fiókok lezárása nélkül állítja meg a futást.
- A várakozó üzenet visszavonása is törli a titkos törzset; a törölt tartalmú sikertelen üzenet nem küldhető újra.
- A portál kijelentkezése csak akkor mutat kijelentkezett állapotot, ha a kiszolgáló ténylegesen kiléptetett; hiba esetén értesítés és újrapróbálás.
- A kérdés-válasz munkamenet indítása ügyfelenkénti zár alatt fut; a hívás elengedése átvétel után elutasítva; a nem fogadott hívásból indított azonosítás a hívásra kerül; lezárt főügyfélhez nem kapcsolható ügyfél.
- Az ütemezett takarítók ezernél több sort is egy futásban lezárnak; a JWKS-kiesés „szolgáltató nem elérhető” válasz.

### English
- The SuperAdmin role can only be granted by a SuperAdmin, and a SuperAdmin account can only be edited, closed or deleted by a SuperAdmin.
- The role seeder only adds on upgrade: permissions revoked or granted on the Roles page are kept.
- The EMD sync counts a repeated external id once (repeats become skipped rows); a repeated page of the paged JSON API fails the run without closing accounts.
- Cancelling a queued message also wipes its secret body; a failed message whose content was wiped cannot be retried.
- The portal only shows a signed-out state when the server really ended the session; on failure it notifies and lets the client retry.
- Starting a question-and-answer session runs under a per-client lock; releasing a call after a take-over is refused; an identification started from a missed call is attached to that call; no client can be linked to a closed sponsor.
- Scheduled sweepers close more than a thousand rows in one run; a JWKS outage is reported as "provider unavailable".

## [1.0.0] – 2026-09-23

### Magyar
- Első nyilvános kiadás AGPL-3.0-or-later licenccel: munkatársi panel, ügyfélportál, telefonközponti és mobil gépi felület, ügyféltörzs-átvétel, üzenetküldés, egyszeri bejelentkezés, auditnapló, rendszerállapot.
- Konténer-image a ghcr.io/adamfhun/openhdid címen linux/amd64 és linux/arm64 architektúrára: PHP 8.5.10, nginx 1.31.6 mainline headers-more 0.40 modullal, OpenSSL 3.5.8, Alpine 3.24; aláírt image, build provenance és SBOM.
- Docker Compose telepítés: web, worker, sync-worker, scheduler, migrate, választható beépített MariaDB 12.3 LTS és Valkey 9.1.
- HTTPS kizárólag TLS 1.3-mal a saját tanúsítvánnyal, indításkori tanúsítvány-ellenőrzéssel; önaláírt kipróbálási mód; terheléselosztó mögötti mód.
- Nem root felhasználó, csak olvasható gyökér-fájlrendszer, Server és X-Powered-By fejléc nélkül; titkok fájlból (*_FILE).
- A panelről indított ügyféltörzs-átvétel külön soron fut, hogy ne tartsa fel az e-maileket és SMS-eket; a rendszerállapot jelzi, ha nincs rá feldolgozó.
- Semleges alapértelmezett színséma; a levelek és a kimutatások a beállított színeket használják.
- A Rendszerállapot oldal mutatja a futó verziót; a portál és a panel láblécében „Forráskód” hivatkozás.

### English
- First public release under AGPL-3.0-or-later: staff panel, client portal, call-center and mobile machine APIs, Enterprise Master Data sync, messaging, single sign-on, audit log, system status.
- Container image at ghcr.io/adamfhun/openhdid for linux/amd64 and linux/arm64: PHP 8.5.10, nginx 1.31.6 mainline with headers-more 0.40, OpenSSL 3.5.8, Alpine 3.24; signed image with build provenance and SBOM.
- Docker Compose deployment: web, worker, sync-worker, scheduler, migrate, optional bundled MariaDB 12.3 LTS and Valkey 9.1.
- HTTPS with TLS 1.3 only and your own certificate, checked at start; self-signed trial mode; mode for running behind a load balancer.
- Unprivileged user, read-only root file system, no Server or X-Powered-By header; secrets from files (*_FILE).
- The EMD sync started from the panel runs on its own queue so it does not delay e-mail and SMS; the system status warns when no worker serves it.
- Neutral default colour scheme; e-mails and reports use the configured colours.
- The System status page shows the running version; portal and panel link to the source code.
