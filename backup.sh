#!/usr/bin/env bash
#
# OpenHDID – mentés és visszaállítás egy paranccsal.
#
# A compose.yaml mellé kerül (a kiadás melléklete), és mindig a saját mappájából dolgozik,
# bárhonnan indítják. A mentések a mappa backup/ almappájába kerülnek, időbélyeggel.
#
# Használat:
#   ./backup.sh                      mentés: backup/ÉÉÉÉ-HH-NN_óóppmm
#   ./backup.sh list                 a mentések listája
#   ./backup.sh restore              visszaállítás a legutóbbi mentésből (megerősítést kér)
#   ./backup.sh restore <név>        visszaállítás egy adott mentésből
#
# Kapcsolók:
#   --keep N                  mentés után a legutóbbi N mentés marad (alapból 14; 0 = mind marad)
#   --quiet                   csak a hibák (ütemezett futtatáshoz)
#   --yes                     visszaállítás megerősítés nélkül
#   --no-safety-backup        visszaállítás előtt nem készül biztonsági mentés a jelenlegi állapotról
#
# Egy mentés tartalma (a mappa 700-as, a fájlok 600-as jogúak):
#   database.sql.gz   a beépített MariaDB adatbázisa, konzisztens pillanatképként
#   storage.tar.gz    a storage kötet (arculati képek, feltöltések)
#   config.tar.gz     .env, db.env, compose.yaml, compose.override.yaml és a ca mappa –
#                     benne az APP_KEY és minden jelszó: ezt a fájlt védje a legjobban
#   tls.tar.gz        a tanúsítvány és a kulcs (a tls mappa)
#   info.txt          időpont, OpenHDID-verzió, image, adatbázis
#   SHA256SUMS        ellenőrzőösszegek; visszaállítás előtt ellenőrizve
#
# Külső adatbázisnál (a db profil nélkül) az adatbázist az adatbázis üzemeltetője menti és
# állítja vissza; a szkript a többit kezeli.
#
# Root nem kell: sem sudo, sem root jogú konténer. A docker parancsot futtató felhasználó
# (a docker csoport tagja, vagy rootless Dockernél a démon tulajdonosa) indítja; a tls mappát
# az alkalmazás saját felhasználója (uid 82) olvassa és írja, így rootful és rootless
# Dockerrel is a konténer által látott tulajdonos áll vissza.
#
# Napi mentés cronnal (2:30-kor):  30 2 * * * /opt/openhdid/backup.sh --quiet
# A mentéseket rendszeresen másolja a gazdagépen kívülre, titkosítva.
set -euo pipefail
umask 077

DIR=$(cd -- "$(dirname -- "$0")" && pwd -P)
SELF="$DIR/$(basename -- "$0")"
cd "$DIR"
BACKUPS="$DIR/backup"
STAMP_PATTERN='^[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}$'
APP_SERVICES=(web worker sync-worker scheduler)

KEEP=14
QUIET=0
YES=0
SAFETY=1
COMMAND=backup
NAME=""

say() { [[ $QUIET == 1 ]] || printf '%s\n' "$*"; }
step() { [[ $QUIET == 1 ]] || printf '\n\033[1m==> %s\033[0m\n' "$*"; }
die() {
    printf '\033[31mHIBA: %s\033[0m\n' "$*" >&2
    exit 1
}

while [[ $# -gt 0 ]]; do
    case $1 in
        list | restore) COMMAND=$1; shift ;;
        --keep) [[ ${2:-} =~ ^[0-9]+$ ]] || die "A --keep után szám kell."; KEEP=$2; shift 2 ;;
        --quiet) QUIET=1; shift ;;
        --yes) YES=1; shift ;;
        --no-safety-backup) SAFETY=0; shift ;;
        -h | --help) awk 'NR > 2 && /^#/ { sub(/^# ?/, ""); print; next } NR > 2 { exit }' "$SELF"; exit 0 ;;
        -*) die "Ismeretlen kapcsoló: $1 (súgó: ./backup.sh --help)" ;;
        *) [[ $COMMAND == restore && -z $NAME ]] || die "Ismeretlen paraméter: $1"; NAME=$1; shift ;;
    esac
done

command -v docker >/dev/null || die "Nincs docker parancs."
docker compose version >/dev/null 2>&1 || die "A docker compose nem érhető el (Docker Compose 2.24+ kell)."
[[ -f compose.yaml ]] || die "Nincs compose.yaml a szkript mappájában ($DIR)."

checksum() {
    if command -v sha256sum >/dev/null; then sha256sum "$@"; else shasum -a 256 "$@"; fi
}

# The account and the database inside the mariadb container: db.env gives the migration
# account, which may also come from a secret file (NAME_FILE).
DB_SHELL='u=${DB_USERNAME:-}; [ -n "$u" ] || u=$(cat "${DB_USERNAME_FILE:-/dev/null}" 2>/dev/null)
p=${DB_PASSWORD:-}; [ -n "$p" ] || p=$(cat "${DB_PASSWORD_FILE:-/dev/null}" 2>/dev/null)
db=${MARIADB_DATABASE:?}
[ -n "$u" ] && [ -n "$p" ] || { echo "a migrációs fiók (db.env: DB_USERNAME, DB_PASSWORD) nincs megadva" >&2; exit 1; }
export MYSQL_PWD="$p"
'

# Command output is read into a variable before it is searched: under pipefail a
# "cmd | grep -q" fails when grep quits at the first match and cmd still writes (SIGPIPE).
# Whether this installation runs the bundled database (the db profile).
bundled_db() {
    local services
    services=$(docker compose config --services 2>/dev/null) || return 1
    grep -qx mariadb <<<"$services"
}
running() {
    local services
    services=$(docker compose ps --status running --services 2>/dev/null) || return 1
    grep -qx "$1" <<<"$services"
}
app_image() {
    local images
    images=$(docker compose config --images 2>/dev/null) || true
    grep -m1 '/openhdid:' <<<"$images" || true
}

lock() {
    mkdir -p "$BACKUPS"
    chmod 700 "$BACKUPS"
    mkdir "$BACKUPS/.lock" 2>/dev/null \
        || die "Már fut egy mentés vagy visszaállítás ($BACKUPS/.lock). Ha biztosan nem, törölje a mappát."
    trap 'rm -rf "$BACKUPS/.lock" "$BACKUPS"/.tmp-*' EXIT
}

# A one-off container of the web service. Compose reports creating it on stderr, which a
# cron job would mail every night: that output is shown only when the run fails.
run_web() {
    local err status=0
    err=$(mktemp)
    docker compose run --rm --no-deps -T "$@" 2>"$err" || status=$?
    if [[ $status != 0 ]]; then
        grep -vE '^ ?Container .* (Creating|Created)' "$err" >&2 || true
    fi
    rm -f "$err"
    return "$status"
}

# The operator's own files, archived with the operator's rights.
config_archive() {
    local files=(.env db.env compose.yaml)
    [[ -f compose.override.yaml ]] && files+=(compose.override.yaml)
    [[ -d ca ]] && files+=(ca)
    # Archive and compress in two steps: some tar builds (bsdtar) pad a compressed stream.
    tar cf - "${files[@]}" | gzip -c
}

# The TLS key belongs to the container user (uid 82, or its namespace id under rootless
# Docker) and is not readable for the operator: that user reads it, without root.
tls_archive() {
    run_web --entrypoint sh web -c 'tar czf - -C /etc/openhdid/tls .' </dev/null
}

create_backup() {
    local suffix=${1:-} name target tmp image version db_state
    [[ -f .env ]] || die "Nincs .env a szkript mappájában: ez nem egy telepítés mappája."
    image=$(app_image)
    [[ -n $image ]] || die "A compose.yaml-ből nem olvasható ki az OpenHDID image (OPENHDID_VERSION a .env-ben?)."
    version=${image##*:}

    name="$(date +%Y-%m-%d_%H%M%S)${suffix}"
    target="$BACKUPS/$name"
    tmp="$BACKUPS/.tmp-$name"
    [[ ! -e $target ]] || die "Már van ilyen nevű mentés: $name"
    mkdir "$tmp"

    step "Mentés: backup/$name"

    if bundled_db; then
        running mariadb || die "A mariadb szolgáltatás nem fut; indítsa el (docker compose up -d), majd mentsen újra."
        docker compose exec -T mariadb sh -c "$DB_SHELL"'exec mariadb-dump -u"$u" --single-transaction --no-tablespaces --default-character-set=utf8mb4 "$db"' </dev/null \
            | gzip > "$tmp/database.sql.gz" || die "Az adatbázis mentése nem sikerült."
        [[ $(gzip -dc "$tmp/database.sql.gz" | tail -n 1) == *'Dump completed'* ]] || die "Az adatbázis mentése hiányos."
        db_state=included
        say "    adatbázis: $(du -h "$tmp/database.sql.gz" | cut -f1)"
    else
        db_state=external
        say "    adatbázis: külső kiszolgáló, azt az adatbázis üzemeltetője menti"
    fi

    run_web --entrypoint sh web -c 'tar czf - -C /app/storage .' </dev/null \
        > "$tmp/storage.tar.gz" || die "A storage kötet mentése nem sikerült."
    gzip -t "$tmp/storage.tar.gz" 2>/dev/null || die "A storage kötet mentése sérült."
    say "    storage kötet: $(du -h "$tmp/storage.tar.gz" | cut -f1)"

    config_archive > "$tmp/config.tar.gz" || die "A beállítások mentése nem sikerült (a .env és a db.env olvasható?)."
    gzip -t "$tmp/config.tar.gz" 2>/dev/null || die "A beállítások mentése sérült."
    tls_archive > "$tmp/tls.tar.gz" || die "A tls mappa mentése nem sikerült."
    gzip -t "$tmp/tls.tar.gz" 2>/dev/null || die "A tls mappa mentése sérült."
    say "    beállítások és kulcsok: config.tar.gz, tls.tar.gz"

    cat > "$tmp/info.txt" <<EOF
created=$(date '+%Y-%m-%d %H:%M:%S %z')
openhdid_version=$version
image=$image
database=$db_state
host=$(hostname)
EOF
    (cd "$tmp" && checksum -- *.gz info.txt > SHA256SUMS)
    chmod 600 "$tmp"/*
    mv "$tmp" "$target"
    say "    kész: backup/$name"
}

prune() {
    [[ $KEEP -gt 0 ]] || return 0
    local all count
    all=$(find "$BACKUPS" -mindepth 1 -maxdepth 1 -type d -exec basename {} \; | grep -E "$STAMP_PATTERN" | sort || true)
    count=$(printf '%s' "$all" | grep -c . || true)
    [[ $count -gt $KEEP ]] || return 0
    printf '%s\n' "$all" | sed -n "1,$((count - KEEP))p" | while read -r old; do
        rm -rf "${BACKUPS:?}/$old"
        say "    régi mentés törölve: backup/$old"
    done
}

list_backups() {
    [[ -d $BACKUPS ]] || { echo "Még nincs mentés ($BACKUPS)."; return 0; }
    local found=0 dir version db
    for dir in $(find "$BACKUPS" -mindepth 1 -maxdepth 1 -type d ! -name '.*' | sort -r); do
        version=$(sed -n 's/^openhdid_version=//p' "$dir/info.txt" 2>/dev/null)
        db=$(sed -n 's/^database=//p' "$dir/info.txt" 2>/dev/null)
        printf '  %-40s OpenHDID %-10s adatbázis: %-9s %s\n' "$(basename "$dir")" "${version:-?}" "${db:-?}" "$(du -sh "$dir" | cut -f1)"
        found=1
    done
    [[ $found == 1 ]] || echo "Még nincs mentés ($BACKUPS)."
}

restore_backup() {
    local source image version db_state answer
    if [[ -z $NAME ]]; then
        NAME=$(find "$BACKUPS" -mindepth 1 -maxdepth 1 -type d -exec basename {} \; 2>/dev/null | grep -E "$STAMP_PATTERN" | sort | tail -n 1 || true)
        [[ -n $NAME ]] || die "Nincs mentés a $BACKUPS mappában."
    fi
    NAME=${NAME%/}
    NAME=${NAME#backup/}
    source="$BACKUPS/$NAME"
    [[ -d $source ]] || die "Nincs ilyen mentés: backup/$NAME (lista: ./backup.sh list)"
    for f in config.tar.gz tls.tar.gz storage.tar.gz info.txt SHA256SUMS; do
        [[ -f $source/$f ]] || die "Hiányos mentés, nincs benne: $f"
    done
    (cd "$source" && checksum -c SHA256SUMS >/dev/null 2>&1) || die "A mentés ellenőrzőösszege nem egyezik: a fájlok sérültek vagy módosultak."

    image=$(sed -n 's/^image=//p' "$source/info.txt")
    version=$(sed -n 's/^openhdid_version=//p' "$source/info.txt")
    db_state=$(sed -n 's/^database=//p' "$source/info.txt")
    [[ -n $image ]] || die "A mentés info.txt fájljából hiányzik az image."

    step "Visszaállítás: backup/$NAME"
    say "    készült: $(sed -n 's/^created=//p' "$source/info.txt"), OpenHDID $version"
    say "    a jelenlegi adatbázis, storage kötet és beállítások helyére ez a mentés kerül;"
    say "    a mentés óta keletkezett adatok elvesznek."
    if [[ $YES != 1 ]]; then
        [[ -t 0 ]] || die "Megerősítés kellene, de nincs terminál; nem interaktív futtatáshoz: --yes"
        read -r -p "    Folytatja? Írja be: igen > " answer || answer=""
        [[ $answer == igen ]] || die "Megszakítva, semmi nem változott."
    fi

    if [[ $SAFETY == 1 && -f .env ]]; then
        if ! (QUIET=1 create_backup "_visszaallitas-elott"); then
            die "A visszaállítás előtti biztonsági mentés nem sikerült, semmi nem változott. Ha a jelenlegi állapot úgyis menthetetlen: ./backup.sh restore $NAME --no-safety-backup"
        fi
        say "    biztonsági mentés a jelenlegi állapotról: backup/ (…_visszaallitas-elott)"
    fi

    step "Alkalmazás leállítása"
    if [[ -f .env ]]; then
        docker compose stop "${APP_SERVICES[@]}" >/dev/null 2>&1 || true
    fi

    step "Beállítások és kulcsok"
    local listing
    listing=$(tar tzf "$source/config.tar.gz") || die "A beállítások mentése nem olvasható."
    if grep -qE '^(\./)?ca(/|$)' <<<"$listing"; then
        rm -rf ca
    fi
    # The CA files must stay readable for the container user: the archived modes count, not this script's umask.
    (umask 022 && tar xzf "$source/config.tar.gz") || die "A beállítások visszaállítása nem sikerült (írható a mappa: $DIR?)."
    chmod 600 .env db.env
    say "    .env, db.env, compose.yaml, ca visszaállítva (OpenHDID $version)"

    # The container user writes the key back: the directory is opened for it for the
    # moment of the restore only (the operator owns it, so no root is needed).
    mkdir -p tls
    [[ -O tls ]] || die "A tls mappa nem az Ön tulajdona ($(ls -ld tls | awk '{print $3}')); állítsa át a tulajdonost, majd indítsa újra."
    chmod 777 tls
    if ! run_web -v "$DIR/tls":/restore-tls --entrypoint sh web \
        -c 'find /restore-tls -mindepth 1 -delete && tar xzf - -C /restore-tls' < "$source/tls.tar.gz"; then
        chmod 755 tls
        die "A tls mappa visszaállítása nem sikerült."
    fi
    chmod 755 tls
    say "    tls mappa visszaállítva (a tulajdonos a konténer felhasználója)"

    if [[ $db_state == included ]]; then
        bundled_db || die "A mentés a beépített adatbázist tartalmazza, de a visszaállított .env-ben nincs db profil."
        step "Adatbázis"
        docker compose up -d --wait --wait-timeout 300 mariadb >/dev/null 2>&1 || die "A mariadb nem indult el (docker compose logs mariadb)."
        docker compose exec -T mariadb sh -c "$DB_SHELL"'
            { echo "SET FOREIGN_KEY_CHECKS=0;"
              mariadb -u"$u" -N -B "$db" -e "SHOW TABLES" | sed "s/.*/DROP TABLE IF EXISTS \`&\`;/"
            } | mariadb -u"$u" "$db"' </dev/null || die "Az adatbázis kiürítése nem sikerült."
        gzip -dc "$source/database.sql.gz" | docker compose exec -T mariadb sh -c "$DB_SHELL"'exec mariadb -u"$u" "$db"' \
            || die "Az adatbázis visszatöltése nem sikerült."
        say "    adatbázis visszatöltve"
    else
        say "    külső adatbázis: állítsa vissza a mentéssel egyidejű állapotra az adatbázis üzemeltetőjével"
    fi

    step "Storage kötet"
    run_web --entrypoint sh web -c 'find /app/storage -mindepth 1 -delete && tar xzf - -C /app/storage' \
        < "$source/storage.tar.gz" || die "A storage kötet visszaállítása nem sikerült."
    say "    storage kötet visszaállítva"

    step "Indítás"
    docker compose up -d --wait --wait-timeout 300 >/dev/null 2>&1 \
        || die "A szolgáltatások nem indultak el egészségesen; az ok: docker compose ps, docker compose logs"
    say "    a szolgáltatások futnak"
    if [[ $QUIET == 1 ]]; then
        docker compose exec -T web php artisan hdid:health </dev/null >/dev/null 2>&1 || true
    elif ! docker compose exec -T web php artisan hdid:health </dev/null; then
        say "    a rendszerállapot figyelmeztet; részletek a Rendszerállapot oldalon"
    fi
    say ""
    say "Kész. Ellenőrizze a belépést és egy ügyfél adatlapját (a válaszok száma és egy kérdés-válasz próba"
    say "bizonyítja, hogy a titkosított adatok az APP_KEY-jel olvashatók)."
}

case $COMMAND in
    list) list_backups ;;
    backup) lock; create_backup; prune ;;
    restore) lock; restore_backup ;;
esac
