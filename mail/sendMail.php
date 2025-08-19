<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../vendor/autoload.php';

function sendVerificationMail($email, $name, $code) {
    $mail = new PHPMailer(true);

    try {
        // Настройки SMTP
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'garly6ka.tm95@gmail.com'; // твой Gmail
        $mail->Password   = 'hnnc jepl vxpf ebcj';    // новый пароль приложения
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // От кого
        $mail->setFrom('your_email@gmail.com', 'Mehanik Support');
        $mail->addAddress($email, $name);

        // Контент письма
        $mail->isHTML(true);
        $mail->Subject = 'Подтверждение регистрации';
        $mail->Body    = "Здравствуйте, $name!<br><br>Ваш код подтверждения: <b>$code</b><br><br>Введите его на сайте для активации аккаунта.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return "Ошибка при отправке письма: {$mail->ErrorInfo}";
    }
}
