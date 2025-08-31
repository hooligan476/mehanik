<?php
// middleware.php

// Гарантированно стартуем сессию если она ещё не запущена
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Попытка обновить last_seen для текущего пользователя.
 * Не ломает выполнение, если база недоступна или файл db.php отсутствует.
 * Поддерживаются как mysqli ($mysqli), так и PDO ($pdo).
 */
if (!empty($_SESSION['user']['id'])) {
    $userId = (int)$_SESSION['user']['id'];

    // Путь к db.php (обычно находится рядом с middleware.php)
    $dbFile = __DIR__ . '/db.php';

    if (file_exists($dbFile)) {
        // require_once — не перезагрузит файл, если он уже был подключён ранее
        try {
            require_once $dbFile;
        } catch (Throwable $e) {
            // Подавляем любые ошибки при подключении db.php, чтобы middleware не ломался
        }

        // Если в проекте используется mysqli (обычно $mysqli в db.php)
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            try {
                $stmt = $mysqli->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $stmt->close();
                }
            } catch (Throwable $e) {
                // подавляем ошибки — важнее не прерывать выполнение приложения
            }
        }
        // Если в проекте используется PDO (в редких случаях)
        elseif (isset($pdo) && $pdo instanceof PDO) {
            try {
                $st = $pdo->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
                $st->execute([$userId]);
            } catch (Throwable $e) {
                // подавляем ошибки
            }
        }
    }
}

/**
 * Проверка, что пользователь залогинен.
 * Если не залогинен — редирект на страницу логина.
 */
function require_auth() {
    if (session_status() === PHP_SESSION_NONE) session_start();

    if (empty($_SESSION['user'])) {
        header('Location: /mehanik/public/login.php');
        exit;
    }
}

/**
 * Проверка, что пользователь - админ.
 * Если не залогинен — редирект на логин.
 * Если залогинен, но не админ — выдаём Forbidden.
 *
 * Поддерживаются разные варианты хранения роли (строка 'admin',
 * 'administrator', или числовой флаг 1/'1'). При необходимости добавьте свои варианты.
 */
function require_admin() {
    if (session_status() === PHP_SESSION_NONE) session_start();

    if (empty($_SESSION['user'])) {
        // Если нет сессии — отправляем на логин
        header('Location: /mehanik/public/login.php');
        exit;
    }

    $role = $_SESSION['user']['role'] ?? null;

    // Если в вашей БД роль хранится по-другому — добавьте варианты в этот массив
    $allowed = ['admin', 'administrator', '1', 1];

    if (!in_array($role, $allowed, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}
