<?php
require_once __DIR__.'/../middleware.php';
require_auth();
require_once __DIR__.'/../db.php';
header('Content-Type: application/json');

$uid = $_SESSION['user']['id'];
$role = $_SESSION['user']['role'] ?? 'user';

if ($role === 'admin') {
    $stmt = $mysqli->prepare("SELECT * FROM products ORDER BY id DESC");
} else {
    $stmt = $mysqli->prepare("SELECT * FROM products WHERE user_id=? ORDER BY id DESC");
    $stmt->bind_param('i',$uid);
}

$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode(['products'=>$rows]);
