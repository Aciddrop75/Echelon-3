<?php
/**
 * World map / GeoLite2 setup (AJAX, JSON answer). Needs the manage_settings permission.
 *  t=geo_save      provider (geolite2|legacy), account, key  - save the choice and MaxMind credentials
 *  t=geo_download  [account, key]                            - download and install the GeoLite2 City database
 *  t=geo_remove                                              - delete the installed GeoLite2 database
 *  t=geo_auto_run                                            - background update when automatic updating is on and the database is old
 *  t=dl_save       sources, lan, max_mb                       - download sites for missing maps
 *  t=dl_test       source, map                                - check a site without saving anything
 *  t=style_save    style, switcher, custom_url, custom_attr  - map style for the World Map
 */
$auth_name = 'manage_settings';
require '../inc.php';

header('Content-Type: application/json; charset=utf-8');
function gsJson($ok, $extra = array(), $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(array('ok' => $ok), $extra));
    exit;
}
function gsStatus() {
    $i = geoMmdbInfo();
    return array(
        'installed' => $i !== null,
        'built' => $i ? gmdate('Y-m-d', $i['built']) : null,
        'size_mb' => $i ? round($i['size'] / 1048576, 1) : null,
        'provider' => geoProvider(),
        'updated' => geoLastUpdated() ? gmdate('Y-m-d', geoLastUpdated()) : null,
        'auto' => geoAutoEnabled(),
        'last_error' => (string) geoCfg('geo_last_error', ''),
    );
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    gsJson(false, array('error' => 'POST required.'), 405);
}
csrfCheck(true);

$t = $_POST['t'] ?? '';
$key_in = trim((string) ($_POST['key'] ?? ''));
$acc_in = trim((string) ($_POST['account'] ?? ''));
$masked = ($key_in !== '' && preg_match('/^\x{2022}+/u', $key_in)); // the page shows the saved key as dots; that means "unchanged"

if ($t === 'geo_save' || $t === 'geo_download') {
    $provider = $_POST['provider'] ?? geoCfg('geo_provider', 'geolite2');
    if (!in_array($provider, array('geolite2', 'legacy'), true)) { gsJson(false, array('error' => 'Unknown provider.'), 400); }

    if ($t === 'geo_save') {
        $dbl->saveSetting('geo_provider', $provider);
        if ($provider === 'geolite2' && $acc_in !== '') {
            if (!preg_match('/^\d{1,12}$/', $acc_in)) { gsJson(false, array('error' => 'The Account ID is the number shown at the top of your MaxMind account page.')); }
            $dbl->saveSetting('maxmind_account', $acc_in);
        }
        if ($provider === 'geolite2' && $key_in !== '' && !$masked) {
            if (!preg_match('/^[A-Za-z0-9_]{16,80}$/', $key_in)) { gsJson(false, array('error' => 'That does not look like a MaxMind license key (letters, numbers and underscores only).')); }
            $dbl->saveSetting('maxmind_key', $key_in);
        }
        if (isset($_POST['auto'])) { $dbl->saveSetting('geo_auto', $_POST['auto'] === '1' ? '1' : '0'); $config['cosmos']['geo_auto'] = $_POST['auto'] === '1' ? '1' : '0'; }
        $config['cosmos']['geo_provider'] = $provider;
        gsJson(true, array('status' => gsStatus()));
    }

    // download: use what was typed, else what is saved
    $account = $acc_in !== '' ? $acc_in : (string) geoCfg('maxmind_account', '');
    $key = ($key_in !== '' && !$masked) ? $key_in : (string) geoCfg('maxmind_key', '');
    list($ok, $msg) = geoDownload($account, $key);
    if (!$ok) { gsJson(false, array('error' => $msg)); }
    // the credentials worked, so keep them (and select GeoLite2) for the next update
    $dbl->saveSetting('geo_provider', 'geolite2');
    $dbl->saveSetting('maxmind_account', $account);
    $dbl->saveSetting('maxmind_key', $key);
    $dbl->saveSetting('geo_updated', (string) time());
    $dbl->saveSetting('geo_last_error', '');
    if (geoCfg('geo_auto', '') === '') { $dbl->saveSetting('geo_auto', '1'); $config['cosmos']['geo_auto'] = '1'; } // first successful download: keep it fresh from now on
    $config['cosmos']['maxmind_account'] = $account; $config['cosmos']['maxmind_key'] = $key;
    @unlink(ECH_ROOT . 'lib/worldmap-cache.json');
    gsJson(true, array('message' => $msg, 'status' => gsStatus()));
}

if ($t === 'geo_auto_run') {
    if (!geoUpdateDue()) { gsJson(true, array('ran' => false)); }
    list($ok, $msg) = geoUpdateNow();
    $config['cosmos']['geo_last_error'] = $ok ? '' : $msg;
    gsJson(true, array('ran' => true, 'updated' => $ok, 'message' => $msg, 'status' => gsStatus()));
}

if ($t === 'dl_save') {
    $clean = array();
    foreach (preg_split('/\r\n|\r|\n/', (string) ($_POST['sources'] ?? '')) as $l) {
        if (trim($l) === '') { continue; }
        list($ok, $err) = mapDlSourceClean($l);
        if ($ok === null) { gsJson(false, array('error' => htmlspecialchars(mb_substr(trim($l), 0, 80), ENT_QUOTES) . ' ' . $err)); }
        $clean[] = $ok;
    }
    $clean = array_values(array_unique($clean));
    if (count($clean) > 8) { gsJson(false, array('error' => 'Eight download sites at most.')); }
    $lan = !empty($_POST['lan']) && $_POST['lan'] === '1' ? '1' : '0';
    $mb = (int) ($_POST['max_mb'] ?? 200);
    if ($mb < 5 || $mb > 1000) { gsJson(false, array('error' => 'The size limit must be between 5 and 1000 MB.')); }
    $dbl->saveSetting('map_dl_sources', implode("\n", $clean));
    $dbl->saveSetting('map_dl_lan', $lan);
    $dbl->saveSetting('map_dl_max_mb', (string) $mb);
    echLog('mapconfig', 'Map download sites saved (' . count($clean) . ')');
    gsJson(true, array('count' => count($clean)));
}

if ($t === 'dl_test') {
    list($src, $err) = mapDlSourceClean((string) ($_POST['source'] ?? ''));
    if ($src === null) { gsJson(false, array('error' => 'That address ' . $err)); }
    $map = strtolower(trim((string) ($_POST['map'] ?? 'ut4_turnpike')));
    if (!preg_match('/^[a-z0-9_\-]{1,50}$/', $map)) { gsJson(false, array('error' => 'Enter a map name such as ut4_turnpike.')); }
    list($ok, $msg, $code) = mapDlFetch(str_replace('{map}', rawurlencode($map), $src), null, mapDlMaxBytes());
    gsJson(true, array('found' => $ok, 'message' => $ok ? 'The site has ' . $map . '.' : $msg));
}

if ($t === 'style_save') {
    $style = (string) ($_POST['style'] ?? 'osm');
    $url = trim((string) ($_POST['custom_url'] ?? ''));
    $attr = trim((string) ($_POST['custom_attr'] ?? ''));
    if ($url !== '' && !geoValidTileUrl($url)) {
        gsJson(false, array('error' => 'The tile address must start with http:// or https:// and contain {z}, {x} and {y}, for example https://tiles.example.com/{z}/{x}/{y}.png'));
    }
    if (strlen($attr) > 200 || preg_match('/[<>]/', $attr)) { gsJson(false, array('error' => 'The credit line is plain text (up to 200 characters, no < or >).')); }
    // check the choice first, so a refused request changes nothing
    if ($style === 'custom') {
        if ($url === '') { gsJson(false, array('error' => 'Enter a tile address for the Custom style first.')); }
    } elseif (!isset(geoMapStyles()[$style])) {
        gsJson(false, array('error' => 'Unknown map style.'));
    }
    $dbl->saveSetting('map_custom_url', $url);
    $dbl->saveSetting('map_custom_attr', $attr);
    $config['cosmos']['map_custom_url'] = $url; $config['cosmos']['map_custom_attr'] = $attr;
    $dbl->saveSetting('map_style', $style);
    $dbl->saveSetting('map_switcher', (($_POST['switcher'] ?? '1') === '0') ? '0' : '1');
    gsJson(true, array('style' => $style));
}

if ($t === 'geo_remove') {
    @unlink(geoMmdbPath());
    @unlink(ECH_ROOT . 'lib/worldmap-cache.json');
    $dbl->saveSetting('maxmind_key', '');
    gsJson(true, array('status' => gsStatus()));
}

gsJson(false, array('error' => 'Unknown command.'), 400);
