<?php require_once __DIR__.'/../db.php'; require_once __DIR__.'/../middleware.php';
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$pass = $_POST['password'] ?? '';
if (!$name || !$email || strlen($pass) < 6) {
  http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Заполните поля (пароль ≥ 6)']); exit;
}
$hash = password_hash($pass, PASSWORD_BCRYPT);
$stmt = $mysqli->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)');
$stmt->bind_param('sss',$name,$email,$hash);
if ($stmt->execute()) {
  $_SESSION['user']=['id'=>$stmt->insert_id,'name'=>$name,'email'=>$email,'role'=>'user'];
  echo json_encode(['ok'=>true]);
} else {
  http_response_code(409); echo json_encode(['ok'=>false,'error'=>'Email уже используется']);
}
