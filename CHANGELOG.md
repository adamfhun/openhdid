# Changelog / Változásnapló

All notable changes of OpenHDID releases. The format follows [Keep a Changelog](https://keepachangelog.com/), versions follow [Semantic Versioning](https://semver.org). Every entry is given in Hungarian and English.

Az OpenHDID kiadásainak lényeges változásai. Minden bejegyzés magyarul és angolul is szerepel.

## [1.14.1] – 2026-10-26

### Magyar
- Biztonsági javítóverzió: a PHP munkamenet-feltöltési folyamatjelzője ki van kapcsolva (`session.upload_progress.enabled = 0` az image `php.ini`-jében, a PHP-FPM-poolban rögzítve, az alkalmazás sem kapcsolhatja vissza), a PHP 2026. október 21-i és 23-i biztonsági közleményeinek (CVE-2026-109619, CVE-2026-115560) mitigációs ajánlása szerint. Az image PHP 8.5.12-je már javított; ez kiegészítő védelem, amíg a következő PHP-javítás meg nem jelenik. Az alkalmazás a funkciót nem használja (a munkameneteket a Laravel kezeli), mellékhatása nincs. A webes kérés memóriakorlátja változatlanul 128M, a parancssori folyamatoké 256M (soha nem korlátlan).
- A dokumentumok verziója 1.14.1.

**Frissítés előtt:** migráció nincs, új telepítési kulcs nincs; a frissítés az 1.14.0 `UPGRADE.md` lépései szerint (az 1.13.1-ről érdemes közvetlenül erre a verzióra frissíteni). Hagyományos telepítésen ugyanezt a PHP-FPM `php.ini`-jében és poolfájljában kell beállítani, majd az FPM-et újraindítani (telepítési útmutató 4.3).

### English
- Security patch release: the PHP session upload progress tracking is off (`session.upload_progress.enabled = 0` in the image's `php.ini`, locked in the PHP-FPM pool so the application cannot switch it back on), following the mitigation of the PHP security advisories of 21 and 23 October 2026 (CVE-2026-109619, CVE-2026-115560). The image's PHP 8.5.12 is already fixed; this is an extra safeguard until the next PHP fix release. The application does not use the feature (Laravel keeps its own sessions), so nothing changes in operation. The memory limit of a web request stays 128M, that of the command-line processes 256M (never unlimited).
- The documents carry version 1.14.1.

**Before upgrading:** no migration, no new deployment key; upgrade with the steps of the 1.14.0 `UPGRADE.md` (from 1.13.1, upgrade straight to this version). On a classic installation set the same in the PHP-FPM `php.ini` and pool file and restart PHP-FPM (installation guide 4.3).

## [1.14.0] – 2026-10-26

### Magyar
Biztonsági kiadás: egy tizenhárom szempontú biztonsági vizsgálat megerősített magas és közepes súlyú tételeinek javítása, a megrendelő ehhez kapcsolódó döntéseivel. Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- BEL-05, BEL-05.1 (új), BEL-05.2 (új): a Szerepkörök és jogosultságok oldalhoz eddig nem volt bekötve jogosultsági szabályzat, így bármely munkatárs szerkeszthette a szerepköröket; ez minden korábbi verziót érintett, és javítva van: az oldal csak a szerepkör-kezelési joggal érhető el, szerepkörnek csak a szerkesztő saját jogai adhatók vagy vehetők el, a beépített szerepkörök neve foglalt (a hasonló alakú is), beépített vagy tagokkal bíró szerepkör nem törölhető, a főrendszergazda szerepkört csak főrendszergazda szerkesztheti. Szerepkört csak az oszthat ki vagy vehet el, akinek a szerepkör minden joga megvan, és a munkatársakat kezelő csak olyan fiókot módosíthat (szerkesztés, e-mail, jelszó, második lépés, zárolás, kötés, lezárás, törlés), amelynek minden szerepkörét maga is kioszthatná; a tiltott szerepkör és gomb szürkén, okkal látszik, minden elutasítás és minden kiosztás naplózott (`user.role_escalation_rejected`, `user.manage_rejected`, `user.roles_changed`). A `hdid:make-admin` a főrendszergazda szerepkört is helyreállítja.
- NYT-03, NYT-03.2 (új): a panelen kézzel átírt e-mail-cím minden hozzáférést visszavon; ügyféltörzstételhez kötött vagy belépési kötéssel rendelkező fiók címe nem írható át; ha az új cím egy másik személy ügyféltörzstételéhez köti a fiókot, a Mentés megerősítést kér, és megerősítés után törlődik, amit az előző birtokos beállított (munkatársnál jelszó, második lépés, belépési kötés; ügyfélnél PIN, biztonsági válaszok, kézzel felvett számok); kötés nélküli csere után az első későbbi összekötés ugyanígy más személy átvételének számít. Az átvételen belüli átkötésnél (az ügyfél címe másik tétellel érkezik) a futás végén dől el: ha a régi tétel ugyanabban a futásban még jelen van (két élő tétel, két személy), az ügyfél azonosító adatai törlődnek; ha a régi tétel eltűnt a forrásból, az ugyanannak a személynek az újrakiadása új külső azonosítóval, semmi nem törlődik, csak a belépések szűnnek meg, és a futás részletei külön blokkban ("Ugyanannak a személynek újra kiadott tételek") és számlálóval mutatják. Az átvétel csak pontosan egyező címmel vesz át meglévő fiókot; a csak írásmódban eltérő cím e-mail-ütközés.
- AZO-04.2 (új): ügyfelenként legfeljebb 3 sikertelen kérdés-válasz munkamenet 24 órán belül (beállítható); a megszakított és a lejárt munkamenet is számít, ha volt benne megmutatott, el nem fogadott kérdés; feloldás az ügyfelek kezelési jogával, naplózva.
- KOM-02.3 (új), POR-03: egyetlen „Elfogadott országok (telefonszám)” beállítás (alapból Magyarország) dönti el, melyik ország számát veheti fel az ügyfél a portálon, és melyik ország mobilszámára mehet SMS; vezetékes, emelt díjas, VoIP- és más országbeli számra SMS nem megy (díjcsalás ellen). A portál az elfogadott ország vezetékes számát „SMS-re nem használható” jelzéssel felveszi; az SMS az elsődleges vagy az első SMS-képes számra megy.
- BEL-01.8 (új): rendszerszintű plafon a névtelenül kért belépési üzenetekre: belépési hivatkozás óránként 10 000 (0 = nincs plafon), SMS-belépőkód óránként 60 és naponta 300, számonként naponta 5; betelt plafonnál a portál jelzi, hogy a hivatkozás vagy a kód most nem kérhető.
- BEL-01.2: dokumentált kivétel: az idegen által kódkéréssel és öt hibás tippel kiváltható SMS-zárolást a rendszer nem akadályozza meg (a javítás a semleges válasz és az élő kódhoz kötött zárolás döntéseit nyitná újra); a zárolás csak az SMS-utat érinti, a belépési hivatkozás és a céges bejelentkezés közben működik, a kódkeret korlátozza.
- KOM-02: Exchange-en a levelek mentett másolat nélkül mennek (a titok nem marad a küldő postafiókban); SMTP-n a STARTTLS kötelező (`MAIL_REQUIRE_TLS`); a belépési kódok elsőbbségi soron mennek (`HDID_PRIORITY_QUEUE`), a várakozás közben lejárt vagy felváltott titok nem megy ki; a formázott levélben a beillesztett adat szöveg marad.
- BIZ-00, BIZ-00.1 (új): minden generált cím az `APP_URL`-ből készül, más kiszolgálónévre 400 („Ismeretlen kiszolgálónév”); az nginx kliens-címenként korlátozza a kéréseket és az egyidejű kéréseket (IPv6-on /64, ahogy az alkalmazás saját címenkénti korlátai is), a túlterhelést okot mondó 429-cel vagy 503-mal jelzi; vendégkérés nem nyit munkamenetet; minden bemenetnek hosszkorlátja van; az importálás csak a feltöltött fájlt fogadja el; vállalati hálózat mentesíthető a kliens-cím szerinti korlátok alól (`HDID_RATE_LIMIT_EXEMPT_NETWORKS`).
- BIZ-03 (új): éles környezetben a demóadat megállítja a `migrate`-et, a demófiók nem léphet be, az átvétel nem adja át a demótételt.
- BIZ-04 (új): a mentés visszaállítása után mindenki kilép, a mobilalkalmazásban is; új parancsok: `./backup.sh signout` és az alkalmazás saját `php artisan hdid:sign-out-all` parancsa (megerősítést kér, a végén kiírja, mi történt), amelyet a `backup.sh` is hív, és amely a forrásból, konténer nélkül futó telepítésen a kézi SQL-t váltja.
- NYT-06.1, ÜZ-01: a kapcsolt ügyfél zárva marad, amíg a főügyfele zárva van és a kapcsolat él; a csomaglisták megerősítése a lezárt főügyfeleket és az újranyílókat is megnevezi.
- AZO-02, CC-03, MOB-01: az IVR-kód kiadása pontos címegyezést kíván; az IVR hívásonkénti kerete egy kulcs egy hívására szól, és csak a hitelesített kérés fogyasztja.
- ÜZ-03, ÜZ-03.2: a profilok a normál csúcs kétszeresére méretezettek, minden szolgáltatásnak memóriaplafonnal; a munkamenetek saját Valkey-be kerülnek (`valkey-sessions`, `allkeys-lru`), a fő Valkey memóriakorláttal, kiszorítás nélkül fut (`noeviction`); új Rendszerállapot-kártyák: Valkey / Redis, Munkamenet-tár, Fájljogosultságok, Demóadatok, Korlátok alól mentesített hálózatok; két, közös Valkey-t használó telepítés zárai, ütemező-mutexei és beállítás-gyorsítótára elválik, a közös tárat a kártyák pirosan jelzik.

**Frissítés előtt:**
- **nginx-dolgozók:** az `OPENHDID_NGINX_WORKERS` üresen a profil értékét veszi (profil nélkül 2, 5k és 10k: 4), mert a web memóriaplafonja ezzel számol. Ha a meglévő `.env`-ben `auto` áll, és a gépnek több magja van, induláskor figyelmeztetés jön: írja üresre (vagy emelje az `OPENHDID_WEB_MEM_LIMIT`-et).
- **Az új `compose.yaml` és a profilfájl letöltése kötelező** (UPGRADE.md 3. lépés): a `worker` parancsa `worker` lett (előbb az elsőbbségi sor), új a `valkey-sessions` szolgáltatás és minden szolgáltatás memóriaplafonja. Régi `compose.yaml`-lel és az új `env.example` `HDID_PRIORITY_QUEUE=priority` sorával a belépési kódok nem mennek ki; régi `compose.yaml` mellett a kulcs maradjon üres.
- **Szerepkörök:** nézze át az auditnaplóban a `role.updated` és `role.created` eseményeket és a szerepkörök jogkészletét. Ha a főrendszergazda szerepkört átnevezték, a `hdid:make-admin` helyreállítja; a régi, átnevezett szerepkör egyéni szerepkörként a tagjaival megmarad: ellenőrizze, és ossza újra a tagokat.
- **Valkey:** a beépített fő Valkey `maxmemory 256mb`/`768m` korláttal, `noeviction` politikával indul. Ha most többet használ (`docker compose exec valkey valkey-cli -a "$REDIS_PASSWORD" INFO memory`, `used_memory_human`), előbb emelje az `OPENHDID_VALKEY_MAXMEMORY` és az `OPENHDID_VALKEY_MEM_LIMIT` értékét. Külső vagy menedzselt Valkey-n a `maxmemory-policy noeviction` kötelező, a munkamenetekhez második példány kell `allkeys-lru` politikával (`REDIS_SESSION_*`); addig a Munkamenet-tár kártya sárga. A munkamenetek áthelyezése egyszer mindenkit kiléptet. A `REDIS_PREFIX`/`CACHE_PREFIX` csak üres sor mellett cserélhető.
- **SMTP:** STARTTLS nélküli SMTP-kiszolgálóval a levelek a frissítés után nem mennek ki (`MAIL_REQUIRE_TLS=true` az alapérték): STARTTLS a kiszolgálón, vagy `MAIL_SCHEME=smtps` a 465-ös porttal; a régi működést a `MAIL_REQUIRE_TLS=false` állítja vissza (a Levelezés kártya ekkor sárga).
- **Kéréskorlát:** az nginx kliens-címenként alapból 20 kérés/s-ot, 100-as löketet és 16 egyidejű kérést enged, felette 429 (az alapérték 2026. október 26-án 50/200-ról 20/100-ra csökkent: az 50/s az 5k profil teljes normál csúcsa volt, két cím telíthette a PHP-FPM-et; egy böngészős felhasználónak a 20/s bőven elég). A `HDID_RATE_LIMIT_EXEMPT_NETWORKS` címeire a korlát nem hat. Ha minden kérés ugyanarról a címről érkezik (rootless Docker, terheléselosztó `TRUSTED_PROXIES` nélkül, újratitkosító előtét), előbb a valódi kliens-címet állítsa helyre; vállalati NAT mögött a `HDID_RATE_LIMIT_EXEMPT_NETWORKS`; a korábbi működést az `OPENHDID_NGINX_RATE_LIMIT=0` és az `OPENHDID_NGINX_CONN_LIMIT=0` adja vissza. Érvénytelen vagy túl tág `HDID_RATE_LIMIT_EXEMPT_NETWORKS`-elemmel a konténer nem indul el.
- **Kiszolgálónév:** az alkalmazás csak az `APP_URL` gépnevére, a `localhost` névre és az `OPENHDID_EXTRA_HOSTS` neveire szóló kérést fogadja; az `APP_URL` legyen pontos. A `/health` és az `/up` loopback-engedélye csak a konténeren belüli kapcsolatra érvényes: terheléselosztó mögül a felügyeletet az `OPENHDID_MONITORING_ALLOW`-ban kell felsorolni.
- **Méretezés:** az 5k gép 10 GB memóriát kér (eddig 8 GB); a PHP-FPM 12 folyamat 128M-mel, a MariaDB 1G/40 kapcsolat, `mem_limit` 2g. A `.env`-ben felülírt `OPENHDID_FPM_*`, `OPENHDID_DB_*` és `OPENHDID_VALKEY_*` értékek maradnak: ha az `OPENHDID_FPM_MAX_CHILDREN` 16, az `OPENHDID_WEB_MEM_LIMIT`-et is emelni kell (kb. 3,2 GB). Ha egy panelműveletnél „Allowed memory size” hiba jelenik meg: `OPENHDID_FPM_MEMORY_LIMIT=256M` nagyobb `OPENHDID_WEB_MEM_LIMIT` mellett.
- **Kubernetes:** az új `base/networkpolicy.yaml` a web podok 8080-as portját csak az `ingress-nginx` névtérből engedi; más ingress-vezérlőnél a névtér nevét az alkalmazás előtt írja át, különben a portál elérhetetlen. A podok memóriakérése egyenlő a korlátjukkal (web 2Gi, ütemező 1Gi): a csomópontokat a telepítési útmutató 15. fejezete szerint méretezze. Az ingress címenként korlátoz, az élő-próba csak az nginx-et kérdezi.
- **Demóadat élesben:** ha egy demópéldányt csak az `APP_ENV` átírásával vitt élesbe, a `migrate` a frissítés után megáll (64): üres adatbázissal kell újrakezdeni (`docker compose down -v`, majd `docker compose up -d` és `hdid:make-admin`).
- **Visszaállítás:** a `./backup.sh restore` után mindenkinek újra be kell lépnie (a mobilalkalmazásban is), a Valkey-ben várakozó sorfeladatok elvesznek (a ki nem küldött üzeneteket az ütemező újrasorolja); a mentés óta tett biztonsági lépéseket meg kell ismételni. Külső adatbázis külön visszaállítása után: `./backup.sh signout`.
- **Mobil háttérrendszer:** a 16 384 karakternél hosszabb ID tokenre a tokencsere 422-t ad. A rejtetté vált rekord nyitva hagyott oldala 404-et ad 403 helyett.
- **Forrásból, konténer nélkül futtatott telepítés:** az artisan 78-as kóddal megáll, ha egy `bootstrap/cache/*.php` más, nem root fiók tulajdona, mint a futtatóé, vagy bárki írhatja; az artisan a futtatási fiókkal, 027-es umaskkal fusson. Az új Fájljogosultságok kártya piros, ha a `.env`, a config cache, a napló vagy a munkamenet-fájlok más helyi fiók számára olvashatók: egyszeri jogszűkítés kell. A konténeres telepítésben nincs teendő.
- Két új migráció fut a szokásos módon (a kimenő üzenet törzse nagyobb oszlopba kerül; a kézi e-mail-csere jelölése). Új, választható telepítési kulcsok: `MAIL_REQUIRE_TLS`, `HDID_PRIORITY_QUEUE`, `HDID_RATE_LIMIT_EXEMPT_NETWORKS`, `REDIS_SESSION_HOST`, `_PORT`, `_PASSWORD`, `_USERNAME`, `_DB`, `OPENHDID_NGINX_RATE_LIMIT`, `_RATE_BURST`, `_CONN_LIMIT`, `_CLIENT_TIMEOUT`, `OPENHDID_FPM_MEMORY_LIMIT`, `OPENHDID_FPM_BACKLOG`, `OPENHDID_WEB_MEM_LIMIT`, `OPENHDID_DB_MEM_LIMIT`, `OPENHDID_VALKEY_MAXMEMORY`, `_MEM_LIMIT`, `OPENHDID_VALKEY_SESSIONS_MAXMEMORY`, `_MEM_LIMIT`; dokumentálva: `REDIS_PREFIX`, `CACHE_PREFIX`, `REDIS_DB`, `REDIS_CACHE_DB`. Az `OPENHDID_EXTRA_HOSTS` mostantól az alkalmazásra is hat.

Részletek:
- Biztonság: az ügyféladatlap fülei és a vezérlőpult, illetve az Üzenetsorok oldal widgetjei a lusta első betöltéskor is ellenőrzik a jogosultságot és a „csak prémium” módot. A beállítások képmezőibe nem írható fájlútvonal, a táblázatok lapmérete csak a felkínált értékek egyike. A belépési levél hivatkozása nem a kérés fejléceiből készül; `OPENHDID_TLS=off` mellett az nginx minden továbbító fejlécet a saját értékére cserél. Az e-mail-cím legfeljebb 254 karakter (a @ előtt 64), karakterenként számolva; a telefonszám 32, az SMS-kód 4–10 számjegy; a túl hosszú érték 422-t kap, és nem kerül a naplóba. A munkamenetben tárolt előző cím legfeljebb 2048 bájt. A JWKS-t csak ismeretlen kulcsazonosítóra, legfeljebb percenként kéri újra. A közös Valkey-t használó telepítések zárai és ütemező-mutexei a telepítésazonosítót viselik, így egymást nem akadályozzák.
- nginx: a kéréstörzs legfeljebb 4 MB (a feltöltésé 11 MB), a fejlécre 10 mp, a törzsre és a küldésre 15 mp jut, HTTP/2-n legfeljebb 32 stream; telített PHP-FPM-nél azonnali 503 (`OPENHDID_FPM_BACKLOG`); a megszakított kérés folyamata 125 mp-en belül felszabadul. A konténer induláskor figyelmeztet, ha a PHP-FPM folyamatok × memóriakorlát nem fér a webkonténer plafonjába.
- SMS: a belépőkód és a PIN az elsődleges számra megy, ha SMS-képes, különben az első SMS-képes számra; SMS-képes szám nélkül nincs kód (`login.otp_refused`). A munkatársi SMS-faktor csak elfogadott ország mobilszámára állítható. A sorban álló SMS a küldés előtt is ellenőrződik, a próbaküldés megmondja, ha nem ment ki. A Beállítások oldal név szerint felsorolja azokat a kiválasztott országokat, ahol a számterv nem különbözteti meg a mobilszámot (például Egyesült Államok, Kanada).
- Ügyfélportál, Microsoft-belépés: a Beállítások › Ügyfélportál › Belépési módok blokkban a telepítés Entra-bérlői és elfogadott domainjeik, domainenként színes jelvénnyel aszerint, szerepelnek-e az ügyféltörzs-átvételben; az Egyszeri bejelentkezés kártya sárga, ha egy bérlő domainje munkatársi domain, vagy egyik ügyféltörzs-listán sincs.
- Ügyféltörzs-átvétel: az e-mail-ütközések „Eset” oszlopa (átnevezés, hasonló alakú új tétel, demótétel); új számlálók az érvénytelen e-mail-című, a levágott szövegű és a régi címükkel megtartott tételekre; új blokk a más személyhez került fiókokról és az ugyanannak a személynek újra kiadott tételekről (a `hdid:emd-sync` kimenete is kiírja őket; auditesemény `account.reissued`). Az import csak .csv vagy .xlsx, legfeljebb 10 MB.
- Szóhasználat: a felületen, a dokumentumokban és ebben a jegyzetben egységesen „belépési hivatkozás” és „auditnapló” áll a korábbi vegyes szóhasználat (a „link” szóval, illetve az „esemény” előtaggal írt alakok) helyett; a belépési levél magyar tárgya is „Belépési hivatkozás – …” (a már mentett sablon nem változik). Tartalmi változás nincs.
- Munkamenet-tár tanácsa: a Rendszerállapot és az `.env.example` a második Valkey méretét a használati profil szerint mondja (5k: 384mb, 10k: 576mb, 20k: 1gb).
- Függőségek, biztonsági javítások: laravel/framework 13.38, filament/* 5.10.3, firebase/php-jwt 7.2.1 (forrás: googleapis/php-jwt); a függőségek miatt új a symfony/polyfill-time (MIT). A Composer alsó határai: `laravel/framework ^13.38`, `filament/filament ^5.10.3`, `firebase/php-jwt ^7.2.1`. Jelszó nélküli fióknál a bekapcsolt „Emlékezz rám” működik.
- `backup.sh`: a visszaállítás és a `signout` az alkalmazás `hdid:sign-out-all --force --reason=backup_restore` parancsát hívja (egy helyen él a logika); ez minden fiók munkamenet-korszakát növeli, törli az „emlékezz rám” és a mobil tokeneket, érvényteleníti az élő egyszeri titkokat, és üríti a munkamenet- és gyorsítótárat a Valkey-ben is (`FLUSHDB`, ezért egy Valkey-adatbázis egy telepítésé); a kiléptetés az auditnaplóba kerül (`access.revoked_all`). A füstteszt a két Valkey politikáját és korlátját, az `OPENHDID_TLS=off` mód fejléc- és korlátkezelését és a visszaállítás utáni kiléptetést is ellenőrzi.
- Platform: headers-more nginx-modul 0.41 (az image forrásból épített nginx-ében).
- A dokumentumok verziója 1.14.0.

### English
Security release: fixes for the confirmed high and medium findings of a thirteen-dimension security review, with the owner's decisions around them. Rule changes in one sentence (with the specification identifiers):
- BEL-05, BEL-05.1 (new), BEL-05.2 (new): the Roles and permissions page had no authorization policy bound, so any staff member could edit the roles; this affected every earlier version and is fixed: the page needs the role management permission, a role editor may only grant or revoke permissions they hold themselves, the names of the built-in roles are reserved (look-alikes too), a built-in role or a role with members cannot be deleted, and only a SuperAdmin may edit the SuperAdmin role. A role may only be granted or taken away by someone holding every one of its permissions, and a staff manager may only change an account (edit, e-mail, password, second factor, unlock, binding, close, delete) whose every role they could grant themselves; the refused role or button is greyed out with the reason, every refusal and every grant is logged (`user.role_escalation_rejected`, `user.manage_rejected`, `user.roles_changed`). `hdid:make-admin` restores the SuperAdmin role too.
- NYT-03, NYT-03.2 (new): an e-mail address changed by hand in the panel revokes all access; the address of an account bound to a directory record or a sign-in binding cannot be changed; if the new address binds the account to another person's directory record, Save asks for confirmation, and after it what the previous holder set up is deleted (staff: password, second factor, sign-in binding; clients: PIN, security answers, manually added numbers); after a change without a binding the first later binding counts as a hand-over in the same way. A rebinding within the sync (the client's address arrives with another record) is decided at the end of the run: if the old record is still in the same run (two live records, two people), the client's identification data is deleted; if the old record is gone from the source, the directory reissued the same person's record under a new external id, nothing is deleted, only the sign-ins end, and the run details show it in a block of its own ("Records reissued to the same person") with a counter. The sync takes over an existing account only with an exactly matching address; one that differs only in how it is written is an e-mail conflict.
- AZO-04.2 (new): at most 3 unsuccessful question-and-answer sessions per client within 24 hours (configurable); a cancelled or expired session counts too when it showed a question that was not accepted; unlocking needs the client management permission and is logged.
- KOM-02.3 (new), POR-03: a single "Accepted countries (phone number)" setting (Hungary by default) decides which countries' numbers a client may add on the portal and to which countries' mobile numbers an SMS may go; no SMS goes to landline, premium-rate, VoIP or other countries' numbers (against toll fraud). The portal accepts a landline of an accepted country marked "cannot be used for SMS"; an SMS goes to the primary or the first SMS-capable number.
- BEL-01.8 (new): system-wide caps on anonymously requested sign-in messages: login links 10,000 an hour (0 = no cap), SMS sign-in codes 60 an hour and 300 a day, 5 a day per number; while a cap is full the portal says that the link or the code cannot be requested now.
- BEL-01.2: documented exception: the SMS lockout a stranger can cause with a code request and five wrong guesses is not prevented (the fix would reopen the decisions on the neutral answer and on counting only against a live code); the lockout affects the SMS path only, the login link and company sign-in keep working, and the code budget limits it.
- KOM-02: on Exchange, mails go without a saved copy (no secret stays in the sending mailbox); STARTTLS is mandatory on SMTP (`MAIL_REQUIRE_TLS`); sign-in codes go on a priority queue (`HDID_PRIORITY_QUEUE`), a secret that expired or was superseded while waiting is not sent; data inserted into formatted mails stays text.
- BIZ-00, BIZ-00.1 (new): every generated address comes from `APP_URL`, any other host name gets 400 ("Unknown host name"); nginx limits requests and concurrent requests per client address (the /64 network on IPv6, as do the application's own limits per address) and answers overload with a 429 or 503 that says why; a guest request opens no session; every input has a length limit; the import only accepts the uploaded file; a company network can be exempted from the limits per client address (`HDID_RATE_LIMIT_EXEMPT_NETWORKS`).
- BIZ-03 (new): in production, demo data stops `migrate`, demo accounts cannot sign in, and the sync does not hand a demo record over.
- BIZ-04 (new): after a backup is restored everyone is signed out, in the mobile app too; new commands: `./backup.sh signout` and the application's own `php artisan hdid:sign-out-all` (asks for confirmation, reports what it did), which `backup.sh` calls too and which replaces the manual SQL on installations run from source without containers.
- NYT-06.1, ÜZ-01: a linked client stays closed while its sponsor is closed and the link stands; the package list confirmation also names the closed sponsors and the clients it reopens.
- AZO-02, CC-03, MOB-01: issuing an IVR code needs an exact address match; the IVR per-call budget is per key and call, and only authenticated requests use it.
- ÜZ-03, ÜZ-03.2: the profiles are sized for twice the normal peak, every service with a memory ceiling; sessions move to a Valkey of their own (`valkey-sessions`, `allkeys-lru`), the main Valkey runs with a memory limit and without eviction (`noeviction`); new status tiles: Valkey / Redis, Session store, File permissions, Demo data, Rate limit exemptions; two installations sharing a Valkey keep their locks, scheduler mutexes and settings cache apart, and the tiles show the shared store in red.

**Before upgrading:**
- **nginx workers:** an empty `OPENHDID_NGINX_WORKERS` takes the profile's value (2 without a profile, 4 for 5k and 10k), the number the web memory ceiling counts with. If the existing `.env` says `auto` and the host has more cores, a warning comes at startup: leave it empty (or raise `OPENHDID_WEB_MEM_LIMIT`).
- **Download the new `compose.yaml` and profile file** (UPGRADE.md step 3): the `worker` command is now `worker` (the priority queue first), there is a new `valkey-sessions` service and a memory ceiling on every service. With an old `compose.yaml` and the `HDID_PRIORITY_QUEUE=priority` line of the new `env.example` sign-in codes are not sent; with an old `compose.yaml` keep the key empty.
- **Roles:** review the `role.updated` and `role.created` events in the audit log and the permission sets of the roles. If the SuperAdmin role was renamed, `hdid:make-admin` restores it; the old, renamed role stays as a custom role with its members: check it and reassign them.
- **Valkey:** the bundled main Valkey starts with `maxmemory 256mb`/`768m` and the `noeviction` policy. If it uses more now (`docker compose exec valkey valkey-cli -a "$REDIS_PASSWORD" INFO memory`, `used_memory_human`), raise `OPENHDID_VALKEY_MAXMEMORY` and `OPENHDID_VALKEY_MEM_LIMIT` first. On an external or managed Valkey `maxmemory-policy noeviction` is mandatory, and the sessions need a second instance with `allkeys-lru` (`REDIS_SESSION_*`); until then the Session store tile is yellow. Moving the sessions signs everybody out once. Change `REDIS_PREFIX`/`CACHE_PREFIX` only while the queue is empty.
- **SMTP:** with an SMTP server that offers no STARTTLS, mail stops after the upgrade (`MAIL_REQUIRE_TLS=true` by default): enable STARTTLS on the server, or use `MAIL_SCHEME=smtps` on port 465; `MAIL_REQUIRE_TLS=false` restores the old behaviour (the Mail tile turns yellow).
- **Request limit:** nginx allows 20 requests/s, a burst of 100 and 16 concurrent requests per client address by default, 429 above that (lowered from 50/200 on 2026-10-26: 50/s equalled the whole normal peak of the 5k profile, so two addresses could fill PHP-FPM; 20/s is plenty for one browser user). The limit does not apply to the addresses of `HDID_RATE_LIMIT_EXEMPT_NETWORKS`. If every request comes from the same address (rootless Docker, a load balancer without `TRUSTED_PROXIES`, a re-encrypting front end), restore the real client address first; behind a company NAT use `HDID_RATE_LIMIT_EXEMPT_NETWORKS`; `OPENHDID_NGINX_RATE_LIMIT=0` and `OPENHDID_NGINX_CONN_LIMIT=0` bring back the old behaviour. With an invalid or too wide `HDID_RATE_LIMIT_EXEMPT_NETWORKS` entry the container does not start.
- **Host name:** the application only accepts requests for the host of `APP_URL`, `localhost` and the names in `OPENHDID_EXTRA_HOSTS`; `APP_URL` must be exact. The loopback permission of `/health` and `/up` only applies to connections inside the container: behind a load balancer list the monitoring system in `OPENHDID_MONITORING_ALLOW`.
- **Sizing:** the 5k host needs 10 GB of memory (8 GB before); PHP-FPM runs 12 processes with 128M, MariaDB 1G and 40 connections, `mem_limit` 2g. Values of `OPENHDID_FPM_*`, `OPENHDID_DB_*` and `OPENHDID_VALKEY_*` overridden in `.env` stay as they are: with `OPENHDID_FPM_MAX_CHILDREN` at 16, raise `OPENHDID_WEB_MEM_LIMIT` too (about 3.2 GB). If a panel action fails with "Allowed memory size": `OPENHDID_FPM_MEMORY_LIMIT=256M` with a larger `OPENHDID_WEB_MEM_LIMIT`.
- **Kubernetes:** the new `base/networkpolicy.yaml` lets only the `ingress-nginx` namespace reach port 8080 of the web pods; with another ingress controller change the namespace name before applying, or the portal becomes unreachable. Memory requests now equal the limits (web 2Gi, scheduler 1Gi): size the nodes by chapter 15 of the installation guide. The ingress limits per address, the liveness probe asks nginx only.
- **Demo data in production:** if a demo instance went live by changing `APP_ENV` only, `migrate` stops after the upgrade (64): start over with an empty database (`docker compose down -v`, then `docker compose up -d` and `hdid:make-admin`).
- **Restore:** after `./backup.sh restore` everyone signs in again (in the mobile app too), jobs waiting in Valkey are lost (the scheduler requeues unsent messages); security steps taken since the backup must be repeated. After restoring an external database separately: `./backup.sh signout`.
- **Mobile backend:** an ID token longer than 16,384 characters gets 422 from the token exchange. An open page of a record that became hidden answers 404 instead of 403.
- **Installations run from source without containers:** artisan stops with exit code 78 when a `bootstrap/cache/*.php` belongs to another non-root account than the one running it, or is writable by anyone; run artisan as the runtime account with umask 027. The new File permissions tile is red when `.env`, the config cache, the log or the session files are readable by other local accounts: tighten the permissions once. Container installations need nothing.
- Two new migrations run as usual (the outbound message body moves to a larger column; the mark of a manual e-mail change). New optional deployment keys: `MAIL_REQUIRE_TLS`, `HDID_PRIORITY_QUEUE`, `HDID_RATE_LIMIT_EXEMPT_NETWORKS`, `REDIS_SESSION_HOST`, `_PORT`, `_PASSWORD`, `_USERNAME`, `_DB`, `OPENHDID_NGINX_RATE_LIMIT`, `_RATE_BURST`, `_CONN_LIMIT`, `_CLIENT_TIMEOUT`, `OPENHDID_FPM_MEMORY_LIMIT`, `OPENHDID_FPM_BACKLOG`, `OPENHDID_WEB_MEM_LIMIT`, `OPENHDID_DB_MEM_LIMIT`, `OPENHDID_VALKEY_MAXMEMORY`, `_MEM_LIMIT`, `OPENHDID_VALKEY_SESSIONS_MAXMEMORY`, `_MEM_LIMIT`; now documented: `REDIS_PREFIX`, `CACHE_PREFIX`, `REDIS_DB`, `REDIS_CACHE_DB`. `OPENHDID_EXTRA_HOSTS` now applies to the application too.

Details:
- Security: the tabs of the client page and the widgets of the dashboard and the Queues page check the permission and the premium-only mode on the lazy first load too. Image settings take no file path, table page sizes are limited to the offered values. The link in the sign-in mail is not built from request headers; with `OPENHDID_TLS=off` nginx replaces every forwarding header with its own value. An e-mail address is at most 254 characters (64 before the @), counted per character; a phone number 32, an SMS code 4–10 digits; a value that is too long gets 422 and stays out of the log. The previous URL kept in the session is at most 2,048 bytes. The JWKS is fetched again only for an unknown key id, at most once a minute. The locks and scheduler mutexes of installations sharing a Valkey carry the installation id, so they no longer block each other.
- nginx: request bodies up to 4 MB (uploads 11 MB), 10 s for the headers, 15 s for the body and sending, at most 32 streams on HTTP/2; an immediate 503 when PHP-FPM is saturated (`OPENHDID_FPM_BACKLOG`); the process of an aborted request is freed within 125 s. The container warns at start when PHP-FPM processes × memory limit do not fit the web container's ceiling.
- SMS: the sign-in code and the PIN go to the primary number when it can receive SMS, otherwise to the first SMS-capable number; without one no code is sent (`login.otp_refused`). A staff SMS factor needs a mobile number of an accepted country. A queued SMS is checked again before sending, and a test send says when it was not sent. The Settings page names the selected countries whose numbering plan does not tell mobiles apart (for example the United States, Canada).
- Client portal, Microsoft sign-in: the Settings › Client portal › Sign-in methods block lists the installation's Entra tenants and their accepted domains, each domain with a coloured badge by whether it appears in the master-data sync; the Single sign-on tile turns yellow when a tenant's domain is a staff domain or on neither master-data list.
- Master-data sync: a "Case" column for e-mail conflicts (rename, look-alike new record, demo record); new counters for records with an invalid e-mail, with truncated text and kept on their old address; a new block of accounts that went to another person. Imports take .csv or .xlsx only, up to 10 MB.
- Dependencies, security fixes: laravel/framework 13.38, filament/* 5.10.3, firebase/php-jwt 7.2.1 (source: googleapis/php-jwt); new through the dependencies: symfony/polyfill-time (MIT). Composer lower bounds: `laravel/framework ^13.38`, `filament/filament ^5.10.3`, `firebase/php-jwt ^7.2.1`. "Remember me" works for passwordless accounts.
- EMD sync: a block and a counter for records reissued to the same person (`account.reissued`); `hdid:emd-sync` prints hand-overs and reissues. Wording: the Hungarian interface and documents say "belépési hivatkozás" and "auditnapló" throughout (no change in behaviour). The Session store tile and `.env.example` give the size of the second Valkey by sizing profile (5k: 384mb, 10k: 576mb, 20k: 1gb).
- `backup.sh`: restore and `signout` call the application's `hdid:sign-out-all --force --reason=backup_restore` command (one place for the logic); it moves every account's session epoch on, delete remember-me and mobile tokens, invalidate live one-time secrets and empty the session store and the cache, in Valkey too (`FLUSHDB`, so one Valkey database belongs to one installation); the sign-out goes into the audit log (`access.revoked_all`). The smoke test also checks the policy and limit of both Valkeys, the header and limit handling of `OPENHDID_TLS=off` and the sign-out after a restore.
- Platform: headers-more nginx module 0.41 (in the image's nginx built from source).
- The documents carry version 1.14.0.

## [1.13.1] – 2026-10-24

### Magyar
- Az 1.13.0 kiadás image-e a kiadási workflow-ban nem került ki: a CI sérülékenység-kapuja az alapimage egy aznap közzétett, magas súlyosságú Alpine-hibáján megállt (`brotli-libs` 1.2.0-r1, CVE-2026-111497, javítva 1.2.1-r0-ban), mert a hivatalos PHP-image még a javítás előtti csomaggal állt. Ezért az 1.13.0 a GitHubon kiadásként nem jelent meg (a `v1.13.0` címke létezik, melléklete és image-e nincs). Ez a kiadás az 1.13.0 teljes tartalmát hozza.
- Platform: az image-build a digesttel rögzített PHP-alapimage Alpine-csomagjait a tároló javított változatára frissíti (`apk upgrade`), így egy Alpine-biztonsági javítás nem várja meg a hivatalos image újraépítését; a rögzített verziók változatlanok.
- A dokumentumok verziója 1.13.1.

**Frissítés előtt:** mint az 1.13.0-nál: migráció nincs, új telepítési kulcs nincs; az 1.12.0-ról közvetlenül erre a verzióra kell frissíteni.

### English
- The image of release 1.13.0 was not published by the release workflow: the CI vulnerability gate stopped on a high-severity Alpine flaw in the base image published that day (`brotli-libs` 1.2.0-r1, CVE-2026-111497, fixed in 1.2.1-r0), because the official PHP image still carried the unfixed package. So 1.13.0 never appeared as a GitHub release (the `v1.13.0` tag exists, without assets or image). This release carries the full content of 1.13.0.
- Platform: the image build brings the Alpine packages of the digest-pinned PHP base image up to the repository's fixed versions (`apk upgrade`), so an Alpine security fix does not wait for the official image to be rebuilt; the pinned versions are unchanged.
- The documents carry version 1.13.1.

**Before upgrading:** as for 1.13.0: no migration, no new deployment key; upgrade from 1.12.0 straight to this version.

## [1.13.0] – 2026-10-24

### Magyar
Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- NYT-06, ADM-01.2: főügyfélhez csak az az ügyfél kapcsolható, aki az ügyféltörzs explicit csomagja alapján prémium, saját implicit prémium csomag nélkül (a főügyfél kérésére kapott explicit csomag esete); implicit normál, explicit üres ügyfélnél a kapcsolásnak nincs funkciója, ezért a gomb és a főügyfél fülének keresője sem kínálja, a szolgáltatás elutasítja. A meglévő kapcsolatok nem változnak.
- ADM-01: az ügyféladatlap fejlécében csak az Azonosítás és a Szerkesztés gomb marad; minden más művelet annak az adatnak a sorában áll, amiről szól (PIN beállítása és törlése a PIN mellett, fiók lezárása, újranyitása és a zárolás feloldása az Állapot mellett, főügyfélhez kapcsolás és a figyelmeztetés elrejtése a „Kapcsolva ehhez” mellett, belépési hivatkozás az Utolsó belépés mellett, kötés törlése a Belépési kötés mellett). Az adatok öt blokkban: Ügyfél, Azonosítás, Csomagok és szint, Belépés, Ügyféltörzs (EMD); az ügyfélszolgálati vonal alapból összecsukva.
- ADM-01, BEL-00.2, BEL-04.1: az állapotot elhárító gombok (Zárolás feloldása, Belépési kötés törlése) csak az adott állapotban jelennek meg, az ügyfélnél és a munkatársnál is; a belépés zárolását az Állapot jelvény pirossal mondja, a PIN zárolását a PIN jelvény, az ügyféltörzs-tétel hiányzását vagy a következő átvételnél várható lezárást a Külső azonosító alatti sor (külön „Hiányzik ekkortól” és „PIN zárolva eddig” mező nincs többé).
- ADM-01: a belső megjegyzés kitöltve sárga dobozban áll, és az adatlapon közvetlenül hozzáadható, szerkeszthető; az ügyféltörzs megtekintésére jogosult a Külső azonosító mellől megnyithatja a tételt; az ügyféllista sormenüje két csoportra válik (napi és adminisztratív műveletek).
- ÜZ-01: a Beállítások oldal tizenhárom, a belső kulcsnevek szerinti füle helyett hét fül a rendszergazda kérdései szerint (Ügyfelek és szintek, Azonosítás, Ügyfélportál, Munkatársi belépés, Ügyféltörzs (EMD), Megjelenés, Üzemeltetés és megőrzés), fülönként blokkok „Hol hat” sorral; egyetlen beállítás és tárolt érték sem változott. A csak egy kapcsoló mellett számító beállítások a kapcsoló alatt, csak bekapcsolva látszanak (elrejtve a tárolt érték megmarad); a mértékegység a mezőben, a részletek egy „i” jel mögött, az alapértéktől eltérő érték „alap: …” jelzéssel; a céges belépés és az SMS-átjárót kívánó kapcsolók alatt a telepítési állapot (a céges belépés hiányzó kulcsokkal nem kapcsolható be, a sor megnevezi őket; `SMS_DRIVER=log` mellett sárga figyelmeztetés). Új kereső minden fülön (név, magyarázat, blokk, fül, belső kulcs, korábbi felirat, telepítési kulcs; értékekben nem). A két JSON-kéréstörzs mellé csak megjelenítő „Áttekinthető nézet” került (formázva, színezve, a helyőrzők kiemelve, a mentés ítéletével). A nem mentett módosítás „fül › blokk › beállítás” alakban jelenik meg.

**Frissítés előtt:** migráció nincs; új telepítési kulcs nincs.

Részletek:
- A munkatársi belépés zárolási számai (hibás jelszó vagy kód) nem kerültek a jelszavas belépés kapcsolója alá, mert a kétlépcsős belépés hibás kódjaira is vonatkoznak; a feliratuk ezt ki is mondja.
- Az ügyféladatlap és a Beállítások oldal telefonos szélességen is vízszintes görgetés nélkül használható.
- Függőségek, biztonsági javítás: Livewire 4.4.8 (PKSA-xhv3-nr94-h8db, jogosultság-ellenőrzés megkerülése az `#[Authorize]` attribútumnál; a HDID nem használja, de a javított verzió megy ki).
- A dokumentumok verziója 1.13.0.

### English
Rule changes in one sentence (with the specification identifiers):
- NYT-06, ADM-01.2: only a client who is premium through the directory's explicit package, without an implicit premium package of their own (the explicit package granted at a sponsor's request), can be linked to a sponsor; for a client with a standard implicit package and no explicit package a link has no function, so neither the button nor the sponsor's tab offers it and the service refuses it. Existing links are untouched.
- ADM-01: the client page header keeps Identify and Edit only; every other action stands in the row of the data it acts on (Set and Clear PIN next to the PIN, Close, Reopen and Unlock next to the Status, Link to a sponsor and Mute warning next to "Linked to", Send login link next to Last login, Clear binding next to the Sign-in binding). The data is grouped in five blocks: Client, Identification, Packages and level, Sign-in, EMD data; the support line is collapsed by default.
- ADM-01, BEL-00.2, BEL-04.1: buttons that remedy a state (Unlock login, Clear sign-in binding) appear only while the state holds, for clients and staff alike; the sign-in lock shows in red on the Status badge, the PIN lock on the PIN badge, a missing EMD record or a closure due at the next sync in the line under the External id (the separate "Missing since" and "PIN locked until" entries are gone).
- ADM-01: a filled internal note stands in a yellow box and can be added or edited on the record page directly; whoever may view the directory can open the EMD record from next to the External id; the client list's row menu is split into two groups (everyday and administrative actions).
- ÜZ-01: the Settings page has seven tabs by the administrator's questions instead of thirteen by the internal key names (Clients and levels, Identification, Client portal, Staff sign-in, EMD data, Appearance, Operations and retention), each made of blocks with a "Where it acts" line; no setting and no stored value changed. Settings that only matter under a switch show under it while it is on (hidden, the stored value is kept); units sit in the field, the finer points behind an info icon, a value that differs from the default says so; the company sign-in switches and the switches that need the SMS gateway show the installation status (company sign-in cannot be switched on with keys missing, the line names them; `SMS_DRIVER=log` gives a yellow warning). A new search covers every tab (label, help, block, tab, internal key, earlier label, deployment keys; not the values). The two JSON request bodies get a display-only "Readable view" (formatted, coloured, placeholders marked, with Save's verdict). Unsaved changes are named as "tab › block › setting".

**Before upgrading:** no migration; no new deployment key.

Details:
- The staff sign-in lockout numbers (wrong password or code) did not move under the password sign-in switch, because they also count wrong second-factor codes; their label now says so.
- The client page and the Settings page work at phone width without horizontal scrolling.
- Dependencies, security fix: Livewire 4.4.8 (PKSA-xhv3-nr94-h8db, authorization bypass in the `#[Authorize]` attribute; HDID does not use it, but ships the fixed version).
- The documents carry version 1.13.0.

## [1.12.0] – 2026-10-23

### Magyar
Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- BEL-00.4 (új): az ügyfélportál több Entra-bérlőből fogadhat belépést a `CLIENT_ENTRA_TENANTS` listából (GUID-ok vesszővel, bérlőnként választható `:domain|domain` e-mail-szűrővel; domain nélkül a bérlő megbízható); több bérlőnél a közös `organizations` végpont, a token bérlője a listához, a kibocsátó a token saját bérlőjéhez kötve, a listán kívüli bérlő tokenje érvénytelen; a domain-szűrőn elutasított cím `tenant_domain_not_allowed`; a kötés bérlőnként él (másik engedélyezett bérlőből érkező azonos cím „más címtár-azonosító”, újrakötés csak a listáról lekerült bérlő után); a mobil tokencsere ugyanezt követi; a `CLIENT_ENTRA_TENANT` és a `CLIENT_ENTRA_TENANTS` együttes kitöltését és az egy domain több bérlőnél esetet a rendszer eltűri és a Rendszerállapot sárgán jelzi; a bérlőazonosító csak GUID lehet (elgépelésnél a szolgáltató „nincs beállítva”, a kártya megnevezi); a munkatársi belépés egy bérlős marad.

**Frissítés előtt:** migráció nincs; egy új, választható telepítési kulcs (`CLIENT_ENTRA_TENANTS`); egy bérlővel minden a korábbiak szerint működik.

Részletek:
- A telepítési útmutató lépésenként írja le az Entra regisztrációt egy és több bérlőre (regisztráció típusa, azonosítók, titok, `xms_edov` igény, engedélyek, hozzájárulás a partner oldalán, mobil); a sikeres Entra-belépés auditbejegyzése a `tenant` mezőben mutatja a bérlőt.
- A dokumentumok verziója 1.12.0.

### English
Rule changes in one sentence (with the specification identifiers):
- BEL-00.4 (new): the client portal accepts Entra sign-ins from the tenants listed in `CLIENT_ENTRA_TENANTS` (GUIDs, comma-separated, each with an optional `:domain|domain` e-mail filter; without domains the tenant is trusted); with several tenants the shared `organizations` endpoint is used, the token's tenant is checked against the list and the issuer against the token's own tenant, a token of an unlisted tenant is invalid; an address refused by a tenant's domain filter is `tenant_domain_not_allowed`; bindings are per tenant (the same address from another allowed tenant is a "different directory identity", renewal only after the bound tenant left the list); the mobile token exchange follows the same rules; `CLIENT_ENTRA_TENANT` and `CLIENT_ENTRA_TENANTS` both set, or one domain under several tenants, is tolerated and shown yellow on the status page; a tenant id must be a GUID (a typo leaves the provider "not configured", named on the tile); staff sign-in stays single-tenant.

**Before upgrading:** no migration; one new optional deployment key (`CLIENT_ENTRA_TENANTS`); with one tenant everything works as before.

Details:
- The install guide describes the Entra registration step by step for one and for several tenants (registration type, ids, secret, `xms_edov` claim, permissions, consent on the partner's side, mobile); the audit entry of a successful Entra sign-in carries the tenant in its `tenant` field.
- The documents carry version 1.12.0.

## [1.11.1] – 2026-10-23

### Magyar
- Az 1.11.0 kiadás image-e a kiadási workflow-ban nem készült el: a libcurl forrásának kibontása (`tar -xJf`) a GitHub-futókon kétszer egymás után üzenet nélkül, 1-es kóddal megállt, ezért az 1.11.0 a GitHubon kiadásként nem jelent meg (a `v1.11.0` címke létezik, melléklete és image-e nincs). Ez a kiadás az 1.11.0 teljes tartalmát hozza, és az image-build a tömörített forrást két lépésben bontja ki (`xz -d`, majd `tar -xf`, csővezeték nélkül).
- Platform: csak az `xz` csomag került a libcurl-fordító lépésbe; a rögzített verziók változatlanok.

**Frissítés előtt:** mint az 1.11.0-nál: egy migráció (az `outbound_messages` kézbesítési oszlopai), négy új, választható telepítési kulcs alapértékkel; az 1.10.0-ról közvetlenül erre a verzióra kell frissíteni.

### English
- The image of release 1.11.0 was not built by the release workflow: extracting the libcurl source (`tar -xJf`) stopped twice in a row on the GitHub runners with exit code 1 and no message, so 1.11.0 never appeared as a GitHub release (the `v1.11.0` tag exists, without assets or image). This release carries the full content of 1.11.0, and the image build now extracts the compressed source in two steps (`xz -d`, then `tar -xf`, without a pipe).
- Platform: only the `xz` package was added to the libcurl build step; the pinned versions are unchanged.

**Before upgrading:** as for 1.11.0: one migration (delivery columns of `outbound_messages`), four new optional deployment keys with defaults; upgrade from 1.10.0 straight to this version.

## [1.11.0] – 2026-10-23

### Magyar
Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- KOM-02.2 (új): az SMS kézbesítési jelentését a rendszer az Ozeki átjárótól kérdezi le az átjáró üzenetazonosítójával, a küldés után beállítható időpontokban (`SMS_DELIVERY_CHECKS`, alapból 30 mp, 2, 5, 30 és 120 perc, legfeljebb öt lekérdezés); az üzenet „kézbesítve” vagy „nem kézbesíthető” lesz (mindkettő auditált: `message.delivered`, `message.undeliverable`), jelentés nélkül „elküldve · nincs jelentés”; a nem kézbesíthető SMS-t a rendszer nem küldi újra magától, a munkatárs kézzel küldheti újra; a lekérdező művelet és az állapotszavak az átjáróhoz igazíthatók (`OZEKI_STATUS_ACTION`, `OZEKI_DELIVERED_STATUSES`, `OZEKI_UNDELIVERED_STATUSES`); az üzenet adatlapja mutatja a jelentést, a Rendszerállapot kimenő üzenetek kártyája sárga nem kézbesíthető SMS vagy nem futott lekérdezés mellett; új percenkénti ütemezett feladat (`hdid:sms-delivery-checks`).
- ÜZ-08: a Kimenő üzenetek lista (a Tartalom alatt és az Üzenetsorok oldalon) keresője a sorba állítás és a küldés időbélyegének részletére is keres, a megjelenített magyar alakban (például „10. 23.”, „23. 09:5”, „09:54”); eddig csak a címzettre keresett.

**Frissítés előtt:** egy migráció (az `outbound_messages` kézbesítési oszlopai; a `migrate` szolgáltatás lefuttatja). Négy új, választható telepítési kulcs alapértékkel (`SMS_DELIVERY_CHECKS`, `OZEKI_STATUS_ACTION`, `OZEKI_DELIVERED_STATUSES`, `OZEKI_UNDELIVERED_STATUSES`); a lekérdező műveletet és az állapotszavakat a saját Ozeki-átjárón érdemes ellenőrizni (telepítési útmutató, 11. fejezet).

Részletek:
- A dokumentumok verziója 1.11.0.

### English
Rule changes in one sentence (with the specification identifiers):
- KOM-02.2 (new): the delivery report of an SMS is queried from the Ozeki gateway with the gateway's message id at configurable offsets after sending (`SMS_DELIVERY_CHECKS`, by default 30 s, 2, 5, 30 and 120 minutes, at most five queries); the message becomes "delivered" or "undeliverable" (both audited: `message.delivered`, `message.undeliverable`), or stays "sent · no report"; an undeliverable SMS is never resent on its own, an operator may resend it by hand; the query action and the status words can be adapted to the gateway (`OZEKI_STATUS_ACTION`, `OZEKI_DELIVERED_STATUSES`, `OZEKI_UNDELIVERED_STATUSES`); the message's details show the report, the Outbound messages status tile turns yellow on an undeliverable SMS or a query that did not run; new minutely scheduled task (`hdid:sms-delivery-checks`).
- ÜZ-08: the search box of the Outbound messages list (under Content and on the Queues page) also matches a fragment of the queued and sent timestamps as they are shown (for example "10. 23.", "23. 09:5", "09:54"); until now it searched the recipient only.

**Before upgrading:** one migration (delivery columns of `outbound_messages`; the `migrate` service runs it). Four new optional deployment keys with defaults (`SMS_DELIVERY_CHECKS`, `OZEKI_STATUS_ACTION`, `OZEKI_DELIVERED_STATUSES`, `OZEKI_UNDELIVERED_STATUSES`); verify the query action and the status words on your own Ozeki gateway (install guide, chapter 11).

Details:
- The documents carry version 1.11.0.

## [1.10.0] – 2026-10-23

### Magyar
Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- BEL-00.2 (új): a céges bejelentkezés (Microsoft Entra ID, ADFS) az első belépéstől a szolgáltató állandó azonosítójához köti a fiókot (Entra objektumazonosító a bérlővel, ADFS alany a kliensregisztrációval); az e-mail-cím csak megtalálja a fiókot, az ügyféltörzs szabálya változatlanul fut; a fiók címére más azonosítóval érkező belépés „más címtár-azonosító”, a már másik nyitott fiókhoz kötött azonosító „már kötött” okkal elutasul, a fiók nem zárolódik (lezárt vagy törölt fiók kötése magától átadódik, auditálva); a kötést a rendszergazda törli a munkatárs szerkesztő oldalán vagy az ügyfél adatlapján, a listák sormenüjében (<kbd>Belépési kötés törlése</kbd>) vagy a `hdid:sso-unbind` paranccsal; az ADFS lecserélt regisztrációját a következő belépés magától megújítja; a mobil tokencsere ugyanazt a kötést használja; új „Belépési kötések” kártya a Rendszerállapoton (sárga, ha 7 napon belül más azonosító miatt elutasítás volt); új auditesemények `sso.subject_bound`, `sso.subject_unbound`.
- BEL-00.3 (új): a fiókot megtaláló igény fióktípusonként választható: e-mail-cím (alapérték) vagy dolgozói azonosító, az ügyféltörzstétel külső azonosítójával összevetve (`USER_/CLIENT_SSO_MATCH`, `USER_/CLIENT_SSO_EMPLOYEE_ID_CLAIM`); ismeretlen érték mellett a szolgáltató „nincs beállítva”.
- BEL-00: az állandó azonosító nélkül érkező token elutasul (`HDID_SSO_REQUIRE_SUBJECT=true`); `false` kötés nélküli belépést enged, az auditnapló jelzi.
- Kilépés: az alkalmazás csak a saját munkamenetét, sütijét és tokenjét szünteti meg, a céges szolgáltató munkamenetét nem (megrendelői döntés); a dokumentumok kimondják a következményt.
- BIZ-00: a kimeneti escape két rétegben tesztelve (a nyers HTML-t kiadó helyek ellenőrzött listája és egy szkript-próbaszöveg minden szabad szöveges mezőben a panel minden oldalán és a portálon).

**Frissítés előtt:** egy migráció (`sso_identities` tábla; a `migrate` szolgáltatás lefuttatja). Három új telepítési kulcs alapértékkel (`USER_/CLIENT_SSO_MATCH`, `USER_/CLIENT_SSO_EMPLOYEE_ID_CLAIM`, `HDID_SSO_REQUIRE_SUBJECT`); felvételük nem kötelező, a korábbi működést adják. Frissítés után minden fiók az első céges belépésekor kötődik az azonosítójához.

Részletek:
- Új `hdid:sso-unbind` parancs; a `login.succeeded` bejegyzés `sso_subject` mezője (`bound`, `matched`, `missing`); a Rendszerállapot „Belépési szolgáltatók” tanácsa az ismeretlen `SSO_MATCH` értéket is megnevezi.
- A dokumentumok verziója 1.10.0.

### English
Rule changes in one sentence (with the specification identifiers):
- BEL-00.2 (new): company sign-on (Microsoft Entra ID, ADFS) binds the account to the provider's stable identity at the first sign-in (Entra object id with the tenant, ADFS subject with the client registration); the e-mail address only finds the account and the EMD rule runs unchanged; a sign-in under the account's address from another identity is refused as "different directory identity", an identity already bound to another open account as "already bound", without locking the account (a binding held by a closed or deleted account yields automatically, audited); an administrator clears the binding on the staff edit page or the client record, in the row menu of the lists ("Clear sign-in binding") or with `hdid:sso-unbind`; a replaced ADFS registration is renewed by the next sign-in; the mobile token exchange uses the same binding; new "Sign-in bindings" tile on the status page (yellow when a refusal from another identity happened within 7 days); new audit events `sso.subject_bound`, `sso.subject_unbound`.
- BEL-00.3 (new): the claim that finds the account is chosen per account kind: the e-mail address (default) or the employee id compared with the EMD record's external id (`USER_/CLIENT_SSO_MATCH`, `USER_/CLIENT_SSO_EMPLOYEE_ID_CLAIM`); an unknown value leaves the provider "not configured".
- BEL-00: a token without the stable identity is refused (`HDID_SSO_REQUIRE_SUBJECT=true`); `false` allows an unbound sign-in, which the audit log marks.
- Sign-out: the application ends only its own session, cookie and token, never the company provider's session (owner decision); the documents state the consequence.
- BIZ-00: output escaping is tested in two layers (a reviewed allowlist of every raw-HTML output site, and a script canary in every free-text field on every panel page and on the portal).

**Before upgrading:** one migration (`sso_identities` table; the `migrate` service runs it). Three new deployment keys with defaults (`USER_/CLIENT_SSO_MATCH`, `USER_/CLIENT_SSO_EMPLOYEE_ID_CLAIM`, `HDID_SSO_REQUIRE_SUBJECT`); adding them is optional, the defaults keep the previous behaviour. After the upgrade every account is bound to its identity at its first company sign-in.

Details:
- New `hdid:sso-unbind` command; the `sso_subject` field of the `login.succeeded` entry (`bound`, `matched`, `missing`); the advice of the "Login providers" status tile also names an unknown `SSO_MATCH` value.
- The documents carry version 1.10.0.

## [1.9.0] – 2026-10-22

### Magyar
Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- ÜZ-08 (új): a sor és a kimenő üzenetek a felületen kezelhetők: az *Adminisztráció › Rendszer › Üzenetsorok* oldal (a beállítások kezelése joggal) soronként mutatja a feldolgozó életjelét és a feladatok számát, a sikertelen feladatokat a hibával (újrafuttatás, törlés egyesével, kijelölve, mind, régebbiek), a várakozó feladatokat (törlés, a folyamatban lévő védett), a sor ürítését megerősítéssel, és a kimenő üzenetek listáját tömeges újraküldéssel, visszavonással, az összes sikertelen újraküldésével és a várakozók azonnali, feldolgozó nélküli küldésével (legfeljebb 25 kattintásonként); ugyanezek a tömeges műveletek a Tartalom › Kimenő üzenetek listán is; minden művelet auditált.
- ÜZ-03: a Rendszerállapot két blokkra oszlik (fent a rendszer működése, lent az üzemmódok és beállítások); új „Tájékoztatás” (kék) státusz: az élő csomag-felülírás, a megosztott telefonszám és a bekapcsolt csak prémium mód nem figyelmeztetés többé; az ADFS-hibakeresés külön csempét kapott; a nem zöld csempéről hivatkozás vezet a kezelő oldalra.
- ÜZ-03.1: a `/health` válasz `info` státuszt is adhat (nem rontja a választ), a `hdid:health --strict` a tájékoztatáson nem bukik.
- ÜZ-04: az ütemező minden beállított sorra (`default` és az `EMD_SYNC_QUEUE`) külön életjelet küld, a feldolgozó nélküli sorban az életjel nem halmozódik; a „Feladatsor” csempe sárga, ha a `sync-worker` nem ad életjelet.
- ÜZ-05: a 30 napnál régebbi sikertelen feladatokat a napi tisztítás törli.

**Frissítés előtt:** migráció és új telepítési kulcs nincs.

Részletek:
- Az üzemeltetési kézikönyv napi ellenőrzése, az üzenet- és életjel-helyzetek és a parancsreferencia az új oldalra mutatnak; a felhasználói kézikönyv új „Üzenetsorok” szakaszt és képet kap.
- Platform: Node 24.22.1 (csak a frontend buildje; korábban 24.22.0); a PHP 8.5.12, a Composer 2.10.3 és az Alpine 3.24 image frissített digestje.
- Az image frontend-buildje lassú hálózaton is lefut: a betűtípus letöltésénél a Node címválasztási időkorlátja 10 s (az alapértelmezett 250 ms a kiadás napján `ETIMEDOUT` hibával állította meg a buildet); a kiadási workflow ugyanezt az értéket használja.
- A dokumentumok verziója 1.9.0.

### English
Rule changes in one sentence (with the specification identifiers):
- ÜZ-08 (new): the queue and the outbound messages are managed from the panel: the *Administration › System › Queues* page (settings permission) shows every worker queue with its heartbeat and job counts, the failed jobs with their error (run again or delete one, the selected ones, all, or the old ones), the waiting jobs (delete; a job in progress is protected), clears a queue with confirmation, and lists the outbound messages with bulk retry and cancel, "retry all failed" and "send queued now" without a worker (25 per click); the same bulk actions are on the Content › Outbound messages list; every action is audited.
- ÜZ-03: the System status page is split into two blocks (system operation on top, modes and settings below); new "Information" (blue) status: a live package override, a shared phone number and the premium-only mode are no longer warnings; ADFS debugging got a tile of its own; a tile that is not green links to the page that handles it.
- ÜZ-03.1: `/health` may report an `info` status (it does not degrade the response); `hdid:health --strict` does not fail on information.
- ÜZ-04: the scheduler sends a separate heartbeat to every configured queue (`default` and `EMD_SYNC_QUEUE`), and heartbeats do not pile up on a queue without a worker; the "Queue worker" tile turns yellow when the `sync-worker` gives no heartbeat.
- ÜZ-05: failed jobs older than 30 days are deleted by the daily prune task.

**Before upgrading:** no migration, no new deployment key.

Details:
- The daily check, the message and heartbeat situations and the command reference of the operations manual point to the new page; the user manual gets a "Queues" section and screenshot.
- Platform: Node 24.22.1 (frontend build only; previously 24.22.0); refreshed digests of the PHP 8.5.12, Composer 2.10.3 and Alpine 3.24 images.
- The frontend build of the image also succeeds on a slow link: Node's address-selection attempt timeout is 10 s while the web font is downloaded (the default 250 ms stopped the build with `ETIMEDOUT` on release day); the release workflow uses the same value.
- The documents carry version 1.9.0.

## [1.8.1] – 2026-10-22

### Magyar
Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- KOM-02: az `EWS_AUTH=ntlm` most valóban NTLM-mel hitelesít: a Guzzle 8 az `auth` opció `ntlm` típusát csendben figyelmen kívül hagyja, így a kérés hitelesítés nélkül ment ki, és az Exchange minden küldésre „HTTP 401 · the server offers: NTLM · empty response body” választ adott az NTLM-képes libcurl mellett is; az NTLM-et mostantól a libcurl saját beállításai (`CURLOPT_HTTPAUTH`, `CURLOPT_USERPWD`) kapcsolják be, és egy teszt helyi Exchange-utánzattal bizonyítja a háromlépéses kézfogást (1. típusú üzenet törzs nélkül, 2. típusú kihívás, 3. típusú válasz a SOAP-törzzsel).
- ÜZ-03.2: a mentés (`backup.sh`) a használati profil fájlját (`profiles/*/compose.yaml`) is menti és visszaállítja; eddig a `COMPOSE_FILE`-os telepítés visszaállítása a „mentett beállításokból a compose nem olvasható” hibával állt meg, mert a visszaállított `.env` hiányzó fájlra mutatott (a füstteszt mostantól ezt az utat járja).
- ÜZ-03.2: a konténer elindul a `.env` `COMPOSE_FILE` sorával: a `.env` a konténerek `env_file`-ja is, az indítószkript pedig minden `NÉV_FILE` változót titokfájl-mutatónak vett, így a használati profilt választó telepítésen a `migrate` „`COMPOSE_FILE points to a file that cannot be read`” hibával, 64-es kóddal lépett ki, és a többi szolgáltatás nem indult el (1.7.0-tól minden profilos telepítést érintett; a füstteszt eddig profil nélkül futott, mostantól az 5k profillal, `COMPOSE_FILE`-lal indítja a vermet, és két feldolgozót vár).
- ÜZ-03.2: az `UPGRADE.md` 3. lépése a használati profil fájlját (`profile-5k.yaml` → `profiles/5k/compose.yaml`) is letölti és ellenőrzi a `compose.yaml`-lal együtt, a 4. lépés kimondja, hogy az 1.7.0 előtti telepítésen újként megjelenő `COMPOSE_FILE` kulcs felvételéhez a profilfájl kell; az 1.7.1 „a meglévő telepítéseket nem érintette” állítása csak addig igaz, amíg a kulcs nincs felvéve: a frissítési útmutató szerint felvett kulcs a profilfájl nélkül minden `docker compose` parancsot a `compose file "profiles/5k/compose.yaml" set by COMPOSE_FILE environment variable is invalid` hibával állít meg.

**Frissítés előtt:** migráció és új telepítési kulcs nincs. Akinek a `.env`-jében `COMPOSE_FILE` áll, és a profilfájl hiányzik, vagy a konténer a `COMPOSE_FILE points to a file that cannot be read` hibával áll: `TROUBLESHOOTING.md` 15. fejezet (a profilfájl letöltése ellenőrzőösszeggel; régebbi image-dzsel a profil `compose.override.yaml`-ként).

Részletek:
- `TROUBLESHOOTING.md` új 15. fejezete a hiányzó profilfájl hibájára; az `UPGRADE.md` „Ha valami elakad” listája is megnevezi.
- A telepítési útmutató 14. fejezete (frissítés) a kiadás fájljainak cseréjét külön lépésként mondja, a profilfájllal és az új kulcsok átnézésével; a 3. fejezet a hiba pontos szövegét idézi.
- A dokumentumok verziója 1.8.1.

### English
Rule changes in one sentence (with the specification identifiers):
- KOM-02: `EWS_AUTH=ntlm` now really authenticates with NTLM: Guzzle 8 silently ignores the `ntlm` type of the `auth` option, so the request went out without credentials and Exchange answered every send with "HTTP 401 · the server offers: NTLM · empty response body" even with the NTLM-capable libcurl; NTLM is now switched on through libcurl's own options (`CURLOPT_HTTPAUTH`, `CURLOPT_USERPWD`), and a test proves the three-step handshake against a local stand-in Exchange (type-1 message without a body, type-2 challenge, type-3 answer with the SOAP body).
- ÜZ-03.2: the backup (`backup.sh`) saves and restores the usage profile file (`profiles/*/compose.yaml`) too; until now the restore of a `COMPOSE_FILE` installation stopped with "the compose of the saved settings cannot be read", because the restored `.env` pointed to a missing file (the smoke test now walks this path).
- ÜZ-03.2: the container starts with the `COMPOSE_FILE` line of `.env`: `.env` is the containers' `env_file` too, and the start script took every `NAME_FILE` variable for a secret-file pointer, so on an installation that selects a usage profile `migrate` exited with code 64 and "`COMPOSE_FILE points to a file that cannot be read`", and the other services did not start (every profiled installation since 1.7.0 was affected; the smoke test used to run without a profile, from now on it starts the stack with the 5k profile and `COMPOSE_FILE`, and expects two workers).
- ÜZ-03.2: step 3 of `UPGRADE.md` downloads and verifies the usage profile file (`profile-5k.yaml` → `profiles/5k/compose.yaml`) together with `compose.yaml`, and step 4 says that adding the `COMPOSE_FILE` key (new on installations older than 1.7.0) needs the profile file; the 1.7.1 statement "existing installations were not affected" only holds until the key is added: a key added by the upgrade guide without the profile file stops every `docker compose` command with `compose file "profiles/5k/compose.yaml" set by COMPOSE_FILE environment variable is invalid`.

**Before upgrading:** no migration, no new deployment key. If `.env` has `COMPOSE_FILE` and the profile file is missing, or the container stops with `COMPOSE_FILE points to a file that cannot be read`: `TROUBLESHOOTING.md`, chapter 15 (download of the profile file with checksum; with an older image the profile as `compose.override.yaml`).

Details:
- New chapter 15 of `TROUBLESHOOTING.md` for the missing profile file; the "If something gets stuck" list of `UPGRADE.md` names it too.
- Chapter 14 (upgrade) of the installation guide states the replacement of the release files as its own step, with the profile file and the review of new keys; chapter 3 quotes the exact error text.
- The documents carry version 1.8.1.

## [1.8.0] – 2026-10-21

### Magyar
Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- KOM-02: az Exchange (EWS) elutasított kérésének hibaszövege a HTTP-státusszal kezdődik, 401-nél a kiszolgáló felkínált hitelesítési módjaival, az Exchange saját válaszkódjával és szövegével (`EWS Error…`), bejelentkező oldal vagy proxy válaszánál ennek jelzésével, üres törzsnél ennek kimondásával, elérhetetlen kiszolgálónál a hálózati hibával; eddig egy üres válasz „EWS CreateItem failed:” után semmit nem mondott.
- AZO-07 (új): bármelyik módszer sikeres azonosítása után az ügyintéző az ügyfél adatlapjára kerül, amelynek tetején „Azonosítva” sáv mondja a módszert, az időt és a hívást (hívásmegjegyzés és „Vissza a hívósorhoz” gombbal), így az azonosított ügyfél minden adata egy helyen van; amíg a hívás tart (hívás nélkül 30 percig) a fejléc zöld „Azonosított ügyfél” gombja minden oldalról visszavezet az adatlapra; amíg a hívás tart, az adatlap és az azonosítás oldal elhagyása előtt a rendszer rákérdez (nyitott kérdés-válasz menet alatt is); a Kód ellenőrzése oldal menüből megnyitva is a saját, folyamatban lévő hívásra köti az ellenőrzést, és siker után az adatlapra visz (eddig a találat csak az oldalon élt, egy félrekattintás elveszítette); a vezérlőpulton a saját, már azonosított hívás gombja „Adatlap”, a kolléga által azonosított hívás nem vehető át (a gomb letiltva mondja az okot, a kiszolgáló is elutasítja).

**Frissítés előtt:** migráció és új telepítési kulcs nincs.

Részletek:
- A `TROUBLESHOOTING.md` és az üzemeltetési kézikönyv felsorolja, melyik státusz mit jelent (`EWS_AUTH`, `EWS_URL`, postaláda, proxy, CA).
- Rootless Docker a dokumentumokban: a telepítési útmutató 3. fejezetének új „Rootless Docker” része (telepítés és indítás, portok, a subuid/subgid-számítás – a konténer 82-es felhasználója a gazdagépen `kezdő + 81`, például `165617` –, a TLS-kulcs tulajdonosa a konténer névterén belül `--user 0 --cap-add CHOWN`-nal a `sudo chown 82:82` helyett, a hívók forrás-IP-je a `slirp4netns` port-továbbítóval, tároló és áttérés), az üzemeltetési kézikönyv három helyzete, a `TROUBLESHOOTING.md` 5. és 6. pontja és a README gyorsindítása.
- Platform: PHP 8.5.12 (a 2026. október 21-én közzétett két kritikus PHP-sérülékenység javítása; korábban 8.5.11), nginx 1.31.8, libcurl 8.23.0; az ellenőrző Trivy 0.76.1.
- Az image saját, NTLM-képes libcurl-t hoz (curl 8.23.0 forrásból, SHA-256 és PGP-ellenőrzéssel), mert az Alpine curl-je NTLM nélkül épül, és így az `EWS_AUTH=ntlm` (Windows-hitelesítésű Exchange) eddig mindig 401-et kapott; a build, az indításkori ellenőrzés és a füstteszt számon kéri az NTLM-képességet, a Rendszerállapot Levelezés kártyája piros, ha hiányozna. A `platform.sh` a libcurl-t is követi.

### English
Rule changes in one sentence (with the specification identifiers):
- KOM-02: the error of a request Exchange (EWS) refused starts with the HTTP status, lists the authentication methods the server offers on a 401, names Exchange's own response code and text (`EWS Error…`), says when a sign-in page or proxy answered or the body was empty, and reports the network error of an unreachable server; an empty answer used to say nothing after "EWS CreateItem failed:".
- AZO-07 (new): after a successful identification by any method the agent lands on the client's record, headed by an "Identified" bar with the method, the time and the call (with a call-note and a "Back to the call queue" button), so every detail of the identified client is in one place; while the call is in progress (30 minutes without a call) a green "Identified client" button in the header leads back to the record from any page; while the call is in progress, leaving the record or the identification page asks first (also during an open question-and-answer session); the Verify code page binds the check to the agent's own ongoing call also when opened from the menu, and moves on to the record after a correct code (the match used to live only on the page, one stray click lost it); on the dashboard the button of the agent's own identified call reads "Client record", and a call a colleague identified cannot be taken over (the disabled button says why, the server refuses too).

**Before upgrading:** no migration, no new deployment key.

Details:
- `TROUBLESHOOTING.md` and the operations manual list what each status means (`EWS_AUTH`, `EWS_URL`, mailbox, proxy, CA).
- Rootless Docker in the documents: a new "Rootless Docker" part of chapter 3 of the installation guide (set-up and start, ports, the subuid/subgid arithmetic – the container's uid 82 is `start + 81` on the host, for example `165617` –, the TLS key's owner set inside the container's user namespace with `--user 0 --cap-add CHOWN` instead of `sudo chown 82:82`, the callers' source IP with the `slirp4netns` port driver, storage and migration), three situations of the operations manual, points 5 and 6 of `TROUBLESHOOTING.md` and the README quick start.
- Platform: PHP 8.5.12 (fixes the two critical PHP vulnerabilities published on 21 October 2026; previously 8.5.11), nginx 1.31.8, libcurl 8.23.0; the scanning Trivy 0.76.1.
- The image ships its own NTLM-capable libcurl (curl 8.23.0 from source, SHA-256 and PGP verified), because Alpine builds curl without NTLM and `EWS_AUTH=ntlm` (Exchange with Windows authentication) always got a 401 before; the build, the start-up check and the smoke test assert NTLM, and the Mail tile of the system status turns red should it be missing. `platform.sh` tracks libcurl too.

## [1.7.1] – 2026-10-21

### Magyar
Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- ÜZ-03.2: a használati profilok fájljai a kiadás mellékletei (`profile-5k.yaml`, `profile-10k.yaml`), és a gyorsindítás letölti az 5k profilét a `profiles/5k/compose.yaml` helyre, mert a letöltött `env.example` `COMPOSE_FILE` sora erre mutat; az 1.7.0 gyorsindítása e fájl nélkül „profiles/5k/compose.yaml nem található” hibával állt meg (friss telepítésnél; a meglévő telepítéseket nem érintette).

**Frissítés előtt:** migráció és új telepítési kulcs nincs. Aki az 1.7.0 `env.example`-jéből indult, töltse le a profilfájlt (`curl -fsSL --create-dirs -o profiles/5k/compose.yaml "$base/profile-5k.yaml"`), vagy törölje a `.env` `COMPOSE_FILE` sorát.

Részletek:
- A kiadás ellenőrzése bukik, ha a `.env.example` `COMPOSE_FILE` sora olyan fájlra mutat, amely nincs a mellékletek között.
- A dokumentumok verziója 1.7.1.

### English
Rule changes in one sentence (with the specification identifiers):
- ÜZ-03.2: the usage profile files are release assets (`profile-5k.yaml`, `profile-10k.yaml`), and the quick start downloads the 5k one to `profiles/5k/compose.yaml`, because `COMPOSE_FILE` in the downloaded `env.example` points there; the 1.7.0 quick start stopped without this file with "profiles/5k/compose.yaml not found" (fresh installations only; existing ones were not affected).

**Before upgrading:** no migration, no new deployment key. Installations started from the 1.7.0 `env.example`: download the profile file (`curl -fsSL --create-dirs -o profiles/5k/compose.yaml "$base/profile-5k.yaml"`), or remove the `COMPOSE_FILE` line from `.env`.

Details:
- The release checks fail when `COMPOSE_FILE` in `.env.example` points to a file that is not among the assets.
- The documents carry version 1.7.1.

## [1.7.0] – 2026-10-21

### Magyar
Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- ÜZ-03.2: három használati profil (`5k`, `10k`, `20k`) kész telepítési leírással: `profiles/5k` és `profiles/10k` Compose-kiegészítő fájl (a `.env` `COMPOSE_FILE` sora választja), `kubernetes/` Kustomize-alap és overlayek; 5 000 ügyfél fölött két alkalmazásgép terheléselosztó mögött külső MariaDB-vel, Valkey-vel és NFS-sel (magas rendelkezésre állás), 10 000 fölött Kubernetes. A Rendszerállapot új „Kapacitás” kártyája az utolsó 15 percben aktív fiókokat, a mai csúcsot és az adatbázis-kapcsolatokat mutatja, és a profil (`HDID_SIZING_PROFILE`) tervezett egyidejű felhasználószámához méri (80 %-nál sárga).
- ADM-03.2: az auditnapló forró kerete az adatbázisban (Beállítások › Adatmegőrzés › „Auditnapló az adatbázisban (nap)”, alapból 150, legalább 30); a régebbi napokat az éjjeli `hdid:prune` napi, tömörített JSON Lines fájlokba írja a `storage` kötetre SHA-256 kísérőfájllal, és csak utána törli; új Auditarchívum oldal letöltéssel (naplózott), „Auditnapló” rendszerállapot-kártya, az auditnapló oldalán az utolsó 7 nap eseményenkénti darabszáma.
- NYT-01.2: az ügyféltörzs-átvétel tételenkénti változásai nem az auditnaplóba, hanem az új Ügyféltörzs-változások oldalra kerülnek (futásonként és tételenként egy sor a tétel, a fiók és a telefonszámok mezőnkénti változásával; kereshető, szűrhető futásra, CSV-export; 2 éves megőrzés); az auditnaplóban a futás összegző sora és a fiókok lezárása, újranyitása, e-mail-változása marad.
- ADM-03: a gépi kulcs elfogadott használata (`api_key.used`) nem naplóesemény; az API kulcsok lista „Utoljára használva” oszlopa mutatja. Az elutasítás és a régi kulcsos használat továbbra is naplózott.

**Frissítés előtt:** két migráció (`sync_run_changes` tábla; `last_seen_at` oszlop a munkatársi és ügyfélfiókokon). Az első éjjeli `hdid:prune` futások a 150 napnál régebbi auditbejegyzéseket archívumfájlba viszik (futásonként legfeljebb 120 napot): ha a régi bejegyzéseket az adatbázisban kell tartani, a frissítés előtt emelje a „Auditnapló az adatbázisban (nap)” értékét. A letöltött `env.example` mostantól Valkey-t használ munkamenetre, gyorsítótárra és sorra (`COMPOSE_PROFILES=db,redis`, `*=redis`), és az 5k profilt választja (`COMPOSE_FILE`); meglévő telepítés `.env`-je változatlanul működik, az áttérés a Valkey-re és egy profilra választható (telepítési útmutató 15. fejezet). Új, választható kulcsok: `HDID_SIZING_PROFILE`, `COMPOSE_FILE`, `OPENHDID_NFS_SERVER`, `OPENHDID_NFS_PATH`. A `docker compose run --rm --no-deps web key` mostantól `APP_KEY=…` és `REDIS_PASSWORD=…` sort ír ki.

Részletek:
- A vezérlőpult számlálói az azonos nézetű ügyintézők között közös gyorsítótárból jönnek (frissítési időnként egy lekérdezés-sorozat); a „Feladatsor” kártya Valkey mellett is mutatja a várakozó feladatok számát.
- A `worker` szerep PHP-időkorlát nélkül fut (a feladat saját 1200 s-os korlátja marad), a `migrate` Valkey mellett zár alatt (`--isolated`), ezért két alkalmazásgép egyszerre indulhat.
- A kiadás ellenőrzése a profilokat (`docker compose config`) és a Kubernetes-overlayeket (kustomize, kubeconform) is ellenőrzi.
- Javítás (kétlépcsős belépés): a küldött SMS- vagy e-mailes kód élettartamát a hibás próbák nem hosszabbítják meg; a kód a kiküldéstől számított 10 percig érvényes.
- Platform: a MariaDB 12.3.3 image frissített digestje; ellenőrző eszközök kustomize 5.8.3 és kubeconform 0.8.0.
- A dokumentumok verziója 1.7.0.

### English
Rule changes in one sentence (with the specification identifiers):
- ÜZ-03.2: three usage profiles (`5k`, `10k`, `20k`) with ready deployment descriptions: Compose override files `profiles/5k` and `profiles/10k` (selected by `COMPOSE_FILE` in `.env`), a Kustomize base and overlays under `kubernetes/`; above 5,000 clients two application hosts behind a load balancer with an external MariaDB, Valkey and NFS (high availability), above 10,000 Kubernetes. The new "Capacity" tile of the system status shows the accounts active in the last 15 minutes, today's peak and the database connections, measured against the profile's design point (`HDID_SIZING_PROFILE`; yellow at 80 %).
- ADM-03.2: a hot window of the audit log in the database (Settings › Retention › "Audit hot days", default 150, at least 30); the nightly `hdid:prune` moves older days into compressed daily JSON Lines files on the `storage` volume with a SHA-256 sidecar and deletes them only afterwards; a new Audit archive page with (audited) downloads, an "Audit log" status tile, and the event counts of the last 7 days on the audit log page.
- NYT-01.2: the per-record changes of an EMD sync no longer go to the audit log but to the new EMD changes page (one row per run and record with the field-level changes of the record, the account and the phone numbers; searchable, filterable by run, CSV export; kept for 2 years); the audit log keeps the run's summary entry and the account closures, reopenings and e-mail changes.
- ADM-03: an accepted API key use (`api_key.used`) is no longer an audit event; the "Last used" column of the API keys list shows it. Rejections and legacy-key uses are still audited.

**Before upgrading:** two migrations (the `sync_run_changes` table; a `last_seen_at` column on staff and client accounts). The first nightly `hdid:prune` runs move audit entries older than 150 days into archive files (at most 120 days per run): raise "Audit hot days" before upgrading if older entries must stay in the database. The downloaded `env.example` now uses Valkey for sessions, cache and queue (`COMPOSE_PROFILES=db,redis`, `*=redis`) and selects the 5k profile (`COMPOSE_FILE`); an existing installation's `.env` keeps working unchanged, moving to Valkey and a profile is optional (installation guide, chapter 15). New optional keys: `HDID_SIZING_PROFILE`, `COMPOSE_FILE`, `OPENHDID_NFS_SERVER`, `OPENHDID_NFS_PATH`. `docker compose run --rm --no-deps web key` now prints an `APP_KEY=…` and a `REDIS_PASSWORD=…` line.

Details:
- The dashboard counters come from a cache shared by everyone with the same view (one round of queries per poll interval); the "Queue" tile shows the pending jobs with Valkey as well.
- The `worker` role runs without a PHP time limit (the job's own 1200 s limit remains), `migrate` runs under a lock with Valkey (`--isolated`), so two application hosts may start at once.
- The release checks validate the profiles (`docker compose config`) and the Kubernetes overlays (kustomize, kubeconform).
- Fix (two-factor sign-in): wrong tries no longer extend the lifetime of a sent SMS or e-mail code; a code is valid for 10 minutes from sending.
- Platform: refreshed digest of the MariaDB 12.3.3 image; checking tools kustomize 5.8.3 and kubeconform 0.8.0.
- The documents carry version 1.7.0.

## [1.6.0] – 2026-10-21

### Magyar
Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- BEL-04.4: bekapcsolható kétlépcsős munkatársi belépés (Beállítások › Admin belépés, alapból ki): hitelesítő alkalmazás (RFC 6238, QR-kód), SMS-kód az ügyféltörzsben szereplő mobilszámra (csak bekötött SMS-átjáróval) és e-mailes kód közül a rendszergazda engedélyez, a munkatárs választ. Minden belépési úton (jelszó, ADFS, Entra, „emlékezz rám” süti) és minden új munkamenetnél kéri, a panel minden kérése a feltétele; a céges szolgáltató tokenben igazolt MFA-ja (`amr`) kapcsolhatóan elfogadott. Elveszett eszköznél rendszergazdai nullázás a Felhasználók listán vagy `hdid:mfa-reset` (a `--off` kikapcsolja).
- BEL-04.3: új munkatársi fiók jelszava csak akkor kötelező, ha nincs bekapcsolt és beállított munkatársi SSO. A `hdid:make-admin` és a `hdid:mfa-reset` minden futása külön auditbejegyzést kap (operációs rendszerbeli felhasználóval és géppel), és e-mailt kap róla minden másik főrendszergazda; bekapcsolt kétlépcsős belépésnél a `hdid:make-admin` meglévő fiókot csak `--force`-szal módosít.
- NYT-03.1: új munkatárs és ügyfél (és a még össze nem kötött fiók, ha az e-mail-címét javítják) mentéskor azonnal az azonos e-mail-című, jelen lévő ügyféltörzstételhez kötődik; ügyfélnél a név, a csomagok és a telefonszámok az ügyféltörzsből jönnek. Ha nincs összekötés, az értesítés megmondja, miért.
- NYT-10, NYT-13: az export kéréstörzsében a `{{ ids }}` idézőjel nélkül, szögletes zárójelek között JSON-listát ad (`"ids": [{{ ids }}]` → `[3,12]`; a csak számjegyekből álló ID szám), idézőjelek között változatlanul szöveg (`"3,12"`). Az ügyféltörzs HTTP 4xx/5xx válaszának törzse (legfeljebb 4000 bájt) az alkalmazásnaplóba kerül; a kérés (token, kéréstörzs) soha.
- AZO-04.1: választható vak kérdés-válasz ellenőrzés (Beállítások › Azonosítás, alapból ki): a tárolt válasz rejtett, az ügyintéző beírja, amit a hívó mond, a rendszer összeveti (NFKC, kis- és nagybetű, ékezet, írásjel és szóköz nélkül, a számjegyek pontosan, beállítható számú elírással), és a választ csak egyezéskor mutatja; egyértelmű eltérés elutasítás, közeli eltérés egy újrapróba, utána „nem eldönthető”. Kézzel csak a „Nem eldönthető” választható. A kérdés-válasz panel letisztult: haladásjelző, a kérdés, egy beírómező, az eredmény egyetlen sorban, a szabályok lenyitható súgóban.
- BEL-01.7: `CLIENT_LOGIN_REQUIRE_EMD=false` mellett az ügyfél ügyféltörzstétel nélkül is beléphet; a lezárás és a csomagjogosultság marad, a rendszerállapot sárgán jelzi.
- ADM-01.3: a Munkatársak lista keresője szavanként keres mindenben, amit a lista mutat (név, e-mail-cím, szerepkör, külső azonosító, cég, beosztás, szervezeti egység, bejelentkezési név).
- CC-04: az API-kulcsok oldaláról új lapon nyílnak a partneri felületleírások (telefonközpont, mobilalkalmazás kiszolgálója, teljes API).

**Frissítés előtt:** két migráció (az azonosítási munkamenetek és lépéseik, valamint a munkatársi fiókok kétlépcsős belépésének új oszlopai, adatváltozás nélkül). Új, választható telepítési kulcsok: `CLIENT_LOGIN_REQUIRE_EMD` (alapból `true`, a működés nem változik), `HDID_MFA_PROVIDER_AMR` (üresen a beépített Entra- és ADFS-jelölések). A kétlépcsős belépés és a vak ellenőrzés alapból ki van kapcsolva; az MFA bekapcsolása előtt érdemes legalább két módot engedélyezni. Aki az export kéréstörzsében eddig `"{{ ids }}"` alakot használt, annál minden marad; listát váró ügyféltörzsnél `[{{ ids }}]`-re kell átírni. A `docs/` mappában ettől a kiadástól csak a PDF-ek vannak.

Részletek:
- Az ügyféltörzs-export kéréstörzsének mentése a rossz helyre írt helyőrzőt érthetően utasítja el: a `{{ now }}` idézőjel nélkül, illetve a `{{ ids }}` idézőjel és zárójel nélkül; a JSON-hiba helye (sor, oszlop) a `[{{ ids }}]` mellett is pontos.
- Auditesemények: `account.linked`, `id_session.answer_checked` (eredmény, elírások száma, próbálkozás; a beírt szöveg sehol nem tárolódik), `mfa.*`, `account.mfa_locked`, `account.admin_made_by_command`, `account.mfa_reset_by_command`, `mfa.switched_off_by_command`. A vak ellenőrzés nem egyező válaszai az ügyintézői óránkénti sikertelen-ellenőrzési korlátba számítanak; a kétlépcsős belépés hibás kódjai a jelszavas zárolástól külön zárolnak.
- A parancsok listája a leírásukkal: `php artisan list hdid`; egy parancs súgója: `php artisan help <parancs>`.
- A felhasználói kézikönyv új képekkel (kétlépcsős belépés, vak ellenőrzés, beállítások), a vak ellenőrzés egyezési szabályainak példatáblázatával és a kétlépcsős belépés szabálytáblázatával; csak a felülettel és a két OpenHDID-dokumentumban is meglévő fogalmakkal dolgozik.
- Az OpenHDID telepítési útmutatója lépésenként leírja az ADFS beállítását: alkalmazáscsoport, szerveralkalmazás, Web API a HDID címével mint azonosítóval, a kliens engedélye, az `E-Mail-Addresses` → `E-Mail Address` kiadási szabály, a `.env` kulcsai (`USER_ADFS_RESOURCE`, `USER_ADFS_EMAIL_CLAIM=email`) és az ellenőrzés PowerShell-lel; az üzemeltetési kézikönyv és a `TROUBLESHOOTING.md` felismeri a leggyakoribb hibát (az access token az ADFS userinfo-végpontjának szól, ezért nincs benne e-mail-igény, és a fiókot a `upn` köti).
- Függőségek, biztonsági javítás: a frontend buildjében a `vite` 8.3.3 (a `rolldown` 1.2.14-gyel), a beágyazott `launch-editor` három sérülékenysége miatt (GHSA-698v-4r3x-3vcv, GHSA-r825-w8mm-5pxw, GHSA-587h-7wmj-h5pm); a futó image-et nem érinti, mert a vite csak a buildben fut.
- A dokumentumok verziója 1.6.0.

### English
Rule changes in one sentence (with the specification identifiers):
- BEL-04.4: optional two-factor sign-in for staff (Settings › User login, off by default): the administrator allows an authenticator app (RFC 6238, QR code), an SMS code to the mobile number in the master data (only with a connected SMS gateway) and an e-mail code, the staff member chooses. Asked on every sign-in path (password, ADFS, Entra, remember-me cookie) and in every new session, required by every panel request; the company provider's MFA proven in the token (`amr`) is accepted, switchable. A lost device: administrator reset on the Users list or `hdid:mfa-reset` (`--off` switches it off).
- BEL-04.3: a new staff account needs a password only while no staff SSO provider is switched on and configured. Every run of `hdid:make-admin` and `hdid:mfa-reset` gets its own audit entry (with the operating-system user and host) and is e-mailed to every other super-admin; with two-factor sign-in on, `hdid:make-admin` changes an existing account only with `--force`.
- NYT-03.1: a new staff member or client (and a not yet linked account whose e-mail is corrected) is linked on save, at once, to the present master data record with the same e-mail; for clients the name, packages and phone numbers then come from the master data. When nothing is linked, the notification says why.
- NYT-10, NYT-13: in the export payload `{{ ids }}` written without quotes between brackets gives a JSON list (`"ids": [{{ ids }}]` → `[3,12]`; all-digit IDs as numbers), inside quotes it stays text (`"3,12"`). The body of a master data HTTP 4xx/5xx response (up to 4000 bytes) goes to the application log; the request (token, payload) never does.
- AZO-04.1: optional blind question-and-answer check (Settings › Identification, off by default): the stored answer is hidden, the agent types what the caller says, the system compares it (NFKC, ignoring case, accents, punctuation and spaces, digits exactly, a configurable number of typos) and shows the answer only on a match; a clear difference is a rejection, a close one gets one retry, then "cannot decide". Only "Cannot decide" can be chosen by hand. The question-and-answer panel is cleaner: progress dots, the question, one input, the result in a single line, the rules in a fold-out help.
- BEL-01.7: with `CLIENT_LOGIN_REQUIRE_EMD=false` clients sign in without a master data record; closure and package entitlement still apply, the system status shows it in yellow.
- ADM-01.3: the search of the Users list matches word by word everything the list shows (name, e-mail, role, external id, company, job title, department, login name).
- CC-04: the API keys page opens the partner interface documents in a new tab (call center, mobile app backend, complete API).

**Before upgrading:** two migrations (new columns of identification sessions and their steps, and of the staff accounts' two-factor sign-in, no data change). New optional deployment keys: `CLIENT_LOGIN_REQUIRE_EMD` (default `true`, behaviour unchanged), `HDID_MFA_PROVIDER_AMR` (empty: the built-in Entra and ADFS markers). Two-factor sign-in and the blind check are off by default; before switching MFA on, allow at least two methods. An export payload with `"{{ ids }}"` keeps working as before; for a master data endpoint expecting a list, change it to `[{{ ids }}]`. From this release `docs/` holds the PDFs only.

Details:
- Saving the export payload explains a misplaced placeholder: `{{ now }}` outside quotes, or `{{ ids }}` outside quotes without brackets; the position of a JSON error (line, column) stays exact next to `[{{ ids }}]`.
- Audit events: `account.linked`, `id_session.answer_checked` (result, number of typos, attempt; the typed text is never stored), `mfa.*`, `account.mfa_locked`, `account.admin_made_by_command`, `account.mfa_reset_by_command`, `mfa.switched_off_by_command`. Blind-check answers that do not match count toward the agent's hourly limit of failed checks; wrong two-factor codes lock apart from the password lock.
- All commands with their descriptions: `php artisan list hdid`; the help of one: `php artisan help <command>`.
- The user manual has new screenshots (two-factor sign-in, blind check, settings), an example table of the blind check's matching rules and a rule table of two-factor sign-in; it only works with the interface and with terms the two OpenHDID documents also know.
- The OpenHDID installation guide describes the ADFS setup step by step: application group, server application, a Web API with the HDID address as its identifier, the client's permission, the `E-Mail-Addresses` → `E-Mail Address` issuance rule, the `.env` keys (`USER_ADFS_RESOURCE`, `USER_ADFS_EMAIL_CLAIM=email`) and the check with PowerShell; the operations guide and `TROUBLESHOOTING.md` recognise the most common fault (the access token is meant for the ADFS userinfo endpoint, so it carries no e-mail claim and `upn` binds the account).
- Dependencies, security fix: the frontend build uses `vite` 8.3.3 (with `rolldown` 1.2.14) because of three vulnerabilities of the bundled `launch-editor` (GHSA-698v-4r3x-3vcv, GHSA-r825-w8mm-5pxw, GHSA-587h-7wmj-h5pm); the running image is not affected, vite only runs in the build.
- The documents carry version 1.6.0.

## [1.5.2] – 2026-10-15

### Magyar
Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- BEL-00.1: az ADFS-hibakeresés naplója a tokeneket az érkezett alakban is mutatja, aláírás nélkül.

**Frissítés előtt:** migráció és új telepítési kulcs nincs.

Részletek:
- `HDID_ADFS_DEBUG=true` mellett az `ADFS debug: token response` sor mindkét tokennél a fejléc- és tartalomrészt az érkezett alakban (`received`, például jwt.io-ba másolható) és visszafejtve (`header`, `claims`) adja. Az aláírásnak csak a hossza látszik (`signature`), a frissítő tokennek csak a megléte, így a naplóban nincs újra felhasználható token.
- A web konténer PHP-FPM-je a 64 KiB-nál rövidebb naplósorokat egyben adja ki (eddig 8 KiB felett tördelte, és a `grep 'ADFS debug'` csak a sor elejét mutatta).
- Platform: a frontend buildje Node 24.22.0 LTS-sel (korábban 24.21.0).
- A dokumentumok verziója 1.5.2.

### English
Rule changes in one sentence (with the specification's IDs):
- BEL-00.1: the ADFS debug log also shows the tokens as received, without their signatures.

**Before upgrading:** no migration and no new setting.

Details:
- With `HDID_ADFS_DEBUG=true` the `ADFS debug: token response` line gives the header and payload of both tokens as received (`received`, ready to paste into jwt.io, for example) and decoded (`header`, `claims`). Only the signature's length shows (`signature`) and only the presence of the refresh token, so the log never holds a token that could be presented again.
- The web container's PHP-FPM keeps log lines shorter than 64 KiB in one piece (it used to split them above 8 KiB, and `grep 'ADFS debug'` showed only the start of the line).
- Platform: the frontend is built with Node 24.22.0 LTS (previously 24.21.0).
- The documents carry version 1.5.2.

## [1.5.1] – 2026-10-14

### Magyar
Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- BEL-00.1: az ADFS-belépés fiókot kötő e-mail-igénye (claimje) telepítéskor megadható, az access token igényei is számítanak, és az auditnapló a döntés minden lépését rögzíti; a tokenek naplózása hibakereséshez bekapcsolható.

**Frissítés előtt:** migráció nincs; új, választható telepítési kulcsok: `USER_ADFS_EMAIL_CLAIM`, `CLIENT_ADFS_EMAIL_CLAIM`, `USER_ADFS_SCOPES`, `CLIENT_ADFS_SCOPES`, `USER_ADFS_RESOURCE`, `CLIENT_ADFS_RESOURCE`, `HDID_ADFS_DEBUG` (alapból `false`). Üresen az ADFS a `emailaddress`, `email`, `upn`, `preferred_username`, `unique_name` sorrendben keres, az ID tokenben és az access tokenben is (eddig `email`, `upn`, `preferred_username`, `unique_name`, csak az ID tokenben). Ha a tokenekben a fiók címétől eltérő `emailaddress` érkezik, a régi viselkedés a `USER_ADFS_EMAIL_CLAIM=email,upn,preferred_username,unique_name` értékkel állítható vissza.

Részletek:
- Az igény neve a teljes igénytípus-URI végére is illeszkedik (`…/claims/emailaddress`); a többértékű igény csak egyetlen címmel használható.
- Az ADFS access tokenjének igényei csak akkor számítanak, ha a token ugyanazzal a kulccsal aláírt, a felderítési dokumentum `access_token_issuer` kibocsátójától származik, nem járt le és ennek az alkalmazásnak szól (`appid`); egyébként a rendszer csak az ID tokent nézi, és az okot naplózza. Az `allatclaims` hatókör nem kell.
- Választható további hatókör (`*_ADFS_SCOPES`) és Web API-azonosító (`*_ADFS_RESOURCE`, a `resource` paraméterben).
- Auditnapló: a sikeres és az elutasított ADFS-belépés megnevezi a döntő igényt és tokent, a kihagyott igényeket az okkal (`missing`, `empty`, `domain_account`, `not_an_email`, `several_addresses`) és az access token állapotát; használható igény híján a keresett igényeket és a két token igényneveit is, értékek nélkül. Az ADFS visszautasításának szövege (`error_description`, MSIS-kóddal) is bekerül. Entránál a nem ellenőrzött `email` igény `unverified` okkal jelenik meg.
- Használható e-mail-igény híján új elutasítási ok és üzenet: `no_email_claim`, „A bejelentkezési szolgáltatás nem küldött használható e-mail-címet, ezért a fiók nem azonosítható.” (eddig „érvénytelen adatok”).
- `HDID_ADFS_DEBUG=true`: minden ADFS-belépés az engedélykérést, a tokenválaszt a két token visszafejtett fejlécével és igényeivel, valamint a döntést `ADFS debug:` üzenettel warning szinten naplózza; a nyers token és a frissítő token nem kerül bele. Amíg be van kapcsolva, a „Belépési szolgáltatók” csempe sárga; a csempe a keresett ADFS-igényeket is mutatja.
- Platform: nginx 1.31.7 mainline (korábban 1.31.6), az ellenőrző Trivy 0.76.0.
- A dokumentumok verziója 1.5.1.

### English
Rule changes in one sentence each (with the specification's identifiers):
- BEL-00.1: the claim that binds an ADFS sign-in to an account is set at installation, the access token's claims count too, and the audit log records every step of the decision; logging the tokens can be switched on for troubleshooting.

**Before upgrading:** no migration; new optional installation keys: `USER_ADFS_EMAIL_CLAIM`, `CLIENT_ADFS_EMAIL_CLAIM`, `USER_ADFS_SCOPES`, `CLIENT_ADFS_SCOPES`, `USER_ADFS_RESOURCE`, `CLIENT_ADFS_RESOURCE`, `HDID_ADFS_DEBUG` (default `false`). Left empty, ADFS looks for `emailaddress`, `email`, `upn`, `preferred_username`, `unique_name` in this order, in the ID token and the access token (previously `email`, `upn`, `preferred_username`, `unique_name`, ID token only). If the tokens carry an `emailaddress` other than the account's address, `USER_ADFS_EMAIL_CLAIM=email,upn,preferred_username,unique_name` restores the old behaviour.

Details:
- A claim name also matches the end of a full claim-type URI (`…/claims/emailaddress`); a multi-valued claim counts only when it holds a single address.
- The claims of the ADFS access token count only when it is signed with the same keys, issued by the discovery document's `access_token_issuer`, unexpired and issued to this application (`appid`); otherwise the ID token stands alone and the reason is logged. The `allatclaims` scope is not needed.
- Optional extra scopes (`*_ADFS_SCOPES`) and Web API identifier (`*_ADFS_RESOURCE`, sent as `resource`).
- Audit log: a successful or refused ADFS sign-in names the deciding claim and token, the claims passed over with the reason (`missing`, `empty`, `domain_account`, `not_an_email`, `several_addresses`) and the access token's state; without a usable claim also the claims looked for and the claim names of both tokens, never their values. The text of an ADFS refusal (`error_description`, with the MSIS code) is recorded too. For Entra an unverified `email` claim shows as `unverified`.
- Without a usable e-mail claim there is a new refusal reason and message: `no_email_claim`, "The sign-in service did not send a usable e-mail address, so the account cannot be identified." (previously "invalid credentials").
- `HDID_ADFS_DEBUG=true`: every ADFS sign-in logs the authorization request, the token response with the decoded header and claims of both tokens, and the decision as `ADFS debug:` messages at warning level; the raw tokens and the refresh token are left out. While it is on the "Login providers" tile is yellow; the tile also shows the ADFS claims looked for.
- Platform: nginx 1.31.7 mainline (previously 1.31.6), the Trivy scanner is 0.76.0.
- The documents carry version 1.5.1.

## [1.5.0] – 2026-10-10

### Magyar
Szabályváltozások egy mondatban (a specifikáció azonosítóival):
- BEL-03.1: a „Csak prémium kiszolgálás” kapcsolóval a rendszer csak a prémium ügyfeleket szolgálja ki: a normál ügyfél nem léphet be, a telefonos menü nem ismeri fel, és egyetlen munkatársi nézet sem mutatja; a meglévő adat nem változik, kikapcsolva minden visszaáll, és amíg be van kapcsolva, a panel tetején sáv jelzi.
- ADM-07: a mentést igénylő oldalak (Beállítások, ügyfél és munkatárs létrehozása és szerkesztése, üzenetsablon) mentés nélküli elhagyásakor ablak sorolja fel a függő módosításokat, és mentést, elvetést vagy maradást kínál; a Beállítások módosított fülein pont látszik.
- ÜZ-01: a beállítások mentése egyszerre egy, a tárolt értékek friss állapotán ellenőriz, és ha a megerősítés óta más mentés megváltoztatta a következményt, újra megerősítést kér.

**Frissítés előtt:** migráció és új telepítési kulcs nincs. A „Csak prémium kiszolgálás” alapból ki van kapcsolva, így frissítés után minden úgy működik, mint előtte.

Részletek:
- Csak prémium kiszolgálás (Beállítások › Csomagok): a normál csomaglista bekapcsolt módban semmilyen jogosultságot nem ad; a portálra belépett normál ügyfél a következő lépésénél kilép (a szokásos „nem jogosult” üzenettel; az auditnapló `premium_only` jelzést ad), a hívásesemény a normál hívót ismeretlenként rögzíti, a normál hívósor hívásai nem látszanak, a korábbi hívásoknál és azonosításoknál a normál ügyfél neve helyett „ismeretlen” áll. Az átvétel a normál ügyfeleket is hozza, semmi nem törlődik és nem zárul le. Bekapcsoláskor megerősítés mutatja az érintett ügyfeleket; üres prémium listával nem kapcsolható be. Új Rendszerállapot-kártya: „Csak prémium ügyfelek” (sárga, amíg be van kapcsolva). A normál ügyfélszolgálati elérhetőség szerkeszthető marad: az elutasított normál ügyfelek ezt látják a portál belépőoldalán. Bekapcsolt módban: egy prémium és egy normál ügyfél közös száma közös marad (a hívót a telefonos menü nem ismeri fel); a hívósorhoz nem rendelt hívás prémium szintű; a bekapcsolás előtt normál ügyfélhez rendelt folyó hívás ismeretlen hívóként jelenik meg; a mobil kiszolgáló normál ügyfélnek nem kap kódot; a csomaglisták módosításának megerősítése megnevezi, kik nem lesznek kiszolgáltak; a csak normál hívásokra beállított ügyintézők a prémium hívásokat kezelik; a kimutatások a normál szintet is mutatják.
- A mobil kiszolgáló általában sem kap kódot olyan ügyfélnek, akinek a csomagja nem ad portáljogosultságot (eddig a kód elkészült, de a telefonos menü elutasította).
- Nem mentett módosítások: a „Mentés és tovább” ugyanúgy ment, mint a Mentés gomb (a szükséges megerősítéssel együtt), hibás adatnál az oldal marad; csak a rendszer saját oldalaira lép tovább. A böngészőfül bezárásakor, frissítésekor vagy az űrlap Mégse gombjánál a böngésző saját kérdése jelenik meg.
- Beállítások: az eltávolított kép csak a mentéskor törlődik a tárhelyről (eddig azonnal, így egy elvetett módosítás hiányzó fájlra mutatott).
- Biztonsági felülvizsgálat (a két új funkció): a bekapcsolás előtt megnyitott normál ügyfél adatlapja, szerkesztő oldala és fülei a következő kattintásnál elutasítanak; a rejtett ügyfélről írt hívásjegyzet, kézi azonosítási indoklás és a neki szóló üzenetek címzettje, tárgya, szövege és hibája helyén „rejtve” áll, a címzettre keresés és az ügyféltörzs-tételek cégszűrője sem fedi fel; a főügyfél-választó csak kiszolgált főügyfelet ad; a megerősítések a már most nem kiszolgált ügyfeleket csak megszámolják; a telefonközpont a rejtett ügyfél hívását nem azonosítottként kapja; a bekapcsolás előtt sorba állt, normál ügyfélnek szóló üzenet nem megy ki („Visszavonva”, `message.cancelled`); a kód-, PIN- és kérdés-válasz-elutasítás auditnapló-bejegyzése `premium_only` jelzést kap, ha a mód volt az oka; az auditnapló a neveket megtartja, de a rejtett ügyfél adatlapjára nem hivatkozik.
- Beállítások: egyszerre egy mentés fut, és az ellenőrzések a tárolt értékek friss állapotán futnak, így két rendszergazda egyidejű mentése sem kapcsolhatja be a „Csak prémium kiszolgálás”-t üres prémium listával; ha a megerősítő ablak nyitva tartása alatt más mentés megváltoztatta a következményt, a mentés megáll, és az ablak az újat mutatja; a megerősítés kiszolgálóoldalon sem kerülhető meg. A beállítások gyorsítótárába egy közvetlenül a mentés előtt olvasott régi állapot nem kerülhet vissza.
- Nem mentett módosítások: a „Mentés és tovább” a panel űrlapját (például a kilépést) egyszer küldi be, és a hivatkozásnak csak az útvonalát küldi, így nem megbízott proxy mögött is a kattintott oldalra lép tovább.
- A dokumentumok verziója 1.5.0.

### English
Rule changes in one sentence each (with the specification's identifiers):
- BEL-03.1: with the "Premium only" switch the system serves premium clients only: standard clients cannot sign in, the phone menu does not recognise them, and no staff view shows them; existing data is untouched, switching off brings everything back, and while it is on a bar on top of the panel says so.
- ADM-07: leaving a page that needs saving (settings, creating and editing clients and staff, message templates) without saving opens a dialog listing the pending changes and offering save, discard or stay; the settings tabs with changes get a dot.
- ÜZ-01: settings are saved one at a time, checked against the freshly stored values, and a save whose consequences changed since its confirmation asks again.

**Before upgrading:** no migration and no new installation key. "Premium only" is off by default, so after the upgrade everything works as before.

Details:
- Premium only (Settings › Packages): while on, the standard package list grants nothing; a standard client signed in to the portal is signed out at the next step (with the usual "not entitled" message; the audit log adds `premium_only`), the call event records a standard caller as unknown, calls of the standard queue are not shown, and earlier calls and identifications show "unknown" instead of a standard client's name. The sync keeps importing standard clients; nothing is deleted or closed. Switching on asks for confirmation naming the clients affected; with an empty premium list it cannot be switched on. New status tile: "Premium clients only" (yellow while on). The standard support contact stays editable: turned-away standard clients see it on the portal's login page. While on: a number a premium and a standard client share stays shared (the phone menu does not recognise the caller); a call on an unmapped queue gets the premium level; an ongoing call matched to a standard client before the switch shows as an unknown caller; the mobile backend gets no code for a standard client; the confirmation of package list changes names who stops being served; agents set to standard calls only handle premium calls; reports keep showing the standard level.
- The mobile backend no longer gets a code for any client whose package grants no portal access (the code used to be issued and then refused by the phone menu).
- Unsaved changes: "Save and go on" saves exactly like the Save button (including any confirmation it needs) and stays on the page when the data is refused; it only follows links of the system itself. Closing or reloading the browser tab, or the form's Cancel button, shows the browser's own question.
- Settings: a removed image is deleted from the storage only when the change is saved (it used to go at once, so a discarded change left a missing file).
- Security review (the two new features): a standard client's page, edit page and tabs opened before the switch are refused on the next click; a call note, the reason of a manual identification and the recipient, subject, body and error of messages to a hidden client read "hidden", and neither a recipient search nor the company filter of the master data records reveals them; the sponsor picker offers served sponsors only; the confirmations count clients not served at present without naming them; the call center sees a hidden client's call as not identified; a message queued for a standard client before the switch is not sent ("Cancelled", `message.cancelled`); the audit entry of a refused code, PIN or question-and-answer identification carries `premium_only` when the mode was the reason; the audit log keeps the names but links no page of a hidden client.
- Settings: one save runs at a time and the checks run on the freshly stored values, so two administrators saving at once cannot switch "Premium only" on with an empty premium list; when another save changed the consequences while the confirmation was open, the save stops and the dialog shows the new ones; the confirmation cannot be skipped on the server either. A state read just before a save can no longer be put back into the settings cache.
- Unsaved changes: "Save and go on" submits a panel form (such as signing out) once, and sends only the path of a link, so behind a proxy that is not trusted it still goes to the page clicked.
- The documents carry version 1.5.0.

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
- BEL-01.1: a panelen megjelenített belépési hivatkozást az auditnapló megjelöli, a vele tett belépés bejegyzése megnevezi a munkatársat.
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
- NYT-13: az ID-lista lekérdezésénél a belépés hibája is az ID-lista állapotában és az auditnaplóban látszik.
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
- Tesztkörnyezeti belépés debug mód nélkül: a „Belépési hivatkozás küldése” értesítése a hivatkozást is mutatja, ha a Beállítások › Ügyfél belépés új kapcsolója be van kapcsolva; új rendszerállapot-csempe („Belépési hivatkozás előnézete”) sárga, amíg be van kapcsolva. Csomagnév-egységesítés egy helyen (`PackageName`): az ügyféltörzsből ékezettel vagy másképp írt csomagnév is a listához talál, az adatbázis-szűrők a táblában lévő pontos alakokat kapják. A kéréstörzs JSON-hibáinál sor- és oszlopszám, a hiba fajtájával (felesleges vessző a záró jel előtt, aposztróf, idézőjel nélküli kulcs vagy érték, lezáratlan szöveg, hiányzó vessző vagy kettőspont).
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
- Ügyféltörzs-átvétel: a munkatárs e-mail-változását is követi a fiók; ha az új cím már másik fiókon áll, a régi cím marad, a futás nem bukik és a fiók nem zárul le, az ütközést a futás részletei, az auditnapló, a napló és a Rendszerállapot jelzi (a belépés az eltérés miatt elutasítva az ütközés rendezéséig). A besorolás (ügyfél–munkatárs) váltása ugyanannyi türelmi futást kap, mint a hiányzás; utána a régi fiók lezárul, visszaváltáskor újranyílik. Új migráció (kapcsolat-megszűnés oka, függőben lévő besorolás).
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
- Belépési SMS-kód: egy telefonszámra percenként 1, óránként 3 kód mehet ki (beállítható); a felette érkező kérést a rendszer csendben elutasítja és `message.refused` eseménnyel naplózza. A portál egy újraküldést kínál, utána e-mailes belépési hivatkozást (csak ha a tartalék kapcsoló és az e-mail link belépés is be van kapcsolva) vagy az ügyfélszolgálatot. A többi SMS-t a keret nem érinti. Hibás kód csak élő kód mellett számít a zárolásba, a zárolt ügyfél nem kap új kódot, és a válasz ilyenkor is semleges, az ok az auditnaplóban.
- Minden kimenő e-mail és SMS végeredménye auditbejegyzés az ügyfélnél (`message.sent`, `message.failed`).
- A panel belépőjén a zárolt és a kikapcsolt jelszavas belépés is a címenkénti korlátba számít; a lezárt vagy jogosultság nélküli fiók helyes jelszava nem számít hibás jelszónak. Egy őr munkamenetének lejárata nem lépteti ki a másikat (ügyintéző, aki ugyanabban a böngészőben a portált is használja). A belépési hivatkozás köztes oldala frissítés után is megtalálja a linket.
- Vezérlőpult: a hívás felvétele, átvétele, elengedése és a nem fogadott hívás kezeltnek jelölése az azonosítási jogot is kéri. Kézi azonosítás csomag nélkül maradt ügyfélre nem rögzíthető. Az ügyfél sorának törlése előbb lezárja a fiókot (a főügyfél kapcsolt ügyfeleivel). A panelen átírt telefonszám új számnak számít, a régi megerősítése nem öröklődik. A gépi API elutasított kérései címenként és okonként percenként egy auditsort írnak.
- Ügyféltörzs-átvétel, időkorlát-védelem: a panelről feltöltött CSV/XLSX-et is a háttérfolyamat dolgozza fel (eddig a webkérésben futott, és egy nagy fájlnál a PHP időkorlátja félbevágta, a futás „Futó” maradt, a zár egy óráig blokkolta a következő átvételt). Az átvétel 120 mp alatti `max_execution_time` mellett el sem indul; a PHP által félbeszakított futás „Sikertelen” lesz az okkal, a zár felszabadul. Új „PHP időkorlát” rendszerállapot-kártya; a kép `php.ini`-je 300 mp-et ad (`request_terminate_timeout` 330 s), az indításkori ellenőrzés 120 alatt leáll.
- Ügyféltörzs-átvétel, előrejelzés: látható, mely fiókok zárulnak le a következő átvételnél, ha a tételük továbbra is hiányzik — az Ügyféltörzs (EMD) listán jelvény, szűrő és „A következő átvételnél lezárul” fül, a futás eredményében darabszám és névminta (próbafuttatásnál is), a rendszerállapoton sárga jelzés, az Ügyfelek és Munkatársak listán azonos nevű fül a gyűjtőszámmal.
- Fordítások: a Hívások lista „Összes” füle, a `sorban` átvételi státusz és a „hiányzik az ügyféltörzsből” lezárási ok magyarul. A lábléc-beállítás segédszövege már nem ígér Markdown-értelmezést.
- Függőségek, biztonsági javítások: Filament 5.9.0 (CVE-2026-104181, a többtényezős hitelesítés kezelőműveletei jelszó-újraellenőrzés nélkül; a HDID nem használja az MFA-t, de a javított verzió megy ki), league/commonmark 2.10.3 (GHSA-97jj-33gv-5xf9, GHSA-3q6v-r5mr-hxv8: HTML-szűrő megkerülése és négyzetes idejű feldolgozás a táblázat-bővítményben; a hírek Markdownját ez rendereli), Livewire 4.4.7 és a Symfony 8.1 javítóverziói; a build-függőségekben a source-map-js 1.2.2 (GHSA-68fv-2mgg-jv7q).
- Frissítés: új adatbázis-migrációk (`sync_id_list_items`, a fiókok munkamenet- és zárolási oszlopai, a belépési hivatkozás érvényessége, a kimenő üzenetek címzett-indexe), amelyeket a `migrate` szolgáltatás magától lefuttat. Az új `EMD_SYNC_ID_LIST_URL` kulcs csak a `{{ ids }}` használatához kell, nélküle minden a korábbiak szerint működik. A `USER_ENTRA_MOBILE_CLIENT_ID` kulcs megszűnt (munkatársi mobil tokencsere nincs).

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
