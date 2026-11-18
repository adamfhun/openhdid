#!/usr/bin/env bash
# OpenHDID – az npm-csomagok sérülékenységvizsgálata indokolt kivételekkel.
#
# Az `npm audit` minden magas (high) vagy kritikus (critical) súlyú közleményre megáll. Ha egy
# közleményhez nincs javított verzió, és a hibás rész a kiadást bizonyíthatóan nem érinti, a közlemény
# indoklással és felülvizsgálati dátummal a szkript melletti, nyilvános npm-audit.exceptions fájlba
# vehető fel. Minden más közlemény, és a kivett csomag minden más közleménye, továbbra is megállít.
#
#   npm-audit.sh [--exceptions FÁJL] [--package-lock-only]
#
# A kiadás előtti ellenőrzés (preflight) a telepített csomagokon, a heti vizsgálat (security-scan.yml)
# a kiadás címkéjének lockfájlján (--package-lock-only) futtatja.
#
# Kivételek, soronként:  csomag | közlemény-azonosító (GHSA-…) | felülvizsgálat (ÉÉÉÉ-HH-NN) | indoklás
# Indoklás nélküli, lejárt vagy egy évnél későbbi felülvizsgálatú sor hiba; a futás a felhasznált és a
# már nem szükséges kivételeket is kiírja.
#
# Kilépési kód: 0 nincs lelet a kivételeken kívül; 1 van; 2 hibás kivételfájl; 3 az npm audit nem
# adott értelmezhető eredményt (hálózat, npm-hiba); 64 hibás hívás.
#
# bash 3.2–5. Szükséges: npm (vagy NPM_AUDIT_NPM), jq, awk.
set -euo pipefail
export LC_ALL=C

HERE=$(cd "$(dirname "$0")" && pwd -P)
EXCEPTIONS="$HERE/npm-audit.exceptions"
NPM_COMMAND=${NPM_AUDIT_NPM:-npm}
LOCK_ONLY=0

usage() {
    printf 'HIBA: %s\n' "$*" >&2
    awk 'NR > 1 && !/^#/ { exit } NR > 1 { print }' "$0" >&2
    exit 64
}

while [[ $# -gt 0 ]]; do
    case $1 in
        --exceptions) [[ $# -ge 2 ]] || usage "a --exceptions után fájl kell"; EXCEPTIONS=$2; shift 2 ;;
        --package-lock-only) LOCK_ONLY=1; shift ;;
        *) usage "ismeretlen kapcsoló: $1" ;;
    esac
done

for tool in jq awk; do
    command -v "$tool" >/dev/null || { printf 'HIBA: hiányzó eszköz: %s\n' "$tool" >&2; exit 3; }
done

WORK=$(mktemp -d "${TMPDIR:-/tmp}/npm-audit.XXXXXX")
trap 'rm -rf "$WORK"' EXIT

# --- Exceptions: checked before the audit runs, so a wrong file fails fast and the same way offline ---
today=$(date -u +%Y-%m-%d)
latest_review="$((10#${today%%-*} + 1))-${today#*-}"
[[ -f $EXCEPTIONS ]] || { printf 'HIBA: nincs kivételfájl: %s\n' "$EXCEPTIONS" >&2; exit 2; }
awk -v today="$today" -v latest="$latest_review" -v file="$EXCEPTIONS" '
    function trim(s) { sub(/^[ \t\r]+/, "", s); sub(/[ \t\r]+$/, "", s); return s }
    function bad(message) { errors = errors "  " file ":" NR ": " message "\n" }
    /^[ \t]*(#|$)/ { next }
    {
        n = split($0, field, "|")
        package = trim(field[1]); id = toupper(trim(field[2])); review = trim(field[3]); reason = ""
        for (i = 4; i <= n; i++) { reason = reason (i > 4 ? "|" : "") field[i] }
        reason = trim(reason)
        if (n < 4 || length(reason) < 10) { bad("nincs indoklás (csomag | azonosító | felülvizsgálat | indoklás; az indoklás legalább 10 karakter)"); next }
        if (package !~ /^(@[a-z0-9._-]+\/)?[a-z0-9._-]+$/) { bad("hibás csomagnév: " package); next }
        if (id !~ /^GHSA-[A-Z0-9]+-[A-Z0-9]+-[A-Z0-9]+$/) { bad("a közlemény azonosítója GHSA-… alakú legyen: " id); next }
        if (review !~ /^[0-9][0-9][0-9][0-9]-[01][0-9]-[0-3][0-9]$/) { bad("a felülvizsgálat nem ÉÉÉÉ-HH-NN dátum: " review); next }
        if (review < today) { bad("a felülvizsgálat dátuma lejárt (" review "): a kivételt újra át kell nézni"); next }
        if (review > latest) { bad("a felülvizsgálat legfeljebb egy évre előre szólhat (" review " > " latest ")"); next }
        if ((package SUBSEP id) in seen) { bad("ugyanaz a kivétel kétszer szerepel: " package " " id); next }
        seen[package SUBSEP id] = 1
        printf "%s\t%s\t%s\t%s\n", package, id, review, reason
    }
    END { if (errors != "") { printf "HIBA: a kivételfájl hibás:\n%s", errors > "/dev/stderr"; exit 2 } }
' "$EXCEPTIONS" > "$WORK/exceptions.tsv" || exit 2

# --- The audit: its exit code says "something was found"; the report decides what ----------------------
audit_args=(audit --json)
[[ $LOCK_ONLY == 0 ]] || audit_args+=(--package-lock-only)
# shellcheck disable=SC2086 # NPM_AUDIT_NPM may be a command with arguments
$NPM_COMMAND "${audit_args[@]}" > "$WORK/audit.json" 2> "$WORK/audit.err" || true

# Every high or critical advisory, once per package: package, GHSA id, severity, title, address.
# A report without its list (an error text, an empty answer) is no report: it must not read as "clean".
if ! jq -e '(.vulnerabilities | type) == "object"' "$WORK/audit.json" > /dev/null 2> "$WORK/jq.err" || ! jq -r '
    [.vulnerabilities[] | .via[] | select(type == "object")
        | select(.severity == "high" or .severity == "critical")
        | [.name, ((.url // "") | split("/") | last | ascii_upcase), .severity, (.title // ""), (.url // "")]]
    | unique | .[] | @tsv
' "$WORK/audit.json" > "$WORK/advisories.tsv" 2> "$WORK/jq.err"; then
    printf 'HIBA: az npm audit nem adott értelmezhető eredményt (hálózat vagy npm-hiba); a vizsgálat nem futott le.\n' >&2
    sed 's/^/  /' "$WORK/audit.err" "$WORK/jq.err" >&2 || true
    exit 3
fi

status=0
awk -F '\t' -v exceptions="$WORK/exceptions.tsv" '
    FILENAME == exceptions { waiver[$1 SUBSEP $2] = $0; next }
    {
        key = $1 SUBSEP $2
        if (key in waiver) {
            split(waiver[key], f, "\t")
            used[key] = 1
            printf "  KIVÉTEL   %s  %s (%s) – indoklás: %s (felülvizsgálat: %s)\n", $1, $2, $3, f[4], f[3]
            next
        }
        found++
        printf "  LELET     %s  %s (%s): %s  %s\n", $1, $2, $3, $4, $5
    }
    END {
        for (key in waiver) {
            if (!(key in used)) {
                split(waiver[key], f, "\t")
                printf "  megjegyzés: a(z) %s %s kivételre már nincs szükség (a közlemény nem érinti a csomagokat), törölhető.\n", f[1], f[2]
            }
        }
        exit found > 0 ? 1 : 0
    }
' "$WORK/exceptions.tsv" "$WORK/advisories.tsv" || status=$?

if [[ $status == 0 ]]; then
    echo "npm audit: nincs magas vagy kritikus közlemény a kivételeken kívül."
else
    printf 'HIBA: magas vagy kritikus közlemény az npm-csomagokban. Ha nincs javított verzió, és a hiba a kiadást\n' >&2
    printf 'bizonyíthatóan nem érinti, a közlemény indoklással és felülvizsgálati dátummal az npm-audit.exceptions fájlba vehető fel.\n' >&2
fi
exit "$status"
