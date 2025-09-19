<?php
// mehanik/api/user_get.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

$projectRoot = dirname(__DIR__);
$dbPath = $projectRoot . '/db.php';
if (!file_exists($dbPath)) {
    echo json_encode(['ok'=>false,'error'=>'db.php not found']);
    exit;
}
require_once $dbPath;

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo json_encode(['ok'=>false,'error'=>'invalid id']);
    exit;
}

try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $st = $mysqli->prepare("SELECT id,name,phone,role,status,balance,created_at,last_seen FROM users WHERE id = ? LIMIT 1");
        $st->bind_param('i', $id);
        $st->execute();
        $res = $st->get_result();
        $r = $res->fetch_assoc();
        $st->close();
        if (!$r) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
        $r['balance'] = isset($r['balance']) ? (float)$r['balance'] : 0.0;
        echo json_encode(['ok'=>true,'user'=>$r], JSON_UNESCAPED_UNICODE);
        exit;
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $st = $pdo->prepare("SELECT id,name,phone,role,status,balance,created_at,last_seen FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id'=>$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
        $r['balance'] = isset($r['balance']) ? (float)$r['balance'] : 0.0;
        echo json_encode(['ok'=>true,'user'=>$r], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Throwable $e) {
    // ignore
}

echo json_encode(['ok'=>false,'error'=>'db error']);
exit;
