# Installing or upgrading Echelon 3 on Linux

`install.sh` does everything for an **existing** Echelon (upgrade) and for a **new** one. For Windows see `INSTALL-WINDOWS.md`.

## What the installer does to an existing Echelon

1. **Checks the server** — PHP version/extensions, MySQL client tools, permissions.
2. **Finds your Echelon** (or you give it with `--target`) and reads the database login from its `inc/config.php`.
3. **Backs everything up first**, into `/var/backups/echelon/echelon-backup-<date>-from-<version>/` (mode 700, root only):
   * `files.tar.gz` — the whole old Echelon folder, with a checksum
   * `echelon-db.sql.gz` — the Echelon database (`ech_*` tables), exact bytes
   * `b3-<id>-mapconfig.sql.gz` — the map table of each game (B3) database
   * `revert.sh` — a copy of the revert script, ready to run
4. **Builds Echelon 3 in a private folder** next to the old one, keeps your `inc/config.php` (database login and salts — never changed), your map pictures (`images/maps`), your mapcycle links (`mapcycle`), GeoLite2/GeoIP data, log file and any extra plugin folders, and checks that every PHP file runs on this server's PHP.
5. **Switches** the folders (a split second) and **upgrades the database** with `sql/echelon-upgrade.sql` (adds columns/permissions, deletes nothing).
6. **Checks the result.** If anything fails after the switch, the old folder is put back automatically.

The old browser installer (`install/`) is not installed on an upgraded site. Files that exist in the old site but not in Echelon 3 are listed in `files-not-carried-over.txt` inside the backup; anything you changed in program files (for example your own `.htaccess` lines) is only in the backup, so re-apply it if you need it.

## Upgrade

```bash
cd Echelon-3/installer
sudo ./install.sh --check      # look only, changes nothing (optional)
sudo ./install.sh              # asks to confirm, then does it
```

Options: `--target DIR`, `--backup-dir DIR`, `--web-group GROUP`, `--url http://yourhost/echelon/` (adds a web check at the end), `--keep-old` (also leave the old site unpacked in the backup), `-y/--yes` (no questions).

When it finishes it prints the backup folder. **Keep it until you are happy with Echelon 3** — it contains the database login, so keep it private.

## New installation

1. Create the database and a user (as the MySQL admin):
   ```sql
   CREATE DATABASE echelon CHARACTER SET utf8;
   CREATE USER 'echelon'@'localhost' IDENTIFIED BY 'CHOOSE-A-PASSWORD';
   GRANT ALL PRIVILEGES ON echelon.* TO 'echelon'@'localhost';
   FLUSH PRIVILEGES;
   ```
2. `sudo ./install.sh --target /var/www/html/echelon` — it asks for the database details, creates the tables and puts the files in place.
3. Open `http://your-server/echelon/install/index.php`, enter your e-mail and the same database details. It creates the `admin` login (shown on screen if the e-mail cannot be sent).
4. **Delete the installer folder:** `sudo rm -r /var/www/html/echelon/install`

## Going back

```bash
sudo /var/backups/echelon/echelon-backup-…/revert.sh       # or: sudo ./revert.sh to pick a backup
```

* Puts the old files back exactly as they were (owners and permissions included).
* Asks whether to restore the database too. The upgrade only **adds** columns and rows, so the old Echelon runs on the upgraded database; restore it only if you want the exact state of backup time (this loses anything changed since). Use `--restore-db` / `--keep-db` to answer in advance.
* Before changing anything it saves the *current* site (and database, if restoring) into the same backup folder as `before-revert-*`, so a revert can itself be undone.

## Web server notes

**Apache**: the shipped `.htaccess` files protect `inc/`, `classes/`, logs and databases. Needs `AllowOverride All` (or at least `Limit AuthConfig FileInfo`) for the Echelon folder, and `mod_headers`/`mod_expires` are optional.

**nginx** ignores `.htaccess`. Add this inside your `server { }` block (adjust `/echelon/`):

```nginx
location ~ ^/echelon/(inc|classes|cron|mapcycle)/               { deny all; }
location ~ ^/echelon/lib/maxmind-db/                    { deny all; }
location ~ ^/echelon/lib/.*\.(txt|log|dat|json|mmdb|part|lock)$ { deny all; }
location ~ ^/echelon/images/.*\.(php|phtml|phar)$       { deny all; }
location ~ \.(log|sql|bak|inc|sh|ini)$                  { deny all; }
location ~ /\.ht                                        { deny all; }
```

**Permissions** the installer sets: code `root:<web group>` 644/755, `inc/config.php` 640, and the web group may write `lib/`, `images/maps/` and `mapcycle/` (needed for logs, the GeoLite2 download, map pictures and the mapcycle links).

**Mapcycle files** are chosen in the site (Settings > Maps & World Map > Mapcycle files). The web server user also needs write access to the game server's real mapcycle files, e.g. `chown urt:www-data mapcycle_ctf.txt && chmod 664 mapcycle_ctf.txt`. You can also make a link yourself: `ln -s /home/urt/.q3a/q3ut4/mapcycle_ctf.txt /var/www/html/echelon/mapcycle/ctf.txt`.

## Keeping the GeoLite2 database fresh (optional)

Once the MaxMind details are saved (Settings > Maps & World Map), the database can update itself in two ways:

* **From the World Map** (switch on *Keep the database up to date automatically*): when an administrator opens the map and the database is more than 3 days old, it is refreshed in the background.
* **By schedule**, no browser needed. As the web server's user, twice a week (MaxMind publishes Tuesday and Friday):
  ```
  17 4 * * 3,6  www-data  php /var/www/html/echelon/cron/update-geolite2.php
  ```
  (`--force` always downloads. The exact command for your install is shown on the settings page.)

## If something goes wrong

* `install.log` and `revert.log` in the backup folder record every step.
* "Cannot connect to database": check the details in `inc/config.php`; the database user needs SELECT + LOCK TABLES for the backup and ALTER/INSERT/UPDATE for the upgrade.
* "These files do not run on PHP …": the command-line PHP is older than 8.1 — install PHP 8.1+ (nothing was changed).
* Blank page after install: look in `lib/log.txt` and your web server's PHP error log.

## Doing it by hand instead

Everything the script does is plain files and SQL; `INSTALL-WINDOWS.md` lists the manual steps (they apply to Linux too — use `mysqldump`, `tar`/`cp` and `mysql < installer/sql/echelon-upgrade.sql`).
