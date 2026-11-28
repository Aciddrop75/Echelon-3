<?php
/**
 * Upload / remove the picture of a map (AJAX, JSON answer).
 * POST: id, t = upload | delete, image (file, for upload). Header X-CSRF-Token.
 */
$auth_name = 'mapconfig';
$b3_conn = true;
require '../../inc.php';

header('Content-Type: application/json; charset=utf-8');
function mcJson($ok, $extra = array(), $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(array('ok' => $ok), $extra));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mcJson(false, array('error' => 'POST required.'), 405);
}
csrfCheck(true);

$id = $_POST['id'] ?? '';
if (!is_string($id) || !ctype_digit($id)) {
    mcJson(false, array('error' => 'Invalid map id.'), 400);
}
$map = $db->getMapconfig((int) $id);
if (!$map) {
    mcJson(false, array('error' => 'That map no longer exists.'), 404);
}

$t = $_POST['t'] ?? '';
if ($t === 'delete') {
    mapImageDelete($map['mapname']);
    mcJson(true);
}
if ($t !== 'upload') {
    mcJson(false, array('error' => 'Unknown command.'), 400);
}

$f = $_FILES['image'] ?? null;
if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $msgs = array(
        UPLOAD_ERR_INI_SIZE => 'The picture is larger than the server allows (upload_max_filesize).',
        UPLOAD_ERR_FORM_SIZE => 'The picture is too large.',
        UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE => 'No file was received.',
    );
    mcJson(false, array('error' => $msgs[$f['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'The upload failed.'), 400);
}

list($ok, $message) = mapImageSave($f['tmp_name'], $map['mapname']);
if (!$ok) {
    mcJson(false, array('error' => $message), 422);
}
// make sure the page sees the new file even if the folder was scanned earlier in this request
mcJson(true, array('url' => (function ($m) {
    foreach (MAP_IMG_EXT as $ext) {
        $p = MAP_IMG_DIR . mapSlug($m) . '.' . $ext;
        if (is_file($p)) { return PATH . 'images/maps/' . rawurlencode(basename($p)) . '?v=' . filemtime($p); }
    }
    return null;
})($map['mapname'])));
