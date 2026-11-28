#!/usr/bin/env bash
# ============================================================================
#  Echelon revert (Linux): puts back the files - and, if you want, the database -
#  from a backup the Echelon 3 installer made.
#
#    sudo ./revert.sh                    list the backups found and pick one
#    sudo ./revert.sh /path/to/backup    use that backup folder
#    sudo /var/backups/echelon/<backup>/revert.sh     (the copy inside each backup)
#
#  Before it changes anything it saves the CURRENT site too, so a revert can be undone.
# ============================================================================
set -Eeuo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$HERE/lib/common.sh"

DB_MODE=ask   # ask | yes | no
BK=""; BACKUP_ROOT=""
usage() {
cat <<USAGE
Usage: sudo ./revert.sh [options] [backup-folder]

  --restore-db     Also put the database back as it was at backup time (loses changes made since)
  --keep-db        Put the files back only; leave the database as it is
  --backup-dir D   Where to look for backups (default: /var/backups/echelon, ~/echelon-backups)
  -y, --yes        Do not ask; with no --restore-db this keeps the database as it is
  -h, --help       This text

The database only gains columns/rows in the upgrade, so putting the files back is normally enough;
restore the database too if you want the exact state of backup time.
USAGE
}
while [ $# -gt 0 ]; do
    case "$1" in
        --restore-db) DB_MODE=yes; shift ;;
        --keep-db)    DB_MODE=no; shift ;;
        --backup-dir) BACKUP_ROOT="${2:?}"; shift 2 ;;
        -y|--yes)     ASSUME_YES=1; shift ;;
        -h|--help)    usage; exit 0 ;;
        -*) usage; die "Unknown option: $1" ;;
        *)  BK="${1%/}"; shift ;;
    esac
done

info_get() { awk -F= -v k="$1" '$1 == k { sub(/^[^=]*=/, ""); print; exit }' "$BK/backup.info"; }

for c in php tar gzip mysql mysqldump sha256sum mktemp awk; do need_cmd "$c"; done

# ---- pick the backup ---------------------------------------------------------------------------
if [ -z "$BK" ]; then
    if [ -f "$HERE/files.tar.gz" ] && [ -f "$HERE/backup.info" ]; then
        BK="$HERE"
    else
        LIST=()
        for root in ${BACKUP_ROOT:+"$BACKUP_ROOT"} /var/backups/echelon "${HOME:-/root}/echelon-backups"; do
            [ -d "$root" ] || continue
            for d in "$root"/echelon-backup-*; do [ -f "$d/files.tar.gz" ] && [ -f "$d/backup.info" ] && LIST+=("$d"); done
        done
        [ "${#LIST[@]}" -gt 0 ] || die "No Echelon backups found. Give the backup folder as an argument."
        say "Backups found:"; i=1
        for d in "${LIST[@]}"; do say "  $i) $d"; i=$((i+1)); done
        ask pick "Which one (number)" "${#LIST[@]}"
        [[ "$pick" =~ ^[0-9]+$ ]] && [ "$pick" -ge 1 ] && [ "$pick" -le "${#LIST[@]}" ] || die "Not a valid choice."
        BK="${LIST[$((pick-1))]}"
    fi
fi
[ -f "$BK/files.tar.gz" ] && [ -f "$BK/backup.info" ] || die "$BK is not an Echelon backup folder (files.tar.gz / backup.info missing)."
LOGFILE="$BK/revert.log"; touch "$LOGFILE"; chmod 600 "$LOGFILE"

TARGET="$(info_get TARGET)"; PARENT="$(info_get TARGET_PARENT)"; BASE="$(info_get TARGET_BASENAME)"
DB_NAME="$(info_get DB_NAME)"
[ -n "$TARGET" ] && [ -n "$PARENT" ] && [ -n "$BASE" ] || die "backup.info is incomplete."
[ "$TARGET" != "/" ] && [ "$PARENT/$BASE" = "$TARGET" ] || die "backup.info looks wrong; refusing to continue."

step "This backup"
say "  Made:         $(info_get CREATED)"
say "  Echelon was:  $(info_get FROM_VERSION)   (then upgraded to $(info_get TO_VERSION))"
say "  Folder:       $TARGET"
say "  Database:     $DB_NAME"
[ -w "$PARENT" ] || die "No permission to change $PARENT. Run with sudo."

step "Checking the backup"
if [ -f "$BK/files.tar.gz.sha256" ]; then
    [ "$(sha_of "$BK/files.tar.gz")" = "$(tr -d '[:space:]' < "$BK/files.tar.gz.sha256")" ] || die "files.tar.gz does not match its checksum - the backup is damaged."
    ok "Checksum matches"
fi
tar -tzf "$BK/files.tar.gz" > /dev/null || die "files.tar.gz cannot be read."
ok "Archive is readable"

# the OLD config is inside the archive: use it for the database login
OLDCFG="$(mktemp "${TMPDIR:-/tmp}/echcfg.XXXXXX")"; chmod 600 "$OLDCFG"; CLEAN_FILES+=("$OLDCFG")
tar -xzf "$BK/files.tar.gz" -O "$BASE/inc/config.php" > "$OLDCFG" 2>/dev/null || die "inc/config.php is not in the backup archive."
read_config "$OLDCFG" || die "Could not read the database login from the backed-up config."

DO_DB=0
case "$DB_MODE" in
    yes) DO_DB=1 ;;
    no)  DO_DB=0 ;;
    ask)
        if [ -f "$BK/echelon-db.sql.gz" ]; then
            say ""; say "  The database can also be put back to how it was at $(info_get CREATED)."
            say "  That throws away anything changed in Echelon since (new users, notes, settings...)."
            confirm "Restore the database too?" n && DO_DB=1
        fi ;;
esac
[ "$DO_DB" = 0 ] || [ -f "$BK/echelon-db.sql.gz" ] || die "This backup has no database dump."
confirm "Put the old Echelon back in $TARGET now?" y || die "Cancelled. Nothing was changed."

# ---- safety copy of what is there now ----------------------------------------------------------
TS="$(date +%Y%m%d-%H%M%S)"
mk_cnf "$CFG_HOST" "$CFG_USER" "$CFG_PASS"; CNF="$MK_CNF"
step "Saving the current state first"
if [ -d "$TARGET" ]; then
    tar -C "$PARENT" -czpf "$BK/before-revert-$TS-files.tar.gz" "$BASE" && chmod 600 "$BK/before-revert-$TS-files.tar.gz" && ok "Current files saved as before-revert-$TS-files.tar.gz"
fi
if [ "$DO_DB" = 1 ]; then
    dump_db "$CNF" "$DB_NAME" "$BK/before-revert-$TS-db.sql.gz" || die "Could not save the current database - nothing was changed."
    ok "Current database saved as before-revert-$TS-db.sql.gz"
fi

# ---- files -------------------------------------------------------------------------------------
step "Restoring files"
STAGE="$(mktemp -d "$PARENT/.echelon-stage.XXXXXX")"
trap 'rc=$?; [ -n "${STAGE:-}" ] && [ -d "$STAGE" ] && [[ "$(basename "$STAGE")" == .echelon-stage.* ]] && rm -rf -- "$STAGE"; cleanup; exit $rc' EXIT
tar -C "$STAGE" -xzpf "$BK/files.tar.gz" || die "Could not unpack the backup."
[ -d "$STAGE/$BASE" ] || die "The archive does not hold the expected folder."
chmod 755 "$STAGE/$BASE"
ASIDE="$BK/.replaced-by-revert-$TS"
if [ -e "$TARGET" ]; then mv -- "$TARGET" "$ASIDE" || die "Could not move the current site away."; fi
if ! mv -- "$STAGE/$BASE" "$TARGET"; then
    [ -e "$ASIDE" ] && mv -- "$ASIDE" "$TARGET"
    die "Could not put the old files in place; the current site was left as it was."
fi
ok "Old Echelon files are back in $TARGET"

# ---- database ----------------------------------------------------------------------------------
if [ "$DO_DB" = 1 ]; then
    step "Restoring the database"
    if gzip -dc "$BK/echelon-db.sql.gz" | mysql --defaults-extra-file="$CNF" "$DB_NAME"; then
        ok "Database '$DB_NAME' restored"
    else
        warn "Restoring the database failed. The files are back; the database dump is $BK/echelon-db.sql.gz and the state before this attempt is before-revert-$TS-db.sql.gz."
        exit 1
    fi
    for f in "$BK"/b3-*-mapconfig.sql.gz; do
        [ -f "$f" ] || continue
        gid="$(basename "$f" | sed -E 's/^b3-([0-9]+)-mapconfig.*/\1/')"
        row="$(mysql --defaults-extra-file="$CNF" --batch --raw --skip-column-names "$DB_NAME" -e "SELECT db_host, db_user, IFNULL(db_pw,''), db_name FROM ech_games WHERE id = $gid" 2>/dev/null || true)"
        [ -n "$row" ] || continue
        IFS=$'\t' read -r gh gu gp gd <<< "$row"
        mk_cnf "$gh" "$gu" "$gp"
        if gzip -dc "$f" | mysql --defaults-extra-file="$MK_CNF" "$gd" 2>/dev/null; then ok "Map table of game $gid restored"; else warn "Could not restore the map table of game $gid ($gd)."; fi
    done
fi

# the swap is done; the replaced (Echelon 3) site was saved as before-revert-*-files.tar.gz
if [ -d "$ASIDE" ] && [[ "$(basename "$ASIDE")" == .replaced-by-revert-* ]]; then rm -rf -- "$ASIDE"; fi

step "Done"
say "  The old Echelon ($(info_get FROM_VERSION)) is back in $TARGET."
[ "$DO_DB" = 1 ] && say "  The database was restored as well." || say "  The database was left as it is (the Echelon 3 upgrade only added columns and rows, so the old version runs on it)."
say "  If you revert by mistake, the files that were replaced are in $BK/before-revert-$TS-files.tar.gz"
