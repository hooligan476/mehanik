<?php
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json; charset=utf-8');

$region_id = isset($_GET['region_id']) ? (int)$_GET['region_id'] : 0;
if (!$region_id) { echo json_encode([]); exit; }

try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $st = $mysqli->prepare("SELECT id, name FROM districts WHERE region_id = ? AND active = 1 ORDER BY `order` ASC, name ASC");
        $st->bind_param('i', $region_id);
        $st->execute();
        $res = $st->get_result();
        $out = [];
        while ($r = $res->fetch_assoc()) $out[] = $r;
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $st = $pdo->prepare("SELECT id, name FROM districts WHERE region_id = :rid AND active = 1 ORDER BY `order` ASC, name ASC");
        $st->execute([':rid' => $region_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        echo json_encode([]);
    }
} catch (Throwable $e) {
    echo json_encode([]);
}
