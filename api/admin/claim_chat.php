<?php
// claim_chat.php
header('Content-Type: application/json; charset=utf-8');

// include middleware
$included = false;
$candidates = [
    __DIR__ . '/../../../middleware.php',
    __DIR__ . '/../../middleware.php',
];
foreach ($candidates as $c) {
    if (file_exists($c)) { require_once $c; $included = true; break;}
}
if (!$included) { echo json_encode(['ok'=>false,'error'=>'middleware_not_found']); exit; }
require_admin();

// include db
$dbIncluded = false;
$dbCandidates = [
    __DIR__ . '/../../../db.php',
    __DIR__ . '/../../db.php',
    __DIR__ . '/../db.php'
];
foreach ($dbCandidates as $f) {
    if (file_exists($f)) { require_once $f; $dbIncluded = true; break; }
}
if (!isset($mysqli) && !isset($pdo)) { echo json_encode(['ok'=>false,'error'=>'db_not_found']); exit; }

// only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'error'=>'method']);
    exit;
}

$chat_id = isset($_POST['chat_id']) ? (int)$_POST['chat_id'] : 0;
if ($chat_id <= 0) {
    echo json_encode(['ok'=>false,'error'=>'invalid_chat_id']);
    exit;
}

$adminId = (int)($_SESSION['user']['id'] ?? 0);
if ($adminId <= 0) {
    echo json_encode(['ok'=>false,'error'=>'not_auth']); exit;
}

try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $st = $mysqli->prepare("UPDATE chats SET accepted_by = ?, accepted_at = NOW(), status = 'accepted' WHERE id = ? AND (accepted_by IS NULL OR accepted_by = 0) AND status <> 'closed' LIMIT 1");
        $st->bind_param('ii', $adminId, $chat_id);
        $st->execute();
        $affected = $st->affected_rows;
        $st->close();
        if ($affected > 0) {
            echo json_encode(['ok'=>true,'chat_id'=>$chat_id]);
            exit;
        } else {
            echo json_encode(['ok'=>false,'error'=>'already_taken_or_closed']);
            exit;
        }
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $st = $pdo->prepare("UPDATE chats SET accepted_by = :aid, accepted_at = NOW(), status = 'accepted' WHERE id = :id AND (accepted_by IS NULL OR accepted_by = 0) AND status <> 'closed' LIMIT 1");
        $st->execute([':aid'=>$adminId, ':id'=>$chat_id]);
        if ($st->rowCount() > 0) {
            echo json_encode(['ok'=>true,'chat_id'=>$chat_id]);
            exit;
        } else {
            echo json_encode(['ok'=>false,'error'=>'already_taken_or_closed']);
            exit;
        }
    }
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>'exception','msg'=>$e->getMessage()]);
    exit;
}
