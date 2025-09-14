<?php
// api/admin/new_chats_count.php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

$role = $_SESSION['user']['role'] ?? null;
if (empty($_SESSION['user']) || !in_array($role, ['admin','superadmin'], true)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden']);
    exit;
}

// require db.php — файл находится в корне проекта: ../../db.php
require_once __DIR__ . '/../../db.php';

try {
    $sql = "SELECT COUNT(*) AS c FROM chats WHERE (accepted_by IS NULL OR accepted_by = 0) AND status <> 'closed'";
    $res = $mysqli->query($sql);
    $row = $res ? $res->fetch_assoc() : null;
    $count = (int)($row['c'] ?? 0);
    echo json_encode(['ok'=>true,'Count'=>$count,'count'=>$count]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'db']);
}
