# Echelon 3 - what is new

*Echelon 3 upgrades by Aciddrop ([@Aciddrop75](https://github.com/Aciddrop75)), built on Echelon 2 by miltann, dkman123 and contributors.*

**Requires:** PHP 8.1 or newer (tested on 8.3) with `mysqli`, `gd` and `zip` (map pictures), `fileinfo`, and `curl` or `allow_url_fopen` (GeoLite2 download); MySQL 5.7+/MariaDB 10.3+.

## Installing
Use the installer: `installer/install.sh` on Linux (backs up files and database, installs cleanly, keeps your settings, rolls back if it fails; `installer/revert.sh` goes back), or follow `installer/INSTALL-WINDOWS.md` by hand. Your old `inc/config.php` keeps working as it is. Database changes live in one script that is safe to run again: `installer/sql/echelon-upgrade.sql` (it replaces the old `echelon-2.3.x-*.sql` files, which are kept in `installer/sql/legacy/`; those hard-coded the database name `echelon`). The B3 `mapconfig` table is used exactly as before.

Optional constants for `inc/config.php` are listed in `inc/config_sample.php` (`ECH_DEBUG`, `ECH_LOG`, `B3_DB_CHARSET`, `MAP_PK3_DIR` as a fallback, `ECH_UPDATE_URL`).

## Version and update notice
The version number now lives in the code (`ECH_VERSION` in `inc/defaults.php`), so an upgrade can never leave an old number in your config. The "not the latest version" notice is off unless you set `ECH_UPDATE_URL` to a text file holding the newest version (the old check pointed at the Echelon 2 repository and always complained).

## Installer fixes
The browser installer's form never submitted (it looked for `t=install` in POST data but sends it in the address); fixed. It also refuses to run again on a site that is already installed, and the upgrade installer does not leave the `install/` folder behind. An IIS `web.config` is included next to the Apache `.htaccess` files.

## What changed
- **PHP 8.1+**: mysqli exceptions handled, undefined variable/key warnings fixed, removed functions replaced, null-to-string deprecations fixed. Errors go to `lib/log.txt` (rotated at 1 MB); set `ECH_DEBUG` to true to see them on screen.
- **New interface**: dark sidebar navigation, top bar with client search, dashboard home page, consistent cards and tables, responsive down to phones. Bootstrap, jQuery and Font Awesome are bundled (`vendor/`), so no CDN is needed.
- **Map Config**: a tile board. Click a picture to edit that map; `+` on a tile adds it to the mapcycle builder at the bottom. Sort by name, game type (grouped), or how long since the map was in the cycle. Filter by one or more game types (match any/all) and search by name or start message. Filters are kept in the page URL.
- **Map editor**: general info, game type picker, rules, history, picture upload by drag and drop, duplicate and delete.
- **Speed and memory**: no per-query file logging, page counts use `COUNT(*)` instead of loading every row, the update check is cached for 12 hours with a 3 second timeout, the world map no longer loads up to 25,000 IPs, map pictures are resized on upload (with a memory guard for huge images) and loaded lazily.
- **Security**: search queries use bound parameters, output is escaped (player names were an XSS risk), tokens use a secure random source, map and mapcycle actions require a CSRF token, uploads are validated by content, `.htaccess` blocks logs and internal folders.

## Every map in your game files is listed
Map Config used to show only the maps already saved in its table, so a game folder with 242 maps could show 105. Echelon now reads the map files (`.pk3`) in your game files folders and lists every map it finds: maps not in Map Config yet are marked **NOT ADDED** (click one to add it with its name and game types filled in, or use **Add them to Map Config** to add all of them at once, with their pictures). The **Show** menu limits the board to maps in Map Config or maps only found in the game files.
Game types are read from the `.arena` files (`scripts/*.arena` inside the `.pk3` files, and loose in `<game folder>/scripts`; later files override earlier ones, as in the game). If a map has no `.arena` entry, a readme line such as `Gametypes: CTF, TS` inside a one-map `.pk3` is used. A map with neither is **still listed**, under "No game type set". **Fill in game types** sets the types of Map Config maps that have none, and the map editor has a **Read from .arena file** button. Results are cached in `lib/map-index.json` (rebuilt when a `.pk3` changes).

## Download missing maps
Maps in Map Config that have no `.pk3` in your game files folder are marked **NO FILE**, and a banner offers **Download missing maps**; each map's page has a **Download this map** button. Echelon fetches `<map>.pk3` from the download sites you enter under **Settings > Maps & World Map > Map downloads** (an address with `{map}` in it, such as `https://fastdl.example.com/q3ut4/{map}.pk3`, one per line, tried in order) and saves it in the game files folder, then takes the map's picture from it if it has none. Safeguards: only maps listed in Map Config; only http(s); addresses that point at the web server itself or a private network are refused unless you tick *Allow addresses on my own network*; redirects are checked step by step; a size limit (default 200 MB); the file must be a zip that really contains `maps/<name>.bsp`; an existing file is never overwritten. The web server user needs write access to the game files folder and PHP needs `curl` and `zip`. Which maps count as missing is decided from the `.pk3` files in your game files folders (the result is cached in `lib/map-index.json`), so list the folder with the game's own maps as well as the one for downloaded maps.

## Gear calculator
The map editor's Gear field now has a calculator: tick what players may use (sidearms, weapons, grenades, items) and the `g_gear` value is filled in; typing letters ticks the boxes; presets for *Allow everything*, *Pistols only* and *Snipers only*. `g_gear` holds the letters of what is **not** allowed, and `0` means everything is allowed. The letters are the Urban Terror 4.2 set; newer 4.3 items (FR-F1, P90, Magnum, Benelli) are not in the calculator yet, but letters it does not know are kept as typed.

## Mapcycle files: browse, link, write
Map Config now reads and writes the real mapcycle file of your game server. Under **Settings > Maps & World Map > Mapcycle files**, pick the file for each game type (or one for *All game types*) with a folder browser that looks at the server's own disk. Echelon makes a symbolic link to it in its `mapcycle/` folder (`mapcycle/ctf.txt -> /home/urt/.q3a/q3ut4/mapcycle_ctf.txt`) and reads and writes through the link, so the real file is rewritten in place and stays where the game server expects it. In Map Config, the mapcycle drawer has a **File** selector; *Load current* and *Write file* use the chosen one. A file with Windows (CRLF) line endings keeps them, the previous text is kept in `mapcycle/<type>.previous`, and a write is read back to make sure it landed. The link is always required: if it cannot be made, the page says why and how to fix it (folder permissions, `disable_functions`, or the Windows symbolic-link right) instead of quietly saving a path. Locations saved by an earlier build keep working until you choose the file again. Only `.txt` and `.cfg` files outside Echelon's own folder can be chosen, and only users with the *manage_settings* permission can choose them. The web server user needs write access to the real file; the page tells you when it does not. The upgrade installer keeps your `mapcycle/` links. This replaces the single Mapcycle File of Server Settings for writing (that file is still used for *All game types* until you choose another).


## Custom game types
Does your server run a mod or a game type Echelon does not list (Instagib CTF, a Gun Game with your own rules)? Under **Settings > Maps & World Map > Mapcycle files**, use **Add your own game type**: give it a name (the short code is made from it and can be changed before you add it). The new game type gets its own mapcycle row, so you can choose its real mapcycle file like any other (the link is `mapcycle/<code>.txt`), and it appears in Map Config as a game type: in the filter, on the tiles, and as a tick box in the map editor. Rename it with the pencil, or delete it with the bin: deleting removes only its link in `mapcycle/`, never your real mapcycle file or the game types already ticked on your maps. Codes are lower-case letters, digits and underscores, start with a letter and are at most 12 long; built-in codes (`ctf`, `ts`, ...) and the reserved names `default`, `none`, `all`, `any`, `other` and `custom` cannot be used. Up to 24 custom game types are kept (in the `mc_custom_types` setting). Only users with the *manage_settings* permission can add, rename or delete them.

## Original logo
The original Echelon globe logo (`images/logo.png`) and `favicon.ico` are back in the sidebar, on the sign-in page and in the browser tab; the replacement logo is gone.

## Light, Dark and System theme
A three-way switch (sun / moon / monitor) sits in the top bar on every page, including the login and public ban list pages. **System** follows the device and changes live when the device switches between light and dark; **Light** and **Dark** pin the choice. The choice is remembered per browser (`ech-theme` in localStorage) and applied before the page paints, so there is no flash. All colours are CSS variables (`css/echelon.css`), so a dark palette is one block (`html[data-theme="dark"]`); the Bootstrap pieces, client details page and the Leaflet map controls are covered too. The sidebar is dark in both themes. Map tile styles are chosen separately under Maps & World Map.

## World Map: styles and automatic updates
* **Map styles**: Standard, Humanitarian, Topographic and Satellite (all with their required credits), plus a **Custom** tile server address (MapTiler, Thunderforest, Stadia, your own...). Administrators choose the default under Settings > Maps & World Map; visitors can switch on the map itself (remembered per browser) unless that is switched off.
* **Database updates**: *Keep the database up to date automatically* refreshes the GeoLite2 database in the background when an administrator opens the World Map and it is more than 3 days old (a failed try waits 12 hours). Or run `cron/update-geolite2.php` from cron / Task Scheduler (`--force` always downloads). Only one update runs at a time.

## Map pictures
Every map file (`.pk3`) contains the picture the game shows on its map screen (`levelshots/<mapname>.jpg` or `.tga`). Echelon takes these automatically:
- **Game files folder**: enter it in **Settings > Maps & World Map** (or in the box that appears on the Map Config page when no folder is found). Echelon suggests likely folders, checks that the path exists and is readable, and "Save and get map pictures" extracts the pictures at once. Order used: the folder you saved, then `MAP_PK3_DIR` in `inc/config.php`, then the folder of the server's Mapcycle File. The folder must be on the same machine as the website and readable by the web server user.
- **Extract from map files** (button on the Map Config page and on each map's page) looks through the `.pk3` files in that folder. PHP needs the `zip` and `gd` extensions.
- **From a .pk3 file...** lets you upload a map file from your computer instead (limited by your PHP `upload_max_filesize` / `post_max_size`).
- A map you add is checked against the map files straight away.
- You can still upload any picture by hand, or copy `ut4_turnpike.jpg` into `images/maps/`. Maps without a picture show a coloured name tile.

## World map and GeoLite2
The World Map now uses Leaflet with OpenStreetMap tiles (bundled in `vendor/leaflet/`; the visitor's browser needs internet access to `tile.openstreetmap.org`, and the OpenStreetMap attribution is shown as required). Choose the location source in **Settings > Maps & World Map**:
- **MaxMind GeoLite2 City** (recommended; city level). Steps shown on the page: 1) create a free account at maxmind.com/en/geolite2/signup, 2) in *Manage license keys* generate a key (answer *No* to geoipupdate) and note your Account ID, 3) enter both and press *Update database*. Echelon downloads and installs `lib/GeoLite2-City.mmdb` (about 60 MB; `lib/` must be writable), keeps your credentials in the settings table (the key is shown masked) and reminds you on the World Map when the database is over a month old. Use *Update database* again whenever you like. The reader is pure PHP (`lib/maxmind-db/`), so no extension is needed. The GeoLite2 licence requires the attribution line that the map shows.
- **Old GeoIP.dat**: country level only, used if you already have `lib/GeoIP.dat` and do not want GeoLite2.
Results are cached for 10 minutes (`lib/worldmap-cache.json`). The download could not be tested against MaxMind's real servers here, only against a mock, so check the first download shows a green message.

## Mapcycle
Set the **Mapcycle File** in Settings > Server (the web server user must be able to write to it). "Load current" reads that file; if it is not readable it falls back to the Mapcycle URL.
