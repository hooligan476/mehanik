<?php require_once __DIR__.'/../middleware.php'; require_admin(); require_once __DIR__.'/../db.php'; header('Content-Type: application/json');
$users=$mysqli->query('SELECT COUNT(*) c FROM users')->fetch_assoc()['c'];
$products=$mysqli->query('SELECT COUNT(*) c FROM products')->fetch_assoc()['c'];
$open=$mysqli->query("SELECT COUNT(*) c FROM chats WHERE status='open'")->fetch_assoc()['c'];
echo json_encode(['users'=>(int)$users,'products'=>(int)$products,'open_chats'=>(int)$open]);
