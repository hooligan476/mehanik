<?php
// middleware.php

// Гарантированно стартуем сессию если она ещё не запущена
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
