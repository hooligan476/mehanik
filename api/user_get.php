<?php
// mehanik/public/api/user_get.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

// permission check (admin only)
$user = $_SESSION['user'] ?? null;
$role = strtolower((string)($user['role'] ?? ''));
$isSuper = ((int)($user['is_superadmin'] ?? 0) === 1);
if (!$user || !in_array($role, ['admin','superadmin'], true) && !$isSuper) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'access_denied']);
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_id']);
    exit;
}

try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $st = $mysqli->prepare("SELECT id,name,phone,role,status,balance,created_at FROM users WHERE id = ? LIMIT 1");
        $st->bind_param('i', $id);
        $st->execute();
        $res = $st->get_result();
        $row = $res->fetch_assoc();
        $st->close();
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $st = $pdo->prepare("SELECT id,name,phone,role,status,balance,created_at FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id'=>$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } else {
        throw new Exception('no_db');
    }

    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok'=>false,'error'=>'not_found']);
        exit;
    }

    $row['id'] = (int)$row['id'];
    $row['balance'] = isset($row['balance']) ? number_format((float)$row['balance'],2,'.','') : '0.00';

    echo json_encode(['ok'=>true,'user'=>$row], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'server_error','message'=>$e->getMessage()]);
    exit;
}
