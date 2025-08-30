<?php
// htdocs/mehanik/admin/users.php
session_start();

// ПРОВЕРКА: доступ только для админа
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'admin') {
    header('Location: /mehanik/public/login.php');
    exit;
}

// DB настройки
$dbHost = '127.0.0.1';
$dbName = 'mehanik';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("DB error: " . $e->getMessage());
}

// Сообщения (через GET)
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

// ПАГИНАЦИЯ
$perPage = 10;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalPages = ceil($totalUsers / $perPage);

// Пользователи
$stmt = $pdo->prepare("SELECT id, name, phone, role, created_at, verify_code, status, ip 
                       FROM users ORDER BY created_at DESC 
                       LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$basePublic = '/mehanik/public';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Админ — Пользователи</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body {
    font-family: Arial, sans-serif;
    background:#f5f6fa;
    margin:0;
    padding:0;
}
.container {
    max-width:1200px;
    margin:20px auto;
    background:#fff;
    border-radius:10px;
    padding:20px 30px;
    box-shadow:0 2px 6px rgba(0,0,0,.1);
}
h2 { margin-bottom:20px; }
.message { margin:10px 0; padding:10px; border-radius:6px; }
.message.success { background:#eafaf1; color:#2e7d32; border:1px solid #c8e6c9; }
.message.error { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; }
table { border-collapse: collapse; width:100%; margin-top:15px; }
th, td { padding:10px 12px; border-bottom:1px solid #eee; text-align:left; }
th { background:#f9f9f9; }
tr:hover { background:#fafafa; }
.button {
    padding:6px 12px;
    border:none;
    border-radius:6px;
    cursor:pointer;
    margin:2px;
}
.approve { background:#2ecc71; color:white; }
.reject { background:#e74c3c; color:white; }
.pending { background:#f39c12; color:white; }
.pagination {
    margin-top:20px;
    text-align:center;
}
.pagination a {
    padding:6px 12px;
    border:1px solid #ddd;
    margin:2px;
    border-radius:6px;
    text-decoration:none;
    color:#333;
}
.pagination a.active {
    background:#3498db;
    color:#fff;
    border-color:#3498db;
}
</style>
</head>
<body>
<?php require_once __DIR__.'/header.php'; ?>
<div class="container">
<h2>Админ — Пользователи</h2>

<?php if ($msg): ?><div class="message success"><?=htmlspecialchars($msg)?></div><?php endif; ?>
<?php if ($err): ?><div class="message error"><?=htmlspecialchars($err)?></div><?php endif; ?>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Имя</th>
            <th>Телефон</th>
            <th>IP</th>
            <th>Роль</th>
            <th>Создан</th>
            <th>Код</th>
            <th>Статус</th>
            <th>Действие</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><?=htmlspecialchars($u['id'])?></td>
            <td><?=htmlspecialchars($u['name'])?></td>
            <td><?=htmlspecialchars($u['phone'])?></td>
            <td><?=htmlspecialchars($u['ip'] ?? '-')?></td>
            <td><?=htmlspecialchars($u['role'])?></td>
            <td><?=htmlspecialchars($u['created_at'])?></td>
            <td><?=htmlspecialchars($u['verify_code'])?></td>
            <td><?=htmlspecialchars($u['status'])?></td>
            <td>
                <?php if ($u['status'] === 'pending'): ?>
                    <form style="display:inline" method="post" action="<?= $basePublic ?>/admin/action_user.php" onsubmit="return confirm('Подтвердить пользователя ID <?= $u['id'] ?>?')">
                        <input type="hidden" name="id" value="<?=htmlspecialchars($u['id'])?>">
                        <input type="hidden" name="action" value="approve">
                        <button class="button approve" type="submit">Подтвердить</button>
                    </form>
                    <form style="display:inline" method="post" action="<?= $basePublic ?>/admin/action_user.php" onsubmit="return confirm('Отклонить пользователя ID <?= $u['id'] ?>?')">
                        <input type="hidden" name="id" value="<?=htmlspecialchars($u['id'])?>">
                        <input type="hidden" name="action" value="reject">
                        <button class="button reject" type="submit">Отклонить</button>
                    </form>
                <?php else: ?>
                    <form style="display:inline" method="post" action="<?= $basePublic ?>/admin/action_user.php" onsubmit="return confirm('Установить статус pending для ID <?= $u['id'] ?>?')">
                        <input type="hidden" name="id" value="<?=htmlspecialchars($u['id'])?>">
                        <input type="hidden" name="action" value="set_pending">
                        <button class="button pending" type="submit">Вернуть в Pending</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<div class="pagination">
  <?php for ($i = 1; $i <= $totalPages; $i++): ?>
    <a href="?page=<?=$i?>" class="<?= $i === $page ? 'active' : '' ?>"><?=$i?></a>
  <?php endfor; ?>
</div>
</div>
</body>
</html>
