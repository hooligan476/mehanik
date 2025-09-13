<?php
// middleware.php
// Универсальный middleware — старт сессии, обновление last_seen, refresh session user,
// и централизованная проверка session_version (force logout при инвалидации).

/* Гарантированно стартуем сессию */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Подключаем DB (если есть) — один раз */
$dbFile = __DIR__ . '/db.php';
if (file_exists($dbFile)) {
    // require_once безопасно — если db.php уже был загружен, повторно не подключится
    try {
        require_once $dbFile;
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * enforce_session_version(?int $userId = null)
 * Проверяет session_version из БД и сравнивает с $_SESSION['user']['session_version'].
 * При расхождении — аккуратно уничтожает сессию и редиректит на логин.
 *
 * Если $userId == null, пытается взять id из $_SESSION['user'].
 */
function enforce_session_version(?int $userId = null): void {
    if (session_status() === PHP_SESSION_NONE) session_start();

    // получим id
    $uid = $userId ?? (isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0);
    if (!$uid) return; // не залогинен — нечего проверять

    global $mysqli, $pdo;

    try {
        $dbSessionVersion = 0;
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            if ($st = $mysqli->prepare("SELECT COALESCE(session_version,0) AS session_version FROM users WHERE id = ? LIMIT 1")) {
                $st->bind_param('i', $uid);
                $st->execute();
                $res = $st->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $st->close();
                $dbSessionVersion = isset($row['session_version']) ? (int)$row['session_version'] : 0;
            } else {
                // если prepare не поддерживается по какой-то причине, пробуем прямой запрос (редко)
                $res = $mysqli->query("SELECT COALESCE(session_version,0) AS session_version FROM users WHERE id = " . (int)$uid . " LIMIT 1");
                if ($res) {
                    $row = $res->fetch_assoc();
                    $dbSessionVersion = isset($row['session_version']) ? (int)$row['session_version'] : 0;
                }
            }
        } elseif (isset($pdo) && $pdo instanceof PDO) {
            $st = $pdo->prepare("SELECT COALESCE(session_version,0) AS session_version FROM users WHERE id = :id LIMIT 1");
            $st->execute([':id' => $uid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $dbSessionVersion = isset($row['session_version']) ? (int)$row['session_version'] : 0;
        } else {
            // если нет доступного DB-объекта — ничего не делаем
            return;
        }

        $sessVersionInSession = isset($_SESSION['user']['session_version']) ? (int)$_SESSION['user']['session_version'] : null;

        if ($sessVersionInSession !== null && $dbSessionVersion !== $sessVersionInSession) {
            // уничтожаем сессию аккуратно
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            session_destroy();

            // редиректим на логин (включаем reason для UX/логирования)
            header('Location: /mehanik/public/login.php?reason=session_invalidated');
            exit;
        }

        // если в сессии не было версии — записываем текущую (чтобы можно было сравнить позже)
        if ($sessVersionInSession === null) {
            if (!isset($_SESSION['user'])) $_SESSION['user'] = [];
            $_SESSION['user']['session_version'] = $dbSessionVersion;
        }
    } catch (Throwable $e) {
        // silent fail — middleware не должен ломать приложение
        // при необходимости можно раскомментировать: error_log($e->getMessage());
    }
}

/* Попытка обновить last_seen для текущего пользователя.
   Поддерживаются оба варианта: mysqli ($mysqli) и PDO ($pdo).
   Любые ошибки подавляются — middleware не должен ломать приложение. */
if (!empty($_SESSION['user']['id'])) {
    $userId = (int)$_SESSION['user']['id'];

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

/* Обновление данных пользователя в сессии (полезно, если роль/имя поменялись в БД).
   Функция подтянет дополнительные поля: id,name,phone,role,created_at,verify_code,status,ip,balance,session_version
   и аккуратно сольёт их в $_SESSION['user'] (не затирая дополнительные флаги). */
function refresh_session_user(): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user']['id'])) return;

    $uid = (int)$_SESSION['user']['id'];
    global $mysqli, $pdo;

    try {
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            if ($st = $mysqli->prepare("SELECT id,name,phone,role,created_at,verify_code,status,ip, COALESCE(balance,0.00) AS balance, COALESCE(session_version,0) AS session_version FROM users WHERE id = ? LIMIT 1")) {
                $st->bind_param('i', $uid);
                $st->execute();
                $res = $st->get_result();
                $fresh = $res ? $res->fetch_assoc() : null;
                $st->close();
                if ($fresh) {
                    $fresh['balance'] = isset($fresh['balance']) ? (float)$fresh['balance'] : 0.0;
                    $fresh['session_version'] = isset($fresh['session_version']) ? (int)$fresh['session_version'] : 0;
                    $_SESSION['user'] = array_merge((array)$_SESSION['user'], (array)$fresh);
                }
            }
        } elseif (isset($pdo) && $pdo instanceof PDO) {
            $st = $pdo->prepare("SELECT id,name,phone,role,created_at,verify_code,status,ip, COALESCE(balance,0.00) AS balance, COALESCE(session_version,0) AS session_version FROM users WHERE id = :id LIMIT 1");
            $st->execute([':id' => $uid]);
            $fresh = $st->fetch(PDO::FETCH_ASSOC);
            if ($fresh) {
                $fresh['balance'] = isset($fresh['balance']) ? (float)$fresh['balance'] : 0.0;
                $fresh['session_version'] = isset($fresh['session_version']) ? (int)$fresh['session_version'] : 0;
                $_SESSION['user'] = array_merge((array)$_SESSION['user'], (array)$fresh);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/* Проверка — залогинен ли пользователь */
function require_auth(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user'])) {
        header('Location: /mehanik/public/login.php');
        exit;
    }
}

/* Проверка — админ-подобный доступ.
   Разрешает роли: admin, superadmin и совместимые варианты. */
function require_admin(): void {
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
function require_superadmin(): void {
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

/* Включаем enforce_session_version автоматически при загрузке middleware,
   чтобы не требовать ручного вызова с каждой страницы.
   (Если нужно отключить автоматическую проверку — закомментируйте вызов.) */
enforce_session_version(); // <-- централизованная проверка, выполняется при каждом include middleware.php

// Экспортируем функции: refresh_session_user(), enforce_session_version(), require_auth(), require_admin(), require_superadmin()
// их можно вызывать из других скриптов при необходимости.
