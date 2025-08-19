<?php
session_start();
require __DIR__ . '/../config.php';
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../mail/sendMail.php';

// Проверка формы
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // Проверка, есть ли уже такой email
    $stmt = $conn->prepare("SELECT id FROM users WHERE email=?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        echo "❌ Такой email уже зарегистрирован.";
        exit;
    }

    // Генерируем код подтверждения
    $code = rand(100000, 999999);

    // Сохраняем пользователя как неподтверждённого
    $stmt = $conn->prepare("INSERT INTO users (name, email, password, verification_code, verified) VALUES (?, ?, ?, ?, 0)");
    $stmt->bind_param("sssi", $name, $email, $password, $code);

    if ($stmt->execute()) {
        // Отправляем письмо
        $result = sendVerificationMail($email, $name, $code);
        if ($result === true) {
            $_SESSION['pending_email'] = $email;
            echo "✅ Регистрация почти завершена! Проверьте почту и введите код <a href='verify.php'>здесь</a>.";
        } else {
            echo "❌ Ошибка при отправке письма: " . $result;
        }
    } else {
        echo "❌ Ошибка регистрации: " . $stmt->error;
    }
}
?>
