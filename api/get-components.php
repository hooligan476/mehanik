<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_GET['complex_part_id'])) {
    echo json_encode([]);
    exit;
}

$complex_part_id = intval($_GET['complex_part_id']);

$stmt = $mysqli->prepare("SELECT id, name FROM components WHERE complex_part_id = ? ORDER BY name");
$stmt->bind_param("i", $complex_part_id);
$stmt->execute();
$res = $stmt->get_result();

$components = [];
while ($row = $res->fetch_assoc()) {
    $components[] = $row;
}

echo json_encode($components);
