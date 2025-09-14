<?php
// api/admin/claim_chat.php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

// only admin/superadmin
$role = $_SESSION['user']['role'] ?? null;
if (empty($_SESSION['user']) || !in_array($role, ['admin','superadmin'], true)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden']);
    exit;
}

require_once __DIR__ . '/../../db.php';

$adminId = (int)($_SESSION['user']['id'] ?? 0);
$chatId = isset($_POST['chat_id']) ? (int)$_POST['chat_id'] : 0;

if ($chatId <= 0) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'invalid_chat_id']);
    exit;
}

try {
    // Atomically claim only if not claimed and not closed
    $st = $mysqli->prepare("UPDATE chats SET accepted_by = ?, accepted_at = NOW(), status = 'accepted' WHERE id = ? AND (accepted_by IS NULL OR accepted_by = 0) AND status <> 'closed' LIMIT 1");
    $st->bind_param('ii', $adminId, $chatId);
    $st->execute();
    if ($st->affected_rows > 0) {
        // get admin name to return
        $name = $_SESSION['user']['name'] ?? 'admin#'.$adminId;
        echo json_encode(['ok'=>true,'chat_id'=>$chatId,'accepted_by'=>$adminId,'admin_name'=>$name]);
    } else {
        echo json_encode(['ok'=>false,'error'=>'already_taken_or_closed']);
    }
    $st->close();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'db_error']);
}
