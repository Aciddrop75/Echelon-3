#!/usr/bin/env bash
# ============================================================================
#  Echelon 3 installer for Linux
#
#  * Finds your existing Echelon, backs up its files AND its database, installs
#    Echelon 3 cleanly in its place, keeps your settings, and checks the result.
#  * If anything goes wrong after the switch it puts the old version back by itself.
#  * Later on, <backup folder>/revert.sh (or ./revert.sh) puts the old version back.
#  * No Echelon installed yet?  It sets up a new one (files + database tables).
#
#  Run it as root (sudo ./install.sh) so file owners and permissions can be set.
#  Windows has no script: follow INSTALL-WINDOWS.md.
# ============================================================================
set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PKG="$(cd "$HERE/.." && pwd)"
APP_SRC="$PKG/echelon"
SQL_DIR="$HERE/sql"
# shellcheck source=lib/common.sh
source "$HERE/lib/common.sh"

NEW_VER="$(tr -d '[:space:]' < "$APP_SRC/version.txt" 2>/dev/null || echo v3.0)"

usage() {
cat <<EOF
Echelon $NEW_VER installer (Linux)

Usage: sudo ./install.sh [options]

  --target DIR        Folder where Echelon is (or should be) installed, e.g. /var/www/html/echelon
  --backup-dir DIR    Where backups are kept (default: /var/backups/echelon, or ~/echelon-backups when not root)
  --web-group GROUP   Group of the web server user (default: taken from the old install, else detected)
  --url URL           Public address of Echelon, e.g. http://localhost/echelon/  (adds a web check at the end)
  --keep-old          Keep an extracted copy of the old site in the backup folder (the .tar.gz is always kept)
  --check             Only look and report (nothing is changed)
  -y, --yes           Do not ask questions, accept the defaults
  -h, --help          This text

To go back to the old version later:  sudo <backup folder>/revert.sh
EOF
}

TARGET=""; BACKUP_ROOT=""; WEB_GROUP=""; URL=""; KEEP_OLD=0; CHECK_ONLY=0
while [ $# -gt 0 ]; do
    case "$1" in
        --target)     TARGET="${2:?--target needs a folder}"; shift 2 ;;
        --backup-dir) BACKUP_ROOT="${2:?--backup-dir needs a folder}"; shift 2 ;;
        --web-group)  WEB_GROUP="${2:?--web-group needs a name}"; shift 2 ;;
        --url)        URL="${2:?--url needs an address}"; shift 2 ;;
        --keep-old)   KEEP_OLD=1; shift ;;
        --check)      CHECK_ONLY=1; shift ;;
        -y|--yes)     ASSUME_YES=1; shift ;;
        -h|--help)    usage; exit 0 ;;
        *) usage; die "Unknown option: $1" ;;
    esac
done

# --------------------------------------------------------------------------------------------
SWAPPED=0; DONE=0; ASIDE=""; BK=""; STAGE=""; TARGET_REAL=""
rollback() {
    [ "$SWAPPED" = 1 ] || return 0
    warn "Something failed - putting the previous version back."
    if [ -d "$ASIDE" ]; then
        [ -e "$TARGET_REAL" ] && mv -- "$TARGET_REAL" "$BK/.failed-new" 2>/dev/null || true
        mv -- "$ASIDE" "$TARGET_REAL" && warn "Previous files restored at $TARGET_REAL. Your backup is still in $BK."
    fi
}
on_exit() {
    local rc=$?
    if [ $rc -ne 0 ] && [ "$DONE" = 0 ]; then rollback || true; fi
    # a half-built staging folder is ours to remove (its name always starts with .echelon-stage.)
    if [ -n "$STAGE" ] && [ -d "$STAGE" ] && [[ "$(basename "$STAGE")" == .echelon-stage.* ]]; then rm -rf -- "$STAGE"; fi
    cleanup
    return $rc
}
trap on_exit EXIT

printf '%s\n' "${C_BLD}Echelon $NEW_VER installer${C_OFF}"

# ---- 1. Preflight -----------------------------------------------------------------------------
step "Checking this server"
[ -f "$APP_SRC/inc.php" ] || die "The Echelon files were not found next to the installer (expected $APP_SRC). Run the installer from the unpacked package."
[ -f "$SQL_DIR/echelon-upgrade.sql" ] || die "installer/sql/echelon-upgrade.sql is missing from the package."
for c in php tar gzip mysql mysqldump sha256sum mktemp find awk cp mv; do
    case "$c" in
        mysql|mysqldump) need_cmd "$c" "Install the MySQL/MariaDB client (apt install mariadb-client / dnf install mariadb)." ;;
        *)               need_cmd "$c" ;;
    esac
done

PHPV="$(php -r 'echo PHP_VERSION;')"
if php -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);'; then
    ok "PHP $PHPV (command line)"
else
    warn "The PHP command line is version $PHPV; Echelon 3 needs 8.1 or newer."
    confirm "The web server may still use a newer PHP. Continue anyway?" n || die "Install PHP 8.1+ and try again."
fi
MODS="$(php -m 2>/dev/null | tr 'A-Z' 'a-z')"
for m in mysqli gd zip fileinfo mbstring; do
    if grep -qx "$m" <<< "$MODS"; then ok "PHP extension $m"; else warn "PHP extension '$m' is not loaded in the command-line PHP. Make sure the web server's PHP has it (apt install php-$m)."; fi
done
if grep -qx curl <<< "$MODS" || [ "$(php -r 'echo ini_get("allow_url_fopen");')" = 1 ]; then ok "Downloads possible (curl or allow_url_fopen) - needed for the optional GeoLite2 setup"; else warn "Neither curl nor allow_url_fopen is available: the GeoLite2 download in Settings will not work."; fi

if [ "$(id -u)" -eq 0 ]; then ok "Running as root"; else warn "Not running as root: file owners cannot be changed. Use sudo if Echelon's files belong to the web server."; fi

# ---- 2. Find Echelon --------------------------------------------------------------------------
step "Looking for your current Echelon"
if [ -z "$TARGET" ]; then
    FOUND=()
    for d in /var/www/html/echelon /var/www/echelon /var/www/html/Echelon /srv/www/echelon /srv/http/echelon /usr/share/nginx/html/echelon /var/www/vhosts/*/httpdocs/echelon /home/*/public_html/echelon; do
        [ -f "$d/inc/config.php" ] && [ -f "$d/inc.php" ] && FOUND+=("$d")
    done
    if [ "${#FOUND[@]}" -eq 1 ]; then
        say "  Found Echelon in ${FOUND[0]}"
        TARGET="${FOUND[0]}"
        confirm "Install Echelon $NEW_VER over this copy?" y || { ask TARGET "Folder to use instead" "$TARGET"; }
    elif [ "${#FOUND[@]}" -gt 1 ]; then
        say "  Several copies were found:"; i=1; for d in "${FOUND[@]}"; do say "    $i) $d"; i=$((i+1)); done
        ask pick "Which one (number), or type another folder" "1"
        if [[ "$pick" =~ ^[0-9]+$ ]] && [ "$pick" -ge 1 ] && [ "$pick" -le "${#FOUND[@]}" ]; then TARGET="${FOUND[$((pick-1))]}"; else TARGET="$pick"; fi
    else
        say "  No existing Echelon found in the usual places."
        ask TARGET "Folder of your existing Echelon (or where a new one should go)" "/var/www/html/echelon"
    fi
fi
TARGET="${TARGET%/}"
[ -n "$TARGET" ] && [ "$TARGET" != "/" ] || die "Refusing to use '/' as the Echelon folder."
case "$TARGET" in /*) ;; *) TARGET="$(pwd)/$TARGET" ;; esac
PARENT="$(dirname "$TARGET")"; BASE="$(basename "$TARGET")"

MODE=""
if [ -f "$TARGET/inc/config.php" ]; then
    [ -f "$TARGET/inc.php" ] || die "$TARGET has inc/config.php but no inc.php - that does not look like an Echelon folder."
    MODE=upgrade
elif [ -e "$TARGET" ] && [ -n "$(ls -A "$TARGET" 2>/dev/null)" ]; then
    die "$TARGET exists and is not empty, but it is not an Echelon install (no inc/config.php). Pick the right folder with --target."
else
    MODE=fresh
fi
TARGET_REAL="$TARGET"
[ -w "$PARENT" ] || [ "$MODE" = fresh -a ! -d "$PARENT" ] || die "No permission to change $PARENT. Run the installer with sudo."

# ---- 3. Database credentials ------------------------------------------------------------------
if [ "$MODE" = upgrade ]; then
    ok "Existing Echelon found: $TARGET (a copy of the files, plus the database, will be backed up first)"
    OLD_VER="$(tr -d '[:space:]' < "$TARGET/version.txt" 2>/dev/null || true)"
    read_config "$TARGET/inc/config.php" || die "Could not read the database settings from $TARGET/inc/config.php."
    [ -n "$OLD_VER" ] || OLD_VER="${CFG_VER:-unknown}"
    ok "Current version: ${OLD_VER:-unknown}; database '$CFG_DB' on '${CFG_HOST:-localhost}' as '$CFG_USER'"
    [ "$CFG_INSTALLED" = yes ] || warn "The old config says Echelon was never fully installed (INSTALLED is not 'yes')."
    DB_HOST="$CFG_HOST"; DB_USER="$CFG_USER"; DB_PASS="$CFG_PASS"; DB_NAME="$CFG_DB"
else
    say "  Nothing installed in $TARGET - this will be a NEW installation."
    say "  Create the database and user first (see INSTALL-LINUX.md, step 1), then enter them here."
    ask DB_HOST "Database host" "localhost"
    ask DB_NAME "Database name" "echelon"
    ask DB_USER "Database user" "echelon"
    if [ "$ASSUME_YES" = 1 ]; then DB_PASS="${ECHELON_DB_PASSWORD:-}"; else ask_secret DB_PASS "Database password"; fi
fi

mk_cnf "$DB_HOST" "$DB_USER" "$DB_PASS"; CNF="$MK_CNF"
mysql --defaults-extra-file="$CNF" "$DB_NAME" -e "SELECT 1" >/dev/null 2>&1 \
    || die "Cannot connect to database '$DB_NAME' as '$DB_USER' on '${DB_HOST:-localhost}'. Check the details (is the database server running?)."
ok "Database connection works"
dbq() { mysql --defaults-extra-file="$CNF" --batch --raw --skip-column-names "$DB_NAME" "$@"; }

HAS_TABLES="$(dbq -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ech_users'")"
if [ "$MODE" = upgrade ] && [ "$HAS_TABLES" = 0 ]; then
    die "Connected to '$DB_NAME' but it holds no Echelon tables. The config may point at the wrong database."
fi
if [ "$MODE" = fresh ] && [ "$HAS_TABLES" != 0 ]; then
    die "Database '$DB_NAME' already contains Echelon tables. If Echelon is installed somewhere, point --target at that folder so it is upgraded (and backed up); otherwise use an empty database."
fi

# game databases (B3) known to this Echelon - only their map table gets backed up
GAMES=()
if [ "$MODE" = upgrade ]; then
    while IFS=$'\t' read -r gid ghost guser gpw gdb; do
        [ -n "$gid" ] && GAMES+=("$gid"$'\t'"$ghost"$'\t'"$guser"$'\t'"$gpw"$'\t'"$gdb")
    done < <(dbq -e "SELECT id, db_host, db_user, IFNULL(db_pw,''), db_name FROM ech_games" 2>/dev/null || true)
    ok "${#GAMES[@]} game database(s) registered in Echelon"
    USERS_BEFORE="$(dbq -e "SELECT COUNT(*) FROM ech_users")"
fi

# ---- 4. Plan ----------------------------------------------------------------------------------
TS="$(date +%Y%m%d-%H%M%S)"
if [ -z "$BACKUP_ROOT" ]; then
    if [ "$(id -u)" -eq 0 ]; then BACKUP_ROOT=/var/backups/echelon; else BACKUP_ROOT="${HOME:-/tmp}/echelon-backups"; fi
fi
case "$BACKUP_ROOT" in "$TARGET"|"$TARGET"/*) die "The backup folder must not be inside the Echelon folder." ;; esac

step "Plan"
say "  Mode:            $([ "$MODE" = upgrade ] && echo "upgrade ${OLD_VER:-?} -> $NEW_VER (clean install, settings kept)" || echo "new installation of $NEW_VER")"
say "  Echelon folder:  $TARGET"
say "  Database:        $DB_NAME"
if [ "$MODE" = upgrade ]; then
    BK="$BACKUP_ROOT/echelon-backup-$TS-from-${OLD_VER:-unknown}"
    say "  Backup folder:   $BK   (files.tar.gz + database dump + revert.sh)"
    say "  Kept as they are: inc/config.php, images/maps/*, mapcycle/* (your mapcycle links), lib/*.mmdb, lib/GeoIP.dat, lib/log.txt, extra lib/plugins folders"
    say "  Replaced:        every other program file; the old web installer folder is not installed"
fi
if [ "$CHECK_ONLY" = 1 ]; then
    ok "Check finished - nothing was changed."
    exit 0
fi
confirm "Go ahead?" y || die "Cancelled. Nothing was changed."

# ---- 5. Backup --------------------------------------------------------------------------------
if [ "$MODE" = upgrade ]; then
    step "Backing up"
    mkdir -p "$BACKUP_ROOT"; chmod 700 "$BACKUP_ROOT"
    mkdir "$BK"; chmod 700 "$BK"
    LOGFILE="$BK/install.log"; : > "$LOGFILE"; chmod 600 "$LOGFILE"

    dump_db "$CNF" "$DB_NAME" "$BK/echelon-db.sql.gz" || die "The database backup failed - nothing was changed. (Does '$DB_USER' have SELECT and LOCK TABLES rights?)"
    ok "Database '$DB_NAME' saved ($(human "$BK/echelon-db.sql.gz"))"

    n=0
    for g in "${GAMES[@]:-}"; do
        [ -n "$g" ] || continue
        IFS=$'\t' read -r gid ghost guser gpw gdb <<< "$g"
        mk_cnf "$ghost" "$guser" "$gpw"; gcnf="$MK_CNF"
        if has="$(mysql --defaults-extra-file="$gcnf" --batch --skip-column-names "$gdb" -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mapconfig'" 2>/dev/null)"; then
            if [ "$has" != 0 ]; then
                if dump_db "$gcnf" "$gdb" "$BK/b3-$gid-mapconfig.sql.gz" mapconfig; then n=$((n+1)); else warn "Could not back up the map table of game $gid ($gdb)."; fi
            fi
        else
            warn "Could not connect to game database '$gdb' (game $gid) - its map table was not backed up (it is not changed by this installer anyway)."
        fi
    done
    ok "Map tables of $n game database(s) saved"

    tar -C "$PARENT" -czpf "$BK/files.tar.gz" "$BASE" || die "The file backup failed - nothing was changed."
    tar -tzf "$BK/files.tar.gz" >/dev/null || die "The file backup could not be read back - nothing was changed."
    chmod 600 "$BK/files.tar.gz"
    sha_of "$BK/files.tar.gz" > "$BK/files.tar.gz.sha256"
    ok "Files saved ($(human "$BK/files.tar.gz"), checksum written)"

    {
        echo "ECHELON_BACKUP=1"
        echo "CREATED=$(date '+%F %T %z')"
        echo "FROM_VERSION=${OLD_VER:-unknown}"
        echo "TO_VERSION=$NEW_VER"
        echo "TARGET=$TARGET"
        echo "TARGET_PARENT=$PARENT"
        echo "TARGET_BASENAME=$BASE"
        echo "DB_HOST=$DB_HOST"
        echo "DB_NAME=$DB_NAME"
        echo "DB_USER=$DB_USER"
    } > "$BK/backup.info"
    chmod 600 "$BK/backup.info"
    mkdir -p "$BK/lib"; cp "$HERE/revert.sh" "$BK/revert.sh"; cp "$HERE/lib/common.sh" "$BK/lib/common.sh"; chmod 700 "$BK/revert.sh"
    ok "Backup complete: $BK"
fi

# ---- 6. Build the new tree next to the old one (private until it is swapped in) ----------------
step "Preparing Echelon $NEW_VER"
[ "$MODE" = fresh ] && { mkdir -p "$PARENT"; }
STAGE="$(mktemp -d "$PARENT/.echelon-stage.XXXXXX")"   # mode 700: the web server cannot see it yet
cp -a "$APP_SRC/." "$STAGE/"
find "$STAGE" -name 'version-check.txt' -delete 2>/dev/null || true

if [ "$MODE" = upgrade ]; then
    cp -p "$TARGET/inc/config.php" "$STAGE/inc/config.php"
    ok "Kept your configuration (database login and salts)"
    if [ -d "$TARGET/images/maps" ]; then
        mkdir -p "$STAGE/images/maps"
        find "$TARGET/images/maps" -maxdepth 1 -type f ! -name '.htaccess' ! -name 'README.txt' -exec cp -p {} "$STAGE/images/maps/" \;
        ok "Kept $(find "$STAGE/images/maps" -maxdepth 1 -type f ! -name '.htaccess' ! -name 'README.txt' | wc -l) map picture(s)"
    fi
    if [ -d "$TARGET/mapcycle" ]; then
        # the links to your real mapcycle files (cp -a keeps them as links) and the saved previous texts
        find "$TARGET/mapcycle" -mindepth 1 -maxdepth 1 \( -type l -o -type f \) ! -name '.htaccess' ! -name 'README.txt' -exec cp -a {} "$STAGE/mapcycle/" \;
        ok "Kept $(find "$STAGE/mapcycle" -maxdepth 1 -type l | wc -l) mapcycle link(s)"
    fi
    for f in "$TARGET"/lib/*.mmdb "$TARGET/lib/GeoIP.dat" "$TARGET/lib/log.txt"; do
        [ -f "$f" ] && cp -p "$f" "$STAGE/lib/$(basename "$f")"
    done
    if [ -d "$TARGET/lib/plugins" ]; then
        for d in "$TARGET"/lib/plugins/*/; do
            [ -d "$d" ] || continue
            [ -d "$STAGE/lib/plugins/$(basename "$d")" ] || { cp -a "$d" "$STAGE/lib/plugins/"; ok "Kept your extra plugin folder $(basename "$d")"; }
        done
    fi
    rm -rf -- "$STAGE/install"   # the web installer must not stay on a working site
    # what the old tree had that the new one does not (stays in the backup)
    comm -23 <(cd "$TARGET" && find . -type f ! -path './images/maps/*' ! -name 'version-check.txt' ! -name 'worldmap-cache.json' | sort) \
             <(cd "$STAGE"  && find . -type f | sort) > "$BK/files-not-carried-over.txt" || true
    cnt="$(wc -l < "$BK/files-not-carried-over.txt")"
    if [ "$cnt" -gt 0 ]; then warn "$cnt file(s) of the old site are not part of Echelon $NEW_VER and were left behind (listed in $BK/files-not-carried-over.txt; they are still inside files.tar.gz)."; else ok "No stray files in the old site"; fi
fi

# every PHP file must parse with this server's PHP before anything is switched
BAD="$(find "$STAGE" -name '*.php' -print0 | xargs -0 -n1 -P4 sh -c 'php -l "$1" >/dev/null 2>&1 || echo "$1"' _ )"
[ -z "$BAD" ] || die "These files do not run on PHP $PHPV (nothing was changed):
$BAD"
ok "All PHP files check out on PHP $PHPV"

# ownership and permissions
if [ "$MODE" = upgrade ]; then
    GRP="${WEB_GROUP:-$(stat -c %G "$TARGET/inc/config.php" 2>/dev/null || true)}"
else
    GRP="${WEB_GROUP:-$(detect_web_user)}"
fi
{ [ -z "$GRP" ] || [ "$GRP" = root ]; } && GRP="$(detect_web_user)"
id -g "$GRP" >/dev/null 2>&1 || getent group "$GRP" >/dev/null 2>&1 || { warn "Group '$GRP' does not exist here; using the web user's group instead."; GRP="$(detect_web_user)"; }

find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f -exec chmod 644 {} +
chmod 640 "$STAGE/inc/config.php" 2>/dev/null || true
mkdir -p "$STAGE/mapcycle"
chmod 775 "$STAGE/lib" "$STAGE/images/maps" "$STAGE/mapcycle"
find "$STAGE/lib" -maxdepth 1 -type f \( -name 'log.txt' -o -name '*.mmdb' \) -exec chmod 664 {} +
find "$STAGE/images/maps" -maxdepth 1 -type f ! -name '.htaccess' ! -name 'README.txt' -exec chmod 664 {} +
find "$STAGE/mapcycle" -maxdepth 1 -type f ! -name '.htaccess' ! -name 'README.txt' -exec chmod 664 {} +
[ -f "$STAGE/lib/log.txt" ] || { : > "$STAGE/lib/log.txt"; chmod 664 "$STAGE/lib/log.txt"; }
if [ "$MODE" = fresh ]; then
    # the browser installer writes the config: it needs these
    chmod 775 "$STAGE/inc" "$STAGE/install"
    chmod 664 "$STAGE/inc/config.php" "$STAGE"/install/config*.php
fi
if [ "$(id -u)" -eq 0 ]; then
    chown -R "root:$GRP" "$STAGE"
else
    chgrp -R "$GRP" "$STAGE" 2>/dev/null || warn "Could not change the group to '$GRP'; run with sudo if the website cannot read/write its files."
fi
ok "Permissions set (group '$GRP' can write lib/, images/maps/ and mapcycle/)"

# ---- 7. Switch -------------------------------------------------------------------------------
step "Installing"
if [ "$MODE" = upgrade ]; then
    ASIDE="$BK/.previous-site"
    chmod 755 "$STAGE"
    swap_in "$STAGE" "$TARGET" "$ASIDE" || die "Could not switch the folders."
    SWAPPED=1; STAGE=""
    ok "Echelon $NEW_VER is now in $TARGET (the old files wait in the backup folder for the moment)"
else
    if [ -d "$TARGET" ]; then rmdir "$TARGET" || die "$TARGET exists and is not empty."; fi
    chmod 755 "$STAGE"
    mv -- "$STAGE" "$TARGET"; STAGE=""
    ok "Files installed in $TARGET"
fi

# ---- 8. Database ------------------------------------------------------------------------------
step "Updating the database"
if [ "$MODE" = fresh ]; then
    mysql --defaults-extra-file="$CNF" "$DB_NAME" < "$SQL_DIR/echelon-base-schema.sql" || die "Creating the Echelon tables failed."
    ok "Echelon tables created"
fi
mysql --defaults-extra-file="$CNF" "$DB_NAME" < "$SQL_DIR/echelon-upgrade.sql" > /dev/null || die "The database upgrade failed (your files were put back; the database was backed up first and the upgrade only adds things)."
ok "Database is at the Echelon 3 layout"

# ---- 9. Verify --------------------------------------------------------------------------------
step "Checking the result"
FAILS=0
chk() { if [ "$2" = 1 ]; then ok "$1"; else warn "$1  <-- problem"; FAILS=$((FAILS+1)); fi; }
tbl() { [ "$(dbq -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$1'")" = 1 ] && echo 1 || echo 0; }
MISSING=""
for t in ech_blacklist ech_config ech_games ech_groups ech_links ech_logs ech_permissions ech_servers ech_user_keys ech_users; do [ "$(tbl $t)" = 1 ] || MISSING="$MISSING $t"; done
chk "All Echelon tables are present${MISSING:+ (missing:$MISSING)}" "$([ -z "$MISSING" ] && echo 1 || echo 0)"
chk "Map cycle columns exist" "$([ "$(dbq -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ech_servers' AND COLUMN_NAME IN ('mapcyclefile','mapcycleurl')")" = 2 ] && echo 1 || echo 0)"
chk "Permissions are registered" "$([ "$(dbq -e "SELECT COUNT(*) FROM ech_permissions WHERE name IN ('mapconfig','listmgmt','reports','permmute','server_action')")" = 5 ] && echo 1 || echo 0)"
chk "Schema version recorded" "$([ "$(dbq -e "SELECT value FROM ech_config WHERE name = 'schema_version'")" = 3.0 ] && echo 1 || echo 0)"
if [ "$MODE" = upgrade ]; then
    U_NOW="$(dbq -e "SELECT COUNT(*) FROM ech_users")"
    chk "Echelon users are all still there ($U_NOW account(s))" "$([ "$U_NOW" = "$USERS_BEFORE" ] && echo 1 || echo 0)"
fi
chk "Configuration file is in place" "$([ -r "$TARGET/inc/config.php" ] && echo 1 || echo 0)"
if [ "$(id -u)" -eq 0 ] && command -v runuser >/dev/null 2>&1; then
    WUSER="$(detect_web_user)"
    if id "$WUSER" >/dev/null 2>&1; then
        chk "Web server user '$WUSER' can read the config" "$(runuser -u "$WUSER" -- test -r "$TARGET/inc/config.php" 2>/dev/null && echo 1 || echo 0)"
        chk "Web server user '$WUSER' can write lib/, images/maps/ and mapcycle/" "$( (runuser -u "$WUSER" -- test -w "$TARGET/lib" && runuser -u "$WUSER" -- test -w "$TARGET/images/maps" && runuser -u "$WUSER" -- test -w "$TARGET/mapcycle") 2>/dev/null && echo 1 || echo 0)"
    fi
fi
if [ -n "$URL" ] && command -v curl >/dev/null 2>&1; then
    URL="${URL%/}/"
    body="$(curl -ksS -m 20 -L "${URL}login.php" 2>&1 || true)"
    if grep -qiE 'Fatal error|Parse error|Warning:|Uncaught' <<< "$body" || ! grep -qi 'echelon' <<< "$body"; then
        warn "The login page at ${URL}login.php did not load cleanly - check your web server / PHP error log."; FAILS=$((FAILS+1))
    else ok "The login page loads (${URL}login.php)"; fi
fi
[ "$FAILS" -eq 0 ] || die "$FAILS check(s) failed."

# ---- 10. Wrap up ------------------------------------------------------------------------------
DONE=1
if [ "$MODE" = upgrade ]; then
    if [ "$KEEP_OLD" = 1 ]; then mv -- "$ASIDE" "$BK/previous-site"; ok "Old site kept unpacked in $BK/previous-site"
    elif [ "$(basename "$ASIDE")" = ".previous-site" ] && [ -d "$ASIDE" ]; then rm -rf -- "$ASIDE"; fi
fi

step "Done"
if [ "$MODE" = upgrade ]; then
    say "  Echelon is now ${C_BLD}$NEW_VER${C_OFF} in $TARGET."
    say "  Log in as usual. Your users, settings, servers and map pictures were kept."
    say ""
    say "  ${C_BLD}Backup:${C_OFF}  $BK"
    say "  ${C_BLD}To go back:${C_OFF}  sudo $BK/revert.sh"
    say "  (Keep the backup folder until you are happy with Echelon 3. It contains the database login - keep it private.)"
else
    say "  The files and database tables are in place. One step is left - the browser installer:"
    say "    open  http://YOUR-SERVER/${BASE}/install/index.php  and fill in your e-mail and the database details."
    say "    It creates the 'admin' login. Afterwards DELETE the folder:  sudo rm -r $TARGET/install"
fi
