<?php
session_start();
require_once __DIR__.'/../db.php';

$success = false;
$errorMsg = '';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $email = trim($_POST['email'] ?? '');
    $code  = trim($_POST['code'] ?? '');

    if(!$email || !$code){
        $errorMsg = 'Заполните все поля.';
    } else {
        $stmt = $mysqli->prepare("SELECT id,status FROM users WHERE email=? AND verify_code=? LIMIT 1");
        $stmt->bind_param('si', $email, $code);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if($user){
            if($user['status']==1){
                $errorMsg = 'Аккаунт уже подтверждён.';
            } else {
                $upd = $mysqli->prepare("UPDATE users SET status=1, verify_code=NULL WHERE id=?");
                $upd->bind_param('i',$user['id']);
                $upd->execute();
                $success = true;
            }
        } else {
            $errorMsg = 'Неверный код или email.';
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Подтверждение аккаунта</title>
<style>
body { font-family: Arial, sans-serif; margin:30px; }
form { max-width:300px; }
input { display:block; margin:8px 0; padding:8px; width:100%; }
button { padding:8px 12px; }
.msg { padding:10px; margin-bottom:15px; border-radius:5px; }
.success { background:#e0ffe0; border:1px solid #70c570; color:#2e7d32; }
.error { background:#ffe0e0; border:1px solid #d66; color:#b71c1c; }
</style>
</head>
<body>
<h2>Подтверждение аккаунта</h2>

<?php if($success): ?>
<div class="msg success">
    ✅ Аккаунт подтверждён! Сейчас вы будете перенаправлены на вход...
</div>
<script>
setTimeout(()=>location.href='login.php',3000);
</script>
<?php else: ?>
<?php if($errorMsg): ?>
<div class="msg error"><?=htmlspecialchars($errorMsg)?></div>
<?php endif; ?>
<form method="post">
    <input type="email" name="email" placeholder="Email" required>
    <input type="text" name="code" placeholder="Код подтверждения" required>
    <button type="submit">Подтвердить</button>
</form>
<?php endif; ?>
</body>
</html>
