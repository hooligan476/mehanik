<?php
// api/get-vehicle-types.php
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

$out = [];

try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $res = $mysqli->query("SELECT id, `key`, name FROM vehicle_types ORDER BY `order` ASC, name ASC");
        while ($r = $res->fetch_assoc()) $out[] = $r;
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $st = $pdo->query("SELECT id, `key`, name FROM vehicle_types ORDER BY `order` ASC, name ASC");
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
    exit;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
