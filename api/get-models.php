<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_GET['brand_id'])) {
    echo json_encode([]);
    exit;
}

$brand_id = intval($_GET['brand_id']);

$stmt = $mysqli->prepare("SELECT id, name FROM models WHERE brand_id = ? ORDER BY name");
$stmt->bind_param("i", $brand_id);
$stmt->execute();
$res = $stmt->get_result();

$models = [];
while ($row = $res->fetch_assoc()) {
    $models[] = $row;
}

echo json_encode($models);
