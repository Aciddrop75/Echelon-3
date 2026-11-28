<?php
/**
 * Stamp a map as "added to the mapcycle today". AJAX, JSON answer: {ok, date}
 */
$auth_name = 'mapconfig';
$b3_conn = true;
require '../../inc.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'POST required.'));
    exit;
}
csrfCheck(true);

$id = $_POST['id'] ?? '';
if (!is_string($id) || !ctype_digit($id) || (int) $id < 1) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Invalid map id.'));
    exit;
}

if (!$db->datelastaddMapconfig((int) $id)) {
    echo json_encode(array('ok' => false, 'error' => 'The date could not be updated.'));
    exit;
}
$map = $db->getMapconfig((int) $id);
echo json_encode(array('ok' => (bool) $map, 'date' => $map ? $map['datelastadd'] : null));
