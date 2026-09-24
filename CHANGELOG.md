# Changelog / Változásnapló

All notable changes of OpenHDID releases. The format follows [Keep a Changelog](https://keepachangelog.com/), versions follow [Semantic Versioning](https://semver.org). Every entry is given in Hungarian and English.

Az OpenHDID kiadásainak lényeges változásai. Minden bejegyzés magyarul és angolul is szerepel.

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
