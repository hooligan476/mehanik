<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

try {
    $res = $mysqli->query("SELECT id, name FROM brands ORDER BY name ASC");
    $brands = [];
    while ($row = $res->fetch_assoc()) {
        $brands[] = $row;
    }
    echo json_encode($brands);
} catch (Exception $e) {
    echo json_encode([]);
}
