<?php
/**
 * Download missing maps into the game files folder. AJAX, JSON answer. Needs the mapconfig permission.
 *
 *  t=status             which maps in Map Config are in none of the game files' .pk3 (field "name" = only that map)
 *  t=get   name         download that map from the configured sites
 * Header X-CSRF-Token. The sites themselves are set under Settings > Maps & World Map (manage_settings).
 */
$auth_name = 'mapconfig';
$b3_conn = true;
require '../../inc.php';

header('Content-Type: application/json; charset=utf-8');
function mdlJson($ok, $extra = array(), $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(array('ok' => $ok), $extra), JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { mdlJson(false, array('error' => 'POST required.'), 405); }
csrfCheck(true);

$t = $_POST['t'] ?? '';
$servers = $dbl->getServers($game);
$dirs = mapPk3Dirs(!empty($servers) ? trim((string) $servers[0]['mapcyclefile']) : '');
$sources = mapDlSources();

if ($t === 'status') {
    $info = array('sources' => count($sources), 'folder' => $dirs ? $dirs[0] : '', 'writable' => mapDlTargetDir($dirs) !== '', 'curl' => function_exists('curl_init'), 'zip' => class_exists('ZipArchive'));
    if (!$dirs) { mdlJson(true, $info + array('missing' => array())); }
    $names = array();
    $one = (string) ($_POST['name'] ?? '');
    if ($one !== '') { $names[] = $one; }
    else { foreach ($db->getMapconfigs() as $m) { $names[] = $m['mapname']; } }
    mdlJson(true, $info + array('missing' => mapMissingFiles($names, $dirs)));
}

if ($t === 'get') {
    $name = trim((string) ($_POST['name'] ?? ''));
    if (!mapNameValid($name)) { mdlJson(false, array('error' => 'That is not a valid map name.'), 400); }
    $known = false;
    foreach ($db->getMapconfigs() as $m) { if (strcasecmp($m['mapname'], $name) === 0) { $known = true; $name = $m['mapname']; break; } }
    if (!$known) { mdlJson(false, array('error' => 'That map is not in Map Config.'), 404); } // only maps the admins listed can be fetched
    if (!$dirs) { mdlJson(false, array('error' => 'Set the game files folder first (Settings > Maps & World Map).')); }
    @set_time_limit(300);
    $r = mapDownload($name, $dirs);
    echLog('mapconfig', 'Map download ' . $name . ': ' . ($r['ok'] ? 'saved ' . $r['file'] . ' from ' . $r['host'] : 'failed (' . $r['error'] . ')'));
    if (!$r['ok']) { mdlJson(false, array('error' => $r['error'], 'tried' => $r['tried'])); }
    mdlJson(true, array('file' => $r['file'], 'host' => $r['host'], 'size_mb' => round($r['size'] / 1048576, 1), 'picture' => !empty($r['saved'])));
}

mdlJson(false, array('error' => 'Unknown command.'), 400);
