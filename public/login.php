<?php
// login.php
session_start();

// DB настройки — при необходимости измени
$dbHost = '127.0.0.1';
$dbName = 'mehanik';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("DB connection failed: " . $e->getMessage());
}

function clean($v) {
    return trim(htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Входная форма — префикс +993 (неизменяемый) + поле phone_local (8 цифр)
    $phone_local = isset($_POST['phone_local']) ? preg_replace('/\D/', '', $_POST['phone_local']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($phone_local === '') $errors[] = 'Введите номер (8 цифр).';
    if (!preg_match('/^\d{8}$/', $phone_local)) $errors[] = 'Номер должен содержать ровно 8 цифр (без префикса +993).';
    if ($password === '') $errors[] = 'Введите пароль.';

    if (empty($errors)) {
        $phone_norm = '+993' . $phone_local;

        $stmt = $pdo->prepare("SELECT id, name, phone, password_hash, role, created_at, verify_code, status FROM users WHERE phone = :phone LIMIT 1");
        $stmt->execute([':phone' => $phone_norm]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $errors[] = 'Пользователь не найден.';
        } else {
            if (password_verify($password, $user['password_hash'])) {
                unset($user['password_hash']);
                $_SESSION['user'] = $user;

                // Абсолютный редирект на public/index.php внутри проекта mehanik
                header('Location: http://localhost/mehanik/public/index.php');
                exit;
            } else {
                $errors[] = 'Неверный пароль.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Вход</title>
<style>
.input-inline { display:flex; align-items:center; gap:6px; }
.input-inline .prefix { background:#efefef; padding:8px 10px; border:1px solid #ccc; border-right:none; }
.input-inline input { padding:8px; border:1px solid #ccc; }
</style>
</head>
<body>
<h2>Вход по номеру телефона</h2>

<?php if (!empty($errors)): ?>
    <div style="color:red">
        <ul>
            <?php foreach ($errors as $e): ?>
                <li><?=htmlspecialchars($e)?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="">
    <label>Телефон:<br>
        <div class="input-inline">
            <div class="prefix">+993</div>
            <input type="text" name="phone_local" required pattern="\d{8}" maxlength="8" inputmode="numeric" placeholder="XXXXXXXX"
                   value="<?= isset($phone_local) ? htmlspecialchars($phone_local) : '' ?>">
        </div>
        <small>Введите только 8 цифр (без префикса).</small>
    </label><br><br>

    <label>Пароль:<br><input type="password" name="password" required></label><br><br>
    <button type="submit">Войти</button>
</form>

<p><a href="/mehanik/public/register.php">Регистрация</a></p>

<script>
// Разрешаем только цифры в поле phone_local
document.querySelectorAll('input[name="phone_local"]').forEach(function(el){
    el.addEventListener('input', function(){
        this.value = this.value.replace(/\D/g,'').slice(0,8);
    });
});
</script>
</body>
</html>