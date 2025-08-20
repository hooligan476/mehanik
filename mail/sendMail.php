<?php
// mail/sendMail.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * sendVerificationMail(string $email, string $name, int $code): bool|string
 * - возвращает true при успехе
 * - возвращает строку с текстом ошибки при неуспехе
 *
 * Рекомендуется задать параметры в config.php:
 *   define('SMTP_HOST', 'smtp.gmail.com');
 *   define('SMTP_PORT', 587);
 *   define('SMTP_USER', 'your@email.com');
 *   define('SMTP_PASS', 'your-app-password');
 *   define('SMTP_FROM', 'no-reply@yourdomain.com');
 *   define('SMTP_FROM_NAME', 'Mehanik Support');
 *   // опционально:
 *   define('SMTP_SECURE', 'tls'); // 'tls' или 'ssl' или '' (none)
 *   define('SMTP_ALLOW_INSECURE', true); // для локальной разработки (не для продакшена)
 */
function sendVerificationMail($email, $name, $code) {
    // Лог-файл
    $root = dirname(__DIR__);
    $logDir = $root . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logFile = $logDir . '/mail.log';

    // Конфигурация (из config.php если определено, иначе - безопасные значения по умолчанию)
    $host = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
    $port = defined('SMTP_PORT') ? SMTP_PORT : 587;
    $user = defined('SMTP_USER') ? SMTP_USER : 'garly6ka.tm95@gmail.com'; // замените на ваш логин
    $pass = defined('SMTP_PASS') ? SMTP_PASS : 'hnnc jepl vxpf ebcj'; // НЕ храните пароли в репозитории
    $from = defined('SMTP_FROM') ? SMTP_FROM : $user;
    $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Mehanik Support';
    $secure = defined('SMTP_SECURE') ? SMTP_SECURE : 'tls'; // 'tls' или 'ssl' или ''
    $allowInsecure = defined('SMTP_ALLOW_INSECURE') ? SMTP_ALLOW_INSECURE : false; // dev only

    $mail = new PHPMailer(true);
    try {
        // Настройки SMTP
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        // выберем режим шифрования
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
        }
        $mail->Port       = (int)$port;

        // Опции TLS (полезно на локалке с самоподписанным cert)
        if ($allowInsecure) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        // From / To
        $mail->setFrom($from, $fromName);
        $mail->addAddress($email, $name);

        // Контент
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Подтверждение регистрации на Mehanik';
        $htmlBody = "<p>Здравствуйте, " . htmlspecialchars($name) . "!</p>"
                  . "<p>Ваш код подтверждения: <b>" . intval($code) . "</b></p>"
                  . "<p>Введите его на сайте для активации аккаунта.</p>";
        $plainBody = "Здравствуйте, {$name}!\n\nВаш код подтверждения: {$code}\n\nВведите его на сайте для активации аккаунта.";

        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainBody;

        // Отправка
        $mail->send();

        // Лог успеха (по желанию)
        file_put_contents($logFile, date('c') . " Mail sent to {$email}\n", FILE_APPEND | LOCK_EX);
        return true;
    } catch (Exception $e) {
        // Логируем подробности
        $err = date('c') . " PHPMailer error to {$email}: " . $e->getMessage() . " | ErrorInfo: " . $mail->ErrorInfo . "\n";
        file_put_contents($logFile, $err, FILE_APPEND | LOCK_EX);

        // Безопасно возвращаем сообщение об ошибке для отладки
        return "Ошибка при отправке письма: " . $e->getMessage();
    }
}
