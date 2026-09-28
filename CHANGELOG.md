# Changelog / Változásnapló

All notable changes of OpenHDID releases. The format follows [Keep a Changelog](https://keepachangelog.com/), versions follow [Semantic Versioning](https://semver.org). Every entry is given in Hungarian and English.

Az OpenHDID kiadásainak lényeges változásai. Minden bejegyzés magyarul és angolul is szerepel.

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
