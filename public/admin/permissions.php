<?php
// mehanik/public/admin/permissions.php
// Просмотр: admin или superadmin; сохранение — только superadmin

require_once __DIR__ . '/../../middleware.php';
require_admin(); // разрешаем admin и superadmin просматривать страницу

// текущая роль и флаг супер-админа
$currentRole = $_SESSION['user']['role'] ?? '';
$isSuper = ($currentRole === 'superadmin');

$basePublic = '/mehanik/public';

// попытка подключить общий db.php (несколько популярных путей)
$dbIncluded = false;
$dbCandidates = [
    __DIR__ . '/../db.php',       // mehanik/public/db.php
    __DIR__ . '/../../db.php',    // mehanik/db.php
    __DIR__ . '/db.php'           // на всякий случай
];
foreach ($dbCandidates as $f) {
    if (file_exists($f)) {
        try { require_once $f; $dbIncluded = true; break; } catch (Throwable $e) {}
    }
}

// если $pdo не определён — попытка создать своё PDO (настройки по-умолчанию)
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $dbHost = '127.0.0.1';
    $dbName = 'mehanik';
    $dbUser = 'root';
    $dbPass = '';

    try {
        $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    } catch (PDOException $e) {
        // если не получилось подключиться — аккуратно прекратить и показать ошибку
        header('Content-Type: text/plain; charset=utf-8', true, 500);
        echo "DB connection failed: " . $e->getMessage();
        exit;
    }
}

// параметры и проверка user_id
$uid = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($uid <= 0) {
    header('Location: ' . $basePublic . '/admin/users.php?err=' . urlencode('No user_id'));
    exit;
}

// msg / err для отображения после редиректа
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

// ресурсы — добавлены users, chats, brands
$resources = [
    'services' => 'Сервисы / Услуги',
    'products' => 'Товары',
    'users'    => 'Пользователи',
    'chats'    => 'Чаты',
    'brands'   => 'Бренды / Модели'
];

// получаем информацию о пользователе (для имени и fallback прав)
try {
    $stUser = $pdo->prepare("SELECT id, name, phone, role, can_view, can_edit, can_delete FROM users WHERE id = ? LIMIT 1");
    $stUser->execute([$uid]);
    $user = $stUser->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        header('Location: ' . $basePublic . '/admin/users.php?err=' . urlencode('User not found'));
        exit;
    }
} catch (Throwable $e) {
    header('Location: ' . $basePublic . '/admin/users.php?err=' . urlencode('DB error'));
    exit;
}

// Загружаем существующие записи в user_permissions для всех ресурсов
$placeholders = implode(',', array_fill(0, count($resources), '?'));
$sql = "SELECT resource, can_view, can_edit, can_delete FROM user_permissions WHERE user_id = ? AND resource IN ($placeholders)";
$params = array_merge([$uid], array_keys($resources));

$perms = [];
// дефолт из полей users (fallback)
foreach ($resources as $k => $label) {
    $perms[$k] = [
        'can_view' => isset($user['can_view']) ? (int)$user['can_view'] : 0,
        'can_edit' => isset($user['can_edit']) ? (int)$user['can_edit'] : 0,
        'can_delete' => isset($user['can_delete']) ? (int)$user['can_delete'] : 0,
    ];
}

try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $res = $r['resource'];
        if (isset($perms[$res])) {
            $perms[$res] = [
                'can_view' => (int)$r['can_view'],
                'can_edit' => (int)$r['can_edit'],
                'can_delete' => (int)$r['can_delete']
            ];
        }
    }
} catch (Throwable $e) {
    // если ошибка — оставляем дефолты и показываем предупреждение ниже
    $err = $err ?: 'Ошибка при чтении прав: ' . $e->getMessage();
}

// --- HTML ---
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Права — Пользователь #<?= htmlspecialchars($uid, ENT_QUOTES) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/mehanik/assets/css/users.css">
<style>
/* расширенный контейнер, более читабельный */
.container{max-width:1100px;margin:22px auto;padding:20px;background:#fff;border-radius:10px;box-shadow:0 6px 18px rgba(2,6,23,0.04)}
.topbar{display:flex;gap:12px;align-items:center;justify-content:space-between;margin-bottom:14px}
.title{font-size:20px;font-weight:700;color:#111827}
.legend{color:#6b7280;margin-bottom:12px}
.row{display:flex;gap:12px;align-items:center;margin-bottom:8px;flex-wrap:wrap}
.checkbox{display:flex;gap:8px;align-items:center}
.btn{padding:8px 12px;border-radius:8px;border:0;background:#0b57a4;color:#fff;cursor:pointer}
.btn.ghost{background:transparent;color:#0b57a4;border:1px solid #e6eef7}
.res-block{border:1px solid #eef2f6;padding:12px;border-radius:8px;margin-bottom:12px}
.notice { padding:10px 12px;border-radius:8px;margin-bottom:12px; }
.notice.ok { background:#eaf7ef;color:#116530;border:1px solid #cfead1; }
.notice.err { background:#fff6f6;color:#7a1f1f;border:1px solid #f2c6c6; }
.small { font-size:13px;color:#6b7280; }
@media (max-width:900px){ .container{padding:14px} }
</style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="container">
  <div class="topbar">
    <div>
      <a href="<?= $basePublic ?>/admin/users.php" class="btn ghost">← Вернуться в список</a>
    </div>
    <div class="title">Права — <?= htmlspecialchars($user['name'] ?? ('#'.$uid), ENT_QUOTES) ?> (ID <?= $uid ?>)</div>
  </div>

  <?php if ($msg): ?>
    <div class="notice ok"><?= htmlspecialchars($msg, ENT_QUOTES) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="notice err"><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
  <?php endif; ?>

  <div class="legend">Управление правами по ресурсам. Просмотр доступен администраторам, изменение — только супер-админ.</div>

  <form method="post" action="<?= htmlspecialchars($basePublic . '/admin/action_user.php?action=update_permissions') ?>">
    <input type="hidden" name="user_id" value="<?= $uid ?>">

    <?php foreach ($resources as $res => $label):
        $cur = $perms[$res] ?? ['can_view'=>0,'can_edit'=>0,'can_delete'=>0];
    ?>
      <div class="res-block">
        <div style="font-weight:700;margin-bottom:8px;"><?= htmlspecialchars($label) ?></div>
        <div class="row">
          <label class="checkbox">
            <input type="checkbox" name="perm_<?= $res ?>_view" value="1" <?= $cur['can_view'] ? 'checked' : '' ?> <?= $isSuper ? '' : 'disabled' ?>>
            Просмотр
          </label>

          <label class="checkbox">
            <input type="checkbox" name="perm_<?= $res ?>_edit" value="1" <?= $cur['can_edit'] ? 'checked' : '' ?> <?= $isSuper ? '' : 'disabled' ?>>
            Редактирование
          </label>

          <label class="checkbox">
            <input type="checkbox" name="perm_<?= $res ?>_delete" value="1" <?= $cur['can_delete'] ? 'checked' : '' ?> <?= $isSuper ? '' : 'disabled' ?>>
            Удаление
          </label>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($isSuper): ?>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="submit" class="btn">Сохранить</button>
      </div>
    <?php else: ?>
      <div style="color:#6b7280;padding:10px;border:1px dashed #eef2f6;border-radius:8px">Вы не супер-админ — права нельзя менять.</div>
    <?php endif; ?>
  </form>
</div>

</body>
</html>
