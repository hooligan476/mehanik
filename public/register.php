<?php
session_start();

// --- DEBUG / logging (удали или поставь false в production) ---
$DEBUG = true;
ini_set('display_errors', $DEBUG ? 1 : 0);
ini_set('display_startup_errors', $DEBUG ? 1 : 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
if (!is_dir(__DIR__ . '/../logs')) {
    @mkdir(__DIR__ . '/../logs', 0755, true);
}
ini_set('error_log', __DIR__ . '/../logs/php_error.log');

// --- безопасные подключения ---
$root = dirname(__DIR__);

// config.php (обязательно)
$configFile = $root . '/config.php';
if (!file_exists($configFile)) {
    error_log("register.php: missing config.php");
    die('Ошибка конфигурации (config.php отсутствует). Смотри logs/php_error.log');
}
require_once $configFile;

// db.php (должно создавать $conn как mysqli или $pdo)
$dbFile = $root . '/db.php';
if (file_exists($dbFile)) {
    require_once $dbFile;
} else {
    error_log("register.php: db.php not found. Проверь, подключена ли БД в config/db.php");
}

// composer autoload (PHPMailer и т.д.)
$autoload = $root . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    // не фатал — но логируем (если используются библиотеки — нужно выполнить composer install)
    error_log("register.php: vendor/autoload.php not found. Выполни 'composer install' если используешь внешние библиотеки.");
}

// mail helper
$mailFile = $root . '/mail/sendMail.php';
if (file_exists($mailFile)) {
    require_once $mailFile;
} else {
    error_log("register.php: mail/sendMail.php not found. Email-уведомления не будут работать.");
}

// --- Проверка наличия соединения с БД ---
if (!isset($conn)) {
    error_log("register.php: \$conn is not set. Проверь db.php / config.php");
    if ($DEBUG) {
        die('DB connection не установлен. Проверь файл db.php или config.php. Подробности в logs/php_error.log');
    } else {
        die('Сервер временно недоступен.');
    }
}

// --- Обработка POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // безопасно читаем поля
    $name     = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email    = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password_raw = isset($_POST['password']) ? $_POST['password'] : '';

    // базовая валидация
    if ($name === '' || $email === '' || $password_raw === '') {
        die('Пожалуйста, заполните все поля.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        die('Неверный email.');
    }
    if (strlen($password_raw) < 6) {
        die('Пароль должен быть не менее 6 символов.');
    }

    // хешируем пароль
    $password_hash = password_hash($password_raw, PASSWORD_DEFAULT);

    // Проверка существующего email
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    if (!$check) {
        error_log("register.php: prepare failed (check): " . $conn->error);
        die('Внутренняя ошибка (db prepare). Проверь logs.');
    }
    $check->bind_param('s', $email);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        echo "❌ Такой email уже зарегистрирован.";
        exit;
    }
    $check->close();

    // код подтверждения
    $code = rand(100000, 999999);

    // вставка в БД
    $ins = $conn->prepare("INSERT INTO users (name, email, password, verification_code, verified) VALUES (?, ?, ?, ?, 0)");
    if (!$ins) {
        error_log("register.php: prepare failed (insert): " . $conn->error);
        die('Внутренняя ошибка при регистрации (db prepare). Смотри logs/php_error.log');
    }
    $ins->bind_param("sssi", $name, $email, $password_hash, $code);
    if (!$ins->execute()) {
        error_log("register.php: insert execute failed: " . $ins->error);
        die('Ошибка при записи в БД. Смотри logs/php_error.log');
    }
    $ins->close();

    // отправляем письмо — обернём в try/catch, если sendVerificationMail кидает исключение
    $mailResult = null;
    try {
        if (function_exists('sendVerificationMail')) {
            $mailResult = sendVerificationMail($email, $name, $code);
        } else {
            error_log("register.php: sendVerificationMail() function not found.");
            $mailResult = "sendVerificationMail not available";
        }
    } catch (Throwable $e) {
        error_log("register.php: exception in sendVerificationMail: " . $e->getMessage());
        $mailResult = $e->getMessage();
    }

    if ($mailResult === true) {
        $_SESSION['pending_email'] = $email;
        echo "✅ Регистрация почти завершена! Проверьте почту и введите код <a href='verify.php'>здесь</a>.";
    } else {
        // не критично — но логируем и покажем сообщение
        error_log("register.php: send mail failed: " . var_export($mailResult, true));
        echo "❌ Ошибка при отправке письма: " . htmlspecialchars((string)$mailResult);
    }
    exit;
}

// GET — можно показать простую форму или вернуть 200 пустую страницу как раньше
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Register</title></head>
<body>
<form method="post">
  <input name="name" placeholder="Имя" required><br>
  <input name="email" type="email" placeholder="Email" required><br>
  <input name="password" type="password" placeholder="Пароль" required><br>
  <button>Зарегистрироваться</button>
</form>
</body></html>
