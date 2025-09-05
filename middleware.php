<?php
// middleware.php
// Универсальный middleware — старт сессии, обновление last_seen и проверки ролей.

/* Гарантированно стартуем сессию */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Попытка обновить last_seen для текущего пользователя.
   Поддерживаются оба варианта: mysqli ($mysqli) и PDO ($pdo).
   Любые ошибки подавляются — middleware не должен ломать приложение. */
if (!empty($_SESSION['user']['id'])) {
    $userId = (int)$_SESSION['user']['id'];
    $dbFile = __DIR__ . '/db.php';

    if (file_exists($dbFile)) {
        try {
            require_once $dbFile;
        } catch (Throwable $e) {
            // ignore
        }

        try {
            if (isset($mysqli) && $mysqli instanceof mysqli) {
                $stmt = $mysqli->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $stmt->close();
                }
            } elseif (isset($pdo) && $pdo instanceof PDO) {
                $st = $pdo->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
                $st->execute([$userId]);
            }
        } catch (Throwable $e) {
            // ignore DB errors here
        }
    }
}

/* Обновление данных пользователя в сессии (полезно, если роль/имя поменялись в БД).
   Функция безопасно подтянет свежие текущие поля (id,name,phone,role,created_at,verify_code,status,ip)
   и перезапишет $_SESSION['user'] если удалось. */
function refresh_session_user()
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user']['id'])) return;

    $uid = (int)$_SESSION['user']['id'];
    $dbFile = __DIR__ . '/db.php';
    if (!file_exists($dbFile)) return;

    try {
        require_once $dbFile;
    } catch (Throwable $e) {
        return;
    }

    try {
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            if ($st = $mysqli->prepare("SELECT id,name,phone,role,created_at,verify_code,status,ip FROM users WHERE id = ? LIMIT 1")) {
                $st->bind_param('i', $uid);
                $st->execute();
                $res = $st->get_result();
                $fresh = $res ? $res->fetch_assoc() : null;
                $st->close();
                if ($fresh) $_SESSION['user'] = $fresh;
            }
        } elseif (isset($pdo) && $pdo instanceof PDO) {
            $st = $pdo->prepare("SELECT id,name,phone,role,created_at,verify_code,status,ip FROM users WHERE id = :id LIMIT 1");
            $st->execute([':id' => $uid]);
            $fresh = $st->fetch(PDO::FETCH_ASSOC);
            if ($fresh) $_SESSION['user'] = $fresh;
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/* Проверка — залогинен ли пользователь */
function require_auth() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user'])) {
        header('Location: /mehanik/public/login.php');
        exit;
    }
}

/* Проверка — админ-подобный доступ.
   Разрешает роли: admin, superadmin и совместимые варианты. */
function require_admin() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user'])) {
        header('Location: /mehanik/public/login.php');
        exit;
    }

    $role = $_SESSION['user']['role'] ?? null;
    $allowed = ['admin','superadmin','administrator','1', 1];

    if (!in_array($role, $allowed, true)) {
        http_response_code(403);
        echo 'Forbidden — доступ только для админов';
        exit;
    }
}

/* Проверка — только суперадмин */
function require_superadmin() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user'])) {
        header('Location: /mehanik/public/login.php');
        exit;
    }
    $role = $_SESSION['user']['role'] ?? null;
    if ($role !== 'superadmin') {
        http_response_code(403);
        echo 'Forbidden — доступ только для суперадминов';
        exit;
    }
}

/* Экспортируем refresh_session_user для вызова из других мест */
# функция уже определена — можно вызывать refresh_session_user();
