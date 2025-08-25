<?php
// register.php
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
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = isset($_POST['name']) ? clean($_POST['name']) : '';
    // пользователь вводит только локальную часть — 8 цифр
    $phone_local = isset($_POST['phone_local']) ? preg_replace('/\D/', '', $_POST['phone_local']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $password_confirm = isset($_POST['password_confirm']) ? $_POST['password_confirm'] : '';

    if ($name === '') $errors[] = 'Введите имя.';
    if ($phone_local === '') $errors[] = 'Введите номер (8 цифр).';
    if (!preg_match('/^\d{8}$/', $phone_local)) $errors[] = 'Номер должен содержать ровно 8 цифр (без префикса +993).';
    if ($password === '') $errors[] = 'Введите пароль.';
    if ($password !== $password_confirm) $errors[] = 'Пароли не совпадают.';

    if (empty($errors)) {
        $phone_norm = '+993' . $phone_local;

        // Проверка уникальности
        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = :phone LIMIT 1");
        $stmt->execute([':phone' => $phone_norm]);
        if ($stmt->fetch()) {
            $errors[] = 'Пользователь с таким номером уже существует.';
        } else {
            $verify_code = random_int(100000, 999999);
            $status = 'pending';
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

            $insert = $pdo->prepare("INSERT INTO users (name, phone, ip, password_hash, role, created_at, verify_code, status)
                VALUES (:name, :phone, :ip, :password_hash, :role, NOW(), :verify_code, :status)");
            $insert->execute([
                ':name' => $name,
                ':phone' => $phone_norm,
                ':ip' => $ip,
                ':password_hash' => $password_hash,
                ':role' => 'user',
                ':verify_code' => (string)$verify_code,
                ':status' => $status
            ]);

            $userId = $pdo->lastInsertId();
            $stmt = $pdo->prepare("SELECT id, name, phone, role, created_at, verify_code, status FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // логиним пользователя в сессию (без password_hash)
            $_SESSION['user'] = $user;

            // Абсолютный редирект на public/index.php внутри проекта mehanik
            header('Location: http://localhost/mehanik/public/index.php');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Регистрация</title>
<style>
.input-inline { display:flex; align-items:center; gap:6px; }
.input-inline .prefix { background:#efefef; padding:8px 10px; border:1px solid #ccc; border-right:none; }
.input-inline input { padding:8px; border:1px solid #ccc; }
</style>
</head>
<body>
<h2>Регистрация (по номеру телефона)</h2>

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
    <label>Имя:<br><input type="text" name="name" required value="<?= isset($name) ? htmlspecialchars($name) : '' ?>"></label><br><br>

    <label>Телефон:<br>
        <div class="input-inline">
            <div class="prefix">+993</div>
            <input type="text" name="phone_local" required pattern="\d{8}" maxlength="8" inputmode="numeric" placeholder="XXXXXXXX"
                   value="<?= isset($phone_local) ? htmlspecialchars($phone_local) : '' ?>">
        </div>
        <small>Введите только 8 цифр (без пробелов). Префикс +993 добавляется автоматически.</small>
    </label>
    <br><br>

    <label>Пароль:<br><input type="password" name="password" required></label><br><br>
    <label>Подтвердите пароль:<br><input type="password" name="password_confirm" required></label><br><br>
    <button type="submit">Зарегистрироваться</button>
</form>

<p><a href="/mehanik/login.php">Войти</a></p>

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
