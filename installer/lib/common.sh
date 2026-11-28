#!/usr/bin/env bash
# Shared helpers for install.sh and revert.sh (Linux). Sourced, never run directly.

if [ -t 1 ]; then
    C_RED=$'\033[31m'; C_GRN=$'\033[32m'; C_YEL=$'\033[33m'; C_BLU=$'\033[36m'; C_BLD=$'\033[1m'; C_OFF=$'\033[0m'
else
    C_RED=""; C_GRN=""; C_YEL=""; C_BLU=""; C_BLD=""; C_OFF=""
fi

LOGFILE=""
_log()  { [ -n "$LOGFILE" ] && printf '%s %s\n' "$(date '+%F %T')" "$*" >> "$LOGFILE" 2>/dev/null || true; }
say()   { printf '%s\n' "$*"; _log "$*"; }
step()  { printf '\n%s== %s%s\n' "$C_BLD$C_BLU" "$*" "$C_OFF"; _log "== $*"; }
ok()    { printf '  %s[ ok ]%s %s\n' "$C_GRN" "$C_OFF" "$*"; _log "OK   $*"; }
warn()  { printf '  %s[warn]%s %s\n' "$C_YEL" "$C_OFF" "$*" >&2; _log "WARN $*"; }
die()   { printf '\n%s[FAIL]%s %s\n' "$C_RED" "$C_OFF" "$*" >&2; _log "FAIL $*"; exit 1; }

ASSUME_YES=0
CLEAN_FILES=()
cleanup() { local f; for f in "${CLEAN_FILES[@]:-}"; do [ -n "$f" ] && rm -f -- "$f" 2>/dev/null; done; return 0; }
trap cleanup EXIT

# ask VAR "Question" "default"
ask() {
    local __var="$1" __q="$2" __def="${3:-}" __ans
    if [ "$ASSUME_YES" = 1 ]; then printf -v "$__var" '%s' "$__def"; return 0; fi
    [ -t 0 ] || die "No terminal to ask \"$__q\" on. Pass the answers as options (see --help) or use --yes."
    if [ -n "$__def" ]; then read -r -p "  $__q [$__def]: " __ans; else read -r -p "  $__q: " __ans; fi
    printf -v "$__var" '%s' "${__ans:-$__def}"
}
# ask_secret VAR "Question"
ask_secret() {
    local __var="$1" __q="$2" __ans
    [ -t 0 ] || die "No terminal to ask \"$__q\" on."
    read -r -s -p "  $__q: " __ans; echo
    printf -v "$__var" '%s' "$__ans"
}
# confirm "Question" y|n   -> 0 = yes
confirm() {
    local q="$1" def="${2:-y}" ans hint="[Y/n]"
    [ "$def" = n ] && hint="[y/N]"
    if [ "$ASSUME_YES" = 1 ]; then [ "$def" = y ]; return; fi
    [ -t 0 ] || die "No terminal to ask \"$q\" on. Use --yes to accept the defaults."
    read -r -p "  $q $hint " ans
    ans="${ans:-$def}"
    [[ "$ans" =~ ^[Yy] ]]
}

need_cmd() { command -v "$1" >/dev/null 2>&1 || die "'$1' is required but was not found.${2:+ $2}"; }

# --- MySQL credentials: kept in a private option file so passwords never show up in `ps` -----------
# mk_cnf HOST USER PASS  -> sets MK_CNF to the path of a 0600 option file (removed automatically on exit).
# (Not a command substitution on purpose: the clean-up list must be updated in this shell.)
MK_CNF=""
mk_cnf() {
    local host="$1" user="$2" pass="$3" port="" f
    if [[ "$host" == *:* && "$host" != */* ]]; then port="${host##*:}"; host="${host%%:*}"; fi
    f="$(mktemp "${TMPDIR:-/tmp}/echcnf.XXXXXX")"; chmod 600 "$f"
    pass="${pass//\\/\\\\}"; pass="${pass//\"/\\\"}"
    { echo "[client]"; echo "host=\"$host\""; [ -n "$port" ] && echo "port=$port"; echo "user=\"$user\""; echo "password=\"$pass\""; } > "$f"
    CLEAN_FILES+=("$f")
    MK_CNF="$f"
}

# read_config /path/to/config.php  -> sets CFG_HOST CFG_USER CFG_PASS CFG_DB CFG_PATH CFG_VER
read_config() {
    local cfg="$1" out
    [ -r "$cfg" ] || return 1
    out="$(php -d display_errors=0 -r '
        $_SERVER["SCRIPT_FILENAME"] = "installer";
        @include $argv[1];
        $v = [];
        foreach (["DBL_HOSTNAME","DBL_USERNAME","DBL_PASSWORD","DBL_DB","PATH","ECH_VER","INSTALLED"] as $c) { $v[] = defined($c) ? (string) constant($c) : ""; }
        echo implode("\x1f", $v);
    ' "$cfg" 2>/dev/null)" || return 1
    local IFS=$'\x1f'
    # shellcheck disable=SC2162
    read -r CFG_HOST CFG_USER CFG_PASS CFG_DB CFG_PATH CFG_VER CFG_INSTALLED <<< "$out"
    [ -n "$CFG_DB" ] && [ -n "$CFG_USER" ]
}

# mysqldump options that depend on the client version
dump_opts() {
    local o="--single-transaction --quick --default-character-set=binary --hex-blob"
    local help; help="$(mysqldump --help 2>/dev/null || true)"
    grep -q -- '--no-tablespaces' <<< "$help" && o="$o --no-tablespaces"
    grep -q -- '--column-statistics' <<< "$help" && o="$o --column-statistics=0"
    printf '%s' "$o"
}

# dump_db CNF DBNAME OUTFILE.sql.gz [table ...]
dump_db() {
    local cnf="$1" db="$2" out="$3"; shift 3
    local tmp="${out%.gz}.tmp"
    # shellcheck disable=SC2046
    mysqldump --defaults-extra-file="$cnf" $(dump_opts) "$db" "$@" > "$tmp" || { rm -f "$tmp"; return 1; }
    tail -n 3 "$tmp" | grep -q "Dump completed" || { rm -f "$tmp"; return 1; }
    gzip -9 -c "$tmp" > "$out" && rm -f "$tmp"
    chmod 600 "$out"
    gzip -t "$out"
}

# Guess the web server's user / group
detect_web_user() {
    local u
    u="$(ps -eo user:32,comm 2>/dev/null | awk '$2 ~ /^(apache2|httpd|nginx|php-fpm.*|lighttpd|caddy)$/ && $1 != "root" {print $1; exit}')"
    [ -n "$u" ] || { id www-data >/dev/null 2>&1 && u=www-data; }
    [ -n "$u" ] || { id apache >/dev/null 2>&1 && u=apache; }
    [ -n "$u" ] || { id nginx >/dev/null 2>&1 && u=nginx; }
    printf '%s' "${u:-www-data}"
}

# sha256 of a file (first field only)
sha_of() { sha256sum "$1" | awk '{print $1}'; }

# Make the swap of two directories as quick as possible: $1 = staged tree, $2 = live path
# (old tree is moved to $3 first). Rolls back by itself if the second move fails.
swap_in() {
    local staged="$1" live="$2" aside="$3"
    if [ -e "$live" ]; then mv -- "$live" "$aside" || return 1; fi
    if ! mv -- "$staged" "$live"; then
        [ -e "$aside" ] && mv -- "$aside" "$live"
        return 1
    fi
    return 0
}

# Pretty printing of the sizes in a backup dir
human() { du -sh "$1" 2>/dev/null | awk '{print $1}'; }
