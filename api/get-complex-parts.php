<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db.php';

$stmt = $pdo->query("SELECT id, name FROM complex_part ORDER BY name");
$parts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($parts);
