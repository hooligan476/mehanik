<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db.php';

$complex_part_id = $_GET['complex_part_id'] ?? null;
if (!$complex_part_id) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("SELECT id, name FROM components WHERE complex_part_id = ? ORDER BY name");
$stmt->execute([$complex_part_id]);
$components = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($components);
