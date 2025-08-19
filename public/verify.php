<?php
session_start();
require_once __DIR__ . '/../db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_SESSION['pending_email'] ?? null;
    $code  = trim($_POST['code']);

    if (!$email) {
        echo "❌ Ошибка: нет email для проверки. Зарегистрируйтесь заново.";
        exit;
    }

    // Проверяем код в БД
    $stmt = $conn->prepare("SELECT id FROM users WHERE email=? AND verification_code=? AND verified=0");
    $stmt->bind_param("si", $email, $code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        // Подтверждаем аккаунт
        $update = $conn->prepare("UPDATE users SET verified=1, verification_code=NULL WHERE email=?");
        $update->bind_param("s", $email);
        if ($update->execute()) {
            unset($_SESSION['pending_email']);
            // Редирект на страницу логина
            header("Location: login.php");
            exit;
        } else {
            echo "❌ Ошибка активации: " . $update->error;
        }
    } else {
        echo "❌ Неверный код подтверждения.";
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Подтверждение аккаунта</title>
</head>
<body>
    <h2>Введите код подтверждения</h2>
    <form method="POST" action="">
        <input type="text" name="code" placeholder="Код из письма" required>
        <button type="submit">Подтвердить</button>
    </form>
</body>
</html>
