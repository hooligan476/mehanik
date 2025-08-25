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
$perPage = 10; // пользователей на страницу
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Всего пользователей
$totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalPages = ceil($totalUsers / $perPage);

// Получим пользователей (с ip тоже)
$stmt = $pdo->prepare("SELECT id, name, phone, role, created_at, verify_code, status, ip 
                       FROM users ORDER BY created_at DESC 
                       LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Базовый путь для public
$basePublic = '/mehanik/public';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Админ - Пользователи</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
table { border-collapse: collapse; width:100%; }
th, td { border:1px solid #ddd; padding:8px; text-align:left; }
th { background:#f4f4f4; }
.button { padding:6px 10px; margin:2px; cursor:pointer; }
.approve { background:#4CAF50; color:white; border:none; }
.reject { background:#F44336; color:white; border:none; }
.pagination { margin-top:15px; }
.pagination a { padding:6px 10px; border:1px solid #ddd; margin:2px; text-decoration:none; }
.pagination .active { background:#4CAF50; color:white; }
</style>
</head>
<body>
<h2>Админ — Пользователи</h2>

<p>
  <a href="<?= $basePublic ?>/index.php">Вернуться на сайт</a> |
  <a href="<?= $basePublic ?>/admin/index.php">Админ главная</a> |
  <a href="<?= $basePublic ?>/logout.php">Выйти</a>
</p>

<?php if ($msg): ?><div style="color:green;"><?=htmlspecialchars($msg)?></div><?php endif; ?>
<?php if ($err): ?><div style="color:red;"><?=htmlspecialchars($err)?></div><?php endif; ?>

<table>
    <thead>
        <tr>
            <th>ID</th><th>Имя</th><th>Телефон</th><th>IP</th><th>Роль</th><th>Создан</th><th>Код</th><th>Статус</th><th>Действие</th>
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
                        <button class="button approve" type="submit">Approve</button>
                    </form>

                    <form style="display:inline" method="post" action="<?= $basePublic ?>/admin/action_user.php" onsubmit="return confirm('Отклонить пользователя ID <?= $u['id'] ?>?')">
                        <input type="hidden" name="id" value="<?=htmlspecialchars($u['id'])?>">
                        <input type="hidden" name="action" value="reject">
                        <button class="button reject" type="submit">Delete</button>
                    </form>
                <?php else: ?>
                    <form style="display:inline" method="post" action="<?= $basePublic ?>/admin/action_user.php" onsubmit="return confirm('Установить статус pending для ID <?= $u['id'] ?>?')">
                        <input type="hidden" name="id" value="<?=htmlspecialchars($u['id'])?>">
                        <input type="hidden" name="action" value="set_pending">
                        <button class="button" type="submit">Set Pending</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<!-- Пагинация -->
<div class="pagination">
  <?php for ($i = 1; $i <= $totalPages; $i++): ?>
    <a href="?page=<?=$i?>" class="<?= $i === $page ? 'active' : '' ?>"><?=$i?></a>
  <?php endfor; ?>
</div>

</body>
</html>
