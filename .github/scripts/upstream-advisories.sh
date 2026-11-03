#!/usr/bin/env bash
#
# OpenHDID – a forrásból épített összetevők verzióinak összevetése a gyártók biztonsági közleményeivel.
# Az nginx-et, a PHP-t, a libcurl-t, a phpredist és a headers-more-t nem csomagkezelő telepíti, ezért
# a Trivy nem látja őket; ez a szkript a kapujuk. A kiadás előtti ellenőrzés (preflight, 5. lépés)
# a Dockerfile rögzítéseivel futtatja és megáll, ha érintett verziót talál; a heti security-scan.yml
# a legutóbbi image-ből kiolvasott verziókkal futtatja (csak riaszt); a platform.sh check tájékoztatásul.
#
# Használat:
#   upstream-advisories.sh [--exceptions FÁJL] --dockerfile Dockerfile
#   upstream-advisories.sh [--exceptions FÁJL] nginx=1.31.8 libcurl=8.23.0 php=8.5.12 phpredis=6.3.0 headers-more=0.41
#
# Források (a címek env-vel felülírhatók; a tesztek file:// címet és saját parancsot adnak):
#   nginx           ADVISORY_NGINX_URL  https://nginx.org/en/security_advisories.html („Vulnerable:” sávok)
#   libcurl         ADVISORY_CURL_URL   https://curl.se/docs/vuln.json (OSV); a csak a curl parancssori
#                                       eszközt érintő közlemény kimarad: az image-ben csak a libcurl van
#   php             ADVISORY_PHP_URL    a php.net kiadáslistája a rögzített ágra ({branch}); egy újabb,
#                                       „security” címkés kiadás azt jelenti, hogy a rögzített verzió javítatlan
#   phpredis,       ADVISORY_GH         a GitHub-repó közzétett biztonsági közleményei; a parancs egy
#   headers-more                        API-útvonalat kap (alapból: gh api)
#
# Kivételek: a szkript melletti upstream-advisories.exceptions (nyilvános), soronként
#   komponens | közlemény-azonosító | felülvizsgálat (ÉÉÉÉ-HH-NN) | indoklás
# Indoklás nélküli, lejárt vagy egy évnél későbbi felülvizsgálatú sor hiba; a futás a felhasznált
# kivételeket az indoklásukkal együtt kiírja, a nem használtakat jelzi.
#
# Kilépési kód: 0 nincs érintett rögzítés; 1 van érintett rögzítés; 2 hibás kivételfájl; 3 egy forrás
# nem érhető el, vagy megváltozott a formátuma; 64 hibás hívás.
#
# bash 3.2–5. Szükséges: curl, jq, sort -V; a phpredis és a headers-more lekérdezéséhez gh (vagy ADVISORY_GH).
set -euo pipefail
export LC_ALL=C

HERE=$(cd "$(dirname "$0")" && pwd -P)
EXCEPTIONS="$HERE/upstream-advisories.exceptions"
NGINX_URL=${ADVISORY_NGINX_URL:-https://nginx.org/en/security_advisories.html}
CURL_URL=${ADVISORY_CURL_URL:-https://curl.se/docs/vuln.json}
PHP_URL=${ADVISORY_PHP_URL:-https://www.php.net/releases/index.php?json&max=100&version=\{branch\}}
GH_COMMAND=${ADVISORY_GH:-gh api}
COMPONENTS="nginx libcurl php phpredis headers-more"

usage() {
    printf 'HIBA: %s\n' "$*" >&2
    awk 'NR > 1 && !/^#/ { exit } NR > 1 { print }' "$0" >&2
    exit 64
}
feed_error() {
    printf 'HIBA: %s\n' "$*" >&2
    exit 3
}

# The pinned versions, one variable per component (bash 3.2 has no associative arrays).
v_nginx="" v_libcurl="" v_php="" v_phpredis="" v_headers_more=""
set_version() {
    [[ $2 =~ ^[0-9]+(\.[0-9]+)+$ ]] || usage "érvénytelen verzió: $1=$2"
    case $1 in
        nginx) v_nginx=$2 ;;
        libcurl) v_libcurl=$2 ;;
        php) v_php=$2 ;;
        phpredis) v_phpredis=$2 ;;
        headers-more) v_headers_more=$2 ;;
        *) usage "ismeretlen komponens: $1 (ismert: $COMPONENTS)" ;;
    esac
}
version_of() {
    case $1 in
        nginx) printf '%s' "$v_nginx" ;;
        libcurl) printf '%s' "$v_libcurl" ;;
        php) printf '%s' "$v_php" ;;
        phpredis) printf '%s' "$v_phpredis" ;;
        headers-more) printf '%s' "$v_headers_more" ;;
    esac
}

dockerfile=""
while [[ $# -gt 0 ]]; do
    case $1 in
        --exceptions) [[ $# -ge 2 ]] || usage "a --exceptions után fájl kell"; EXCEPTIONS=$2; shift 2 ;;
        --dockerfile) [[ $# -ge 2 ]] || usage "a --dockerfile után fájl kell"; dockerfile=$2; shift 2 ;;
        -h | --help) awk 'NR > 1 && !/^#/ { exit } NR > 1 { print }' "$0"; exit 0 ;;
        *=*) set_version "${1%%=*}" "${1#*=}"; shift ;;
        *) usage "ismeretlen paraméter: $1" ;;
    esac
done

# The Dockerfile pins: ARG NGINX_VERSION, CURL_VERSION, PHPREDIS_VERSION, HEADERS_MORE_VERSION and the PHP image tag.
if [[ -n $dockerfile ]]; then
    [[ -f $dockerfile ]] || usage "nincs ilyen Dockerfile: $dockerfile"
    arg() { sed -n "s/^ARG $1=//p" "$dockerfile"; }
    php_image=$(arg PHP_IMAGE)
    set_version nginx "$(arg NGINX_VERSION)"
    set_version libcurl "$(arg CURL_VERSION)"
    set_version php "$(sed -E 's/^php:([0-9.]+)-.*/\1/' <<< "$php_image")"
    set_version phpredis "$(arg PHPREDIS_VERSION)"
    set_version headers-more "$(arg HEADERS_MORE_VERSION)"
fi

checked=""
for component in $COMPONENTS; do
    [[ -z $(version_of "$component") ]] || checked="$checked $component"
done
[[ -n $checked ]] || usage "nincs megadott verzió"
for tool in curl jq sort awk; do
    command -v "$tool" >/dev/null || feed_error "hiányzó eszköz: $tool"
done

WORK=$(mktemp -d "${TMPDIR:-/tmp}/upstream-advisories.XXXXXX")
trap 'rm -rf "$WORK"' EXIT

# --- Exceptions: checked before any download, so a wrong file fails fast and the same way offline ---
today=$(date -u +%Y-%m-%d)
latest_review="$((10#${today%%-*} + 1))-${today#*-}"
[[ -f $EXCEPTIONS ]] || { printf 'HIBA: nincs kivételfájl: %s\n' "$EXCEPTIONS" >&2; exit 2; }
awk -v today="$today" -v latest="$latest_review" -v file="$EXCEPTIONS" -v known="$COMPONENTS" '
    function trim(s) { sub(/^[ \t\r]+/, "", s); sub(/[ \t\r]+$/, "", s); return s }
    function bad(message) { errors = errors "  " file ":" NR ": " message "\n" }
    /^[ \t]*(#|$)/ { next }
    {
        n = split($0, field, "|")
        component = trim(field[1]); id = toupper(trim(field[2])); review = trim(field[3]); reason = ""
        for (i = 4; i <= n; i++) { reason = reason (i > 4 ? "|" : "") field[i] }
        reason = trim(reason)
        if (n < 4 || length(reason) < 10) { bad("nincs indoklás (komponens | azonosító | felülvizsgálat | indoklás; az indoklás legalább 10 karakter)"); next }
        if (index(" " known " ", " " component " ") == 0) { bad("ismeretlen komponens: " component " (ismert: " known ")"); next }
        if (id !~ /^[A-Z0-9][A-Z0-9._-]*$/) { bad("hibás közlemény-azonosító: " id); next }
        if (review !~ /^[0-9][0-9][0-9][0-9]-[01][0-9]-[0-3][0-9]$/) { bad("a felülvizsgálat nem ÉÉÉÉ-HH-NN dátum: " review); next }
        if (review < today) { bad("a felülvizsgálat dátuma lejárt (" review "): a kivételt újra át kell nézni"); next }
        if (review > latest) { bad("a felülvizsgálat legfeljebb egy évre előre szólhat (" review " > " latest ")"); next }
        if ((component SUBSEP id) in seen) { bad("ugyanaz a kivétel kétszer szerepel: " component " " id); next }
        seen[component SUBSEP id] = 1
        printf "%s\t%s\t%s\t%s\t%s:%d\n", component, id, review, reason, file, NR
    }
    END { if (errors != "") { printf "HIBA: a kivételfájl hibás:\n%s", errors > "/dev/stderr"; exit 2 } }
' "$EXCEPTIONS" > "$WORK/exceptions.tsv" || exit 2

# --- Feeds -> interval rows ---------------------------------------------------------------------------
# One tab-separated row per vulnerable interval: component, shown id (the CVE where there is one), every
# id of the advisory, summary, link, fixed in, skip reason (why it cannot apply to the image), lower bound,
# its kind (ge|gt), upper bound, its kind (lt|le). An empty bound is open.
ROWS="$WORK/rows.tsv"
: > "$ROWS"

fetch() {
    curl -fsSL --retry 2 --max-time 60 -o "$2" "$1" </dev/null \
        || feed_error "$3: a forrás nem érhető el: $1"
}

if [[ -n $v_nginx ]]; then
    fetch "$NGINX_URL" "$WORK/nginx.html" nginx
    # One advisory per <li><p> line, as nginx.org writes them: title, Severity, links, the CVE,
    # "Not vulnerable:" and "Vulnerable:" (ranges a-b, single versions, "all", or nginx/Windows only).
    # A "Vulnerable:" line in any other shape fails, so a change to some advisories cannot hide them.
    awk '
        function text(re, strip,   s) { if (!match($0, re)) return ""; s = substr($0, RSTART, RLENGTH); sub(strip, "", s); return s }
        function out(skip, from, fk, to, tk) {
            printf "nginx\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n", id, toupper(id), title, url, fixed, skip, from, fk, to, tk
        }
        /<br>Vulnerable: / && !(/^<li><p>/ && /Severity:/) { printf "nginx: közlemény a várt egysoros alakon kívül (<li><p>…Severity:…Vulnerable:): %s\n", substr($0, 1, 160) > "/dev/stderr"; failed = 1; exit 3 }
        /^<li><p>/ && /Severity:/ {
            if ($0 !~ /<br>Vulnerable: /) { printf "nginx: közlemény „Vulnerable:” sor nélkül: %s\n", substr($0, 1, 160) > "/dev/stderr"; failed = 1; exit 3 }
            count++
            title = text("^<li><p>[^<]*", "^<li><p>")
            severity = text("Severity: (<b>)?[a-z]+", "Severity: (<b>)?")
            id = text(">CVE-[0-9]+-[0-9]+<", "^>"); sub(/<$/, "", id)
            if (id == "") { id = text("<a href=\"[^\"]*\">[^<]*</a><br>Not vulnerable", "<br>Not vulnerable$"); sub(/^<a href="[^"]*">/, "", id); sub(/<\/a>$/, "", id) }
            if (id == "") { id = "nginx-" count }
            url = text("<a href=\"[^\"]*\">Advisory</a>", "\">Advisory</a>$"); sub(/^<a href="/, "", url)
            fixed = text("Not vulnerable: [^<]*", "^Not vulnerable: ")
            vulnerable = text("<br>Vulnerable: [^<]*", "^<br>Vulnerable: ")
            skip = (severity == "none") ? "az nginx szerint nincs súlyossága (Severity: none)" : ""
            n = split(vulnerable, part, ", ")
            for (i = 1; i <= n; i++) {
                range = part[i]
                rowskip = sub(/^nginx\/Windows /, "", range) ? "csak a Windowsos nginx-et érinti; az image Linuxos" : skip
                if (range == "all") { out(rowskip, "", "", "", "") }
                else if (range ~ /^[0-9][0-9.]*-[0-9][0-9.]*$/) { split(range, ab, "-"); out(rowskip, ab[1], "ge", ab[2], "le") }
                else if (range ~ /^[0-9][0-9.]*$/) { out(rowskip, range, "ge", range, "le") }
                else { printf "nginx: olvashatatlan „Vulnerable:” sáv: %s (%s)\n", range, id > "/dev/stderr"; failed = 1; exit 3 }
            }
        }
        END { if (!failed && count == 0) { print "nginx: a közleményoldalon nincs egyetlen közlemény sem (formátumváltozás?)" > "/dev/stderr"; exit 3 } }
    ' "$WORK/nginx.html" >> "$ROWS" || feed_error "nginx: a közleményoldal formátuma megváltozott ($NGINX_URL)"
fi

if [[ -n $v_libcurl ]]; then
    fetch "$CURL_URL" "$WORK/curl.json" libcurl
    # OSV: affected[].ranges[] of type SEMVER, events introduced / fixed / last_affected in order.
    jq -r '
        def intervals:
            reduce .[] as $e ({open: null, out: []};
                if ($e | has("introduced")) then .open = ($e.introduced | if . == "0" then "" else . end)
                elif ($e | has("fixed")) then .out += [[(.open // ""), "ge", $e.fixed, "lt"]] | .open = null
                elif ($e | has("last_affected")) then .out += [[(.open // ""), "ge", $e.last_affected, "le"]] | .open = null
                elif ($e | has("limit")) then .
                else error("unknown range event: \($e | tojson)") end)
            | .out + (if .open == null then [] else [[.open, "ge", "", ""]] end);
        if type != "array" or length == 0 then error("not a non-empty list of OSV entries") else . end
        | .[]
        | . as $a
        | if (($a.id | type) != "string") or (($a.affected | type) != "array") then error("an entry without id or affected list") else . end
        | [$a.affected[].ranges[]? | select(.type == "SEMVER") | .events] as $ranges
        | (if $ranges != [] then [$ranges[] | intervals[]]
           else [$a.affected[].versions[]? | [., "ge", ., "le"]] end) as $rows
        | if $rows == [] then error("\($a.id): no SEMVER range and no version list") else . end
        | ([($a.aliases // [])[] | select(startswith("CVE-"))] | first // $a.id) as $shown
        | ([$a.id] + ($a.aliases // []) | map(ascii_upcase) | join(" ")) as $ids
        | ([$ranges[][] | .fixed // empty] | join(", ")) as $fixed
        | (if ($a.database_specific.affects // "") == "tool" then "csak a curl parancssori eszközt érinti; az image-ben csak a libcurl van" else "" end) as $skip
        | $rows[]
        | ["libcurl", $shown, $ids, (($a.summary // "") | gsub("[\t\r\n]+"; " ")), ($a.database_specific.www // ""), $fixed, $skip] + .
        | @tsv
    ' "$WORK/curl.json" >> "$ROWS" || feed_error "libcurl: a curl.se közleménylistájának formátuma megváltozott ($CURL_URL)"
fi

if [[ -n $v_php ]]; then
    php_branch=${v_php%.*}
    php_url=${PHP_URL//\{branch\}/$php_branch}
    fetch "$php_url" "$WORK/php.json" php
    # {"8.5.12": {"tags": ["security"], …}, …}: every newer security release of the branch is a finding.
    jq -r --arg pinned "$v_php" '
        if type != "object" or length == 0 or (keys | all(test("^[0-9]+\\.[0-9]+\\.[0-9]+$")) | not)
            then error("not a php.net release list keyed by version") else . end
        | if has($pinned) then . else error("PHP \($pinned) is not in the release list of its branch") end
        | to_entries[]
        | select(((.value.tags // []) | index("security")) != null)
        | .key as $r
        | ["php", "PHP-\($r)", "PHP-\($r)", "a PHP \($r) biztonsági javításokat hozott (php.net: security)",
           "https://www.php.net/ChangeLog-\($r | split(".")[0]).php#\($r)", $r, "", "", "", $r, "lt"]
        | @tsv
    ' "$WORK/php.json" >> "$ROWS" || feed_error "php: a php.net kiadáslistájának formátuma megváltozott ($php_url)"
fi

# The published repository advisories of a GitHub project. vulnerable_version_range: comma-separated
# parts; consecutive bounds (">= 1.0, < 2.0") make one interval, "a - b" and a single version one each.
github_advisories() {
    local component=$1 repo=$2 json="$WORK/gh-$1.json"
    local -a gh_call
    read -r -a gh_call <<< "$GH_COMMAND"
    command -v "${gh_call[0]}" >/dev/null || feed_error "$component: hiányzó eszköz: ${gh_call[0]} (GitHub-közlemények; ADVISORY_GH)"
    "${gh_call[@]}" "repos/$repo/security-advisories?state=published&per_page=100" < /dev/null > "$json" \
        || feed_error "$component: a GitHub-közlemények nem érhetők el (repos/$repo/security-advisories)"
    jq -r --arg component "$component" '
        def bounds($range):
            ($range | split(",") | map(gsub("^\\s+|\\s+$"; "")) | map(select(. != ""))) as $parts
            | if $parts == [] then [["", "", "", ""]] else
                (reduce $parts[] as $p ({cur: null, out: []};
                    if ($p | test("^[0-9][^\\s]* - [0-9][^\\s]*$")) then
                        ($p | split(" - ")) as $ab
                        | (if .cur then .out += [.cur] else . end) | .cur = null | .out += [[$ab[0], "ge", $ab[1], "le"]]
                    elif ($p | test("^(<=|>=|<|>|=)\\s*[0-9][^\\s]*$")) then
                        ($p | capture("^(?<op><=|>=|<|>|=)\\s*(?<v>.+)$")) as $b
                        | if $b.op == "=" then (if .cur then .out += [.cur] else . end) | .cur = null | .out += [[$b.v, "ge", $b.v, "le"]]
                          elif ($b.op | startswith(">")) then
                              (if .cur then .out += [.cur] else . end)
                              | .cur = [$b.v, (if $b.op == ">" then "gt" else "ge" end), "", ""]
                          else
                              (if .cur and .cur[2] != "" then .out += [.cur] | .cur = null else . end)
                              | .cur = ((.cur // ["", "", "", ""]) | .[2] = $b.v | .[3] = (if $b.op == "<" then "lt" else "le" end))
                          end
                    elif ($p | test("^[0-9][^\\s]*$")) then
                        (if .cur then .out += [.cur] else . end) | .cur = null | .out += [[$p, "ge", $p, "le"]]
                    else error("unreadable version range: \($range)") end)
                | .out + (if .cur then [.cur] else [] end))
              end;
        if type != "array" then error("not a list of repository advisories") else . end
        | if length >= 100 then error("100 or more advisories: the list needs paging") else . end
        | .[]
        | select(.withdrawn_at == null)
        | . as $a
        | if ($a.ghsa_id | type) != "string" then error("an advisory without ghsa_id") else . end
        | (($a.vulnerabilities // []) | if . == [] then [{vulnerable_version_range: ""}] else . end) as $vulnerabilities
        | ([$vulnerabilities[] | .patched_versions // "" | select(. != "")] | join(", ")
           | if . == "" and ([$vulnerabilities[] | .vulnerable_version_range // "" | select(. != "")] == [])
             then "nincs verziósáv: minden verzió érintettnek számít" else . end) as $fixed
        | ([$a.ghsa_id, ($a.cve_id // empty)] | map(ascii_upcase) | join(" ")) as $ids
        | $vulnerabilities[]
        | bounds(.vulnerable_version_range // "")[]
        | [$component, ($a.cve_id // $a.ghsa_id), $ids, (($a.summary // "") | gsub("[\t\r\n]+"; " ")), ($a.html_url // ""), $fixed, ""] + .
        | @tsv
    ' "$json" >> "$ROWS" || feed_error "$component: a GitHub-közlemények formátuma megváltozott (repos/$repo/security-advisories)"
}
[[ -z $v_phpredis ]] || github_advisories phpredis phpredis/phpredis
[[ -z $v_headers_more ]] || github_advisories headers-more openresty/headers-more-nginx-module

# --- Evaluation -------------------------------------------------------------------------------------
# Every version is ranked once by sort -V, the order the rest of the release scripts use; the intervals
# are then compared by rank.
pins=""
for component in $checked; do
    pins="$pins $component=$(version_of "$component")"
done
{
    cut -f8,10 "$ROWS" | tr '\t' '\n'
    for pin in $pins; do printf '%s\n' "${pin#*=}"; done
} | sed '/^$/d' | sort -u | sort -V > "$WORK/order"

printf 'Rögzített verziók:%s\n' "$(sed 's/=/ /g; s/ \([a-z]\)/, \1/g; s/^,//' <<< "$pins")"
status=0
awk -F '\t' -v pins="$pins" -v order="$WORK/order" -v exceptions="$WORK/exceptions.tsv" '
    BEGIN {
        n = split(pins, pin, " ")
        for (i = 1; i <= n; i++) { split(pin[i], kv, "="); version[kv[1]] = kv[2]; name[i] = kv[1] }
    }
    FILENAME == order { rank[$0] = FNR; next }
    FILENAME == exceptions { waiver[$1 SUBSEP $2] = $0; next }
    function inside(v, from, fk, to, tk,   r) {
        r = rank[v]
        if (from != "") {
            if (fk == "gt" && r <= rank[from]) return 0
            if (fk != "gt" && r < rank[from]) return 0
        }
        if (to != "") {
            if (tk == "lt" && r >= rank[to]) return 0
            if (tk != "lt" && r > rank[to]) return 0
        }
        return 1
    }
    function waived(component, ids,   k, m, list) {
        m = split(ids, list, " ")
        for (k = 1; k <= m; k++) { if ((component SUBSEP list[k]) in waiver) return component SUBSEP list[k] }
        return ""
    }
    {
        component = $1; key = component SUBSEP $3
        if (!(component in version)) next
        if (!(key in seen)) { seen[key] = 1; total[component]++ }
        if ((key in hit) || !inside(version[component], $8, $9, $10, $11)) next
        hit[key] = 1
        where = sprintf("%-12s %-14s", component " " version[component], $2)
        fixed = ($6 == "") ? "" : " – javítva: " $6
        link = ($5 == "") ? "" : " – " $5
        if ($7 != "") { skipped[component]++; lines = lines sprintf("  KIHAGYVA  %s  %s – %s\n", where, $4, $7); next }
        w = waived(component, $3)
        if (w != "") {
            split(waiver[w], f, "\t"); used[w] = 1; waivedcount[component]++
            lines = lines sprintf("  KIVÉTEL   %s  %s – indoklás: %s (felülvizsgálat: %s; %s)\n", where, $4, f[4], f[3], f[5])
            next
        }
        affected[component]++; affectedtotal++
        lines = lines sprintf("  ÉRINTETT  %s  %s%s%s\n", where, $4, fixed, link)
    }
    END {
        for (i = 1; i <= n; i++) {
            c = name[i]
            printf "  %s %s: %d közlemény, érintett: %d, kivétel: %d, kihagyva: %d\n", c, version[c], total[c] + 0, affected[c] + 0, waivedcount[c] + 0, skipped[c] + 0
        }
        printf "%s", lines
        for (w in waiver) {
            split(waiver[w], f, "\t")
            if (!(w in used) && (f[1] in version)) { printf "  figyelem: nem használt kivétel (törölhető): %s %s %s\n", f[5], f[1], f[2] }
        }
        if (affectedtotal > 0) exit 1
    }
' "$WORK/order" "$WORK/exceptions.tsv" "$ROWS" || status=$?

case $status in
    0) echo "Nincs érintett rögzítés." ;;
    1)
        printf '%s\n' \
            "HIBA: a fenti ÉRINTETT közlemények a rögzített verziót érintik." \
            "Teendő: platform.sh check, majd apply (újabb, javított verzió), és új javítóverzió." \
            "Ha az érintett rész nincs a buildben (például egy be nem fordított modul vagy kikapcsolt protokoll)," \
            "a közlemény indoklással és felülvizsgálati dátummal az upstream-advisories.exceptions fájlba vehető fel." >&2
        exit 1
        ;;
    *) feed_error "az összevetés nem sikerült (kilépési kód $status)" ;;
esac
