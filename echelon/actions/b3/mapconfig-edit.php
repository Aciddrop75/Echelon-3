<?php
/**
 * Add / edit / copy / delete a mapconfig. POST only, CSRF protected, redirects back to the board or the editor.
 */
$auth_name = 'mapconfig';
$b3_conn = true; // this page needs to connect to the B3 database
require '../../inc.php';

function mcH($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }

function mcGo($url) {
    header('Location: ' . PATH . $url);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mcGo('mapconfig.php');
}
csrfCheck();

$t = $_POST['t'] ?? '';
$idraw = $_POST['id'] ?? '0';
$id = (is_string($idraw) && ctype_digit($idraw)) ? (int) $idraw : 0;

/* ---- delete ---------------------------------------------------------- */
if ($t === 'del') {
    $map = $id ? $db->getMapconfig($id) : null;
    if (!$map) {
        set_error('That map no longer exists.');
        mcGo('mapconfig.php');
    }
    if ($db->delMapconfig($id)) {
        mapImageDelete($map['mapname']);
        echLog('mapconfig', 'Deleted map ' . $map['mapname']);
        set_good(mcH($map['mapname']) . ' has been deleted.');
    } else {
        set_error('There is a problem. The map has not been deleted.');
    }
    mcGo('mapconfig.php');
}

/* ---- duplicate -------------------------------------------------------- */
if ($t === 'copy') {
    $map = $id ? $db->getMapconfig($id) : null;
    if (!$map) {
        set_error('That map no longer exists.');
        mcGo('mapconfig.php');
    }
    $base = substr($map['mapname'], 0, 44);
    $name = $base . '_copy';
    for ($n = 2; $db->mapconfigNameExists($name) && $n < 100; $n++) {
        $name = $base . '_copy' . $n;
    }
    $new = $db->addMapconfig($name, (int) $map['capturelimit'], (int) $map['g_suddendeath'], (string) $map['g_gear'], (int) $map['g_gravity'],
        (int) $map['g_friendlyfire'], (string) $map['startmessage'], (int) $map['skiprandom'], '2000-01-01', (int) $map['timelimit'], (string) $map['gamemodes'], (int) $map['bot']);
    if ($new) {
        set_good('Created ' . mcH($name) . '. Rename it and add a picture.');
        mcGo('mapconfigdetails.php?id=' . $new);
    }
    set_error('There is a problem. The map could not be duplicated.');
    mcGo('mapconfigdetails.php?id=' . $id);
}

/* ---- add / edit -------------------------------------------------------- */
if ($t !== 'add' && $t !== 'edit') {
    set_error('There is a problem. Unknown command received.');
    mcGo('mapconfig.php');
}

list($c, $errors) = mapReadForm();
$back = ($t === 'add') ? 'mapconfigdetails.php?new=1' : 'mapconfigdetails.php?id=' . $id;

$old = null;
if ($t === 'edit') {
    $old = $id ? $db->getMapconfig($id) : null;
    if (!$old) {
        set_error('That map no longer exists.');
        mcGo('mapconfig.php');
    }
}
if (!$errors && $db->mapconfigNameExists($c['mapname'], $id)) {
    $errors[] = 'There is already a map called &quot;' . mcH($c['mapname']) . '&quot;.';
}
if ($errors) {
    $_SESSION['mc_old'] = $c; // so the form is filled in again
    set_error(implode(' ', array_map(function ($m) { return strpos($m, '&quot;') !== false ? $m : mcH($m); }, $errors)));
    mcGo($back);
}

if ($t === 'add') {
    $new = $db->addMapconfig($c['mapname'], $c['capturelimit'], $c['g_suddendeath'], $c['g_gear'], $c['g_gravity'], $c['g_friendlyfire'],
        $c['startmessage'], $c['skiprandom'], $c['datelastadd'], $c['timelimit'], $c['gamemodes'], $c['bot']);
    if ($new) {
        // try to take the picture from the map's own .pk3 (no-op when no map folder is available)
        $got = false;
        try {
            $sv = $dbl->getServers($game);
            $dirs = mapPk3Dirs(!empty($sv) ? trim((string) $sv[0]['mapcyclefile']) : '');
            if ($dirs) {
                $r = mapPk3Scan($dirs, array($c['mapname']));
                $got = !empty($r['saved']);
            }
        } catch (Throwable $e) { /* the map is saved either way */ }
        set_good(mcH($c['mapname']) . ' has been added.' . ($got ? ' Its picture was taken from the map file.' : ' You can add a picture now.'));
        mcGo('mapconfigdetails.php?id=' . $new);
    }
    $_SESSION['mc_old'] = $c;
    set_error('There is a problem. The map has not been saved.');
    mcGo($back);
}

$db->editMapconfig($id, $c['mapname'], $c['capturelimit'], $c['g_suddendeath'], $c['g_gear'], $c['g_gravity'], $c['g_friendlyfire'],
    $c['startmessage'], $c['skiprandom'], $c['datelastadd'], $c['timelimit'], $c['gamemodes'], $c['bot']);
if ($old['mapname'] !== $c['mapname']) {
    mapImageRename($old['mapname'], $c['mapname']); // keep the picture with the map
}
set_good(mcH($c['mapname']) . ' has been updated.');
mcGo($back);
