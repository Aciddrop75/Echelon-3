<?php
/**
 * Mapcycle file setup (AJAX, JSON answer). Needs the manage_settings permission.
 *  t=status                  every slot and what it points to
 *  t=browse   path           list a folder on the server (folders and .txt/.cfg files)
 *  t=link     slot, path     link mapcycle/<slot>.txt to the chosen file
 *  t=unlink   slot           remove that link
 *  t=custom_add    name, code?   add a custom game type (its own mapcycle file)
 *  t=custom_rename slot, name    rename a custom game type
 *  t=custom_remove slot          remove a custom game type and its link
 */
$auth_name = 'manage_settings';
require '../inc.php';

header('Content-Type: application/json; charset=utf-8');
function mcfJson($ok, $extra = array(), $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(array('ok' => $ok), $extra), JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { mcfJson(false, array('error' => 'POST required.'), 405); }
csrfCheck(true);

$servers = $dbl->getServers($game);
$serverFile = !empty($servers) ? trim((string) $servers[0]['mapcyclefile']) : '';
$t = $_POST['t'] ?? '';

if ($t === 'status') {
    mcfJson(true, array('slots' => mapcycleOverview($serverFile)));
}

if ($t === 'browse') {
    $hint = '';
    if ($serverFile !== '' && @is_dir(dirname($serverFile))) { $hint = dirname($serverFile); }
    $r = mapcycleBrowse((string) ($_POST['path'] ?? ''), $hint);
    mcfJson($r['ok'], $r);
}

if ($t === 'link') {
    $slot = (string) ($_POST['slot'] ?? '');
    $r = mapcycleLink($slot, (string) ($_POST['path'] ?? ''));
    if ($r['ok']) { echLog('mapconfig', 'Mapcycle file for ' . $slot . ' set to ' . (string) ($_POST['path'] ?? '') . ' (' . $r['via'] . ')'); }
    $r['slots'] = $r['ok'] ? mapcycleOverview($serverFile) : null;
    mcfJson($r['ok'], $r, $r['ok'] ? 200 : 400);
}

if ($t === 'unlink') {
    $r = mapcycleUnlink((string) ($_POST['slot'] ?? ''));
    if ($r['ok']) { echLog('mapconfig', 'Mapcycle file for ' . (string) $_POST['slot'] . ' removed'); }
    $r['slots'] = $r['ok'] ? mapcycleOverview($serverFile) : null;
    mcfJson($r['ok'], $r, $r['ok'] ? 200 : 400);
}

if ($t === 'custom_add' || $t === 'custom_rename' || $t === 'custom_remove') {
    if ($t === 'custom_add') {
        $r = mapcycleCustomAdd((string) ($_POST['name'] ?? ''), (string) ($_POST['code'] ?? ''));
        if ($r['ok']) { echLog('mapconfig', 'Custom game type added: ' . $r['code'] . ' (' . $r['label'] . ')'); }
    } elseif ($t === 'custom_rename') {
        $r = mapcycleCustomRename((string) ($_POST['slot'] ?? ''), (string) ($_POST['name'] ?? ''));
        if ($r['ok']) { echLog('mapconfig', 'Custom game type ' . $r['code'] . ' renamed to ' . $r['label']); }
    } else {
        $r = mapcycleCustomRemove((string) ($_POST['slot'] ?? ''));
        if ($r['ok']) { echLog('mapconfig', 'Custom game type removed: ' . (string) ($_POST['slot'] ?? '')); }
    }
    $r['slots'] = $r['ok'] ? mapcycleOverview($serverFile) : null;
    mcfJson($r['ok'], $r, $r['ok'] ? 200 : 400);
}

mcfJson(false, array('error' => 'Unknown command.'), 400);
