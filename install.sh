#!/usr/bin/env bash
#
# OpenHDID – telepítő, ellenőrző és javító egy paranccsal / installer, checker and fixer.
#
# A telepítés mappájába kerül (a kiadás melléklete), és mindig a saját mappájából dolgozik.
# Lépésenként kimondja, mit csinál és miért; ami a gépen kívüli teendő (terheléselosztó, NFS, DNS,
# tűzfal, tanúsítvány), azt pontos parancsokkal írja ki, de nem hajtja végre.
#
# Használat / usage:
#   ./install.sh [install] [--profile trial|5k|10k-single|10k-ha|20k] [--role a|b|infra]
#                [--answers FÁJL] [--from MAPPA] [--version X.Y.Z] [--lang hu|en] [--yes]
#   ./install.sh check  [--json] [--lang hu|en]       teljes ellenőrzés (a gép, a Docker, a .env …)
#   ./install.sh fix    [--only ID[,ID…]] [--from MAPPA] [--lang hu|en] [--yes]   vezetett javítás
#   ./install.sh report [--lang hu|en]                diagnosztikai csomag a mappában (nem tölt fel)
#   ./install.sh --help | --version
#
# Profilok: trial = kipróbálás (profil nélkül, önaláírt tanúsítvány, demóadat), 5k = egy gép,
# 10k-single = 10k egy nagyobb gépen, 10k-ha = két alkalmazásgép (A, B) és egy infra gép
# (MariaDB, Valkey), 20k = Kubernetes (a telepítő csak a fájlokat és a kubectl lépéseket adja).
#
# Kapcsolók:
#   --answers FÁJL   válaszfájl (csak a tulajdonos olvashatja: chmod 600); a kétgépes 10k-ban az
#                    infra vagy az A gép írja (openhdid-10k-answers.txt), használat után törlendő
#   --from MAPPA     letöltés helyett helyi kiadási fájlok (a kiadás mellékletei vagy a repó fája)
#   --version        a kiadás verziója (alapból ennek a szkriptnek a kiadása)
#   --yes            minden megerősítés igen; csak válaszfájllal, próbához és füstteszthez,
#                    élesre nem ajánlott
#
# A telepítő soha nem telepít csomagot, nem nyúl a tűzfalhoz, a rendszer és a Docker-démon
# beállításaihoz (ezekre csak parancsot javasol), nem kér root jogot (a docker csoport tagsága elég,
# rootless Dockerrel is működik), meglévő .env-et és db.env-et csak megerősítés és mentés után ír
# felül (.env.bak-<időbélyeg>), meglévő adat mellett az APP_KEY-t soha nem cseréli, adatot nem töröl,
# és semmit nem tölt fel sehová. Titok nem kerül a kimenetre, a naplóba és parancssori argumentumba.
#
# Saját fájljai: .install/ (állapot, napló titok nélkül, letöltések), report/, k8s/ (20k).

# The message catalogue (M_*), the answers (A_*), the generated (G_*) and chosen (S_*) secrets are
# read through indirection (msg, printf -v), and the validators are called by name: shellcheck
# cannot see where they are used, assigned or reached.
# shellcheck disable=SC2034,SC2153,SC2317
set -euo pipefail
umask 077

# The release this script belongs to; the release process writes it (like OPENHDID_VERSION in env.example).
OPENHDID_RELEASE=1.15.0

DIR=$(cd -- "$(dirname -- "$0")" && pwd -P)
SELF="$DIR/$(basename -- "$0")"
cd "$DIR"
# The installation's own .env decides the project, its files and profiles: variables exported
# in the operator's shell would point every command below at another project or drop a profile.
unset COMPOSE_FILE COMPOSE_PROJECT_NAME COMPOSE_PROFILES COMPOSE_ENV_FILES

STATE_DIR="$DIR/.install"
RELEASE_BASE=https://github.com/adamfhun/openhdid/releases/download
REPO_URL=https://github.com/adamfhun/openhdid.git
APP_IMAGE=ghcr.io/adamfhun/openhdid
SHARED_ANSWERS=openhdid-10k-answers.txt
STAMP=$(date +%Y%m%d-%H%M%S)
SECRET_KEYS=" APP_KEY DB_PASSWORD DB_MIGRATE_PASSWORD MARIADB_ROOT_PASSWORD REDIS_PASSWORD REDIS_SESSION_PASSWORD "

# ===== common =====================================================================================

COMMAND=install
UI_LANG=""
PROFILE_OPT=""
ROLE_OPT=""
ANSWERS_FILE=""
FROM_DIR=""
VERSION_OPT=""
YES=0
JSON=0
ONLY=""
QUIET=0
LOG_FILE=""
WARNINGS=0
ANSWERED=" "
STEP_TOTAL=0

PROFILE=""
ROLE=""
KIND=""
VERSION=""
PROJECT=""
PARTIAL=0
SUMS_VERIFIED=1
CHECK_IMPLEMENTED=1

# --- output -----------------------------------------------------------------------------------

# A message of the catalogue in the chosen language, with printf arguments.
msg() {
    local name="M_${1}_${UI_LANG:-en}"
    shift
    # shellcheck disable=SC2059 # the catalogue holds the formats
    printf -- "${!name:-$name}" "$@"
}

log_line() {
    [[ -z $LOG_FILE ]] || printf '%s\n' "$*" >> "$LOG_FILE" 2>/dev/null || true
}
say() {
    [[ $QUIET == 1 ]] || printf '%s\n' "$*"
    log_line "$*"
}
step() {
    local n=$1
    shift
    if [[ -t 1 && $QUIET != 1 ]]; then
        printf '\n\033[1m==> [%s/%s] %s\033[0m\n' "$n" "$STEP_TOTAL" "$*"
    else
        [[ $QUIET == 1 ]] || printf '\n==> [%s/%s] %s\n' "$n" "$STEP_TOTAL" "$*"
    fi
    log_line "==> [$n/$STEP_TOTAL] $*"
}
why() { say "    $(msg g_why "$*")"; }
done_() { say "    $(msg g_done "$*")"; }
info() { say "    $*"; }
warn() {
    WARNINGS=$((WARNINGS + 1))
    say "    $(msg g_warning "$*")"
}
die() {
    if [[ -t 2 ]]; then
        printf '\033[31m%s\033[0m\n' "$(msg g_error "$*")" >&2
    else
        printf '%s\n' "$(msg g_error "$*")" >&2
    fi
    log_line "$(msg g_error "$*")"
    exit 2
}
aborted() { die "$(msg g_aborted)"; }

# --- input ------------------------------------------------------------------------------------

# Reads one line of the operator's input (a terminal or a pipe); fails at the end of the input.
read_answer() {
    local __line
    IFS= read -r __line || return 1
    printf -v "$1" '%s' "$__line"
}

# Asks for a yes; anything else stops the run (as backup.sh). --yes answers it.
confirm() {
    local answer=""
    if [[ $YES == 1 ]]; then
        say "    $(msg g_confirm_prompt)$(msg g_yes_auto)"
        return 0
    fi
    printf '    %s' "$(msg g_confirm_prompt)"
    read_answer answer || answer=""
    [[ -t 0 ]] || printf '\n'
    [[ $answer == igen || $answer == yes ]] || aborted
}

# Asks for a yes, but a no returns 1 instead of stopping (the fix goes on with the next finding).
confirm_soft() {
    local answer=""
    if [[ $YES == 1 ]]; then
        say "    $(msg g_confirm_prompt)$(msg g_yes_auto)"
        return 0
    fi
    printf '    %s' "$(msg g_confirm_prompt)"
    read_answer answer || aborted
    [[ -t 0 ]] || printf '\n'
    [[ $answer == igen || $answer == yes ]]
}

# A yes/no question with a default (igen/nem, yes/no); --yes takes the default given as $3.
ask_yes_no() {
    local question=$1 default=$2 yes_default=${3:-$2} answer=""
    if [[ $YES == 1 ]]; then
        [[ $yes_default == y ]]
        return
    fi
    local label
    if [[ $default == y ]]; then label=$(msg g_yes_word); else label=$(msg g_no_word); fi
    printf '    %s [%s] ' "$question" "$label"
    read_answer answer || answer=""
    [[ -t 0 ]] || printf '\n'
    case $answer in
        '') [[ $default == y ]] ;;
        i | igen | y | yes) return 0 ;;
        *) return 1 ;;
    esac
}

answered() { [[ $ANSWERED == *" $1 "* ]]; }

# The answer to one question: the answers file's value, else the operator's (or with --yes the
# default). The value lands in A_<KEY>; the validator decides, an empty default means "required"
# unless the validator accepts an empty value.
ask() {
    local key=$1 question=$2 default=$3 validator=$4 var="A_$1" answer tries=0
    if answered "$key"; then
        "$validator" "${!var-}" || die "$(msg g_bad_answer "$key")"
        return 0
    fi
    if [[ $YES == 1 ]]; then
        "$validator" "$default" || die "$(msg g_missing_answer "$key")"
        printf -v "$var" '%s' "$default"
        ANSWERED="$ANSWERED$key "
        return 0
    fi
    while :; do
        if [[ -n $default ]]; then
            printf '    %s [%s]: ' "$question" "$default"
        else
            printf '    %s: ' "$question"
        fi
        read_answer answer || aborted
        [[ -t 0 ]] || printf '\n'
        [[ -n $answer ]] || answer=$default
        if "$validator" "$answer"; then
            printf -v "$var" '%s' "$answer"
            ANSWERED="$ANSWERED$key "
            return 0
        fi
        say "    $(msg g_invalid_value)"
        tries=$((tries + 1))
        [[ $tries -lt 5 ]] || die "$(msg g_bad_answer "$key")"
    done
}

# A secret answer: never echoed, never logged; --yes needs it in the answers file.
ask_secret() {
    local key=$1 question=$2 validator=$3 var="A_$1" answer tries=0
    if answered "$key"; then
        "$validator" "${!var-}" || die "$(msg g_bad_answer "$key")"
        return 0
    fi
    [[ $YES != 1 ]] || die "$(msg g_missing_answer "$key")"
    while :; do
        printf '    %s: ' "$question"
        if [[ -t 0 ]]; then
            IFS= read -rs answer || aborted
        else
            IFS= read -r answer || aborted
        fi
        printf '\n'
        if "$validator" "$answer"; then
            printf -v "$var" '%s' "$answer"
            ANSWERED="$ANSWERED$key "
            return 0
        fi
        say "    $(msg g_invalid_secret)"
        tries=$((tries + 1))
        [[ $tries -lt 5 ]] || die "$(msg g_bad_answer "$key")"
    done
}

# --- validators (an answers file value or an operator's answer) -----------------------------------

v_any() { return 0; }
v_format() { [[ $1 == 1 ]]; }
v_semver() { [[ $1 =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]]; }
v_profile() { [[ $1 == trial || $1 == 5k || $1 == 10k-single || $1 == 10k-ha || $1 == 20k ]]; }
v_role() { [[ $1 == a || $1 == b || $1 == infra ]]; }
v_project() { [[ $1 =~ ^[a-z0-9][a-z0-9_-]{0,62}$ ]]; }
v_appenv() { [[ $1 == production || $1 == staging || $1 == demo ]]; }
v_url() { [[ $1 =~ ^https://[a-z0-9.-]+(:[0-9]{1,5})?/?$ ]]; }
v_fqdn_url() { v_url "$1" && [[ ${1#https://} == *.* ]]; }
v_url_opt() { [[ -z $1 ]] || v_url "$1"; }
v_tls() { [[ $1 == on || $1 == off || $1 == selfsigned ]]; }
v_file() { [[ $1 == /* && -f $1 && -r $1 ]]; }
v_file_opt() { [[ -z $1 ]] || v_file "$1"; }
v_name() { [[ $1 =~ ^[A-Za-z0-9._-]{1,253}$ ]]; }
v_name_opt() { [[ -z $1 ]] || v_name "$1"; }
v_host() { v_name "$1"; }
v_dbname() { [[ $1 =~ ^[A-Za-z0-9_]{1,64}$ ]]; }
v_port() { [[ $1 =~ ^[0-9]{1,5}$ ]] && ((10#$1 >= 1 && 10#$1 <= 65535)); }
v_bool() { [[ $1 == true || $1 == false ]]; }
v_appkey() { [[ $1 =~ ^base64:[A-Za-z0-9+/]{43}=$ ]]; }
v_appkey_opt() { [[ -z $1 ]] || v_appkey "$1"; }
v_password() { [[ ${#1} -ge 12 && ${#1} -le 256 && $1 != *"'"* && $1 != *[[:cntrl:]]* ]]; }
v_password_opt() { [[ -z $1 ]] || v_password "$1"; }
v_prefix() { [[ $1 =~ ^[a-z0-9-]{1,40}-$ ]]; }
v_abspath() { [[ $1 == /* && $1 =~ ^[A-Za-z0-9._/-]+$ ]]; }
v_label() { [[ $1 =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$ ]]; }
v_label_opt() { [[ -z $1 ]] || v_label "$1"; }
v_email_opt() { [[ -z $1 || $1 =~ ^[^[:space:]@\']+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]]; }
v_text100() { [[ ${#1} -le 100 && $1 != *"'"* && $1 != *[[:cntrl:]]* ]]; }
v_dir_opt() { [[ -z $1 || -d $1 ]]; }
v_hosts() {
    local item
    for item in ${1//,/ }; do v_name "$item" || return 1; done
}
# Addresses or networks, IPv4 or IPv6, separated by commas or spaces (the container decides the rest).
v_cidr() {
    local ip=${1%%/*} prefix=""
    [[ $1 == */* ]] && prefix=${1#*/}
    [[ -z $prefix || $prefix =~ ^[0-9]{1,3}$ ]] || return 1
    if [[ $ip =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]]; then
        local part
        for part in "${BASH_REMATCH[@]:1}"; do ((10#$part <= 255)) || return 1; done
        [[ -z $prefix ]] || ((10#$prefix <= 32))
    else
        [[ $ip == *:* && $ip =~ ^[0-9A-Fa-f:.]+$ ]] || return 1
        [[ -z $prefix ]] || ((10#$prefix <= 128))
    fi
}
v_cidrs() {
    local item
    for item in ${1//,/ }; do v_cidr "$item" || return 1; done
}
v_cidrs_req() { [[ -n ${1//[ ,]/} ]] && v_cidrs "$1"; }
# The proxies the application trusts: a list, but never "everyone".
v_proxies() {
    local item
    v_cidrs_req "$1" || return 1
    for item in ${1//,/ }; do
        [[ $item != 0.0.0.0/0 && $item != ::/0 ]] || return 1
    done
}
# Rate-limit exemptions: at most /8 (IPv4) and /32 (IPv6) wide, as the container requires.
v_exempt() {
    local item prefix
    v_cidrs "$1" || return 1
    for item in ${1//,/ }; do
        [[ $item == */* ]] || continue
        prefix=${item#*/}
        if [[ $item == *:* ]]; then ((10#$prefix >= 32)) || return 1; else ((10#$prefix >= 8)) || return 1; fi
    done
}
v_choice_profile() { [[ $1 =~ ^[1-5]$ ]]; }

# The validator of every key an answers file may hold; anything else is refused.
answer_validator() {
    case $1 in
        ANSWERS_FORMAT) echo v_format ;;
        OPENHDID_VERSION) echo v_semver ;;
        PROFILE) echo v_profile ;;
        ROLE) echo v_role ;;
        PROJECT_NAME) echo v_project ;;
        APP_ENV) echo v_appenv ;;
        APP_URL) echo v_url_opt ;;
        TLS_MODE) echo v_tls ;;
        TLS_CERT | TLS_KEY) echo v_file ;;
        BIND_IP | INFRA_BIND_IP | INFRA_ADDRESS) echo v_name ;;
        HTTPS_PORT | HTTP_PORT | DB_PORT | REDIS_PORT | REDIS_SESSION_PORT | INFRA_DB_PORT | INFRA_VALKEY_PORT | INFRA_VALKEY_SESSIONS_PORT) echo v_port ;;
        EXTRA_HOSTS) echo v_hosts ;;
        EXEMPT_NETWORKS) echo v_exempt ;;
        LB_ADDRESSES | APP_HOSTS) echo v_cidrs ;;
        K8S_POD_CIDR) echo v_proxies ;;
        DEMO_DATA) echo v_bool ;;
        APP_KEY) echo v_appkey ;;
        DB_HOST | DB_USERNAME | DB_MIGRATE_USERNAME | REDIS_HOST | REDIS_SESSION_HOST | NFS_SERVER) echo v_name ;;
        DB_DATABASE) echo v_dbname ;;
        DB_PASSWORD | DB_MIGRATE_PASSWORD | MARIADB_ROOT_PASSWORD | REDIS_PASSWORD | REDIS_SESSION_PASSWORD) echo v_password ;;
        REDIS_PREFIX) echo v_prefix ;;
        NFS_PATH) echo v_abspath ;;
        K8S_NAMESPACE | K8S_INGRESS_NAMESPACE | K8S_STORAGE_CLASS) echo v_label ;;
        ADMIN_EMAIL) echo v_email_opt ;;
        ADMIN_NAME) echo v_text100 ;;
        *) return 1 ;;
    esac
}

# --- files ------------------------------------------------------------------------------------

file_mode() { stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"; }

checksum() {
    if command -v sha256sum >/dev/null; then sha256sum "$@"; else shasum -a 256 "$@"; fi
}
sha_of() {
    local out
    out=$(checksum "$1") || return 1
    printf '%s' "${out%% *}"
}

# The value of KEY in an env file, read line by line (no source, no eval): the last assignment
# wins, one pair of outer quotes is removed, nothing is expanded.
env_get() {
    local file=$1 key=$2 line value="" found=1
    [[ -f $file ]] || return 1
    while IFS= read -r line || [[ -n $line ]]; do
        [[ $line == "$key="* ]] || continue
        value=${line#*=}
        found=0
    done < "$file"
    [[ $found == 0 ]] || return 1
    if [[ ${#value} -ge 2 && ( ( $value == \"*\" ) || ( $value == \'*\' ) ) ]]; then
        value=${value:1:${#value}-2}
    elif [[ $value == *' #'* ]]; then
        value=${value%% #*}
    fi
    printf '%s' "$value"
}
env_has() {
    local line
    [[ -f $1 ]] || return 1
    while IFS= read -r line || [[ -n $line ]]; do
        [[ $line == "$2="* ]] && return 0
    done < "$1"
    return 1
}

# One KEY=value line: plain when the value needs no quoting, else between apostrophes, which
# Compose and phpdotenv both take literally ($ and # stay text). Values never hold an apostrophe.
ENV_PLAIN='^[A-Za-z0-9._~+/=:@%,-]*$'
env_line() {
    if [[ $2 =~ $ENV_PLAIN ]]; then
        printf '%s=%s\n' "$1" "$2"
    else
        printf "%s='%s'\n" "$1" "$2"
    fi
}

# The keys env_render writes (parallel arrays), the keys it comments out, the note of its block.
SET_KEYS=()
SET_VALS=()
UNSET_KEYS=" "
RENDER_NOTE=""
UNSET_NOTE=""
env_set() {
    local i=0
    while [[ $i -lt ${#SET_KEYS[@]} ]]; do
        if [[ ${SET_KEYS[$i]} == "$1" ]]; then
            SET_VALS[i]=$2
            return 0
        fi
        i=$((i + 1))
    done
    SET_KEYS+=("$1")
    SET_VALS+=("$2")
}
env_reset() {
    SET_KEYS=()
    SET_VALS=()
    UNSET_KEYS=" "
}
set_index() {
    IDX=-1
    local i=0
    while [[ $i -lt ${#SET_KEYS[@]} ]]; do
        if [[ ${SET_KEYS[$i]} == "$1" ]]; then
            IDX=$i
            return 0
        fi
        i=$((i + 1))
    done
    return 1
}

# Writes DST from SRC line by line: a KEY= line gets the new value of a set key, a commented
# sample ("# KEY=…") is opened only when that key is set and has no line of its own, a key of
# UNSET_KEYS is commented out; every other line (comments, empty lines) stays as it was. Keys SRC
# does not know go into a block at the end. Atomic: a temporary file (600), then mv.
ENV_KEY_RE='^([A-Za-z_][A-Za-z0-9_]*)='
ENV_SAMPLE_RE='^# ?([A-Z][A-Z0-9_]*)='
env_render() {
    local src=$1 dst=$2 line key present=" " written=" " tmp i
    tmp="$dst.tmp.$$"
    while IFS= read -r line || [[ -n $line ]]; do
        [[ $line =~ $ENV_KEY_RE ]] && present="$present${BASH_REMATCH[1]} "
    done < "$src"
    {
        while IFS= read -r line || [[ -n $line ]]; do
            if [[ $line =~ $ENV_KEY_RE ]]; then
                key=${BASH_REMATCH[1]}
                if [[ $UNSET_KEYS == *" $key "* ]]; then
                    printf '# %s\n' "${UNSET_NOTE:-$(msg g_env_unset_note)}"
                    printf '# %s\n' "$line"
                    continue
                fi
                if [[ $written != *" $key "* ]] && set_index "$key"; then
                    env_line "$key" "${SET_VALS[$IDX]}"
                    written="$written$key "
                    continue
                fi
            elif [[ $line =~ $ENV_SAMPLE_RE ]]; then
                key=${BASH_REMATCH[1]}
                if [[ $present != *" $key "* && $written != *" $key "* ]] && set_index "$key"; then
                    env_line "$key" "${SET_VALS[$IDX]}"
                    written="$written$key "
                    continue
                fi
            fi
            printf '%s\n' "$line"
        done < "$src"
        local header=0
        i=0
        while [[ $i -lt ${#SET_KEYS[@]} ]]; do
            key=${SET_KEYS[$i]}
            if [[ $written != *" $key "* ]]; then
                if [[ $header == 0 ]]; then
                    printf '\n# --- %s ---\n' "${RENDER_NOTE:-$(msg g_env_block "$OPENHDID_RELEASE" "$(date +%Y-%m-%d)")}"
                    header=1
                fi
                env_line "$key" "${SET_VALS[$i]}"
            fi
            i=$((i + 1))
        done
    } > "$tmp" || {
        rm -f "$tmp"
        die "$(msg g_write_failed "$dst")"
    }
    chmod 600 "$tmp"
    mv -f "$tmp" "$dst" || die "$(msg g_write_failed "$dst")"
}

# A copy of an env file before it changes: <file>.bak-<stamp>, 600.
env_backup() {
    local file=$1 target
    [[ -f $file ]] || return 0
    target="$file.bak-$STAMP"
    [[ -e $target ]] && return 0
    cp -p "$file" "$target" || die "$(msg g_write_failed "$target")"
    chmod 600 "$target"
    say "    $(msg g_backed_up "$(basename "$file")" "$(basename "$target")")"
}

# --- docker -----------------------------------------------------------------------------------

# Every docker call reads /dev/null: a container that reads its input would otherwise swallow the
# operator's piped answers (and the release script's confirmations).
dk() { docker "$@" </dev/null; }
dc() { docker compose "$@" </dev/null; }

# A one-off container of the web service; Compose's "Creating …" lines on stderr are shown only
# when the run fails. The caller gives its input (/dev/null, or a tar stream on purpose).
run_web() {
    local err status=0
    err=$(mktemp "$STATE_DIR/.tmp-err.XXXXXX" 2>/dev/null || mktemp)
    docker compose run --rm --no-deps -T "$@" 2>"$err" || status=$?
    if [[ $status != 0 ]]; then
        grep -vE '^ ?Container .* (Creating|Created)' "$err" >&2 || true
    fi
    rm -f "$err"
    return "$status"
}

# Every known secret value replaced by *** (for container output and logs shown to the operator).
redact_secrets() {
    local text=$1 key var value file
    for key in $SECRET_KEYS; do
        for var in "A_$key" "G_$key"; do
            value=${!var-}
            [[ ${#value} -ge 6 ]] && text=${text//"$value"/***}
        done
    done
    for file in .env db.env; do
        [[ -f $file ]] || continue
        for key in APP_KEY DB_PASSWORD REDIS_PASSWORD REDIS_SESSION_PASSWORD MARIADB_ROOT_PASSWORD; do
            value=$(env_get "$file" "$key" || true)
            [[ ${#value} -ge 6 ]] && text=${text//"$value"/***}
        done
    done
    printf '%s' "$text"
}

# Major.minor[.patch] comparison: $1 >= $2.
ver_ge() {
    local a=${1#v} b=${2#v} x y i
    a=${a%%[-+ ]*}
    b=${b%%[-+ ]*}
    for i in 1 2 3; do
        x=$(cut -d. -f"$i" <<<"$a.0.0")
        y=$(cut -d. -f"$i" <<<"$b.0.0")
        x=${x//[^0-9]/}
        y=${y//[^0-9]/}
        ((10#${x:-0} > 10#${y:-0})) && return 0
        ((10#${x:-0} < 10#${y:-0})) && return 1
    done
    return 0
}

json_str() {
    local s=$1
    s=${s//\\/\\\\}
    s=${s//\"/\\\"}
    s=${s//$'\n'/\\n}
    s=${s//$'\r'/\\r}
    s=${s//$'\t'/\\t}
    printf '"%s"' "$s"
}

# --- host facts (an unavailable tool gives an empty answer, never an error) ----------------------

host_cpus() {
    local n=""
    n=$(nproc 2>/dev/null) || n=$(getconf _NPROCESSORS_ONLN 2>/dev/null) || n=$(sysctl -n hw.ncpu 2>/dev/null) || n=""
    [[ $n =~ ^[0-9]+$ ]] && printf '%s' "$n"
    return 0
}
host_mem_mb() {
    local out line bytes=""
    if out=$(free -b 2>/dev/null); then
        while read -r line; do
            # shellcheck disable=SC2086 # the fields of one line of free
            [[ $line == Mem:* ]] && { set -- $line; bytes=$2; }
        done <<<"$out"
    elif out=$(sysctl -n hw.memsize 2>/dev/null); then
        bytes=$out
    elif [[ -r /proc/meminfo ]]; then
        while read -r line; do
            # shellcheck disable=SC2086
            [[ $line == MemTotal:* ]] && { set -- $line; bytes=$(($2 * 1024)); }
        done < /proc/meminfo
    fi
    [[ $bytes =~ ^[0-9]+$ ]] && printf '%s' $((bytes / 1048576))
    return 0
}
disk_free_mb() {
    local out line="" n=0
    out=$(df -Pk "$1" 2>/dev/null) || return 0
    while IFS= read -r line; do
        n=$((n + 1))
        if [[ $n == 2 ]]; then
            # shellcheck disable=SC2086 # the fields of one line of df
            set -- $line
            [[ ${4:-} =~ ^[0-9]+$ ]] && printf '%s' $(($4 / 1024))
        fi
    done <<<"$out"
    return 0
}
# Whether something listens on a TCP port: 0 yes, 1 no, 2 unknown.
port_in_use() {
    local port=$1 out line field
    if ! out=$(ss -ltnH 2>/dev/null); then
        out=$(netstat -ltn 2>/dev/null) || out=$(netstat -an -p tcp 2>/dev/null) || return 2
    fi
    while IFS= read -r line; do
        [[ $line == *LISTEN* ]] || continue
        for field in $line; do
            [[ $field == *:"$port" || $field == *."$port" ]] && return 0
        done
    done <<<"$out"
    return 1
}
time_synced() {
    local out
    out=$(timedatectl show -p NTPSynchronized --value 2>/dev/null) || return 0
    printf '%s' "$out"
}
resolve_host() {
    local out
    out=$(getent hosts "$1" 2>/dev/null) || out=$(host "$1" 2>/dev/null) || return 1
    [[ -n $out ]] && printf '%s' "${out%%[[:space:]]*}"
}
docker_rootless() {
    local out
    out=$(dk info --format '{{.SecurityOptions}}' 2>/dev/null) || return 1
    [[ $out == *name=rootless* ]]
}
docker_server_version() { dk version --format '{{.Server.Version}}' 2>/dev/null || true; }
compose_version() {
    local v
    v=$(dc version --short 2>/dev/null || true)
    printf '%s' "${v#v}"
}
first_private_ipv4() {
    local out item
    out=$(hostname -I 2>/dev/null) || return 0
    for item in $out; do
        if [[ $item =~ ^10\. || $item =~ ^192\.168\. || $item =~ ^172\.(1[6-9]|2[0-9]|3[01])\. ]]; then
            printf '%s' "$item"
            return 0
        fi
    done
    return 0
}

# --- findings ---------------------------------------------------------------------------------
# finding ID SEVERITY AREA TITLE DETAIL ADVICE DOC FIX [COMMAND…]: severity error|warning|info.

F_ID=()
F_SEV=()
F_AREA=()
F_TITLE=()
F_DETAIL=()
F_ADVICE=()
F_DOC=()
F_FIX=()
F_CMDS=()
F_OK=0
finding() {
    F_ID+=("$1")
    F_SEV+=("$2")
    F_AREA+=("$3")
    F_TITLE+=("$4")
    F_DETAIL+=("$5")
    F_ADVICE+=("$6")
    F_DOC+=("$7")
    F_FIX+=("$8")
    shift 8
    local IFS=$'\x1f'
    F_CMDS+=("$*")
}
check_ok() { F_OK=$((F_OK + ${1:-1})); }
findings_reset() {
    F_ID=() F_SEV=() F_AREA=() F_TITLE=() F_DETAIL=() F_ADVICE=() F_DOC=() F_FIX=() F_CMDS=()
    F_OK=0
}
findings_count() {
    local n=0 sev
    for sev in ${F_SEV[@]+"${F_SEV[@]}"}; do
        [[ $sev == "$1" ]] && n=$((n + 1))
    done
    printf '%s' "$n"
}
findings_exit_code() {
    if [[ $(findings_count error) -gt 0 ]]; then
        printf 2
    elif [[ $(findings_count warning) -gt 0 ]]; then
        printf 1
    else
        printf 0
    fi
}
# The findings for a person, grouped by area, from index $1 (default 0).
print_findings() {
    local from=${1:-0} areas=" " area i label cmds cmd
    i=$from
    while [[ $i -lt ${#F_ID[@]} ]]; do
        [[ $areas == *" ${F_AREA[$i]} "* ]] || areas="$areas${F_AREA[$i]} "
        i=$((i + 1))
    done
    for area in $areas; do
        say "  $(msg "g_area_$area")"
        i=$from
        while [[ $i -lt ${#F_ID[@]} ]]; do
            if [[ ${F_AREA[$i]} == "$area" ]]; then
                label=$(msg "g_sev_${F_SEV[$i]}")
                say "$(printf '    %-18s %-10s %s' "[$label]" "${F_ID[$i]}" "${F_TITLE[$i]}")"
                [[ -z ${F_DETAIL[$i]} ]] || say "        $(msg g_cause "${F_DETAIL[$i]}")"
                [[ -z ${F_ADVICE[$i]} ]] || say "        $(msg g_advice "${F_ADVICE[$i]}")"
                if [[ -n ${F_CMDS[$i]} ]]; then
                    IFS=$'\x1f' read -r -a cmds <<<"${F_CMDS[$i]}"
                    for cmd in ${cmds[@]+"${cmds[@]}"}; do
                        say "            $cmd"
                    done
                fi
                [[ -z ${F_FIX[$i]} ]] || say "            ./install.sh fix --only ${F_ID[$i]}"
                [[ -z ${F_DOC[$i]} ]] || say "        $(msg g_docref "${F_DOC[$i]}")"
            fi
            i=$((i + 1))
        done
    done
}
# The findings as JSON (schema openhdid-check/1); header values from J_* globals.
findings_json() {
    local i first=1 cmds cmd cfirst
    printf '{"schema":"openhdid-check/1","installer":%s,"openhdid_version":%s,"profile":%s,"role":%s,"lang":%s,"generated_at":%s,"exit_code":%s,' \
        "$(json_str "$OPENHDID_RELEASE")" "$(json_str "${J_VERSION:-}")" "$(json_str "${J_PROFILE:-}")" "$(json_str "${J_ROLE:-}")" \
        "$(json_str "$UI_LANG")" "$(json_str "$(date -u +%Y-%m-%dT%H:%M:%SZ)")" "$(findings_exit_code)"
    printf '"summary":{"error":%s,"warning":%s,"info":%s,"ok":%s},"findings":[' \
        "$(findings_count error)" "$(findings_count warning)" "$(findings_count info)" "$F_OK"
    i=0
    while [[ $i -lt ${#F_ID[@]} ]]; do
        [[ $first == 1 ]] || printf ','
        first=0
        printf '{"id":%s,"area":%s,"severity":%s,"title":%s,"detail":%s,"advice":%s,"commands":[' \
            "$(json_str "${F_ID[$i]}")" "$(json_str "${F_AREA[$i]}")" "$(json_str "${F_SEV[$i]}")" \
            "$(json_str "${F_TITLE[$i]}")" "$(json_str "${F_DETAIL[$i]}")" "$(json_str "${F_ADVICE[$i]}")"
        cfirst=1
        cmds=()
        [[ -z ${F_CMDS[$i]} ]] || IFS=$'\x1f' read -r -a cmds <<<"${F_CMDS[$i]}"
        [[ -z ${F_FIX[$i]} ]] || cmds+=("./install.sh fix --only ${F_ID[$i]}")
        for cmd in ${cmds[@]+"${cmds[@]}"}; do
            [[ $cfirst == 1 ]] || printf ','
            cfirst=0
            json_str "$cmd"
        done
        printf '],"doc":%s,"fix":%s}' "$(json_str "${F_DOC[$i]}")" "$(json_str "${F_FIX[$i]}")"
        i=$((i + 1))
    done
    printf ']}\n'
}

# --- host and Docker checks (HOST-*, DOCKER-*) ---------------------------------------------------

# The machine table row of a kind: CPUs, memory GB, free disk GB for a warning.
size_row() {
    case $1 in
        trial) REQ_CPU=2 REQ_MEM=8 REQ_DISK=10 ;;
        5k) REQ_CPU=4 REQ_MEM=10 REQ_DISK=40 ;;
        10k-single) REQ_CPU=8 REQ_MEM=16 REQ_DISK=60 ;;
        app) REQ_CPU=4 REQ_MEM=6 REQ_DISK=20 ;;
        infra) REQ_CPU=4 REQ_MEM=10 REQ_DISK=50 ;;
        *) REQ_CPU=0 REQ_MEM=0 REQ_DISK=0 ;;
    esac
}

# check_host KIND MODE: MODE refuse (install, fix: root is an error) or warn (check, report).
check_host() {
    local kind=$1 mode=${2:-refuse} cpus mem need dir free sync
    local guide
    guide=$(msg g_doc_guide)
    size_row "$kind"
    if [[ $(id -u 2>/dev/null || echo 1000) == 0 ]]; then
        finding HOST-05 "$( [[ $mode == refuse ]] && echo error || echo warning)" host "$(msg g_host05_t)" "" "$(msg g_host05_a)" "$guide, 3." "" "sudo chown -R <$(msg g_account)> $DIR"
    else
        check_ok
    fi
    [[ $REQ_CPU -gt 0 ]] || return 0
    cpus=$(host_cpus)
    if [[ -z $cpus ]]; then
        finding HOST-01 info host "$(msg g_host01_t)" "$(msg g_unknown_fact)" "" "$guide, 15." ""
    elif [[ $cpus -lt $REQ_CPU ]]; then
        finding HOST-01 warning host "$(msg g_host01_t)" "$(msg g_host01_d "$cpus" "$REQ_CPU")" "$(msg g_host01_a)" "$guide, 15." ""
    else
        check_ok
    fi
    mem=$(host_mem_mb)
    need=$((REQ_MEM * 1024 * 9 / 10))
    if [[ -z $mem ]]; then
        finding HOST-02 info host "$(msg g_host02_t)" "$(msg g_unknown_fact)" "" "$guide, 15." ""
    elif [[ $mem -lt $need ]]; then
        finding HOST-02 warning host "$(msg g_host02_t)" "$(msg g_host02_d "$mem" "$REQ_MEM")" "$(msg g_host02_a)" "$guide, 15." ""
    else
        check_ok
    fi
    for dir in "$DIR" "$(dk info --format '{{.DockerRootDir}}' 2>/dev/null || true)"; do
        [[ -n $dir ]] || continue
        free=$(disk_free_mb "$dir")
        if [[ -z $free ]]; then
            continue
        elif [[ $free -lt 2048 ]]; then
            finding HOST-03 error host "$(msg g_host03_t)" "$(msg g_host03_d "$dir" "$((free / 1024))" "$REQ_DISK")" "$(msg g_host03_a)" "$guide, 2." ""
        elif [[ $free -lt $((REQ_DISK * 1024)) ]]; then
            finding HOST-03 warning host "$(msg g_host03_t)" "$(msg g_host03_d "$dir" "$((free / 1024))" "$REQ_DISK")" "$(msg g_host03_a)" "$guide, 2." ""
        else
            check_ok
        fi
    done
    sync=$(time_synced)
    if [[ $sync == yes ]]; then
        check_ok
    elif [[ $sync == no ]]; then
        finding HOST-04 warning host "$(msg g_host04_t)" "$(msg g_host04_d)" "$(msg g_host04_a)" "$guide, 2." "" "sudo timedatectl set-ntp true"
    else
        finding HOST-04 info host "$(msg g_host04_t)" "$(msg g_unknown_fact)" "$(msg g_host04_a)" "$guide, 2." ""
    fi
}

# HOST-06 and HOST-07: the ports the installation will publish.
check_ports() {
    local port start=""
    local guide
    guide=$(msg g_doc_guide)
    for port in "$@"; do
        if port_in_use "$port"; then
            finding HOST-06 error host "$(msg g_host06_t "$port")" "$(msg g_host06_d "$port")" "$(msg g_host06_a)" "$guide, 6.1" "" "ss -ltnp 'sport = :$port'"
        else
            [[ $? == 2 ]] || check_ok
        fi
        if [[ ${ROOTLESS:-0} == 1 && $port -lt 1024 ]]; then
            [[ -z $start && -r /proc/sys/net/ipv4/ip_unprivileged_port_start ]] && start=$(cat /proc/sys/net/ipv4/ip_unprivileged_port_start)
            if [[ $start =~ ^[0-9]+$ && $port -lt $start ]]; then
                finding HOST-07 error host "$(msg g_host07_t "$port")" "$(msg g_host07_d "$port" "$start")" "$(msg g_host07_a)" "$guide, 3." "" \
                    "sudo sysctl -w net.ipv4.ip_unprivileged_port_start=0"
            fi
        fi
    done
}

# check_docker KIND: DOCKER-01..08. Sets ROOTLESS.
check_docker() {
    local kind=${1:-} out version guide user_proxy daemon_proxy images image missing=""
    guide=$(msg g_doc_guide)
    ROOTLESS=0
    if ! command -v docker >/dev/null 2>&1; then
        finding DOCKER-01 error docker "$(msg g_docker01_t)" "$(msg g_docker01_none)" "$(msg g_docker01_install)" "$guide, 2." ""
        return 0
    fi
    if ! out=$(dk info 2>&1); then
        finding DOCKER-01 error docker "$(msg g_docker01_t)" "$(redact_secrets "${out%%$'\n'*}")" "$(msg g_docker01_a)" "TROUBLESHOOTING.md 5." "" \
            "sudo usermod -aG docker \"\$(id -un)\"" "docker context ls"
        return 0
    fi
    check_ok
    out=$(dk version 2>/dev/null || true)
    if [[ $out == *Podman* || $out == *podman* ]]; then
        finding DOCKER-04 error docker "$(msg g_docker04_t)" "" "$(msg g_docker04_a)" "$guide, 2." "" "unset DOCKER_HOST" "docker context use default"
    else
        check_ok
    fi
    version=$(docker_server_version)
    if [[ -n $version ]] && ! ver_ge "$version" 27.0; then
        finding DOCKER-02 error docker "$(msg g_docker02_t)" "$(msg g_version_found "$version")" "$(msg g_docker02_a)" "$guide, 2." ""
    else
        check_ok
    fi
    version=$(compose_version)
    if [[ -z $version ]]; then
        finding DOCKER-03 error docker "$(msg g_docker03_t)" "$(msg g_docker03_none)" "$(msg g_docker03_a)" "$guide, 2." ""
    elif ! ver_ge "$version" 2.24; then
        finding DOCKER-03 error docker "$(msg g_docker03_t)" "$(msg g_version_found "$version")" "$(msg g_docker03_a)" "$guide, 2." ""
    else
        check_ok
    fi
    if docker_rootless; then
        ROOTLESS=1
        finding DOCKER-05 info docker "$(msg g_docker05_t)" "" "$(msg g_docker05_a)" "$guide, 3." ""
        if [[ $kind == app ]]; then
            finding DOCKER-08 error docker "$(msg g_docker08_t)" "" "$(msg g_docker08_a)" "$guide, 15.4" ""
        fi
    fi
    user_proxy=${https_proxy:-${HTTPS_PROXY:-}}
    if [[ -n $user_proxy ]]; then
        daemon_proxy=$(dk info --format '{{.HTTPSProxy}}{{.HTTPProxy}}' 2>/dev/null || true)
        if [[ -z $daemon_proxy ]]; then
            finding DOCKER-06 warning docker "$(msg g_docker06_t)" "$(msg g_docker06_d)" "$(msg g_docker06_a)" "TROUBLESHOOTING.md 4." ""
        else
            check_ok
        fi
    fi
    if [[ -f .env && ( -f compose.yaml || -f profiles/10k/infra.yaml ) ]]; then
        images=$(dc config --images 2>/dev/null || true)
        for image in $images; do
            dk image inspect "$image" >/dev/null 2>&1 || missing="$missing $image"
        done
        if [[ -n $missing ]]; then
            finding DOCKER-07 warning docker "$(msg g_docker07_t)" "${missing# }" "$(msg g_docker07_a)" "$guide, 3." "" "docker compose pull"
        elif [[ -n $images ]]; then
            check_ok
        fi
    fi
}

# --- release files ------------------------------------------------------------------------------

# Where a release asset of the --from folder is: the release layout (env.example, profile-5k.yaml,
# SHA256SUMS) or the repository tree (.env.example, profiles/5k/compose.yaml).
from_source() {
    local name=$1
    if [[ -f $FROM_DIR/$name ]]; then
        printf '%s' "$FROM_DIR/$name"
        return 0
    fi
    case $name in
        env.example) name=.env.example ;;
        profile-5k.yaml) name=profiles/5k/compose.yaml ;;
        profile-10k.yaml) name=profiles/10k/compose.yaml ;;
        profile-10k-single.yaml) name=profiles/10k/single.yaml ;;
        profile-10k-infra.yaml) name=profiles/10k/infra.yaml ;;
        *) return 1 ;;
    esac
    [[ -f $FROM_DIR/$name ]] || return 1
    printf '%s' "$FROM_DIR/$name"
}
# The place of a release asset in the installation folder.
asset_target() {
    case $1 in
        profile-5k.yaml) printf 'profiles/5k/compose.yaml' ;;
        profile-10k.yaml) printf 'profiles/10k/compose.yaml' ;;
        profile-10k-single.yaml) printf 'profiles/10k/single.yaml' ;;
        profile-10k-infra.yaml) printf 'profiles/10k/infra.yaml' ;;
        SHA256SUMS) printf '.install/SHA256SUMS-%s' "$VERSION" ;;
        *) printf '%s' "$1" ;;
    esac
}

fetch_one() {
    local name=$1 target=$2 url
    if [[ -n $FROM_DIR ]]; then
        local source
        source=$(from_source "$name") || return 1
        cp "$source" "$target"
        return
    fi
    url="$RELEASE_BASE/v$VERSION/$name"
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL --proto '=https' -o "$target" "$url" </dev/null
    elif command -v wget >/dev/null 2>&1; then
        wget -q -O "$target" "$url" </dev/null
    else
        die "$(msg g_no_downloader)"
    fi
}

# The expected checksum of a release asset (from the downloaded SHA256SUMS).
sum_of() {
    local sums=$1 name=$2 sum file
    while read -r sum file; do
        file=${file#\*}
        [[ $file == "$name" ]] && { printf '%s' "$sum"; return 0; }
    done < "$sums"
    return 1
}

# Downloads (or takes from --from) the release assets into .install/download-<stamp>, checks them
# against SHA256SUMS and only then puts them in place. Nothing changes when one does not match.
DOWNLOADED=()
download_release() {
    local dl="$STATE_DIR/download-$STAMP" name sums="" expected actual target existing
    mkdir -p "$dl"
    chmod 700 "$dl"
    if fetch_one SHA256SUMS "$dl/SHA256SUMS" 2>/dev/null; then
        sums="$dl/SHA256SUMS"
        SUMS_VERIFIED=1
    elif [[ -n $FROM_DIR ]]; then
        SUMS_VERIFIED=0
        warn "$(msg g_from_unverified)"
    else
        die "$(msg g_download_failed SHA256SUMS "$RELEASE_BASE/v$VERSION/SHA256SUMS")"
    fi
    for name in "$@"; do
        fetch_one "$name" "$dl/$name" || die "$(msg g_download_failed "$name" "${FROM_DIR:-$RELEASE_BASE/v$VERSION/$name}")"
        if [[ -n $sums ]]; then
            expected=$(sum_of "$sums" "$name") || die "$(msg g_sum_missing "$name")"
            actual=$(sha_of "$dl/$name")
            [[ $actual == "$expected" ]] || die "$(msg g_sum_mismatch "$name")"
        fi
        if [[ -n $sums ]]; then info "$(msg g_file_ok "$name")"; else info "$(msg g_file_taken "$name")"; fi
    done
    # The running script itself, when the release lists it.
    if [[ -n $sums ]] && expected=$(sum_of "$sums" install.sh); then
        if [[ $(sha_of "$SELF") != "$expected" ]]; then
            warn "$(msg g_self_differs "$VERSION")"
            confirm
        fi
    fi
    # Everything matched: into place.
    mkdir -p "$STATE_DIR"
    for name in "$@"; do
        target=$(asset_target "$name")
        existing=0
        [[ -f $target ]] && existing=1
        if [[ $existing == 1 ]] && ! cmp -s "$target" "$dl/$name"; then
            case $name in
                compose.yaml | profile-*.yaml)
                    warn "$(msg g_file_differs "$target")"
                    confirm
                    cp -p "$target" "$STATE_DIR/$(basename "$target")-elozo-$STAMP"
                    ;;
            esac
        fi
        mkdir -p "$(dirname "$target")"
        cp "$dl/$name" "$target.tmp.$$"
        mv -f "$target.tmp.$$" "$target"
        case $name in
            backup.sh) chmod 755 "$target" ;;
            *) chmod 644 "$target" ;;
        esac
        DOWNLOADED+=("$target")
        [[ $name != env.example ]] || cp "$dl/$name" "$STATE_DIR/env.example-$VERSION"
    done
    [[ -z $sums ]] || cp "$sums" "$STATE_DIR/SHA256SUMS-$VERSION"
    rm -rf "$dl"
}

# --- secrets ------------------------------------------------------------------------------------

# shellcheck disable=SC2016 # PHP code, run by the image's php
GEN_PHP='foreach (array_slice($argv, 1) as $n) { echo $n, "=", $n === "APP_KEY" ? "base64:".base64_encode(random_bytes(32)) : bin2hex(random_bytes(24)), PHP_EOL; }'

# New secrets into G_<NAME>: with the application image, one call without network, the names as
# the only arguments; without the image from the host's /dev/urandom. Values never reach a
# command line, the output or the log.
gen_secrets() {
    [[ $# -gt 0 ]] || return 0
    local out="" rest line name value use_image=${GEN_WITH_IMAGE:-1}
    if [[ $use_image == 1 ]] && out=$(dk run --rm --network none --cap-drop ALL --security-opt no-new-privileges --read-only \
        --entrypoint php "$APP_IMAGE:$VERSION" -r "$GEN_PHP" -- "$@" 2>/dev/null); then
        rest=$out
        while [[ -n $rest ]]; do
            line=${rest%%$'\n'*}
            if [[ $rest == *$'\n'* ]]; then rest=${rest#*$'\n'}; else rest=""; fi
            name=${line%%=*}
            value=${line#*=}
            [[ $SECRET_KEYS == *" $name "* ]] || continue
            if [[ $name == APP_KEY ]]; then v_appkey "$value" || continue; else [[ $value =~ ^[0-9a-f]{48}$ ]] || continue; fi
            printf -v "G_$name" '%s' "$value"
        done
    fi
    for name in "$@"; do
        value="G_$name"
        [[ -n ${!value-} ]] && continue
        if [[ $name == APP_KEY ]]; then
            value=$(head -c 32 /dev/urandom | base64 | tr -d '\n')
            value="base64:$value"
            v_appkey "$value" || die "$(msg g_secret_failed)"
        else
            value=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
            [[ $value =~ ^[0-9a-f]{48}$ ]] || die "$(msg g_secret_failed)"
        fi
        printf -v "G_$name" '%s' "$value"
    done
}

# The secret a key gets: the existing file's value (a resumed installation), the answers file's,
# else a generated one. Sets S_<NAME> and says where it came from (length only).
pick_secret() {
    local name=$1 existing=$2 a="A_$1" g="G_$1" value
    if [[ -n $existing ]]; then
        value=$existing
        info "$(msg g_secret_kept "$name" "${#value}")"
    elif [[ -n ${!a-} ]]; then
        value=${!a}
        info "$(msg g_secret_given "$name" "${#value}")"
    else
        value=${!g-}
        [[ -n $value ]] || die "$(msg g_secret_failed)"
        info "$(msg g_secret_generated "$name" "${#value}")"
    fi
    printf -v "S_$name" '%s' "$value"
}

# A password of a Valkey this installation runs itself: Compose writes it into the server's
# configuration between double quotes (requirepass "${REDIS_PASSWORD}"), where a " ends the value and
# a \ starts an escape, so the server would expect another password than the application sends.
bundled_valkey_password() {
    local var="S_$1"
    [[ ${!var-} != *'"'* && ${!var-} != *\\* ]] || die "$(msg g_valkey_password_chars "$1")"
}

# --- state and lock ---------------------------------------------------------------------------

prepare_state_dir() {
    mkdir -p "$STATE_DIR"
    chmod 700 "$STATE_DIR"
    LOG_FILE="$STATE_DIR/$COMMAND-$STAMP.log"
    : > "$LOG_FILE"
    chmod 600 "$LOG_FILE"
}
# One install or fix at a time (the lock names its process, as in backup.sh).
lock_state() {
    if ! mkdir "$STATE_DIR/.lock" 2>/dev/null; then
        local pid stale=0
        pid=$(cat "$STATE_DIR/.lock/pid" 2>/dev/null || true)
        if [[ $pid =~ ^[0-9]+$ ]]; then
            ps -p "$pid" >/dev/null 2>&1 || stale=1
        elif [[ -n $(find "$STATE_DIR/.lock" -maxdepth 0 -mmin +1 2>/dev/null) ]]; then
            stale=1
        fi
        [[ $stale == 1 ]] || die "$(msg g_locked "${pid:-?}")"
        rm -rf "$STATE_DIR/.lock"
        mkdir "$STATE_DIR/.lock" 2>/dev/null || die "$(msg g_locked "?")"
    fi
    echo $$ > "$STATE_DIR/.lock/pid"
    trap 'rm -rf "$STATE_DIR/.lock" "$STATE_DIR"/.tmp-* 2>/dev/null || true' EXIT
}
write_state() {
    {
        printf 'installer_version=%s\n' "$OPENHDID_RELEASE"
        printf 'profile=%s\n' "$PROFILE"
        printf 'role=%s\n' "${ROLE:-single}"
        printf 'project=%s\n' "$PROJECT"
        printf 'openhdid_version=%s\n' "$VERSION"
        printf 'installed_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        printf 'files_verified=%s\n' "$SUMS_VERIFIED"
        [[ -z ${ANSWERS_WRITTEN:-} ]] || printf 'answers_written=%s\n' "$ANSWERS_WRITTEN"
    } > "$STATE_DIR/state.tmp"
    chmod 600 "$STATE_DIR/state.tmp"
    mv -f "$STATE_DIR/state.tmp" "$STATE_DIR/state"
}

# The installer wrote this folder's .env and has not started the stack yet (step 9 writes the
# state): a later run may resume although its own "docker compose run" calls created the project's
# volumes, which hold no data before the first start.
mark_env_written() {
    printf 'env_written=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$STATE_DIR/progress"
    chmod 600 "$STATE_DIR/progress"
}
resumable() { [[ -f $STATE_DIR/progress && ! -f $STATE_DIR/state ]]; }

# ===== install ====================================================================================

refuse_root() {
    [[ $(id -u 2>/dev/null || echo 1000) != 0 ]] || die "$(msg i_root)"
}

# The answers file: "# openhdid-answers 1", then KEY=value lines of the known keys; the value is
# taken literally (one outer pair of apostrophes removed), never sourced or evaluated.
load_answers() {
    local file=$1 line n=0 key value validator mode
    [[ -f $file && -r $file ]] || die "$(msg i_answers_missing "$file")"
    mode=$(file_mode "$file")
    [[ $mode =~ ^[0-7]+$ ]] || die "$(msg i_answers_perm "$file" "?")"
    if (((8#$mode & 8#077) != 0)) || [[ ! -O $file ]]; then
        die "$(msg i_answers_perm "$file" "$mode")"
    fi
    # shellcheck disable=SC2094 # the file is only read; its name appears in the messages
    while IFS= read -r line || [[ -n $line ]]; do
        n=$((n + 1))
        line=${line%$'\r'}
        if [[ $n == 1 ]]; then
            [[ $line == '# openhdid-answers 1' ]] || die "$(msg i_answers_header "$file")"
            continue
        fi
        [[ -z $line || $line == \#* ]] && continue
        [[ $line == *=* ]] || die "$(msg i_answers_line "$file" "$n")"
        key=${line%%=*}
        value=${line#*=}
        validator=$(answer_validator "$key") || die "$(msg i_answers_key "$file" "$n" "$key")"
        answered "$key" && die "$(msg i_answers_dup "$file" "$n" "$key")"
        if [[ ${#value} -ge 2 && $value == \'*\' ]]; then
            value=${value:1:${#value}-2}
        fi
        [[ $value != *[[:cntrl:]]* ]] || die "$(msg i_answers_value "$file" "$n" "$key")"
        "$validator" "$value" || die "$(msg i_answers_value "$file" "$n" "$key")"
        printf -v "A_$key" '%s' "$value"
        ANSWERED="$ANSWERED$key "
    done < "$file"
    say "$(msg i_answers_loaded "$file")"
}

# The profile: --profile, the answers file, else the menu.
choose_profile() {
    local choice
    if [[ -n $PROFILE_OPT ]]; then
        v_profile "$PROFILE_OPT" || die "$(msg i_bad_profile "$PROFILE_OPT")"
        if answered PROFILE && [[ $A_PROFILE != "$PROFILE_OPT" ]]; then
            die "$(msg i_profile_mismatch "$PROFILE_OPT" "$A_PROFILE")"
        fi
        PROFILE=$PROFILE_OPT
    elif answered PROFILE; then
        PROFILE=$A_PROFILE
    elif [[ $YES == 1 ]]; then
        die "$(msg g_missing_answer PROFILE)"
    else
        say ""
        say "$(msg i_menu)"
        ask MENU_PROFILE "$(msg i_menu_q)" 2 v_choice_profile
        choice=$A_MENU_PROFILE
        case $choice in
            1) PROFILE=trial ;;
            2) PROFILE=5k ;;
            3) PROFILE=10k-single ;;
            4) PROFILE=10k-ha ;;
            5) PROFILE=20k ;;
        esac
    fi
    say ""
    say "$(msg "i_about_${PROFILE//-/_}")"
}

choose_role() {
    ROLE=""
    [[ $PROFILE == 10k-ha ]] || return 0
    if [[ -n $ROLE_OPT ]]; then
        v_role "$ROLE_OPT" || die "$(msg i_bad_role "$ROLE_OPT")"
        if answered ROLE && [[ $A_ROLE != "$ROLE_OPT" ]]; then
            die "$(msg i_role_mismatch "$ROLE_OPT" "$A_ROLE")"
        fi
        ROLE=$ROLE_OPT
    elif answered ROLE; then
        ROLE=$A_ROLE
    else
        say ""
        say "$(msg i_role_menu)"
        ask ROLE "$(msg i_role_q)" "" v_role
        ROLE=$A_ROLE
    fi
    say "$(msg "i_about_role_$ROLE")"
}

resolve_version() {
    local from_version=""
    if [[ -n $VERSION_OPT ]]; then
        VERSION=$VERSION_OPT
    elif answered OPENHDID_VERSION; then
        VERSION=$A_OPENHDID_VERSION
    elif [[ -n $FROM_DIR ]] && from_version=$(env_get "$(from_source env.example 2>/dev/null || echo /nonexistent)" OPENHDID_VERSION 2>/dev/null) && [[ -n $from_version ]]; then
        VERSION=$from_version
    else
        VERSION=$OPENHDID_RELEASE
    fi
    v_semver "$VERSION" || die "$(msg i_bad_version "$VERSION")"
}

kind_of() {
    case $PROFILE in
        trial | 5k | 10k-single) printf '%s' "$PROFILE" ;;
        10k-ha) [[ $ROLE == infra ]] && printf infra || printf app ;;
        20k) printf 20k ;;
    esac
}

# Step 1: the machine and Docker. Errors stop, warnings ask.
machine_checks() {
    findings_reset
    check_host "$KIND" refuse
    if [[ $KIND != 20k ]]; then
        check_docker "$KIND"
    fi
    if [[ ${#F_ID[@]} -gt 0 ]]; then
        print_findings
    fi
    [[ $(findings_count error) == 0 ]] || die "$(msg i_machine_errors)"
    if [[ $(findings_count warning) -gt 0 ]]; then
        WARNINGS=$((WARNINGS + $(findings_count warning)))
        say "    $(msg i_machine_warnings)"
        confirm
    fi
    done_ "$(msg i_machine_ok "$F_OK")"
}

# An existing installation is not installed over: check, fix and UPGRADE.md are the ways.
# A folder with only a .env (an interrupted installation) goes on, after a confirmation.
existing_guard() {
    local default=openhdid containers volumes volume found=""
    [[ $KIND == infra ]] && default=openhdid-infra
    PROJECT=$default
    if [[ -f .env ]]; then
        PROJECT=$(env_get .env COMPOSE_PROJECT_NAME || true)
        [[ -n $PROJECT ]] || PROJECT=$default
    elif answered PROJECT_NAME; then
        PROJECT=$A_PROJECT_NAME
    fi
    [[ $KIND != 20k ]] || return 0
    containers=$(dk ps -a -q --filter "label=com.docker.compose.project=$PROJECT" 2>/dev/null || true)
    volumes=$(dk volume ls --format '{{.Name}}' 2>/dev/null || true)
    for volume in $volumes; do
        [[ $volume == "${PROJECT}_db" || $volume == "${PROJECT}_storage" ]] && found="$found $volume"
    done
    if [[ -f .env ]]; then
        if [[ -n $containers ]] || { [[ -n $found ]] && ! resumable; }; then
            say ""
            say "$(msg i_existing "$DIR" "$PROJECT")"
            exit 2
        fi
        PARTIAL=1
        if [[ -n $found ]]; then say "    $(msg i_partial_volumes "${found# }")"; else say "    $(msg i_partial)"; fi
        confirm
        return 0
    fi
    while [[ -n $containers || -n $found ]]; do
        say "    $(msg i_project_taken "$PROJECT" "${found# }")"
        if [[ $YES == 1 ]] || answered PROJECT_NAME; then
            die "$(msg i_volume_without_env "$PROJECT" "${found# }")"
        fi
        ask PROJECT_NAME "$(msg i_q_project)" "" v_project
        if [[ $A_PROJECT_NAME == "$PROJECT" ]]; then
            die "$(msg i_volume_without_env "$PROJECT" "${found# }")"
        fi
        PROJECT=$A_PROJECT_NAME
        containers=$(dk ps -a -q --filter "label=com.docker.compose.project=$PROJECT" 2>/dev/null || true)
        found=""
        for volume in $volumes; do
            [[ $volume == "${PROJECT}_db" || $volume == "${PROJECT}_storage" ]] && found="$found $volume"
        done
    done
}

# A value of the existing .env (a resumed installation) as the default of a question.
existing() {
    local v=""
    [[ $PARTIAL == 1 ]] && v=$(env_get .env "$1" || true)
    printf '%s' "${v:-$2}"
}
default_url() {
    local host port=$1
    host=$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo localhost)
    host=$(tr '[:upper:]' '[:lower:]' <<<"$host")
    if [[ $port == 443 ]]; then printf 'https://%s' "$host"; else printf 'https://%s:%s' "$host" "$port"; fi
}
url_host() {
    local h=${1#https://}
    h=${h%%/*}
    printf '%s' "${h%%:*}"
}

# --- questions ----------------------------------------------------------------------------------

questions_single() {
    ask HTTPS_PORT "$(msg i_q_https_port)" "$(existing OPENHDID_HTTPS_PORT 443)" v_port
    ask HTTP_PORT "$(msg i_q_http_port)" "$(existing OPENHDID_HTTP_PORT 80)" v_port
    ask BIND_IP "$(msg i_q_bind)" "$(existing OPENHDID_BIND_IP 0.0.0.0)" v_name
    if [[ $PROFILE == trial ]]; then
        ask APP_URL "$(msg i_q_app_url)" "$(existing APP_URL "$(default_url "$A_HTTPS_PORT")")" v_url
        ask DEMO_DATA "$(msg i_q_demo)" "$(existing OPENHDID_DEMO_DATA true)" v_bool
        A_TLS_MODE=selfsigned
        ANSWERED="${ANSWERED}TLS_MODE "
        ask APP_ENV "$(msg i_q_app_env)" "$( [[ $A_DEMO_DATA == true ]] && echo demo || echo production)" v_appenv
        [[ $A_DEMO_DATA != true || $A_APP_ENV == demo ]] || die "$(msg i_demo_needs_env)"
        return 0
    fi
    ask APP_URL "$(msg i_q_app_url_req)" "$(existing APP_URL "")" v_fqdn_url
    ask APP_ENV "$(msg i_q_app_env)" production v_appenv
    ask TLS_MODE "$(msg i_q_tls)" "$(existing OPENHDID_TLS on)" v_tls
    if [[ $A_TLS_MODE == on ]]; then
        local cert_default="" key_default=""
        [[ -f $DIR/tls/fullchain.pem ]] && cert_default="$DIR/tls/fullchain.pem"
        [[ -f $DIR/tls/privkey.pem ]] && key_default="$DIR/tls/privkey.pem"
        ask TLS_CERT "$(msg i_q_tls_cert)" "$cert_default" v_file_rel
        A_TLS_CERT=$(abs_path "$A_TLS_CERT")
        ask TLS_KEY "$(msg i_q_tls_key)" "$key_default" v_file_rel
        A_TLS_KEY=$(abs_path "$A_TLS_KEY")
    elif [[ $A_TLS_MODE == off ]]; then
        ask LB_ADDRESSES "$(msg i_q_lb)" "" v_proxies
    fi
    ask EXEMPT_NETWORKS "$(msg i_q_exempt)" "$(existing HDID_RATE_LIMIT_EXEMPT_NETWORKS "")" v_exempt
    ask ADMIN_EMAIL "$(msg i_q_admin_email)" "" v_email_opt
    if [[ -n $A_ADMIN_EMAIL ]]; then
        ask ADMIN_NAME "$(msg i_q_admin_name)" "$(msg i_admin_default_name)" v_text100
    fi
}
# An answer may be relative when typed; the answers file needs absolute paths.
v_file_rel() { [[ -f $1 && -r $1 ]]; }
abs_path() {
    case $1 in
        /*) printf '%s' "$1" ;;
        *) printf '%s/%s' "$(cd -- "$(dirname -- "$1")" && pwd -P)" "$(basename -- "$1")" ;;
    esac
}

questions_infra() {
    local private
    private=$(first_private_ipv4)
    ask INFRA_BIND_IP "$(msg i_q_infra_bind)" "$private" v_name
    [[ $A_INFRA_BIND_IP != 0.0.0.0 ]] || warn "$(msg i_infra_bind_all)"
    ask INFRA_ADDRESS "$(msg i_q_infra_address)" "$( [[ $A_INFRA_BIND_IP == 0.0.0.0 ]] && echo "$private" || echo "$A_INFRA_BIND_IP")" v_name
    ask INFRA_DB_PORT "$(msg i_q_infra_db_port)" 3306 v_port
    ask INFRA_VALKEY_PORT "$(msg i_q_infra_valkey_port)" 6379 v_port
    ask INFRA_VALKEY_SESSIONS_PORT "$(msg i_q_infra_sessions_port)" 6380 v_port
    ask APP_HOSTS "$(msg i_q_app_hosts)" "" v_cidrs
    ask DB_DATABASE "$(msg i_q_db_name)" openhdid v_dbname
    ask DB_USERNAME "$(msg i_q_db_user)" openhdid v_name
    ask DB_MIGRATE_USERNAME "$(msg i_q_db_migrate_user)" openhdid_migrate v_name
    ask APP_URL "$(msg i_q_app_url_infra)" "" v_url_opt
    ask LB_ADDRESSES "$(msg i_q_lb_infra)" "" v_cidrs
    ask NFS_SERVER "$(msg i_q_nfs_server)" "$A_INFRA_ADDRESS" v_name
    ask NFS_PATH "$(msg i_q_nfs_path)" /export/openhdid-storage v_abspath
    ask REDIS_PREFIX "$(msg i_q_prefix)" "openhdid-$(random_hex6)-" v_prefix
}
random_hex6() { head -c 3 /dev/urandom | od -An -tx1 | tr -d ' \n'; }

# Host A or B of the 10k profile. With the shared answers file only what it lacks is asked.
questions_app() {
    local missing=" " key
    if [[ $ROLE == b ]]; then
        for key in APP_KEY APP_URL LB_ADDRESSES NFS_SERVER NFS_PATH DB_HOST DB_PASSWORD DB_MIGRATE_PASSWORD REDIS_HOST REDIS_PASSWORD REDIS_SESSION_HOST; do
            answered "$key" || die "$(msg i_answers_from_a "$key")"
        done
    fi
    for key in APP_URL LB_ADDRESSES NFS_SERVER NFS_PATH DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_MIGRATE_USERNAME DB_MIGRATE_PASSWORD \
        REDIS_HOST REDIS_PORT REDIS_PASSWORD REDIS_SESSION_HOST REDIS_SESSION_PORT REDIS_PREFIX; do
        answered "$key" || missing="$missing$key "
    done
    ask APP_URL "$(msg i_q_app_url_req)" "" v_fqdn_url
    ask LB_ADDRESSES "$(msg i_q_lb)" "" v_proxies
    ask NFS_SERVER "$(msg i_q_nfs_server)" "" v_name
    ask NFS_PATH "$(msg i_q_nfs_path)" /export/openhdid-storage v_abspath
    ask DB_HOST "$(msg i_q_db_host)" "" v_name
    ask DB_PORT "$(msg i_q_db_port)" 3306 v_port
    ask DB_DATABASE "$(msg i_q_db_name)" openhdid v_dbname
    ask DB_USERNAME "$(msg i_q_db_user)" openhdid v_name
    ask_secret DB_PASSWORD "$(msg i_q_db_password)" v_password
    ask DB_MIGRATE_USERNAME "$(msg i_q_db_migrate_user)" openhdid_migrate v_name
    ask_secret DB_MIGRATE_PASSWORD "$(msg i_q_db_migrate_password)" v_password
    ask REDIS_HOST "$(msg i_q_redis_host)" "" v_name
    ask REDIS_PORT "$(msg i_q_redis_port)" 6379 v_port
    ask_secret REDIS_PASSWORD "$(msg i_q_redis_password)" v_password
    ask REDIS_SESSION_HOST "$(msg i_q_sessions_host)" "" v_name
    ask REDIS_SESSION_PORT "$(msg i_q_sessions_port)" 6379 v_port
    if ! answered REDIS_SESSION_PASSWORD && [[ $YES != 1 ]]; then
        ask_secret REDIS_SESSION_PASSWORD "$(msg i_q_sessions_password)" v_password_opt
    fi
    ask REDIS_PREFIX "$(msg i_q_prefix)" "openhdid-$(random_hex6)-" v_prefix
    # Host B takes the shared exemptions from the answers file (empty when host A gave none).
    if [[ $ROLE == a ]]; then
        ask EXEMPT_NETWORKS "$(msg i_q_exempt)" "" v_exempt
    fi
    ask HTTP_PORT "$(msg i_q_http_port)" 80 v_port
    ask BIND_IP "$(msg i_q_bind)" 0.0.0.0 v_name
    if [[ $ROLE == a ]]; then
        ask ADMIN_EMAIL "$(msg i_q_admin_email)" "" v_email_opt
        [[ -z $A_ADMIN_EMAIL ]] || ask ADMIN_NAME "$(msg i_q_admin_name)" "$(msg i_admin_default_name)" v_text100
    fi
    MISSING_SHARED=$missing
}

questions_k8s() {
    ask K8S_NAMESPACE "$(msg i_q_k8s_ns)" openhdid v_label
    ask APP_URL "$(msg i_q_app_url_req)" "" v_fqdn_url
    ask K8S_INGRESS_NAMESPACE "$(msg i_q_k8s_ingress_ns)" ingress-nginx v_label
    ask K8S_POD_CIDR "$(msg i_q_k8s_pod_cidr)" "" v_proxies
    ask DB_HOST "$(msg i_q_db_host)" "" v_name
    ask DB_PORT "$(msg i_q_db_port)" 3306 v_port
    ask DB_DATABASE "$(msg i_q_db_name)" openhdid v_dbname
    ask DB_USERNAME "$(msg i_q_db_user)" openhdid v_name
    ask_secret DB_PASSWORD "$(msg i_q_db_password)" v_password
    ask DB_MIGRATE_USERNAME "$(msg i_q_db_migrate_user)" openhdid_migrate v_name
    ask_secret DB_MIGRATE_PASSWORD "$(msg i_q_db_migrate_password)" v_password
    ask REDIS_HOST "$(msg i_q_redis_host)" "" v_name
    ask REDIS_PORT "$(msg i_q_redis_port)" 6379 v_port
    ask_secret REDIS_PASSWORD "$(msg i_q_redis_password)" v_password
    ask REDIS_SESSION_HOST "$(msg i_q_sessions_host)" "" v_name
    ask REDIS_SESSION_PORT "$(msg i_q_sessions_port)" 6379 v_port
    if ! answered REDIS_SESSION_PASSWORD && [[ $YES != 1 ]]; then
        ask_secret REDIS_SESSION_PASSWORD "$(msg i_q_sessions_password)" v_password_opt
    fi
    ask REDIS_PREFIX "$(msg i_q_prefix)" "openhdid-$(random_hex6)-" v_prefix
    ask K8S_STORAGE_CLASS "$(msg i_q_k8s_storage)" "" v_label_opt
    ask TLS_CERT "$(msg i_q_tls_cert_k8s)" "" v_file_opt
    ask TLS_KEY "$(msg i_q_tls_key_k8s)" "" v_file_opt
    ask K8S_CA_DIR "$(msg i_q_k8s_ca)" "" v_dir_opt
    ask ADMIN_EMAIL "$(msg i_q_admin_email)" "" v_email_opt
}

# The value of a secret for the summary: its length or that it will be generated.
summary_secret() {
    local key=$1 existing=$2 a="A_$1"
    if [[ -n $existing ]]; then
        msg i_sum_kept "${#existing}"
    elif [[ -n ${!a-} ]]; then
        local v=${!a}
        msg i_sum_given "${#v}"
    elif [[ $key == APP_KEY ]]; then
        msg i_sum_generated_key
    else
        msg i_sum_generated
    fi
}
# A label padded by characters, not bytes (printf %-34s would count the bytes of á, ő …).
summary_line() {
    local pad=$((34 - ${#1}))
    [[ $pad -gt 0 ]] || pad=0
    say "$(printf '    %s%*s %s' "$1" "$pad" '' "$2")"
}
summary_value() {
    local var="A_$1"
    summary_line "$1" "${!var:-$(msg i_sum_empty)}"
}

# --- the shared answers file of the 10k profile --------------------------------------------------

write_answers_file() {
    local file="$DIR/$SHARED_ANSWERS" tmp
    tmp="$file.tmp.$$"
    {
        printf '# openhdid-answers 1\n'
        printf '# %s\n' "$(msg i_answers_file_note "$(date '+%Y-%m-%d %H:%M')")"
        printf 'ANSWERS_FORMAT=1\n'
        printf 'PROFILE=10k-ha\n'
        printf 'OPENHDID_VERSION=%s\n' "$VERSION"
        local key value var
        for key in APP_KEY APP_URL LB_ADDRESSES APP_HOSTS NFS_SERVER NFS_PATH DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD \
            DB_MIGRATE_USERNAME DB_MIGRATE_PASSWORD REDIS_HOST REDIS_PORT REDIS_PASSWORD REDIS_SESSION_HOST REDIS_SESSION_PORT \
            REDIS_SESSION_PASSWORD REDIS_PREFIX EXEMPT_NETWORKS; do
            var="W_$key"
            value=${!var-}
            [[ -n $value ]] || continue
            printf '%s=%s\n' "$key" "$value"
        done
    } > "$tmp"
    chmod 600 "$tmp"
    if [[ -f $file ]] && ! cmp -s "$file" "$tmp"; then
        warn "$(msg i_answers_overwrite "$SHARED_ANSWERS")"
        confirm
    fi
    mv -f "$tmp" "$file"
    ANSWERS_WRITTEN="$file"
    say "    $(msg i_answers_written "$SHARED_ANSWERS")"
}
# Values a host A asked for go back into the shared file (B reads them from there).
append_answers() {
    local key var
    [[ -n ${MISSING_SHARED// /} && -n $ANSWERS_FILE ]] || return 0
    say "    $(msg i_answers_append "$ANSWERS_FILE" "${MISSING_SHARED# }")"
    confirm_soft || return 0
    for key in $MISSING_SHARED; do
        var="A_$key"
        [[ -n ${!var-} ]] && printf '%s=%s\n' "$key" "${!var}" >> "$ANSWERS_FILE"
    done
    done_ "$(msg i_answers_appended)"
}

# --- shared install steps -----------------------------------------------------------------------

step_images() {
    local images=() image file line
    images+=("$APP_IMAGE:$VERSION")
    for file in "$@"; do
        [[ -f $file ]] || continue
        while IFS= read -r line; do
            if [[ $line =~ ^[[:space:]]+image:[[:space:]]+([^[:space:]\$]+)$ ]]; then
                image=${BASH_REMATCH[1]}
                [[ " ${images[*]} " == *" $image "* ]] || images+=("$image")
            fi
        done < "$file"
    done
    [[ ${NO_APP_IMAGE:-0} == 0 ]] || images=("${images[@]:1}")
    for image in ${images[@]+"${images[@]}"}; do
        if dk image inspect "$image" >/dev/null 2>&1; then
            info "$(msg i_image_present "$image")"
        else
            info "$(msg i_image_pull "$image")"
            dk pull -q "$image" >/dev/null || die "$(msg i_image_failed "$image")"
        fi
    done
}

# The check-env of the container (all start rules); an older image without the command runs
# the same rules through any role start ("artisan --version").
step_container_check() {
    local out status=0 line errors=0
    # Not run_web: the start check writes its warnings to stderr, which run_web keeps only on failure.
    out=$(dc run --rm --no-deps -T web check-env web 2>&1) || status=$?
    if [[ $out == *"unknown command 'check-env'"* ]]; then
        status=0
        out=$(dc run --rm --no-deps -T web artisan --version 2>&1) || status=$?
    fi
    out=$(redact_secrets "$out")
    while IFS= read -r line; do
        case $line in
            *"openhdid: ERROR:"*) errors=$((errors + 1)); say "    ${line#*openhdid: }" ;;
            *"openhdid: warning:"*) warn "${line#*openhdid: warning: }" ;;
        esac
    done <<<"$out"
    if [[ $errors -gt 0 || $status != 0 ]]; then
        [[ $errors -gt 0 ]] || say "$out"
        die "$(msg i_container_check_failed)"
    fi
}

step_compose_config() {
    local out
    if ! out=$(dc config --quiet 2>&1); then
        say "$(redact_secrets "$out")"
        die "$(msg i_compose_invalid)"
    fi
    done_ "$(msg i_compose_ok)"
}

# docker compose up: the migration runs first (it is part of what the summary announced).
step_start() {
    local out services line name state health code
    if ! dc up -d --wait --wait-timeout 600; then
        services=$(dc ps -a --format '{{.Service}} {{.State}} {{.Health}} {{.ExitCode}}' 2>/dev/null || true)
        say "$services"
        while read -r name state health code; do
            [[ -n $name ]] || continue
            if [[ $name == migrate && $code == 0 ]]; then continue; fi
            if [[ $state != running || ( -n $health && $health != healthy ) ]]; then
                say ""
                say "    --- docker compose logs --tail 30 $name ---"
                out=$(dc logs --no-color --tail 30 "$name" 2>&1 || true)
                say "$(redact_secrets "$out")"
            fi
        done <<<"$services"
        die "$(msg i_start_failed)"
    fi
    done_ "$(msg i_started)"
}

step_check() {
    if [[ $CHECK_IMPLEMENTED == 1 ]]; then
        local code=0
        (QUIET=0 cmd_check) || code=$?
        [[ $code == 0 ]] || WARNINGS=$((WARNINGS + 1))
        CHECK_RESULT=$code
    else
        info "$(msg i_check_later)"
        CHECK_RESULT=""
    fi
}

step_admin() {
    if [[ -z ${A_ADMIN_EMAIL:-} ]]; then
        info "$(msg i_admin_later)"
        ADMIN_PENDING=1
        return 0
    fi
    if [[ $YES == 1 || ! -t 0 || ! -t 1 ]]; then
        info "$(msg i_admin_no_terminal)"
        ADMIN_PENDING=1
        return 0
    fi
    # The command asks for the password itself, hidden: the terminal is handed over on purpose.
    if docker compose exec web php artisan hdid:make-admin "$A_ADMIN_EMAIL" --name="${A_ADMIN_NAME:-$(msg i_admin_default_name)}"; then
        ADMIN_PENDING=0
        done_ "$(msg i_admin_done "$A_ADMIN_EMAIL")"
    else
        warn "$(msg i_admin_failed)"
        ADMIN_PENDING=1
    fi
}

# The folders the web container mounts: tls (the certificate) and ca (internal CAs it trusts at start).
# The container user (uid 82) has to enter them, so they are 755 although the installer's umask is 077;
# an existing folder keeps the mode its owner gave it (the check names an unreadable one: TLS-05).
make_mount_dirs() {
    local folder
    for folder in tls ca; do
        if [[ ! -d $folder ]]; then
            mkdir "$folder" || die "$(msg g_write_failed "$folder")"
            chmod 755 "$folder"
        fi
    done
}

# The certificate goes in place through the container user (uid 82, or its namespace id under
# rootless Docker), without root: the user writes it into a folder under .install (700) that is
# open to it only, the container checks the new pair there (mounted beside the live folder, so a
# bad pair never takes the place of tls), and the finished folder takes the place of tls (the
# backup.sh restore method). COPYFILE_DISABLE keeps the macOS tar from adding ._ metadata files.
step_tls() {
    local mode=${A_TLS_MODE:-off} src new old out line mount=/etc/openhdid/tls-new
    make_mount_dirs
    if [[ $mode != on ]]; then
        info "$(msg "i_tls_$mode")"
        return 0
    fi
    src="$STATE_DIR/.tmp-tls-src-$$"
    new="$STATE_DIR/.tmp-tls-$$"
    mkdir -p "$src" && chmod 700 "$src"
    cp "$A_TLS_CERT" "$src/fullchain.pem" || die "$(msg i_tls_read "$A_TLS_CERT")"
    cp "$A_TLS_KEY" "$src/privkey.pem" || die "$(msg i_tls_read "$A_TLS_KEY")"
    { mkdir "$new" && chmod 777 "$new"; } || die "$(msg g_write_failed "$new")"
    COPYFILE_DISABLE=1 tar cf - -C "$src" fullchain.pem privkey.pem | run_web -v "$new":/t --entrypoint sh web \
        -c 'umask 077 && tar xf - -C /t && chmod 444 /t/fullchain.pem && chmod 400 /t/privkey.pem' \
        || die "$(msg i_tls_install_failed)"
    rm -rf "$src"
    if ! out=$(run_web -v "$new":$mount:ro -e OPENHDID_TLS_CERT=$mount/fullchain.pem -e OPENHDID_TLS_KEY=$mount/privkey.pem web tls-check 2>&1 </dev/null); then
        say "$(redact_secrets "$out")"
        rm -rf "$new"
        die "$(msg i_tls_check_failed)"
    fi
    chmod 755 "$new"
    if [[ -n $(ls -A tls 2>/dev/null) ]]; then
        old="$STATE_DIR/tls-elozo-$STAMP"
        mv tls "$old" || die "$(msg g_write_failed tls)"
        info "$(msg i_tls_previous ".install/$(basename "$old")")"
    else
        rmdir tls 2>/dev/null || true
    fi
    mv "$new" tls || die "$(msg g_write_failed tls)"
    while IFS= read -r line; do
        [[ -n $line ]] && info "$line"
    done <<<"$out"
    done_ "$(msg i_tls_done)"
}

# --- the single-host profiles (trial, 5k, 10k-single) --------------------------------------------

install_single() {
    local files compose_file="" profile_asset=""
    STEP_TOTAL=12
    case $PROFILE in
        5k) compose_file=compose.yaml:profiles/5k/compose.yaml profile_asset=profile-5k.yaml ;;
        10k-single) compose_file=compose.yaml:profiles/10k/single.yaml profile_asset=profile-10k-single.yaml ;;
    esac

    step 1 "$(msg i_step_machine)"
    why "$(msg i_why_machine)"
    machine_checks
    existing_guard

    step 2 "$(msg i_step_questions)"
    why "$(msg i_why_questions)"
    questions_single
    findings_reset
    check_ports "$A_HTTPS_PORT" "$A_HTTP_PORT"
    if [[ $(findings_count error) -gt 0 ]]; then
        print_findings
        die "$(msg i_ports_taken)"
    fi
    local old_key="" old_db="" old_redis="" old_root="" old_migrate=""
    if [[ $PARTIAL == 1 ]]; then
        old_key=$(env_get .env APP_KEY || true)
        old_db=$(env_get .env DB_PASSWORD || true)
        old_redis=$(env_get .env REDIS_PASSWORD || true)
        old_root=$(env_get db.env MARIADB_ROOT_PASSWORD || true)
        old_migrate=$(env_get db.env DB_PASSWORD || true)
    fi
    say ""
    say "  $(msg i_summary_title)"
    summary_line PROFILE "$PROFILE"
    summary_line "$(msg i_sum_folder)" "$DIR"
    summary_line "$(msg i_sum_project)" "$PROJECT"
    summary_line OPENHDID_VERSION "$VERSION"
    summary_value APP_URL
    summary_value APP_ENV
    summary_line OPENHDID_TLS "$A_TLS_MODE"
    [[ $A_TLS_MODE != on ]] || { summary_value TLS_CERT; summary_value TLS_KEY; }
    [[ $A_TLS_MODE != off ]] || summary_value LB_ADDRESSES
    summary_line "$(msg i_sum_ports)" "$A_BIND_IP:$A_HTTPS_PORT (HTTPS), $A_BIND_IP:$A_HTTP_PORT (HTTP)"
    [[ $PROFILE != trial ]] || summary_value DEMO_DATA
    [[ $PROFILE == trial ]] || summary_value EXEMPT_NETWORKS
    summary_line APP_KEY "$(summary_secret APP_KEY "$old_key")"
    summary_line "DB_PASSWORD (.env)" "$(summary_secret DB_PASSWORD "$old_db")"
    summary_line "DB_PASSWORD (db.env)" "$(summary_secret DB_MIGRATE_PASSWORD "$old_migrate")"
    summary_line MARIADB_ROOT_PASSWORD "$(summary_secret MARIADB_ROOT_PASSWORD "$old_root")"
    summary_line REDIS_PASSWORD "$(summary_secret REDIS_PASSWORD "$old_redis")"
    [[ $PROFILE == trial ]] || summary_value ADMIN_EMAIL
    files="compose.yaml${profile_asset:+, $(asset_target "$profile_asset")}, .env, db.env, env.example, db.env.example, backup.sh, UPGRADE.md, TROUBLESHOOTING.md"
    [[ $A_TLS_MODE != on ]] || files="$files, tls/"
    summary_line "$(msg i_sum_files)" "$files"
    [[ $PARTIAL == 0 ]] || say "    $(msg i_sum_backup)"
    say "    $(msg i_sum_migrations)"
    confirm

    step 3 "$(msg i_step_download)"
    why "$(msg i_why_download "$VERSION")"
    local assets=(compose.yaml env.example db.env.example backup.sh UPGRADE.md TROUBLESHOOTING.md)
    [[ -z $profile_asset ]] || assets+=("$profile_asset")
    download_release "${assets[@]}"
    done_ "$(msg i_download_done)"

    step 4 "$(msg i_step_images)"
    why "$(msg i_why_images)"
    step_images compose.yaml
    done_ "$(msg i_images_done)"

    step 5 "$(msg i_step_secrets)"
    why "$(msg i_why_secrets)"
    local need=()
    [[ -n $old_key || -n ${A_APP_KEY:-} ]] || need+=(APP_KEY)
    [[ -n $old_db || -n ${A_DB_PASSWORD:-} ]] || need+=(DB_PASSWORD)
    [[ -n $old_migrate || -n ${A_DB_MIGRATE_PASSWORD:-} ]] || need+=(DB_MIGRATE_PASSWORD)
    [[ -n $old_root || -n ${A_MARIADB_ROOT_PASSWORD:-} ]] || need+=(MARIADB_ROOT_PASSWORD)
    [[ -n $old_redis || -n ${A_REDIS_PASSWORD:-} ]] || need+=(REDIS_PASSWORD)
    gen_secrets ${need[@]+"${need[@]}"}
    pick_secret APP_KEY "$old_key"
    pick_secret DB_PASSWORD "$old_db"
    pick_secret DB_MIGRATE_PASSWORD "$old_migrate"
    pick_secret MARIADB_ROOT_PASSWORD "$old_root"
    pick_secret REDIS_PASSWORD "$old_redis"
    bundled_valkey_password REDIS_PASSWORD
    done_ "$(msg i_secrets_done)"

    step 6 "$(msg i_step_env)"
    why "$(msg i_why_env)"
    env_backup .env
    env_backup db.env
    env_reset
    env_set OPENHDID_VERSION "$VERSION"
    [[ $PROJECT == openhdid ]] || env_set COMPOSE_PROJECT_NAME "$PROJECT"
    env_set COMPOSE_PROFILES db,redis
    if [[ -n $compose_file ]]; then
        [[ ! -f compose.override.yaml ]] || { compose_file="$compose_file:compose.override.yaml"; info "$(msg i_override_added)"; }
        env_set COMPOSE_FILE "$compose_file"
    else
        UNSET_KEYS=" COMPOSE_FILE "
    fi
    env_set OPENHDID_BIND_IP "$A_BIND_IP"
    env_set OPENHDID_HTTPS_PORT "$A_HTTPS_PORT"
    env_set OPENHDID_HTTP_PORT "$A_HTTP_PORT"
    env_set OPENHDID_TLS "$A_TLS_MODE"
    if [[ $A_TLS_MODE == off ]]; then
        env_set TRUSTED_PROXIES "$(list_commas "$A_LB_ADDRESSES")"
        env_set OPENHDID_MONITORING_ALLOW "$(list_commas "$A_LB_ADDRESSES")"
    fi
    answered EXTRA_HOSTS && env_set OPENHDID_EXTRA_HOSTS "$(list_commas "$A_EXTRA_HOSTS")"
    env_set HDID_RATE_LIMIT_EXEMPT_NETWORKS "$(list_commas "${A_EXEMPT_NETWORKS:-}")"
    env_set APP_ENV "$A_APP_ENV"
    env_set APP_KEY "$S_APP_KEY"
    env_set APP_URL "${A_APP_URL%/}"
    [[ $PROFILE != trial ]] || env_set OPENHDID_DEMO_DATA "$A_DEMO_DATA"
    env_set DB_PASSWORD "$S_DB_PASSWORD"
    env_set REDIS_PASSWORD "$S_REDIS_PASSWORD"
    mark_env_written
    env_render env.example .env
    info "$(msg i_env_written .env)"
    env_reset
    env_set MARIADB_ROOT_PASSWORD "$S_MARIADB_ROOT_PASSWORD"
    env_set DB_USERNAME openhdid_migrate
    env_set DB_PASSWORD "$S_DB_MIGRATE_PASSWORD"
    env_render db.env.example db.env
    info "$(msg i_env_written db.env)"
    done_ "$(msg i_env_done)"

    step 7 "$(msg i_step_tls)"
    why "$(msg i_why_tls)"
    step_tls

    step 8 "$(msg i_step_config)"
    why "$(msg i_why_config)"
    step_compose_config
    step_container_check
    done_ "$(msg i_config_done)"

    step 9 "$(msg i_step_start)"
    why "$(msg i_why_start)"
    write_state
    step_start

    step 10 "$(msg i_step_check)"
    why "$(msg i_why_check)"
    step_check

    step 11 "$(msg i_step_admin)"
    why "$(msg i_why_admin)"
    if [[ $PROFILE == trial ]]; then
        info "$(msg i_admin_trial)"
        ADMIN_PENDING=0
    else
        step_admin
    fi

    step 12 "$(msg i_step_summary)"
    write_state
    final_summary
    next_steps_single
}

list_commas() {
    local item out=""
    for item in ${1//,/ }; do out="$out${out:+,}$item"; done
    printf '%s' "$out"
}

# --- host A and host B of the 10k profile --------------------------------------------------------

install_app() {
    local files
    STEP_TOTAL=12
    step 1 "$(msg i_step_machine)"
    why "$(msg i_why_machine)"
    machine_checks
    existing_guard

    step 2 "$(msg i_step_questions)"
    why "$(msg "i_why_questions_$ROLE")"
    questions_app
    findings_reset
    check_ports "$A_HTTP_PORT"
    if [[ $(findings_count error) -gt 0 ]]; then
        print_findings
        die "$(msg i_ports_taken)"
    fi
    local old_key=""
    [[ $PARTIAL == 0 ]] || old_key=$(env_get .env APP_KEY || true)
    if [[ $ROLE == b && -n $old_key && $old_key != "${A_APP_KEY:-}" ]]; then
        die "$(msg i_key_differs)"
    fi
    say ""
    say "  $(msg i_summary_title)"
    summary_line PROFILE "10k-ha, $(msg "i_role_name_$ROLE")"
    summary_line "$(msg i_sum_folder)" "$DIR"
    summary_line "$(msg i_sum_project)" "$PROJECT"
    summary_line OPENHDID_VERSION "$VERSION"
    summary_value APP_URL
    summary_line OPENHDID_TLS "off ($(msg i_sum_tls_lb))"
    summary_value LB_ADDRESSES
    summary_line "$(msg i_sum_ports)" "$A_BIND_IP:$A_HTTP_PORT (HTTP)"
    summary_line "MariaDB" "$A_DB_HOST:$A_DB_PORT/$A_DB_DATABASE ($A_DB_USERNAME, $A_DB_MIGRATE_USERNAME)"
    summary_line "Valkey" "$A_REDIS_HOST:$A_REDIS_PORT, $(msg i_sum_sessions) $A_REDIS_SESSION_HOST:$A_REDIS_SESSION_PORT"
    summary_line "NFS" "$A_NFS_SERVER:$A_NFS_PATH"
    summary_value REDIS_PREFIX
    summary_line APP_KEY "$(summary_secret APP_KEY "$old_key")"
    summary_line DB_PASSWORD "$(summary_secret DB_PASSWORD "")"
    summary_line "DB_PASSWORD (db.env)" "$(summary_secret DB_MIGRATE_PASSWORD "")"
    summary_line REDIS_PASSWORD "$(summary_secret REDIS_PASSWORD "")"
    if [[ -n ${A_REDIS_SESSION_PASSWORD:-} ]]; then
        summary_line REDIS_SESSION_PASSWORD "$(summary_secret REDIS_SESSION_PASSWORD "")"
    else
        summary_line REDIS_SESSION_PASSWORD "$(msg i_sum_same_as_main)"
    fi
    if [[ $ROLE == a ]]; then
        summary_line COMPOSE_PROFILES "primary ($(msg i_sum_primary))"
    else
        summary_line COMPOSE_PROFILES "$(msg i_sum_secondary)"
    fi
    files="compose.yaml, profiles/10k/compose.yaml, .env, db.env, env.example, db.env.example, backup.sh, UPGRADE.md, TROUBLESHOOTING.md"
    summary_line "$(msg i_sum_files)" "$files"
    [[ $PARTIAL == 0 ]] || say "    $(msg i_sum_backup)"
    say "    $(msg "i_sum_migrations_$ROLE")"
    confirm
    [[ $ROLE != a ]] || append_answers

    step 3 "$(msg i_step_download)"
    why "$(msg i_why_download "$VERSION")"
    download_release compose.yaml env.example db.env.example backup.sh UPGRADE.md TROUBLESHOOTING.md profile-10k.yaml
    done_ "$(msg i_download_done)"

    step 4 "$(msg i_step_images)"
    why "$(msg i_why_images_app)"
    step_images
    done_ "$(msg i_images_done)"

    step 5 "$(msg i_step_secrets)"
    why "$(msg i_why_secrets_app)"
    if [[ -z $old_key && -z ${A_APP_KEY:-} ]]; then
        gen_secrets APP_KEY
    fi
    pick_secret APP_KEY "$old_key"
    done_ "$(msg i_secrets_done)"

    step 6 "$(msg i_step_env)"
    why "$(msg i_why_env)"
    env_backup .env
    env_backup db.env
    env_reset
    local compose_file=compose.yaml:profiles/10k/compose.yaml
    [[ ! -f compose.override.yaml ]] || { compose_file="$compose_file:compose.override.yaml"; info "$(msg i_override_added)"; }
    env_set OPENHDID_VERSION "$VERSION"
    [[ $PROJECT == openhdid ]] || env_set COMPOSE_PROJECT_NAME "$PROJECT"
    env_set COMPOSE_PROFILES "$( [[ $ROLE == a ]] && echo primary || true)"
    env_set COMPOSE_FILE "$compose_file"
    env_set OPENHDID_BIND_IP "$A_BIND_IP"
    env_set OPENHDID_HTTP_PORT "$A_HTTP_PORT"
    env_set OPENHDID_TLS off
    env_set OPENHDID_MONITORING_ALLOW "$(list_commas "$A_LB_ADDRESSES")"
    env_set TRUSTED_PROXIES "$(list_commas "$A_LB_ADDRESSES")"
    env_set HDID_RATE_LIMIT_EXEMPT_NETWORKS "$(list_commas "${A_EXEMPT_NETWORKS:-}")"
    env_set APP_KEY "$S_APP_KEY"
    env_set APP_URL "${A_APP_URL%/}"
    env_set DB_HOST "$A_DB_HOST"
    env_set DB_PORT "$A_DB_PORT"
    env_set DB_DATABASE "$A_DB_DATABASE"
    env_set DB_USERNAME "$A_DB_USERNAME"
    env_set DB_PASSWORD "$A_DB_PASSWORD"
    env_set REDIS_HOST "$A_REDIS_HOST"
    env_set REDIS_PORT "$A_REDIS_PORT"
    env_set REDIS_PASSWORD "$A_REDIS_PASSWORD"
    env_set REDIS_SESSION_HOST "$A_REDIS_SESSION_HOST"
    env_set REDIS_SESSION_PORT "$A_REDIS_SESSION_PORT"
    env_set REDIS_SESSION_PASSWORD "${A_REDIS_SESSION_PASSWORD:-}"
    env_set REDIS_PREFIX "$A_REDIS_PREFIX"
    env_set CACHE_PREFIX "${A_REDIS_PREFIX}cache-"
    env_set OPENHDID_NFS_SERVER "$A_NFS_SERVER"
    env_set OPENHDID_NFS_PATH "$A_NFS_PATH"
    mark_env_written
    env_render env.example .env
    info "$(msg i_env_written .env)"
    env_reset
    env_set DB_USERNAME "$A_DB_MIGRATE_USERNAME"
    env_set DB_PASSWORD "$A_DB_MIGRATE_PASSWORD"
    env_render db.env.example db.env
    info "$(msg i_env_written db.env)"
    done_ "$(msg i_env_done)"

    step 7 "$(msg i_step_tls)"
    why "$(msg i_why_tls_app)"
    A_TLS_MODE=off
    step_tls

    step 8 "$(msg i_step_config)"
    why "$(msg i_why_config)"
    step_compose_config
    step_container_check
    done_ "$(msg i_config_done)"

    step 9 "$(msg i_step_start)"
    why "$(msg "i_why_start_$ROLE")"
    write_state
    step_start

    step 10 "$(msg i_step_check)"
    why "$(msg i_why_check)"
    step_check

    step 11 "$(msg i_step_admin)"
    why "$(msg i_why_admin)"
    if [[ $ROLE == a ]]; then
        step_admin
    else
        info "$(msg i_admin_on_a)"
        ADMIN_PENDING=0
    fi

    step 12 "$(msg i_step_summary)"
    if [[ $ROLE == a && -z $ANSWERS_FILE ]]; then
        share_values_from_answers
        write_answers_file
    fi
    write_state
    final_summary
    next_steps_app
    if [[ $ROLE == b && -n $ANSWERS_FILE && -f $ANSWERS_FILE ]]; then
        say ""
        if [[ $YES == 1 ]]; then
            warn "$(msg i_answers_kept_yes "$ANSWERS_FILE")"
        elif ask_yes_no "$(msg i_answers_delete_q "$ANSWERS_FILE")" y; then
            rm -f "$ANSWERS_FILE"
            done_ "$(msg i_answers_deleted "$ANSWERS_FILE")"
        else
            warn "$(msg i_answers_kept "$ANSWERS_FILE")"
        fi
    fi
}

# The values host B needs, from what this host was told (host A without an infra host).
share_values_from_answers() {
    local key var
    for key in APP_URL LB_ADDRESSES APP_HOSTS NFS_SERVER NFS_PATH DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_MIGRATE_USERNAME \
        DB_MIGRATE_PASSWORD REDIS_HOST REDIS_PORT REDIS_PASSWORD REDIS_SESSION_HOST REDIS_SESSION_PORT REDIS_SESSION_PASSWORD REDIS_PREFIX EXEMPT_NETWORKS; do
        var="A_$key"
        printf -v "W_$key" '%s' "${!var-}"
    done
    W_APP_KEY=$S_APP_KEY
}

# --- the infra host of the 10k profile ----------------------------------------------------------

install_infra() {
    STEP_TOTAL=10
    NO_APP_IMAGE=1
    step 1 "$(msg i_step_machine)"
    why "$(msg i_why_machine)"
    machine_checks
    existing_guard

    step 2 "$(msg i_step_questions)"
    why "$(msg i_why_questions_infra)"
    questions_infra
    findings_reset
    check_ports "$A_INFRA_DB_PORT" "$A_INFRA_VALKEY_PORT" "$A_INFRA_VALKEY_SESSIONS_PORT"
    if [[ $(findings_count error) -gt 0 ]]; then
        print_findings
        die "$(msg i_ports_taken)"
    fi
    local old_key="" old_db="" old_redis="" old_sessions="" old_root="" old_migrate=""
    if [[ $PARTIAL == 1 ]]; then
        old_db=$(env_get .env DB_PASSWORD || true)
        old_redis=$(env_get .env REDIS_PASSWORD || true)
        old_sessions=$(env_get .env REDIS_SESSION_PASSWORD || true)
        old_root=$(env_get db.env MARIADB_ROOT_PASSWORD || true)
        old_migrate=$(env_get db.env DB_PASSWORD || true)
        [[ ! -f $SHARED_ANSWERS ]] || old_key=$(env_get "$SHARED_ANSWERS" APP_KEY || true)
    fi
    say ""
    say "  $(msg i_summary_title)"
    summary_line PROFILE "10k-ha, $(msg i_role_name_infra)"
    summary_line "$(msg i_sum_folder)" "$DIR"
    summary_line "$(msg i_sum_project)" "$PROJECT"
    summary_line OPENHDID_VERSION "$VERSION"
    summary_line "$(msg i_sum_listen)" "$A_INFRA_BIND_IP ($A_INFRA_DB_PORT MariaDB, $A_INFRA_VALKEY_PORT Valkey, $A_INFRA_VALKEY_SESSIONS_PORT $(msg i_sum_sessions))"
    summary_value INFRA_ADDRESS
    summary_value APP_HOSTS
    summary_line "MariaDB" "$A_DB_DATABASE ($A_DB_USERNAME, $A_DB_MIGRATE_USERNAME)"
    summary_value APP_URL
    summary_value LB_ADDRESSES
    summary_line "NFS" "$A_NFS_SERVER:$A_NFS_PATH"
    summary_value REDIS_PREFIX
    summary_line APP_KEY "$(summary_secret APP_KEY "$old_key")"
    summary_line "DB_PASSWORD (.env)" "$(summary_secret DB_PASSWORD "$old_db")"
    summary_line "DB_PASSWORD (db.env)" "$(summary_secret DB_MIGRATE_PASSWORD "$old_migrate")"
    summary_line MARIADB_ROOT_PASSWORD "$(summary_secret MARIADB_ROOT_PASSWORD "$old_root")"
    summary_line REDIS_PASSWORD "$(summary_secret REDIS_PASSWORD "$old_redis")"
    summary_line REDIS_SESSION_PASSWORD "$(summary_secret REDIS_SESSION_PASSWORD "$old_sessions")"
    summary_line "$(msg i_sum_files)" "profiles/10k/infra.yaml, .env, db.env, db.env.example, TROUBLESHOOTING.md, $SHARED_ANSWERS"
    [[ $PARTIAL == 0 ]] || say "    $(msg i_sum_backup)"
    say "    $(msg i_sum_infra_start)"
    confirm

    step 3 "$(msg i_step_download)"
    why "$(msg i_why_download "$VERSION")"
    download_release profile-10k-infra.yaml db.env.example TROUBLESHOOTING.md
    done_ "$(msg i_download_done)"

    step 4 "$(msg i_step_images)"
    why "$(msg i_why_images_infra)"
    step_images profiles/10k/infra.yaml
    done_ "$(msg i_images_done)"

    step 5 "$(msg i_step_secrets)"
    why "$(msg i_why_secrets_infra)"
    local need=()
    [[ -n $old_key || -n ${A_APP_KEY:-} ]] || need+=(APP_KEY)
    [[ -n $old_db || -n ${A_DB_PASSWORD:-} ]] || need+=(DB_PASSWORD)
    [[ -n $old_migrate || -n ${A_DB_MIGRATE_PASSWORD:-} ]] || need+=(DB_MIGRATE_PASSWORD)
    [[ -n $old_root || -n ${A_MARIADB_ROOT_PASSWORD:-} ]] || need+=(MARIADB_ROOT_PASSWORD)
    [[ -n $old_redis || -n ${A_REDIS_PASSWORD:-} ]] || need+=(REDIS_PASSWORD)
    [[ -n $old_sessions || -n ${A_REDIS_SESSION_PASSWORD:-} ]] || need+=(REDIS_SESSION_PASSWORD)
    GEN_WITH_IMAGE=0 gen_secrets ${need[@]+"${need[@]}"}
    pick_secret APP_KEY "$old_key"
    pick_secret DB_PASSWORD "$old_db"
    pick_secret DB_MIGRATE_PASSWORD "$old_migrate"
    pick_secret MARIADB_ROOT_PASSWORD "$old_root"
    pick_secret REDIS_PASSWORD "$old_redis"
    pick_secret REDIS_SESSION_PASSWORD "$old_sessions"
    bundled_valkey_password REDIS_PASSWORD
    bundled_valkey_password REDIS_SESSION_PASSWORD
    done_ "$(msg i_secrets_done)"

    step 6 "$(msg i_step_env)"
    why "$(msg i_why_env_infra)"
    env_backup .env
    env_backup db.env
    local template="$STATE_DIR/.tmp-infra-env-$$"
    infra_env_template > "$template"
    env_reset
    RENDER_NOTE=""
    env_set OPENHDID_VERSION "$VERSION"
    env_set COMPOSE_PROJECT_NAME "$PROJECT"
    env_set COMPOSE_FILE profiles/10k/infra.yaml
    env_set OPENHDID_INFRA_BIND_IP "$A_INFRA_BIND_IP"
    env_set OPENHDID_INFRA_DB_PORT "$A_INFRA_DB_PORT"
    env_set OPENHDID_INFRA_VALKEY_PORT "$A_INFRA_VALKEY_PORT"
    env_set OPENHDID_INFRA_VALKEY_SESSIONS_PORT "$A_INFRA_VALKEY_SESSIONS_PORT"
    env_set DB_DATABASE "$A_DB_DATABASE"
    env_set DB_USERNAME "$A_DB_USERNAME"
    env_set DB_PASSWORD "$S_DB_PASSWORD"
    env_set REDIS_PASSWORD "$S_REDIS_PASSWORD"
    env_set REDIS_SESSION_PASSWORD "$S_REDIS_SESSION_PASSWORD"
    mark_env_written
    env_render "$template" .env
    rm -f "$template"
    info "$(msg i_env_written .env)"
    env_reset
    env_set MARIADB_ROOT_PASSWORD "$S_MARIADB_ROOT_PASSWORD"
    env_set DB_USERNAME "$A_DB_MIGRATE_USERNAME"
    env_set DB_PASSWORD "$S_DB_MIGRATE_PASSWORD"
    env_render db.env.example db.env
    info "$(msg i_env_written db.env)"
    done_ "$(msg i_env_done)"

    step 7 "$(msg i_step_config)"
    why "$(msg i_why_config_infra)"
    step_compose_config

    step 8 "$(msg i_step_start)"
    why "$(msg i_why_start_infra)"
    write_state
    step_start

    step 9 "$(msg i_step_check)"
    why "$(msg i_why_check_infra)"
    step_check
    W_APP_KEY=$S_APP_KEY
    W_APP_URL=${A_APP_URL%/}
    W_LB_ADDRESSES=$(list_commas "$A_LB_ADDRESSES")
    W_APP_HOSTS=$(list_commas "$A_APP_HOSTS")
    W_NFS_SERVER=$A_NFS_SERVER
    W_NFS_PATH=$A_NFS_PATH
    W_DB_HOST=$A_INFRA_ADDRESS
    W_DB_PORT=$A_INFRA_DB_PORT
    W_DB_DATABASE=$A_DB_DATABASE
    W_DB_USERNAME=$A_DB_USERNAME
    W_DB_PASSWORD=$S_DB_PASSWORD
    W_DB_MIGRATE_USERNAME=$A_DB_MIGRATE_USERNAME
    W_DB_MIGRATE_PASSWORD=$S_DB_MIGRATE_PASSWORD
    W_REDIS_HOST=$A_INFRA_ADDRESS
    W_REDIS_PORT=$A_INFRA_VALKEY_PORT
    W_REDIS_PASSWORD=$S_REDIS_PASSWORD
    W_REDIS_SESSION_HOST=$A_INFRA_ADDRESS
    W_REDIS_SESSION_PORT=$A_INFRA_VALKEY_SESSIONS_PORT
    W_REDIS_SESSION_PASSWORD=$S_REDIS_SESSION_PASSWORD
    W_REDIS_PREFIX=$A_REDIS_PREFIX
    write_answers_file

    step 10 "$(msg i_step_summary)"
    write_state
    final_summary
    next_steps_infra
}

# The infra host's own .env: only its keys, with Hungarian comments (the application's
# env.example would be 95 % noise there).
infra_env_template() {
    cat <<'EOF'
# OpenHDID – a kétgépes 10k profil infra gépe: MariaDB, fő Valkey és munkamenet-Valkey.
# A telepítő (install.sh) írta; a profiles/10k/infra.yaml olvassa. Jog: chmod 600 .env
# Módosítás után: docker compose up -d. Az adatbázis-rendszergazda és a migrációs fiók a db.env-ben.

# A Compose-projekt és -fájl (a konténerek és kötetek előtagja: openhdid-infra).
COMPOSE_PROJECT_NAME=openhdid-infra
COMPOSE_FILE=profiles/10k/infra.yaml
# Csak tájékoztató: a kiadás, amelynek telepítője ezt a gépet felépítette.
OPENHDID_VERSION=
# A gép privát címe, amelyen a három szolgáltatás figyel (0.0.0.0 = minden cím: kerülendő,
# a MariaDB és a Valkey itt titkosítatlanul beszél).
OPENHDID_INFRA_BIND_IP=
OPENHDID_INFRA_DB_PORT=3306
OPENHDID_INFRA_VALKEY_PORT=6379
OPENHDID_INFRA_VALKEY_SESSIONS_PORT=6380

# Az alkalmazás adatbázisa és futási fiókja (csak adatot olvas és ír); első induláskor jön létre.
DB_DATABASE=openhdid
DB_USERNAME=openhdid
DB_PASSWORD=

# A fő Valkey (sor, zárak, korlátszámlálók, gyorsítótár) és a munkamenet-Valkey jelszava.
REDIS_PASSWORD=
REDIS_SESSION_PASSWORD=

# Méretek (a profiles/10k/infra.yaml alapértékeit írják felül; a plafont a hozzá tartozó
# értékkel együtt kell emelni).
# OPENHDID_DB_BUFFER_POOL=4G
# OPENHDID_DB_MAX_CONNECTIONS=80
# OPENHDID_DB_MEM_LIMIT=6g
# OPENHDID_VALKEY_MAXMEMORY=256mb
# OPENHDID_VALKEY_MEM_LIMIT=768m
# OPENHDID_VALKEY_SESSIONS_MAXMEMORY=576mb
# OPENHDID_VALKEY_SESSIONS_MEM_LIMIT=1280m
EOF
}

# --- Kubernetes (20k) ---------------------------------------------------------------------------

install_k8s() {
    STEP_TOTAL=7
    local k8s="$DIR/k8s" old_key="" kube_path host
    step 1 "$(msg i_step_tools)"
    why "$(msg i_why_tools_k8s)"
    findings_reset
    check_host 20k refuse
    [[ $(findings_count error) == 0 ]] || { print_findings; die "$(msg i_machine_errors)"; }
    if command -v kubectl >/dev/null 2>&1; then info "$(msg i_tool_found kubectl)"; else info "$(msg i_tool_missing kubectl)"; fi
    if command -v git >/dev/null 2>&1; then info "$(msg i_tool_found git)"; else info "$(msg i_tool_missing git)"; fi
    PROJECT=openhdid
    if [[ -f $k8s/openhdid-env.env ]]; then
        old_key=$(env_get "$k8s/openhdid-env.env" APP_KEY || true)
        say "    $(msg i_k8s_existing)"
        confirm
    fi

    step 2 "$(msg i_step_questions)"
    why "$(msg i_why_questions_k8s)"
    questions_k8s
    host=$(url_host "$A_APP_URL")
    say ""
    say "  $(msg i_summary_title)"
    summary_line PROFILE 20k
    summary_line "$(msg i_sum_folder)" "$k8s"
    summary_line OPENHDID_VERSION "$VERSION"
    summary_value K8S_NAMESPACE
    summary_value APP_URL
    summary_value K8S_INGRESS_NAMESPACE
    summary_line "TRUSTED_PROXIES" "$A_K8S_POD_CIDR"
    summary_line "MariaDB" "$A_DB_HOST:$A_DB_PORT/$A_DB_DATABASE ($A_DB_USERNAME, $A_DB_MIGRATE_USERNAME)"
    summary_line "Valkey" "$A_REDIS_HOST:$A_REDIS_PORT, $(msg i_sum_sessions) $A_REDIS_SESSION_HOST:$A_REDIS_SESSION_PORT"
    summary_value REDIS_PREFIX
    summary_value K8S_STORAGE_CLASS
    summary_line APP_KEY "$(summary_secret APP_KEY "$old_key")"
    summary_line "$(msg i_sum_files)" "k8s/kustomization.yaml, k8s/openhdid-env.env, k8s/openhdid-db-env.env, k8s/$(msg i_steps_file)"
    say "    $(msg i_sum_k8s_nothing)"
    confirm

    step 3 "$(msg i_step_download)"
    why "$(msg i_why_download "$VERSION")"
    download_release env.example db.env.example UPGRADE.md TROUBLESHOOTING.md
    mkdir -p "$k8s"
    chmod 700 "$k8s"
    if [[ -n $FROM_DIR && -d $FROM_DIR/kubernetes ]]; then
        rm -rf "$k8s/kubernetes"
        cp -R "$FROM_DIR/kubernetes" "$k8s/kubernetes"
        kube_path=kubernetes
        info "$(msg i_k8s_copied)"
    else
        kube_path="openhdid-$VERSION/kubernetes"
        if [[ ! -d $k8s/$kube_path ]]; then
            info "$(msg i_k8s_clone)"
            info "    git clone --depth 1 --branch v$VERSION $REPO_URL $k8s/openhdid-$VERSION"
        fi
    fi
    done_ "$(msg i_download_done)"

    step 4 "$(msg i_step_secrets)"
    why "$(msg i_why_secrets_k8s)"
    if [[ -z $old_key && -z ${A_APP_KEY:-} ]]; then
        GEN_WITH_IMAGE=0 gen_secrets APP_KEY
    fi
    pick_secret APP_KEY "$old_key"
    done_ "$(msg i_secrets_done)"

    step 5 "$(msg i_step_k8s_files)"
    why "$(msg i_why_k8s_files)"
    local rendered="$STATE_DIR/.tmp-k8s-env-$$"
    env_reset
    env_set OPENHDID_VERSION "$VERSION"
    env_set OPENHDID_TLS off
    env_set TRUSTED_PROXIES "$(list_commas "$A_K8S_POD_CIDR")"
    env_set APP_KEY "$S_APP_KEY"
    env_set APP_URL "${A_APP_URL%/}"
    env_set DB_HOST "$A_DB_HOST"
    env_set DB_PORT "$A_DB_PORT"
    env_set DB_DATABASE "$A_DB_DATABASE"
    env_set DB_USERNAME "$A_DB_USERNAME"
    env_set DB_PASSWORD "$A_DB_PASSWORD"
    env_set REDIS_HOST "$A_REDIS_HOST"
    env_set REDIS_PORT "$A_REDIS_PORT"
    env_set REDIS_PASSWORD "$A_REDIS_PASSWORD"
    env_set REDIS_SESSION_HOST "$A_REDIS_SESSION_HOST"
    env_set REDIS_SESSION_PORT "$A_REDIS_SESSION_PORT"
    env_set REDIS_SESSION_PASSWORD "${A_REDIS_SESSION_PASSWORD:-}"
    env_set REDIS_PREFIX "$A_REDIS_PREFIX"
    env_set CACHE_PREFIX "${A_REDIS_PREFIX}cache-"
    env_set HDID_RATE_LIMIT_EXEMPT_NETWORKS "$(list_commas "${A_EXEMPT_NETWORKS:-}")"
    env_render env.example "$rendered"
    k8s_env_file "$rendered" "$k8s/openhdid-env.env"
    rm -f "$rendered"
    env_reset
    env_set DB_USERNAME "$A_DB_MIGRATE_USERNAME"
    env_set DB_PASSWORD "$A_DB_MIGRATE_PASSWORD"
    env_render db.env.example "$rendered"
    k8s_env_file "$rendered" "$k8s/openhdid-db-env.env" MARIADB_ROOT_PASSWORD
    rm -f "$rendered"
    write_kustomization "$k8s/kustomization.yaml" "$kube_path" "$host"
    k8s_steps "$host" > "$k8s/$(msg i_steps_file)"
    chmod 600 "$k8s/$(msg i_steps_file)" "$k8s/kustomization.yaml"
    info "$(msg i_k8s_written)"
    done_ "$(msg i_env_done)"

    step 6 "$(msg i_step_check)"
    why "$(msg i_why_check_k8s)"
    if command -v kubectl >/dev/null 2>&1 && [[ -d $k8s/$kube_path ]]; then
        local out
        if out=$(kubectl kustomize "$k8s" 2>&1 </dev/null); then
            done_ "$(msg i_kustomize_ok)"
        else
            say "$(redact_secrets "$out")"
            warn "$(msg i_kustomize_failed)"
        fi
    else
        info "$(msg i_kustomize_skipped)"
    fi

    step 7 "$(msg i_step_summary)"
    write_state
    final_summary
    say ""
    say "  $(msg i_next_title)"
    k8s_steps "$host"
}

# An env file for "kubectl create secret --from-env-file": no comments, no quotes (kubectl would
# keep them as part of the value), ${APP_NAME} expanded, no Compose-only keys.
k8s_env_file() {
    local src=$1 dst=$2 skip=" ${3:-} " line key value app_name
    app_name=$(env_get "$src" APP_NAME || echo OpenHDID)
    {
        while IFS= read -r line || [[ -n $line ]]; do
            [[ $line =~ $ENV_KEY_RE ]] || continue
            key=${BASH_REMATCH[1]}
            case $key in COMPOSE_* | OPENHDID_NFS_*) continue ;; esac
            [[ $skip != *" $key "* ]] || continue
            value=${line#*=}
            if [[ ${#value} -ge 2 && ( ( $value == \"*\" ) || ( $value == \'*\' ) ) ]]; then
                value=${value:1:${#value}-2}
            fi
            # shellcheck disable=SC2016 # the literal text ${APP_NAME} of env.example
            value=${value//'${APP_NAME}'/$app_name}
            printf '%s=%s\n' "$key" "$value"
        done < "$src"
    } > "$dst.tmp.$$"
    chmod 600 "$dst.tmp.$$"
    mv -f "$dst.tmp.$$" "$dst"
}

write_kustomization() {
    local file=$1 kube=$2 host=$3
    {
        printf '# OpenHDID 20k – a telepítő (install.sh %s) saját overlay-e; a fürtön semmit nem változtat.\n' "$OPENHDID_RELEASE"
        printf '# Építés: kubectl kustomize k8s   Alkalmazás: lásd %s\n' "$(msg i_steps_file)"
        printf 'apiVersion: kustomize.config.k8s.io/v1beta1\nkind: Kustomization\n\n'
        printf 'namespace: %s\n\n' "$A_K8S_NAMESPACE"
        printf 'resources:\n  - %s/overlays/20k\n\n' "$kube"
        printf 'images:\n  - name: %s\n    newTag: "%s"\n\n' "$APP_IMAGE" "$VERSION"
        printf 'patches:\n'
        printf '  - target: {kind: Ingress, name: openhdid}\n    patch: |-\n'
        printf '      - op: replace\n        path: /spec/tls/0/hosts/0\n        value: %s\n' "$host"
        printf '      - op: replace\n        path: /spec/rules/0/host\n        value: %s\n' "$host"
        if [[ $A_K8S_INGRESS_NAMESPACE != ingress-nginx ]]; then
            printf '  - target: {kind: NetworkPolicy, name: openhdid-web}\n    patch: |-\n'
            printf '      - op: replace\n        path: /spec/ingress/0/from/0/namespaceSelector/matchLabels/kubernetes.io~1metadata.name\n        value: %s\n' "$A_K8S_INGRESS_NAMESPACE"
        fi
        if [[ -n $A_K8S_STORAGE_CLASS ]]; then
            printf '  - target: {kind: PersistentVolumeClaim, name: openhdid-storage}\n    patch: |-\n'
            printf '      - op: add\n        path: /spec/storageClassName\n        value: %s\n' "$A_K8S_STORAGE_CLASS"
        fi
    } > "$file.tmp.$$"
    chmod 600 "$file.tmp.$$"
    mv -f "$file.tmp.$$" "$file"
}

k8s_steps() {
    local host=$1 ns=$A_K8S_NAMESPACE cert=${A_TLS_CERT:-<fullchain.pem>} key=${A_TLS_KEY:-<privkey.pem>}
    say "$(msg i_k8s_steps_intro)"
    say "  1. kubectl create namespace $ns"
    say "  2. kubectl -n $ns create secret generic openhdid-env --from-env-file=k8s/openhdid-env.env"
    say "  3. kubectl -n $ns create secret generic openhdid-db-env --from-env-file=k8s/openhdid-db-env.env"
    say "  4. kubectl -n $ns create secret tls openhdid-tls --cert=$cert --key=$key"
    if [[ -n ${A_K8S_CA_DIR:-} ]]; then
        say "  5. kubectl -n $ns create configmap openhdid-ca --from-file=$A_K8S_CA_DIR/"
    else
        say "  5. $(msg i_k8s_no_ca)"
    fi
    say "  6. kubectl kustomize k8s | kubectl apply -l app.kubernetes.io/component=migrate -f -"
    say "  7. kubectl -n $ns wait --for=condition=complete job/openhdid-migrate --timeout=600s"
    say "  8. kubectl apply -k k8s"
    say "  9. kubectl -n $ns rollout status deploy/openhdid-web --timeout=600s"
    say " 10. kubectl -n $ns exec -it deploy/openhdid-web -- php artisan hdid:make-admin ${A_ADMIN_EMAIL:-<e-mail>}"
    say " 11. $(msg i_k8s_dns "$host")"
    say "     $(msg g_docref "$(msg g_doc_guide), 15.5")"
}

# --- the end of an installation ----------------------------------------------------------------

final_summary() {
    local file mode
    say ""
    say "  $(msg i_final_title)"
    if [[ -n $ROLE ]]; then
        summary_line PROFILE "$PROFILE, $(msg "i_role_name_$ROLE")"
    else
        summary_line PROFILE "$PROFILE"
    fi
    summary_line "$(msg i_sum_folder)" "$DIR"
    summary_line "$(msg i_sum_project)" "$PROJECT"
    summary_line OPENHDID_VERSION "$VERSION"
    for file in .env db.env "$SHARED_ANSWERS" k8s/openhdid-env.env k8s/openhdid-db-env.env; do
        [[ -f $file ]] || continue
        mode=$(file_mode "$file")
        summary_line "$file" "$mode"
    done
    if [[ -n ${CHECK_RESULT:-} ]]; then
        summary_line "$(msg i_sum_check)" "$(msg i_check_code "$CHECK_RESULT")"
    fi
    summary_line "$(msg i_sum_log)" ".install/$(basename "$LOG_FILE")"
    [[ $WARNINGS == 0 ]] || summary_line "$(msg i_sum_warnings)" "$WARNINGS"
}

firewall_lines() {
    local source=$1
    shift
    local port
    for port in "$@"; do
        if [[ -n $source ]]; then
            local item
            for item in ${source//,/ }; do
                say "        sudo firewall-cmd --permanent --add-rich-rule='rule family=ipv4 source address=$item port port=$port protocol=tcp accept'"
                say "        sudo ufw allow from $item to any port $port proto tcp"
            done
        else
            say "        sudo firewall-cmd --permanent --add-port=$port/tcp"
            say "        sudo ufw allow $port/tcp"
        fi
    done
    say "        sudo firewall-cmd --reload"
}

next_steps_common_tail() {
    local n=$1
    say "  $n. $(msg i_next_integrations)"
    say "        docker compose exec web php artisan hdid:test-mail <e-mail> --now"
    say "        docker compose exec web php artisan hdid:test-sms <36…> --now"
    n=$((n + 1))
    say "  $n. $(msg i_next_api_key)"
    say "        docker compose exec web php artisan hdid:api-key:create <név> callcenter"
    n=$((n + 1))
    say "  $n. $(msg i_next_backup)"
    say "        ( crontab -l 2>/dev/null; echo '30 2 * * * $DIR/backup.sh --quiet' ) | crontab -"
    n=$((n + 1))
    say "  $n. $(msg i_next_check)"
    say "        ./install.sh check"
}

next_steps_single() {
    local host n=1
    host=$(url_host "$A_APP_URL")
    say ""
    say "  $(msg i_next_title)"
    if [[ ${ADMIN_PENDING:-0} == 1 ]]; then
        say "  $n. $(msg i_next_admin)"
        say "        docker compose exec web php artisan hdid:make-admin ${A_ADMIN_EMAIL:-<e-mail>} --name=\"${A_ADMIN_NAME:-$(msg i_admin_default_name)}\""
        n=$((n + 1))
    fi
    if [[ $PROFILE == trial ]]; then
        say "  $n. $(msg i_next_trial "$A_APP_URL")"
        n=$((n + 1))
        say "  $n. $(msg i_next_trial_end)"
        say "        docker compose down -v"
        return 0
    fi
    say "  $n. $(msg i_next_dns "$host")"
    n=$((n + 1))
    if [[ $A_TLS_MODE == on ]]; then
        say "  $n. $(msg i_next_tls)"
        say "        ./install.sh fix --only TLS-02"
        say "        docker compose exec web openhdid reload"
        n=$((n + 1))
    elif [[ $A_TLS_MODE == off ]]; then
        say "  $n. $(msg i_next_tls_off "$(list_commas "$A_LB_ADDRESSES")")"
        n=$((n + 1))
    fi
    say "  $n. $(msg i_next_firewall)"
    if [[ $A_TLS_MODE == off ]]; then
        firewall_lines "$A_LB_ADDRESSES" "$A_HTTP_PORT"
    else
        firewall_lines "" "$A_HTTPS_PORT" "$A_HTTP_PORT"
    fi
    n=$((n + 1))
    next_steps_common_tail "$n"
}

next_steps_app() {
    local host n=1 answers=$SHARED_ANSWERS
    host=$(url_host "$A_APP_URL")
    [[ -z $ANSWERS_FILE ]] || answers=$(basename "$ANSWERS_FILE")
    say ""
    say "  $(msg i_next_title)"
    if [[ ${ADMIN_PENDING:-0} == 1 ]]; then
        say "  $n. $(msg i_next_admin)"
        say "        docker compose exec web php artisan hdid:make-admin ${A_ADMIN_EMAIL:-<e-mail>}"
        n=$((n + 1))
    fi
    if [[ $ROLE == a ]]; then
        say "  $n. $(msg i_next_install_b)"
        say "        scp $answers install.sh <B>:/opt/openhdid/  &&  ssh <B> chmod 600 /opt/openhdid/$answers"
        say "        ./install.sh --answers $answers --role b"
        n=$((n + 1))
    fi
    say "  $n. $(msg i_next_lb)"
    print_haproxy "$host"
    n=$((n + 1))
    say "  $n. $(msg i_next_dns_lb "$host")"
    n=$((n + 1))
    say "  $n. $(msg i_next_firewall_app "$A_HTTP_PORT")"
    firewall_lines "$A_LB_ADDRESSES" "$A_HTTP_PORT"
    n=$((n + 1))
    say "  $n. $(msg i_next_nfs_check)"
    say "        showmount -e $A_NFS_SERVER"
    n=$((n + 1))
    if [[ -f $DIR/$SHARED_ANSWERS || ( -n $ANSWERS_FILE && -f $ANSWERS_FILE ) ]]; then
        say "  $n. $(msg i_next_answers_delete)"
        say "        rm $answers"
        n=$((n + 1))
    fi
    if [[ $ROLE == b ]]; then
        say "  $n. $(msg i_next_check)"
        say "        ./install.sh check"
        return 0
    fi
    next_steps_common_tail "$n"
}

print_haproxy() {
    local host=$1 a="<A>" b="<B>" item i=0 hosts=${A_APP_HOSTS:-${W_APP_HOSTS:-}}
    for item in ${hosts//,/ }; do
        i=$((i + 1))
        [[ $i != 1 ]] || a=$item
        [[ $i != 2 ]] || b=$item
    done
    say "        $(msg i_haproxy_note)"
    say "        frontend openhdid"
    say "            bind :443 ssl crt /etc/haproxy/certs/$host.pem alpn h2,http/1.1 ssl-min-ver TLSv1.3"
    say "            bind :80"
    say "            http-request redirect scheme https code 308 unless { ssl_fc }"
    say "            http-request del-header X-Forwarded-For"
    say "            http-request set-header X-Forwarded-Proto https"
    say "            option forwardfor"
    say "            timeout client 60s"
    say "            default_backend openhdid_app"
    say "        backend openhdid_app"
    say "            balance roundrobin"
    say "            timeout connect 5s"
    say "            timeout server 330s"
    say "            option httpchk"
    say "            http-check send meth GET uri /up ver HTTP/1.1 hdr Host $host"
    say "            http-check expect status 200"
    say "            server a $a:${A_HTTP_PORT:-80} check inter 5s fall 3 rise 2"
    say "            server b $b:${A_HTTP_PORT:-80} check inter 5s fall 3 rise 2"
    say "        $(msg i_haproxy_trust)"
    say "        $(msg g_docref "$(msg g_doc_guide), 6.5, 15.4")"
}

next_steps_infra() {
    local n=1 hosts=${A_APP_HOSTS:-} a="<A>" b="<B>" item i=0 path=$A_NFS_PATH
    for item in ${hosts//,/ }; do
        i=$((i + 1))
        [[ $i != 1 ]] || a=$item
        [[ $i != 2 ]] || b=$item
    done
    say ""
    say "  $(msg i_next_title)"
    say "  $n. $(msg i_next_copy_answers "$SHARED_ANSWERS")"
    say "        scp $SHARED_ANSWERS <A>:/opt/openhdid/  &&  scp $SHARED_ANSWERS <B>:/opt/openhdid/"
    say "        ssh <A> chmod 600 /opt/openhdid/$SHARED_ANSWERS  &&  ssh <B> chmod 600 /opt/openhdid/$SHARED_ANSWERS"
    say "        $(msg i_next_then_a_b)"
    say "        ./install.sh --answers $SHARED_ANSWERS --role a     (A)"
    say "        ./install.sh --answers $SHARED_ANSWERS --role b     (B)"
    say "        rm $SHARED_ANSWERS     ($(msg i_next_all_three))"
    n=$((n + 1))
    say "  $n. $(msg i_next_firewall_infra)"
    firewall_lines "$(list_commas "$hosts")" "$A_INFRA_DB_PORT" "$A_INFRA_VALKEY_PORT" "$A_INFRA_VALKEY_SESSIONS_PORT"
    n=$((n + 1))
    if [[ $A_NFS_SERVER == "$A_INFRA_ADDRESS" ]]; then
        say "  $n. $(msg i_next_nfs)"
    else
        say "  $n. $(msg i_next_nfs_other "$A_NFS_SERVER")"
    fi
    say "        sudo mkdir -p $path"
    say "        sudo chown 82:82 $path        # $(msg i_nfs_rootless)"
    say "        sudo chmod 750 $path"
    say "        echo '$path $a(rw,sync,no_subtree_check,root_squash) $b(rw,sync,no_subtree_check,root_squash)' | sudo tee -a /etc/exports"
    say "        sudo exportfs -ra"
    firewall_lines "$(list_commas "$hosts")" 2049
    say "        showmount -e $A_NFS_SERVER      # $(msg i_nfs_check_on_ab)"
    say "        $(msg g_docref "$(msg g_doc_guide), 15.8")"
    n=$((n + 1))
    say "  $n. $(msg i_next_backup_infra)"
    say "        docker compose exec -T mariadb sh -c 'MYSQL_PWD=\"\$DB_PASSWORD\" exec mariadb-dump -u\"\$DB_USERNAME\" --single-transaction --no-tablespaces --default-character-set=utf8mb4 \"\$MARIADB_DATABASE\"' | gzip > openhdid-\$(date +%F).sql.gz"
    n=$((n + 1))
    say "  $n. $(msg i_next_replica)"
    say "        $(msg i_replica_1)"
    say "        $(msg i_replica_2)"
    say "            services:"
    say "              mariadb:"
    say "                command: [--server-id=1, --log-bin=mariadb-bin, --binlog-format=ROW]   # $(msg i_replica_replica_id)"
    say "        $(msg i_replica_3)"
    say "            docker compose exec mariadb mariadb -uroot -p"
    say "            CREATE USER 'repl'@'<$(msg i_replica_address)>' IDENTIFIED BY '<REPL_JELSZÓ>';"
    say "            GRANT REPLICATION SLAVE ON *.* TO 'repl'@'<$(msg i_replica_address)>';"
    say "        $(msg i_replica_4)"
    say "            docker compose exec -T mariadb sh -c 'MYSQL_PWD=\"\$MARIADB_ROOT_PASSWORD\" exec mariadb-dump -uroot --all-databases --single-transaction --master-data=2 --gtid' | gzip > replika-alap.sql.gz"
    say "            gunzip -c replika-alap.sql.gz | docker compose exec -T mariadb sh -c 'MYSQL_PWD=\"\$MARIADB_ROOT_PASSWORD\" exec mariadb -uroot'     # $(msg i_replica_on_replica)"
    say "        $(msg i_replica_5)"
    say "            CHANGE MASTER TO MASTER_HOST='$A_INFRA_ADDRESS', MASTER_PORT=$A_INFRA_DB_PORT, MASTER_USER='repl', MASTER_PASSWORD='<REPL_JELSZÓ>', MASTER_USE_GTID=slave_pos;"
    say "            START SLAVE;"
    say "            SHOW SLAVE STATUS\\G"
    say "        $(msg i_replica_6)"
    say "        $(msg g_docref "$(msg g_doc_guide), 15.8")"
    n=$((n + 1))
    say "  $n. $(msg i_next_check)"
    say "        ./install.sh check"
}

cmd_install() {
    refuse_root
    prepare_state_dir
    lock_state
    say "$(msg i_banner "$OPENHDID_RELEASE" "$DIR")"
    [[ $YES == 0 || -n $ANSWERS_FILE ]] || die "$(msg i_yes_needs_answers)"
    [[ -z $FROM_DIR || -d $FROM_DIR ]] || die "$(msg i_from_missing "$FROM_DIR")"
    [[ -z $FROM_DIR ]] || FROM_DIR=$(cd -- "$FROM_DIR" && pwd -P)
    [[ -z $ANSWERS_FILE ]] || load_answers "$ANSWERS_FILE"
    choose_profile
    choose_role
    resolve_version
    KIND=$(kind_of)
    case $KIND in
        trial | 5k | 10k-single) install_single ;;
        app) install_app ;;
        infra) install_infra ;;
        20k) install_k8s ;;
    esac
    say ""
    if [[ $WARNINGS -gt 0 ]]; then
        say "$(msg i_finished_warnings "$WARNINGS")"
        exit 1
    fi
    say "$(msg i_finished)"
    exit 0
}

# ===== check ======================================================================================
# The full check: every finding with its id, severity, cause, advice, the exact command and the
# TROUBLESHOOTING.md reference; --json for machines. Read-only: it changes nothing, and every
# command it runs only reads (docker compose config/ps/logs/port, exec of read-only commands,
# one-off containers of check-env, tls-check and migrate:status).

CK_ENVF=.env
CK_PROFILE=""
CK_ROLE=""
CK_KIND=""
CK_VERSION=""
CK_DOCKER=0
CK_COMPOSE=0
CK_SERVICES=" "
CK_RUNNING=" "
CK_PRESENT=" "
CK_NOW=0
CK_APP_IMAGE_MISSING=0

# The released files of an installation and their release asset names.
RELEASE_FILES="compose.yaml backup.sh install.sh profiles/5k/compose.yaml profiles/10k/compose.yaml profiles/10k/single.yaml profiles/10k/infra.yaml"
# Keys of earlier releases that no longer do anything (FIX-ENV-OBSOLETE comments them out).
REMOVED_KEYS=" USER_ENTRA_MOBILE_CLIENT_ID "

ev() { env_get "$CK_ENVF" "$1" 2>/dev/null || true; }
ev_or() {
    local v
    v=$(ev "$1")
    printf '%s' "${v:-$2}"
}
in_words() { [[ " $2 " == *" $1 "* ]]; }
configured() { in_words "$1" "$CK_SERVICES"; }
running() { in_words "$1" "$CK_RUNNING"; }
has_finding() {
    local i=0
    while [[ $i -lt ${#F_ID[@]} ]]; do
        [[ ${F_ID[$i]} == "$1" ]] && return 0
        i=$((i + 1))
    done
    return 1
}
has_error() {
    local i=0
    while [[ $i -lt ${#F_ID[@]} ]]; do
        [[ ${F_ID[$i]} == "$1" && ${F_SEV[$i]} == error ]] && return 0
        i=$((i + 1))
    done
    return 1
}
# A check that cannot run because an earlier one failed: an information, naming that one.
skipped() { finding "$1" info "$2" "$(msg c_skipped "$3")" "" "" "" ""; }
doc_guide() { msg g_doc_guide; }
doc_ops() { msg c_doc_ops; }
file_owner() { stat -c '%u' "$1" 2>/dev/null || stat -f '%u' "$1"; }
file_mtime() { stat -c '%Y' "$1" 2>/dev/null || stat -f '%m' "$1"; }
# An RFC 3339 time of Docker (2026-10-26T10:00:00.123456789Z) in epoch seconds (GNU, then BSD date).
iso_to_epoch() {
    local base=${1%%.*}
    base=${base%Z}
    [[ $base =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}$ && $base != 0001-* ]] || return 1
    date -u -d "$base" +%s 2>/dev/null || date -u -j -f '%Y-%m-%dT%H:%M:%S' "$base" +%s 2>/dev/null
}
# The first N lines of a text, joined by " | ".
first_lines() {
    local n=$1 text=$2 line out="" i=0
    while IFS= read -r line; do
        [[ -n ${line//[[:space:]]/} ]] || continue
        i=$((i + 1))
        [[ $i -le $n ]] || break
        out="$out${out:+ | }$line"
    done <<<"$text"
    printf '%s' "$out"
}
# The last N lines of a text, joined by " | ".
last_lines() {
    local n=$1 text=$2 line lines=() i out=""
    while IFS= read -r line; do
        [[ -n ${line//[[:space:]]/} ]] && lines+=("$line")
    done <<<"$text"
    i=$((${#lines[@]} - n))
    [[ $i -ge 0 ]] || i=0
    while [[ $i -lt ${#lines[@]} ]]; do
        out="$out${out:+ | }${lines[$i]}"
        i=$((i + 1))
    done
    printf '%s' "$out"
}
# The host of an URL (https://user:pw@host:port/path → host).
host_of() {
    local h=${1#*://}
    h=${h%%/*}
    h=${h##*@}
    printf '%s' "${h%%:*}"
}
is_ipv4() { [[ $1 =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; }

# --- redaction ----------------------------------------------------------------------------------
# One function masks secrets in everything the check, the fix and the report show or keep:
# (1) the value of every secret-looking key (KEY=value, KEY: value), (2) every secret value of
# .env, db.env, the answers file and the k8s env files wherever it appears, (3) common patterns
# (user:password@ in URLs, Bearer, JWT, password=, "password":"…", requirepass). Logs also lose
# personal data (redact_personal). Secrets never reach a command line: the texts go through bash
# and through sed's input only.

REDACT_READY=0
REDACT_VALUES=()
REDACT_LINE_RE='^([[:space:]]*-?[[:space:]]*)([A-Za-z_][A-Za-z0-9_]*)(=|:[[:space:]]+)(.+)$'
SECRET_KEY_RE='(^|_)(PASSWORD|PASS|PASSWD|SECRET|TOKEN|KEY|HMAC|CREDENTIAL|CREDENTIALS|PRIVATE)(_|$)'
secret_key() {
    local key
    key=$(tr '[:lower:]' '[:upper:]' <<<"$1")
    case $key in
        # Paths, addresses and settings named after a secret are not one.
        *_FILE | *_URL | *_KEEP_ALIVE | *_EXPIRATION | *_TIMEOUT | *_TTL | *_MINUTES | *_HOURS | *_DAYS | *_LIFETIME | *_LENGTH | *_ALGORITHM | OPENHDID_TLS_KEY | OPENHDID_TLS_CERT) return 1 ;;
    esac
    [[ $key =~ $SECRET_KEY_RE ]]
}
redact_collect() {
    local file=$1 line key value
    [[ -f $file && -r $file ]] || return 0
    while IFS= read -r line || [[ -n $line ]]; do
        [[ $line =~ ^([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]] || continue
        key=${BASH_REMATCH[1]}
        value=${BASH_REMATCH[2]}
        secret_key "$key" || continue
        if [[ ${#value} -ge 2 && ( $value == \"*\" || $value == \'*\' ) ]]; then value=${value:1:${#value}-2}; fi
        [[ ${#value} -ge 6 ]] && REDACT_VALUES+=("$value")
    done < "$file"
    return 0
}
redact_init() {
    local file
    REDACT_VALUES=()
    for file in .env db.env "$SHARED_ANSWERS" k8s/openhdid-env.env k8s/openhdid-db-env.env ${ANSWERS_FILE:+"$ANSWERS_FILE"}; do
        redact_collect "$file"
    done
    REDACT_READY=1
}
# shellcheck disable=SC2016 # sed expressions
REDACT_SED=(
    -e 's#(://)[^/@[:space:]:]+:[^/@[:space:]]+@#\1***@#g'
    -e 's/(Bearer[[:space:]]+)[A-Za-z0-9._~+\/=-]+/\1***/g'
    -e 's/eyJ[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]*/***JWT***/g'
    -e 's/("([Pp]assword|PASSWORD|passwd|[Ss]ecret|SECRET|[Tt]oken|TOKEN|access_token|refresh_token|id_token|api_key|client_secret)"[[:space:]]*:[[:space:]]*")[^"]*"/\1***"/g'
    -e 's/(^|[?&[:space:]])(([Pp]assword|passwd|[Tt]oken|[Ss]ecret|api_key|client_secret)=)[^&[:space:]"]+/\1\2***/g'
    -e 's/(requirepass[[:space:]]+)"[^"]*"/\1"***"/g'
    -e 's/(requirepass[[:space:]]+)[^"[:space:]]+/\1***/g'
)
redact_text() {
    local text=$1 v line out="" key value prefix sep
    [[ $REDACT_READY == 1 ]] || redact_init
    while IFS= read -r line || [[ -n $line ]]; do
        if [[ $line =~ $REDACT_LINE_RE ]]; then
            prefix=${BASH_REMATCH[1]}
            key=${BASH_REMATCH[2]}
            sep=${BASH_REMATCH[3]}
            value=${BASH_REMATCH[4]}
            if secret_key "$key"; then
                if [[ ${#value} -ge 2 && ( $value == \"*\" || $value == \'*\' ) ]]; then value=${value:1:${#value}-2}; fi
                line="$prefix$key$sep***($(msg r_chars "${#value}"))"
            fi
        fi
        out="$out$line"$'\n'
    done <<<"$text"
    for v in ${REDACT_VALUES[@]+"${REDACT_VALUES[@]}"}; do
        out=${out//"$v"/***}
    done
    out=$(sed -E "${REDACT_SED[@]}" <<<"$out")
    printf '%s' "$out"
}
# Personal data in log lines: the last octet of an IPv4 address, an IPv6 address after its first
# three groups, the local part of an e-mail address after its first letter, a phone number after
# its first five digits.
redact_personal() {
    sed -E \
        -e 's/(^|[^0-9.])([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})\.[0-9]{1,3}($|[^0-9.])/\1\2.x\3/g' \
        -e 's/([0-9A-Fa-f]{1,4}:[0-9A-Fa-f]{1,4}:[0-9A-Fa-f]{1,4}):[0-9A-Fa-f:]{2,}/\1:.../g' \
        -e 's/([A-Za-z0-9])[A-Za-z0-9._%+-]*@([A-Za-z0-9-]+\.[A-Za-z0-9.-]*[A-Za-z])/\1***@\2/g' \
        -e 's/(\+?[0-9]{5})[0-9]{4,10}/\1*****/g' <<<"$1"
}

# --- what is installed here ------------------------------------------------------------------------

ck_detect() {
    local state="$STATE_DIR/state" cf profiles
    CK_ENVF=.env
    CK_PROFILE=""
    CK_ROLE=""
    if [[ -f $state ]]; then
        CK_PROFILE=$(env_get "$state" profile || true)
        CK_ROLE=$(env_get "$state" role || true)
    fi
    if [[ -z $CK_PROFILE ]]; then
        if [[ -f .env ]]; then
            cf=$(ev COMPOSE_FILE)
            profiles=$(ev COMPOSE_PROFILES)
            case ":$cf:" in
                *:profiles/10k/infra.yaml:*) CK_PROFILE=10k-ha CK_ROLE=infra ;;
                *:profiles/10k/compose.yaml:*)
                    CK_PROFILE=10k-ha
                    if [[ ,${profiles// /}, == *,primary,* ]]; then CK_ROLE=a; else CK_ROLE=b; fi
                    ;;
                *:profiles/10k/single.yaml:*) CK_PROFILE=10k-single ;;
                *:profiles/5k/compose.yaml:*) CK_PROFILE=5k ;;
                *) CK_PROFILE=trial ;;
            esac
        elif [[ -f k8s/kustomization.yaml ]]; then
            CK_PROFILE=20k
        fi
    fi
    case $CK_PROFILE in
        trial | 5k | 10k-single) CK_KIND=$CK_PROFILE CK_ROLE=single ;;
        10k-ha) [[ $CK_ROLE == infra ]] && CK_KIND=infra || CK_KIND=app ;;
        20k) CK_KIND=20k CK_ROLE=k8s CK_ENVF=k8s/openhdid-env.env ;;
        *) CK_KIND="" ;;
    esac
    if [[ $CK_PROFILE == 10k-ha && $CK_ROLE != a && $CK_ROLE != b && $CK_ROLE != infra ]]; then CK_ROLE=b; fi
    CK_VERSION=$(ev OPENHDID_VERSION)
    if [[ $CK_KIND == 20k && -z $CK_VERSION ]]; then
        CK_VERSION=$(env_get k8s/kustomization.yaml OPENHDID_VERSION 2>/dev/null || true)
    fi
    return 0
}

ck_label() {
    case $CK_PROFILE in
        10k-ha) msg "c_prof_10k_ha_$CK_ROLE" ;;
        '') msg c_prof_none ;;
        *) msg "c_prof_${CK_PROFILE//-/_}" ;;
    esac
}

# --- files and Compose (FILES-*) ---------------------------------------------------------------------

ck_files() {
    local main=compose.yaml file mode owner bad="" cf part missing="" fix="" sums name expected actual differs="" newest="" age
    [[ $CK_KIND == infra ]] && main=profiles/10k/infra.yaml
    if [[ -f $main ]]; then check_ok; else
        finding FILES-01 error files "$(msg c_files01_t "$main")" "" "$(msg c_files01_a)" "UPGRADE.md 3." ""
    fi
    if [[ -f db.env ]]; then check_ok; else
        finding FILES-02 error files "$(msg c_files02_t db.env)" "$(msg c_files02_d)" "$(msg c_files02_a)" "$(doc_guide), 3." "" "./backup.sh list"
    fi
    for file in .env db.env; do
        [[ -f $file ]] || continue
        mode=$(file_mode "$file")
        owner=$(file_owner "$file")
        if [[ $mode != 600 || ! -O $file ]]; then
            bad="$bad${bad:+, }$file $mode (uid $owner)"
        fi
    done
    if [[ -n $bad ]]; then
        finding FILES-03 warning files "$(msg c_files03_t)" "$bad" "$(msg c_files03_a)" "TROUBLESHOOTING.md 6." FIX-PERMS "chmod 600 .env db.env"
    else
        check_ok
    fi
    cf=$(ev COMPOSE_FILE)
    if [[ -n $cf ]]; then
        local parts=()
        IFS=: read -r -a parts <<<"$cf"
        for part in ${parts[@]+"${parts[@]}"}; do
            [[ -z $part || -f $part ]] || missing="$missing${missing:+ }$part"
        done
        if [[ -n $missing ]]; then
            for part in $missing; do asset_of "$part" >/dev/null && fix=FIX-PROFILE-FILE; done
            finding FILES-04 error files "$(msg c_files04_t)" "$(msg c_files04_d "$missing")" "$(msg c_files04_a "$CK_VERSION")" "TROUBLESHOOTING.md 15." "$fix"
        else
            check_ok
        fi
        if [[ -f compose.override.yaml && ":$cf:" != *":compose.override.yaml:"* ]]; then
            finding FILES-05 warning files "$(msg c_files05_t)" "$(msg c_files05_d "$cf")" "$(msg c_files05_a)" "TROUBLESHOOTING.md 7." FIX-OVERRIDE
        else
            check_ok
        fi
    fi
    sums="$STATE_DIR/SHA256SUMS-$CK_VERSION"
    if [[ -n $CK_VERSION && -f $sums ]]; then
        for file in $RELEASE_FILES; do
            [[ -f $file ]] || continue
            name=$(asset_of "$file") || continue
            expected=$(sum_of "$sums" "$name") || continue
            actual=$(sha_of "$file")
            [[ $actual == "$expected" ]] || differs="$differs${differs:+, }$file"
        done
        if [[ -n $differs ]]; then
            finding FILES-07 info files "$(msg c_files07_t)" "$(msg c_files07_d "$differs" "$CK_VERSION")" "$(msg c_files07_a)" "UPGRADE.md 3." ""
        else
            check_ok
        fi
    elif [[ $CK_KIND != 20k ]]; then
        finding FILES-07 info files "$(msg c_files07_t)" "$(msg c_files07_none "${CK_VERSION:-?}")" "$(msg c_files07_a)" "UPGRADE.md 3." ""
    fi
    if [[ -f $SHARED_ANSWERS ]]; then
        finding FILES-08 warning files "$(msg c_files08_t)" "$(msg c_files08_d "$SHARED_ANSWERS")" "$(msg c_files08_a)" "$(doc_guide), 15.8" "" "rm $SHARED_ANSWERS"
    else
        check_ok
    fi
    if [[ $CK_KIND != infra && $CK_ROLE != b && $CK_KIND != 20k ]]; then
        if [[ ! -x backup.sh ]]; then
            finding FILES-09 warning files "$(msg c_files09_t)" "$(msg c_files09_none_script)" "$(msg c_files09_a)" "$(doc_ops), 7." ""
        else
            for file in backup/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]_*; do
                [[ -d $file ]] && newest=$file
            done
            if [[ -z $newest ]]; then
                finding FILES-09 warning files "$(msg c_files09_t)" "$(msg c_files09_never)" "$(msg c_files09_a)" "$(doc_ops), 7." "" "./backup.sh" "30 2 * * * $DIR/backup.sh --quiet"
            else
                name=${newest#backup/}
                age=$(iso_to_epoch "${name:0:10}T${name:11:2}:${name:13:2}:${name:15:2}" || true)
                if [[ -n $age && $((CK_NOW - age)) -gt $((2 * 86400)) ]]; then
                    finding FILES-09 warning files "$(msg c_files09_t)" "$(msg c_files09_old "$name")" "$(msg c_files09_a)" "$(doc_ops), 7." "" "./backup.sh" "30 2 * * * $DIR/backup.sh --quiet"
                else
                    check_ok
                fi
            fi
        fi
    fi
    return 0
}

# The release asset of an installed file (compose.yaml → compose.yaml, profiles/5k/compose.yaml → profile-5k.yaml).
asset_of() {
    case $1 in
        compose.yaml | backup.sh | install.sh) printf '%s' "$1" ;;
        profiles/5k/compose.yaml) printf profile-5k.yaml ;;
        profiles/10k/compose.yaml) printf profile-10k.yaml ;;
        profiles/10k/single.yaml) printf profile-10k-single.yaml ;;
        profiles/10k/infra.yaml) printf profile-10k-infra.yaml ;;
        *) return 1 ;;
    esac
}

ck_compose() {
    local out
    CK_COMPOSE=0
    if [[ ! -f compose.yaml && ! -f profiles/10k/infra.yaml ]]; then
        skipped FILES-06 files FILES-01
        return 0
    fi
    if has_error FILES-04; then
        skipped FILES-06 files FILES-04
        return 0
    fi
    if ! out=$(dc config --quiet 2>&1); then
        finding FILES-06 error files "$(msg c_files06_t)" "$(first_lines 3 "$(redact_text "$out")")" "$(msg c_files06_a)" "TROUBLESHOOTING.md 15." "" "docker compose config --quiet"
        return 0
    fi
    check_ok
    CK_COMPOSE=1
    out=$(dc config --services 2>/dev/null || true)
    CK_SERVICES=" $(tr '\n' ' ' <<<"$out")"
    return 0
}

# --- environment (ENV-*): the .env itself --------------------------------------------------------

# The keys an env file names, set (KEY=) or as a commented sample (# KEY=).
env_keys_of() {
    local line keys=" "
    while IFS= read -r line || [[ -n $line ]]; do
        if [[ $line =~ $ENV_KEY_RE || $line =~ $ENV_SAMPLE_RE ]]; then
            [[ $keys == *" ${BASH_REMATCH[1]} "* ]] || keys="$keys${BASH_REMATCH[1]} "
        fi
    done < "$1"
    printf '%s' "$keys"
}
# The env.example of the installed release: the copy the installer kept, else the folder's own
# when it carries the same version.
reference_example() {
    local v=$CK_VERSION
    if [[ -n $v && -f $STATE_DIR/env.example-$v ]]; then
        printf '%s' "$STATE_DIR/env.example-$v"
    elif [[ -f env.example && -n $v && $(env_get env.example OPENHDID_VERSION 2>/dev/null || true) == "$v" ]]; then
        printf '%s' env.example
    else
        return 1
    fi
}
# Missing keys (CK_MISSING_KEYS), removed (CK_REMOVED_KEYS), legacy aliases and unknown own keys.
env_key_diff() {
    local example=$1 have want key legacy="" unknown=""
    have=$(env_keys_of "$CK_ENVF")
    want=$(env_keys_of "$example")
    CK_MISSING_KEYS=""
    CK_REMOVED_KEYS=""
    CK_LEGACY_KEYS=""
    CK_UNKNOWN_KEYS=""
    for key in $want; do
        in_words "$key" "$have" || CK_MISSING_KEYS="$CK_MISSING_KEYS${CK_MISSING_KEYS:+ }$key"
    done
    for key in $have; do
        in_words "$key" "$want" && continue
        env_has "$CK_ENVF" "$key" || continue
        if in_words "$key" "$REMOVED_KEYS"; then
            CK_REMOVED_KEYS="$CK_REMOVED_KEYS${CK_REMOVED_KEYS:+ }$key"
        elif [[ $key == SYNC_* ]] && in_words "EMD_$key" "$want"; then
            CK_LEGACY_KEYS="$CK_LEGACY_KEYS${CK_LEGACY_KEYS:+ }$key"
        else
            CK_UNKNOWN_KEYS="$CK_UNKNOWN_KEYS${CK_UNKNOWN_KEYS:+ }$key"
        fi
    done
    return 0
}

ck_env_static() {
    local guide v key app_env tls profiles redis_host bundled stores="" item items=() bad="" workers cpus queue
    guide=$(doc_guide)
    app_env=$(ev_or APP_ENV production)
    if [[ $CK_KIND == infra ]]; then
        ck_env_infra
        return 0
    fi
    # ENV-01, ENV-02: keys against the release's env.example (frissítés után).
    if [[ $CK_KIND != 20k ]]; then
        local example
        if example=$(reference_example); then
            env_key_diff "$example"
            if [[ -n $CK_MISSING_KEYS ]]; then
                finding ENV-01 warning env "$(msg c_env01_t)" "$(msg c_env01_d "$CK_VERSION" "$CK_MISSING_KEYS")" "$(msg c_env01_a)" "UPGRADE.md 4." FIX-ENV-KEYS
            else
                check_ok
            fi
            if [[ -n $CK_REMOVED_KEYS ]]; then
                finding ENV-02 info env "$(msg c_env02_t)" "$(msg c_env02_removed "$CK_REMOVED_KEYS")" "$(msg c_env02_a)" "UPGRADE.md 4." FIX-ENV-OBSOLETE
            fi
            if [[ -n $CK_LEGACY_KEYS || -n $CK_UNKNOWN_KEYS ]]; then
                finding ENV-02 info env "$(msg c_env02_other_t)" "$(msg c_env02_other_d "${CK_LEGACY_KEYS:--}" "${CK_UNKNOWN_KEYS:--}")" "$(msg c_env02_other_a)" "UPGRADE.md 4." ""
            fi
        else
            finding ENV-01 info env "$(msg c_env01_t)" "$(msg c_env01_none "${CK_VERSION:-?}")" "$(msg c_env01_none_a "${CK_VERSION:-<verzió>}")" "UPGRADE.md 3." FIX-ENV-KEYS
        fi
        # ENV-03: the image version.
        v=$CK_VERSION
        if [[ -z $v || $v == latest ]] || ! v_semver "$v"; then
            finding ENV-03 error env "$(msg c_env03_t)" "$(msg c_env03_d "${v:-$(msg i_sum_empty)}")" "$(msg c_env03_a)" "UPGRADE.md 5." ""
        else
            check_ok
        fi
    fi
    # ENV-04: APP_KEY.
    v=$(ev APP_KEY)
    if [[ -z $v ]]; then
        finding ENV-04 error env "$(msg c_env04_t)" "$(msg c_env04_empty)" "$(msg c_env04_a)" "$guide, 3." FIX-APP-KEY
    elif [[ $v == base64:* ]] && ! v_appkey "$v"; then
        finding ENV-04 error env "$(msg c_env04_t)" "$(msg c_env04_bad)" "$(msg c_env04_a)" "$guide, 3." ""
    else
        check_ok
    fi
    # ENV-05: APP_URL.
    v=$(ev APP_URL)
    if [[ ! $v =~ ^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?/?$ ]]; then
        finding ENV-05 error env "$(msg c_env05_t)" "$(msg c_env05_d "${v:-$(msg i_sum_empty)}")" "$(msg c_env05_a)" "$guide, 6.2" ""
    else
        check_ok
    fi
    # ENV-06: the production safeguards.
    case $app_env in
        production | staging | demo | local | testing) check_ok ;;
        *) finding ENV-06 error env "$(msg c_env06_t)" "$(msg c_env06_env "$app_env")" "$(msg c_env06_a)" "TROUBLESHOOTING.md 3." "" ;;
    esac
    if [[ $app_env == production ]]; then
        [[ $(ev APP_DEBUG) != true ]] || finding ENV-06 error env "$(msg c_env06_t)" "$(msg c_env06_debug)" "$(msg c_env06_a)" "TROUBLESHOOTING.md 3." ""
        [[ $(ev SESSION_SECURE_COOKIE) != false ]] || finding ENV-06 error env "$(msg c_env06_t)" "$(msg c_env06_cookie)" "$(msg c_env06_a)" "TROUBLESHOOTING.md 3." ""
        IFS=', ' read -r -a items <<<"$(ev TRUSTED_PROXIES)"
        for item in ${items[@]+"${items[@]}"}; do
            case $item in
                '*' | '**' | 0.0.0.0/0 | ::/0) finding ENV-06 error env "$(msg c_env06_t)" "$(msg c_env06_proxies "$item")" "$(msg c_env06_a)" "TROUBLESHOOTING.md 3." "" ;;
            esac
        done
    fi
    [[ $CK_KIND != 20k ]] || { ck_env_redis_passwords; return 0; }
    # ENV-07, ENV-08, ENV-09: the old database defaults and the Valkey password.
    profiles=$(ev COMPOSE_PROFILES)
    redis_host=$(ev_or REDIS_HOST valkey)
    bundled=0
    [[ $redis_host == valkey && ,${profiles// /}, == *,redis,* ]] && bundled=1
    for key in SESSION_DRIVER CACHE_STORE QUEUE_CONNECTION; do
        [[ $(ev "$key") == database ]] && stores="$stores${stores:+, }$key"
    done
    if [[ -n $stores ]]; then
        local pw_state
        if [[ -z $(ev REDIS_PASSWORD) ]]; then pw_state=$(msg c_pw_empty); else pw_state=$(msg c_pw_set); fi
        if [[ $bundled == 1 || ( $redis_host != valkey && -n $redis_host ) ]]; then
            finding ENV-07 warning env "$(msg c_env07_t)" "$(msg c_env07_d "$stores" "$pw_state")" "$(msg c_env07_a)" "TROUBLESHOOTING.md 21.; UPGRADE.md" FIX-DB-TO-REDIS
        else
            finding ENV-07 info env "$(msg c_env07_t)" "$(msg c_env07_nov "$stores")" "$(msg c_env07_a)" "TROUBLESHOOTING.md 21.; UPGRADE.md" FIX-DB-TO-REDIS
        fi
    else
        check_ok
    fi
    local uses_redis=0
    for key in SESSION_DRIVER CACHE_STORE QUEUE_CONNECTION; do
        [[ $(ev "$key") == redis ]] && uses_redis=1
    done
    if [[ -n $(ev REDIS_PASSWORD_FILE) && $bundled == 1 ]]; then
        finding ENV-09 error env "$(msg c_env09_t)" "$(msg c_env09_d)" "$(msg c_env09_a)" "TROUBLESHOOTING.md 23." FIX-REDIS-PASSWORD-FILE
    elif [[ -z $(ev REDIS_PASSWORD) && -z $(ev REDIS_PASSWORD_FILE) && $uses_redis == 1 ]]; then
        # An unused bundled Valkey without a password belongs to ENV-07 (its fix sets the password).
        finding ENV-08 error env "$(msg c_env08_t)" "$(msg c_env08_d)" "$(msg c_env08_a)" "TROUBLESHOOTING.md 23." FIX-DB-TO-REDIS
    else
        check_ok
    fi
    # ENV-11: the exempt networks (at most /8 and /32).
    IFS=', ' read -r -a items <<<"$(ev HDID_RATE_LIMIT_EXEMPT_NETWORKS)"
    for item in ${items[@]+"${items[@]}"}; do
        v_exempt "$item" || bad="$bad${bad:+ }$item"
    done
    if [[ -n $bad ]]; then
        finding ENV-11 error env "$(msg c_env11_t)" "$(msg c_env11_d "$bad")" "$(msg c_env11_a)" "TROUBLESHOOTING.md 18." FIX-EXEMPT
    else
        check_ok
    fi
    # ENV-12: behind a load balancer.
    tls=$(ev_or OPENHDID_TLS on)
    if [[ $tls == off ]]; then
        v=$(ev TRUSTED_PROXIES)
        if [[ -z ${v// /} || $v == 127.0.0.1 ]]; then
            finding ENV-12 warning env "$(msg c_env12_t)" "$(msg c_env12_proxies "${v:-$(msg i_sum_empty)}")" "$(msg c_env12_a)" "$guide, 6.5" ""
        elif [[ $CK_KIND == app && -z $(ev OPENHDID_MONITORING_ALLOW) ]]; then
            finding ENV-12 warning env "$(msg c_env12_t)" "$(msg c_env12_monitoring)" "$(msg c_env12_a)" "$guide, 6.5" ""
        else
            check_ok
        fi
    fi
    # ENV-13: one nginx worker per CPU.
    workers=$(ev OPENHDID_NGINX_WORKERS)
    if [[ $workers == auto ]]; then
        local profile_workers=4
        [[ $CK_KIND == trial ]] && profile_workers=2
        cpus=$(host_cpus)
        if [[ -n $cpus && $cpus -gt $profile_workers ]]; then
            finding ENV-13 warning env "$(msg c_env13_t)" "$(msg c_env13_d "$cpus" "$profile_workers" "$(((cpus - profile_workers) * 80))")" "$(msg c_env13_a)" "TROUBLESHOOTING.md 18." FIX-NGINX-WORKERS
        else
            check_ok
        fi
    fi
    # ENV-14: the priority queue with an old compose.yaml.
    queue=$(ev HDID_PRIORITY_QUEUE)
    if [[ $queue == priority && -f compose.yaml ]] && grep -Eq 'command: \[ ?worker, ?default ?\]' compose.yaml; then
        finding ENV-14 error env "$(msg c_env14_t)" "$(msg c_env14_d)" "$(msg c_env14_a)" "UPGRADE.md 4." FIX-PRIORITY-QUEUE
    fi
    # ENV-15: log transports or a self-signed certificate.
    local logging=""
    case $(ev_or MAIL_MAILER log) in log | array) logging="MAIL_MAILER=$(ev_or MAIL_MAILER log)" ;; esac
    [[ $(ev_or SMS_DRIVER log) != log ]] || logging="$logging${logging:+, }SMS_DRIVER=log"
    [[ $tls != selfsigned ]] || logging="$logging${logging:+, }OPENHDID_TLS=selfsigned"
    if [[ -n $logging ]]; then
        if [[ $app_env == production ]]; then
            finding ENV-15 warning env "$(msg c_env15_t)" "$logging" "$(msg c_env15_a)" "$guide, 11." ""
        else
            finding ENV-15 info env "$(msg c_env15_t)" "$(msg c_env15_trial "$logging" "$app_env")" "$(msg c_env15_a)" "$guide, 11." ""
        fi
    else
        check_ok
    fi
    # ENV-16: the roles of the two-host 10k profile.
    if [[ $CK_KIND == app ]]; then
        local problems=""
        profiles=",${profiles// /},"
        [[ $CK_ROLE != a || $profiles == *,primary,* ]] || problems="$problems${problems:+; }$(msg c_env16_a_primary)"
        [[ $CK_ROLE != b || $profiles != *,primary,* ]] || problems="$problems${problems:+; }$(msg c_env16_b_primary)"
        [[ $profiles != *,db,* && $profiles != *,redis,* ]] || problems="$problems${problems:+; }$(msg c_env16_local)"
        [[ $tls == off ]] || problems="$problems${problems:+; }$(msg c_env16_tls "$tls")"
        [[ -n $(ev OPENHDID_NFS_SERVER) && -n $(ev OPENHDID_NFS_PATH) ]] || problems="$problems${problems:+; }$(msg c_env16_nfs)"
        if [[ -n $problems ]]; then
            finding ENV-16 error env "$(msg c_env16_t "$CK_ROLE")" "$problems" "$(msg c_env16_adv)" "$guide, 15.4" ""
        else
            check_ok
        fi
    fi
    # ENV-18: the key prefix on an external Valkey.
    if [[ $redis_host != valkey && -n $redis_host && -z $(ev REDIS_PREFIX) ]]; then
        finding ENV-18 warning env "$(msg c_env18_t)" "$(msg c_env18_d "$redis_host")" "$(msg c_env18_a)" "TROUBLESHOOTING.md 20." ""
    fi
    # ENV-21: the database password.
    if [[ -z $(ev DB_PASSWORD) && -z $(ev DB_PASSWORD_FILE) ]]; then
        finding ENV-21 error env "$(msg c_env21_t)" "" "$(msg c_env21_a)" "$guide, 7." ""
    else
        check_ok
    fi
    return 0
}
# Kubernetes: the secret and settings file needs the passwords too.
ck_env_redis_passwords() {
    [[ -n $(ev REDIS_PASSWORD) ]] || finding ENV-08 error env "$(msg c_env08_t)" "$(msg c_env08_k8s)" "$(msg c_env08_a)" "TROUBLESHOOTING.md 23." ""
    [[ -n $(ev DB_PASSWORD) ]] || finding ENV-21 error env "$(msg c_env21_t)" "" "$(msg c_env21_a)" "$(doc_guide), 7." ""
    return 0
}
# The infra host: its own short .env.
ck_env_infra() {
    local key bind
    for key in REDIS_PASSWORD REDIS_SESSION_PASSWORD; do
        if [[ -z $(ev "$key") ]]; then
            finding ENV-08 error env "$(msg c_env08_t)" "$(msg c_env08_infra "$key")" "$(msg c_env08_infra_a "$key")" "$(doc_guide), 15.8" ""
        else
            check_ok
        fi
    done
    if [[ -z $(ev DB_PASSWORD) ]]; then
        finding ENV-21 error env "$(msg c_env21_t)" "" "$(msg c_env21_a)" "$(doc_guide), 7.2" ""
    else
        check_ok
    fi
    bind=$(ev OPENHDID_INFRA_BIND_IP)
    if [[ $bind == 0.0.0.0 || -z $bind ]]; then
        finding INFRA-03 warning infra "$(msg c_infra03_t)" "$(msg c_infra03_d "${bind:-$(msg i_sum_empty)}")" "$(msg c_infra03_a)" "$(doc_guide), 15.8" "" \
            "sudo firewall-cmd --permanent --add-rich-rule='rule family=ipv4 source address=<A> port port=3306 protocol=tcp accept'"
    else
        check_ok
    fi
    return 0
}

# --- the container's own start check (check-env) -----------------------------------------------------

ck_container_env() {
    local out line text id sev key
    if [[ $CK_APP_IMAGE_MISSING == 1 ]]; then
        skipped ENV-17 env DOCKER-07
        return 0
    fi
    out=$(dc run --rm --no-deps -T web check-env web 2>&1) || true
    if [[ $out == *"unknown command 'check-env'"* ]]; then
        out=$(dc run --rm --no-deps -T web artisan --version 2>&1) || true
    fi
    out=$(redact_text "$out")
    while IFS= read -r line; do
        case $line in
            *"openhdid: ERROR: "*) sev=error text=${line#*openhdid: ERROR: } ;;
            *"openhdid: warning: "*) sev=warning text=${line#*openhdid: warning: } ;;
            *) continue ;;
        esac
        case $text in
            *HDID_RATE_LIMIT_EXEMPT_NETWORKS*) id=ENV-11 key=c_env11_t ;;
            *"REDIS_PASSWORD is empty"*) id=ENV-08 key=c_env08_t ;;
            *TRUSTED_PROXIES=* | *APP_ENV* | *APP_DEBUG* | *SESSION_SECURE_COOKIE*) id=ENV-06 key=c_env06_t ;;
            *APP_KEY*) id=ENV-04 key=c_env04_t ;;
            *APP_URL*) id=ENV-05 key=c_env05_t ;;
            *DB_PASSWORD*) id=ENV-21 key=c_env21_t ;;
            *"PHP-FPM and nginx may take"*) id=ENV-13 key=c_env13_t ;;
            *"MAIL_MAILER writes"* | *"SMS_DRIVER=log"* | *"OPENHDID_TLS=selfsigned"*) id=ENV-15 key=c_env15_t ;;
            *) id=ENV-17 key=c_env17_t ;;
        esac
        # The static rule above already named it: the container's own words only for the rest.
        if [[ $id != ENV-17 ]] && has_finding "$id"; then continue; fi
        case $id in
            ENV-11) finding "$id" "$sev" env "$(msg "$key")" "$text" "$(msg c_env11_a)" "TROUBLESHOOTING.md 18." FIX-EXEMPT ;;
            ENV-13) finding "$id" "$sev" env "$(msg "$key")" "$text" "$(msg c_env13_a)" "TROUBLESHOOTING.md 18." FIX-NGINX-WORKERS ;;
            *) finding "$id" "$sev" env "$(msg "$key")" "$text" "$(msg c_env17_a)" "TROUBLESHOOTING.md 3." "" ;;
        esac
    done <<<"$out"
    check_ok
    return 0
}

# --- certificate (TLS-*) -------------------------------------------------------------------------------

ck_tls() {
    local tls out status=0 line text found=0 started file newer="" sev
    tls=$(ev_or OPENHDID_TLS on)
    if [[ $tls == selfsigned ]]; then
        finding TLS-02 info tls "$(msg c_tls_selfsigned_t)" "" "$(msg c_tls_selfsigned_a)" "$(doc_guide), 6.3" ""
    elif [[ $tls == on ]]; then
        if [[ $CK_APP_IMAGE_MISSING == 1 ]]; then
            skipped TLS-01 tls DOCKER-07
        else
            out=$(dc run --rm --no-deps -T web tls-check 2>&1) || status=$?
            out=$(redact_text "$out")
            while IFS= read -r line; do
                case $line in
                    *"ERROR: "*) text=${line#*ERROR: } ;;
                    *"warning: "*) text=${line#*warning: } ;;
                    *) continue ;;
                esac
                found=1
                case $line in
                    *"ERROR: "*"expired"*) finding TLS-02 error tls "$(msg c_tls02_expired_t)" "$text" "$(msg c_tls02_a)" "$(doc_guide), 6.3" FIX-TLS-INSTALL ;;
                    *"warning: "*"expires in"*) finding TLS-02 warning tls "$(msg c_tls02_t)" "$text" "$(msg c_tls02_a)" "$(doc_guide), 6.3" FIX-TLS-INSTALL "docker compose exec web openhdid reload" ;;
                    *"missing or not readable"* | *"holds no PEM"* | *"not a readable PEM"* | *"readable by every user"*)
                        if [[ $line == *"ERROR: "* ]]; then sev=error; else sev=warning; fi
                        finding TLS-01 "$sev" tls "$(msg c_tls01_t)" "$text" "$(msg c_tls01_a)" "TROUBLESHOOTING.md 6." FIX-TLS-INSTALL
                        ;;
                    *"ERROR: "*) finding TLS-03 error tls "$(msg c_tls03_t)" "$text" "$(msg c_tls03_a)" "$(doc_guide), 6.3" FIX-TLS-INSTALL ;;
                    *) finding TLS-03 warning tls "$(msg c_tls03_t)" "$text" "$(msg c_tls03_a)" "$(doc_guide), 6.3" FIX-TLS-INSTALL ;;
                esac
            done <<<"$out"
            if [[ $found == 0 && $status != 0 ]]; then
                finding TLS-01 error tls "$(msg c_tls01_t)" "$(first_lines 2 "$out")" "$(msg c_tls01_a)" "TROUBLESHOOTING.md 6." FIX-TLS-INSTALL
            elif [[ $found == 0 ]]; then
                check_ok
            fi
        fi
    fi
    # TLS-05: the container user (uid 82, "others" for the operator's folder, under rootless Docker
    # too) has to enter ca and read its files, or the start takes no CA at all, silently.
    local unreadable=""
    if [[ -d ca && -O ca ]]; then
        (((8#$(file_mode ca) & 8#005) == 8#005)) || unreadable="ca/ ($(file_mode ca))"
        for file in ca/*.crt ca/*.pem; do
            [[ -f $file && -O $file ]] || continue
            (((8#$(file_mode "$file") & 8#004) == 8#004)) || unreadable="$unreadable${unreadable:+, }$file ($(file_mode "$file"))"
        done
        if [[ -n $unreadable ]]; then
            finding TLS-05 warning tls "$(msg c_tls05_t)" "$(msg c_tls05_d "$unreadable")" "$(msg c_tls05_a)" "TROUBLESHOOTING.md 10." "" \
                "chmod 755 ca && chmod 644 ca/*.pem ca/*.crt 2>/dev/null; docker compose restart web worker sync-worker scheduler"
        else
            check_ok
        fi
    fi
    # TLS-04: CA files newer than the containers that read them at start.
    started=${CK_START_web:-}
    if [[ -n $started ]]; then
        for file in ca/*.crt ca/*.pem; do
            [[ -f $file ]] || continue
            [[ $(file_mtime "$file") -gt $started ]] && newer="$newer${newer:+, }$file"
        done
        if [[ -n $newer ]]; then
            finding TLS-04 warning tls "$(msg c_tls04_t)" "$(msg c_tls04_d "$newer")" "$(msg c_tls04_a)" "TROUBLESHOOTING.md 10." FIX-CA-RESTART
        else
            check_ok
        fi
    fi
    return 0
}

# --- services (SVC-*, INFRA-01, NET-03) ----------------------------------------------------------------

ck_services() {
    local ps line svc state health code image var expected="" id=SVC-01 ids info name started oom restarts epoch problem logs
    ps=$(dc ps -a --format '{{.Service}}|{{.State}}|{{.Health}}|{{.ExitCode}}|{{.Image}}' 2>/dev/null || true)
    CK_RUNNING=" "
    CK_PRESENT=" "
    while IFS='|' read -r svc state health code image; do
        [[ -n $svc ]] || continue
        CK_PRESENT="$CK_PRESENT$svc "
        [[ $state == running ]] && CK_RUNNING="$CK_RUNNING$svc "
        var="CKS_${svc//-/_}"
        printf -v "$var" '%s|%s|%s|%s' "$state" "$health" "$code" "$image"
    done <<<"$ps"
    # Start times, memory kills and restarts of every container of the project.
    ids=$(dc ps -a -q 2>/dev/null || true)
    if [[ -n ${ids//[[:space:]]/} ]]; then
        # shellcheck disable=SC2086 # one id per word
        info=$(dk inspect -f '{{index .Config.Labels "com.docker.compose.service"}}|{{.State.StartedAt}}|{{.State.OOMKilled}}|{{.RestartCount}}|{{index .Config.Labels "com.docker.compose.config-hash"}}' $ids 2>/dev/null || true)
        while IFS='|' read -r name started oom restarts hash; do
            [[ -n $name ]] || continue
            epoch=$(iso_to_epoch "$started" || true)
            [[ -z $epoch ]] || printf -v "CK_START_${name//-/_}" '%s' "$epoch"
            [[ ! ${hash:-} =~ ^[0-9a-f]{16,}$ ]] || printf -v "CK_HASH_${name//-/_}" '%s' "$hash"
            if [[ $oom == true ]]; then
                finding SVC-04 error svc "$(msg c_svc04_oom_t "$name")" "" "$(msg c_svc04_a)" "$(doc_ops), 9." "" "docker compose logs --tail=100 $name"
            elif [[ $restarts =~ ^[0-9]+$ && $restarts -gt 0 ]]; then
                finding SVC-04 warning svc "$(msg c_svc04_restart_t "$name" "$restarts")" "" "$(msg c_svc04_a)" "$(doc_ops), 9." "" "docker compose logs --tail=100 $name"
            fi
        done <<<"$info"
    fi
    [[ $CK_KIND == infra ]] && id=INFRA-01
    for svc in $CK_SERVICES; do
        [[ $svc == migrate ]] && continue
        expected="$expected $svc"
        var="CKS_${svc//-/_}"
        IFS='|' read -r state health code image <<<"${!var-}"
        problem=""
        if ! in_words "$svc" "$CK_PRESENT"; then
            problem=$(msg c_svc01_absent)
        elif [[ $state != running ]]; then
            problem=$(msg c_svc01_state "$state" "$code")
        elif [[ $health == unhealthy ]]; then
            problem=$(msg c_svc01_unhealthy)
        elif [[ $health == starting ]]; then
            finding "$id" warning svc "$(msg c_svc01_t "$svc")" "$(msg c_svc01_starting)" "$(msg c_svc01_a)" "TROUBLESHOOTING.md 3." "" "docker compose ps"
            continue
        fi
        if [[ -n $problem ]]; then
            logs=""
            if in_words "$svc" "$CK_PRESENT"; then
                logs=$(dc logs --no-color --no-log-prefix --tail 20 "$svc" 2>/dev/null || true)
                logs=$(last_lines 3 "$(redact_text "$logs")")
            fi
            finding "$id" error svc "$(msg c_svc01_t "$svc")" "$problem${logs:+ – $logs}" "$(msg c_svc01_a)" "TROUBLESHOOTING.md 3." FIX-UP "docker compose logs --tail=100 $svc"
        else
            check_ok
        fi
    done
    # SVC-02: the migration.
    if configured migrate && in_words migrate "$CK_PRESENT"; then
        IFS='|' read -r state health code image <<<"${CKS_migrate-}"
        if [[ $state == exited && $code != 0 ]]; then
            logs=$(dc logs --no-color --no-log-prefix --tail 40 migrate 2>/dev/null || true)
            logs=$(redact_text "$logs")
            if grep -qi 'demo data' <<<"$logs"; then
                finding SVC-02 error svc "$(msg c_svc02_t "$code")" "$(last_lines 2 "$logs")" "$(msg c_svc02_demo)" "TROUBLESHOOTING.md 16." "" "docker compose logs migrate"
            else
                finding SVC-02 error svc "$(msg c_svc02_t "$code")" "$(last_lines 3 "$logs")" "$(msg c_svc02_a)" "TROUBLESHOOTING.md 3." "" "docker compose logs migrate"
            fi
        else
            check_ok
        fi
    fi
    # SVC-05: exactly one scheduler and EMD worker in the two-host profile.
    if [[ $CK_KIND == app && $CK_ROLE == b ]]; then
        local extra=""
        running scheduler && extra="scheduler"
        running sync-worker && extra="$extra${extra:+, }sync-worker"
        if [[ -n $extra ]]; then
            finding SVC-05 error svc "$(msg c_svc05_t)" "$(msg c_svc05_d "$extra")" "$(msg c_svc05_a)" "UPGRADE.md" "" "docker compose up -d --remove-orphans" "docker compose ps"
        else
            check_ok
        fi
    fi
    [[ $CK_KIND != infra ]] || return 0
    # SVC-03: the running image and files newer than the containers.
    if running web; then
        IFS='|' read -r state health code image <<<"${CKS_web-}"
        if [[ -n $CK_VERSION && -n $image && $image != "$APP_IMAGE:$CK_VERSION" ]]; then
            finding SVC-03 warning svc "$(msg c_svc03_t)" "$(msg c_svc03_image "$image" "$APP_IMAGE:$CK_VERSION")" "$(msg c_svc03_a)" "UPGRADE.md 5." FIX-UP
        elif ck_outdated_services; then
            if [[ -n $CK_OUTDATED ]]; then
                finding SVC-03 warning svc "$(msg c_svc03_t)" "$(msg c_svc03_services "$CK_OUTDATED")" "$(msg c_svc03_a)" "UPGRADE.md 5." FIX-UP
            else
                check_ok
            fi
        elif [[ -n ${CK_START_web:-} ]]; then
            local file changed="" part cf
            cf=$(ev COMPOSE_FILE)
            for file in .env compose.yaml ${cf//:/ }; do
                [[ -f $file ]] || continue
                [[ $(file_mtime "$file") -gt $CK_START_web ]] && ! in_words "$file" "$changed" && changed="$changed${changed:+ }$file"
            done
            if [[ -n $changed ]]; then
                finding SVC-03 warning svc "$(msg c_svc03_t)" "$(msg c_svc03_files "$changed")" "$(msg c_svc03_a)" "UPGRADE.md 5." FIX-UP
            else
                check_ok
            fi
        fi
        ck_ports
    fi
    return 0
}

# The running services whose container was made from another configuration than the current
# files give (Compose's config hash label against "docker compose config --hash"): exactly what
# "docker compose up -d" would recreate. A file only touched, or changed and changed back, is no
# finding. Fails (1) when the hashes are unknown (an older Compose): the file times decide then.
ck_outdated_services() {
    local out svc hash var
    CK_OUTDATED=""
    out=$(dc config --hash '*' 2>/dev/null) || return 1
    [[ -n $out ]] || return 1
    while read -r svc hash; do
        [[ -n $svc && -n ${hash:-} ]] || continue
        running "$svc" || continue
        var="CK_HASH_${svc//-/_}"
        [[ -n ${!var-} ]] || return 1
        [[ ${!var} == "$hash" ]] || CK_OUTDATED="$CK_OUTDATED${CK_OUTDATED:+, }$svc"
    done <<<"$out"
    return 0
}

# NET-03: the web container publishes the configured address and ports. Behind a TLS-terminating
# load balancer (OPENHDID_TLS=off) only the HTTP port serves, so whether the HTTPS port is
# published does not matter there (an override may leave it out, for example two hosts on one machine).
ck_ports() {
    local bind https http out want target port wrong="" targets="8443 8080"
    bind=$(ev_or OPENHDID_BIND_IP 0.0.0.0)
    https=$(ev_or OPENHDID_HTTPS_PORT 443)
    http=$(ev_or OPENHDID_HTTP_PORT 80)
    [[ $(ev_or OPENHDID_TLS on) != off ]] || targets=8080
    for target in $targets; do
        if [[ $target == 8443 ]]; then want=$https; else want=$http; fi
        out=$(dc port web "$target" 2>/dev/null || true)
        out=$(first_lines 1 "$out")
        [[ -n $out ]] || { wrong="$wrong${wrong:+, }$target → -"; continue; }
        port=${out##*:}
        if [[ $port != "$want" || ( $bind != 0.0.0.0 && ${out%:*} != "$bind" ) ]]; then
            wrong="$wrong${wrong:+, }$target → $out"
        fi
    done
    if [[ -n $wrong ]]; then
        finding NET-03 warning net "$(msg c_net03_t)" "$(msg c_net03_d "$wrong" "$bind" "$https" "$http")" "$(msg c_net03_a)" "$(doc_guide), 6.1" FIX-UP
    else
        check_ok
    fi
}

# --- inside the containers: system status, database, Valkey, names, scheduler ----------------------------

# The hdid:health JSON as lines of the container's PHP: key, status, label, detail, hint (\x1f).
# shellcheck disable=SC2016 # PHP code, run by the container's php
HEALTH_TSV_PHP='$d = json_decode(stream_get_contents(STDIN), true); if (! is_array($d)) { exit(3); } foreach ($d as $c) { $f = []; foreach (["key", "status", "label", "detail", "hint"] as $k) { $f[] = str_replace(["\x1f", "\t", "\r", "\n"], " ", (string) ($c[$k] ?? "")); } echo "HEALTH\x1f", implode("\x1f", $f), PHP_EOL; }'
# shellcheck disable=SC2016
RESOLVE_PHP='foreach (array_slice($argv, 1) as $n) { echo "NAME\x1f", $n, "\x1f", gethostbyname($n), PHP_EOL; }'
# shellcheck disable=SC2016
GRANTS_PHP='foreach (Illuminate\Support\Facades\DB::select("SHOW GRANTS") as $r) { echo "GRANT\x1f", implode(" ", (array) $r), PHP_EOL; }'
# The overlap locks held now: name, artisan command, background (1/0), lifetime in minutes.
# shellcheck disable=SC2016
LOCKS_PHP='foreach (app(Illuminate\Console\Scheduling\Schedule::class)->events() as $e) { if ($e->withoutOverlapping && $e->mutex->exists($e)) { $c = preg_match("/artisan.? +([^ ]+)/", (string) $e->command, $m) ? $m[1] : ""; echo "LOCK\x1f", $e->description ?: $c, "\x1f", $c, "\x1f", $e->runInBackground ? 1 : 0, "\x1f", $e->expiresAt, PHP_EOL; } }'

# Whether an integration of a health tile is still unconfigured (a fresh installation's to-do);
# $2 is the tile's status.
unconfigured() {
    case $1 in
        mail) case $(ev_or MAIL_MAILER log) in log | array) return 0 ;; esac ;;
        sms) [[ $(ev_or SMS_DRIVER log) == log ]] && return 0 ;;
        sync | sync_auth) [[ -z $(ev EMD_SYNC_API_URL)$(ev EMD_SYNC_LOGIN_URL)$(ev SYNC_API_URL)$(ev SYNC_LOGIN_URL) ]] && return 0 ;;
        # Yellow: a partner has no key yet; red (a mobile key without its signing secret) is a real fault.
        api_keys) [[ ${2:-} == warn ]] && return 0 ;;
        oidc) [[ -z $(ev USER_ADFS_ISSUER)$(ev CLIENT_ADFS_ISSUER)$(ev USER_ENTRA_CLIENT_ID)$(ev CLIENT_ENTRA_CLIENT_ID) ]] && return 0 ;;
    esac
    return 1
}

ck_health() {
    local script out line tag key status label detail hint sev title fresh=0 rows=0 since
    printf -v script "php artisan hdid:health --json 2>/dev/null | php -r '%s'" "$HEALTH_TSV_PHP"
    out=$(dc exec -T web sh -c "$script" 2>/dev/null) || true
    since=${CK_START_scheduler:-}
    # Host B runs no scheduler: the scheduler and queue heartbeats come from host A over the shared
    # Valkey, so a host B that has just started waits for the first ones the same way.
    [[ -n $since || $CK_ROLE != b ]] || since=${CK_START_web:-}
    [[ -n $since && $((CK_NOW - since)) -lt 180 ]] && fresh=1
    while IFS=$'\x1f' read -r tag key status label detail hint; do
        [[ $tag == HEALTH ]] || continue
        rows=$((rows + 1))
        case $status in
            ok) check_ok; continue ;;
            fail) sev=error ;;
            warn) sev=warning ;;
            *) sev=info ;;
        esac
        detail=$(redact_text "$detail")
        title=$(msg c_health_t "${label:-$key}")
        [[ -n $hint ]] || hint=$(msg c_health_hint)
        if [[ $key == database && $sev == error ]]; then
            finding DB-01 error db "$(msg c_db01_t)" "$detail" "$hint" "$(doc_guide), 7." "" "docker compose logs --tail=50 mariadb"
            continue
        fi
        if [[ $sev != info ]] && unconfigured "$key" "$status"; then
            sev=warning
            title=$(msg c_health_unconfigured_t "${label:-$key}")
            hint="$(msg c_health_unconfigured_a) $hint"
        elif [[ $fresh == 1 && ( $key == scheduler || $key == queue ) ]]; then
            sev=info
            title=$(msg c_health_fresh_t "${label:-$key}")
        elif [[ $CK_ROLE == b && $key == scheduler && $sev != info ]]; then
            hint="$(msg c_health_role_b_scheduler_a) $hint"
        fi
        finding "HEALTH-$key" "$sev" health "$title" "$detail" "$hint" "$(doc_ops), 1., 6." ""
    done <<<"$out"
    if [[ $rows == 0 ]]; then
        finding HEALTH-all warning health "$(msg c_health_none_t)" "" "$(msg c_health_none_a)" "TROUBLESHOOTING.md 1." "" "docker compose exec web php artisan hdid:health"
    fi
    return 0
}

ck_database() {
    local out pending line grants="" tag text
    # DB-02: the migration account and pending migrations (migrate:status only reads).
    if configured migrate && [[ $CK_APP_IMAGE_MISSING == 0 ]]; then
        if out=$(dc run --rm --no-deps -T migrate artisan migrate:status 2>&1); then
            pending=$(grep -c 'Pending' <<<"$out" || true)
            if [[ ${pending:-0} -gt 0 ]]; then
                finding DB-02 warning db "$(msg c_db02_pending_t "$pending")" "" "$(msg c_db02_pending_a)" "$(doc_guide), 7.2" FIX-UP
            else
                check_ok
            fi
        else
            finding DB-02 warning db "$(msg c_db02_t)" "$(last_lines 2 "$(redact_text "$out")")" "$(msg c_db02_a)" "$(doc_guide), 7.2" "" "docker compose run --rm --no-deps migrate artisan migrate:status"
        fi
    fi
    # DB-03: the runtime account must not change the schema.
    out=$(dc exec -T web php artisan tinker --execute "$GRANTS_PHP" 2>/dev/null) || true
    while IFS=$'\x1f' read -r tag text; do
        [[ $tag == GRANT ]] || continue
        case $text in
            *"GRANT USAGE"*) ;;
            *"ALL PRIVILEGES"* | *CREATE* | *DROP* | *ALTER*) grants="$grants${grants:+ | }$text" ;;
        esac
    done <<<"$out"
    if [[ -n $grants ]]; then
        finding DB-03 warning db "$(msg c_db03_t)" "$(redact_text "$grants")" "$(msg c_db03_a)" "$(doc_guide), 7.2" ""
    elif [[ $out == *GRANT* ]]; then
        check_ok
    fi
    return 0
}

# PING (or another valkey-cli command) on a bundled Valkey with the password in REDISCLI_AUTH only.
valkey_cli() {
    local svc=$1 pw=$2
    shift 2
    if [[ -n $pw ]]; then
        REDISCLI_AUTH=$pw dc exec -T -e REDISCLI_AUTH "$svc" valkey-cli --no-auth-warning "$@" 2>&1
    else
        dc exec -T "$svc" valkey-cli "$@" 2>&1
    fi
}
valkey_auth_failed() { [[ $1 == *NOAUTH* || $1 == *WRONGPASS* || $1 == *"invalid password"* || $1 == *"without any password configured"* || $1 == *"invalid username-password"* ]]; }

ck_valkey() {
    local pw spw out svc want policy mismatch=""
    running valkey || return 0
    pw=$(dc exec -T web php -r 'echo getenv("REDIS_PASSWORD");' 2>/dev/null) || pw=""
    spw=$(dc exec -T web php -r 'echo getenv("REDIS_SESSION_PASSWORD");' 2>/dev/null) || spw=""
    [[ -n $spw ]] || spw=$pw
    for svc in valkey valkey-sessions; do
        running "$svc" || continue
        if [[ $svc == valkey ]]; then out=$(valkey_cli "$svc" "$pw" PING || true); else out=$(valkey_cli "$svc" "$spw" PING || true); fi
        if valkey_auth_failed "$out"; then
            mismatch="$mismatch${mismatch:+, }$svc"
            continue
        fi
        [[ $out == *PONG* ]] || continue
        check_ok
        if [[ $svc == valkey ]]; then want=noeviction; out=$(valkey_cli "$svc" "$pw" CONFIG GET maxmemory-policy || true); else want=allkeys-lru; out=$(valkey_cli "$svc" "$spw" CONFIG GET maxmemory-policy || true); fi
        policy=$(last_lines 1 "$out")
        if [[ -n $policy && $policy != *maxmemory-policy* && $policy != "$want" ]]; then
            finding VALKEY-01 error valkey "$(msg c_valkey01_t "$svc")" "$(msg c_valkey01_d "$policy" "$want")" "$(msg c_valkey01_a)" "TROUBLESHOOTING.md 20." "" "docker compose up -d"
        fi
    done
    if [[ -n $mismatch ]]; then
        finding ENV-10 error env "$(msg c_env10_t)" "$(msg c_env10_d "$mismatch" "${#pw}")" "$(msg c_env10_a)" "TROUBLESHOOTING.md 23." FIX-VALKEY-RECREATE
    fi
    return 0
}

ck_names() {
    local names="" key value host out tag name address bad="" soft=""
    for key in DB_HOST REDIS_HOST REDIS_SESSION_HOST OPENHDID_NFS_SERVER; do
        value=$(ev "$key")
        [[ -n $value ]] && ! in_words "$value" "$names" && names="$names $value"
    done
    for key in EMD_SYNC_API_URL EMD_SYNC_LOGIN_URL EMD_SYNC_ID_LIST_URL USER_ADFS_ISSUER CLIENT_ADFS_ISSUER; do
        value=$(ev "$key")
        [[ -n $value ]] || continue
        host=$(host_of "$value")
        [[ -n $host ]] && ! in_words "$host" "$names" && names="$names $host"
    done
    [[ $(ev MAIL_MAILER) != ews || -z $(ev EWS_URL) ]] || { host=$(host_of "$(ev EWS_URL)"); in_words "$host" "$names" || names="$names $host"; }
    [[ $(ev MAIL_MAILER) != smtp || -z $(ev MAIL_HOST) ]] || { host=$(ev MAIL_HOST); in_words "$host" "$names" || names="$names $host"; }
    [[ $(ev SMS_DRIVER) != ozeki || -z $(ev OZEKI_URL) ]] || { host=$(host_of "$(ev OZEKI_URL)"); in_words "$host" "$names" || names="$names $host"; }
    local wanted=""
    for name in $names; do
        case $name in mariadb | valkey | valkey-sessions | localhost) continue ;; esac
        is_ipv4 "$name" && continue
        [[ $name == *:* ]] && continue
        wanted="$wanted $name"
    done
    [[ -n ${wanted// /} ]] || return 0
    # shellcheck disable=SC2086 # one name per word
    out=$(dc exec -T web php -r "$RESOLVE_PHP" -- $wanted 2>/dev/null) || return 0
    while IFS=$'\x1f' read -r tag name address; do
        [[ $tag == NAME ]] || continue
        if [[ $address == "$name" || -z $address ]]; then
            if [[ $name == "$(ev DB_HOST)" || $name == "$(ev REDIS_HOST)" || $name == "$(ev REDIS_SESSION_HOST)" ]]; then
                bad="$bad${bad:+, }$name"
            else
                soft="$soft${soft:+, }$name"
            fi
        else
            check_ok
        fi
    done <<<"$out"
    [[ -z $bad ]] || finding NET-02 error net "$(msg c_net02_t)" "$bad" "$(msg c_net02_a)" "TROUBLESHOOTING.md 7." ""
    [[ -z $soft ]] || finding NET-02 warning net "$(msg c_net02_t)" "$soft" "$(msg c_net02_a)" "TROUBLESHOOTING.md 7." ""
    return 0
}

# SCHED-01: a task behind its overlap lock without a process: held locks (twice, 5 s apart),
# and the processes of the scheduler container.
CK_STUCK=()
sched_sample() {
    local locks procs tag name cmd bg expires i stuck=() running_now
    locks=$(dc exec -T scheduler php artisan tinker --execute "$LOCKS_PHP" 2>/dev/null) || return 1
    procs=$(dc exec -T scheduler ps -o args 2>/dev/null) || return 1
    while IFS=$'\x1f' read -r tag name cmd bg expires; do
        [[ $tag == LOCK ]] || continue
        running_now=0
        if [[ $bg == 1 ]]; then
            if [[ -n $cmd && $procs == *"$cmd"* ]] || [[ $procs == *schedule:finish* ]]; then running_now=1; fi
        else
            # A task of the scheduler process itself runs inside schedule:run: sample it again.
            i=0
            while [[ $procs == *schedule:run* && $i -lt 3 ]]; do
                sleep 5
                procs=$(dc exec -T scheduler ps -o args 2>/dev/null) || procs=""
                i=$((i + 1))
            done
            [[ $procs != *schedule:run* ]] || running_now=1
        fi
        [[ $running_now == 1 ]] || stuck+=("$name|$cmd|$expires")
    done <<<"$locks"
    CK_STUCK=(${stuck[@]+"${stuck[@]}"})
    return 0
}
ck_scheduler() {
    local first=() item name cmd expires effect
    running scheduler || {
        [[ $CK_ROLE == b ]] || skipped SCHED-01 sched SVC-01
        return 0
    }
    sched_sample || return 0
    [[ ${#CK_STUCK[@]} -gt 0 ]] || { check_ok; return 0; }
    first=("${CK_STUCK[@]}")
    sleep 5
    sched_sample || return 0
    local now=" ${CK_STUCK[*]-} "
    for item in "${first[@]}"; do
        [[ $now == *" $item "* ]] || continue
        IFS='|' read -r name cmd expires <<<"$item"
        effect=""
        [[ $name != hdid:emd-sync-token ]] || effect=" $(msg c_sched01_token)"
        finding SCHED-01 warning sched "$(msg c_sched01_t "$name")" "$(msg c_sched01_d "$expires")$effect" "$(msg c_sched01_a)" "TROUBLESHOOTING.md 22." FIX-SCHED-LOCK \
            "docker compose exec scheduler php artisan schedule:clear-cache"
    done
    return 0
}

# --- logs (LOG-*) --------------------------------------------------------------------------------------

log_hits() {
    local pattern=$1 n
    n=$(grep -E -c -e "$pattern" <<<"$CK_LOGS" || true)
    printf '%s' "${n:-0}"
}
log_sample() {
    local out
    out=$(grep -E -m 3 -e "$1" <<<"$CK_LOGS" || true)
    out=$(redact_personal "$(redact_text "$out")")
    first_lines 3 "$out"
}
# log_finding ID SEVERITY KEY PATTERN DOC FIX: the title is msg KEY_t (with the count), the advice KEY_a.
log_finding() {
    local id=$1 sev=$2 key=$3 pattern=$4 doc=$5 fix=$6 n
    n=$(log_hits "$pattern")
    if [[ $n -gt 0 ]]; then
        finding "$id" "$sev" log "$(msg "${key}_t" "$n")" "$(log_sample "$pattern")" "$(msg "${key}_a")" "$doc" "$fix"
    else
        check_ok
    fi
}
ck_logs() {
    local svc services="" n
    for svc in web worker sync-worker scheduler migrate; do
        in_words "$svc" "$CK_PRESENT" && services="$services $svc"
    done
    [[ -n $services ]] || return 0
    # shellcheck disable=SC2086 # one service per word
    CK_LOGS=$(dc logs --since 24h --tail 3000 --no-color --no-log-prefix $services 2>/dev/null || true)
    log_finding LOG-01 error c_log01 'openhdid: ERROR:' "TROUBLESHOOTING.md 3." ""
    log_finding LOG-02 error c_log02 'NOAUTH|WRONGPASS' "TROUBLESHOOTING.md 23." FIX-VALKEY-RECREATE
    log_finding LOG-03 error c_log03 'SQLSTATE\[HY000\] \[(1045|2002)\]' "$(doc_guide), 7." ""
    log_finding LOG-04 warning c_log04 'unable to get local issuer certificate|cURL error 60' "TROUBLESHOOTING.md 10." FIX-CA-RESTART
    log_finding LOG-05 warning c_log05 'cURL error 6:|Could not resolve host' "TROUBLESHOOTING.md 7." ""
    log_finding LOG-06 error c_log06 'OOM command not allowed' "TROUBLESHOOTING.md 20." ""
    n=$(log_hits 'limiting requests|"status":(429|503)')
    if [[ $n -gt 100 ]]; then
        finding LOG-07 info log "$(msg c_log07_t "$n")" "" "$(msg c_log07_a)" "TROUBLESHOOTING.md 18." ""
    else
        check_ok
    fi
    log_finding LOG-08 warning c_log08 'TLS required' "TROUBLESHOOTING.md 19." ""
    log_finding LOG-09 warning c_log09 'production\.ERROR|PHP Fatal error' "TROUBLESHOOTING.md 2." ""
    CK_LOGS=""
    return 0
}

# --- the infra host (INFRA-*) ------------------------------------------------------------------------

ck_infra() {
    local svc want maxmem pw out policy limit runtime migrate grants
    for svc in valkey valkey-sessions; do
        running "$svc" || continue
        if [[ $svc == valkey ]]; then want=noeviction maxmem=256mb pw=$(ev REDIS_PASSWORD); else want=allkeys-lru maxmem=576mb pw=$(ev REDIS_SESSION_PASSWORD); fi
        out=$(valkey_cli "$svc" "$pw" CONFIG GET maxmemory-policy || true)
        if valkey_auth_failed "$out"; then
            finding ENV-10 error env "$(msg c_env10_t)" "$(msg c_env10_d "$svc" "${#pw}")" "$(msg c_env10_infra_a)" "TROUBLESHOOTING.md 23." "" "docker compose up -d --force-recreate $svc"
            continue
        fi
        policy=$(last_lines 1 "$out")
        out=$(valkey_cli "$svc" "$pw" CONFIG GET maxmemory || true)
        limit=$(last_lines 1 "$out")
        if [[ $policy != "$want" ]]; then
            finding INFRA-02 error infra "$(msg c_infra02_t "$svc")" "$(msg c_valkey01_d "${policy:-?}" "$want")" "$(msg c_infra02_a)" "$(doc_guide), 15.8" "" "docker compose up -d"
        else
            check_ok
        fi
        [[ -z $limit || $limit == 0 ]] && finding INFRA-02 error infra "$(msg c_infra02_t "$svc")" "$(msg c_infra02_nolimit "$maxmem")" "$(msg c_infra02_a)" "$(doc_guide), 15.8" "" "docker compose up -d"
    done
    if running mariadb; then
        runtime=$(ev_or DB_USERNAME openhdid)
        migrate=$(env_get db.env DB_USERNAME 2>/dev/null || echo openhdid_migrate)
        # shellcheck disable=SC2016 # the container's shell expands the root password from its own environment
        out=$(dc exec -T -e "CK_RUNTIME=$runtime" -e "CK_MIGRATE=$migrate" mariadb sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -uroot -N -B -e "SHOW GRANTS FOR \`$CK_RUNTIME\`@\`%\`; SHOW GRANTS FOR \`$CK_MIGRATE\`@\`%\`"' 2>&1) || true
        grants=$(redact_text "$out")
        if [[ $grants != *"GRANT SELECT, INSERT, UPDATE, DELETE ON"*"\`$runtime\`@\`%\`"* && $grants != *"GRANT SELECT, INSERT, UPDATE, DELETE ON"*"'$runtime'@'%'"* ]]; then
            finding INFRA-04 error infra "$(msg c_infra04_t "$runtime")" "$(first_lines 2 "$grants")" "$(msg c_infra04_a)" "$(doc_guide), 7.2" ""
        elif [[ $grants != *"ALL PRIVILEGES"* ]]; then
            finding INFRA-04 error infra "$(msg c_infra04_t "$migrate")" "$(first_lines 2 "$grants")" "$(msg c_infra04_a)" "$(doc_guide), 7.2" ""
        else
            check_ok
        fi
    fi
    return 0
}

# --- Kubernetes (K8S-*) ----------------------------------------------------------------------------

ck_k8s() {
    local file bad="" missing="" out
    for file in k8s/kustomization.yaml k8s/openhdid-env.env k8s/openhdid-db-env.env; do
        if [[ ! -f $file ]]; then
            missing="$missing${missing:+, }$file"
        elif [[ $file == *.env && $(file_mode "$file") != 600 ]]; then
            bad="$bad${bad:+, }$file $(file_mode "$file")"
        fi
    done
    if [[ -n $missing$bad ]]; then
        finding K8S-01 warning k8s "$(msg c_k8s01_t)" "${missing:+$(msg c_k8s01_missing "$missing")}${bad:+ $bad}" "$(msg c_k8s01_a)" "$(doc_guide), 15.5" "${bad:+FIX-PERMS}" "chmod 600 k8s/*.env"
    else
        check_ok
    fi
    ck_env_static
    if command -v kubectl >/dev/null 2>&1 && [[ -f k8s/kustomization.yaml ]]; then
        if out=$(kubectl kustomize k8s 2>&1 </dev/null); then
            check_ok
        else
            finding K8S-02 error k8s "$(msg c_k8s02_t)" "$(first_lines 2 "$(redact_text "$out")")" "$(msg c_k8s02_a)" "$(doc_guide), 15.5" "" "kubectl kustomize k8s"
        fi
    else
        finding K8S-02 info k8s "$(msg c_k8s02_t)" "$(msg c_k8s02_none)" "$(msg c_k8s02_a)" "$(doc_guide), 15.5" ""
    fi
    return 0
}

# --- the whole check ---------------------------------------------------------------------------------

check_run() {
    findings_reset
    CK_NOW=$(date +%s)
    REDACT_READY=0
    ck_detect
    J_VERSION=$CK_VERSION
    J_PROFILE=$CK_PROFILE
    J_ROLE=$CK_ROLE
    if [[ -z $CK_PROFILE ]]; then
        finding FILES-02 error files "$(msg c_files02_t .env)" "$(msg c_no_install "$DIR")" "$(msg c_no_install_a)" "$(doc_guide), 3." "" "./install.sh"
        return 0
    fi
    # The installer's own first step has just checked the host; the check after it does not repeat it.
    [[ $COMMAND == install ]] || check_host "$CK_KIND" warn
    if [[ $CK_KIND == 20k ]]; then
        ck_k8s
        return 0
    fi
    check_docker "$CK_KIND"
    CK_DOCKER=1
    has_error DOCKER-01 && CK_DOCKER=0
    CK_APP_IMAGE_MISSING=0
    local i=0
    while [[ $i -lt ${#F_ID[@]} ]]; do
        [[ ${F_ID[$i]} == DOCKER-07 && ${F_DETAIL[$i]} == *"$APP_IMAGE:"* ]] && CK_APP_IMAGE_MISSING=1
        i=$((i + 1))
    done
    ck_files
    ck_env_static
    if [[ $CK_DOCKER == 0 ]]; then
        skipped SVC-01 svc DOCKER-01
        return 0
    fi
    ck_compose
    if [[ $CK_COMPOSE == 0 ]]; then
        skipped SVC-01 svc FILES-06
        return 0
    fi
    ck_services
    if [[ $CK_KIND == infra ]]; then
        ck_infra
        return 0
    fi
    ck_container_env
    ck_tls
    if running web; then
        ck_health
        ck_database
        ck_valkey
        ck_names
    else
        skipped HEALTH-all health SVC-01
    fi
    [[ $CK_ROLE == b ]] || ck_scheduler
    ck_logs
    # NET-01: the public name in the DNS.
    local host address
    host=$(host_of "$(ev APP_URL)")
    if [[ -n $host ]] && command -v getent >/dev/null 2>&1; then
        if address=$(getent hosts "$host" 2>/dev/null) && [[ -n $address ]]; then
            check_ok
        else
            finding NET-01 warning net "$(msg c_net01_t "$host")" "" "$(msg c_net01_a "$host")" "$(doc_guide), 6.2" ""
        fi
    fi
    return 0
}

check_print() {
    say "$(msg c_header "$DIR" "${CK_VERSION:-?}" "$(ck_label)")"
    say ""
    if [[ ${#F_ID[@]} -gt 0 ]]; then
        print_findings
        say ""
    fi
    say "$(msg c_summary "$(findings_count error)" "$(findings_count warning)" "$(findings_count info)" "$F_OK")"
}

cmd_check() {
    local code
    [[ $(id -u 2>/dev/null || echo 1000) != 0 ]] || [[ $JSON == 1 ]] || say "$(msg c_root_note)"
    if [[ $JSON == 1 ]]; then
        QUIET=1
        check_run
        findings_json
    else
        say "$(msg c_running)"
        check_run
        check_print
    fi
    code=$(findings_exit_code)
    exit "$code"
}

# ===== fix ========================================================================================
# Guided repair: the same survey as the check, then for every finding that has a fix, one at a
# time, what is wrong, exactly what will be done (commands, keys, downtime and its effect), and a
# confirmation; a no goes on with the next one and changes nothing. Before .env changes it is
# saved once (.env.bak-<stamp>, 600); every write keeps the comments (env_render) and is atomic.
# Nothing is uploaded, no data is deleted, root is never needed.

# The order of the fixes: files first, then the keys, the Valkey password before the move to Valkey,
# the restarts last.
FIX_ORDER="FIX-PERMS FIX-PROFILE-FILE FIX-OVERRIDE FIX-ENV-KEYS FIX-ENV-OBSOLETE FIX-APP-KEY FIX-REDIS-PASSWORD-FILE FIX-DB-TO-REDIS FIX-VALKEY-RECREATE FIX-EXEMPT FIX-NGINX-WORKERS FIX-PRIORITY-QUEUE FIX-TLS-INSTALL FIX-CA-RESTART FIX-SCHED-LOCK FIX-UP"
FX_FIXED=0
FX_SKIPPED=0
FX_MANUAL=0
FX_ENV_BACKUP=""

# Prints one planned step ("    - …").
plan() { say "      - $*"; }

fx_backup_env() {
    env_backup .env
    FX_ENV_BACKUP=".env.bak-$STAMP"
}
# Writes the keys set with env_set (and UNSET_KEYS commented out) into .env, comments kept.
fx_render() {
    local saved_note=$RENDER_NOTE
    RENDER_NOTE=$(msg f_env_block "$OPENHDID_RELEASE" "$(date +%Y-%m-%d)")
    UNSET_NOTE=$(msg f_env_unset_note "$OPENHDID_RELEASE")
    env_render .env .env
    RENDER_NOTE=$saved_note
    UNSET_NOTE=""
    info "$(msg f_env_written)"
}
# docker compose up -d (the documented, confirmed restart); on failure the state and the advice.
# A successful up of every service (no service named) makes FIX-UP of the same run superfluous.
FX_UP_ALL=0
fx_up() {
    local out arg services=0
    for arg in "$@"; do [[ $arg == -* || $arg =~ ^[0-9]+$ ]] || services=1; done
    info "$(msg f_running "docker compose up -d $*")"
    if ! dc up -d "$@"; then
        out=$(dc ps -a 2>/dev/null || true)
        say "$(redact_text "$out")"
        say "    $(msg f_up_failed)"
        return 2
    fi
    [[ $services == 1 ]] || FX_UP_ALL=1
    return 0
}
selected() {
    [[ -z $ONLY ]] && return 0
    [[ ",${ONLY// /}," == *",$1,"* ]]
}

fix_FIX_PERMS() {
    local file own=() foreign=""
    for file in .env db.env k8s/openhdid-env.env k8s/openhdid-db-env.env; do
        [[ -f $file ]] || continue
        if [[ ! -O $file ]]; then
            foreign="$foreign $file"
        elif [[ $(file_mode "$file") != 600 ]]; then
            own+=("$file")
        fi
    done
    say "    $(msg f_plan)"
    [[ ${#own[@]} == 0 ]] || plan "chmod 600 ${own[*]}"
    [[ -z $foreign ]] || plan "$(msg f_perms_foreign "${foreign# }")"
    [[ ${#own[@]} -gt 0 ]] || { say "        sudo chown \"\$(id -un)\"${foreign}"; return 2; }
    confirm_soft || return 1
    chmod 600 "${own[@]}" || return 2
    done_ "$(msg f_perms_done "${own[*]}")"
    if [[ -n $foreign ]]; then
        say "        sudo chown \"\$(id -un)\"${foreign}"
        return 2
    fi
    return 0
}

fix_FIX_PROFILE_FILE() {
    local cf part parts assets=() manual="" asset out
    cf=$(ev COMPOSE_FILE)
    IFS=: read -r -a parts <<<"$cf"
    for part in ${parts[@]+"${parts[@]}"}; do
        [[ -z $part || -f $part ]] && continue
        if asset=$(asset_of "$part"); then assets+=("$asset"); else manual="$manual $part"; fi
    done
    [[ -z $manual ]] || say "    $(msg f_profile_unknown "${manual# }")"
    [[ ${#assets[@]} -gt 0 ]] || return 2
    say "    $(msg f_plan)"
    for asset in "${assets[@]}"; do
        plan "$(msg f_profile_get "$asset" "$(asset_target "$asset")" "${FROM_DIR:-$RELEASE_BASE/v$VERSION}")"
    done
    plan "docker compose config --quiet"
    confirm_soft || return 1
    if ! (download_release "${assets[@]}"); then
        say "    $(msg f_download_failed)"
        return 2
    fi
    if ! out=$(dc config --quiet 2>&1); then
        say "$(redact_text "$out")"
        return 2
    fi
    done_ "$(msg f_profile_done)"
    return 0
}

fix_FIX_OVERRIDE() {
    local cf new out
    cf=$(ev COMPOSE_FILE)
    new="$cf:compose.override.yaml"
    say "    $(msg f_plan)"
    plan "$(msg f_env_key COMPOSE_FILE "$new")"
    plan "$(msg f_restart_all)"
    # The new list is tried first, in this one call's environment only.
    if ! out=$(COMPOSE_FILE=$new dc config --quiet 2>&1); then
        say "$(redact_text "$out")"
        say "    $(msg f_probe_failed)"
        return 2
    fi
    confirm_soft || return 1
    fx_backup_env
    env_reset
    env_set COMPOSE_FILE "$new"
    fx_render
    fx_up || return 2
    return 0
}

# The example's comment block right above a key's line, and the line itself, onto EX_BLOCK
# (FIX-ENV-KEYS); a block shared by several keys comes once.
EX_LINES=()
EX_BLOCK=""
EX_EMITTED=" "
ex_block() {
    local key=$1 j=0 k start re="^# ?$1="
    while [[ $j -lt ${#EX_LINES[@]} ]]; do
        if [[ ${EX_LINES[$j]} == "$key="* || ${EX_LINES[$j]} =~ $re ]]; then break; fi
        j=$((j + 1))
    done
    [[ $j -lt ${#EX_LINES[@]} ]] || return 1
    start=$j
    k=$((j - 1))
    while [[ $k -ge 0 && ${EX_LINES[$k]} == \#* ]]; do
        [[ ${EX_LINES[$k]} =~ $ENV_SAMPLE_RE ]] && break
        in_words "$k" "$EX_EMITTED" && break
        start=$k
        k=$((k - 1))
    done
    k=$start
    while [[ $k -le $j ]]; do
        in_words "$k" "$EX_EMITTED" || EX_BLOCK="$EX_BLOCK${EX_LINES[$k]}"$'\n'
        EX_EMITTED="$EX_EMITTED$k "
        k=$((k + 1))
    done
    return 0
}
fix_FIX_ENV_KEYS() {
    local example line block="" key tmp
    if ! example=$(reference_example); then
        [[ -n $CK_VERSION ]] || { say "    $(msg f_no_version)"; return 2; }
        say "    $(msg f_env_example_get "$CK_VERSION")"
        if ! (download_release env.example); then
            say "    $(msg f_download_failed)"
            return 2
        fi
        example=$(reference_example) || return 2
    fi
    env_key_diff "$example"
    [[ -n $CK_MISSING_KEYS ]] || { info "$(msg f_env_keys_none)"; return 0; }
    EX_LINES=()
    while IFS= read -r line || [[ -n $line ]]; do EX_LINES+=("$line"); done < "$example"
    EX_EMITTED=" "
    EX_BLOCK=""
    for key in $CK_MISSING_KEYS; do
        ex_block "$key" || true
    done
    block=$EX_BLOCK
    say "    $(msg f_plan)"
    plan "$(msg f_env_keys_add "$CK_MISSING_KEYS")"
    plan "$(msg f_env_keys_keep)"
    confirm_soft || return 1
    fx_backup_env
    tmp=".env.tmp.$$"
    {
        cat .env
        printf '\n# --- %s ---\n' "$(msg f_env_keys_block "$(date +%Y-%m-%d)" "$CK_VERSION")"
        printf '%s' "$block"
    } > "$tmp" || { rm -f "$tmp"; return 2; }
    chmod 600 "$tmp"
    mv -f "$tmp" .env || return 2
    done_ "$(msg f_env_keys_done)"
    say "        docker compose up -d"
    return 0
}

fix_FIX_ENV_OBSOLETE() {
    local line key tmp=".env.tmp.$$" prefix
    [[ -n $CK_REMOVED_KEYS ]] || { info "$(msg f_env_keys_none)"; return 0; }
    say "    $(msg f_plan)"
    plan "$(msg f_obsolete_plan "$CK_REMOVED_KEYS")"
    confirm_soft || return 1
    fx_backup_env
    prefix=$(msg f_obsolete_prefix "$OPENHDID_RELEASE")
    {
        while IFS= read -r line || [[ -n $line ]]; do
            if [[ $line =~ $ENV_KEY_RE ]] && in_words "${BASH_REMATCH[1]}" "$CK_REMOVED_KEYS"; then
                printf '# %s%s\n' "$prefix" "$line"
            else
                printf '%s\n' "$line"
            fi
        done < .env
    } > "$tmp" || { rm -f "$tmp"; return 2; }
    chmod 600 "$tmp"
    mv -f "$tmp" .env || return 2
    done_ "$(msg f_obsolete_done)"
    return 0
}

fix_FIX_APP_KEY() {
    local project volumes volume found="" containers
    [[ -z $(ev APP_KEY) ]] || { say "    $(msg f_appkey_present)"; return 2; }
    project=$(ev_or COMPOSE_PROJECT_NAME openhdid)
    volumes=$(dk volume ls --format '{{.Name}}' 2>/dev/null || true)
    for volume in $volumes; do
        [[ $volume == "${project}_db" || $volume == "${project}_storage" ]] && found="$found $volume"
    done
    containers=$(dc ps -a -q 2>/dev/null || true)
    if [[ -n $found || -n ${containers//[[:space:]]/} ]]; then
        say "    $(msg f_appkey_data "${found# }")"
        say "        ./backup.sh list"
        return 2
    fi
    say "    $(msg f_plan)"
    plan "$(msg f_appkey_plan)"
    confirm_soft || return 1
    VERSION=${CK_VERSION:-$OPENHDID_RELEASE}
    gen_secrets APP_KEY
    fx_backup_env
    env_reset
    env_set APP_KEY "$G_APP_KEY"
    fx_render
    done_ "$(msg f_appkey_done "${#G_APP_KEY}")"
    return 0
}

fix_FIX_REDIS_PASSWORD_FILE() {
    local file value=""
    file=$(ev REDIS_PASSWORD_FILE)
    if [[ -f $file && -r $file ]]; then
        IFS= read -r value < "$file" || true
        value=${value%$'\r'}
        if ! v_password "$value"; then
            say "    $(msg f_rpf_unusable "$file")"
            value=""
        elif ! ask_yes_no "$(msg f_rpf_use "$file" "${#value}")" y y; then
            value=""
        fi
    fi
    say "    $(msg f_plan)"
    plan "$(msg f_rpf_plan)"
    plan "$(msg f_valkey_restart)"
    confirm_soft || return 1
    if [[ -z $value ]]; then
        VERSION=${CK_VERSION:-$OPENHDID_RELEASE}
        gen_secrets REDIS_PASSWORD
        value=$G_REDIS_PASSWORD
        info "$(msg g_secret_generated REDIS_PASSWORD "${#value}")"
    fi
    fx_backup_env
    env_reset
    UNSET_KEYS=" REDIS_PASSWORD_FILE "
    env_set REDIS_PASSWORD "$value"
    fx_render
    dc up -d --force-recreate --no-deps valkey valkey-sessions || return 2
    fx_up || return 2
    return 0
}

# The jobs still waiting in the database queues (queue:monitor of the worker); empty when unknown.
db_queue_size() {
    local out line total="" re='\[([0-9]+)\]'
    out=$(dc exec -T worker php artisan queue:monitor "$1" 2>/dev/null) || return 0
    while IFS= read -r line; do
        if [[ $line =~ $re ]]; then total=$((${total:-0} + BASH_REMATCH[1])); fi
    done <<<"$out"
    printf '%s' "$total"
}

fix_FIX_DB_TO_REDIS() {
    local stores="" key profiles new_profiles redis_host bundled=0 pw queue_db=0 list q total tries=0 choice valkey_was=0 workers=""
    for key in SESSION_DRIVER CACHE_STORE QUEUE_CONNECTION; do
        [[ $(ev "$key") == database ]] && stores="$stores $key"
    done
    [[ $(ev QUEUE_CONNECTION) == database ]] && queue_db=1
    redis_host=$(ev_or REDIS_HOST valkey)
    [[ $redis_host == valkey ]] && bundled=1
    profiles=$(ev COMPOSE_PROFILES)
    new_profiles=$profiles
    if [[ $bundled == 1 && ,${profiles// /}, != *,redis,* ]]; then new_profiles="${profiles:+$profiles,}redis"; fi
    pw=$(ev REDIS_PASSWORD)
    if [[ -z $pw && $bundled == 0 ]]; then
        say "    $(msg f_db2r_external_pw "$redis_host")"
        return 2
    fi
    say "    $(msg f_db2r_effect)"
    say "    $(msg f_plan)"
    [[ $new_profiles == "$profiles" ]] || plan "$(msg f_env_key COMPOSE_PROFILES "$new_profiles")"
    [[ -n $pw ]] || plan "$(msg f_db2r_password)"
    if [[ $queue_db == 1 ]]; then
        plan "docker compose stop web scheduler   ($(msg f_db2r_stop_why))"
        plan "$(msg f_db2r_drain)"
    fi
    [[ -z ${stores// /} ]] || plan "$(msg f_db2r_keys "${stores# }")"
    plan "docker compose up -d --wait"
    confirm_soft || return 1
    fx_backup_env
    [[ -n $pw ]] || { VERSION=${CK_VERSION:-$OPENHDID_RELEASE}; gen_secrets REDIS_PASSWORD; }
    if [[ $queue_db == 1 ]]; then
        info "$(msg f_running "docker compose stop web scheduler")"
        dc stop web scheduler || { say "    $(msg f_up_failed)"; return 2; }
        running worker || workers="worker"
        if configured sync-worker && ! running sync-worker; then workers="$workers sync-worker"; fi
        if [[ -n ${workers// /} ]]; then
            info "$(msg f_running "docker compose up -d --no-deps ${workers# }")"
            # shellcheck disable=SC2086 # one service per word
            dc up -d --no-deps $workers || true
        fi
        list="database:default"
        for q in "$(ev HDID_PRIORITY_QUEUE)" "$(ev EMD_SYNC_QUEUE)"; do
            [[ -n $q && $q != default && ,$list, != *",database:$q,"* ]] && list="$list,database:$q"
        done
        while :; do
            total=$(db_queue_size "$list")
            info "$(msg f_db2r_waiting "$list" "${total:-?}")"
            [[ $total == 0 ]] && break
            tries=$((tries + 1))
            if [[ $tries -ge 60 || -z $total ]]; then
                say "    $(msg f_db2r_not_empty "${total:-?}")"
                choice=2
                if [[ $YES != 1 ]]; then
                    printf '    %s ' "$(msg f_db2r_choice)"
                    read_answer choice || choice=2
                    [[ -t 0 ]] || printf '\n'
                fi
                case $choice in
                    1) tries=0 ;;
                    3) say "    $(msg f_db2r_left "${total:-?}")"; break ;;
                    *)
                        dc up -d web scheduler || true
                        say "    $(msg f_db2r_cancelled)"
                        return 1
                        ;;
                esac
            fi
            sleep 10
        done
    fi
    env_reset
    for key in $stores; do env_set "$key" redis; done
    [[ $new_profiles == "$profiles" ]] || env_set COMPOSE_PROFILES "$new_profiles"
    [[ -n $pw ]] || env_set REDIS_PASSWORD "$G_REDIS_PASSWORD"
    [[ $bundled == 0 || -z $(ev REDIS_PASSWORD_FILE) ]] || UNSET_KEYS=" REDIS_PASSWORD_FILE "
    fx_render
    running valkey && valkey_was=1
    if [[ $valkey_was == 1 ]]; then
        info "$(msg f_running "docker compose up -d --force-recreate --no-deps valkey valkey-sessions")"
        dc up -d --force-recreate --no-deps valkey valkey-sessions || return 2
    fi
    fx_up --wait --wait-timeout 600 || return 2
    done_ "$(msg f_db2r_done)"
    return 0
}

fix_FIX_VALKEY_RECREATE() {
    [[ $(ev_or REDIS_HOST valkey) == valkey ]] || { say "    $(msg f_valkey_external)"; return 2; }
    say "    $(msg f_plan)"
    plan "docker compose up -d --force-recreate --no-deps valkey valkey-sessions"
    plan "$(msg f_valkey_restart)"
    confirm_soft || return 1
    dc up -d --force-recreate --no-deps valkey valkey-sessions || return 2
    fx_up || return 2
    return 0
}

fix_FIX_EXEMPT() {
    local value items=() item good="" bad="" out
    value=$(ev HDID_RATE_LIMIT_EXEMPT_NETWORKS)
    IFS=', ' read -r -a items <<<"$value"
    for item in ${items[@]+"${items[@]}"}; do
        if v_exempt "$item"; then good="$good${good:+,}$item"; else bad="$bad${bad:+ }$item"; fi
    done
    [[ -n $bad ]] || { say "    $(msg f_exempt_none)"; return 2; }
    say "    $(msg f_plan)"
    plan "$(msg f_exempt_remove "$bad")"
    plan "$(msg f_env_key HDID_RATE_LIMIT_EXEMPT_NETWORKS "${good:-$(msg i_sum_empty)}")"
    plan "$(msg f_exempt_nat)"
    confirm_soft || return 1
    fx_backup_env
    env_reset
    env_set HDID_RATE_LIMIT_EXEMPT_NETWORKS "$good"
    fx_render
    out=$(dc run --rm --no-deps -T web check-env web 2>&1) || true
    if [[ $out == *"openhdid: ERROR:"*HDID_RATE_LIMIT_EXEMPT_NETWORKS* ]]; then
        cp -p "$FX_ENV_BACKUP" .env
        say "$(redact_text "$out")"
        say "    $(msg f_restored "$FX_ENV_BACKUP")"
        return 2
    fi
    fx_up || return 2
    return 0
}

fix_FIX_NGINX_WORKERS() {
    say "    $(msg f_plan)"
    plan "$(msg f_env_key OPENHDID_NGINX_WORKERS "$(msg i_sum_empty)")"
    plan "docker compose up -d web   ($(msg f_web_restart))"
    confirm_soft || return 1
    fx_backup_env
    env_reset
    env_set OPENHDID_NGINX_WORKERS ""
    fx_render
    fx_up web || return 2
    return 0
}

fix_FIX_PRIORITY_QUEUE() {
    say "    $(msg f_priority_why)"
    say "    $(msg f_plan)"
    plan "$(msg f_env_key HDID_PRIORITY_QUEUE "$(msg i_sum_empty)")"
    plan "$(msg f_restart_all)"
    confirm_soft || return 1
    fx_backup_env
    env_reset
    env_set HDID_PRIORITY_QUEUE ""
    fx_render
    fx_up || return 2
    info "$(msg f_priority_done)"
    return 0
}

fix_FIX_TLS_INSTALL() {
    local reown=0 src new old out cert key
    [[ $(ev_or OPENHDID_TLS on) == on ]] || { say "    $(msg f_tls_not_on)"; return 2; }
    has_finding TLS-01 && reown=1
    ask TLS_CERT "$(msg f_tls_q_cert)" "" v_file_rel_opt
    cert=${A_TLS_CERT:-}
    if [[ -n $cert ]]; then
        cert=$(abs_path "$cert")
        ask TLS_KEY "$(msg i_q_tls_key)" "" v_file_rel
        key=$(abs_path "$A_TLS_KEY")
    elif [[ $reown == 0 ]]; then
        say "    $(msg f_tls_need_new)"
        return 2
    fi
    mkdir -p "$STATE_DIR" && chmod 700 "$STATE_DIR"
    new="$STATE_DIR/.tmp-tls-$$"
    { mkdir "$new" && chmod 777 "$new"; } || return 2
    if [[ -n $cert || ( -r tls/fullchain.pem && -r tls/privkey.pem ) ]]; then
        src="$STATE_DIR/.tmp-tls-src-$$"
        mkdir -p "$src" && chmod 700 "$src"
        if ! cp "${cert:-tls/fullchain.pem}" "$src/fullchain.pem" || ! cp "${key:-tls/privkey.pem}" "$src/privkey.pem"; then
            rm -rf "$src" "$new"
            return 2
        fi
        COPYFILE_DISABLE=1 tar cf - -C "$src" fullchain.pem privkey.pem | run_web -v "$new":/t --entrypoint sh web \
            -c 'umask 077 && tar xf - -C /t && chmod 444 /t/fullchain.pem && chmod 400 /t/privkey.pem' || { rm -rf "$src" "$new"; return 2; }
        rm -rf "$src"
    else
        # Not readable by the operator: the container user copies its own files.
        local copy='umask 077 && cp /s/fullchain.pem /s/privkey.pem /t/ && chmod 444 /t/fullchain.pem && chmod 400 /t/privkey.pem'
        run_web -v "$DIR/tls":/s:ro -v "$new":/t --entrypoint sh web -c "$copy" </dev/null || { rm -rf "$new"; return 2; }
    fi
    # The new pair is checked before anything changes, mounted beside the live one.
    local mount=/etc/openhdid/tls-new
    if ! out=$(run_web -v "$new":$mount:ro -e OPENHDID_TLS_CERT=$mount/fullchain.pem -e OPENHDID_TLS_KEY=$mount/privkey.pem web tls-check 2>&1 </dev/null); then
        say "$(redact_text "$out")"
        say "    $(msg f_tls_check_failed)"
        rm -rf "$new"
        return 2
    fi
    say "$(redact_text "$out")"
    say "    $(msg f_plan)"
    plan "$(msg f_tls_swap)"
    running web && plan "docker compose exec -T web openhdid reload   ($(msg f_tls_reload_why))"
    confirm_soft || { rm -rf "$new"; return 1; }
    chmod 755 "$new"
    if [[ -n $(ls -A tls 2>/dev/null) ]]; then
        old="$STATE_DIR/tls-elozo-$STAMP"
        mv tls "$old" || return 2
        info "$(msg i_tls_previous ".install/$(basename "$old")")"
    else
        rmdir tls 2>/dev/null || true
    fi
    mv "$new" tls || return 2
    if running web; then
        dc exec -T web openhdid reload || { say "    $(msg f_tls_reload_failed)"; return 2; }
    fi
    done_ "$(msg i_tls_done)"
    return 0
}
v_file_rel_opt() { [[ -z $1 ]] || v_file_rel "$1"; }

fix_FIX_CA_RESTART() {
    local svc services="" out line
    for svc in web worker sync-worker scheduler; do
        running "$svc" && services="$services $svc"
    done
    [[ -n $services ]] || { say "    $(msg f_nothing_runs)"; return 2; }
    say "    $(msg f_plan)"
    plan "docker compose restart${services}   ($(msg f_short_outage))"
    confirm_soft || return 1
    # shellcheck disable=SC2086 # one service per word
    dc restart $services || return 2
    out=$(dc logs --no-color --no-log-prefix --since 5m web 2>/dev/null || true)
    while IFS= read -r line; do
        [[ $line == *"extra CA file"* ]] && info "${line#*openhdid: }"
    done <<<"$out"
    return 0
}

fix_FIX_SCHED_LOCK() {
    local item name cmd expires
    [[ $CK_ROLE != b ]] || { say "    $(msg f_sched_host_b)"; return 2; }
    running scheduler || return 2
    sched_sample || return 2
    if [[ ${#CK_STUCK[@]} == 0 ]]; then
        say "    $(msg f_sched_running_now)"
        return 2
    fi
    for item in "${CK_STUCK[@]}"; do
        IFS='|' read -r name cmd expires <<<"$item"
        info "$(msg f_sched_stuck "$name")"
    done
    say "    $(msg f_plan)"
    plan "docker compose exec -T scheduler php artisan schedule:clear-cache   ($(msg f_sched_why))"
    confirm_soft || return 1
    dc exec -T scheduler php artisan schedule:clear-cache || return 2
    info "$(msg f_sched_wait)"
    sleep 65
    return 0
}

fix_FIX_UP() {
    say "    $(msg f_plan)"
    plan "docker compose up -d --wait --wait-timeout 600   ($(msg f_up_why))"
    has_finding DB-02 && plan "$(msg f_up_migrate)"
    confirm_soft || return 1
    fx_up --wait --wait-timeout 600 || return 2
    return 0
}

cmd_fix() {
    local fix i status done_fixes=" " ids_seen=" " id func
    refuse_root
    [[ -z $FROM_DIR || -d $FROM_DIR ]] || die "$(msg i_from_missing "$FROM_DIR")"
    [[ -z $FROM_DIR ]] || FROM_DIR=$(cd -- "$FROM_DIR" && pwd -P)
    prepare_state_dir
    lock_state
    say "$(msg f_banner "$OPENHDID_RELEASE" "$DIR")"
    info "$(msg f_survey)"
    QUIET_SAVED=$QUIET
    QUIET=1
    check_run
    QUIET=$QUIET_SAVED
    VERSION=${CK_VERSION:-$OPENHDID_RELEASE}
    v_semver "$VERSION" || VERSION=$OPENHDID_RELEASE
    say "$(msg c_header "$DIR" "${CK_VERSION:-?}" "$(ck_label)")"
    for fix in $FIX_ORDER; do
        i=0
        while [[ $i -lt ${#F_ID[@]} ]]; do
            if [[ ${F_FIX[$i]} == "$fix" ]] && selected "${F_ID[$i]}"; then
                ids_seen="$ids_seen${F_ID[$i]} "
                say ""
                say "==> ${F_ID[$i]}: ${F_TITLE[$i]}"
                [[ -z ${F_DETAIL[$i]} ]] || say "    $(msg g_cause "${F_DETAIL[$i]}")"
                if in_words "$fix" "$done_fixes"; then
                    info "$(msg f_already "$fix")"
                    i=$((i + 1))
                    continue
                fi
                func="fix_${fix//-/_}"
                status=0
                "$func" "$i" || status=$?
                case $status in
                    0)
                        FX_FIXED=$((FX_FIXED + 1))
                        done_fixes="$done_fixes$fix "
                        # These two create the Valkey again with the new password already.
                        [[ $fix != FIX-DB-TO-REDIS && $fix != FIX-REDIS-PASSWORD-FILE ]] || done_fixes="${done_fixes}FIX-VALKEY-RECREATE "
                        # Every service came up again with the current files: nothing left for FIX-UP.
                        [[ $FX_UP_ALL == 0 ]] || done_fixes="${done_fixes}FIX-UP "
                        ;;
                    1) FX_SKIPPED=$((FX_SKIPPED + 1)); info "$(msg f_skipped)" ;;
                    *) FX_MANUAL=$((FX_MANUAL + 1)); info "$(msg f_manual "${F_ADVICE[$i]}")"; done_fixes="$done_fixes$fix " ;;
                esac
            fi
            i=$((i + 1))
        done
    done
    # --only names a finding without a guided fix, or none at all.
    if [[ -n $ONLY ]]; then
        local wanted
        for wanted in ${ONLY//,/ }; do
            in_words "$wanted" "$ids_seen" && continue
            if has_finding "$wanted"; then
                FX_MANUAL=$((FX_MANUAL + 1))
                i=0
                while [[ $i -lt ${#F_ID[@]} ]]; do
                    [[ ${F_ID[$i]} == "$wanted" ]] && say "    $(msg f_no_fix "$wanted" "${F_ADVICE[$i]}")"
                    i=$((i + 1))
                done
            else
                say "    $(msg f_only_none "$wanted")"
            fi
        done
    elif [[ $ids_seen == " " ]]; then
        say ""
        say "    $(msg f_none)"
    fi
    say ""
    say "$(msg f_summary "$FX_FIXED" "$FX_SKIPPED" "$FX_MANUAL")"
    say ""
    info "$(msg f_recheck)"
    check_run
    check_print
    exit "$(findings_exit_code)"
}

# ===== report =====================================================================================
# The diagnostic package: report/openhdid-report-<stamp>.tar.gz (the folder 700, the file 600) with
# the versions, the file list, .env and db.env with every secret masked, docker compose config, the
# check as JSON, hdid:health, the certificate summary and the end of every service log (secrets and
# personal data masked). It stays on this host: nothing is uploaded.

r_versions() {
    local line
    printf 'install.sh: %s\n' "$OPENHDID_RELEASE"
    printf 'OPENHDID_VERSION: %s\n' "${CK_VERSION:-?}"
    printf 'profile: %s, role: %s\n' "${CK_PROFILE:-?}" "${CK_ROLE:-?}"
    if [[ $CK_DOCKER == 1 ]]; then
        printf 'docker: %s, compose: %s, rootless: %s\n' "$(docker_server_version)" "$(compose_version)" "$( [[ ${ROOTLESS:-0} == 1 ]] && echo yes || echo no)"
        [[ $CK_COMPOSE == 0 ]] || { printf '\n'; dc images 2>&1 || true; }
    fi
    printf '\nuname: %s\n' "$(uname -a 2>/dev/null || true)"
    if [[ -r /etc/os-release ]]; then
        while IFS= read -r line; do printf 'os-release: %s\n' "$line"; done < /etc/os-release
    fi
    printf 'cpus: %s, memory MB: %s, free MB here: %s\n' "$(host_cpus)" "$(host_mem_mb)" "$(disk_free_mb "$DIR")"
}
r_files() {
    local dir file name expected actual sums="$STATE_DIR/SHA256SUMS-$CK_VERSION"
    for dir in . tls ca profiles profiles/* backup; do
        [[ -d $dir ]] || continue
        printf '== %s\n' "$dir"
        ls -ln "$dir" 2>&1 || true
    done
    printf '\n== %s\n' "$(msg r_checksums "$CK_VERSION")"
    if [[ -f $sums ]]; then
        for file in $RELEASE_FILES; do
            [[ -f $file ]] || continue
            name=$(asset_of "$file") || continue
            expected=$(sum_of "$sums" "$name") || { printf '%s: -\n' "$file"; continue; }
            actual=$(sha_of "$file")
            if [[ $actual == "$expected" ]]; then printf '%s: ok\n' "$file"; else printf '%s: %s\n' "$file" "$(msg r_differs)"; fi
        done
    else
        printf '%s\n' "$(msg c_files07_none "${CK_VERSION:-?}")"
    fi
}
r_write() { printf '%s\n' "$(redact_text "$2")" > "$1"; }

REPORT_TMP=""
cmd_report() {
    local dir="$DIR/report" tmp out name svc tls
    [[ $(id -u 2>/dev/null || echo 1000) != 0 ]] || say "$(msg c_root_note)"
    mkdir -p "$dir" || die "$(msg g_write_failed report/)"
    chmod 700 "$dir"
    tmp="$dir/.tmp-$$"
    REPORT_TMP=$tmp
    mkdir "$tmp" || die "$(msg g_write_failed "$tmp")"
    chmod 700 "$tmp"
    trap 'rm -rf "$REPORT_TMP"' EXIT
    name="openhdid-report-$STAMP.tar.gz"
    say "$(msg r_banner "$OPENHDID_RELEASE" "$DIR")"
    info "$(msg r_step_check)"
    QUIET_SAVED=$QUIET
    QUIET=1
    check_run
    findings_json > "$tmp/check.json"
    QUIET=$QUIET_SAVED
    info "$(msg r_step_files)"
    r_write "$tmp/versions.txt" "$(r_versions)"
    r_write "$tmp/files.txt" "$(r_files)"
    [[ ! -f .env ]] || r_write "$tmp/env.txt" "$(< .env)"
    [[ ! -f db.env ]] || r_write "$tmp/db-env.txt" "$(< db.env)"
    [[ ! -f k8s/openhdid-env.env ]] || r_write "$tmp/k8s-env.txt" "$(< k8s/openhdid-env.env)"
    [[ ! -f k8s/openhdid-db-env.env ]] || r_write "$tmp/k8s-db-env.txt" "$(< k8s/openhdid-db-env.env)"
    [[ ! -f k8s/kustomization.yaml ]] || r_write "$tmp/k8s-kustomization.yaml" "$(< k8s/kustomization.yaml)"
    if [[ $CK_DOCKER == 1 && $CK_COMPOSE == 1 ]]; then
        info "$(msg r_step_compose)"
        r_write "$tmp/compose-config.yaml" "$(dc config 2>&1 || true)"
        r_write "$tmp/ps.txt" "$(dc ps -a 2>&1 || true)"
        if running web; then
            r_write "$tmp/health.txt" "$(dc exec -T web php artisan hdid:health 2>&1 || true)"
            r_write "$tmp/health.json" "$(dc exec -T web php artisan hdid:health --json 2>/dev/null || true)"
        fi
        tls=$(ev_or OPENHDID_TLS on)
        if [[ $tls == on && $CK_KIND != infra ]]; then
            r_write "$tmp/tls.txt" "$(dc run --rm --no-deps -T web tls-check 2>&1 || true)"
        fi
        info "$(msg r_step_logs)"
        mkdir -p "$tmp/logs"
        for svc in $CK_PRESENT; do
            out=$(dc logs --no-color --tail 300 "$svc" 2>&1 || true)
            redact_personal "$(redact_text "$out")" > "$tmp/logs/$svc.log"
        done
    fi
    # Two steps: the macOS bsdtar pads a compressed archive written to a pipe.
    tar cf - -C "$tmp" . | gzip -c > "$dir/$name.tmp" || die "$(msg g_write_failed "report/$name")"
    chmod 600 "$dir/$name.tmp"
    mv -f "$dir/$name.tmp" "$dir/$name"
    rm -rf "$tmp"
    say ""
    say "$(msg r_done "$dir/$name")"
    exit 0
}

# ===== messages ===================================================================================
# M_<key>_hu and M_<key>_en: printf formats (%s arguments, %% for a percent sign, \n for a new line).

# --- messages: common/install ---

M_g_error_hu='HIBA: %s'
M_g_error_en='ERROR: %s'
M_g_aborted_hu='Megszakítva, semmi nem változott.'
M_g_aborted_en='Cancelled, nothing was changed.'
M_g_why_hu='miért: %s'
M_g_why_en='why: %s'
M_g_done_hu='kész: %s'
M_g_done_en='done: %s'
M_g_warning_hu='FIGYELMEZTETÉS: %s'
M_g_warning_en='WARNING: %s'
M_g_confirm_prompt_hu='Folytatja? Írja be: igen > '
M_g_confirm_prompt_en='Continue? Type yes > '
M_g_yes_auto_hu='igen (--yes)'
M_g_yes_auto_en='yes (--yes)'
M_g_yes_word_hu='igen'
M_g_yes_word_en='yes'
M_g_no_word_hu='nem'
M_g_no_word_en='no'
M_g_invalid_value_hu='Érvénytelen érték, kérem, adja meg újra.'
M_g_invalid_value_en='Invalid value, please enter it again.'
M_g_invalid_secret_hu='Érvénytelen jelszó (12–256 karakter, aposztróf és sortörés nélkül), kérem, adja meg újra.'
M_g_invalid_secret_en='Invalid password (12–256 characters, no apostrophe or line break), please enter it again.'
M_g_bad_answer_hu='a(z) %s értéke érvénytelen.'
M_g_bad_answer_en='the value of %s is invalid.'
M_g_missing_answer_hu='a(z) %s hiányzik a válaszfájlból (--yes mellett nincs kérdés).'
M_g_missing_answer_en='%s is missing from the answers file (no questions are asked with --yes).'
M_g_not_yet_hu='az „install.sh %s” parancs a következő kiadási lépésben készül el.'
M_g_not_yet_en='"install.sh %s" is completed in the next release step.'
M_g_write_failed_hu='a(z) %s nem írható (írható a mappa?).'
M_g_write_failed_en='%s cannot be written (is the folder writable?).'
M_g_backed_up_hu='mentés: %s → %s (600)'
M_g_backed_up_en='backup: %s → %s (600)'
M_g_env_block_hu='install.sh %s, %s: a telepítő által felvett kulcsok'
M_g_env_block_en='install.sh %s, %s: keys added by the installer'
M_g_env_unset_note_hu='kipróbálás: profil nélkül (install.sh)'
M_g_env_unset_note_en='trial: no usage profile (install.sh)'
M_g_locked_hu='már fut egy telepítés vagy javítás ebben a mappában (folyamat: %s).'
M_g_locked_en='an installation or a fix is already running in this folder (process: %s).'
M_g_no_downloader_hu='nincs curl és wget a gépen; a telepítő csomagot nem telepít: telepítse az egyiket (például sudo dnf install curl vagy sudo apt install curl), vagy töltse le a fájlokat, és adja meg: --from <mappa>.'
M_g_no_downloader_en='neither curl nor wget is installed; the installer installs no package: install one (for example sudo dnf install curl or sudo apt install curl), or download the files and pass --from <folder>.'
M_g_download_failed_hu='a(z) %s nem tölthető le (%s); proxy mögött a https_proxy változó, a kiadás verziója a --version kapcsoló.'
M_g_download_failed_en='%s cannot be downloaded (%s); behind a proxy set https_proxy, the release version with --version.'
M_g_sum_missing_hu='a(z) %s nem szerepel a kiadás SHA256SUMS fájljában; semmi nem került a helyére.'
M_g_sum_missing_en='%s is not listed in the SHA256SUMS of the release; nothing was put in place.'
M_g_sum_mismatch_hu='a(z) %s ellenőrzőösszege nem egyezik a kiadáséval (sérült vagy módosított fájl); semmi nem került a helyére.'
M_g_sum_mismatch_en='the checksum of %s does not match the release (damaged or modified file); nothing was put in place.'
M_g_file_ok_hu='%s: ellenőrizve'
M_g_file_ok_en='%s: checked'
M_g_file_taken_hu='%s: átvéve a --from mappából (ellenőrzés nélkül)'
M_g_file_taken_en='%s: taken from the --from folder (not verified)'
M_g_from_unverified_hu='a --from mappában nincs SHA256SUMS: a fájlokat nem ellenőrzöm (az install.sh check is jelzi).'
M_g_from_unverified_en='the --from folder holds no SHA256SUMS: the files are not verified (install.sh check says so too).'
M_g_self_differs_hu='a futó install.sh nem a v%s kiadásé (eltér az ellenőrzőösszege).'
M_g_self_differs_en='the running install.sh is not the one of release v%s (its checksum differs).'
M_g_file_differs_hu='a meglévő %s eltér a kiadásétól; a régi másolata a .install mappába kerül (saját módosítás a compose.override.yaml-ba való).'
M_g_file_differs_en='the existing %s differs from the release; a copy of the old one goes to .install (own changes belong in compose.override.yaml).'
M_g_valkey_password_chars_hu='A(z) %s nem tartalmazhat idézőjelet (") és visszaperjelet (\\): a beépített Valkey a jelszót a Compose-tól idézőjelek között kapja, így mást várna, mint amit az alkalmazás küld. Adjon meg másikat, vagy hagyja ki a válaszfájlból (akkor a telepítő készít egyet).'
M_g_valkey_password_chars_en='%s must not contain a double quote (") or a backslash (\\): the bundled Valkey gets the password from Compose between double quotes, so it would expect another one than the application sends. Give another one, or leave it out of the answers file (the installer then makes one).'
M_g_secret_failed_hu='nem sikerült véletlen titkot előállítani.'
M_g_secret_failed_en='a random secret could not be generated.'
M_g_secret_kept_hu='%s: a meglévő .env értéke marad (%s karakter)'
M_g_secret_kept_en='%s: the existing value stays (%s characters)'
M_g_secret_given_hu='%s: a megadott érték (%s karakter)'
M_g_secret_given_en='%s: the given value (%s characters)'
M_g_secret_generated_hu='%s: generált (%s karakter)'
M_g_secret_generated_en='%s: generated (%s characters)'
M_g_unknown_fact_hu='nem ellenőrizhető ezen a gépen (hiányzó eszköz)'
M_g_unknown_fact_en='cannot be checked on this host (tool missing)'
M_g_version_found_hu='talált verzió: %s'
M_g_version_found_en='version found: %s'
M_g_account_hu='fiók'
M_g_account_en='account'
M_g_cause_hu='Ok: %s'
M_g_cause_en='Cause: %s'
M_g_advice_hu='Teendő: %s'
M_g_advice_en='What to do: %s'
M_g_docref_hu='Leírás: %s'
M_g_docref_en='See: %s'
M_g_doc_guide_hu='OpenHDID telepítési útmutató'
M_g_doc_guide_en='OpenHDID installation guide'
M_g_sev_error_hu='HIBA'
M_g_sev_error_en='ERROR'
M_g_sev_warning_hu='FIGYELMEZTETÉS'
M_g_sev_warning_en='WARNING'
M_g_sev_info_hu='TÁJÉKOZTATÁS'
M_g_sev_info_en='INFO'
M_g_area_host_hu='Gép'
M_g_area_host_en='Host'
M_g_area_docker_hu='Docker'
M_g_area_docker_en='Docker'
M_g_area_files_hu='Fájlok'
M_g_area_files_en='Files'
M_g_area_env_hu='Környezet (.env)'
M_g_area_env_en='Environment (.env)'
M_g_area_tls_hu='Tanúsítvány'
M_g_area_tls_en='Certificate'
M_g_area_net_hu='Hálózat'
M_g_area_net_en='Network'
M_g_area_db_hu='Adatbázis'
M_g_area_db_en='Database'
M_g_area_svc_hu='Szolgáltatások'
M_g_area_svc_en='Services'
M_g_area_health_hu='Rendszerállapot'
M_g_area_health_en='System status'
M_g_area_valkey_hu='Valkey'
M_g_area_valkey_en='Valkey'
M_g_area_sched_hu='Ütemező'
M_g_area_sched_en='Scheduler'
M_g_area_log_hu='Naplók'
M_g_area_log_en='Logs'
M_g_area_infra_hu='Infra gép'
M_g_area_infra_en='Infra host'
M_g_area_k8s_hu='Kubernetes'
M_g_area_k8s_en='Kubernetes'
M_g_host01_t_hu='Kevés processzormag a választott profilhoz'
M_g_host01_t_en='Fewer CPUs than the chosen profile needs'
M_g_host01_d_hu='%s vCPU van, a profil géptáblázat-sora %s vCPU-t kér'
M_g_host01_d_en='%s vCPUs present, the profile row of the machine table asks for %s'
M_g_host01_a_hu='Nagyobb gép, vagy kisebb profil; 10k egy gépen 8 vCPU kell, különben a kétgépes 10k vagy az 5k profil a választás.'
M_g_host01_a_en='A larger host or a smaller profile; 10k on one host needs 8 vCPUs, otherwise choose the two-host 10k or the 5k profile.'
M_g_host02_t_hu='Kevés memória a választott profilhoz'
M_g_host02_t_en='Less memory than the chosen profile needs'
M_g_host02_d_hu='%s MB van, a profil %s GB-ot kér (a memóriaplafonok összege és 1 GB az operációs rendszernek)'
M_g_host02_d_en='%s MB present, the profile asks for %s GB (the sum of the memory ceilings and 1 GB for the operating system)'
M_g_host02_a_hu='Kevesebb memóriánál csúcsterheléskor a kernel leállíthat egy konténert; nagyobb gép vagy kisebb profil (OPENHDID_FPM_MAX_CHILDREN csak a plafonnal együtt csökkenthető).'
M_g_host02_a_en='With less memory the kernel may stop a container at peak load; a larger host or a smaller profile (lower OPENHDID_FPM_MAX_CHILDREN only together with its ceiling).'
M_g_host03_t_hu='Kevés szabad lemezhely'
M_g_host03_t_en='Little free disk space'
M_g_host03_d_hu='%s: %s GB szabad, a profilhoz legalább %s GB ajánlott'
M_g_host03_d_en='%s: %s GB free, at least %s GB is recommended for the profile'
M_g_host03_a_hu='Lemez bővítése vagy régi image-ek, mentések áthelyezése (docker image prune csak a nem használt image-eket törli; a mentéseket a gépen kívülre).'
M_g_host03_a_en='Extend the disk or move old images and backups away (docker image prune removes unused images only; keep backups off the host).'
M_g_host04_t_hu='A gép órája nincs szinkronizálva (NTP)'
M_g_host04_t_en='The host clock is not synchronised (NTP)'
M_g_host04_d_hu='a HMAC-aláírás 300 mp-es ablaka és az SSO-tokenek a pontos időre épülnek'
M_g_host04_d_en='the 300 s window of the HMAC signatures and the SSO tokens rely on the exact time'
M_g_host04_a_hu='Időszinkron bekapcsolása (sudo timedatectl set-ntp true, vagy chrony); a telepítő ezt csak javasolja.'
M_g_host04_a_en='Turn time synchronisation on (sudo timedatectl set-ntp true, or chrony); the installer only suggests it.'
M_g_host05_t_hu='A telepítő rootként fut'
M_g_host05_t_en='The installer runs as root'
M_g_host05_a_hu='A docker csoport tagjaként (rootless Dockernél a démon tulajdonosaként) futtassa; a mappa tulajdonosa ez a fiók legyen. Root tulajdonú fájlokat a backup.sh és az üzemeltető nem tud kezelni.'
M_g_host05_a_en='Run it as a member of the docker group (with rootless Docker as the daemon owner); that account should own the folder. Files owned by root cannot be handled by backup.sh and the operator.'
M_g_host06_t_hu='A(z) %s port foglalt'
M_g_host06_t_en='Port %s is in use'
M_g_host06_d_hu='egy másik szolgáltatás már figyel a(z) %s porton'
M_g_host06_d_en='another service already listens on port %s'
M_g_host06_a_hu='A portot foglaló szolgáltatás leállítása (például egy régi nginx), vagy más port (OPENHDID_HTTPS_PORT, OPENHDID_HTTP_PORT) egy előtét mögött.'
M_g_host06_a_en='Stop the service holding the port (for example an old nginx), or another port (OPENHDID_HTTPS_PORT, OPENHDID_HTTP_PORT) behind a front end.'
M_g_host07_t_hu='Rootless Dockerrel a(z) %s port nem köthető'
M_g_host07_t_en='Port %s cannot be bound with rootless Docker'
M_g_host07_d_hu='a(z) %s port az ip_unprivileged_port_start (%s) alatt van'
M_g_host07_d_en='port %s is below ip_unprivileged_port_start (%s)'
M_g_host07_a_hu='sudo sysctl -w net.ipv4.ip_unprivileged_port_start=0 (tartósan /etc/sysctl.d/ alatt), vagy 8443/8080 port egy előtét vagy átirányítás mögött; a telepítő csak javasolja.'
M_g_host07_a_en='sudo sysctl -w net.ipv4.ip_unprivileged_port_start=0 (permanently under /etc/sysctl.d/), or ports 8443/8080 behind a front end or a redirect; the installer only suggests it.'
M_g_docker01_t_hu='A Docker nem érhető el'
M_g_docker01_t_en='Docker is not reachable'
M_g_docker01_none_hu='nincs docker parancs'
M_g_docker01_none_en='there is no docker command'
M_g_docker01_install_hu='Docker CE a gyártó tárolójából (a telepítési útmutató 2. fejezetének parancsai); a telepítő csomagot nem telepít.'
M_g_docker01_install_en='Docker CE from the vendor repository (the commands of chapter 2 of the installation guide); the installer installs no package.'
# shellcheck disable=SC2016 # a command to copy, not expanded here
M_g_docker01_a_hu='A felhasználó legyen a docker csoport tagja, utána új bejelentkezés (a tagság root-egyenértékű); rootless Dockernél: export DOCKER_HOST=unix:///run/user/$(id -u)/docker.sock.'
# shellcheck disable=SC2016 # a command to copy, not expanded here
M_g_docker01_a_en='Make the user a member of the docker group, then sign in again (membership equals root); with rootless Docker: export DOCKER_HOST=unix:///run/user/$(id -u)/docker.sock.'
M_g_docker02_t_hu='A Docker Engine 27-nél régebbi'
M_g_docker02_t_en='Docker Engine is older than 27'
M_g_docker02_a_hu='Docker CE frissítése a gyártó tárolójából; a telepítő csomagot nem telepít.'
M_g_docker02_a_en='Update Docker CE from the vendor repository; the installer installs no package.'
M_g_docker03_t_hu='A Docker Compose v2 hiányzik vagy 2.24-nél régebbi'
M_g_docker03_t_en='Docker Compose v2 is missing or older than 2.24'
M_g_docker03_none_hu='a docker compose parancs nem válaszol'
M_g_docker03_none_en='docker compose does not answer'
M_g_docker03_a_hu='A docker-compose-plugin frissítése a Docker tárolójából.'
M_g_docker03_a_en='Update docker-compose-plugin from the Docker repository.'
M_g_docker04_t_hu='Podman válaszol a docker parancsra (nem támogatott)'
M_g_docker04_t_en='Podman answers the docker command (not supported)'
M_g_docker04_a_hu='A Podman eltávolítása és Docker CE telepítése; a DOCKER_HOST és a docker context törlése.'
M_g_docker04_a_en='Remove Podman and install Docker CE; clear DOCKER_HOST and the docker context.'
M_g_docker05_t_hu='Rootless Docker'
M_g_docker05_t_en='Rootless Docker'
M_g_docker05_a_hu='Nincs teendő; a tanúsítványt a telepítő a konténer felhasználójával teszi a helyére, a backup.sh-val azonos módon.'
M_g_docker05_a_en='Nothing to do; the installer puts the certificate in place through the container user, as backup.sh does.'
M_g_docker06_t_hu='A Docker-démonnak nincs proxyja'
M_g_docker06_t_en='The Docker daemon has no proxy'
M_g_docker06_d_hu='a felhasználónak van https_proxy változója, a démonnak nincs: az image letöltése időtúllépéssel elhal'
M_g_docker06_d_en='the user has an https_proxy variable, the daemon has none: pulling the image times out'
M_g_docker06_a_hu='A démon proxyja systemd drop-innal (a TROUBLESHOOTING.md 4. pontjának sudo parancsai); a telepítő csak kiírja őket.'
M_g_docker06_a_en='Give the daemon a proxy with a systemd drop-in (the sudo commands of TROUBLESHOOTING.md, item 4); the installer only prints them.'
M_g_docker07_t_hu='Hiányzó image'
M_g_docker07_t_en='Missing image'
M_g_docker07_a_hu='docker compose pull (proxy mögött előbb a démon proxyja).'
M_g_docker07_a_en='docker compose pull (behind a proxy the daemon proxy first).'
M_g_docker08_t_hu='Rootless Docker a kétgépes 10k profilban'
M_g_docker08_t_en='Rootless Docker in the two-host 10k profile'
M_g_docker08_a_hu='A rootless démon NFS-kötetet nem csatol: az alkalmazásgépeken rootful Docker (docker csoport), vagy a gazdagépen csatolt NFS (fstab, root kell) és egy compose.override.yaml bind kötete; a telepítő egyiket sem csinálja meg.'
M_g_docker08_a_en='A rootless daemon mounts no NFS volume: rootful Docker (docker group) on the application hosts, or NFS mounted by the host (fstab, needs root) and a bind volume in compose.override.yaml; the installer does neither.'

M_i_root_hu='a telepítő nem fut rootként: root tulajdonú fájlokat hagyna, amelyeket a backup.sh és az üzemeltető nem kezel. Futtassa a docker csoport tagjaként (rootless Dockernél a démon tulajdonosaként), és a mappa tulajdonosa ez a fiók legyen.'
M_i_root_en='the installer does not run as root: it would leave files owned by root that backup.sh and the operator cannot handle. Run it as a member of the docker group (with rootless Docker as the daemon owner) and let that account own the folder.'
M_i_banner_hu='OpenHDID telepítő %s – mappa: %s'
M_i_banner_en='OpenHDID installer %s – folder: %s'
M_i_yes_needs_answers_hu='a --yes csak válaszfájllal (--answers) használható: próbához és füstteszthez, élesre nem ajánlott.'
M_i_yes_needs_answers_en='--yes works only with an answers file (--answers): for trials and smoke tests, not recommended for production.'
M_i_from_missing_hu='a --from mappa nem létezik: %s'
M_i_from_missing_en='the --from folder does not exist: %s'
M_i_answers_missing_hu='a válaszfájl nem olvasható: %s'
M_i_answers_missing_en='the answers file cannot be read: %s'
M_i_answers_perm_hu='a(z) %s válaszfájlt más is olvashatja (jog: %s) vagy nem az Öné; titkokat tartalmaz: chmod 600 <fájl>, és a saját fiókjával.'
M_i_answers_perm_en='the answers file %s is readable by others (mode %s) or not yours; it holds secrets: chmod 600 <file>, and with your own account.'
M_i_answers_header_hu='a(z) %s első sora nem „# openhdid-answers 1”: ez nem a telepítő válaszfájlja.'
M_i_answers_header_en='the first line of %s is not "# openhdid-answers 1": this is not an answers file of the installer.'
M_i_answers_line_hu='%s, %s. sor: KULCS=érték alak kell; semmi nem futott le.'
M_i_answers_line_en='%s, line %s: a KEY=value line is required; nothing was run.'
M_i_answers_key_hu='%s, %s. sor: ismeretlen kulcs: %s; semmi nem futott le.'
M_i_answers_key_en='%s, line %s: unknown key: %s; nothing was run.'
M_i_answers_dup_hu='%s, %s. sor: a(z) %s kétszer szerepel; semmi nem futott le.'
M_i_answers_dup_en='%s, line %s: %s appears twice; nothing was run.'
M_i_answers_value_hu='%s, %s. sor: a(z) %s értéke érvénytelen; semmi nem futott le.'
M_i_answers_value_en='%s, line %s: the value of %s is invalid; nothing was run.'
M_i_answers_loaded_hu='Válaszfájl: %s (a benne lévő értékeket nem kérdezem újra).'
M_i_answers_loaded_en='Answers file: %s (its values are not asked again).'
M_i_bad_profile_hu='ismeretlen profil: %s (trial, 5k, 10k-single, 10k-ha, 20k)'
M_i_bad_profile_en='unknown profile: %s (trial, 5k, 10k-single, 10k-ha, 20k)'
M_i_profile_mismatch_hu='a --profile (%s) és a válaszfájl profilja (%s) eltér.'
M_i_profile_mismatch_en='--profile (%s) and the profile of the answers file (%s) differ.'
M_i_bad_role_hu='ismeretlen szerep: %s (a, b, infra)'
M_i_bad_role_en='unknown role: %s (a, b, infra)'
M_i_role_mismatch_hu='a --role (%s) és a válaszfájl szerepe (%s) eltér.'
M_i_role_mismatch_en='--role (%s) and the role of the answers file (%s) differ.'
M_i_bad_version_hu='érvénytelen kiadási verzió: %s (például 1.15.0)'
M_i_bad_version_en='invalid release version: %s (for example 1.15.0)'
M_i_menu_hu='Mit szeretne telepíteni?\n  1) Kipróbálás (profil nélkül, önaláírt tanúsítvány, demóadat; 2 vCPU, 8 GB)\n  2) 5k egy gépen (legfeljebb 5 000 ügyfél; 4 vCPU, 10 GB)\n  3) 10k egy gépen (legfeljebb 10 000 ügyfél, magas rendelkezésre állás nélkül; 8 vCPU, 16 GB)\n  4) 10k két alkalmazásgéppel (A, B és egy infra gép; terheléselosztó és NFS kell)\n  5) 20k Kubernetes (a telepítő a fájlokat és a kubectl lépéseket adja)'
M_i_menu_en='What would you like to install?\n  1) Trial (no profile, self-signed certificate, demo data; 2 vCPU, 8 GB)\n  2) 5k on one host (up to 5,000 clients; 4 vCPU, 10 GB)\n  3) 10k on one host (up to 10,000 clients, without high availability; 8 vCPU, 16 GB)\n  4) 10k with two application hosts (A, B and an infra host; needs a load balancer and NFS)\n  5) 20k Kubernetes (the installer gives the files and the kubectl steps)'
M_i_menu_q_hu='Választás'
M_i_menu_q_en='Choice'
M_i_about_trial_hu='Kipróbálás: egy gép (2 vCPU, 8 GB RAM, 20 GB lemez), profil nélkül, önaláírt tanúsítvánnyal és demóadatokkal. Nem éles használatra: a demófiókok jelszava nyilvános, a böngésző figyelmeztet a tanúsítványra.'
M_i_about_trial_en='Trial: one host (2 vCPU, 8 GB RAM, 20 GB disk), no profile, a self-signed certificate and demo data. Not for production: the demo accounts have public passwords, browsers warn about the certificate.'
M_i_about_5k_hu='5k egy gépen: 4 vCPU, 10 GB RAM, 80 GB lemez; legfeljebb 5 000 ügyfél (250–400 egyidejű). A gép kiesése a szolgáltatás kiesése; e fölött a 10k profilok valók.'
M_i_about_5k_en='5k on one host: 4 vCPU, 10 GB RAM, 80 GB disk; up to 5,000 clients (250–400 at a time). Losing the host stops the service; above this the 10k profiles fit.'
M_i_about_10k_single_hu='10k egy gépen: 8 vCPU, 16 GB RAM, 120 GB lemez; legfeljebb 10 000 ügyfél (500–800 egyidejű). Nincs magas rendelkezésre állás: a gép kiesése a szolgáltatás kiesése; ha ez nem elfogadható, a kétgépes 10k vagy a Kubernetes a választás.'
M_i_about_10k_single_en='10k on one host: 8 vCPU, 16 GB RAM, 120 GB disk; up to 10,000 clients (500–800 at a time). No high availability: losing the host stops the service; if that is not acceptable, choose the two-host 10k or Kubernetes.'
M_i_about_10k_ha_hu='10k két alkalmazásgéppel: A és B gép (egyenként 4 vCPU, 6 GB, 40 GB) terheléselosztó mögött, közös NFS-sel, és egy infra gép (4 vCPU, 10 GB, 100 GB: MariaDB, fő és munkamenet-Valkey) vagy saját külső adatbázis és Valkey. Egy alkalmazásgép kiesésekor a másik viszi a normál csúcsot. Sorrend: infra, A, B.'
M_i_about_10k_ha_en='10k with two application hosts: hosts A and B (4 vCPU, 6 GB, 40 GB each) behind a load balancer with shared NFS, and an infra host (4 vCPU, 10 GB, 100 GB: MariaDB, main and session Valkey) or your own external database and Valkey. When one application host fails, the other carries the normal peak. Order: infra, A, B.'
M_i_about_20k_hu='20k Kubernetes: csomópontkészlet 3 × (4 vCPU, 12 GB), menedzselt MariaDB és két Valkey. A telepítő a titok- és beállításfájlokat és egy saját overlay-t készít, és kiírja a kubectl lépéseket; a fürtön semmit nem változtat.'
M_i_about_20k_en='20k Kubernetes: node pool 3 × (4 vCPU, 12 GB), managed MariaDB and two Valkeys. The installer writes the secret and settings files and an overlay of its own and prints the kubectl steps; it changes nothing on the cluster.'
M_i_role_menu_hu='Melyik gépen vagyunk?\n  a) A – elsődleges alkalmazásgép (ütemező, ügyféltörzs-feldolgozó, a migráció)\n  b) B – másodlagos alkalmazásgép (web és feldolgozó)\n  infra) infra gép (MariaDB, fő és munkamenet-Valkey a telepítővel)'
M_i_role_menu_en='Which host is this?\n  a) A – primary application host (scheduler, master data worker, the migration)\n  b) B – secondary application host (web and worker)\n  infra) infra host (MariaDB, main and session Valkey by the installer)'
M_i_role_q_hu='Szerep (a, b, infra)'
M_i_role_q_en='Role (a, b, infra)'
M_i_about_role_a_hu='Szerep: A (elsődleges). Itt fut az ütemező és az ügyféltörzs-feldolgozó (pontosan egy példány), és itt fut először a migráció.'
M_i_about_role_a_en='Role: A (primary). The scheduler and the master data worker run here (exactly one each), and the migration runs here first.'
M_i_about_role_b_hu='Szerep: B (másodlagos). Web és feldolgozó; minden közös érték a válaszfájlból jön.'
M_i_about_role_b_en='Role: B (secondary). Web and worker; every shared value comes from the answers file.'
M_i_about_role_infra_hu='Szerep: infra gép. MariaDB (két fiókkal), fő Valkey és munkamenet-Valkey; a végén a közös értékek a válaszfájlba kerülnek az A és a B gépnek.'
M_i_about_role_infra_en='Role: infra host. MariaDB (two accounts), main Valkey and session Valkey; at the end the shared values go into the answers file for hosts A and B.'
M_i_role_name_a_hu='A gép (elsődleges)'
M_i_role_name_a_en='host A (primary)'
M_i_role_name_b_hu='B gép (másodlagos)'
M_i_role_name_b_en='host B (secondary)'
M_i_role_name_infra_hu='infra gép'
M_i_role_name_infra_en='infra host'
M_i_step_machine_hu='A gép és a Docker'
M_i_step_machine_en='The host and Docker'
M_i_why_machine_hu='a profil géptáblázat-sorával vetem össze a gépet, és megnézem, hogy a Docker használható-e root nélkül.'
M_i_why_machine_en='the host is compared with the profile row of the machine table, and Docker is checked to work without root.'
M_i_machine_errors_hu='a gép vagy a Docker nem alkalmas a telepítésre (lásd fent); semmi nem változott.'
M_i_machine_errors_en='the host or Docker is not fit for the installation (see above); nothing was changed.'
M_i_machine_warnings_hu='A fenti figyelmeztetések mellett is folytatható, de a profil mérete így nem garantált.'
M_i_machine_warnings_en='You may continue despite the warnings above, but the size of the profile is then not guaranteed.'
M_i_machine_ok_hu='%s ellenőrzés rendben'
M_i_machine_ok_en='%s checks passed'
M_i_existing_hu='Ez már egy telepítés (%s, Compose-projekt: %s): van .env, és vannak konténerek vagy kötetek.\nSemmi nem változott.\n  Ellenőrzés:  ./install.sh check\n  Javítás:     ./install.sh fix\n  Frissítés:   UPGRADE.md'
M_i_existing_en='This is already an installation (%s, Compose project: %s): there is a .env and containers or volumes.\nNothing was changed.\n  Check:    ./install.sh check\n  Repair:   ./install.sh fix\n  Upgrade:  UPGRADE.md'
M_i_partial_volumes_hu='Félbemaradt telepítés: a telepítő itt már megírta a .env-et, de a rendszert még nem indította el (nincs konténer); a kötetek (%s) a saját egyszeri konténereitől valók, adat még nincs bennük. A meglévő titkok (APP_KEY, jelszavak) maradnak, a .env és a db.env írás előtt mentést kap.'
M_i_partial_volumes_en='Interrupted installation: the installer already wrote .env here but has not started the stack yet (no container); the volumes (%s) come from its own one-off containers and hold no data yet. The existing secrets (APP_KEY, passwords) stay, .env and db.env are backed up before they are written.'
M_i_partial_hu='Félbemaradt telepítés: van .env, de nincs konténer és kötet. A meglévő titkok (APP_KEY, jelszavak) maradnak, a .env és a db.env írás előtt mentést kap.'
M_i_partial_en='Interrupted installation: there is a .env but no container or volume. The existing secrets (APP_KEY, passwords) stay, .env and db.env are backed up before they change.'
M_i_project_taken_hu='A(z) „%s” Compose-projektnek már vannak konténerei vagy kötetei ezen a gépen (%s), de ebben a mappában nincs .env.'
M_i_project_taken_en='The Compose project "%s" already has containers or volumes on this host (%s), but this folder holds no .env.'
M_i_volume_without_env_hu='a(z) „%s” projekt kötete (%s) már létezik, de itt nincs .env. Ha egy másik telepítés használja ezt a nevet, válasszon másik projektnevet (PROJECT_NAME). Ha egy korábbi telepítés adata, a kötetekben titkosított adat lehet: a régi APP_KEY-t a mentésből kell visszaírni (backup/…/config.tar.gz, ./backup.sh list); új kulcsot nem generálok.'
M_i_volume_without_env_en='a volume of the project "%s" (%s) exists but there is no .env here. If another installation uses this name, choose another project name (PROJECT_NAME). If it is the data of an earlier installation, the volumes may hold encrypted data: the old APP_KEY must come back from the backup (backup/…/config.tar.gz, ./backup.sh list); no new key is generated.'
M_i_q_project_hu='Projektnév ehhez a telepítéshez (kisbetű, szám, -, _)'
M_i_q_project_en='Project name for this installation (lowercase, digits, -, _)'
M_i_step_questions_hu='Kérdések'
M_i_step_questions_en='Questions'
M_i_why_questions_hu='a .env értékei: cím, portok, tanúsítvány; a többi az env.example alapértéke marad (a felületen állítható üzleti beállítások nem itt vannak).'
M_i_why_questions_en='the values of .env: address, ports, certificate; everything else keeps the env.example default (the business settings are set in the panel, not here).'
M_i_why_questions_a_hu='a két gépen közös értékek (cím, adatbázis, Valkey, NFS) a válaszfájlból jönnek; ami hiányzik, azt most kérdezem.'
M_i_why_questions_a_en='the values shared by both hosts (address, database, Valkey, NFS) come from the answers file; what it lacks is asked now.'
M_i_why_questions_b_hu='a B gép minden közös értéket a válaszfájlból kap; csak a saját portját és figyelő címét kérdezem.'
M_i_why_questions_b_en='host B takes every shared value from the answers file; only its own port and listening address are asked.'
M_i_why_questions_infra_hu='az infra gép címe, portjai és fiókjai; a jelszavakat a telepítő generálja, és a végén a válaszfájlba teszi.'
M_i_why_questions_infra_en='the address, ports and accounts of the infra host; the installer generates the passwords and puts them into the answers file at the end.'
M_i_why_questions_k8s_hu='a fürt adatai és a menedzselt adatbázis és Valkey címe; a fájlok a k8s mappába kerülnek.'
M_i_why_questions_k8s_en='the cluster details and the addresses of the managed database and Valkey; the files go into the k8s folder.'
M_i_q_https_port_hu='HTTPS-port'
M_i_q_https_port_en='HTTPS port'
M_i_q_http_port_hu='HTTP-port'
M_i_q_http_port_en='HTTP port'
M_i_q_bind_hu='Figyelő cím (0.0.0.0 = minden IPv4-cím)'
M_i_q_bind_en='Listening address (0.0.0.0 = every IPv4 address)'
M_i_q_app_url_hu='A webhely címe (APP_URL)'
M_i_q_app_url_en='Address of the site (APP_URL)'
M_i_q_app_url_req_hu='A webhely nyilvános címe (APP_URL, https://név, a DNS-ben ezzel a névvel)'
M_i_q_app_url_req_en='Public address of the site (APP_URL, https://name, with this name in DNS)'
M_i_q_app_url_infra_hu='A webhely nyilvános címe (APP_URL; üresen az A gép kérdezi)'
M_i_q_app_url_infra_en='Public address of the site (APP_URL; empty: host A asks for it)'
M_i_q_demo_hu='Demóadatok betöltése (true/false)'
M_i_q_demo_en='Load the demo data (true/false)'
M_i_q_app_env_hu='Környezet (production, staging, demo)'
M_i_q_app_env_en='Environment (production, staging, demo)'
M_i_demo_needs_env_hu='a demóadat csak APP_ENV=demo mellett tölthető be (a demófiókok jelszava nyilvános).'
M_i_demo_needs_env_en='demo data loads only with APP_ENV=demo (the demo accounts have public passwords).'
M_i_q_tls_hu='TLS (on = saját tanúsítvány; off = TLS-t lezáró terheléselosztó mögött; selfsigned = csak próbára)'
M_i_q_tls_en='TLS (on = own certificate; off = behind a TLS-terminating load balancer; selfsigned = trials only)'
M_i_q_tls_cert_hu='A teljes lánc (kiszolgáló + köztes tanúsítvány) PEM-fájlja'
M_i_q_tls_cert_en='The PEM file of the full chain (server + intermediate certificate)'
M_i_q_tls_key_hu='A titkos kulcs PEM-fájlja (jelszó nélkül)'
M_i_q_tls_key_en='The PEM file of the private key (without a password)'
M_i_q_lb_hu='A terheléselosztó címe(i) (vesszővel; TRUSTED_PROXIES és OPENHDID_MONITORING_ALLOW)'
M_i_q_lb_en='Address(es) of the load balancer (comma-separated; TRUSTED_PROXIES and OPENHDID_MONITORING_ALLOW)'
M_i_q_lb_infra_hu='A terheléselosztó címe(i) (üresen az A gép kérdezi)'
M_i_q_lb_infra_en='Address(es) of the load balancer (empty: host A asks for them)'
M_i_q_exempt_hu='Kéréskorlát alól mentesített hálózatok (például a vállalat NAT-címe; legfeljebb /8; üres = senki)'
M_i_q_exempt_en='Networks exempt from the rate limits (for example the company NAT address; at most /8; empty = none)'
M_i_q_admin_email_hu='Az első rendszergazda e-mail-címe (üres = később, a kiírt paranccsal)'
M_i_q_admin_email_en='E-mail address of the first administrator (empty = later, with the printed command)'
M_i_q_admin_name_hu='Az első rendszergazda neve'
M_i_q_admin_name_en='Name of the first administrator'
M_i_admin_default_name_hu='Rendszergazda'
M_i_admin_default_name_en='Administrator'
M_i_q_infra_bind_hu='Figyelő cím: a gép privát címe, amelyen az A és a B gép eléri'
M_i_q_infra_bind_en='Listening address: the private address of this host that hosts A and B reach'
M_i_infra_bind_all_hu='a 0.0.0.0 minden címen nyitja a MariaDB-t és a Valkey-t, amelyek itt titkosítatlanul beszélnek: privát cím és tűzfal ajánlott.'
M_i_infra_bind_all_en='0.0.0.0 opens MariaDB and Valkey on every address, and they talk unencrypted here: a private address and a firewall are recommended.'
M_i_q_infra_address_hu='Az infra gép címe az A és a B gép felől (DB_HOST, REDIS_HOST)'
M_i_q_infra_address_en='Address of the infra host as hosts A and B see it (DB_HOST, REDIS_HOST)'
M_i_q_infra_db_port_hu='MariaDB-port'
M_i_q_infra_db_port_en='MariaDB port'
M_i_q_infra_valkey_port_hu='A fő Valkey portja'
M_i_q_infra_valkey_port_en='Port of the main Valkey'
M_i_q_infra_sessions_port_hu='A munkamenet-Valkey portja'
M_i_q_infra_sessions_port_en='Port of the session Valkey'
M_i_q_app_hosts_hu='Az A és a B gép címe (vesszővel; a tűzfal- és NFS-parancsokhoz)'
M_i_q_app_hosts_en='Addresses of hosts A and B (comma-separated; for the firewall and NFS commands)'
M_i_q_db_name_hu='Adatbázis neve'
M_i_q_db_name_en='Database name'
M_i_q_db_user_hu='Futási fiók (csak adatot olvas és ír)'
M_i_q_db_user_en='Runtime account (reads and writes rows only)'
M_i_q_db_migrate_user_hu='Migrációs fiók (a sémát módosítja)'
M_i_q_db_migrate_user_en='Migration account (changes the schema)'
M_i_q_db_host_hu='Az adatbázis gépe (DB_HOST)'
M_i_q_db_host_en='Database host (DB_HOST)'
M_i_q_db_port_hu='Az adatbázis portja'
M_i_q_db_port_en='Database port'
M_i_q_db_password_hu='A futási fiók jelszava (nem jelenik meg)'
M_i_q_db_password_en='Password of the runtime account (not shown)'
M_i_q_db_migrate_password_hu='A migrációs fiók jelszava (nem jelenik meg)'
M_i_q_db_migrate_password_en='Password of the migration account (not shown)'
M_i_q_redis_host_hu='A fő Valkey gépe (REDIS_HOST)'
M_i_q_redis_host_en='Host of the main Valkey (REDIS_HOST)'
M_i_q_redis_port_hu='A fő Valkey portja'
M_i_q_redis_port_en='Port of the main Valkey'
M_i_q_redis_password_hu='A fő Valkey jelszava (nem jelenik meg)'
M_i_q_redis_password_en='Password of the main Valkey (not shown)'
M_i_q_sessions_host_hu='A munkamenet-Valkey gépe (REDIS_SESSION_HOST)'
M_i_q_sessions_host_en='Host of the session Valkey (REDIS_SESSION_HOST)'
M_i_q_sessions_port_hu='A munkamenet-Valkey portja'
M_i_q_sessions_port_en='Port of the session Valkey'
M_i_q_sessions_password_hu='A munkamenet-Valkey jelszava (üres = a fő Valkey jelszava; nem jelenik meg)'
M_i_q_sessions_password_en='Password of the session Valkey (empty = the main Valkey password; not shown)'
M_i_q_prefix_hu='A kulcsok előtagja a Valkey-ben (REDIS_PREFIX, kötőjellel a végén)'
M_i_q_prefix_en='Key prefix in Valkey (REDIS_PREFIX, ending in a hyphen)'
M_i_q_nfs_server_hu='A közös storage NFS-kiszolgálója'
M_i_q_nfs_server_en='NFS server of the shared storage'
M_i_q_nfs_path_hu='Az exportált útvonal'
M_i_q_nfs_path_en='The exported path'
M_i_q_k8s_ns_hu='Névtér'
M_i_q_k8s_ns_en='Namespace'
M_i_q_k8s_ingress_ns_hu='Az ingress-vezérlő névtere'
M_i_q_k8s_ingress_ns_en='Namespace of the ingress controller'
M_i_q_k8s_pod_cidr_hu='Az ingress-vezérlő pod-hálózata (TRUSTED_PROXIES; nem lehet „mindenki”)'
M_i_q_k8s_pod_cidr_en='Pod network of the ingress controller (TRUSTED_PROXIES; never "everyone")'
M_i_q_k8s_storage_hu='Tárolóosztály a közös kötethez (ReadWriteMany; üres = a fürt alapértelmezése)'
M_i_q_k8s_storage_en='Storage class of the shared volume (ReadWriteMany; empty = the cluster default)'
M_i_q_tls_cert_k8s_hu='A tanúsítvány (teljes lánc) útvonala a kubectl parancshoz (üres = később)'
M_i_q_tls_cert_k8s_en='Path of the certificate (full chain) for the kubectl command (empty = later)'
M_i_q_tls_key_k8s_hu='A kulcs útvonala a kubectl parancshoz (üres = később)'
M_i_q_tls_key_k8s_en='Path of the key for the kubectl command (empty = later)'
M_i_q_k8s_ca_hu='Belső CA-k mappája (üres = nincs)'
M_i_q_k8s_ca_en='Folder of internal CAs (empty = none)'
M_i_answers_from_a_hu='a(z) %s hiányzik a válaszfájlból: a B gép válaszfájlját az A vagy az infra gép telepítője írja (openhdid-10k-answers.txt).'
M_i_answers_from_a_en='%s is missing from the answers file: the answers file of host B is written by the installer of host A or of the infra host (openhdid-10k-answers.txt).'
M_i_key_differs_hu='a meglévő .env APP_KEY-je eltér a válaszfájlétól: a két gépnek ugyanaz a kulcs kell. Semmi nem változott.'
M_i_key_differs_en='the APP_KEY of the existing .env differs from the answers file: both hosts need the same key. Nothing was changed.'
M_i_ports_taken_hu='a szükséges portok egyike foglalt (lásd fent); semmi nem változott.'
M_i_ports_taken_en='one of the ports needed is in use (see above); nothing was changed.'
M_i_summary_title_hu='Összegzés – ez fog történni:'
M_i_summary_title_en='Summary – this is what will happen:'
M_i_sum_folder_hu='mappa'
M_i_sum_folder_en='folder'
M_i_sum_project_hu='Compose-projekt'
M_i_sum_project_en='Compose project'
M_i_sum_ports_hu='portok'
M_i_sum_ports_en='ports'
M_i_sum_listen_hu='figyelő cím'
M_i_sum_listen_en='listening address'
M_i_sum_sessions_hu='munkamenet'
M_i_sum_sessions_en='sessions'
M_i_sum_files_hu='megváltozó fájlok'
M_i_sum_files_en='files that change'
M_i_sum_empty_hu='(üres)'
M_i_sum_empty_en='(empty)'
M_i_sum_kept_hu='(a meglévő .env-ből, %s karakter)'
M_i_sum_kept_en='(from the existing .env, %s characters)'
M_i_sum_given_hu='(megadva, %s karakter)'
M_i_sum_given_en='(given, %s characters)'
M_i_sum_generated_hu='(a telepítő generálja, 48 karakter)'
M_i_sum_generated_en='(generated by the installer, 48 characters)'
M_i_sum_generated_key_hu='(a telepítő generálja: 32 véletlen bájt, base64)'
M_i_sum_generated_key_en='(generated by the installer: 32 random bytes, base64)'
M_i_sum_same_as_main_hu='(a fő Valkey jelszava)'
M_i_sum_same_as_main_en='(the main Valkey password)'
M_i_sum_tls_lb_hu='a TLS a terheléselosztón zárul le'
M_i_sum_tls_lb_en='TLS ends at the load balancer'
M_i_sum_primary_hu='ütemező és ügyféltörzs-feldolgozó ezen a gépen'
M_i_sum_primary_en='scheduler and master data worker on this host'
M_i_sum_secondary_hu='(üres: web és feldolgozó)'
M_i_sum_secondary_en='(empty: web and worker)'
M_i_sum_backup_hu='A meglévő .env és db.env írás előtt mentést kap: .env.bak-<időbélyeg>, db.env.bak-<időbélyeg> (600).'
M_i_sum_backup_en='The existing .env and db.env are backed up before they change: .env.bak-<stamp>, db.env.bak-<stamp> (600).'
M_i_sum_migrations_hu='Az első indításkor a MariaDB létrehozza az adatbázist és a két fiókot, és lefutnak az adatbázis-migrációk.'
M_i_sum_migrations_en='At the first start MariaDB creates the database and the two accounts, and the database migrations run.'
M_i_sum_migrations_a_hu='Az indításkor a közös adatbázison lefutnak az adatbázis-migrációk (a közös Valkey zárja alatt, egyszerre egy gépen).'
M_i_sum_migrations_a_en='At the start the database migrations run on the shared database (under the lock of the shared Valkey, one host at a time).'
M_i_sum_migrations_b_hu='Az indításkor a migráció a közös Valkey zárja alatt fut; az A gép után már nincs teendője („Nothing to migrate”).'
M_i_sum_migrations_b_en='At the start the migration runs under the lock of the shared Valkey; after host A it has nothing left to do ("Nothing to migrate").'
M_i_sum_infra_start_hu='Az első indításkor a MariaDB létrehozza az adatbázist, a futási és a migrációs fiókot; a két Valkey jelszóval indul.'
M_i_sum_infra_start_en='At the first start MariaDB creates the database, the runtime and the migration account; both Valkeys start with a password.'
M_i_sum_k8s_nothing_hu='A fürtön semmi nem változik: a kubectl lépéseket a végén írom ki.'
M_i_sum_k8s_nothing_en='Nothing changes on the cluster: the kubectl steps are printed at the end.'
M_i_sum_check_hu='ellenőrzés'
M_i_sum_check_en='check'
M_i_sum_log_hu='napló (titok nélkül)'
M_i_sum_log_en='log (without secrets)'
M_i_sum_warnings_hu='figyelmeztetések'
M_i_sum_warnings_en='warnings'
M_i_check_code_hu='kilépési kód %s (0 rendben, 1 figyelmeztetés, 2 hiba)'
M_i_check_code_en='exit code %s (0 fine, 1 warnings, 2 errors)'
M_i_step_download_hu='A kiadás fájljai'
M_i_step_download_en='The release files'
M_i_why_download_hu='a v%s kiadás mellékletei, mindegyik az ellenőrzőösszegével (SHA256SUMS) összevetve; csak egyezés után kerülnek a helyükre.'
M_i_why_download_en='the assets of release v%s, each compared with its checksum (SHA256SUMS); they are put in place only when all match.'
M_i_download_done_hu='a fájlok a helyükön'
M_i_download_done_en='the files are in place'
M_i_step_images_hu='Image-ek'
M_i_step_images_en='Images'
M_i_why_images_hu='az alkalmazás, a MariaDB és a Valkey image-e (a compose.yaml rögzített digestjeivel); ami helyben megvan, azt nem töltöm le.'
M_i_why_images_en='the images of the application, MariaDB and Valkey (with the pinned digests of compose.yaml); what is present locally is not pulled.'
M_i_why_images_app_hu='csak az alkalmazás image-e: az adatbázis és a Valkey közös, külső szolgáltatás.'
M_i_why_images_app_en='the application image only: the database and Valkey are shared, external services.'
M_i_why_images_infra_hu='a MariaDB és a Valkey image-e (a compose.yaml-éval azonos digesttel).'
M_i_why_images_infra_en='the MariaDB and Valkey images (with the same digests as compose.yaml).'
M_i_image_present_hu='%s: helyben megvan, nem töltöm le'
M_i_image_present_en='%s: present locally, not pulled'
M_i_image_pull_hu='%s: letöltés'
M_i_image_pull_en='%s: pulling'
M_i_image_failed_hu='a(z) %s nem tölthető le; proxy mögött a démonnak is proxy kell (TROUBLESHOOTING.md 4.).'
M_i_image_failed_en='%s cannot be pulled; behind a proxy the daemon needs one too (TROUBLESHOOTING.md 4.).'
M_i_images_done_hu='az image-ek megvannak'
M_i_images_done_en='the images are present'
M_i_step_secrets_hu='Titkok'
M_i_step_secrets_en='Secrets'
M_i_why_secrets_hu='az APP_KEY és a jelszavak az alkalmazás image-ével, hálózat nélkül készülnek; csak a hosszuk jelenik meg.'
M_i_why_secrets_en='the APP_KEY and the passwords are made with the application image, without network; only their length is shown.'
M_i_why_secrets_app_hu='a két gép ugyanazt az APP_KEY-t és jelszavakat használja: a válaszfájlból, vagy (saját infrastruktúránál) a megadott értékekből.'
M_i_why_secrets_app_en='both hosts use the same APP_KEY and passwords: from the answers file or (with your own infrastructure) the values given.'
M_i_why_secrets_infra_hu='a jelszavak és az alkalmazásgépek közös APP_KEY-je a gép véletlenforrásából (/dev/urandom); csak a hosszuk jelenik meg.'
M_i_why_secrets_infra_en='the passwords and the shared APP_KEY of the application hosts come from the host random source (/dev/urandom); only their length is shown.'
M_i_why_secrets_k8s_hu='az APP_KEY a gép véletlenforrásából, ha még nincs; a többi titok a menedzselt szolgáltatásoké.'
M_i_why_secrets_k8s_en='the APP_KEY from the host random source, unless it exists; the other secrets belong to the managed services.'
M_i_secrets_done_hu='a titkok megvannak (nem jelennek meg)'
M_i_secrets_done_en='the secrets are ready (not shown)'
M_i_step_env_hu='A .env és a db.env'
M_i_step_env_en='.env and db.env'
M_i_why_env_hu='a .env az env.example-ből készül: csak a fenti kulcsok értéke változik, minden megjegyzés marad; a db.env a migrációs fiókot kapja. Mindkettő 600-as jogú.'
M_i_why_env_en='.env is made from env.example: only the values of the keys above change, every comment stays; db.env gets the migration account. Both have mode 600.'
M_i_why_env_infra_hu='az infra gép .env-je csak a saját kulcsait tartalmazza (megjegyzésekkel); a db.env a MariaDB root-jelszavát és a migrációs fiókot. Mindkettő 600-as jogú.'
M_i_why_env_infra_en='the .env of the infra host holds its own keys only (with comments); db.env the MariaDB root password and the migration account. Both have mode 600.'
M_i_env_written_hu='%s megírva (600)'
M_i_env_written_en='%s written (600)'
M_i_env_done_hu='a beállítási fájlok elkészültek'
M_i_env_done_en='the settings files are written'
M_i_override_added_hu='a compose.override.yaml a COMPOSE_FILE végére került (a Compose a COMPOSE_FILE mellett magától nem olvassa; TROUBLESHOOTING.md 7.)'
M_i_override_added_en='compose.override.yaml went to the end of COMPOSE_FILE (Compose does not read it on its own next to COMPOSE_FILE; TROUBLESHOOTING.md 7.)'
M_i_step_tls_hu='Tanúsítvány'
M_i_step_tls_en='Certificate'
M_i_why_tls_hu='a tanúsítványt és a kulcsot a konténer felhasználója (uid 82) teszi a tls mappába, root és sudo nélkül, rootless Dockerrel is; utána a konténer ellenőrzi (kulcspár, lánc, név, lejárat).'
M_i_why_tls_en='the container user (uid 82) puts the certificate and the key into the tls folder, without root or sudo, also with rootless Docker; then the container checks them (key pair, chain, name, expiry).'
M_i_why_tls_app_hu='a TLS a terheléselosztón zárul le (OPENHDID_TLS=off): ezen a gépen nincs tanúsítvány.'
M_i_why_tls_app_en='TLS ends at the load balancer (OPENHDID_TLS=off): there is no certificate on this host.'
M_i_tls_selfsigned_hu='önaláírt, 30 napos tanúsítvány a konténer memóriájában: nincs fájl (csak kipróbáláshoz).'
M_i_tls_selfsigned_en='a self-signed 30-day certificate in the container memory: no file (for trials only).'
M_i_tls_off_hu='OPENHDID_TLS=off: a TLS a terheléselosztón zárul le, ezen a gépen nincs tanúsítványfájl.'
M_i_tls_off_en='OPENHDID_TLS=off: TLS ends at the load balancer, there is no certificate file on this host.'
M_i_tls_read_hu='a(z) %s nem olvasható.'
M_i_tls_read_en='%s cannot be read.'
M_i_tls_install_failed_hu='a tanúsítvány nem került a helyére (docker compose run web); a tls mappa változatlan.'
M_i_tls_install_failed_en='the certificate was not put in place (docker compose run web); the tls folder is unchanged.'
M_i_tls_previous_hu='a tls mappa korábbi tartalma: %s'
M_i_tls_previous_en='the previous content of the tls folder: %s'
M_i_tls_check_failed_hu='a tanúsítvány ellenőrzése nem sikerült (lásd fent): teljes lánc és a hozzá tartozó kulcs kell, a név az APP_URL gépneve.'
M_i_tls_check_failed_en='the certificate check failed (see above): the full chain and its key are needed, the name of the APP_URL host.'
M_i_tls_done_hu='a tanúsítvány a helyén, a konténer felhasználójának tulajdonában'
M_i_tls_done_en='the certificate is in place, owned by the container user'
M_i_step_config_hu='Konfiguráció ellenőrzése'
M_i_step_config_en='Configuration check'
M_i_why_config_hu='a Compose-fájlok összeállnak-e, és a konténer saját induláskori ellenőrzése (check-env) elfogadja-e a .env-et; hibánál nem indítok el semmit.'
M_i_why_config_en='whether the Compose files merge, and whether the container start check (check-env) accepts .env; nothing starts on an error.'
M_i_why_config_infra_hu='a profiles/10k/infra.yaml és a .env összeáll-e.'
M_i_why_config_infra_en='whether profiles/10k/infra.yaml and .env merge.'
M_i_compose_invalid_hu='a docker compose config hibát ad (lásd fent, a titkok kitakarva); semmi nem indult el.'
M_i_compose_invalid_en='docker compose config reports an error (see above, secrets masked); nothing was started.'
M_i_compose_ok_hu='docker compose config: rendben'
M_i_compose_ok_en='docker compose config: fine'
M_i_container_check_failed_hu='a konténer induláskori ellenőrzése hibát talált (lásd fent); javítsa a .env-ben, majd futtassa újra a telepítőt. Semmi nem indult el.'
M_i_container_check_failed_en='the container start check found an error (see above); fix it in .env and run the installer again. Nothing was started.'
M_i_config_done_hu='a konfiguráció rendben'
M_i_config_done_en='the configuration is fine'
M_i_step_start_hu='Indítás'
M_i_step_start_en='Start'
M_i_why_start_hu='docker compose up -d: az első indításkor a MariaDB létrehozza az adatbázist és a két fiókot, a migrate lefuttatja a migrációkat, majd a web, a worker, a sync-worker és az ütemező indul.'
M_i_why_start_en='docker compose up -d: at the first start MariaDB creates the database and the two accounts, migrate runs the migrations, then web, worker, sync-worker and the scheduler start.'
M_i_why_start_a_hu='docker compose up -d: a migrate a közös adatbázison lefuttatja a migrációkat, majd a web, a worker, a sync-worker és az ütemező indul.'
M_i_why_start_a_en='docker compose up -d: migrate runs the migrations on the shared database, then web, worker, sync-worker and the scheduler start.'
M_i_why_start_b_hu='docker compose up -d: a migrate a közös Valkey zárja alatt fut (az A gép után nincs teendője), majd a web és a worker indul.'
M_i_why_start_b_en='docker compose up -d: migrate runs under the lock of the shared Valkey (after host A it has nothing to do), then web and worker start.'
M_i_why_start_infra_hu='docker compose up -d: a MariaDB létrehozza az adatbázist és a két fiókot, a két Valkey a jelszóval indul.'
M_i_why_start_infra_en='docker compose up -d: MariaDB creates the database and the two accounts, both Valkeys start with their password.'
M_i_start_failed_hu='a szolgáltatások nem indultak el egészségesen (lásd fent, a titkok kitakarva); TROUBLESHOOTING.md 3. A javítás után: docker compose up -d, ellenőrzés: ./install.sh check'
M_i_start_failed_en='the services did not start healthy (see above, secrets masked); TROUBLESHOOTING.md 3. After the fix: docker compose up -d, check: ./install.sh check'
M_i_started_hu='a szolgáltatások futnak'
M_i_started_en='the services are running'
M_i_step_check_hu='Ellenőrzés'
M_i_step_check_en='Check'
M_i_why_check_hu='a telepítés teljes ellenőrzése (install.sh check): Docker, .env, tanúsítvány, adatbázis, Valkey, szolgáltatások, rendszerállapot.'
M_i_why_check_en='the full check of the installation (install.sh check): Docker, .env, certificate, database, Valkey, services, system status.'
M_i_why_check_infra_hu='a három szolgáltatás állapota és a kötelező Valkey-politikák (install.sh check); utána a válaszfájl az A és a B gépnek.'
M_i_why_check_infra_en='the state of the three services and the mandatory Valkey policies (install.sh check); then the answers file for hosts A and B.'
M_i_why_check_k8s_hu='az overlay felépül-e (kubectl kustomize, csak helyben, a fürthöz nem nyúl).'
M_i_why_check_k8s_en='whether the overlay builds (kubectl kustomize, locally only, the cluster is not touched).'
M_i_check_later_hu='az „install.sh check” a következő kiadási lépésben készül el; addig: docker compose ps és docker compose exec web php artisan hdid:health'
M_i_check_later_en='"install.sh check" is completed in the next release step; meanwhile: docker compose ps and docker compose exec web php artisan hdid:health'
M_i_step_admin_hu='Az első rendszergazda'
M_i_step_admin_en='The first administrator'
M_i_why_admin_hu='a hdid:make-admin parancs a jelszót maga kéri, rejtve: a jelszó így nem kerül parancssorba és fájlba.'
M_i_why_admin_en='hdid:make-admin asks for the password itself, hidden: it never reaches a command line or a file.'
M_i_admin_trial_hu='kipróbálásnál a demóadatok a demófiókokat is betöltik (telepítési útmutató 3. fejezet); saját rendszergazda: docker compose exec web php artisan hdid:make-admin <e-mail>'
M_i_admin_trial_en='for a trial the demo data brings the demo accounts (installation guide, chapter 3); an own administrator: docker compose exec web php artisan hdid:make-admin <e-mail>'
M_i_admin_later_hu='nincs megadva e-mail-cím: a parancs a következő teendők között.'
M_i_admin_later_en='no e-mail address given: the command is among the next steps.'
M_i_admin_no_terminal_hu='nincs terminál (vagy --yes): a jelszót nem lehet rejtve bekérni, a parancs a következő teendők között.'
M_i_admin_no_terminal_en='no terminal (or --yes): the password cannot be asked hidden, the command is among the next steps.'
M_i_admin_done_hu='rendszergazda: %s'
M_i_admin_done_en='administrator: %s'
M_i_admin_failed_hu='a rendszergazda létrehozása nem sikerült; a parancs a következő teendők között.'
M_i_admin_failed_en='creating the administrator failed; the command is among the next steps.'
M_i_admin_on_a_hu='a rendszergazdát az A gépen kell létrehozni (közös adatbázis).'
M_i_admin_on_a_en='the administrator is created on host A (shared database).'
M_i_step_summary_hu='Összefoglaló és következő teendők'
M_i_step_summary_en='Summary and next steps'
M_i_final_title_hu='Összefoglaló:'
M_i_final_title_en='Summary:'
M_i_next_title_hu='Következő teendők (a gépen kívüli lépéseket a telepítő nem hajtja végre):'
M_i_next_title_en='Next steps (the installer does not perform steps outside this host):'
M_i_next_admin_hu='Az első rendszergazda (a jelszót a parancs kéri):'
M_i_next_admin_en='The first administrator (the command asks for the password):'
M_i_next_trial_hu='Megnyitás: %s (a böngésző az önaláírt tanúsítványra figyelmeztet; a demófiókok: telepítési útmutató 3. fejezet).'
M_i_next_trial_en='Open: %s (the browser warns about the self-signed certificate; the demo accounts: installation guide, chapter 3).'
M_i_next_trial_end_hu='A próba vége (minden adat törlődik; élesítés előtt kötelező):'
M_i_next_trial_end_en='End of the trial (every data is deleted; required before going live):'
M_i_next_dns_hu='DNS-rekord: %s → a gép címe (vagy a terheléselosztóé).'
M_i_next_dns_en='DNS record: %s → the address of this host (or of the load balancer).'
M_i_next_dns_lb_hu='DNS-rekord: %s → a terheléselosztó címe.'
M_i_next_dns_lb_en='DNS record: %s → the address of the load balancer.'
M_i_next_tls_hu='A tanúsítvány megújítása lejárat előtt (telepítési útmutató 6.3), újraindítás nélkül:'
M_i_next_tls_en='Renew the certificate before it expires (installation guide 6.3), without a restart:'
M_i_next_tls_off_hu='A terheléselosztó (%s) zárja le a TLS-t; az élő-próba a /up címen, a Host fejlécben az APP_URL gépnevével.'
M_i_next_tls_off_en='The load balancer (%s) ends TLS; the live probe is /up with the APP_URL host name in the Host header.'
M_i_next_firewall_hu='Tűzfal (csak a szükséges portok; firewalld vagy ufw):'
M_i_next_firewall_en='Firewall (only the ports needed; firewalld or ufw):'
M_i_next_firewall_app_hu='Tűzfal: a(z) %s port csak a terheléselosztó felől:'
M_i_next_firewall_app_en='Firewall: port %s from the load balancer only:'
M_i_next_firewall_infra_hu='Tűzfal: a MariaDB és a két Valkey portja csak az A és a B gép felől:'
M_i_next_firewall_infra_en='Firewall: the MariaDB and both Valkey ports from hosts A and B only:'
M_i_next_integrations_hu='Levelezés, SMS, ügyféltörzs (telepítési útmutató 11. fejezet; amíg nincs bekötve, az ellenőrzés figyelmeztet), próba:'
M_i_next_integrations_en='Mail, SMS, master data (installation guide, chapter 11; until connected the check warns), test:'
M_i_next_api_key_hu='A telefonközpont kulcsa:'
M_i_next_api_key_en='The key of the call centre:'
M_i_next_backup_hu='Napi mentés a saját crontabba (a backup.sh fejléce szerint; a mentést a gépen kívülre, titkosítva):'
M_i_next_backup_en='Daily backup in your own crontab (as the backup.sh header says; keep the backup off the host, encrypted):'
M_i_next_check_hu='Rendszeres ellenőrzés:'
M_i_next_check_en='Regular check:'
M_i_next_install_b_hu='A B gép: a válaszfájlt és a telepítőt másolja át, és ott futtassa:'
M_i_next_install_b_en='Host B: copy the answers file and the installer there and run:'
M_i_next_lb_hu='Terheléselosztó (HAProxy-minta; saját elosztó esetén az használandó, ugyanezekkel a beállításokkal):'
M_i_next_lb_en='Load balancer (HAProxy sample; with your own balancer use that one, with the same settings):'
M_i_next_nfs_check_hu='Az NFS-export ellenőrzése (az infra gép teendőinél írt export kell):'
M_i_next_nfs_check_en='Check the NFS export (the export printed among the infra host steps is needed):'
M_i_next_answers_delete_hu='A válaszfájl törlése, ha a B gép is kész (minden titkot tartalmaz), mindhárom gépen:'
M_i_next_answers_delete_en='Delete the answers file once host B is done (it holds every secret), on all three hosts:'
M_i_next_copy_answers_hu='A válaszfájl (%s, 600) az A és a B gépre (használat után mindhárom gépen törölni kell):'
M_i_next_copy_answers_en='The answers file (%s, 600) to hosts A and B (delete it on all three hosts after use):'
M_i_next_then_a_b_hu='utána előbb az A, majd a B gépen:'
M_i_next_then_a_b_en='then first on host A, then on host B:'
M_i_next_all_three_hu='mindhárom gépen, ha a B is kész'
M_i_next_all_three_en='on all three hosts once B is done'
M_i_next_nfs_hu='NFS-export ezen a gépen (root kell, a telepítő csak kiírja):'
M_i_next_nfs_en='NFS export on this host (needs root, the installer only prints it):'
M_i_next_nfs_other_hu='NFS-export a(z) %s gépen (root kell, a telepítő csak kiírja):'
M_i_next_nfs_other_en='NFS export on host %s (needs root, the installer only prints it):'
M_i_nfs_rootless_hu='rootless alkalmazásgépnél a leképezett azonosító: a subuid kezdete + 81'
M_i_nfs_rootless_en='with a rootless application host the mapped id: the start of its subuid range + 81'
M_i_nfs_check_on_ab_hu='az A és a B gépen'
M_i_nfs_check_on_ab_en='on hosts A and B'
M_i_next_backup_infra_hu='Mentés (a backup.sh az alkalmazásgépé; itt az adatbázis-mentés, a jelszó a konténer környezetéből):'
M_i_next_backup_infra_en='Backup (backup.sh belongs to the application hosts; here the database dump, the password from the container environment):'
M_i_next_replica_hu='MariaDB-replika (ajánlott; kézi lépések, a telepítő nem csinálja meg):'
M_i_next_replica_en='MariaDB replica (recommended; manual steps, the installer does not do them):'
M_i_replica_1_hu='1. Egy második gépen ugyanez a mappa (profiles/10k/infra.yaml, .env, db.env másolata), csak a mariadb szolgáltatással.'
M_i_replica_1_en='1. On a second host the same folder (a copy of profiles/10k/infra.yaml, .env, db.env), with the mariadb service only.'
M_i_replica_2_hu='2. Bináris napló mindkét gépen egy compose.override.yaml-lal (a COMPOSE_FILE végére: :compose.override.yaml), majd docker compose up -d mariadb:'
M_i_replica_2_en='2. Binary log on both hosts with a compose.override.yaml (at the end of COMPOSE_FILE: :compose.override.yaml), then docker compose up -d mariadb:'
M_i_replica_replica_id_hu='a replikán --server-id=2 és --read-only=1'
M_i_replica_replica_id_en='on the replica --server-id=2 and --read-only=1'
M_i_replica_3_hu='3. Replikációs fiók ezen a gépen (a root-jelszó a db.env-ben; a jelszót a kliens kéri):'
M_i_replica_3_en='3. A replication account on this host (the root password is in db.env; the client asks for it):'
M_i_replica_address_hu='a replika címe'
M_i_replica_address_en='replica address'
M_i_replica_4_hu='4. Konzisztens pillanatkép ezen a gépen, betöltés a replikán:'
M_i_replica_4_en='4. A consistent snapshot on this host, loaded on the replica:'
M_i_replica_on_replica_hu='a replikán'
M_i_replica_on_replica_en='on the replica'
M_i_replica_5_hu='5. A replikán (docker compose exec mariadb mariadb -uroot -p), ellenőrzés: Slave_IO_Running és Slave_SQL_Running = Yes:'
M_i_replica_5_en='5. On the replica (docker compose exec mariadb mariadb -uroot -p), check: Slave_IO_Running and Slave_SQL_Running = Yes:'
M_i_replica_6_hu='6. Átállás kézzel: DB_HOST a replika címe az A és a B gép .env-jében, majd docker compose up -d mindkét gépen.'
M_i_replica_6_en='6. Failover by hand: DB_HOST = the replica address in the .env of hosts A and B, then docker compose up -d on both.'
M_i_haproxy_note_hu='# /etc/haproxy/haproxy.cfg (részlet); a tanúsítvány a kulccsal egy PEM-fájlban'
M_i_haproxy_note_en='# /etc/haproxy/haproxy.cfg (excerpt); the certificate with its key in one PEM file'
M_i_haproxy_trust_hu='Az elosztó címe a TRUSTED_PROXIES és az OPENHDID_MONITORING_ALLOW sorban van (különben a /up 404, a kliens-cím az elosztóé lenne).'
M_i_haproxy_trust_en='The balancer address is in TRUSTED_PROXIES and OPENHDID_MONITORING_ALLOW (otherwise /up answers 404 and the client address would be the balancer one).'
M_i_answers_file_note_hu='A kétgépes 10k közös értékei és titkai (%s). Másolja az A és a B gépre (chmod 600), használat után mindhárom gépen törölje.'
M_i_answers_file_note_en='The shared values and secrets of the two-host 10k (%s). Copy it to hosts A and B (chmod 600), delete it on all three hosts after use.'
M_i_answers_written_hu='a válaszfájl megírva: %s (600; minden titkot tartalmaz, használat után törlendő)'
M_i_answers_written_en='the answers file is written: %s (600; it holds every secret, delete it after use)'
M_i_answers_overwrite_hu='a(z) %s már létezik és eltér; felülírom.'
M_i_answers_overwrite_en='%s exists and differs; it will be overwritten.'
M_i_answers_append_hu='A(z) %s fájlba a most megadott közös értékek is bekerülnek a B gépnek: %s'
M_i_answers_append_en='The shared values given now also go into %s for host B: %s'
M_i_answers_appended_hu='a válaszfájl kiegészítve'
M_i_answers_appended_en='the answers file is completed'
M_i_answers_delete_q_hu='Törölhetem a válaszfájlt (%s)? Minden titkot tartalmaz.'
M_i_answers_delete_q_en='May I delete the answers file (%s)? It holds every secret.'
M_i_answers_deleted_hu='törölve: %s (az A és az infra gépen is törölje)'
M_i_answers_deleted_en='deleted: %s (delete it on host A and the infra host too)'
M_i_answers_kept_hu='a válaszfájl (%s) megmaradt: minden titkot tartalmaz, törölje mindhárom gépen.'
M_i_answers_kept_en='the answers file (%s) was kept: it holds every secret, delete it on all three hosts.'
M_i_answers_kept_yes_hu='--yes mellett nem törlöm a válaszfájlt (%s): minden titkot tartalmaz, törölje mindhárom gépen.'
M_i_answers_kept_yes_en='with --yes the answers file (%s) is not deleted: it holds every secret, delete it on all three hosts.'
M_i_step_tools_hu='Eszközök'
M_i_step_tools_en='Tools'
M_i_why_tools_k8s_hu='a fürthöz a kubectl kell (a telepítő csak helyben futtat kustomize-t); a manifesztekhez a kiadás címkéjének klónja (git) vagy a --from mappa.'
M_i_why_tools_k8s_en='the cluster needs kubectl (the installer runs kustomize locally only); the manifests come from a clone of the release tag (git) or the --from folder.'
M_i_tool_found_hu='%s: megvan'
M_i_tool_found_en='%s: present'
M_i_tool_missing_hu='%s: nincs (a kiírt lépésekhez kell)'
M_i_tool_missing_en='%s: missing (needed for the printed steps)'
M_i_k8s_existing_hu='A k8s mappa már tartalmaz beállításfájlokat: az APP_KEY-jük marad, a többit újraírom.'
M_i_k8s_existing_en='The k8s folder already holds settings files: their APP_KEY stays, the rest is rewritten.'
M_i_k8s_copied_hu='a kubernetes manifesztek a --from mappából: k8s/kubernetes'
M_i_k8s_copied_en='the kubernetes manifests from the --from folder: k8s/kubernetes'
M_i_k8s_clone_hu='a manifesztek a kiadás címkéjéről (ezt futtassa a kubectl lépések előtt):'
M_i_k8s_clone_en='the manifests come from the release tag (run this before the kubectl steps):'
M_i_step_k8s_files_hu='Titok- és beállításfájlok, overlay'
M_i_step_k8s_files_en='Secret and settings files, overlay'
M_i_why_k8s_files_hu='a kubectl create secret --from-env-file az idézőjelet az érték részének veszi: a fájlok idézőjel és megjegyzés nélkül, 600-as joggal; az overlay a képcímkét és a kiszolgálónevet javítja.'
M_i_why_k8s_files_en='kubectl create secret --from-env-file keeps quotes as part of the value: the files have no quotes or comments, mode 600; the overlay sets the image tag and the host name.'
M_i_k8s_written_hu='k8s/kustomization.yaml, k8s/openhdid-env.env, k8s/openhdid-db-env.env (600)'
M_i_k8s_written_en='k8s/kustomization.yaml, k8s/openhdid-env.env, k8s/openhdid-db-env.env (600)'
M_i_steps_file_hu='LEPESEK.txt'
M_i_steps_file_en='STEPS.txt'
M_i_kustomize_ok_hu='kubectl kustomize k8s: rendben'
M_i_kustomize_ok_en='kubectl kustomize k8s: fine'
M_i_kustomize_failed_hu='az overlay nem épül fel (lásd fent); a kubernetes mappa útvonala és a javítások a k8s/kustomization.yaml-ban.'
M_i_kustomize_failed_en='the overlay does not build (see above); the kubernetes folder path and the patches are in k8s/kustomization.yaml.'
M_i_kustomize_skipped_hu='kihagyva: nincs kubectl vagy még nincs meg a kubernetes mappa.'
M_i_kustomize_skipped_en='skipped: no kubectl or the kubernetes folder is not there yet.'
M_i_k8s_steps_intro_hu='A fürtön (a telepítő nem futtatja; a fájlok a k8s mappában):'
M_i_k8s_steps_intro_en='On the cluster (the installer does not run these; the files are in the k8s folder):'
M_i_k8s_no_ca_hu='belső CA nélkül nincs configmap (később: kubectl -n <névtér> create configmap openhdid-ca --from-file=ca/)'
M_i_k8s_no_ca_en='without an internal CA there is no configmap (later: kubectl -n <namespace> create configmap openhdid-ca --from-file=ca/)'
M_i_k8s_dns_hu='DNS-rekord: %s → az ingress-vezérlő címe.'
M_i_k8s_dns_en='DNS record: %s → the address of the ingress controller.'
M_i_finished_hu='Kész.'
M_i_finished_en='Done.'
M_i_finished_warnings_hu='Kész, %s figyelmeztetéssel (lásd fent).'
M_i_finished_warnings_en='Done, with %s warnings (see above).'
M_i_help_hu='Használat: ./install.sh [install|check|fix|report] [kapcsolók]; részletek a szkript fejlécében (./install.sh --help).'
M_i_help_en='Usage: ./install.sh [install|check|fix|report] [options]; details in the script header (./install.sh --help).'
M_i_unknown_option_hu='ismeretlen kapcsoló vagy paraméter: %s (súgó: ./install.sh --help)'
M_i_unknown_option_en='unknown option or argument: %s (help: ./install.sh --help)'
M_i_option_value_hu='a(z) %s után érték kell.'
M_i_option_value_en='%s needs a value.'
M_i_lang_q_hu='Nyelv / Language: 1) magyar 2) English [1] '
M_i_lang_q_en='Nyelv / Language: 1) magyar 2) English [1] '

# --- messages: check/fix/report ---

M_c_header_hu='Ellenőrzés: %s – OpenHDID %s, %s'
M_c_header_en='Check: %s – OpenHDID %s, %s'
M_c_running_hu='Ellenőrzés folyamatban (csak olvas; néhány egyszeri konténerrel együtt fél percig is eltarthat)…'
M_c_running_en='Checking (read-only; with a few one-off containers it may take half a minute)…'
M_c_summary_hu='Összefoglaló: %s hiba, %s figyelmeztetés, %s tájékoztatás; %s ellenőrzés rendben.'
M_c_summary_en='Summary: %s errors, %s warnings, %s notes; %s checks fine.'
M_c_root_note_hu='Megjegyzés: az ellenőrzés rootként fut; a telepítést a docker csoport tagjaként kezelje (a check csak olvas).'
M_c_root_note_en='Note: the check runs as root; manage the installation as a member of the docker group (the check only reads).'
M_c_skipped_hu='kihagyva: előbb a(z) %s lelet javítandó'
M_c_skipped_en='skipped: fix finding %s first'
M_c_doc_ops_hu='OpenHDID üzemeltetési kézikönyv'
M_c_doc_ops_en='OpenHDID operations manual'
M_c_prof_none_hu='nincs telepítés'
M_c_prof_none_en='no installation'
M_c_prof_trial_hu='profil nélkül (kipróbálás), egy gép'
M_c_prof_trial_en='no usage profile (trial), one host'
M_c_prof_5k_hu='5k profil, egy gép'
M_c_prof_5k_en='5k profile, one host'
M_c_prof_10k_single_hu='10k profil, egy gép'
M_c_prof_10k_single_en='10k profile, one host'
M_c_prof_10k_ha_a_hu='kétgépes 10k profil, A gép (elsődleges)'
M_c_prof_10k_ha_a_en='two-host 10k profile, host A (primary)'
M_c_prof_10k_ha_b_hu='kétgépes 10k profil, B gép (másodlagos)'
M_c_prof_10k_ha_b_en='two-host 10k profile, host B (secondary)'
M_c_prof_10k_ha_infra_hu='kétgépes 10k profil, infra gép (MariaDB, Valkey)'
M_c_prof_10k_ha_infra_en='two-host 10k profile, infra host (MariaDB, Valkey)'
M_c_prof_20k_hu='20k profil, Kubernetes (a k8s mappa fájljai)'
M_c_prof_20k_en='20k profile, Kubernetes (the files of the k8s folder)'
M_c_no_install_hu='ebben a mappában (%s) nincs .env és k8s/kustomization.yaml: itt nincs telepítés'
M_c_no_install_en='this folder (%s) holds no .env and no k8s/kustomization.yaml: there is no installation here'
M_c_no_install_a_hu='Új telepítés: ./install.sh; meglévő telepítés esetén a telepítés mappájában futtassa (ott van a .env).'
M_c_no_install_a_en='A new installation: ./install.sh; for an existing one run it in the installation folder (where the .env is).'
M_c_pw_empty_hu='a REDIS_PASSWORD üres'
M_c_pw_empty_en='REDIS_PASSWORD is empty'
M_c_pw_set_hu='a REDIS_PASSWORD meg van adva'
M_c_pw_set_en='REDIS_PASSWORD is set'

M_c_files01_t_hu='Hiányzik a(z) %s'
M_c_files01_t_en='%s is missing'
M_c_files01_a_hu='A kiadás mellékletének letöltése ellenőrzőösszeggel (UPGRADE.md 3. lépés), vagy új telepítésnél ./install.sh.'
M_c_files01_a_en='Download the release asset with its checksum (UPGRADE.md, step 3), or ./install.sh for a new installation.'
M_c_files02_t_hu='Hiányzik a(z) %s'
M_c_files02_t_en='%s is missing'
M_c_files02_d_hu='a migrate szolgáltatás (és a beépített adatbázis) a db.env migrációs fiókjával dolgozik; nélküle minden docker compose parancs hibával áll meg'
M_c_files02_d_en='the migrate service (and the bundled database) works with the migration account of db.env; without it every docker compose command stops with an error'
M_c_files02_a_hu='Visszaállítás a mentésből (./backup.sh list, ./backup.sh restore), vagy a db.env.example mintájára a migrációs fiókkal (chmod 600 db.env).'
M_c_files02_a_en='Restore it from a backup (./backup.sh list, ./backup.sh restore), or write it after db.env.example with the migration account (chmod 600 db.env).'
M_c_files03_t_hu='A .env vagy a db.env joga nem 600, vagy nem az üzemeltetőé'
M_c_files03_t_en='.env or db.env is not mode 600 or not owned by the operator'
# shellcheck disable=SC2016 # printed advice, not expanded
M_c_files03_a_hu='A fájlok minden titkot tartalmaznak: chmod 600 .env db.env; idegen tulajdonosnál sudo chown "$(id -un)" .env db.env (a telepítő ezt csak kiírja).'
# shellcheck disable=SC2016
M_c_files03_a_en='The files hold every secret: chmod 600 .env db.env; with another owner sudo chown "$(id -un)" .env db.env (the installer only prints it).'
M_c_files04_t_hu='Hiányzó profilfájl a COMPOSE_FILE sorban'
M_c_files04_t_en='A usage profile file of COMPOSE_FILE is missing'
M_c_files04_d_hu='nem található: %s; minden docker compose parancs hibával áll meg (a futó konténereket ez nem érinti)'
M_c_files04_d_en='not found: %s; every docker compose command stops with an error (running containers are not affected)'
M_c_files04_a_hu='A profilfájl letöltése az OPENHDID_VERSION (%s) kiadásából ellenőrzőösszeggel (profile-5k.yaml → profiles/5k/compose.yaml, profile-10k-single.yaml → profiles/10k/single.yaml, profile-10k.yaml → profiles/10k/compose.yaml).'
M_c_files04_a_en='Download the profile file of the OPENHDID_VERSION (%s) release with its checksum (profile-5k.yaml → profiles/5k/compose.yaml, profile-10k-single.yaml → profiles/10k/single.yaml, profile-10k.yaml → profiles/10k/compose.yaml).'
M_c_files05_t_hu='A compose.override.yaml kimaradt a COMPOSE_FILE sorból'
M_c_files05_t_en='compose.override.yaml is left out of COMPOSE_FILE'
M_c_files05_d_hu='COMPOSE_FILE=%s: a COMPOSE_FILE mellett a Compose a compose.override.yaml-t magától nem olvassa, a benne lévő beállítás (például extra_hosts) nem hat'
M_c_files05_d_en='COMPOSE_FILE=%s: with COMPOSE_FILE set Compose does not read compose.override.yaml by itself, so its settings (extra_hosts, for example) have no effect'
M_c_files05_a_hu='A COMPOSE_FILE végére :compose.override.yaml, utána docker compose config --quiet && docker compose up -d.'
M_c_files05_a_en='Append :compose.override.yaml to COMPOSE_FILE, then docker compose config --quiet && docker compose up -d.'
M_c_files06_t_hu='A docker compose config hibát ad'
M_c_files06_t_en='docker compose config reports an error'
M_c_files06_a_hu='A hibaüzenetben megnevezett kulcs vagy fájl javítása a .env-ben; a 10k profil :? jelű változói (például OPENHDID_NFS_SERVER) kötelezők.'
M_c_files06_a_en='Fix the key or file the message names in .env; the variables of the 10k profile marked :? (OPENHDID_NFS_SERVER, for example) are required.'
M_c_files07_t_hu='A kiadási fájlok ellenőrzőösszege'
M_c_files07_t_en='The checksums of the release files'
M_c_files07_d_hu='eltér a kiadásétól: %s (v%s)'
M_c_files07_d_en='differs from the release: %s (v%s)'
M_c_files07_none_hu='nincs mihez hasonlítani: a .install/SHA256SUMS-%s hiányzik (a telepítő --from mappájában nem volt SHA256SUMS, vagy a telepítés kézzel készült)'
M_c_files07_none_en='nothing to compare with: .install/SHA256SUMS-%s is missing (the installer'"'"'s --from folder held no SHA256SUMS, or the installation was made by hand)'
M_c_files07_a_hu='Ha nem szándékos módosítás, a fájl újra letöltése ellenőrzőösszeggel (UPGRADE.md 3.); saját változtatás a compose.override.yaml-ba való.'
M_c_files07_a_en='Unless it was changed on purpose, download the file again with its checksum (UPGRADE.md 3.); own changes belong in compose.override.yaml.'
M_c_files08_t_hu='A kétgépes 10k válaszfájlja még a gépen van'
M_c_files08_t_en='The answers file of the two-host 10k profile is still on this host'
M_c_files08_d_hu='%s: minden titkot tartalmaz (APP_KEY, adatbázis- és Valkey-jelszavak)'
M_c_files08_d_en='%s: it holds every secret (APP_KEY, database and Valkey passwords)'
M_c_files08_a_hu='Ha a B gép telepítése kész: törlés mindhárom gépen (a másolatokkal együtt).'
M_c_files08_a_en='Once host B is installed: delete it on all three hosts (copies included).'
M_c_files09_t_hu='Nincs friss mentés'
M_c_files09_t_en='No recent backup'
M_c_files09_none_script_hu='nincs végrehajtható backup.sh a mappában'
M_c_files09_none_script_en='there is no executable backup.sh in the folder'
M_c_files09_never_hu='a backup mappában még nincs mentés'
M_c_files09_never_en='the backup folder holds no backup yet'
M_c_files09_old_hu='a legutóbbi mentés két napnál régebbi: backup/%s'
M_c_files09_old_en='the latest backup is older than two days: backup/%s'
M_c_files09_a_hu='./backup.sh, és napi mentés a saját crontabba (30 2 * * * <mappa>/backup.sh --quiet); a mentést a gépen kívülre, titkosítva.'
M_c_files09_a_en='./backup.sh, and a daily backup in your own crontab (30 2 * * * <folder>/backup.sh --quiet); keep the backups off the host, encrypted.'

M_c_env01_t_hu='Hiányzó kulcsok a .env-ben (frissítés után)'
M_c_env01_t_en='Keys missing from .env (after an upgrade)'
M_c_env01_d_hu='a v%s env.example-jéhez képest hiányzik: %s'
M_c_env01_d_en='missing against the env.example of v%s: %s'
M_c_env01_a_hu='A hiányzó kulcsok felvétele a kommentjeikkel és alapértékükkel (az alapértékkel a működés nem változik), utána docker compose up -d.'
M_c_env01_a_en='Add the missing keys with their comments and defaults (the defaults do not change how it works), then docker compose up -d.'
M_c_env01_none_hu='nincs mihez hasonlítani: a v%s env.example-je nincs a gépen'
M_c_env01_none_en='nothing to compare with: the env.example of v%s is not on this host'
M_c_env01_none_a_hu='Az env.example letöltése a v%s kiadásból ellenőrzőösszeggel (UPGRADE.md 3.); a javítás ezt is megteszi.'
M_c_env01_none_a_en='Download env.example of release v%s with its checksum (UPGRADE.md 3.); the fix does it too.'
M_c_env02_t_hu='Megszűnt kulcsok a .env-ben'
M_c_env02_t_en='Keys in .env that no longer exist'
M_c_env02_removed_hu='már nem használt: %s'
M_c_env02_removed_en='no longer used: %s'
M_c_env02_a_hu='A megszűnt kulcsok kikommentelhetők (az értékük a fájlban marad).'
M_c_env02_a_en='The removed keys can be commented out (their values stay in the file).'
M_c_env02_other_t_hu='Régi álnevek és saját kulcsok a .env-ben'
M_c_env02_other_t_en='Old aliases and own keys in .env'
M_c_env02_other_d_hu='régi SYNC_* álnevek: %s; az env.example-ben nem szereplő kulcsok: %s'
M_c_env02_other_d_en='old SYNC_* aliases: %s; keys not in env.example: %s'
M_c_env02_other_a_hu='A SYNC_* álneveket érdemes EMD_SYNC_* névre írni (a régi név működik); a többi saját kulcsot a telepítő nem érinti.'
M_c_env02_other_a_en='Rename the SYNC_* aliases to EMD_SYNC_* (the old names still work); the installer leaves other own keys alone.'
M_c_env03_t_hu='Az OPENHDID_VERSION nem pontos kiadási szám'
M_c_env03_t_en='OPENHDID_VERSION is not an exact release number'
M_c_env03_d_hu='OPENHDID_VERSION=%s'
M_c_env03_d_en='OPENHDID_VERSION=%s'
M_c_env03_a_hu='A pontos kiadási szám (például 1.15.0), latest soha; utána docker compose pull && docker compose up -d.'
M_c_env03_a_en='The exact release number (1.15.0, for example), never latest; then docker compose pull && docker compose up -d.'
M_c_env04_t_hu='Az APP_KEY hiányzik vagy érvénytelen'
M_c_env04_t_en='APP_KEY is missing or invalid'
M_c_env04_empty_hu='az APP_KEY üres'
M_c_env04_empty_en='APP_KEY is empty'
M_c_env04_bad_hu='az APP_KEY nem 32 bájt (base64:…)'
M_c_env04_bad_en='APP_KEY does not hold 32 bytes (base64:…)'
M_c_env04_a_hu='Új telepítésen (nincs db és storage kötet) új kulcs; meglévő adat mellett SOHA új kulcs: a régit a mentés config.tar.gz-jéből kell visszaírni (./backup.sh list).'
M_c_env04_a_en='On a new installation (no db and storage volume) a new key; next to existing data NEVER a new key: write the old one back from config.tar.gz of a backup (./backup.sh list).'
M_c_env05_t_hu='Az APP_URL nem a nyilvános https:// cím'
M_c_env05_t_en='APP_URL is not the public https:// address'
M_c_env05_d_hu='APP_URL=%s'
M_c_env05_d_en='APP_URL=%s'
M_c_env05_a_hu='APP_URL=https://hdid.example.org (a saját gépnévvel) a .env-ben, majd docker compose up -d.'
M_c_env05_a_en='APP_URL=https://hdid.example.org (with your host name) in .env, then docker compose up -d.'
M_c_env06_t_hu='Éles védelmet kikapcsoló érték'
M_c_env06_t_en='A value that switches a production safeguard off'
M_c_env06_env_hu='APP_ENV=%s: csak production, staging, demo, local vagy testing lehet'
M_c_env06_env_en='APP_ENV=%s: only production, staging, demo, local or testing'
M_c_env06_debug_hu='APP_DEBUG=true az APP_ENV=production mellett'
M_c_env06_debug_en='APP_DEBUG=true with APP_ENV=production'
M_c_env06_cookie_hu='SESSION_SECURE_COOKIE=false élesben'
M_c_env06_cookie_en='SESSION_SECURE_COOKIE=false in production'
M_c_env06_proxies_hu='TRUSTED_PROXIES=%s: mindenkit megbízhatónak vesz'
M_c_env06_proxies_en='TRUSTED_PROXIES=%s trusts every address'
M_c_env06_a_hu='Javítás a .env-ben (a konténer induláskor ugyanezt ellenőrzi és megáll); hibakereséshez APP_ENV=staging és APP_DEBUG=true együtt, utána vissza.'
M_c_env06_a_en='Fix it in .env (the container checks the same at start and stops); for debugging APP_ENV=staging together with APP_DEBUG=true, then revert both.'
M_c_env07_t_hu='Régi, adatbázisos alapértékű telepítés'
M_c_env07_t_en='An old installation with the database defaults'
M_c_env07_d_hu='%s = database, miközben a Valkey elérhető; %s'
M_c_env07_d_en='%s = database while Valkey is available; %s'
M_c_env07_nov_hu='%s = database, és nincs Valkey (a redis profil nincs bekapcsolva)'
M_c_env07_nov_en='%s = database, and there is no Valkey (the redis profile is off)'
M_c_env07_a_hu='Átállás a Valkey-re vezetetten: jelszó, a régi sor kiürítése leállított web és ütemező mellett, a három kulcs redis-re, docker compose up -d; egyszer mindenki kilép, a gyorsítótár üres, a failed_jobs marad.'
M_c_env07_a_en='A guided move to Valkey: a password, the old queue drained with web and scheduler stopped, the three keys set to redis, docker compose up -d; everyone signs out once, the cache starts empty, failed_jobs stays.'
M_c_env08_t_hu='A Valkey jelszava üres'
M_c_env08_t_en='The Valkey password is empty'
M_c_env08_d_hu='a REDIS_PASSWORD üres, miközben a gyorsítótár, a sor vagy a munkamenet redis: élesben a konténer nem indul, és a Valkey a hálózaton bárkinek válaszol'
M_c_env08_d_en='REDIS_PASSWORD is empty while the cache, the queue or the sessions use redis: in production the container does not start, and Valkey answers anyone on the network'
M_c_env08_k8s_hu='a REDIS_PASSWORD üres a k8s/openhdid-env.env-ben'
M_c_env08_k8s_en='REDIS_PASSWORD is empty in k8s/openhdid-env.env'
M_c_env08_infra_hu='a(z) %s üres: a Valkey jelszó nélkül figyel a hálózaton'
M_c_env08_infra_en='%s is empty: Valkey listens on the network without a password'
M_c_env08_infra_a_hu='Jelszó a(z) %s sorba (például az ./install.sh --profile 10k-ha --role infra újrafuttatásával), utána docker compose up -d, és ugyanez az A és a B gép .env-jébe.'
M_c_env08_infra_a_en='A password in %s (for example by running ./install.sh --profile 10k-ha --role infra again), then docker compose up -d, and the same in the .env of hosts A and B.'
M_c_env08_a_hu='Jelszó előállítása az image-dzsel a .env REDIS_PASSWORD sorába, utána docker compose up -d (a beépített Valkey az új jelszóval indul).'
M_c_env08_a_en='Make a password with the image into REDIS_PASSWORD of .env, then docker compose up -d (the bundled Valkey starts with the new password).'
M_c_env09_t_hu='REDIS_PASSWORD_FILE a beépített Valkey mellett'
M_c_env09_t_en='REDIS_PASSWORD_FILE with the bundled Valkey'
# shellcheck disable=SC2016 # printed advice, not expanded
M_c_env09_d_hu='a valkey és a valkey-sessions a jelszót a .env REDIS_PASSWORD sorából kapja (requirepass "${REDIS_PASSWORD:-}"), a fájlt nem olvassa: jelszó nélkül vagy eltérő jelszóval fut'
# shellcheck disable=SC2016
M_c_env09_d_en='valkey and valkey-sessions take the password from the REDIS_PASSWORD line of .env (requirepass "${REDIS_PASSWORD:-}") and do not read the file: they run without a password or with another one'
M_c_env09_a_hu='A jelszó a REDIS_PASSWORD sorba (a REDIS_PASSWORD_FILE sor kikommentelve), utána docker compose up -d; titokfájl csak külső Valkey-nél.'
M_c_env09_a_en='Put the password into REDIS_PASSWORD (comment the REDIS_PASSWORD_FILE line out), then docker compose up -d; a secret file only with an external Valkey.'
M_c_env10_t_hu='A Valkey jelszava eltér az alkalmazásétól'
M_c_env10_t_en='The Valkey password differs from the application'"'"'s'
M_c_env10_d_hu='%s: a jelszavas próba (PING) elutasítva (az alkalmazás jelszava %s karakter); a .env változott, de a Valkey a régi beállítással fut'
M_c_env10_d_en='%s: the authenticated probe (PING) was refused (the application'"'"'s password has %s characters); .env changed, but Valkey runs with the old setting'
M_c_env10_a_hu='docker compose up -d --force-recreate valkey valkey-sessions, majd docker compose up -d (a kötetek maradnak); külső Valkey-nél a szolgáltatónál kell egyeztetni.'
M_c_env10_a_en='docker compose up -d --force-recreate valkey valkey-sessions, then docker compose up -d (the volumes stay); with an external Valkey align it with the provider.'
M_c_env10_infra_a_hu='docker compose up -d --force-recreate a jelzett szolgáltatásra; az A és a B gép .env-jében ugyanez a jelszó kell.'
M_c_env10_infra_a_en='docker compose up -d --force-recreate for the named service; hosts A and B need the same password in their .env.'
M_c_env11_t_hu='Hibás vagy túl tág HDID_RATE_LIMIT_EXEMPT_NETWORKS'
M_c_env11_t_en='HDID_RATE_LIMIT_EXEMPT_NETWORKS is invalid or too wide'
M_c_env11_d_hu='érvénytelen vagy /8-nál (IPv6-nál /32-nél) tágabb elem: %s; a konténer nem indul'
M_c_env11_d_en='an invalid entry or one wider than /8 (IPv6: /32): %s; the container does not start'
M_c_env11_a_hu='A hibás vagy túl tág elem javítása (a vállalat saját NAT-címei), utána docker compose up -d.'
M_c_env11_a_en='Fix the invalid or too wide entry (the company'"'"'s own NAT addresses), then docker compose up -d.'
M_c_env12_t_hu='Terheléselosztó mögött hiányzó megbízható cím'
M_c_env12_t_en='The trusted address behind a load balancer is missing'
M_c_env12_proxies_hu='OPENHDID_TLS=off, de TRUSTED_PROXIES=%s: a kliens-cím az elosztóé lesz (kéréskorlát, napló)'
M_c_env12_proxies_en='OPENHDID_TLS=off but TRUSTED_PROXIES=%s: the client address becomes the load balancer'"'"'s (rate limits, logs)'
M_c_env12_monitoring_hu='az OPENHDID_MONITORING_ALLOW üres: az elosztó /up élő-próbája 404-et kap'
M_c_env12_monitoring_en='OPENHDID_MONITORING_ALLOW is empty: the load balancer'"'"'s /up probe gets 404'
M_c_env12_a_hu='TRUSTED_PROXIES és OPENHDID_MONITORING_ALLOW = a terheléselosztó címe(i), utána docker compose up -d.'
M_c_env12_a_en='TRUSTED_PROXIES and OPENHDID_MONITORING_ALLOW = the load balancer address(es), then docker compose up -d.'
M_c_env13_t_hu='OPENHDID_NGINX_WORKERS=auto több magos gépen'
M_c_env13_t_en='OPENHDID_NGINX_WORKERS=auto on a host with many CPUs'
M_c_env13_d_hu='%s mag: magonként egy nginx-dolgozó indul a profil %s dolgozója helyett, kb. %s MB-tal több, mint amivel a web memóriaplafonja számol'
M_c_env13_d_en='%s CPUs: one nginx worker per CPU starts instead of the profile'"'"'s %s, about %s MB more than the web memory ceiling counts with'
M_c_env13_a_hu='A sor kiürítése (a profil értéke), vagy a plafon emelése az OPENHDID_WEB_MEM_LIMIT-tel; utána docker compose up -d web.'
M_c_env13_a_en='Empty the line (the profile'"'"'s value), or raise the ceiling with OPENHDID_WEB_MEM_LIMIT; then docker compose up -d web.'
M_c_env14_t_hu='HDID_PRIORITY_QUEUE=priority a régi compose.yaml mellett'
M_c_env14_t_en='HDID_PRIORITY_QUEUE=priority with the old compose.yaml'
M_c_env14_d_hu='a compose.yaml worker-e csak a default sort hallgatja (command: [worker, default]): a belépési kódok sorát senki nem dolgozza fel'
M_c_env14_d_en='the worker of compose.yaml listens to the default queue only (command: [worker, default]): nobody processes the queue of the sign-in codes'
M_c_env14_a_hu='Az új compose.yaml letöltése (UPGRADE.md 3.), vagy addig a HDID_PRIORITY_QUEUE üresen; a már sorban álló kódokat az ütemező öt perc után a default sorra teszi.'
M_c_env14_a_en='Download the new compose.yaml (UPGRADE.md 3.), or leave HDID_PRIORITY_QUEUE empty meanwhile; the scheduler moves codes already queued to the default queue after five minutes.'
M_c_env15_t_hu='Naplóba író levelezés vagy SMS, önaláírt tanúsítvány'
M_c_env15_t_en='Mail or SMS written to the log, a self-signed certificate'
M_c_env15_trial_hu='%s (APP_ENV=%s: kipróbálásnál rendben)'
M_c_env15_trial_en='%s (APP_ENV=%s: fine for a trial)'
M_c_env15_a_hu='A levelezés és az SMS bekötése (MAIL_MAILER=ews vagy smtp, SMS_DRIVER=ozeki), saját tanúsítvány; próba: hdid:test-mail, hdid:test-sms.'
M_c_env15_a_en='Connect mail and SMS (MAIL_MAILER=ews or smtp, SMS_DRIVER=ozeki), an own certificate; test: hdid:test-mail, hdid:test-sms.'
M_c_env16_t_hu='A kétgépes 10k szerep (%s) ellentmondásai'
M_c_env16_t_en='Contradictions of the two-host 10k role (%s)'
M_c_env16_a_primary_hu='az A gépen a COMPOSE_PROFILES-ból hiányzik a primary (nincs ütemező és ügyféltörzs-feldolgozó)'
M_c_env16_a_primary_en='COMPOSE_PROFILES lacks primary on host A (no scheduler and no EMD worker)'
M_c_env16_b_primary_hu='a B gépen a COMPOSE_PROFILES tartalmazza a primary-t (az ütemező kétszer futna)'
M_c_env16_b_primary_en='COMPOSE_PROFILES holds primary on host B (the scheduler would run twice)'
M_c_env16_local_hu='a db vagy a redis profil be van kapcsolva (nem a közös adatbázis és Valkey)'
M_c_env16_local_en='the db or the redis profile is on (not the shared database and Valkey)'
M_c_env16_tls_hu='OPENHDID_TLS=%s a terheléselosztó mögött (off kell)'
M_c_env16_tls_en='OPENHDID_TLS=%s behind the load balancer (off is needed)'
M_c_env16_nfs_hu='az OPENHDID_NFS_SERVER vagy az OPENHDID_NFS_PATH üres'
M_c_env16_nfs_en='OPENHDID_NFS_SERVER or OPENHDID_NFS_PATH is empty'
M_c_env16_adv_hu='A szerepnek megfelelő COMPOSE_PROFILES (A: primary, B: üres), OPENHDID_TLS=off, a közös DB_HOST és REDIS_HOST, az NFS-kulcsok; utána docker compose up -d --remove-orphans.'
M_c_env16_adv_en='COMPOSE_PROFILES for the role (A: primary, B: empty), OPENHDID_TLS=off, the shared DB_HOST and REDIS_HOST, the NFS keys; then docker compose up -d --remove-orphans.'
M_c_env17_t_hu='A konténer induláskori ellenőrzése'
M_c_env17_t_en='The start check of the container'
M_c_env17_a_hu='Az üzenetben megnevezett kulcs javítása a .env-ben; ERROR sor esetén a konténer nem indul.'
M_c_env17_a_en='Fix the key the message names in .env; with an ERROR line the container does not start.'
M_c_env18_t_hu='Külső Valkey az alapértelmezett kulcs-előtaggal'
M_c_env18_t_en='An external Valkey with the default key prefix'
M_c_env18_d_hu='REDIS_HOST=%s, a REDIS_PREFIX üres: több telepítés egy Valkey-n összeakadhat'
M_c_env18_d_en='REDIS_HOST=%s, REDIS_PREFIX is empty: several installations on one Valkey may get in each other'"'"'s way'
M_c_env18_a_hu='Saját előtag (REDIS_PREFIX, CACHE_PREFIX) csak üres sor mellett, mindkét alkalmazásgépen egyszerre.'
M_c_env18_a_en='An own prefix (REDIS_PREFIX, CACHE_PREFIX) only with an empty queue, on both application hosts at once.'
M_c_env21_t_hu='Üres DB_PASSWORD'
M_c_env21_t_en='DB_PASSWORD is empty'
M_c_env21_a_hu='A futási fiók jelszava a .env-ben (a beépített adatbázisnál a fiók jelszava ugyanaz); meglévő adatbázisnál a jelszót az adatbázisban is át kell állítani.'
M_c_env21_a_en='The runtime account'"'"'s password in .env (with the bundled database the account has the same one); on an existing database change it in the database too.'

M_c_tls01_t_hu='A tanúsítvány vagy a kulcs hiányzik, vagy a konténer nem olvassa'
M_c_tls01_t_en='The certificate or the key is missing or not readable by the container'
M_c_tls01_a_hu='A tanúsítvány (újra)telepítése a konténer felhasználójával (uid 82), rootless Dockerrel is, sudo nélkül.'
M_c_tls01_a_en='Install the certificate (again) through the container user (uid 82), also with rootless Docker, without sudo.'
M_c_tls02_t_hu='A tanúsítvány 30 napon belül lejár'
M_c_tls02_t_en='The certificate expires within 30 days'
M_c_tls02_expired_t_hu='A tanúsítvány lejárt'
M_c_tls02_expired_t_en='The certificate has expired'
M_c_tls02_a_hu='Új tanúsítvány a tls mappába, utána docker compose exec web openhdid reload (újraindítás nélkül).'
M_c_tls02_a_en='A new certificate into the tls folder, then docker compose exec web openhdid reload (no restart).'
M_c_tls03_t_hu='A kulcspár, a lánc vagy a név hibás'
M_c_tls03_t_en='The key pair, the chain or the name is wrong'
M_c_tls03_a_hu='A teljes lánc (kiszolgáló + köztes) és a hozzá tartozó kulcs, a név a tanúsítvány SAN-jában.'
M_c_tls03_a_en='The full chain (server + intermediate) and its own key, the name in the certificate'"'"'s SAN.'
M_c_tls05_t_hu='A ca mappa vagy egy CA-fájl a konténer felhasználójának nem olvasható'
M_c_tls05_t_en='The ca folder or a CA file is not readable by the container user'
M_c_tls05_d_hu='%s: a konténer (uid 82, rootless Dockerrel annak leképezett azonosítója) a gazdagépen „mások” közé tartozik; így induláskor egyetlen belső CA-t sem vesz fel, és a kimenő kérés „unable to get local issuer certificate” hibát kap'
M_c_tls05_d_en='%s: the container (uid 82, or its mapped id under rootless Docker) counts as "others" on the host, so it takes no internal CA at start, and outgoing requests fail with "unable to get local issuer certificate"'
M_c_tls05_a_hu='A mappa 755, a CA-fájlok 644 (a CA-tanúsítvány nem titok), utána újraindítás.'
M_c_tls05_a_en='The folder 755, the CA files 644 (a CA certificate is no secret), then a restart.'
M_c_tls04_t_hu='A ca mappa a konténerek indulása után változott'
M_c_tls04_t_en='The ca folder changed after the containers started'
M_c_tls04_d_hu='újabb a web konténer indulásánál: %s; a belső CA-kat a konténer induláskor veszi fel, a docker compose up -d nem indít újra'
M_c_tls04_d_en='newer than the start of the web container: %s; the container takes the internal CAs at start, docker compose up -d does not restart it'
M_c_tls04_a_hu='docker compose restart web worker sync-worker scheduler; a napló „trusting N extra CA file(s)” sora mutatja a felvett CA-kat.'
M_c_tls04_a_en='docker compose restart web worker sync-worker scheduler; the log line "trusting N extra CA file(s)" shows the CAs taken.'
M_c_tls_selfsigned_t_hu='Önaláírt tanúsítvány (kipróbálás)'
M_c_tls_selfsigned_t_en='A self-signed certificate (trial)'
M_c_tls_selfsigned_a_hu='Élesben saját tanúsítvány kell (OPENHDID_TLS=on, tls/fullchain.pem és tls/privkey.pem).'
M_c_tls_selfsigned_a_en='Production needs an own certificate (OPENHDID_TLS=on, tls/fullchain.pem and tls/privkey.pem).'

M_c_net01_t_hu='A gép nem oldja fel a(z) %s nevet'
M_c_net01_t_en='This host does not resolve %s'
M_c_net01_a_hu='DNS A-rekord: %s → a gép vagy a terheléselosztó címe (a telepítő csak kiírja).'
M_c_net01_a_en='A DNS A record: %s → the address of this host or of the load balancer (the installer only prints it).'
M_c_net02_t_hu='A konténer nem oldja fel a külső neveket'
M_c_net02_t_en='The container does not resolve the external names'
M_c_net02_a_hu='A név a belső DNS-be, vagy extra_hosts a compose.override.yaml-ban (a COMPOSE_FILE-ba felvéve).'
M_c_net02_a_en='Add the name to the internal DNS, or extra_hosts in compose.override.yaml (listed in COMPOSE_FILE).'
M_c_net03_t_hu='A web konténer nem a beállított címen és portokon publikál'
M_c_net03_t_en='The web container does not publish the configured address and ports'
M_c_net03_d_hu='%s (beállítva: %s, HTTPS %s, HTTP %s)'
M_c_net03_d_en='%s (configured: %s, HTTPS %s, HTTP %s)'
M_c_net03_a_hu='docker compose up -d (a .env módosítása után a konténert újra kell létrehozni).'
M_c_net03_a_en='docker compose up -d (after a change of .env the container has to be created again).'

M_c_db01_t_hu='Az alkalmazás nem kapcsolódik az adatbázishoz'
M_c_db01_t_en='The application cannot connect to the database'
M_c_db02_t_hu='A migrációs fiók nem kapcsolódik'
M_c_db02_t_en='The migration account cannot connect'
M_c_db02_a_hu='A db.env fiókja és jelszava (külső adatbázisnál előre létre kell hozni, ALL joggal az adatbázisra).'
M_c_db02_a_en='The account and password of db.env (with an external database create it first, with ALL on the database).'
M_c_db02_pending_t_hu='%s függő adatbázis-migráció'
M_c_db02_pending_t_en='%s pending database migrations'
M_c_db02_pending_a_hu='docker compose up -d (a migrate szolgáltatás lefuttatja őket).'
M_c_db02_pending_a_en='docker compose up -d (the migrate service runs them).'
M_c_db03_t_hu='A futási fióknak sémamódosító joga van'
M_c_db03_t_en='The runtime account may change the schema'
M_c_db03_a_hu='A futási fiók csak SELECT, INSERT, UPDATE, DELETE jogot kapjon; a sémát a db.env migrációs fiókja módosítja (REVOKE az adatbázis üzemeltetőjével).'
M_c_db03_a_en='Give the runtime account SELECT, INSERT, UPDATE, DELETE only; the migration account of db.env changes the schema (REVOKE with the database operator).'

M_c_svc01_t_hu='A(z) %s szolgáltatás nem fut rendben'
M_c_svc01_t_en='Service %s is not running properly'
M_c_svc01_absent_hu='nincs konténere'
M_c_svc01_absent_en='it has no container'
M_c_svc01_state_hu='állapota %s (kilépési kód %s)'
M_c_svc01_state_en='its state is %s (exit code %s)'
M_c_svc01_unhealthy_hu='fut, de az egészségpróbája hibás (unhealthy)'
M_c_svc01_unhealthy_en='it runs but its health check fails (unhealthy)'
M_c_svc01_starting_hu='most indul (az egészségpróba még nem futott le)'
M_c_svc01_starting_en='it is starting (the health check has not passed yet)'
M_c_svc01_a_hu='A napló vége megmondja az okot (az openhdid: ERROR: sor a kulcsot is), javítás után docker compose up -d.'
M_c_svc01_a_en='The end of the log tells the cause (an openhdid: ERROR: line names the key), after the fix docker compose up -d.'
M_c_svc02_t_hu='A migrate hibával lépett ki (%s)'
M_c_svc02_t_en='migrate exited with an error (%s)'
M_c_svc02_a_hu='A napló oka szerint (adatbázis-elérés, a db.env fiókja); javítás után docker compose up -d.'
M_c_svc02_a_en='Follow the cause in the log (database access, the db.env account); after the fix docker compose up -d.'
M_c_svc02_demo_hu='Éles környezetben az adatbázis demóadatot tartalmaz: üres adatbázis kell, vagy APP_ENV=demo a kipróbáláshoz.'
M_c_svc02_demo_en='In production the database holds demo data: an empty database is needed, or APP_ENV=demo for a trial.'
M_c_svc03_services_hu='a jelenlegi fájlok (.env, compose.yaml, profil) szerint más beállítással futnak: %s; a változás csak újra létrehozott konténerben hat'
M_c_svc03_services_en='they run with another configuration than the current files (.env, compose.yaml, profile) give: %s; a change takes effect in a recreated container only'
M_c_svc03_t_hu='A konténerek nem a jelenlegi beállítással futnak'
M_c_svc03_t_en='The containers do not run with the current settings'
M_c_svc03_image_hu='a web image-e %s, a .env szerint %s'
M_c_svc03_image_en='the web image is %s, .env says %s'
M_c_svc03_files_hu='újabb a konténerek indulásánál: %s'
M_c_svc03_files_en='newer than the start of the containers: %s'
M_c_svc03_a_hu='docker compose up -d (a változás csak újra létrehozott konténerben hat).'
M_c_svc03_a_en='docker compose up -d (a change takes effect in a recreated container only).'
M_c_svc04_oom_t_hu='A(z) %s konténert memóriahiány miatt leállította a kernel (OOMKilled)'
M_c_svc04_oom_t_en='The kernel stopped container %s for lack of memory (OOMKilled)'
M_c_svc04_restart_t_hu='A(z) %s konténer %s alkalommal újraindult'
M_c_svc04_restart_t_en='Container %s restarted %s times'
M_c_svc04_a_hu='A plafon (OPENHDID_*_MEM_LIMIT) a hozzá tartozó értékkel együtt emelendő, vagy a következő profil; lásd a Kapacitás kártyát.'
M_c_svc04_a_en='Raise the ceiling (OPENHDID_*_MEM_LIMIT) together with its value, or the next profile; see the Capacity card.'
M_c_svc05_t_hu='A B gépen is fut az ütemező vagy az ügyféltörzs-feldolgozó'
M_c_svc05_t_en='The scheduler or the EMD worker runs on host B too'
M_c_svc05_d_hu='fut: %s (pontosan egy példány lehet, az A gépen)'
M_c_svc05_d_en='running: %s (exactly one instance, on host A)'
M_c_svc05_a_hu='A B gép .env-jében a COMPOSE_PROFILES üres, utána docker compose up -d --remove-orphans.'
M_c_svc05_a_en='COMPOSE_PROFILES empty in the .env of host B, then docker compose up -d --remove-orphans.'

M_c_health_t_hu='Rendszerállapot: %s'
M_c_health_t_en='System status: %s'
M_c_health_hint_hu='A panel Rendszerállapot oldalának tanácsa.'
M_c_health_hint_en='The advice of the System status page of the panel.'
M_c_health_unconfigured_t_hu='Rendszerállapot: %s – még nincs bekötve'
M_c_health_unconfigured_t_en='System status: %s – not connected yet'
M_c_health_unconfigured_a_hu='Friss telepítés utáni teendő: a telepítési útmutató 11. fejezete szerint.'
M_c_health_unconfigured_a_en='A to-do after a fresh installation: chapter 11 of the installation guide.'
M_c_health_role_b_scheduler_a_hu='Ez a B gép: az ütemező az A gépen fut, ott nézze meg (./install.sh check az A gépen).'
M_c_health_role_b_scheduler_a_en='This is host B: the scheduler runs on host A, check it there (./install.sh check on host A).'
M_c_health_fresh_t_hu='Rendszerállapot: %s – most indult, az első életjel egy percen belül'
M_c_health_fresh_t_en='System status: %s – just started, the first heartbeat comes within a minute'
M_c_health_none_t_hu='A hdid:health nem adott eredményt'
M_c_health_none_t_en='hdid:health gave no result'
M_c_health_none_a_hu='docker compose exec web php artisan hdid:health (a hibaüzenet a kimenetben); a web konténer naplója: docker compose logs --tail=100 web.'
M_c_health_none_a_en='docker compose exec web php artisan hdid:health (the error is in its output); the web log: docker compose logs --tail=100 web.'
M_c_valkey01_t_hu='A(z) %s politikája nem a kötelező'
M_c_valkey01_t_en='The policy of %s is not the required one'
M_c_valkey01_d_hu='maxmemory-policy %s, kötelező: %s'
M_c_valkey01_d_en='maxmemory-policy %s, required: %s'
M_c_valkey01_a_hu='A beépített Valkey-nek a compose.yaml configja adja (docker compose up -d); külső Valkey-nél a szolgáltató felületén.'
M_c_valkey01_a_en='The compose.yaml config gives it to the bundled Valkey (docker compose up -d); with an external Valkey set it at the provider.'

M_c_sched01_t_hu='Beragadt ütemező-zár: %s'
M_c_sched01_t_en='A stuck scheduler lock: %s'
M_c_sched01_d_hu='a feladat zárja él, de nem fut hozzá folyamat (az ütemező futás közben leállt, például frissítéskor); a feladat a zár lejártáig kimarad: a zár legfeljebb %s percig él (1.15.0 előtt 24 óráig).'
M_c_sched01_d_en='the task'"'"'s lock is held but no process runs it (the scheduler stopped during a run, an upgrade for example); the task is skipped until the lock expires: it lives at most %s minutes (before 1.15.0: 24 hours).'
M_c_sched01_token_hu='Az EMD-token frissítése ezért kimarad, a sync_auth csempe is piros.'
M_c_sched01_token_en='So the EMD token refresh is skipped, the sync_auth tile is red too.'
M_c_sched01_a_hu='docker compose exec scheduler php artisan schedule:clear-cache (a futó feladatot előbb meg kell várni); hagyományos telepítésen sudo -u www-data sh -c '"'"'umask 027 && php artisan schedule:clear-cache'"'"'.'
M_c_sched01_a_en='docker compose exec scheduler php artisan schedule:clear-cache (wait for a running task first); on a classic installation sudo -u www-data sh -c '"'"'umask 027 && php artisan schedule:clear-cache'"'"'.'

M_c_log01_t_hu='%s induláskori hibasor (openhdid: ERROR:) az elmúlt 24 órában'
M_c_log01_t_en='%s start error lines (openhdid: ERROR:) in the last 24 hours'
M_c_log01_a_hu='Az üzenet szerinti kulcs javítása; a konténer addig újraindulgat.'
M_c_log01_a_en='Fix the key the message names; until then the container keeps restarting.'
M_c_log02_t_hu='%s Valkey-hitelesítési hiba (NOAUTH, WRONGPASS) a naplóban'
M_c_log02_t_en='%s Valkey authentication errors (NOAUTH, WRONGPASS) in the log'
M_c_log02_a_hu='Lásd ENV-09 és ENV-10: a Valkey és az alkalmazás jelszava egyezzen.'
M_c_log02_a_en='See ENV-09 and ENV-10: Valkey and the application need the same password.'
M_c_log03_t_hu='%s adatbázis-hozzáférési hiba ([1045] vagy [2002]) a naplóban'
M_c_log03_t_en='%s database access errors ([1045] or [2002]) in the log'
M_c_log03_a_hu='A DB_* értékek és a fiók jelszava; külső adatbázisnál a tűzfal és a DB_HOST.'
M_c_log03_a_en='The DB_* values and the account'"'"'s password; with an external database the firewall and DB_HOST.'
M_c_log04_t_hu='%s tanúsítvány-bizalmi hiba kimenő kérésnél (cURL error 60)'
M_c_log04_t_en='%s certificate trust errors of outbound requests (cURL error 60)'
M_c_log04_a_hu='A belső CA PEM-ben a ca mappába, utána docker compose restart web worker sync-worker scheduler.'
M_c_log04_a_en='The internal CA as PEM into the ca folder, then docker compose restart web worker sync-worker scheduler.'
M_c_log05_t_hu='%s névfeloldási hiba kimenő kérésnél (cURL error 6)'
M_c_log05_t_en='%s name resolution errors of outbound requests (cURL error 6)'
M_c_log05_a_hu='Lásd NET-02: belső DNS vagy extra_hosts a compose.override.yaml-ban.'
M_c_log05_a_en='See NET-02: the internal DNS or extra_hosts in compose.override.yaml.'
M_c_log06_t_hu='%s alkalommal betelt a fő Valkey (OOM command not allowed)'
M_c_log06_t_en='The main Valkey was full %s times (OOM command not allowed)'
M_c_log06_a_hu='Az OPENHDID_VALKEY_MAXMEMORY és az OPENHDID_VALKEY_MEM_LIMIT együtt emelendő, majd docker compose up -d.'
M_c_log06_a_en='Raise OPENHDID_VALKEY_MAXMEMORY together with OPENHDID_VALKEY_MEM_LIMIT, then docker compose up -d.'
M_c_log07_t_hu='%s kéréskorlát-elutasítás (429/503) a napló mintájában'
M_c_log07_t_en='%s rate-limit refusals (429/503) in the log sample'
M_c_log07_a_hu='Egy címről érkező forgalomnál a valódi kliens-cím (TRUSTED_PROXIES, rootless portkezelés); NAT mögötti ügyfélszolgálatnál HDID_RATE_LIMIT_EXEMPT_NETWORKS.'
M_c_log07_a_en='With all traffic from one address the real client address (TRUSTED_PROXIES, rootless port handling); for a call centre behind NAT HDID_RATE_LIMIT_EXEMPT_NETWORKS.'
M_c_log08_t_hu='%s SMTP-küldés állt meg STARTTLS hiányában (TLS required)'
M_c_log08_t_en='%s SMTP sends stopped without STARTTLS (TLS required)'
M_c_log08_a_hu='STARTTLS a kiszolgálón, vagy MAIL_SCHEME=smtps és MAIL_PORT=465.'
M_c_log08_a_en='STARTTLS on the server, or MAIL_SCHEME=smtps and MAIL_PORT=465.'
M_c_log09_t_hu='%s alkalmazáshiba (production.ERROR, PHP Fatal error) az elmúlt 24 órában'
M_c_log09_t_en='%s application errors (production.ERROR, PHP Fatal error) in the last 24 hours'
M_c_log09_a_hu='A sorok és az ./install.sh report csomagja a hibabejelentéshez (a privát sérülékenység-bejelentő vagy a repó hibajegye).'
M_c_log09_a_en='The lines and the ./install.sh report package for a bug report (the private vulnerability report or an issue of the repository).'

M_c_infra02_t_hu='Az infra gép %s szolgáltatásának beállítása eltér'
M_c_infra02_t_en='The setting of service %s of the infra host differs'
M_c_infra02_nolimit_hu='nincs memóriakorlát (maxmemory 0), a várt %s'
M_c_infra02_nolimit_en='no memory limit (maxmemory 0), %s expected'
M_c_infra02_a_hu='A profiles/10k/infra.yaml configja adja; eltérésnél a fájl visszaállítása a kiadásból és docker compose up -d.'
M_c_infra02_a_en='The config of profiles/10k/infra.yaml gives it; when it differs restore the file from the release and docker compose up -d.'
M_c_infra03_t_hu='Az infra gép szolgáltatásai minden címen figyelnek'
M_c_infra03_t_en='The services of the infra host listen on every address'
M_c_infra03_d_hu='OPENHDID_INFRA_BIND_IP=%s: a MariaDB és a Valkey itt titkosítatlanul beszél'
M_c_infra03_d_en='OPENHDID_INFRA_BIND_IP=%s: MariaDB and Valkey talk unencrypted here'
M_c_infra03_a_hu='A privát hálózati cím a figyelő címnek, és tűzfal: 3306, 6379, 6380, 2049 csak az A és a B gép címéről.'
M_c_infra03_a_en='The private network address as the listening address, and a firewall: 3306, 6379, 6380, 2049 from hosts A and B only.'
M_c_infra04_t_hu='Az adatbázisfiók (%s) hiányzik vagy nem a szándékolt jogokkal bír'
M_c_infra04_t_en='The database account (%s) is missing or lacks the intended rights'
M_c_infra04_a_hu='A fiókokat az első induláskor a mariadb-accounts config hozza létre; meglévő kötetnél a telepítési útmutató 7.2 pontjának SQL-parancsai.'
M_c_infra04_a_en='The mariadb-accounts config creates the accounts at the first start; on an existing volume the SQL commands of section 7.2 of the installation guide.'

M_c_k8s01_t_hu='A k8s mappa fájljai hiányoznak vagy nem 600-as jogúak'
M_c_k8s01_t_en='The files of the k8s folder are missing or not mode 600'
M_c_k8s01_missing_hu='hiányzik: %s'
M_c_k8s01_missing_en='missing: %s'
M_c_k8s01_a_hu='./install.sh --profile 20k újrafuttatása, vagy chmod 600 k8s/*.env.'
M_c_k8s01_a_en='Run ./install.sh --profile 20k again, or chmod 600 k8s/*.env.'
M_c_k8s02_t_hu='Az overlay nem épül fel (kubectl kustomize k8s)'
M_c_k8s02_t_en='The overlay does not build (kubectl kustomize k8s)'
M_c_k8s02_none_hu='nincs kubectl: helyben nem ellenőrizhető (a fürtön semmi nem változik)'
M_c_k8s02_none_en='no kubectl: it cannot be checked here (nothing changes on the cluster)'
M_c_k8s02_a_hu='A kubernetes mappa elérési útja (a kiadás címkéjének klónja) és a javítások a k8s/kustomization.yaml-ban; diagnosztika: kubectl -n <névtér> get pods, logs, exec … php artisan hdid:health.'
M_c_k8s02_a_en='The path of the kubernetes folder (a clone of the release tag) and the patches in k8s/kustomization.yaml; diagnosis: kubectl -n <namespace> get pods, logs, exec … php artisan hdid:health.'

M_f_banner_hu='OpenHDID javító (install.sh %s): %s'
M_f_banner_en='OpenHDID fixer (install.sh %s): %s'
M_f_survey_hu='Felmérés: ugyanaz az ellenőrzés, mint az ./install.sh check (csak olvas)…'
M_f_survey_en='Survey: the same check as ./install.sh check (read-only)…'
M_f_plan_hu='Mit teszek:'
M_f_plan_en='What I will do:'
M_f_running_hu='fut: %s'
M_f_running_en='running: %s'
M_f_env_block_hu='install.sh fix %s, %s: a javító által felvett kulcsok'
M_f_env_block_en='install.sh fix %s, %s: keys added by the fixer'
M_f_env_unset_note_hu='install.sh fix %s: kikommentelve'
M_f_env_unset_note_en='install.sh fix %s: commented out'
M_f_env_written_hu='a .env írva (a megjegyzések megmaradtak, 600)'
M_f_env_written_en='.env written (comments kept, 600)'
M_f_env_key_hu='a .env %s sora: %s'
M_f_env_key_en='the %s line of .env: %s'
M_f_restart_all_hu='docker compose up -d: a megváltozott szolgáltatások újra létrejönnek (rövid kiesés)'
M_f_restart_all_en='docker compose up -d: the changed services are created again (a short outage)'
M_f_up_failed_hu='az indítás nem sikerült; a szolgáltatások állapota fent, a hibakeresés a TROUBLESHOOTING.md 3. fejezete szerint (a .env mentése megvan).'
M_f_up_failed_en='the start failed; the state of the services is above, debug it after chapter 3 of TROUBLESHOOTING.md (the .env backup is there).'
M_f_skipped_hu='kihagyva, semmi nem változott.'
M_f_skipped_en='skipped, nothing was changed.'
M_f_manual_hu='kézi teendő: %s'
M_f_manual_en='to do by hand: %s'
M_f_already_hu='a(z) %s javítás ebben a futásban már lefutott.'
M_f_already_en='fix %s already ran in this run.'
M_f_no_fix_hu='%s: nincs vezetett javítás; teendő: %s'
M_f_no_fix_en='%s: there is no guided fix; what to do: %s'
M_f_only_none_hu='%s: nincs ilyen lelet.'
M_f_only_none_en='%s: there is no such finding.'
M_f_none_hu='Nincs vezetett javítást igénylő lelet.'
M_f_none_en='No finding needs a guided fix.'
M_f_summary_hu='Javítva: %s, kihagyva: %s, kézi teendő: %s.'
M_f_summary_en='Fixed: %s, skipped: %s, to do by hand: %s.'
M_f_recheck_hu='Újraellenőrzés:'
M_f_recheck_en='Check again:'
M_f_perms_foreign_hu='idegen tulajdonú (kézzel, root kell): %s'
M_f_perms_foreign_en='owned by another account (by hand, needs root): %s'
M_f_perms_done_hu='600-as jog: %s'
M_f_perms_done_en='mode 600: %s'
M_f_profile_unknown_hu='ismeretlen fájl a COMPOSE_FILE sorban (nem kiadási fájl, kézzel kell pótolni): %s'
M_f_profile_unknown_en='an unknown file in COMPOSE_FILE (not a release file, restore it by hand): %s'
M_f_profile_get_hu='letöltöm: %s → %s (%s, ellenőrzőösszeggel; meglévő fájlt nem írok felül)'
M_f_profile_get_en='download: %s → %s (%s, with its checksum; no existing file is overwritten)'
M_f_profile_done_hu='a profilfájl a helyén, a docker compose config rendben'
M_f_profile_done_en='the profile file is in place, docker compose config is fine'
M_f_download_failed_hu='a letöltés vagy az ellenőrzőösszeg nem sikerült (lásd fent); semmi nem került a helyére.'
M_f_download_failed_en='the download or the checksum failed (see above); nothing was put in place.'
M_f_probe_failed_hu='a próba (docker compose config) nem sikerült az új értékkel; semmi nem változott.'
M_f_probe_failed_en='the probe (docker compose config) failed with the new value; nothing was changed.'
M_f_no_version_hu='az OPENHDID_VERSION üres: nem tudom, melyik kiadás env.example-je kell (lásd ENV-03).'
M_f_no_version_en='OPENHDID_VERSION is empty: it is unknown which release'"'"'s env.example is needed (see ENV-03).'
M_f_env_example_get_hu='az env.example letöltése a v%s kiadásból (ellenőrzőösszeggel)'
M_f_env_example_get_en='downloading env.example of release v%s (with its checksum)'
M_f_env_keys_none_hu='nincs hiányzó kulcs.'
M_f_env_keys_none_en='no key is missing.'
M_f_env_keys_add_hu='a .env végére a kommentjeikkel és alapértékükkel: %s'
M_f_env_keys_add_en='to the end of .env with their comments and defaults: %s'
M_f_env_keys_keep_hu='meglévő sort nem változtatok; a kikommentelt mintasorok kikommentelve maradnak; titkot nem állítok elő'
M_f_env_keys_keep_en='no existing line changes; commented samples stay commented; no secret is generated'
M_f_env_keys_block_hu='install.sh fix %s: az OpenHDID %s új kulcsai'
M_f_env_keys_block_en='install.sh fix %s: the new keys of OpenHDID %s'
M_f_env_keys_done_hu='a kulcsok felvéve; az alapértékkel a működés nem változik (ha mégis, a CHANGELOG „Frissítés előtt” része mondja). Utána:'
M_f_env_keys_done_en='the keys are added; with the defaults nothing changes (if something does, the "Before upgrading" part of the CHANGELOG says so). Then:'
M_f_obsolete_plan_hu='a megszűnt kulcsok kikommentelése (nem törlöm, az értékük a fájlban marad): %s'
M_f_obsolete_plan_en='comment out the removed keys (not deleted, their values stay in the file): %s'
M_f_obsolete_prefix_hu='megszűnt (%s): '
M_f_obsolete_prefix_en='removed (%s): '
M_f_obsolete_done_hu='a megszűnt kulcsok kikommentelve'
M_f_obsolete_done_en='the removed keys are commented out'
M_f_appkey_present_hu='az APP_KEY nem üres: egy meglévő kulcsot a javító soha nem cserél.'
M_f_appkey_present_en='APP_KEY is not empty: the fixer never replaces an existing key.'
M_f_appkey_data_hu='a telepítésnek már van adata (kötet vagy konténer: %s): új kulcs mellett a titkosított adat olvashatatlan lenne, ezért NEM generálok kulcsot. A régi kulcsot a mentés config.tar.gz-jéből kell visszaírni:'
M_f_appkey_data_en='the installation already has data (a volume or a container: %s): with a new key the encrypted data would be unreadable, so NO key is generated. Write the old key back from config.tar.gz of a backup:'
M_f_appkey_plan_hu='új APP_KEY az image-dzsel, hálózat nélkül (csak a hosszát mutatom); őrizze meg egy védett helyen is'
M_f_appkey_plan_en='a new APP_KEY with the image, without network (only its length is shown); keep a copy in a protected place too'
M_f_appkey_done_hu='az APP_KEY beírva (%s karakter)'
M_f_appkey_done_en='APP_KEY written (%s characters)'
M_f_rpf_unusable_hu='a(z) %s tartalma nem használható jelszónak (12–256 karakter, aposztróf és sortörés nélkül): újat állítok elő.'
M_f_rpf_unusable_en='the content of %s is not usable as a password (12–256 characters, no apostrophe or line break): a new one is generated.'
M_f_rpf_use_hu='A(z) %s fájl jelszavát (%s karakter) tegyem a REDIS_PASSWORD sorba? (nem: új jelszó)'
M_f_rpf_use_en='Put the password of %s (%s characters) into REDIS_PASSWORD? (no: a new password)'
M_f_rpf_plan_hu='a REDIS_PASSWORD_FILE sor kikommentelése, a jelszó a REDIS_PASSWORD sorba (a titokfájlt nem törlöm és nem módosítom)'
M_f_rpf_plan_en='comment out the REDIS_PASSWORD_FILE line, the password into REDIS_PASSWORD (the secret file is neither deleted nor changed)'
M_f_valkey_restart_hu='a valkey és a valkey-sessions újra létrejön a jelszóval (az adatkötetük megmarad; az alkalmazás néhány másodpercig hibát kaphat)'
M_f_valkey_restart_en='valkey and valkey-sessions are created again with the password (their data volumes stay; the application may see errors for a few seconds)'
M_f_valkey_external_hu='külső Valkey: a jelszót a szolgáltató felületén kell egyeztetni a .env REDIS_PASSWORD-jével.'
M_f_valkey_external_en='an external Valkey: align its password with REDIS_PASSWORD of .env at the provider.'
M_f_db2r_external_pw_hu='külső Valkey (%s) jelszó nélkül: a jelszavát a REDIS_PASSWORD sorba kell írni, utána újra ./install.sh fix.'
M_f_db2r_external_pw_en='an external Valkey (%s) without a password: write its password into REDIS_PASSWORD, then ./install.sh fix again.'
M_f_db2r_effect_hu='Hatás: rövid leállás (web, ütemező); egyszer mindenki kilép (a munkamenetek a Valkey-be költöznek); a gyorsítótár egyszer üres (a beállításoké magától újraépül, a korlátszámlálók nulláról indulnak); a failed_jobs tábla és a panel Üzenetsorok oldala megmarad; adatot nem törlök.'
M_f_db2r_effect_en='Effect: a short outage (web, scheduler); everyone signs out once (the sessions move to Valkey); the cache starts empty once (the settings cache rebuilds itself, the rate counters start from zero); the failed_jobs table and the Queues page of the panel stay; no data is deleted.'
M_f_db2r_password_hu='Valkey-jelszó az image-dzsel a REDIS_PASSWORD sorba (csak a hosszát mutatom)'
M_f_db2r_password_en='a Valkey password with the image into REDIS_PASSWORD (only its length is shown)'
M_f_db2r_stop_why_hu='új feladat ne kerüljön a régi sorba'
M_f_db2r_stop_why_en='no new job goes into the old queue'
M_f_db2r_drain_hu='megvárom, amíg a worker kiüríti a régi (adatbázisos) sort: queue:monitor 10 mp-enként, legfeljebb 10 percig'
M_f_db2r_drain_en='wait until the worker drains the old (database) queue: queue:monitor every 10 s, for at most 10 minutes'
M_f_db2r_keys_hu='%s = redis a .env-ben'
M_f_db2r_keys_en='%s = redis in .env'
M_f_db2r_waiting_hu='a régi sor (%s): %s várakozó feladat'
M_f_db2r_waiting_en='the old queue (%s): %s waiting jobs'
M_f_db2r_not_empty_hu='a régi sor nem ürült ki (%s feladat).'
M_f_db2r_not_empty_en='the old queue is not empty (%s jobs).'
M_f_db2r_choice_hu='1) tovább várok 2) megszakítom (a web és az ütemező újraindul, a .env nem változik) 3) folytatom (a maradék a jobs táblában marad) [2]:'
M_f_db2r_choice_en='1) wait longer 2) cancel (web and scheduler start again, .env does not change) 3) go on (the rest stays in the jobs table) [2]:'
M_f_db2r_left_hu='a maradék %s feladat a jobs táblában marad: később QUEUE_CONNECTION=database ideiglenes visszaállításával dolgozható fel.'
M_f_db2r_left_en='the remaining %s jobs stay in the jobs table: they can be processed later by setting QUEUE_CONNECTION=database for a while.'
M_f_db2r_cancelled_hu='megszakítva: a web és az ütemező újraindult, a .env nem változott.'
M_f_db2r_cancelled_en='cancelled: web and scheduler started again, .env did not change.'
M_f_db2r_done_hu='átállás kész: a munkamenet, a gyorsítótár és a sor a Valkey-ben; mindenki egyszer kilépett, a failed_jobs megmaradt'
M_f_db2r_done_en='move done: sessions, cache and queue are on Valkey; everyone signed out once, failed_jobs stayed'
M_f_exempt_none_hu='nincs olyan elem, amit a javító érvénytelennek vagy túl tágnak lát; a konténer üzenete szerint kézzel kell javítani.'
M_f_exempt_none_en='no entry the fixer sees as invalid or too wide; fix it by hand after the container'"'"'s message.'
M_f_exempt_remove_hu='kiveszem: %s'
M_f_exempt_remove_en='remove: %s'
M_f_exempt_nat_hu='figyelem: a kivett címekre ezután a kéréskorlát vonatkozik (egy NAT mögötti ügyfélszolgálatot korlátozhat)'
M_f_exempt_nat_en='note: the removed addresses are rate limited afterwards (a call centre behind NAT may be limited)'
M_f_restored_hu='a próba (check-env) az új értékkel is hibát adott: a mentett .env visszaállítva (%s).'
M_f_restored_en='the probe (check-env) still failed with the new value: the saved .env is restored (%s).'
M_f_web_restart_hu='csak a web jön létre újra, rövid kiesés'
M_f_web_restart_en='only web is created again, a short outage'
M_f_priority_why_hu='A régi compose.yaml worker-e csak a default sort hallgatja; tartós megoldás az új compose.yaml (UPGRADE.md 3.).'
M_f_priority_why_en='The worker of the old compose.yaml listens to the default queue only; the lasting fix is the new compose.yaml (UPGRADE.md 3.).'
M_f_priority_done_hu='a priority sorban már várakozó üzeneteket az ütemező öt perc után a default sorra teszi; üzenet nem vész el.'
M_f_priority_done_en='the scheduler moves messages already waiting on the priority queue to the default queue after five minutes; no message is lost.'
M_f_tls_not_on_hu='OPENHDID_TLS nem on: a tanúsítványt nem ez a konténer használja.'
M_f_tls_not_on_en='OPENHDID_TLS is not on: this container does not use a certificate.'
M_f_tls_q_cert_hu='Az új teljes lánc (fullchain.pem) útvonala (üresen: a jelenlegi tls tartalma, helyes tulajdonba téve)'
M_f_tls_q_cert_en='Path of the new full chain (fullchain.pem) (empty: the current tls content, given the right owner)'
M_f_tls_need_new_hu='lejáró vagy hibás tanúsítványhoz új pár kell: ./install.sh fix --only TLS-02 az új fájlok útvonalával.'
M_f_tls_need_new_en='an expiring or wrong certificate needs a new pair: ./install.sh fix --only TLS-02 with the paths of the new files.'
M_f_tls_check_failed_hu='az új pár nem használható (lásd fent); semmi nem került a helyére.'
M_f_tls_check_failed_en='the new pair is not usable (see above); nothing was put in place.'
M_f_tls_swap_hu='a régi tls mappa a .install alá kerül (megmarad), az új a helyére, a konténer felhasználójával (sem sudo, sem --user 0)'
M_f_tls_swap_en='the old tls folder goes under .install (it is kept), the new one takes its place, through the container user (neither sudo nor --user 0)'
M_f_tls_reload_why_hu='újraindítás nélkül'
M_f_tls_reload_why_en='without a restart'
M_f_tls_reload_failed_hu='az nginx újratöltése nem sikerült: docker compose logs --tail=50 web.'
M_f_tls_reload_failed_en='reloading nginx failed: docker compose logs --tail=50 web.'
M_f_nothing_runs_hu='egyik alkalmazásszolgáltatás sem fut: docker compose up -d (az induló konténer felveszi a CA-kat).'
M_f_nothing_runs_en='no application service runs: docker compose up -d (a starting container takes the CAs).'
M_f_short_outage_hu='rövid kiesés'
M_f_short_outage_en='a short outage'
M_f_sched_host_b_hu='a B gépen nincs ütemező: a javítást az A gépen kell futtatni.'
M_f_sched_host_b_en='host B runs no scheduler: run the fix on host A.'
M_f_sched_running_now_hu='a feladat közben fut (vagy a zár elengedődött): nem oldom fel; ellenőrzés később: ./install.sh check.'
M_f_sched_running_now_en='the task runs now (or its lock was released): not released; check again later: ./install.sh check.'
M_f_sched_stuck_hu='beragadt zár, nincs hozzá folyamat: %s'
M_f_sched_stuck_en='a stuck lock without a process: %s'
M_f_sched_why_hu='minden tartott átfedés-zárat elenged; adatot nem töröl'
M_f_sched_why_en='it releases every held overlap lock; no data is deleted'
M_f_sched_wait_hu='egy ütemezési kör (65 mp) várakozás; a sync_auth csempe a következő 15 perces frissítéssel zöldül'
M_f_sched_wait_en='waiting one scheduler round (65 s); the sync_auth tile turns green with the next 15-minute refresh'
M_f_up_why_hu='a kötetek maradnak; a változott szolgáltatások újra létrejönnek'
M_f_up_why_en='the volumes stay; the changed services are created again'
M_f_up_migrate_hu='a függő adatbázis-migrációk lefutnak (migrate)'
M_f_up_migrate_en='the pending database migrations run (migrate)'

M_r_banner_hu='OpenHDID diagnosztikai csomag (install.sh %s): %s'
M_r_banner_en='OpenHDID diagnostic package (install.sh %s): %s'
M_r_step_check_hu='ellenőrzés (check --json)…'
M_r_step_check_en='check (check --json)…'
M_r_step_files_hu='verziók, fájlok, .env és db.env kitakarva…'
M_r_step_files_en='versions, files, .env and db.env masked…'
M_r_step_compose_hu='docker compose config és ps, hdid:health, tanúsítvány…'
M_r_step_compose_en='docker compose config and ps, hdid:health, certificate…'
M_r_step_logs_hu='a szolgáltatások naplójának vége (300 sor), titkok és személyes adatok kitakarva…'
M_r_step_logs_en='the end of the service logs (300 lines), secrets and personal data masked…'
M_r_chars_hu='%s karakter'
M_r_chars_en='%s characters'
M_r_checksums_hu='ellenőrzőösszegek a v%s SHA256SUMS-ához képest'
M_r_checksums_en='checksums against the SHA256SUMS of v%s'
M_r_differs_hu='ELTÉR'
M_r_differs_en='DIFFERS'
M_r_done_hu='A csomag a gépen maradt: %s (600). Nem töltöttem fel sehová; küldés előtt nézze át.'
M_r_done_en='The package stayed on this host: %s (600). Nothing was uploaded; review it before sending it.'

# ===== main =======================================================================================

need_value() { [[ $# -ge 2 && -n $2 ]] || die "$(msg i_option_value "$1")"; }

while [[ $# -gt 0 ]]; do
    case $1 in
        install | check | fix | report) COMMAND=$1; shift ;;
        --profile) need_value "$@"; PROFILE_OPT=$2; shift 2 ;;
        --role) need_value "$@"; ROLE_OPT=$2; shift 2 ;;
        --answers) need_value "$@"; ANSWERS_FILE=$2; shift 2 ;;
        --from) need_value "$@"; FROM_DIR=$2; shift 2 ;;
        --version) if [[ $# -ge 2 && $2 != -* ]]; then VERSION_OPT=$2; shift 2; else printf '%s\n' "$OPENHDID_RELEASE"; exit 0; fi ;;
        --lang) need_value "$@"; UI_LANG=$2; [[ $UI_LANG == hu || $UI_LANG == en ]] || die "$(msg i_unknown_option "--lang $2")"; shift 2 ;;
        --yes) YES=1; shift ;;
        --json) JSON=1; shift ;;
        --only) need_value "$@"; ONLY=$2; shift 2 ;;
        -h | --help) awk 'NR > 2 && /^#/ { sub(/^# ?/, ""); print; next } NR > 2 { exit }' "$SELF"; exit 0 ;;
        *) die "$(msg i_unknown_option "$1")" ;;
    esac
done

if [[ -z $UI_LANG ]]; then
    if [[ -t 0 && -t 1 && $JSON == 0 ]]; then
        printf '%s' "$M_i_lang_q_hu"
        lang_choice=""
        read_answer lang_choice || lang_choice=""
        [[ $lang_choice == 2 ]] && UI_LANG=en || UI_LANG=hu
    else
        case ${LC_ALL:-${LANG:-}} in
            hu*) UI_LANG=hu ;;
            *) UI_LANG=en ;;
        esac
    fi
fi

case $COMMAND in
    install) cmd_install ;;
    check) cmd_check ;;
    fix) cmd_fix ;;
    report) cmd_report ;;
esac
