<?php
/**
 * Map config helpers: game types, map thumbnails, input validation.
 *
 * Thumbnails live in images/maps/<slug>.<ext> where <slug> is the map name lower-cased with anything
 * outside [a-z0-9_.-] replaced by "_". Dropping a file named like the map (ut4_turnpike.jpg) in that
 * folder is enough - no database change needed (the B3 mapconfig table is owned by B3).
 */

if ('maps.php' === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    die('Please do not load this page directly. Thanks!');
}

/** The game types B3 / Urban Terror use in mapconfig.gamemodes. code => label */
function mapBuiltinGameModes() {
    return array(
        'bm'   => 'Bomb',
        'cah'  => 'Capture and Hold',
        'ctf'  => 'Capture the Flag',
        'ffa'  => 'Free for All',
        'ft'   => 'Freeze Tag',
        'ftl'  => 'Follow the Leader',
        'gun'  => 'Gun Game',
        'jump' => 'Jump',
        'lms'  => 'Last Man Standing',
        'tdm'  => 'Team Deathmatch',
        'ts'   => 'Team Survivor',
    );
}

/** Every game type: the built-in ones, then the custom ones the site owner added (Settings > Maps & World Map). code => label */
function mapGameModes() {
    return mapBuiltinGameModes() + mapcycleCustomTypes();
}

/** A colour for a game type's pill: built-in types have one in the stylesheet, custom ones get a steady colour of their own */
function mapModeColor($code) {
    if (array_key_exists($code, mapBuiltinGameModes())) { return 'var(--m-' . $code . ', #868e96)'; }
    return 'hsl(' . (crc32((string) $code) % 360) . ', 58%, 46%)';
}

/** Split a "ctf, ts,tdm" string into a clean, de-duplicated list of codes */
function mapParseModes($str) {
    $out = array();
    foreach (explode(',', strtolower((string) $str)) as $m) {
        $m = trim($m);
        if ($m !== '' && !in_array($m, $out, true)) {
            $out[] = $m;
        }
    }
    return $out;
}

/** File-name safe version of a map name */
function mapSlug($mapname) {
    return preg_replace('/[^a-z0-9_.\-]/', '_', strtolower(trim((string) $mapname)));
}

/** Valid map name? (letters, digits, underscore, dot, dash - what the game engine allows in practice) */
function mapNameValid($mapname) {
    return (bool) preg_match('/^[A-Za-z0-9_.\-]{1,50}$/', (string) $mapname);
}

/** "ut4_turnpike" -> "Turnpike" for display */
function mapPrettyName($mapname) {
    $n = preg_replace('/^ut4_/i', '', (string) $mapname);
    return ucwords(str_replace('_', ' ', $n));
}

/** Stable hue 0-359 for a map name (used for the placeholder tile colour) */
function mapHue($mapname) {
    return crc32(strtolower((string) $mapname)) % 360;
}

const MAP_IMG_EXT = array('webp', 'jpg', 'jpeg', 'png', 'gif');

/**
 * One scan of the thumbnails folder -> [slug => ['file' => 'x.jpg', 'v' => mtime]].
 * (Doing a file_exists() per map is a stat() call per tile; this is one directory read.)
 */
function mapImageIndex() {
    static $idx = null;
    if ($idx !== null) {
        return $idx;
    }
    $idx = array();
    if (!is_dir(MAP_IMG_DIR)) {
        return $idx;
    }
    foreach (new FilesystemIterator(MAP_IMG_DIR, FilesystemIterator::SKIP_DOTS) as $f) {
        if (!$f->isFile()) { continue; }
        $ext = strtolower($f->getExtension());
        if (!in_array($ext, MAP_IMG_EXT, true)) { continue; }
        $slug = strtolower($f->getBasename('.' . $f->getExtension()));
        // if several formats exist for one map, the newest wins
        if (!isset($idx[$slug]) || $f->getMTime() > $idx[$slug]['v']) {
            $idx[$slug] = array('file' => $f->getFilename(), 'v' => $f->getMTime());
        }
    }
    return $idx;
}

/** Public URL of the thumbnail for a map, or null when there is none */
function mapImageUrl($mapname) {
    $idx = mapImageIndex();
    $slug = mapSlug($mapname);
    if (!isset($idx[$slug])) {
        return null;
    }
    return PATH . 'images/maps/' . rawurlencode($idx[$slug]['file']) . '?v=' . $idx[$slug]['v'];
}

/** Remove every stored thumbnail for a map */
function mapImageDelete($mapname) {
    $slug = mapSlug($mapname);
    foreach (MAP_IMG_EXT as $ext) {
        $p = MAP_IMG_DIR . $slug . '.' . $ext;
        if (is_file($p)) { @unlink($p); }
    }
}

/** Keep the thumbnail when a map is renamed */
function mapImageRename($old, $new) {
    $o = mapSlug($old);
    $n = mapSlug($new);
    if ($o === $n) { return; }
    foreach (MAP_IMG_EXT as $ext) {
        $p = MAP_IMG_DIR . $o . '.' . $ext;
        if (is_file($p)) {
            @rename($p, MAP_IMG_DIR . $n . '.' . $ext);
        }
    }
}

/** PHP ini size ("128M") in bytes; -1 = unlimited */
function mapIniBytes($v) {
    $v = trim((string) $v);
    if ($v === '' || $v === '-1') { return -1; }
    $n = (float) $v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return (int) $n;
}

/**
 * Validate an uploaded picture and store it as an 800x450 thumbnail (cover-cropped to 16:9).
 *
 * Memory: a decoded image costs about width*height*4 bytes in GD, so a 9000x6000 photo would need
 * >200MB. We read the dimensions from the header first (cheap) and refuse anything that would not fit.
 *
 * @return array [bool ok, string message]
 */
function mapImageSave($tmp_file, $mapname, $client_size = 0) {
    if (!is_uploaded_file($tmp_file)) {
        return array(false, 'No file was uploaded.');
    }
    return mapImageProcess($tmp_file, $mapname, '');
}

/**
 * Same as mapImageSave() for any readable file (an upload, or a picture taken out of a .pk3).
 * $hint = 'tga' for Targa files, which cannot be recognised by mime type.
 */
function mapImageProcess($tmp_file, $mapname, $hint = '') {
    if (!extension_loaded('gd')) {
        return array(false, 'The PHP GD extension is not installed, so images cannot be processed (install php-gd).');
    }
    $size = (int) @filesize($tmp_file);
    if ($size <= 0 || $size > 8 * 1024 * 1024) {
        return array(false, 'The image must be smaller than 8 MB.');
    }

    if ($hint === 'tga') {
        // GD reads Targa (PHP 7.4+) but getimagesize() does not: read the size from the 18 byte header
        if (!function_exists('imagecreatefromtga')) {
            return array(false, 'Targa (.tga) pictures are not supported by this PHP build.');
        }
        $hd = (string) @file_get_contents($tmp_file, false, null, 0, 18);
        if (strlen($hd) < 18) { return array(false, 'That file is not a readable image.'); }
        $w = ord($hd[12]) | (ord($hd[13]) << 8);
        $h = ord($hd[14]) | (ord($hd[15]) << 8);
        if ($w < 16 || $h < 16) { return array(false, 'That file is not a readable image.'); }
        $loader = 'imagecreatefromtga';
    } else {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp_file);
        $loaders = array('image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/gif' => 'imagecreatefromgif', 'image/webp' => 'imagecreatefromwebp');
        if (!isset($loaders[$mime]) || !function_exists($loaders[$mime])) {
            return array(false, 'Unsupported image type. Use JPEG, PNG, GIF or WebP.');
        }
        $info = @getimagesize($tmp_file);
        if (!$info || $info[0] < 16 || $info[1] < 16) {
            return array(false, 'That file is not a readable image.');
        }
        list($w, $h) = $info;
        $loader = $loaders[$mime];
    }

    $limit = mapIniBytes(ini_get('memory_limit'));
    $need = (int) ($w * $h * 4 * 1.8) + (800 * 450 * 4) + memory_get_usage(true); // source + working copy + target
    if (($limit > 0 && $need > $limit) || $w * $h > 40000000) {
        return array(false, "That image is too large to process ({$w}x{$h}). Please resize it to under about 4000 pixels wide and try again.");
    }

    $src = @$loader($tmp_file);
    if (!$src) {
        return array(false, 'The image could not be decoded.');
    }

    // cover-crop to 16:9, then scale to at most 800x450
    $tw = 800; $th = 450;
    $ratio = $tw / $th;
    if ($w / $h > $ratio) { $sh = $h; $sw = (int) round($h * $ratio); $sx = (int) (($w - $sw) / 2); $sy = 0; }
    else                  { $sw = $w; $sh = (int) round($w / $ratio); $sx = 0; $sy = (int) (($h - $sh) / 2); }
    if ($sw < $tw) { $tw = $sw; $th = $sh; } // never upscale

    $dst = imagecreatetruecolor($tw, $th);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 18, 22, 29)); // flatten transparency on the app's dark colour
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $tw, $th, $sw, $sh);
    imagedestroy($src);

    if (!is_dir(MAP_IMG_DIR) && !@mkdir(MAP_IMG_DIR, 0775, true)) {
        imagedestroy($dst);
        return array(false, 'The folder images/maps/ does not exist and could not be created.');
    }
    if (!is_writable(MAP_IMG_DIR)) {
        imagedestroy($dst);
        return array(false, 'The folder images/maps/ is not writable by the web server user.');
    }

    mapImageDelete($mapname); // replace, do not accumulate formats
    $slug = mapSlug($mapname);
    if (function_exists('imagewebp')) {
        $ok = imagewebp($dst, MAP_IMG_DIR . $slug . '.webp', 80);
    } else {
        $ok = imagejpeg($dst, MAP_IMG_DIR . $slug . '.jpg', 84);
    }
    imagedestroy($dst);

    return $ok ? array(true, 'Image saved.') : array(false, 'The image could not be written to disk.');
}

/**
 * Read + validate the map form fields from $_POST. Returns [array|null $clean, array $errors].
 * Everything is stored raw (no HTML-entity encoding) and escaped on output.
 */
function mapReadForm() {
    $err = array();
    $p = function ($k, $d = '') { $v = $_POST[$k] ?? $d; return is_string($v) ? trim($v) : $d; };

    $mapname = $p('mapname');
    if (!mapNameValid($mapname)) {
        $err[] = 'Map name is required and may only contain letters, numbers, "_", "-" and "." (max 50 characters).';
    }

    $int = function ($key, $label, $min, $max, $default) use ($p, &$err) {
        $v = $p($key, (string) $default);
        if ($v === '') { return $default; }
        if (!preg_match('/^-?\d+$/', $v) || (int) $v < $min || (int) $v > $max) {
            $err[] = "$label must be a whole number between $min and $max.";
            return $default;
        }
        return (int) $v;
    };

    $clean = array(
        'mapname'        => $mapname,
        'capturelimit'   => $int('capturelimit', 'Capture limit', 0, 99, 0),
        'g_suddendeath'  => $int('g_suddendeath', 'Sudden death', 0, 1, 0),
        'g_gravity'      => $int('g_gravity', 'Gravity', 0, 9999, 800),
        'g_friendlyfire' => $int('g_friendlyfire', 'Friendly fire', 0, 3, 0),
        'skiprandom'     => $int('skiprandom', 'Skip random', 0, 1, 0),
        'timelimit'      => $int('timelimit', 'Time limit', 0, 999, 20),
        'bot'            => $int('bot', 'Bot support', 0, 1, 0),
    );

    $gear = $p('g_gear', '0');
    if ($gear === '') { $gear = '0'; }
    if (!preg_match('/^[A-Za-z0-9]{1,100}$/', $gear)) {
        $err[] = 'Gear may only contain letters and numbers (max 100 characters).';
        $gear = '0';
    }
    $clean['g_gear'] = $gear;

    $msg = preg_replace('/[\x00-\x1F\x7F]/', '', strip_tags($p('startmessage')));
    if (mb_strlen($msg) > 75) {
        $err[] = 'Start message can be at most 75 characters.';
        $msg = mb_substr($msg, 0, 75);
    }
    $clean['startmessage'] = $msg;

    $d = DateTime::createFromFormat('Y-m-d', $p('datelastadd', '2000-01-01'));
    $dl = DateTime::getLastErrors();
    $clean['datelastadd'] = ($d && (!$dl || ($dl['warning_count'] + $dl['error_count']) === 0)) ? $d->format('Y-m-d') : '2000-01-01';

    // game types: ticked boxes + an optional free-text list for modes this site does not know about
    $modes = array();
    $ticked = $_POST['gamemode'] ?? array();
    if (is_array($ticked)) {
        foreach (array_keys(mapGameModes()) as $code) { // canonical order
            if (in_array($code, $ticked, true)) { $modes[] = $code; }
        }
    }
    foreach (mapParseModes($p('gamemodes_other')) as $m) {
        if (preg_match('/^[a-z0-9_]{1,12}$/', $m)) {
            if (!in_array($m, $modes, true)) { $modes[] = $m; }
        } else {
            $err[] = 'Other game types may only contain letters, numbers and underscores.';
        }
    }
    $clean['gamemodes'] = implode(',', $modes);
    if (strlen($clean['gamemodes']) > 50) {
        $err[] = 'Too many game types: the list must fit in 50 characters ("' . $clean['gamemodes'] . '").';
    }

    return array($clean, $err);
}


/* ------------------------------------------------------------------------------------------------------
 * Pictures from map files (.pk3)
 *
 * Urban Terror / Quake 3 maps ship as .pk3 files (zip archives). Each map has a "level shot" inside,
 * levelshots/<mapname>.jpg (or .tga), which is the picture shown on the game's map selection screen.
 * ---------------------------------------------------------------------------------------------------- */

/**
 * Folders that hold the .pk3 files, in this order of preference:
 *  1. the folder(s) the admin entered (Settings > Maps, or the Map Config page); one per "|" in the setting
 *  2. MAP_PK3_DIR in inc/config.php (a path, or an array of paths)
 *  3. the folder of the server's Mapcycle File, because the game keeps both in one place
 * Only folders the web server can read are returned.
 */
function mapPk3Dirs($mapcycleFile = '') {
    $dirs = array();
    $saved = function_exists('geoCfg') ? (string) geoCfg('map_pk3_dir', '') : '';
    if ($saved !== '') { $dirs = explode('|', $saved); }
    if (defined('MAP_PK3_DIR')) {
        $c = MAP_PK3_DIR;
        $dirs = array_merge($dirs, is_array($c) ? $c : array($c));
    }
    if (!$dirs && $mapcycleFile !== '') {
        $dirs = array(dirname($mapcycleFile));
    }
    $out = array();
    foreach ($dirs as $d) {
        $d = rtrim(trim((string) $d), '/\\');
        if ($d !== '' && @is_dir($d) && @is_readable($d)) { $out[] = $d; }
    }
    return array_values(array_unique($out));
}

/**
 * Check a folder an admin typed. Returns [string|null cleaned path, string error].
 * Must be an absolute path to a folder this web server can read; no URLs, no "..".
 */
function mapPathClean($p) {
    $p = trim((string) $p);
    if ($p === '' || strpos($p, "\0") !== false || strlen($p) > 200) { return array(null, 'Please enter the full path of the folder.'); }
    if (strpos($p, '://') !== false || preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $p)) { return array(null, 'Please enter a plain folder path (no web addresses and no "..").'); }
    if (!preg_match('#^(/|[A-Za-z]:[/\\\\]|\\\\\\\\)#', $p)) { return array(null, 'The path must be absolute, for example /home/urt/.q3a/q3ut4 or C:\\UrbanTerror\\q3ut4.'); }
    $p = rtrim($p, '/\\');
    if (!@is_dir($p)) { return array(null, 'That folder was not found on the server that runs this website. (If your game server is on another machine, use "From a .pk3 file" instead.)'); }
    if (!@is_readable($p)) { return array(null, 'That folder exists but the web server user is not allowed to read it. Give that user read access to it.'); }
    return array($p, '');
}

/** Likely game folders that exist and are readable here, to offer as one-click suggestions */
function mapPk3Suggestions($mapcycleFile = '') {
    $c = array();
    if ($mapcycleFile !== '') { $c[] = dirname($mapcycleFile); }
    foreach (array('/home/*/.q3a/q3ut4', '/home/*/.config/UrbanTerror/q3ut4', '/home/*/UrbanTerror43/q3ut4', '/opt/urbanterror/q3ut4', '/opt/UrbanTerror43/q3ut4', '/usr/lib/urbanterror/q3ut4', '/usr/share/urbanterror/q3ut4', '/usr/local/games/urbanterror/q3ut4', '/srv/urbanterror/q3ut4') as $g) {
        foreach ((array) @glob($g) as $d) { $c[] = $d; }
    }
    $out = array();
    foreach (array_unique($c) as $d) {
        if (@is_dir($d) && @is_readable($d) && count((array) @glob($d . '/*.[pP][kK]3')) > 0) { $out[] = $d; }
    }
    return array_slice($out, 0, 5);
}

/** Every .pk3 in the folders (not recursive; Quake 3 keeps them flat) */
function mapPk3Files(array $dirs) {
    $files = array();
    foreach ($dirs as $d) {
        foreach ((array) @glob($d . '/*.[pP][kK]3') as $f) {
            if (is_file($f)) { $files[] = $f; }
        }
    }
    sort($files);
    return $files;
}

/**
 * Pull level shots out of one .pk3 and store them as map pictures.
 *
 * @param string $pk3     path of the archive
 * @param array  $wanted  [slug => mapname] of the maps that may be given a picture
 * @param bool   $replace replace pictures that already exist
 * @return array [saved => [mapname...], skipped => [mapname...], errors => [string...]]
 */
function mapPk3Extract($pk3, array $wanted, $replace = false) {
    $res = array('saved' => array(), 'skipped' => array(), 'errors' => array());
    if (!class_exists('ZipArchive')) {
        $res['errors'][] = 'The PHP zip extension is not installed (install php-zip), so map files cannot be opened.';
        return $res;
    }
    $zip = new ZipArchive();
    if ($zip->open($pk3, ZipArchive::RDONLY) !== true) {
        $res['errors'][] = basename($pk3) . ' could not be opened.';
        return $res;
    }
    $have = mapImageIndex();
    $n = min($zip->numFiles, 20000);
    $best = array(); // slug => [index, rank] (jpg preferred over tga: smaller, same picture)
    for ($i = 0; $i < $n; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false || !preg_match('#^levelshots/([^/]+)\.(jpe?g|png|tga)$#i', $name, $m)) { continue; }
        $slug = mapSlug($m[1]);
        if (!isset($wanted[$slug])) { continue; }
        if (!$replace && isset($have[$slug])) { $res['skipped'][$slug] = $wanted[$slug]; continue; }
        $rank = strtolower($m[2]) === 'tga' ? 1 : 0;
        if (!isset($best[$slug]) || $rank < $best[$slug][1]) { $best[$slug] = array($i, $rank, strtolower($m[2])); }
    }
    foreach ($best as $slug => $b) {
        $st = $zip->statIndex($b[0]);
        if (!$st || $st['size'] < 64 || $st['size'] > 8 * 1024 * 1024) { // also stops zip bombs
            $res['errors'][] = $wanted[$slug] . ': the picture in ' . basename($pk3) . ' has an unusual size.';
            continue;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mapimg');
        $in = $zip->getStream($zip->getNameIndex($b[0]));
        $out = $in ? fopen($tmp, 'wb') : false;
        if (!$in || !$out) { @unlink($tmp); if ($in) { fclose($in); } continue; }
        stream_copy_to_stream($in, $out, 8 * 1024 * 1024);
        fclose($in); fclose($out);
        list($ok, $msg) = mapImageProcess($tmp, $wanted[$slug], $b[2] === 'tga' ? 'tga' : '');
        @unlink($tmp);
        if ($ok) { $res['saved'][] = $wanted[$slug]; } else { $res['errors'][] = $wanted[$slug] . ': ' . $msg; }
    }
    $zip->close();
    $res['skipped'] = array_values($res['skipped']);
    return $res;
}

/**
 * Look through all .pk3 files of the folders for maps that have no picture yet.
 *
 * @param array $maps   mapnames to look for (default: nothing)
 * @return array [saved, missing, errors, files]
 */
function mapPk3Scan(array $dirs, array $maps, $replace = false) {
    @set_time_limit(120);
    $wanted = array();
    foreach ($maps as $m) { $wanted[mapSlug($m)] = $m; }
    if (!$replace) {
        foreach (mapImageIndex() as $slug => $x) { unset($wanted[$slug]); }
    }
    $out = array('saved' => array(), 'missing' => array(), 'errors' => array(), 'files' => 0);
    $files = mapPk3Files($dirs);
    $out['files'] = count($files);
    foreach ($files as $f) {
        if (!$wanted) { break; }
        $r = mapPk3Extract($f, $wanted, $replace);
        foreach ($r['saved'] as $name) { $out['saved'][] = $name; unset($wanted[mapSlug($name)]); }
        $out['errors'] = array_merge($out['errors'], $r['errors']);
        if (count($out['errors']) > 20) { break; }
    }
    $out['missing'] = array_values($wanted);
    return $out;
}


/* ==========================================================================
   Mapcycle files
   Each game type (and "default") can have a mapcycle file. The real file lives wherever the
   game server keeps it; Echelon keeps a symbolic link to it in its own mapcycle/ folder
   (mapcycle/ctf.txt -> /home/urt/.q3a/q3ut4/mapcycle_ctf.txt) and reads and writes through
   that link, so the link stays where it is and the real file is rewritten in place.
   The link is required: if it cannot be made the user is told why (no silent fallback). A path saved
   by an older version (mc_<slot> setting) is still used until the file is chosen again.
   ========================================================================== */

/** extensions a mapcycle file may have */
function mapcycleExtOk($path) {
    return in_array(strtolower(pathinfo((string) $path, PATHINFO_EXTENSION)), array('txt', 'cfg'), true);
}

function mapcycleDir() { return rtrim(ECH_ROOT, '/\\') . DIRECTORY_SEPARATOR . 'mapcycle'; }

/* ---- custom game types: the site owner's own game types, each with its own mapcycle file ---- */

const MAPCYCLE_CUSTOM_MAX = 24;

/** codes that can never be a custom game type */
function mapcycleReservedCodes() { return array('default', 'none', 'all', 'any', 'other', 'custom'); }

/** custom game types, code => label (stored as JSON in the ech_config setting mc_custom_types) */
function mapcycleCustomTypes() {
    $raw = (string) geoCfg('mc_custom_types', '');
    $list = $raw !== '' ? json_decode($raw, true) : null;
    $out = array();
    if (is_array($list)) {
        $builtin = mapBuiltinGameModes();
        foreach ($list as $code => $label) {
            if (is_string($code) && preg_match('/^[a-z][a-z0-9_]{0,11}$/', $code) && is_string($label) && $label !== ''
                && !isset($builtin[$code]) && !in_array($code, mapcycleReservedCodes(), true)) {
                $out[$code] = $label;
            }
        }
    }
    return $out;
}

function mapcycleCustomStore($list) {
    global $dbl, $config;
    $json = $list ? json_encode($list, JSON_UNESCAPED_UNICODE) : '';
    $dbl->saveSetting('mc_custom_types', $json);
    $config['cosmos']['mc_custom_types'] = $json;
}

/** a clean display name: no tags or control characters, 1-40 characters */
function mapcycleCustomLabel($label) {
    $label = trim((string) preg_replace('/[\x00-\x1F\x7F<>"]+/u', ' ', strip_tags((string) $label)));
    $label = trim((string) preg_replace('/\s+/u', ' ', $label));
    return mb_substr($label, 0, 40);
}

/** a code from what was typed: lower-case letters, digits and underscores, starting with a letter, up to 12 */
function mapcycleCustomCode($input) {
    $c = strtolower(trim((string) $input));
    $c = trim((string) preg_replace('/[^a-z0-9]+/', '_', $c), '_');
    return substr($c, 0, 12);
}

/** add a custom game type. @return array ok, error?, code?, label? */
function mapcycleCustomAdd($label, $codeInput = '') {
    $label = mapcycleCustomLabel($label);
    if ($label === '') { return array('ok' => false, 'error' => 'Give the game type a name, for example "Instagib CTF".'); }
    $code = mapcycleCustomCode(trim((string) $codeInput) !== '' ? $codeInput : $label);
    if ($code === '' || !preg_match('/^[a-z][a-z0-9_]{0,11}$/', $code)) {
        return array('ok' => false, 'error' => 'The short code must start with a letter and use only letters, numbers and underscores (up to 12), for example instactf.');
    }
    if (isset(mapBuiltinGameModes()[$code])) { return array('ok' => false, 'error' => '"' . $code . '" is already a built-in game type. Choose another code.'); }
    if (in_array($code, mapcycleReservedCodes(), true)) { return array('ok' => false, 'error' => '"' . $code . '" is reserved. Choose another code.'); }
    $list = mapcycleCustomTypes();
    if (isset($list[$code])) { return array('ok' => false, 'error' => 'You already have a game type with the code "' . $code . '".'); }
    if (count($list) >= MAPCYCLE_CUSTOM_MAX) { return array('ok' => false, 'error' => 'That is the most custom game types Echelon keeps (' . MAPCYCLE_CUSTOM_MAX . ').'); }
    $list[$code] = $label;
    mapcycleCustomStore($list);
    return array('ok' => true, 'code' => $code, 'label' => $label);
}

/** change the name of a custom game type (the code never changes) */
function mapcycleCustomRename($code, $label) {
    $list = mapcycleCustomTypes();
    if (!is_string($code) || !isset($list[$code])) { return array('ok' => false, 'error' => 'That is not one of your custom game types.'); }
    $label = mapcycleCustomLabel($label);
    if ($label === '') { return array('ok' => false, 'error' => 'Give the game type a name.'); }
    $list[$code] = $label;
    mapcycleCustomStore($list);
    return array('ok' => true, 'code' => $code, 'label' => $label);
}

/** remove a custom game type and its link in mapcycle/. The real mapcycle file and the maps' own game type tags are left alone. */
function mapcycleCustomRemove($code) {
    $list = mapcycleCustomTypes();
    if (!is_string($code) || !isset($list[$code])) { return array('ok' => false, 'error' => 'That is not one of your custom game types.'); }
    $r = mapcycleUnlink($code);
    if (!$r['ok']) { return $r; }
    $prev = mapcycleDir() . DIRECTORY_SEPARATOR . $code . '.previous';
    if (is_file($prev)) { @unlink($prev); }
    unset($list[$code]);
    mapcycleCustomStore($list);
    return array('ok' => true);
}

/** slot code => label. "default" is used for any game type that has no file of its own */
function mapcycleSlots() {
    return array('default' => 'All game types (one file)') + mapGameModes();
}

function mapcycleSlotValid($slot) {
    return is_string($slot) && array_key_exists($slot, mapcycleSlots());
}

function mapcycleLinkPath($slot) { return mapcycleDir() . DIRECTORY_SEPARATOR . $slot . '.txt'; }

/** create mapcycle/ (with a .htaccess that keeps browsers out) */
function mapcycleEnsureDir() {
    $d = mapcycleDir();
    if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) { return false; }
    $ht = $d . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "# mapcycle files are read and written by Echelon only\nRequire all denied\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }
    return true;
}

/**
 * What Echelon uses for a slot.
 * @return array path (what to open; '' if nothing), via (link|setting|server|''), target (the real file), plus file facts
 */
function mapcycleTarget($slot, $serverFile = '') {
    $out = array('slot' => $slot, 'via' => '', 'path' => '', 'target' => '', 'broken' => false);
    $lp = mapcycleLinkPath($slot);
    $direct = trim((string) geoCfg('mc_' . $slot, ''));
    if (is_link($lp)) {
        $out['via'] = 'link'; $out['path'] = $lp;
        $t = @readlink($lp);
        $out['target'] = ($t !== false) ? $t : '';
        $out['broken'] = !file_exists($lp);
    } elseif ($direct !== '') {
        $out['via'] = 'setting'; $out['path'] = $direct; $out['target'] = $direct;
    } elseif ($slot === 'default' && trim((string) $serverFile) !== '') {
        $out['via'] = 'server'; $out['path'] = trim((string) $serverFile); $out['target'] = $out['path'];
    }
    if ($out['path'] !== '') {
        $out['exists']   = file_exists($out['path']);
        $out['readable'] = $out['exists'] && is_readable($out['path']);
        $out['writable'] = $out['exists'] ? is_writable($out['path']) : false;
    } else {
        $out['exists'] = $out['readable'] = $out['writable'] = false;
    }
    return $out;
}

/** Make slot -> real file. Returns array(ok, error?, via?) */
function mapcycleLink($slot, $realInput) {
    global $dbl;
    if (!mapcycleSlotValid($slot)) { return array('ok' => false, 'error' => 'Unknown game type.'); }
    $realInput = trim((string) $realInput);
    if ($realInput === '' || strpos($realInput, "\0") !== false) { return array('ok' => false, 'error' => 'Choose a file first.'); }
    $real = @realpath($realInput);
    if ($real === false || !is_file($real)) {
        return array('ok' => false, 'error' => 'That file was not found (or the web server is not allowed to look there).');
    }
    if (!mapcycleExtOk($real)) { return array('ok' => false, 'error' => 'A mapcycle file ends in .txt or .cfg.'); }
    if (!is_readable($real)) { return array('ok' => false, 'error' => 'The web server cannot read that file. Give the web server user read access to it.'); }
    $root = @realpath(ECH_ROOT);
    if ($root !== false && strpos($real . DIRECTORY_SEPARATOR, rtrim($root, '/\\') . DIRECTORY_SEPARATOR) === 0) {
        return array('ok' => false, 'error' => 'That file is part of Echelon itself. Pick the mapcycle file of your game server.');
    }
    if (!mapcycleEnsureDir()) { return array('ok' => false, 'error' => 'Echelon cannot create its mapcycle folder. Make the Echelon folder writable by the web server, or create a folder named mapcycle yourself.'); }

    $lp = mapcycleLinkPath($slot);
    if (is_link($lp)) {
        if (!@unlink($lp)) { return array('ok' => false, 'error' => 'The existing link could not be replaced (permissions on the mapcycle folder).'); }
    } elseif (file_exists($lp)) {
        return array('ok' => false, 'error' => 'There is already a real file named ' . basename($lp) . ' in the mapcycle folder. Move it away first so it is not overwritten.');
    }
    // The link is required: Echelon always works through mapcycle/<slot>.txt. No silent fallback.
    $err = '';
    $made = false;
    if (function_exists('symlink')) {
        $made = @symlink($real, $lp);
        if (!$made) { $e = error_get_last(); $err = $e ? (string) $e['message'] : ''; }
    } else {
        $err = 'symlink() is disabled in PHP (disable_functions).';
    }
    if (!$made && DIRECTORY_SEPARATOR === '\\' && function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
        // Windows: PHP may lack the privilege while cmd can still make the link (needs Developer Mode or an elevated service)
        $o = array(); $rc = 1;
        @exec('cmd /c mklink ' . escapeshellarg($lp) . ' ' . escapeshellarg($real) . ' 2>&1', $o, $rc);
        $made = ($rc === 0 && is_link($lp));
        if (!$made && $o) { $err = trim(implode(' ', $o)); }
    }
    if (!$made) {
        $dir = mapcycleDir();
        $hint = !function_exists('symlink')
            ? 'Remove symlink from disable_functions in php.ini and restart the web server.'
            : (DIRECTORY_SEPARATOR === '\\'
            ? 'On Windows the web server needs the "Create symbolic links" right (or Windows Developer Mode switched on); see INSTALL-WINDOWS.md.'
            : 'Check that the web server user (' . mapcycleWebUser() . ') can write to ' . $dir . ' (for example: sudo chown ' . mapcycleWebUser() . ' ' . $dir . ').');
        return array('ok' => false, 'error' => 'The symbolic link could not be created in the mapcycle folder' . ($err !== '' && function_exists('symlink') ? ' (' . $err . ')' : '') . '. ' . $hint);
    }
    // a link now exists, so any older "saved location" for this slot is obsolete
    $dbl->saveSetting('mc_' . $slot, '');
    global $config;
    $config['cosmos']['mc_' . $slot] = '';
    return array('ok' => true, 'via' => 'link');
}

/** remove a slot's link (only ever a link, never a real file) */
function mapcycleUnlink($slot) {
    global $dbl, $config;
    if (!mapcycleSlotValid($slot)) { return array('ok' => false, 'error' => 'Unknown game type.'); }
    $lp = mapcycleLinkPath($slot);
    if (is_link($lp) && !@unlink($lp)) { return array('ok' => false, 'error' => 'The link could not be removed (permissions on the mapcycle folder).'); }
    $dbl->saveSetting('mc_' . $slot, '');
    $config['cosmos']['mc_' . $slot] = '';
    return array('ok' => true);
}

/** every slot with its current target, for the pages */
function mapcycleOverview($serverFile = '') {
    $out = array();
    $custom = mapcycleCustomTypes();
    foreach (mapcycleSlots() as $slot => $label) {
        $t = mapcycleTarget($slot, $serverFile);
        $t['label'] = $label;
        $t['custom'] = isset($custom[$slot]);
        $out[] = $t;
    }
    return $out;
}

/** the web server's user name, for permission hints */
function mapcycleWebUser() {
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $u = @posix_getpwuid(posix_geteuid());
        if (!empty($u['name'])) { return $u['name']; }
    }
    return get_current_user();
}

/**
 * List one folder for the file picker: folders and .txt/.cfg files only.
 * @return array ok, path, parent, sep, entries[name,path,type,size], roots (Windows drives)
 */
function mapcycleBrowse($path, $startHint = '') {
    $sep = DIRECTORY_SEPARATOR;
    $path = trim((string) $path);
    if ($path === '' || strpos($path, "\0") !== false) { $path = $startHint; }
    $cands = array($path, $startHint, dirname(ECH_ROOT), $sep);
    $dir = false;
    foreach ($cands as $c) {
        if ($c === '' || $c === null) { continue; }
        $r = @realpath($c);
        if ($r === false) { continue; }
        if (is_file($r)) { $r = dirname($r); }
        if (is_dir($r)) { $dir = $r; break; }
    }
    if ($dir === false) { return array('ok' => false, 'error' => 'That folder cannot be opened (it does not exist or the web server is not allowed to look there).'); }
    if (!is_readable($dir)) { return array('ok' => false, 'error' => 'The web server cannot read that folder.', 'path' => $dir); }
    $names = @scandir($dir);
    if ($names === false) { return array('ok' => false, 'error' => 'The web server cannot list that folder.', 'path' => $dir); }
    $dirs = array(); $files = array(); $more = false;
    foreach ($names as $n) {
        if ($n === '.' || $n === '..' || $n[0] === '.') { continue; }
        $full = rtrim($dir, '/\\') . $sep . $n;
        if (is_dir($full)) { $dirs[] = array('name' => $n, 'path' => $full, 'type' => 'dir'); }
        elseif (is_file($full) && mapcycleExtOk($n)) { $files[] = array('name' => $n, 'path' => $full, 'type' => 'file', 'size' => (int) @filesize($full), 'writable' => is_writable($full)); }
        if (count($dirs) + count($files) >= 800) { $more = true; break; }
    }
    $by = function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); };
    usort($dirs, $by); usort($files, $by);
    $parent = dirname($dir);
    $roots = array();
    if ($sep === '\\') { foreach (range('A', 'Z') as $L) { if (@is_dir($L . ':\\')) { $roots[] = $L . ':\\'; } } }
    return array('ok' => true, 'path' => $dir, 'parent' => ($parent !== $dir ? $parent : ''), 'sep' => $sep,
        'entries' => array_merge($dirs, $files), 'roots' => $roots, 'truncated' => $more);
}


/**
 * Gear calculator data: the letters Urban Terror 4.x uses in g_gear (and in a player's own gear setting).
 * g_gear lists the letters of the items that are NOT allowed; "0" allows everything.
 * group => label => [letter => item name]
 */
function mapGearItems() {
    return array(
        'Sidearms' => array('F' => 'Beretta 92G', 'G' => 'Desert Eagle', 'f' => 'Glock 18', 'g' => 'Colt 1911'),
        'Primary and secondary weapons' => array(
            'H' => 'SPAS-12', 'I' => 'MP5K', 'J' => 'UMP45', 'h' => 'MAC-11', 'K' => 'HK69', 'L' => 'LR300ML',
            'M' => 'G36', 'e' => 'M4A1', 'a' => 'AK-103', 'c' => 'Negev', 'N' => 'PSG-1', 'Z' => 'SR-8',
        ),
        'Grenades' => array('O' => 'HE grenade', 'Q' => 'Smoke grenade'),
        'Items' => array('R' => 'Kevlar vest', 'W' => 'Kevlar helmet', 'U' => 'Silencer', 'V' => 'Laser sight', 'T' => 'Medkit', 'S' => 'NVGs', 'X' => 'Extra ammo'),
    );
}


/* ==========================================================================
   Map downloads
   When a map in Map Config has no .pk3 in the game files folder, Echelon can fetch it from the
   download sites an administrator has entered (Settings > Maps & World Map > Map downloads).
   A source is an address with {map} in it, e.g. https://fastdl.example.com/q3ut4/{map}.pk3.
   Safety: only http(s); addresses that point at this machine or its network are refused unless
   the administrator allows them; redirects are followed by hand and checked at every step; the
   size is capped; the result must be a zip that really contains maps/<name>.bsp; an existing
   file is never overwritten.
   ========================================================================== */

/** The configured sources, one address per entry */
function mapDlSources() {
    $out = array();
    foreach (preg_split('/\r\n|\r|\n/', (string) geoCfg('map_dl_sources', '')) as $l) {
        $l = trim($l);
        if ($l !== '' && mapDlSourceClean($l)[0] !== null) { $out[] = $l; }
    }
    return array_slice($out, 0, 8);
}

/** Check one source line typed by an admin. Returns [clean|null, error] */
function mapDlSourceClean($line) {
    $line = trim((string) $line);
    if ($line === '' || strlen($line) > 250 || preg_match('/[\x00-\x20"<>\\\\^`|]/', $line)) { return array(null, 'is not a plain web address.'); }
    if (substr_count($line, '{map}') !== 1) { return array(null, 'must contain {map} once, where the map name goes (for example https://example.com/maps/{map}.pk3).'); }
    $test = str_replace('{map}', 'ut4_test', $line);
    $p = parse_url($test);
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), array('http', 'https'), true)) { return array(null, 'must start with http:// or https://.'); }
    if (isset($p['user']) || isset($p['pass'])) { return array(null, 'must not contain a user name or password.'); }
    if (isset($p['port']) && ($p['port'] < 1 || $p['port'] > 65535)) { return array(null, 'has an invalid port.'); }
    if (strpos((string) ($p['host'] ?? ''), '{') !== false) { return array(null, 'cannot have {map} in the host name.'); }
    return array($line, '');
}

function mapDlMaxBytes() {
    $mb = (int) geoCfg('map_dl_max_mb', '200');
    return max(5, min(1000, $mb ?: 200)) * 1048576;
}

/** Folder new maps go into: the first folder the web server may write to */
function mapDlTargetDir(array $dirs) {
    foreach ($dirs as $d) { if (@is_dir($d) && @is_writable($d)) { return $d; } }
    return '';
}

/** Resolve a host to a public IPv4 address. Returns [ip|null, error] */
function mapDlResolve($host) {
    $host = trim($host, '[]');
    $lan = geoCfg('map_dl_lan', '0') === '1';
    if (filter_var($host, FILTER_VALIDATE_IP)) { $ips = array($host); }
    else {
        $ips = (array) @gethostbynamel($host);
        if (!$ips) { return array(null, 'The address of ' . $host . ' could not be looked up.'); }
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { continue; } // IPv4 only: simple to check, and what game sites use
        $long = ip2long($ip);
        $cgnat = ($long !== false) && ($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255'));
        $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false && !$cgnat;
        if ($public || $lan) { return array($ip, ''); }
    }
    return array(null, $host . ' points to this computer or a private network. Tick "Allow addresses on my own network" in the settings if that is intended.');
}

/**
 * Fetch $url. With $dest the body is written there (only for a 200 answer); with $dest === null only the
 * headers are fetched (a test). Redirects are followed by hand, each one checked like the first address.
 * Returns [ok, message, httpCode]
 */
function mapDlFetch($url, $dest, $maxBytes) {
    if (!function_exists('curl_init')) { return array(false, 'Downloading needs the PHP curl extension (install php-curl).', 0); }
    $code = 0;
    for ($hop = 0; $hop < 4; $hop++) {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        if (!$p || empty($p['host']) || !in_array($scheme, array('http', 'https'), true) || isset($p['user']) || isset($p['pass'])) {
            return array(false, 'The download address is not valid.', 0);
        }
        $host = $p['host'];
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        list($ip, $err) = mapDlResolve($host);
        if ($ip === null) { return array(false, $err, 0); }

        $fp = null; $status = 0; $loc = ''; $len = -1; $tooBig = false;
        if ($dest !== null) { $fp = fopen($dest, 'wb'); if (!$fp) { return array(false, 'Echelon could not write a temporary file.', 0); } }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => array($host . ':' . $port . ':' . $ip), // the address that was checked is the address used
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_TIMEOUT => 280,
            CURLOPT_USERAGENT => 'Echelon-3 map downloader',
            CURLOPT_NOBODY => ($dest === null),
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$status, &$loc, &$len) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int) $m[1]; $loc = ''; $len = -1; }
                elseif (stripos($h, 'Location:') === 0) { $loc = trim(substr($h, 9)); }
                elseif (stripos($h, 'Content-Length:') === 0) { $len = (int) trim(substr($h, 15)); }
                return strlen($h);
            },
            CURLOPT_WRITEFUNCTION => function ($c, $data) use (&$fp, &$status, &$tooBig, $maxBytes) {
                if ($fp === null || $status !== 200) { return strlen($data); } // redirect/error pages are thrown away
                if (ftell($fp) + strlen($data) > $maxBytes) { $tooBig = true; return 0; }
                return fwrite($fp, $data);
            },
        ));
        $ok = curl_exec($ch);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($fp) { fclose($fp); }
        if ($tooBig) { return array(false, 'The file is larger than the limit of ' . round($maxBytes / 1048576) . ' MB.', $status); }
        if ($ok === false) { return array(false, 'Could not connect to ' . $host . ($cerr !== '' ? ' (' . $cerr . ')' : '') . '.', 0); }
        $code = $status;
        if (in_array($status, array(301, 302, 303, 307, 308), true) && $loc !== '') {
            if (!preg_match('#^https?://#i', $loc)) { // relative redirect
                $base = $scheme . '://' . $host . (isset($p['port']) ? ':' . $p['port'] : '');
                $loc = $loc[0] === '/' ? $base . $loc : $base . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $loc;
            }
            $url = $loc;
            continue;
        }
        if ($status === 200) { return array(true, '', 200); }
        if ($status === 404 || $status === 410) { return array(false, 'Not found there (HTTP ' . $status . ').', $status); }
        if ($status === 403 || $status === 401) { return array(false, 'The site refused the download (HTTP ' . $status . ').', $status); }
        return array(false, 'The site answered HTTP ' . $status . '.', $status);
    }
    return array(false, 'Too many redirects.', $code);
}

/** Turn the "type" text of an .arena entry (or a readme's game type list) into Echelon's game type codes */
function mapArenaModes($text) {
    $alias = array(
        'ffa' => 'ffa', 'freeforall' => 'ffa', 'dm' => 'ffa', 'deathmatch' => 'ffa',
        'tdm' => 'tdm', 'team' => 'tdm', 'teamdeathmatch' => 'tdm',
        'ts' => 'ts', 'survivor' => 'ts', 'teamsurvivor' => 'ts',
        'ftl' => 'ftl', 'followtheleader' => 'ftl', 'leader' => 'ftl',
        'cah' => 'cah', 'ch' => 'cah', 'c&h' => 'cah', 'captureandhold' => 'cah', 'capturehold' => 'cah',
        'ctf' => 'ctf', 'captureflag' => 'ctf', 'capturetheflag' => 'ctf',
        'bm' => 'bm', 'bomb' => 'bm', 'bombdefuse' => 'bm',
        'ft' => 'ft', 'freeze' => 'ft', 'freezetag' => 'ft',
        'gun' => 'gun', 'gungame' => 'gun', 'gg' => 'gun',
        'jump' => 'jump', 'jumpmode' => 'jump',
        'lms' => 'lms', 'laststanding' => 'lms', 'lastmanstanding' => 'lms',
    );
    $out = array();
    foreach (preg_split('/[\s,;\/|]+/', strtolower((string) $text)) as $tok) {
        $tok = preg_replace('/^ut[_\-]?/', '', trim($tok, " \t\"'()"));
        if ($tok === '') { continue; }
        $k = preg_replace('/[^a-z0-9&]/', '', $tok);
        if (isset($alias[$k]) && !in_array($alias[$k], $out, true)) { $out[] = $alias[$k]; }
    }
    return $out;
}

/** Entries of a .arena file: [mapname(lower) => [long, modes[]]] */
function mapArenaParse($text) {
    $res = array();
    $text = preg_replace('#//[^\n]*#', '', (string) $text);
    if (!preg_match_all('/\{([^{}]*)\}/s', $text, $blocks)) { return $res; }
    foreach ($blocks[1] as $b) {
        if (!preg_match('/\bmap\s+"([^"]{1,60})"/i', $b, $m)) { continue; }
        $long = preg_match('/\blongname\s+"([^"]{0,80})"/i', $b, $l) ? trim($l[1]) : '';
        $type = preg_match('/\btype\s+"([^"]*)"/i', $b, $t) ? $t[1] : '';
        $res[strtolower(trim($m[1]))] = array('long' => $long, 'modes' => mapArenaModes($type));
    }
    return $res;
}

/** Game types mentioned in a map's readme text ("Gametypes: CTF, TS, FFA"), if it has such a line */
function mapReadmeModes($text) {
    if (preg_match('/^[ \t\*\-]*(?:supported\s+)?(?:game[\s_\-]*(?:modes?|types?)|modes?)[ \t]*[:=\-][ \t]*(.{2,120})$/im', (string) $text, $m)) {
        return mapArenaModes($m[1]);
    }
    return array();
}

/**
 * Everything the game files say about their maps, from one pass over the .pk3 files (cached by file date and size):
 * name(lower) => [name, file, modes[], src ('arena'|'readme'|''), long]
 * Game types come from scripts/*.arena (inside the .pk3 files, and loose in <folder>/scripts), and failing that from
 * a readme line inside a map's .pk3. A map with neither is still listed, with no game types.
 */
function mapPk3Catalog(array $dirs) {
    $cacheFile = rtrim(ECH_ROOT, '/\\') . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'map-index.json';
    $cache = is_file($cacheFile) ? json_decode((string) @file_get_contents($cacheFile), true) : array();
    if (!is_array($cache)) { $cache = array(); }
    $fresh = array(); $cat = array(); $arena = array();
    if (!class_exists('ZipArchive')) { return $cat; }
    @set_time_limit(180);
    foreach (mapPk3Files($dirs) as $f) {
        $m = (int) @filemtime($f); $s = (int) @filesize($f);
        if (isset($cache[$f]) && ($cache[$f]['v'] ?? 0) === 2 && $cache[$f]['m'] === $m && $cache[$f]['s'] === $s) { $e = $cache[$f]; }
        else {
            $e = array('v' => 2, 'm' => $m, 's' => $s, 'n' => array(), 'a' => array(), 'r' => array());
            $z = new ZipArchive();
            if ($z->open($f, ZipArchive::RDONLY) === true) {
                $n = min($z->numFiles, 20000);
                $texts = array();
                for ($i = 0; $i < $n; $i++) {
                    $nm = $z->getNameIndex($i);
                    if ($nm === false) { continue; }
                    if (preg_match('#^maps/([^/]+)\.bsp$#i', $nm, $mm)) { $e['n'][] = $mm[1]; }
                    elseif (preg_match('#^scripts/[^/]+\.arena$#i', $nm)) {
                        $st = $z->statIndex($i);
                        if ($st && $st['size'] > 0 && $st['size'] <= 262144) { $e['a'] = array_merge($e['a'], mapArenaParse((string) $z->getFromIndex($i))); }
                    } elseif (count($texts) < 4 && preg_match('#^(?:[^/]*readme[^/]*|maps/[^/]+|[^/]+)\.txt$#i', $nm) && !preg_match('#(shaderlist|mapcycle)#i', $nm)) {
                        $st = $z->statIndex($i);
                        if ($st && $st['size'] > 0 && $st['size'] <= 65536) { $texts[] = $i; }
                    }
                }
                if (count($e['n']) === 1 && !isset($e['a'][strtolower($e['n'][0])])) { // a readme is only trusted when the file holds one map
                    foreach ($texts as $i) {
                        $modes = mapReadmeModes((string) $z->getFromIndex($i));
                        if ($modes) { $e['r'] = $modes; break; }
                    }
                }
                $z->close();
            }
        }
        $fresh[$f] = $e;
        foreach ($e['a'] as $k => $a) { $arena[$k] = $a; } // later files override earlier ones, as the game does
        foreach ($e['n'] as $nm) {
            $k = strtolower($nm);
            if (!isset($cat[$k])) { $cat[$k] = array('name' => $nm, 'file' => $f, 'modes' => array(), 'src' => '', 'long' => '', 'r' => $e['r']); }
        }
    }
    if ($fresh !== $cache) { @file_put_contents($cacheFile, json_encode($fresh), LOCK_EX); }
    foreach ($dirs as $d) { // loose scripts/*.arena next to the game files override everything
        foreach ((array) @glob(rtrim($d, '/\\') . '/scripts/*.arena') as $af) {
            if (is_file($af) && filesize($af) <= 262144) { foreach (mapArenaParse((string) @file_get_contents($af)) as $k => $a) { $arena[$k] = $a; } }
        }
    }
    foreach ($cat as $k => &$c) {
        if (isset($arena[$k]) && $arena[$k]['modes']) { $c['modes'] = $arena[$k]['modes']; $c['src'] = 'arena'; }
        elseif ($c['r']) { $c['modes'] = $c['r']; $c['src'] = 'readme'; }
        if (isset($arena[$k]) && $arena[$k]['long'] !== '') { $c['long'] = $arena[$k]['long']; }
        unset($c['r']);
    }
    unset($c);
    ksort($cat);
    return $cat;
}

/** map name (lower case) => pk3 file, for every maps/*.bsp in the folders */
function mapPk3Index(array $dirs) {
    $map = array();
    foreach (mapPk3Catalog($dirs) as $k => $c) { $map[$k] = $c['file']; }
    return $map;
}

/** Drop the cached index (after a download) */
function mapPk3IndexForget() {
    @unlink(rtrim(ECH_ROOT, '/\\') . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'map-index.json');
}

/** Of these map names, the ones that are in none of the folders' .pk3 files */
function mapMissingFiles(array $names, array $dirs) {
    $have = mapPk3Index($dirs);
    $out = array();
    foreach ($names as $n) { if (!isset($have[strtolower($n)])) { $out[] = $n; } }
    return $out;
}

/**
 * Download one map from the configured sources into the game files folder.
 * Returns [ok, message, file?, tried => [[host, result]...]]
 */
function mapDownload($name, array $dirs) {
    $res = array('ok' => false, 'error' => '', 'tried' => array());
    $slug = strtolower((string) $name);
    if (!preg_match('/^[a-z0-9_\-]{1,50}$/', $slug)) { $res['error'] = 'This map name cannot be downloaded (only letters, numbers, _ and - are allowed).'; return $res; }
    $sources = mapDlSources();
    if (!$sources) { $res['error'] = 'No download site is set up. Add one under Settings > Maps & World Map > Map downloads.'; return $res; }
    $dir = mapDlTargetDir($dirs);
    if ($dir === '') { $res['error'] = 'The web server is not allowed to write to the game files folder' . ($dirs ? ' (' . $dirs[0] . ')' : '') . '. Give ' . mapcycleWebUser() . ' write access to it.'; return $res; }
    $final = $dir . DIRECTORY_SEPARATOR . $slug . '.pk3';
    if (file_exists($final)) { $res['error'] = basename($final) . ' is already in the game files folder, but it does not contain the map. Check or remove that file first.'; return $res; }
    if (!class_exists('ZipArchive')) { $res['error'] = 'The PHP zip extension is not installed (install php-zip), so the download cannot be checked.'; return $res; }

    $max = mapDlMaxBytes();
    foreach ($sources as $src) {
        $url = str_replace('{map}', rawurlencode($slug), $src);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $tmp = tempnam(sys_get_temp_dir(), 'mapdl');
        list($ok, $msg) = mapDlFetch($url, $tmp, $max);
        if ($ok) {
            $size = (int) @filesize($tmp);
            $z = new ZipArchive();
            if ($size < 1024 || $z->open($tmp, ZipArchive::RDONLY) !== true) { $ok = false; $msg = 'That is not a map file (not a zip archive).'; }
            else {
                $has = $z->locateName('maps/' . $slug . '.bsp', ZipArchive::FL_NOCASE) !== false;
                $z->close();
                if (!$has) { $ok = false; $msg = 'The file does not contain the map ' . $slug . '.'; }
            }
        }
        if ($ok) {
            $moved = @rename($tmp, $final);
            if (!$moved) { $moved = @copy($tmp, $final); @unlink($tmp); }
            if (!$moved) { @unlink($tmp); $res['tried'][] = array($host, 'Could not save into ' . $dir . '.'); $res['error'] = 'Could not save the file into ' . $dir . '. Check the permissions of that folder.'; return $res; }
            @chmod($final, 0644);
            mapPk3IndexForget();
            $res['tried'][] = array($host, 'ok');
            $res['ok'] = true; $res['file'] = basename($final); $res['dir'] = $dir; $res['size'] = $size; $res['host'] = $host;
            // use the picture inside it for the map tile, if the map has none
            $res['saved'] = mapPk3Extract($final, array(mapSlug($name) => $name), false)['saved'];
            return $res;
        }
        @unlink($tmp);
        $res['tried'][] = array($host, $msg);
    }
    $why = array();
    foreach ($res['tried'] as $t) { $why[] = $t[0] . ': ' . $t[1]; }
    $res['error'] = $slug . ' could not be downloaded. ' . implode(' ', $why);
    return $res;
}
