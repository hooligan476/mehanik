<?php
// mehanik/public/admin/action_user.php
require_once __DIR__ . '/../../middleware.php';
require_admin(); // allow admin or superadmin

$basePublic = '/mehanik/public';

// DB config (лучше подключать общий db.php, но оставим так для совместимости)
$dbHost = '127.0.0.1';
$dbName = 'mehanik';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB connection failed']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

/**
 * Helper: JSON response
 */
function json_ok($data = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok'=>true], $data));
    exit;
}
function json_err($msg, $code = 400) {
    header('Content-Type: application/json; charset=utf-8', true, $code);
    echo json_encode(['ok'=>false,'error'=>$msg]);
    exit;
}

/* ----------------- GET permissions ----------------- */
/* GET /admin/action_user.php?action=get_permissions&user_id=NN */
if ($action === 'get_permissions' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    if ($userId <= 0) json_err('Invalid user_id', 422);

    // ресурсы
    $resources = ['services','products','users','chats','brands'];

    try {
        $in = implode(',', array_fill(0, count($resources), '?'));
        $sql = "SELECT resource, can_view, can_edit, can_delete 
                FROM user_permissions 
                WHERE user_id = ? AND resource IN ($in)";
        $pr = $pdo->prepare($sql);
        $params = array_merge([$userId], $resources);
        $pr->execute($params);
        $rows = $pr->fetchAll(PDO::FETCH_ASSOC);

        $perms = [];
        foreach ($resources as $r) {
            $perms[$r] = ['can_view'=>0,'can_edit'=>0,'can_delete'=>0];
        }
        foreach ($rows as $row) {
            $res = $row['resource'];
            $perms[$res] = [
                'can_view' => (int)$row['can_view'],
                'can_edit' => (int)$row['can_edit'],
                'can_delete'=> (int)$row['can_delete']
            ];
        }

        json_ok(['permissions'=>$perms]);
    } catch (Throwable $e) {
        json_err('DB error: ' . $e->getMessage(), 500);
    }
}

/* ----------------- Update permissions (POST) ----------------- */
/* POST /admin/action_user.php?action=update_permissions */
if ($action === 'update_permissions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // only superadmin allowed to change
    $currentRole = $_SESSION['user']['role'] ?? '';
    if ($currentRole !== 'superadmin') {
        header("Location: {$basePublic}/admin/permissions.php?user_id=".(int)($_POST['user_id'] ?? 0)."&err=" . urlencode('Только супер-админ может менять права'));
        exit;
    }

    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    if ($userId <= 0) {
        header("Location: {$basePublic}/admin/users.php?err=" . urlencode('Некорректный user_id'));
        exit;
    }

    $resources = ['services','products','users','chats','brands'];

    try {
        $pdo->beginTransaction();

        $select = $pdo->prepare("SELECT id FROM user_permissions WHERE user_id = :uid AND resource = :res LIMIT 1");
        $update = $pdo->prepare("UPDATE user_permissions 
                                 SET can_view = :cv, can_edit = :ce, can_delete = :cd, updated_at = NOW() 
                                 WHERE id = :id");
        $insert = $pdo->prepare("INSERT INTO user_permissions 
            (user_id, resource, can_view, can_edit, can_delete, created_at, updated_at) 
            VALUES (:uid, :res, :cv, :ce, :cd, NOW(), NOW())");

        foreach ($resources as $res) {
            $cv = isset($_POST["perm_{$res}_view"]) && $_POST["perm_{$res}_view"] === '1' ? 1 : 0;
            $ce = isset($_POST["perm_{$res}_edit"]) && $_POST["perm_{$res}_edit"] === '1' ? 1 : 0;
            $cd = isset($_POST["perm_{$res}_delete"]) && $_POST["perm_{$res}_delete"] === '1' ? 1 : 0;

            $select->execute([':uid'=>$userId, ':res'=>$res]);
            $found = $select->fetchColumn();
            if ($found) {
                $update->execute([':cv'=>$cv, ':ce'=>$ce, ':cd'=>$cd, ':id'=>$found]);
            } else {
                $insert->execute([':uid'=>$userId, ':res'=>$res, ':cv'=>$cv, ':ce'=>$ce, ':cd'=>$cd]);
            }
        }

        $pdo->commit();
        header("Location: {$basePublic}/admin/permissions.php?user_id={$userId}&msg=" . urlencode('Права обновлены'));
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        header("Location: {$basePublic}/admin/permissions.php?user_id={$userId}&err=" . urlencode('DB error'));
        exit;
    }
}

/* ----------------- User management actions (approve/reject/set_pending/delete) ----------------- */
/* These are POST actions invoked from admin/users.php forms */

if (in_array($action, ['approve','reject','set_pending','delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // require admin or superadmin (we have require_admin() at top)
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
        header("Location: {$basePublic}/admin/users.php?err=" . urlencode('Нет ID'));
        exit;
    }

    try {
        if ($action === 'approve') {
            $st = $pdo->prepare("UPDATE users SET status = 'approved' WHERE id = ?");
            $st->execute([$id]);
            header("Location: {$basePublic}/admin/users.php?msg=" . urlencode('Пользователь подтверждён'));
            exit;
        }

        if ($action === 'reject') {
            $st = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $st->execute([$id]);
            header("Location: {$basePublic}/admin/users.php?msg=" . urlencode('Пользователь удалён'));
            exit;
        }

        if ($action === 'set_pending') {
            $st = $pdo->prepare("UPDATE users SET status = 'pending' WHERE id = ?");
            $st->execute([$id]);
            header("Location: {$basePublic}/admin/users.php?msg=" . urlencode('Статус изменён на pending'));
            exit;
        }

        if ($action === 'delete') {
            $st = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $st->execute([$id]);
            header("Location: {$basePublic}/admin/users.php?msg=" . urlencode('Пользователь удалён'));
            exit;
        }
    } catch (Throwable $e) {
        header("Location: {$basePublic}/admin/users.php?err=" . urlencode('DB error'));
        exit;
    }
}

// Unknown action
if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
    json_err('Unknown action', 404);
} else {
    header("Location: {$basePublic}/admin/users.php?err=" . urlencode('Unknown action'));
    exit;
}
