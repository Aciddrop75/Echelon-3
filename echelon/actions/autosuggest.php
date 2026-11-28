<?php
$auth_name = 'clients';
$b3_conn = true;
require '../inc.php';

header('Content-Type: application/json; charset=utf-8');

$string = trim((string) filter_input(INPUT_GET, 's'));
if ($string === '' || mb_strlen($string) < 2 || mb_strlen($string) > 32) {
    echo '[]';
    exit;
}

// LIKE wildcards in what the user typed are data, not patterns
$esc = addcslashes($string, '%_\\');

// 1) names that START with the text (can use the index on clients.name)
$rows = $db->query('SELECT DISTINCT name FROM clients WHERE name LIKE ? ORDER BY name LIMIT 8', true, 'select', array($esc . '%'));
$names = array_column($rows['data'], 'name');

// 2) otherwise names that CONTAIN it
if (!$names) {
    $rows = $db->query('SELECT DISTINCT name FROM clients WHERE name LIKE ? ORDER BY name LIMIT 8', true, 'select', array('%' . $esc . '%'));
    $names = array_column($rows['data'], 'name');
}

echo json_encode(array_values($names), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
