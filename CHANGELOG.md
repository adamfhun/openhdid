# Changelog / Változásnapló

All notable changes of OpenHDID releases. The format follows [Keep a Changelog](https://keepachangelog.com/), versions follow [Semantic Versioning](https://semver.org). Every entry is given in Hungarian and English.

Az OpenHDID kiadásainak lényeges változásai. Minden bejegyzés magyarul és angolul is szerepel.

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
