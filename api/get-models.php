<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

$brand_id = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : 0;

try {
    $stmt = $mysqli->prepare("SELECT id, brand_id, name FROM models WHERE brand_id = ? ORDER BY name ASC");
    $stmt->bind_param('i', $brand_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $models = [];
    while ($row = $res->fetch_assoc()) {
        $models[] = $row;
    }
    echo json_encode($models);
    $stmt->close();
} catch (Exception $e) {
    echo json_encode([]);
}
