<?php
// api/admin/new_chats_list.php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

$role = $_SESSION['user']['role'] ?? null;
if (empty($_SESSION['user']) || !in_array($role, ['admin','superadmin'], true)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden']);
    exit;
}

require_once __DIR__ . '/../../db.php';

$limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 10;

try {
    $sql = "SELECT c.id, c.user_id, COALESCE(u.phone,'-') AS phone, c.status, c.created_at,
                   (SELECT content FROM messages m WHERE m.chat_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message
            FROM chats c
            LEFT JOIN users u ON u.id = c.user_id
            WHERE (c.accepted_by IS NULL OR c.accepted_by = 0) AND c.status <> 'closed'
            ORDER BY c.created_at DESC
            LIMIT " . (int)$limit;
    $res = $mysqli->query($sql);
    $list = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) $list[] = $r;
    }
    echo json_encode(['ok'=>true,'list'=>$list]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'db']);
}
