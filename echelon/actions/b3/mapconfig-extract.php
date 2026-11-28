<?php
/**
 * Fill in map pictures from the maps' own .pk3 files (their level shots). AJAX, JSON answer.
 *
 *  t=scan    look through the .pk3 files on the server (MAP_PK3_DIR, or the folder of the Mapcycle File)
 *  t=upload  take the pictures from an uploaded .pk3 / .zip (field "pk3")
 *  t=setdir  save the folder(s) that hold the .pk3 files (field "dirs", one path per line); scan=1 also extracts
 * Optional: id (only this map), replace=1 (replace pictures that exist already). Header X-CSRF-Token.
 */
$auth_name = 'mapconfig';
$b3_conn = true;
require '../../inc.php';

header('Content-Type: application/json; charset=utf-8');
function mcxJson($ok, $extra = array(), $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(array('ok' => $ok), $extra), JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mcxJson(false, array('error' => 'POST required.'), 405);
}
csrfCheck(true);

$t = $_POST['t'] ?? '';

if ($t === 'setdir') {
    $lines = preg_split('/\r\n|\r|\n|\|/', (string) ($_POST['dirs'] ?? ''));
    $clean = array();
    foreach ($lines as $l) {
        if (trim($l) === '') { continue; }
        list($p, $err) = mapPathClean($l);
        if ($p === null) { mcxJson(false, array('error' => htmlspecialchars(trim($l), ENT_QUOTES) . ': ' . $err)); }
        $clean[] = $p;
    }
    $clean = array_values(array_unique($clean));
    $value = implode('|', $clean);
    if (strlen($value) > 250) { mcxJson(false, array('error' => 'Too many folders: the list has to fit in 250 characters.')); }
    $dbl->saveSetting('map_pk3_dir', $value);
    $config['cosmos']['map_pk3_dir'] = $value; // so the scan below sees it
    $dirs = array();
    foreach ($clean as $d) { $dirs[] = array('path' => $d, 'pk3' => count((array) glob($d . '/*.[pP][kK]3'))); }
    if (!empty($_POST['scan'])) {
        $names = array();
        foreach ($db->getMapconfigs() as $m) { $names[] = $m['mapname']; }
        $r = mapPk3Scan($clean, $names, false);
        mcxJson(true, array('dirs' => $dirs, 'saved' => $r['saved'], 'missing' => $r['missing'], 'errors' => $r['errors'], 'files' => $r['files']));
    }
    mcxJson(true, array('dirs' => $dirs));
}

// which maps may get a picture
$names = array();
$id = $_POST['id'] ?? '';
if ($id !== '' && $id !== '0') {
    $map = (is_string($id) && ctype_digit($id)) ? $db->getMapconfig((int) $id) : null;
    if (!$map) { mcxJson(false, array('error' => 'That map no longer exists.'), 404); }
    $names[] = $map['mapname'];
} else {
    foreach ($db->getMapconfigs() as $m) { $names[] = $m['mapname']; }
}
$replace = !empty($_POST['replace']);

if ($t === 'scan') {
    $servers = $dbl->getServers($game);
    $dirs = mapPk3Dirs(!empty($servers) ? trim((string) $servers[0]['mapcyclefile']) : '');
    if (!$dirs) {
        mcxJson(false, array('error' => 'No readable map folder was found. Set MAP_PK3_DIR in inc/config.php to the folder that holds your .pk3 files, or upload a .pk3 instead.'));
    }
    $r = mapPk3Scan($dirs, $names, $replace);
    mcxJson(true, array('saved' => $r['saved'], 'missing' => $r['missing'], 'errors' => $r['errors'], 'files' => $r['files']));
}

if ($t === 'upload') {
    $f = $_FILES['pk3'] ?? null;
    if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        mcxJson(false, array('error' => 'The file was not received. Large .pk3 files can exceed the upload_max_filesize / post_max_size limits of this server (currently ' . ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size') . ').'), 400);
    }
    if (!preg_match('/\.(pk3|zip)$/i', (string) $f['name']) || !is_uploaded_file($f['tmp_name'])) {
        mcxJson(false, array('error' => 'Please choose a .pk3 (or .zip) file.'), 400);
    }
    $wanted = array();
    foreach ($names as $n) { $wanted[mapSlug($n)] = $n; }
    @set_time_limit(120);
    $r = mapPk3Extract($f['tmp_name'], $wanted, $replace);
    mcxJson(true, array('saved' => $r['saved'], 'skipped' => $r['skipped'], 'errors' => $r['errors']));
}

mcxJson(false, array('error' => 'Unknown command.'), 400);
