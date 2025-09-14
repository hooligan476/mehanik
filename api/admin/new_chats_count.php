<?php
// new_chats_count.php
header('Content-Type: application/json; charset=utf-8');

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

try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $res = $mysqli->query("SELECT COUNT(*) AS c FROM chats WHERE (accepted_by IS NULL OR accepted_by = 0) AND status <> 'closed'");
        $count = 0;
        if ($res) $count = (int)($res->fetch_assoc()['c'] ?? 0);
        echo json_encode(['ok'=>true,'count'=>$count]);
        exit;
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM chats WHERE (accepted_by IS NULL OR accepted_by = 0) AND status <> 'closed'")->fetchColumn();
        echo json_encode(['ok'=>true,'count'=>$count]);
        exit;
    }
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>'exception','msg'=>$e->getMessage()]);
    exit;
}
