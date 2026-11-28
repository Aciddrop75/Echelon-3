<?php
/**
 * Read / write a mapcycle file. AJAX, JSON answer.
 * POST: t = read | write, slot (a game type code or "default"), data (for write). Header X-CSRF-Token.
 */
$page = "maps";
$auth_name = 'mapconfig';
$b3_conn = false;
require '../inc.php';

header('Content-Type: application/json; charset=utf-8');
function mcycleJson($ok, $extra = array(), $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(array('ok' => $ok), $extra), JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mcycleJson(false, array('error' => 'POST required.'), 405);
}
csrfCheck(true);

$servers = $dbl->getServers($game);
$serverFile = !empty($servers) ? trim((string) $servers[0]['mapcyclefile']) : '';

// which mapcycle: a game type's file (a link in mapcycle/ made under Settings > Maps & World Map), or "default"
$slot = (string) ($_POST['slot'] ?? 'default');
if (!mapcycleSlotValid($slot)) {
    mcycleJson(false, array('error' => 'Unknown mapcycle.'), 400);
}
$tg = mapcycleTarget($slot, $serverFile);
$file = $tg['path'];
if ($file === '') {
    mcycleJson(false, array('error' => 'No mapcycle file is chosen for this game type yet. Choose it under Settings > Maps & World Map.'), 400);
}
if ($tg['broken']) {
    mcycleJson(false, array('error' => 'The link points to a file that no longer exists (' . $tg['target'] . '). Choose the file again under Settings > Maps & World Map.'));
}

const MAPCYCLE_MAX = 262144; // 256 KB is far more than any mapcycle

$t = $_POST['t'] ?? '';
if ($t === 'read') {
    if (!is_file($file) || !is_readable($file)) {
        mcycleJson(false, array('error' => 'The mapcycle file cannot be read by the web server (' . $tg['target'] . ').'));
    }
    if (filesize($file) > MAPCYCLE_MAX) {
        mcycleJson(false, array('error' => 'The mapcycle file is unexpectedly large, so it was not loaded.'));
    }
    mcycleJson(true, array('data' => (string) file_get_contents($file, false, null, 0, MAPCYCLE_MAX)));
}

if ($t === 'write') {
    $data = $_POST['data'] ?? '';
    if (!is_string($data) || trim($data) === '') {
        mcycleJson(false, array('error' => 'There was no data to write.'), 400);
    }
    if (strlen($data) > MAPCYCLE_MAX) {
        mcycleJson(false, array('error' => 'That mapcycle is too large.'), 413);
    }
    $data = str_replace(array("\r\n", "\r"), "\n", $data);
    $data = rtrim($data, "\n") . "\n";
    if (strpos($data, "\0") !== false) {
        mcycleJson(false, array('error' => 'The text contains invalid characters.'), 400);
    }
    $exists = file_exists($file);
    if (!$exists && !is_writable(dirname($file))) {
        mcycleJson(false, array('error' => 'The mapcycle file does not exist and its folder is not writable by the web server (' . $tg['target'] . ').'));
    }
    if ($exists && !is_writable($file)) {
        $own = '';
        if (function_exists('posix_getpwuid') && ($ow = @fileowner($file)) !== false && ($u = @posix_getpwuid($ow))) { $own = ' It is owned by "' . $u['name'] . '".'; }
        mcycleJson(false, array('error' => 'The web server user ("' . mapcycleWebUser() . '") is not allowed to write ' . $tg['target'] . '.' . $own . ' Give that user write access to the file (for example: chmod g+w, and add the web server user to the file\'s group).'));
    }
    // keep a file with CRLF line endings as it was (a game server on Windows)
    $old = $exists ? (string) @file_get_contents($file, false, null, 0, MAPCYCLE_MAX) : '';
    if ($old !== '' && strpos($old, "\r\n") !== false) { $data = str_replace("\n", "\r\n", $data); }
    // one step back: the previous text is kept next to the link (mapcycle/<slot>.previous)
    if ($old !== '' && mapcycleEnsureDir()) { @file_put_contents(mapcycleDir() . DIRECTORY_SEPARATOR . $slot . '.previous', $old); }
    // writing through the link rewrites the real file in place; the link itself is never replaced
    if (file_put_contents($file, $data, LOCK_EX) === false) {
        mcycleJson(false, array('error' => 'There is a problem. The mapcycle file has not been written.'));
    }
    clearstatcache(true, $file);
    if ((string) @file_get_contents($file, false, null, 0, MAPCYCLE_MAX) !== $data) {
        mcycleJson(false, array('error' => 'The file was written but reading it back gives different text. Is something else changing it?'));
    }
    echLog('mapconfig', 'Mapcycle ' . $slot . ' written (' . strlen($data) . ' bytes) to ' . $tg['target']);
    mcycleJson(true, array('message' => 'Written to ' . $tg['target'] . '.'));
}

mcycleJson(false, array('error' => 'Unknown command.'), 400);
