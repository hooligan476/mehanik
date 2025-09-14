<?php
// new_chats_list.php
header('Content-Type: application/json; charset=utf-8');

// try to require middleware and db from several likely locations,
// so file works if placed in mehanik/public/api/admin or mehanik/api/admin
$included = false;
$candidates = [
    __DIR__ . '/../../../middleware.php', // mehanik/public/api/admin -> mehanik/middleware.php
    __DIR__ . '/../../middleware.php',    // mehanik/api/admin -> mehanik/middleware.php
];
foreach ($candidates as $c) {
    if (file_exists($c)) { require_once $c; $included = true; break;}
}
if (!$included) {
    echo json_encode(['ok'=>false,'error'=>'middleware_not_found']);
    exit;
}
if (!function_exists('require_admin')) {
    echo json_encode(['ok'=>false,'error'=>'middleware_invalid']);
    exit;
}
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
if (!isset($mysqli) && !isset($pdo)) {
    echo json_encode(['ok'=>false,'error'=>'db_not_found']);
    exit;
}

try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $sql = "SELECT c.id, c.user_id, COALESCE(u.phone, '') AS phone, c.status, c.created_at
                FROM chats c
                LEFT JOIN users u ON u.id = c.user_id
                WHERE (c.accepted_by IS NULL OR c.accepted_by = 0) AND c.status <> 'closed'
                ORDER BY c.id DESC
                LIMIT 200";
        $res = $mysqli->query($sql);
        $list = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) $list[] = $r;
        }
        echo json_encode(['ok'=>true,'list'=>$list]);
        exit;
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $st = $pdo->query("SELECT c.id, c.user_id, COALESCE(u.phone, '') AS phone, c.status, c.created_at
                           FROM chats c
                           LEFT JOIN users u ON u.id = c.user_id
                           WHERE (c.accepted_by IS NULL OR c.accepted_by = 0) AND c.status <> 'closed'
                           ORDER BY c.id DESC
                           LIMIT 200");
        $list = $st->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'list'=>$list]);
        exit;
    } else {
        echo json_encode(['ok'=>false,'error'=>'no_db_driver']);
        exit;
    }
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>'exception','msg'=>$e->getMessage()]);
    exit;
}
