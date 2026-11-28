<?php
/**
 * IP geolocation for the World Map.
 *
 * Two providers:
 *  - geolite2: MaxMind's free GeoLite2-City database (lib/GeoLite2-City.mmdb). City level. Needs a free MaxMind
 *              account; the Settings > Maps page walks the admin through getting a license key and downloads
 *              the database for them.
 *  - legacy:   the old GeoIP.dat country database (lib/GeoIP.dat), for sites that still have one.
 */

if ('geo.php' === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    die('Please do not load this page directly. Thanks!');
}

function geoCfg($key, $default = '') {
    global $config;
    return $config['cosmos'][$key] ?? $default;
}

function geoMmdbPath() { return ECH_ROOT . 'lib/GeoLite2-City.mmdb'; }
function geoLegacyPath() { return ECH_ROOT . 'lib/GeoIP.dat'; }

/** [ISO code => [lat, lng, name]] */
function geoCentroids() {
    static $c = null;
    if ($c === null) {
        $c = json_decode((string) @file_get_contents(ECH_ROOT . 'lib/country-centroids.json'), true);
        if (!is_array($c)) { $c = array(); }
    }
    return $c;
}

function geoReader($path = null) {
    require_once ECH_ROOT . 'lib/maxmind-db/autoload.php';
    return new \MaxMind\Db\Reader($path ?: geoMmdbPath());
}

/** Facts about the installed GeoLite2 database, or null when there is none / it is unreadable */
function geoMmdbInfo() {
    $p = geoMmdbPath();
    if (!is_file($p)) { return null; }
    try {
        $r = geoReader();
        $m = $r->metadata();
        $info = array('size' => (int) filesize($p), 'built' => (int) $m->buildEpoch, 'type' => (string) $m->databaseType, 'mtime' => (int) filemtime($p));
        $r->close();
        return $info;
    } catch (Throwable $e) {
        return null;
    }
}

/** Which provider the map should use right now (null = none is usable yet) */
function geoProvider() {
    $pref = geoCfg('geo_provider', '');
    $mm = geoMmdbInfo() !== null;
    $legacy = is_file(geoLegacyPath());
    if ($pref === 'geolite2') { return $mm ? 'geolite2' : null; }
    if ($pref === 'legacy') { return $legacy ? 'legacy' : null; }
    return $mm ? 'geolite2' : ($legacy ? 'legacy' : null);
}

/**
 * Turn [ip => number of players] into map points.
 *
 * @return array [points => [[lat,lng,label,n,cc]...], countries => [cc => [name, n]], unknown => int]
 */
function geoLocate(array $ips, $provider) {
    $cent = geoCentroids();
    $points = array();
    $countries = array();
    $unknown = 0;

    $add = function ($lat, $lng, $label, $cc, $cname, $n) use (&$points, &$countries) {
        $key = round($lat, 1) . ',' . round($lng, 1); // merge towns that sit on top of each other
        if (!isset($points[$key])) { $points[$key] = array('lat' => round($lat, 3), 'lng' => round($lng, 3), 'label' => $label, 'n' => 0, 'cc' => $cc); }
        $points[$key]['n'] += $n;
        $ck = $cc !== '' ? $cc : '??';
        if (!isset($countries[$ck])) { $countries[$ck] = array('name' => $cname, 'n' => 0); }
        $countries[$ck]['n'] += $n;
    };

    if ($provider === 'geolite2') {
        $r = geoReader();
        foreach ($ips as $ip => $n) {
            try { $rec = filter_var($ip, FILTER_VALIDATE_IP) ? $r->get($ip) : null; } catch (Throwable $e) { $rec = null; }
            if (!is_array($rec)) { $unknown += $n; continue; }
            $cc = (string) ($rec['country']['iso_code'] ?? ($rec['registered_country']['iso_code'] ?? ''));
            $cname = (string) ($rec['country']['names']['en'] ?? ($cent[$cc][2] ?? $cc));
            $lat = $rec['location']['latitude'] ?? null;
            $lng = $rec['location']['longitude'] ?? null;
            $city = (string) ($rec['city']['names']['en'] ?? '');
            $sub = (string) ($rec['subdivisions'][0]['names']['en'] ?? '');
            if ($lat === null || $lng === null) {
                if (!isset($cent[$cc])) { $unknown += $n; continue; }
                list($lat, $lng) = $cent[$cc];
                $city = ''; $sub = '';
            }
            $label = $city !== '' ? $city . ($sub !== '' && $sub !== $city ? ', ' . $sub : '') . ', ' . $cname : $cname;
            $add((float) $lat, (float) $lng, $label, $cc, $cname, $n);
        }
        $r->close();
    } elseif ($provider === 'legacy') {
        require_once ECH_ROOT . 'inc/geoip.php';
        $gi = geoip_open(geoLegacyPath(), GEOIP_STANDARD);
        foreach ($ips as $ip => $n) {
            $cc = (string) geoip_country_code_by_addr($gi, $ip);
            if ($cc === '' || !isset($cent[$cc])) { $unknown += $n; continue; }
            $add($cent[$cc][0], $cent[$cc][1], $cent[$cc][2], $cc, $cent[$cc][2], $n);
        }
        geoip_close($gi);
    }

    uasort($points, function ($a, $b) { return $b['n'] <=> $a['n']; });
    uasort($countries, function ($a, $b) { return $b['n'] <=> $a['n']; });
    return array('points' => array_slice(array_values($points), 0, 3000), 'countries' => $countries, 'unknown' => $unknown);
}

/** Ten minute cache of the located result, so reloading the map does not look every IP up again */
function geoCached($key, callable $make, $ttl = 600) {
    $file = ECH_ROOT . 'lib/worldmap-cache.json';
    $all = json_decode((string) @file_get_contents($file), true);
    if (!is_array($all)) { $all = array(); }
    if (isset($all[$key]) && (time() - $all[$key]['t']) < $ttl) { return $all[$key]['d']; }
    $d = $make();
    $all[$key] = array('t' => time(), 'd' => $d);
    uasort($all, function ($a, $b) { return $b['t'] <=> $a['t']; });
    @file_put_contents($file, json_encode(array_slice($all, 0, 6, true)), LOCK_EX);
    return $d;
}

/* ------------------------------------------------------------------------ downloading GeoLite2 */

/** Fetch $url into $dest with HTTP basic auth. Returns [bool ok, string message] */
function geoHttpGet($url, $user, $pass, $dest) {
    $https_only = (strpos($url, 'https://') === 0);
    if (function_exists('curl_init')) {
        $fp = fopen($dest, 'wb');
        $ch = curl_init($url);
        $opts = array(
            CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERPWD => $user . ':' . $pass, CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 280, CURLOPT_USERAGENT => 'Echelon-3',
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($r, $total, $got) { return $got > 300 * 1024 * 1024 ? 1 : 0; },
        );
        if ($https_only) { $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS; $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS; }
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($ok === false) { return array(false, 'Could not reach MaxMind: ' . ($err !== '' ? $err : 'connection failed') . '. The server must be allowed to connect to download.maxmind.com.'); }
        return geoHttpStatus($code);
    }

    // no cURL: follow redirects by hand so the password is only ever sent to the first host
    $auth = 'Authorization: Basic ' . base64_encode($user . ':' . $pass);
    $host0 = parse_url($url, PHP_URL_HOST);
    for ($i = 0; $i < 6; $i++) {
        $headers = array('User-Agent: Echelon-3');
        if (parse_url($url, PHP_URL_HOST) === $host0) { $headers[] = $auth; }
        $ctx = stream_context_create(array('http' => array('method' => 'GET', 'header' => implode("\r\n", $headers), 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 60)));
        $in = @fopen($url, 'rb', false, $ctx);
        if (!$in) { return array(false, 'Could not reach MaxMind. The server must be allowed to connect to download.maxmind.com (and "allow_url_fopen" must be on, or install the PHP curl extension).'); }
        $code = 0; $loc = '';
        foreach ((array) ($http_response_header ?? array()) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int) $m[1]; }
            if (stripos($h, 'Location:') === 0) { $loc = trim(substr($h, 9)); }
        }
        if (in_array($code, array(301, 302, 303, 307, 308), true) && $loc !== '') { fclose($in); $url = $loc; continue; }
        if ($code !== 200) { fclose($in); return geoHttpStatus($code); }
        $out = fopen($dest, 'wb');
        $copied = stream_copy_to_stream($in, $out, 300 * 1024 * 1024);
        fclose($in); fclose($out);
        return $copied > 0 ? array(true, '') : array(false, 'MaxMind sent an empty answer.');
    }
    return array(false, 'Too many redirects.');
}

function geoHttpStatus($code) {
    if ($code === 200) { return array(true, ''); }
    if ($code === 401) { return array(false, 'MaxMind rejected the Account ID or License key. Check both and try again.'); }
    if ($code === 403) { return array(false, 'MaxMind refused the download. Make sure the license key is active and that your account has accepted the GeoLite2 license.'); }
    if ($code === 429) { return array(false, 'MaxMind says the daily download limit has been reached. Try again tomorrow.'); }
    return array(false, 'MaxMind answered with HTTP ' . $code . '.');
}

/** Pull the single .mmdb file out of a MaxMind .tar.gz without unpacking everything */
function geoExtractMmdb($tgz, $dest) {
    $gz = @gzopen($tgz, 'rb');
    if (!$gz) { return array(false, 'The download is not a valid archive.'); }
    while (true) {
        $h = gzread($gz, 512);
        if ($h === false || strlen($h) < 512 || trim($h, "\0") === '') { break; }
        $name = rtrim(substr($h, 0, 100), "\0");
        $prefix = rtrim(substr($h, 345, 155), "\0");
        if ($prefix !== '') { $name = $prefix . '/' . $name; }
        $size = (int) octdec(trim(substr($h, 124, 12), "\0 "));
        $type = $h[156];
        if (($type === '0' || $type === "\0") && preg_match('/\.mmdb$/', $name)) {
            if ($size < 4096 || $size > 500 * 1024 * 1024) { gzclose($gz); return array(false, 'The database in the archive has an unexpected size.'); }
            $out = fopen($dest, 'wb');
            $left = $size;
            while ($left > 0) {
                $chunk = gzread($gz, min(1048576, $left));
                if ($chunk === false || $chunk === '') { break; }
                fwrite($out, $chunk);
                $left -= strlen($chunk);
            }
            fclose($out); gzclose($gz);
            return $left === 0 ? array(true, '') : array(false, 'The archive ended early. Please try the download again.');
        }
        gzseek($gz, gztell($gz) + (int) (ceil($size / 512) * 512));
    }
    gzclose($gz);
    return array(false, 'No database was found in the download.');
}

/**
 * Download GeoLite2-City with the admin's MaxMind account and install it as lib/GeoLite2-City.mmdb.
 * @return array [bool ok, string message]
 */
function geoDownload($account, $key) {
    @set_time_limit(300);
    $account = trim((string) $account);
    $key = trim((string) $key);
    if (!preg_match('/^\d{1,12}$/', $account)) { return array(false, 'The Account ID is the number shown at the top of your MaxMind account page.'); }
    if (!preg_match('/^[A-Za-z0-9_]{16,80}$/', $key)) { return array(false, 'That does not look like a MaxMind license key (letters, numbers and underscores only).'); }
    $dir = ECH_ROOT . 'lib/';
    if (!is_writable($dir)) { return array(false, 'The folder lib/ is not writable by the web server user, so the database cannot be saved there.'); }

    $base = defined('GEO_DOWNLOAD_BASE') ? rtrim(GEO_DOWNLOAD_BASE, '/') : 'https://download.maxmind.com';
    $tgz = tempnam(sys_get_temp_dir(), 'geo');
    $part = $dir . 'GeoLite2-City.mmdb.part';
    try {
        list($ok, $msg) = geoHttpGet($base . '/geoip/databases/GeoLite2-City/download?suffix=tar.gz', $account, $key, $tgz);
        if (!$ok) { return array(false, $msg); }
        if (@file_get_contents($tgz, false, null, 0, 2) !== "\x1f\x8b") { return array(false, 'MaxMind did not send a database archive.'); }
        list($ok, $msg) = geoExtractMmdb($tgz, $part);
        if (!$ok) { return array(false, $msg); }
        try {
            $r = geoReader($part);
            $type = (string) $r->metadata()->databaseType;
            $r->close();
        } catch (Throwable $e) {
            return array(false, 'The downloaded database could not be read.');
        }
        if (stripos($type, 'City') === false) { return array(false, 'The downloaded database is not a City database.'); }
        if (!@rename($part, geoMmdbPath())) { return array(false, 'The database could not be saved in lib/.'); }
        return array(true, 'The GeoLite2 City database was installed.');
    } finally {
        @unlink($tgz);
        @unlink($part);
    }
}

/* ------------------------------------------------------------------------------------------------
 * Keeping the GeoLite2 database up to date
 * ---------------------------------------------------------------------------------------------- */

/** Seconds a downloaded database counts as "fresh". MaxMind publishes on Tuesdays and Fridays. */
function geoFreshSeconds() { return 3 * 86400; }

/** Unix time the installed database was last downloaded (0 = none) */
function geoLastUpdated() {
    $p = geoMmdbPath();
    return is_file($p) ? (int) @filemtime($p) : 0;
}

/** Is automatic updating switched on, with saved MaxMind details? */
function geoAutoEnabled() {
    return geoCfg('geo_auto', '0') === '1' && (string) geoCfg('maxmind_account', '') !== '' && (string) geoCfg('maxmind_key', '') !== '';
}

/** True when an automatic update should run now (database is old, and a failed try is not repeated for 12 hours) */
function geoUpdateDue($force = false) {
    if (!geoAutoEnabled() && !$force) { return false; }
    if ((string) geoCfg('maxmind_account', '') === '' || (string) geoCfg('maxmind_key', '') === '') { return false; }
    $now = time();
    if ($now - (int) geoCfg('geo_last_try', 0) < 12 * 3600 && !$force) { return false; }
    return $force || geoLastUpdated() === 0 || ($now - geoLastUpdated()) > geoFreshSeconds();
}

/**
 * Download the database with the MaxMind details saved in Settings. Only one update runs at a time.
 * Used by the cron script, by the World Map (in the background, for administrators) and by "Update now".
 *
 * @return array [bool ok, string message]
 */
function geoUpdateNow() {
    global $dbl;
    $account = (string) geoCfg('maxmind_account', '');
    $key = (string) geoCfg('maxmind_key', '');
    if ($account === '' || $key === '') { return array(false, 'No MaxMind account details are saved yet (Settings > Maps & World Map).'); }

    $lock = @fopen(ECH_ROOT . 'lib/geo-update.lock', 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return array(false, 'An update is already running.');
    }
    try {
        $dbl->saveSetting('geo_last_try', (string) time());
        list($ok, $msg) = geoDownload($account, $key);
        if ($ok) {
            $dbl->saveSetting('geo_updated', (string) time());
            $dbl->saveSetting('geo_last_error', '');
            @unlink(ECH_ROOT . 'lib/worldmap-cache.json');
        } else {
            $dbl->saveSetting('geo_last_error', function_exists('mb_substr') ? mb_substr($msg, 0, 200) : substr($msg, 0, 200));
            echLog('warning', 'GeoLite2 update failed: ' . $msg);
        }
        return array($ok, $msg);
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

/* ------------------------------------------------------------------------------------------------
 * Map styles (Leaflet tile layers)
 * ---------------------------------------------------------------------------------------------- */

/**
 * The built-in map styles. 'attr' is trusted HTML written here; the credits shown on the map are required
 * by the tile providers' terms, so they are never left out. None of these needs an account or an API key.
 */
function geoMapStyles() {
    $osm = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors';
    return array(
        'osm' => array('name' => 'Standard', 'note' => 'OpenStreetMap', 'url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', 'attr' => $osm, 'max' => 19, 'sub' => 'abc', 'dark' => false),
        'osm_hot' => array('name' => 'Humanitarian', 'note' => 'OpenStreetMap France, bold colours', 'url' => 'https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', 'attr' => $osm . ', tiles by <a href="https://www.hotosm.org/" target="_blank" rel="noopener">Humanitarian OpenStreetMap Team</a>, hosted by OpenStreetMap France', 'max' => 19, 'sub' => 'abc', 'dark' => false),
        'opentopo' => array('name' => 'Topographic', 'note' => 'OpenTopoMap, terrain and elevation', 'url' => 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', 'attr' => 'Map data: ' . $osm . ', SRTM | Style: <a href="https://opentopomap.org" target="_blank" rel="noopener">OpenTopoMap</a> (<a href="https://creativecommons.org/licenses/by-sa/3.0/" target="_blank" rel="noopener">CC-BY-SA</a>)', 'max' => 17, 'sub' => 'abc', 'dark' => false),
        'esri_imagery' => array('name' => 'Satellite', 'note' => 'Esri World Imagery', 'url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', 'attr' => 'Tiles &copy; Esri &mdash; Source: Esri, Maxar, Earthstar Geographics, and the GIS User Community', 'max' => 18, 'sub' => '', 'dark' => true),
    );
}

/** A tile URL must be http(s), hold {z}, {x} and {y}, and contain nothing that could break out of the page data. */
function geoValidTileUrl($u) {
    $u = trim((string) $u);
    if ($u === '' || strlen($u) > 240) { return false; }
    if (!preg_match('#^https?://[A-Za-z0-9._~:/?\#\[\]@!$&()*+,;=%{}-]+$#', $u)) { return false; }
    foreach (array('{z}', '{x}', '{y}') as $p) { if (strpos($u, $p) === false) { return false; } }
    return true;
}

/** The administrator's own tile source (e.g. a MapTiler / Thunderforest / Stadia URL with their key, or a self-hosted server) */
function geoCustomStyle() {
    $u = trim((string) geoCfg('map_custom_url', ''));
    if (!geoValidTileUrl($u)) { return null; }
    $attr = trim((string) geoCfg('map_custom_attr', ''));
    return array('name' => 'Custom', 'note' => 'Your own tile server', 'url' => $u, 'attr' => htmlspecialchars($attr !== '' ? $attr : 'Map tiles', ENT_QUOTES), 'max' => 19, 'sub' => 'abc', 'dark' => false);
}

/** Built-in styles plus the custom one when set: [id => style] */
function geoStyleList() {
    $s = geoMapStyles();
    $c = geoCustomStyle();
    if ($c) { $s['custom'] = $c; }
    return $s;
}

function geoDefaultStyle() {
    $list = geoStyleList();
    $id = (string) geoCfg('map_style', 'osm');
    return isset($list[$id]) ? $id : 'osm';
}

/** Can people switch styles on the map themselves? (default yes) */
function geoSwitcherOn() { return geoCfg('map_switcher', '1') !== '0'; }
