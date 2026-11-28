<?php
/**
 * Maps found in the game files: add them to Map Config and read their game types from the .arena files. AJAX, JSON answer.
 *
 *  t=import     [names[]]  add maps found in the game files that Map Config does not have yet (all of them without names)
 *  t=fillmodes             give maps that have no game type the ones their .arena file (or readme) lists
 *  t=modes      name       the game types the game files list for one map
 * Header X-CSRF-Token. Needs the mapconfig permission.
 */
$auth_name = 'mapconfig';
$b3_conn = true;
require '../../inc.php';

header('Content-Type: application/json; charset=utf-8');
function mciJson($ok, $extra = array(), $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(array('ok' => $ok), $extra), JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { mciJson(false, array('error' => 'POST required.'), 405); }
csrfCheck(true);

$t = $_POST['t'] ?? '';
$servers = $dbl->getServers($game);
$dirs = mapPk3Dirs(!empty($servers) ? trim((string) $servers[0]['mapcyclefile']) : '');
if (!$dirs) { mciJson(false, array('error' => 'Set the game files folder first (Settings > Maps & World Map).')); }
@set_time_limit(300);
$cat = mapPk3Catalog($dirs);

if ($t === 'modes') {
    $k = strtolower(trim((string) ($_POST['name'] ?? '')));
    if (!isset($cat[$k])) { mciJson(true, array('found' => false, 'modes' => array())); }
    mciJson(true, array('found' => true, 'modes' => $cat[$k]['modes'], 'src' => $cat[$k]['src'], 'file' => basename($cat[$k]['file'])));
}

$have = array();
foreach ($db->getMapconfigs() as $m) { $have[strtolower($m['mapname'])] = $m; }

if ($t === 'import') {
    $want = $_POST['names'] ?? null;
    $pick = array();
    if (is_array($want)) { foreach ($want as $n) { if (is_string($n)) { $pick[strtolower(trim($n))] = true; } } }
    $added = array(); $skipped = 0;
    foreach ($cat as $k => $c) {
        if (isset($have[$k])) { continue; }
        if ($pick && !isset($pick[$k])) { continue; }
        if (!mapNameValid($c['name'])) { $skipped++; continue; }
        $modes = implode(',', array_slice($c['modes'], 0, 12));
        if ($db->addMapconfig($c['name'], 8, 0, '0', 800, 0, '', 0, '2000-01-01', 20, $modes, 0)) { $added[] = $c['name']; }
    }
    $pics = 0;
    if ($added) {
        try { $pics = count(mapPk3Scan($dirs, $added)['saved']); } catch (Throwable $e) { /* maps are added either way */ }
    }
    echLog('mapconfig', 'Imported ' . count($added) . ' maps from the game files');
    mciJson(true, array('added' => count($added), 'skipped' => $skipped, 'pictures' => $pics));
}

if ($t === 'fillmodes') {
    $n = 0;
    foreach ($have as $k => $m) {
        if (trim((string) $m['gamemodes']) !== '' || !isset($cat[$k]) || !$cat[$k]['modes']) { continue; }
        $db->setMapconfigModes((int) $m['id'], implode(',', array_slice($cat[$k]['modes'], 0, 12)));
        $n++;
    }
    echLog('mapconfig', 'Game types filled in for ' . $n . ' maps from the game files');
    mciJson(true, array('filled' => $n));
}

mciJson(false, array('error' => 'Unknown command.'), 400);
