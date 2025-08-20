<?php
/**
 * config.php
 * Основные настройки приложения.
 *
 * ВНИМАНИЕ: не храните секреты (пароли) в публичных репозиториях.
 * Лучше заменить значения на переменные окружения в продакшене.
 */

$config = [
  'db' => [
    'host' => '127.0.0.1',
    'user' => 'root',
    'pass' => '',
    'name' => 'mehanik',
    'charset' => 'utf8mb4',
  ],

  // URL до публичной папки (относительно корня веб-сервера)
  'base_url' => '/mehanik/public',

  // Настройки SMTP (заполните реальными значениями)
  'smtp' => [
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'user' => 'garly6ka.tm95@gmail.com', // Ваш SMTP логин (обычно email)
    'pass' => 'hnnc jepl vxpf ebcj', // Пароль приложения (не обычный пароль!)
    'from' => 'no-reply@mehanik.com.tm',
    'from_name' => 'Mehanik Support',
    'secure' => 'tls', // 'tls' или 'ssl' или ''
    'allow_insecure' => false, // Только для локальной разработки, НЕ включать в проде
  ],

];

// --- Для совместимости с кодом, который использует define('SMTP_HOST', ...)
if (!defined('SMTP_HOST')) define('SMTP_HOST', $config['smtp']['host']);
if (!defined('SMTP_PORT')) define('SMTP_PORT', $config['smtp']['port']);
if (!defined('SMTP_USER')) define('SMTP_USER', $config['smtp']['user']);
if (!defined('SMTP_PASS')) define('SMTP_PASS', $config['smtp']['pass']);
if (!defined('SMTP_FROM')) define('SMTP_FROM', $config['smtp']['from']);
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', $config['smtp']['from_name']);
if (!defined('SMTP_SECURE')) define('SMTP_SECURE', $config['smtp']['secure']);
if (!defined('SMTP_ALLOW_INSECURE')) define('SMTP_ALLOW_INSECURE', $config['smtp']['allow_insecure']);

return $config;
