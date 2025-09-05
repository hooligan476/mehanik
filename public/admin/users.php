<?php
// public/admin/users.php (simplified — "Права" ведёт на permissions.php)
session_start();

// доступ только admin или superadmin
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['admin','superadmin'], true)) {
    header('Location: /mehanik/public/login.php');
    exit;
}

// DB (как было)
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

// сообщения
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

// пагинация
$perPage = 10;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// сортировка
$allowedSort = ['id','name','phone','role','created_at','last_seen','status'];
$sort = $_GET['sort'] ?? 'created_at';
$dir  = strtolower($_GET['dir'] ?? 'desc');

if (!in_array($sort, $allowedSort)) $sort = 'created_at';
if (!in_array($dir, ['asc','desc'])) $dir = 'desc';

$totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalPages = max(1, (int)ceil($totalUsers / $perPage));

// выборка
$sql = "SELECT id, name, phone, role, created_at, last_seen, verify_code, status, ip
        FROM users 
        ORDER BY {$sort} {$dir}
        LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$basePublic = '/mehanik/public';

// helper для ссылки сортировки
function sortLink($col, $title, $currentSort, $currentDir, $page) {
    $dir = ($currentSort === $col && $currentDir === 'asc') ? 'desc' : 'asc';
    $arrow = '';
    if ($currentSort === $col) {
        $arrow = $currentDir === 'asc' ? ' ▲' : ' ▼';
    }
    $q = '?page=' . (int)$page . '&sort=' . urlencode($col) . '&dir=' . $dir;
    return "<a href=\"{$q}\" class=\"sort-link\">{$title}{$arrow}</a>";
}

$labelMap = [
    'id' => 'ID',
    'name' => 'Имя',
    'phone' => 'Телефон',
    'role' => 'Роль',
    'created_at' => 'Создан',
    'last_seen' => 'Был онлайн',
    'status' => 'Статус'
];
$currentSortLabel = $labelMap[$sort] ?? $sort;
$currentDirLabel = $dir === 'asc' ? 'по возрастанию' : 'по убыванию';

// определим, является ли текущий пользователь superadmin (на всякий случай)
$currentUserRole = $_SESSION['user']['role'] ?? '';
$isSuperadmin = ($currentUserRole === 'superadmin');

?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Админ — Пользователи</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/mehanik/assets/css/users.css"> <!-- если вынесли стили -->
<style>
/* Базовые стили (фолбэк, если users.css нет) */
body { font-family: Arial, sans-serif; background:#f5f6fa; margin:0; padding:0; }
.header-wrap { padding:16px 24px; background:#fff; border-bottom:1px solid #edf0f4; box-shadow:0 1px 0 rgba(0,0,0,0.02); }
.header-wrap h2 { margin:0; font-size:20px; color:#111827; }
.container { width:100%; max-width:none; margin:0; padding:20px 24px; box-sizing:border-box; }
.message { margin:10px 0; padding:10px; border-radius:6px; }
.message.success { background:#eafaf1; color:#2e7d32; border:1px solid #c8e6c9; }
.message.error { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; }
.sort-info { margin:12px 0 6px; color:#374151; font-size:14px; }
.table-wrap { overflow-x:auto; background:transparent; }
table { border-collapse: collapse; width:100%; min-width:1100px; background:#fff; border-radius:8px; box-shadow:0 6px 18px rgba(2,6,23,0.04); }
thead th { position:sticky; top:0; background:#fbfcfe; z-index:2; text-align:left; padding:12px; border-bottom:1px solid #eef2f6; font-weight:700; color:#374151; }
th, td { padding:12px 14px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
tbody tr:hover td { background:#fbfbfd; }
.sort-link { text-decoration:none; color:#0f172a; display:inline-block; }
th.sorted { background:#e8f1ff; }
.button { padding:6px 12px; border:none; border-radius:6px; cursor:pointer; margin:2px; font-weight:600; }
.approve { background:#2ecc71; color:white; }
.reject { background:#e74c3c; color:white; }
.pending { background:#f39c12; color:white; }

.btn-rights { padding:6px 10px; border-radius:6px; background:#0b57a4; color:#fff; text-decoration:none; font-weight:700; border:0; cursor:pointer; display:inline-block; }
.btn-rights.viewonly { background:#6c757d; }

.pagination { margin-top:16px; text-align:center; padding-bottom:12px; }
.pagination a { padding:8px 12px; border:1px solid #e6e9ef; margin:2px; border-radius:8px; text-decoration:none; color:#0f172a; display:inline-block; }
.pagination a.active { background:#0b57a4; color:#fff; border-color:#0b57a4; }

.ip-cell { color:#6b7280; font-size:13px; }
.small { font-size:13px; color:#6b7280; }

@media (max-width:900px){
  table { min-width:900px; }
  th, td { padding:10px; }
}
</style>
</head>
<body>
    <?php require_once __DIR__.'/header.php'; ?>
<div class="header-wrap">
  <h2>Админ — Пользователи</h2>
</div>

<div class="container">
  <?php if ($msg): ?><div class="message success"><?=htmlspecialchars($msg)?></div><?php endif; ?>
  <?php if ($err): ?><div class="message error"><?=htmlspecialchars($err)?></div><?php endif; ?>

  <div class="sort-info">Сортировка: <strong><?=htmlspecialchars($currentSortLabel)?> (<?=htmlspecialchars($currentDirLabel)?>)</strong></div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
            <th class="<?= $sort === 'id' ? 'sorted' : '' ?>"><?=sortLink('id','ID',$sort,$dir,$page)?></th>
            <th class="<?= $sort === 'name' ? 'sorted' : '' ?>"><?=sortLink('name','Имя',$sort,$dir,$page)?></th>
            <th class="<?= $sort === 'phone' ? 'sorted' : '' ?>"><?=sortLink('phone','Телефон',$sort,$dir,$page)?></th>
            <th>IP</th>
            <th class="<?= $sort === 'role' ? 'sorted' : '' ?>"><?=sortLink('role','Роль',$sort,$dir,$page)?></th>
            <th class="<?= $sort === 'created_at' ? 'sorted' : '' ?>"><?=sortLink('created_at','Создан',$sort,$dir,$page)?></th>
            <th class="<?= $sort === 'last_seen' ? 'sorted' : '' ?>"><?=sortLink('last_seen','Был онлайн',$sort,$dir,$page)?></th>
            <th>Код</th>
            <th class="<?= $sort === 'status' ? 'sorted' : '' ?>"><?=sortLink('status','Статус',$sort,$dir,$page)?></th>
            <th>Действие</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
            <td><?=htmlspecialchars($u['id'])?></td>
            <td><?=htmlspecialchars($u['name'])?></td>
            <td><?=htmlspecialchars($u['phone'])?></td>
            <td class="ip-cell"><?=htmlspecialchars($u['ip'] ?? '-')?></td>
            <td><?=htmlspecialchars($u['role'])?></td>
            <td class="small"><?=htmlspecialchars($u['created_at'])?></td>
            <td class="small"><?= $u['last_seen'] ? htmlspecialchars($u['last_seen']) : '—' ?></td>
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

                <!-- Права: переход на отдельную страницу управления правами -->
                <?php
                  // ссылка ведёт на permissions.php?user_id=ID — там будем реализовывать сохранение/просмотр прав
                  $permUrl = $basePublic . '/admin/permissions.php?user_id=' . (int)$u['id'];
                ?>
                <a href="<?= htmlspecialchars($permUrl) ?>"
                   class="btn-rights <?= $isSuperadmin ? '' : 'viewonly' ?>"
                   title="<?= $isSuperadmin ? 'Управление правами' : 'Просмотр прав' ?>">
                  Права
                </a>
            </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="pagination" role="navigation" aria-label="Пагинация">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <a href="?page=<?=$i?>&sort=<?=urlencode($sort)?>&dir=<?=urlencode($dir)?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
</div>

</body>
</html>
