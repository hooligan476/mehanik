<?php require_once __DIR__.'/../middleware.php'; require_auth(); require_once __DIR__.'/../db.php'; header('Content-Type: application/json');
$uid=$_SESSION['user']['id'];
$res=$mysqli->prepare("SELECT * FROM products WHERE user_id=? ORDER BY id DESC");
$res->bind_param('i',$uid);
$res->execute();
$rows=$res->get_result()->fetch_all(MYSQLI_ASSOC);
echo json_encode(['products'=>$rows]);
