# Installing or upgrading Echelon 3 on Windows (manual)

On Windows you do the file copying and the database work yourself. Nothing here needs special tools: Explorer, a Command Prompt, and either phpMyAdmin or the `mysql.exe` / `mysqldump.exe` that come with XAMPP, WAMP or MySQL/MariaDB.

> **The paths below are examples.** XAMPP keeps websites in `C:\xampp\htdocs` and MySQL tools in `C:\xampp\mysql\bin`. WAMP uses `C:\wamp64\www` and `C:\wamp64\bin\mysql\...\bin`. IIS usually uses `C:\inetpub\wwwroot`. Use your own folders everywhere.

Pick your case:

* **A. You already run Echelon** and want Echelon 3 in its place → [Part A](#part-a--upgrade-an-existing-echelon) (backup first, easy to go back)
* **B. This is a brand-new install** → [Part B](#part-b--new-installation)

Unzip the Echelon 3 package somewhere first, for example `C:\Downloads\Echelon-3\`. It contains the website (`echelon\`), the SQL scripts (`installer\sql\`) and this guide.

---

## Before you start (both cases)

**1. PHP 8.1 or newer.** In a Command Prompt: `C:\xampp\php\php.exe -v`. If it says 8.0 or lower, upgrade XAMPP/WAMP/PHP first (Echelon 3 will not run on older PHP).

**2. PHP extensions.** Open `php.ini` (XAMPP: `C:\xampp\php\php.ini`), make sure these lines have **no `;` in front**, save, then restart Apache (or IIS / the PHP FastCGI pool):

```
extension=mysqli
extension=gd
extension=zip
extension=fileinfo
extension=mbstring
extension=curl
extension=openssl
```

Check with `C:\xampp\php\php.exe -m` (the list must include them). *The website's PHP can differ from the command-line PHP; if unsure, drop a file `info.php` containing `<?php phpinfo();` in the website folder, open it in a browser, and **delete it again**.*

**3. Optional – GeoLite2 download from Settings.** PHP on Windows needs a certificate bundle to talk to https sites. Download `cacert.pem` from curl.se/docs/caextract.html, save it as `C:\php\cacert.pem` (any place), and in `php.ini` set:

```
curl.cainfo = "C:\php\cacert.pem"
openssl.cafile = "C:\php\cacert.pem"
```

Restart the web server afterwards.

---

## Part A – Upgrade an existing Echelon

Plan for 20–30 minutes. Nothing is deleted until your backup is checked.

### Step 1 – Write down what you have

Open the old `inc\config.php` in Notepad (do not change it) and note:

* `DBL_HOSTNAME`, `DBL_USERNAME`, `DBL_PASSWORD`, `DBL_DB` – the Echelon database login
* `$path` – the web address path, e.g. `/echelon/`

Your B3 game databases are listed in Echelon under *Settings → Games*; Echelon 3 does not change them.

### Step 2 – Make a backup folder

```bat
mkdir C:\EchelonBackups\2026-11-26
```

(Use today's date. Keep this folder **outside** the web server's folder, because it will hold your database password.)

### Step 3 – Back up the files

```bat
robocopy "C:\xampp\htdocs\echelon" "C:\EchelonBackups\2026-11-26\echelon-files" /E /COPY:DAT
```

robocopy prints a summary; "Failed 0" in the Dirs/Files lines means all is well (exit codes 0–7 are success). **Check:** `dir "C:\EchelonBackups\2026-11-26\echelon-files\inc\config.php"` must show the file.

### Step 4 – Back up the Echelon database

Choose **one** way (and use the same way if you ever restore):

**4a. mysqldump (Command Prompt, not PowerShell):**

```bat
"C:\xampp\mysql\bin\mysqldump.exe" -u echelon -p --single-transaction --default-character-set=binary --hex-blob --result-file="C:\EchelonBackups\2026-11-26\echelon-db.sql" echelon
```

Replace `echelon` after `-u` with your `DBL_USERNAME` and the last word with your `DBL_DB`. It asks for the password (`DBL_PASSWORD`). Keep `--result-file`: using `>` in PowerShell saves the file in the wrong encoding.

**4b. phpMyAdmin:** open it, click your Echelon database, **Export → Custom**, tick **"Add DROP TABLE / VIEW / PROCEDURE / FUNCTION / EVENT / TRIGGER statement"**, format SQL, **Go**, and save the file into `C:\EchelonBackups\2026-11-26\echelon-db.sql`.

**Check:** the file is more than a few KB and its last lines say `Dump completed` (mysqldump) or end with `COMMIT;` (phpMyAdmin).

*Optional, tiny:* each game's map table can be saved the same way (4a with your B3 database name followed by `mapconfig`, e.g. `... b3_urt mapconfig`), but Echelon 3 does not change it.

### Step 5 – Replace the program files

1. In `C:\xampp\htdocs`, **delete the `echelon` folder** (your backup from Step 3 has it).
2. Copy the package's `echelon` folder into `C:\xampp\htdocs` so you have `C:\xampp\htdocs\echelon\index.php` again.
3. **Put your own data back** from the backup (Command Prompt):

```bat
copy "C:\EchelonBackups\2026-11-26\echelon-files\inc\config.php" "C:\xampp\htdocs\echelon\inc\config.php"
robocopy "C:\EchelonBackups\2026-11-26\echelon-files\images\maps" "C:\xampp\htdocs\echelon\images\maps" /XF .htaccess README.txt
copy "C:\EchelonBackups\2026-11-26\echelon-files\lib\log.txt" "C:\xampp\htdocs\echelon\lib\log.txt"
copy "C:\EchelonBackups\2026-11-26\echelon-files\lib\GeoIP.dat" "C:\xampp\htdocs\echelon\lib\GeoIP.dat"
copy "C:\EchelonBackups\2026-11-26\echelon-files\lib\*.mmdb" "C:\xampp\htdocs\echelon\lib\"
```

   **Mapcycle files:** Echelon 3 keeps a link to each real mapcycle file in its `mapcycle` folder. Windows only lets PHP make links when the web server runs as administrator or Windows Developer Mode is on; if it cannot, choosing a file shows an error instead, so switch on Developer Mode (Settings > System > For developers) or run the web server service as an administrator first. After upgrading open *Settings > Maps & World Map > Mapcycle files* and check each game type still shows its file; use **Browse...** to choose it again if a row says "Not set". The web server's user also needs *Modify* permission on the real mapcycle files.

(The last three lines only matter if you have those files; "file not found" is fine otherwise. If you added your own plugin folders under `lib\plugins\`, copy those back too.)

4. **Delete the folder** `C:\xampp\htdocs\echelon\install` (the browser installer must not stay on a working site).

Your old `config.php` keeps working as it is. Do **not** use the new `inc\config_sample.php` for an upgrade.

### Step 6 – Upgrade the database

The script `installer\sql\echelon-upgrade.sql` adds what Echelon 3 needs. It never deletes anything and is safe to run twice.

**6a. phpMyAdmin:** click your Echelon database in the left list → **Import** → choose `echelon-upgrade.sql` → **Go**. You should see a green success message (the last result reads *Echelon 3 database upgrade finished*).

**6b. Command Prompt** (use `cmd`, `<` does not work in PowerShell):

```bat
"C:\xampp\mysql\bin\mysql.exe" -u echelon -p echelon < "C:\Downloads\Echelon-3\installer\sql\echelon-upgrade.sql"
```

(Last `echelon` = your database name.) Or from inside the `mysql` client: `USE echelon;` then `source C:/Downloads/Echelon-3/installer/sql/echelon-upgrade.sql`.

### Step 7 – Folder permissions

* **XAMPP / WAMP (Apache):** usually nothing to do.
* **IIS:** the website's identity must be able to write `lib` and `images\maps` (logs, GeoLite2, map pictures). In an *administrator* Command Prompt:

```bat
icacls "C:\inetpub\wwwroot\echelon\lib" /grant "IIS_IUSRS:(OI)(CI)M"
icacls "C:\inetpub\wwwroot\echelon\images\maps" /grant "IIS_IUSRS:(OI)(CI)M"
```

(If your site's app pool runs under its own identity, grant `IIS AppPool\YourPoolName` instead.) IIS uses the shipped `web.config` in place of `.htaccess`.

### Step 8 – Check that it works

1. Open `http://localhost/echelon/` and log in with your usual account.
2. The footer says **Echelon v3.0**.
3. Open **Map Config** (you may see "Game files folder" – enter the folder where your game's `.pk3` files live, e.g. `C:\Games\UrbanTerror43\q3ut4`, and press *Save and get map pictures*; it must be on the same computer as the website).
4. Open **World Player Map**; to get city-level locations open **Settings → Maps & World Map** and follow the three MaxMind steps on that page.

5. **Optional – automatic GeoLite2 updates.** After saving your MaxMind details, tick *Keep the database up to date automatically* (the map refreshes it in the background when an administrator opens it and it is over 3 days old), or schedule it: open **Task Scheduler → Create Basic Task**, run twice a week (Wednesday and Saturday), action *Start a program*, program `C:\xampp\php\php.exe`, arguments `C:\xampp\htdocs\echelon\cron\update-geolite2.php`.
6. **Map style:** on the same settings page, pick the look of the World Map (Standard, Humanitarian, Topographic, Satellite or your own tile server).

If a page is blank, look at `echelon\lib\log.txt` and the web server's PHP error log; the most common causes are a missing PHP extension (see *Before you start*) or the wrong PHP version.

### Going back to the old Echelon

You can do this at any time, even after weeks.

1. *(Safety)* Copy the current `C:\xampp\htdocs\echelon` to `C:\EchelonBackups\2026-11-26\echelon3-files` so this step can be undone as well.
2. **Delete** `C:\xampp\htdocs\echelon`.
3. Put the old one back:

```bat
robocopy "C:\EchelonBackups\2026-11-26\echelon-files" "C:\xampp\htdocs\echelon" /E /COPY:DAT
```

4. **Database – optional.** Echelon 3's upgrade only *added* columns and rows, so the old Echelon works with the upgraded database and you can skip this. To get the exact old state (this **loses anything changed since the backup**), restore the file from Step 4 with the same tool you made it with:
   * mysqldump file: `"C:\xampp\mysql\bin\mysql.exe" -u echelon -p echelon < "C:\EchelonBackups\2026-11-26\echelon-db.sql"` (in `cmd`)
   * phpMyAdmin file: click the database → **Import** → choose the file → **Go**.

   The dump contains `DROP TABLE` lines, so the tables are replaced.

---

## Part B – New installation

1. **Create the database and a user.** In phpMyAdmin's **SQL** tab (as root), or with `"C:\xampp\mysql\bin\mysql.exe" -u root -p`:

```sql
CREATE DATABASE echelon CHARACTER SET utf8;
CREATE USER 'echelon'@'localhost' IDENTIFIED BY 'CHOOSE-A-PASSWORD';
GRANT ALL PRIVILEGES ON echelon.* TO 'echelon'@'localhost';
FLUSH PRIVILEGES;
```

2. **Create the tables.** Select the new `echelon` database, then import **first** `installer\sql\echelon-base-schema.sql`, **then** `installer\sql\echelon-upgrade.sql` (phpMyAdmin *Import*, or `mysql.exe ... < file` as in Part A, Step 6). *Only ever run the base schema on an empty database: it drops the tables first.*
3. **Copy the files:** the package's `echelon` folder to `C:\xampp\htdocs\echelon`.
4. **Run the browser installer:** open `http://localhost/echelon/install/index.php`, enter your e-mail and the database details from step 1, and press *Install*. Echelon creates the `admin` login; if it cannot send e-mail it shows the password on the next page – write it down.
5. **Delete the folder** `C:\xampp\htdocs\echelon\install`.
6. IIS only: grant write access to `lib` and `images\maps` as in Part A, Step 7.
7. Log in as `admin`, and add your game under **Settings → Games**.

---

## Quick checklist (upgrade)

- [ ] PHP 8.1+ and the extensions are active
- [ ] Notes: database login and `$path` from the old `inc\config.php`
- [ ] Files backed up (`echelon-files`, checked)
- [ ] Database backed up (`echelon-db.sql`, checked)
- [ ] Old `echelon` folder deleted, new one copied in
- [ ] `config.php`, `images\maps`, GeoIP/GeoLite2 files and `log.txt` copied back
- [ ] `install` folder deleted
- [ ] `echelon-upgrade.sql` run on the Echelon database
- [ ] Write access for `lib` and `images\maps` (IIS)
- [ ] Mapcycle files chosen again under Settings > Maps & World Map if they show "Not set"
- [ ] Logged in, footer shows v3.0
- [ ] Backup folder kept somewhere private until you are happy
