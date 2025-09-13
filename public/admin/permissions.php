<?php
// mehanik/public/admin/permissions_fixed.php
// Управление правами и ролью пользователя
require_once __DIR__ . '/../../middleware.php';
require_admin(); // разрешаем admin и superadmin просматривать страницу

// определяем, является ли текущий пользователь супер-админом
$currentRole = $_SESSION['user']['role'] ?? '';
$isSuper = ($currentRole === 'superadmin') || (isset($_SESSION['user']['is_superadmin']) && (int)$_SESSION['user']['is_superadmin'] === 1);

$basePublic = '/mehanik/public';

// подключение db (несколько мест)
$dbIncluded = false;
$dbCandidates = [
    __DIR__ . '/../db.php',       // mehanik/public/db.php
    __DIR__ . '/../../db.php',    // mehanik/db.php
    __DIR__ . '/db.php'
];
foreach ($dbCandidates as $f) {
    if (file_exists($f)) {
        try { require_once $f; $dbIncluded = true; break; } catch (Throwable $e) {}
    }
}

// если $pdo не установлен, создаём PDO (дефолт)
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
        header('Content-Type: text/plain; charset=utf-8', true, 500);
        echo "DB connection failed: " . $e->getMessage();
        exit;
    }
}

// валидный user_id из GET
$uid = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($uid <= 0) {
    header('Location: ' . $basePublic . '/admin/users.php?err=' . urlencode('No user_id'));
    exit;
}

// опциональные сообщения
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

// ресурсы для управления правами
$resources = [
    'services' => 'Сервисы / Услуги',
    'products' => 'Товары',
    'users'    => 'Пользователи',
    'chats'    => 'Чаты',
    'brands'   => 'Бренды / Модели'
];

// получаем пользователя (основные поля)
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

// подготовка прав — сначала из полей users (fallback)
$perms = [];
foreach ($resources as $k => $label) {
    $perms[$k] = [
        'can_view' => isset($user['can_view']) ? (int)$user['can_view'] : 0,
        'can_edit' => isset($user['can_edit']) ? (int)$user['can_edit'] : 0,
        'can_delete' => isset($user['can_delete']) ? (int)$user['can_delete'] : 0,
    ];
}

// читаем строки user_permissions, если они существуют
try {
    $placeholders = implode(',', array_fill(0, count($resources), '?'));
    $sql = "SELECT resource, can_view, can_edit, can_delete FROM user_permissions WHERE user_id = ? AND resource IN ($placeholders)";
    $params = array_merge([$uid], array_keys($resources));
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
    $err = $err ?: 'Ошибка при чтении прав: ' . $e->getMessage();
}

// HTML вывод
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Права — Пользователь #<?= htmlspecialchars($uid, ENT_QUOTES) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="<?= htmlspecialchars($basePublic . '/assets/css/style.css', ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($basePublic . '/assets/css/users.css', ENT_QUOTES) ?>">
<style>
html, body { height:100%; margin:0; padding:0; background:#f6f7fb; font-family: Arial, sans-serif; }
.wrap-center{max-width:1100px;margin:20px auto;padding:0 12px}
.container{background:#fff;border-radius:10px;box-shadow:0 6px 18px rgba(2,6,23,0.04);overflow:hidden;padding:20px}
.title{font-size:20px;font-weight:700;color:#111827}
.legend{color:#6b7280;margin-bottom:12px}
.controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.btn{padding:8px 12px;border-radius:8px;border:0;background:#0b57a4;color:#fff;cursor:pointer}
.btn.ghost{background:transparent;color:#0b57a4;border:1px solid #e6eef7}
.notice { padding:10px 12px;border-radius:8px;margin-bottom:12px; }
.notice.ok { background:#eaf7ef;color:#116530;border:1px solid #cfead1; }
.notice.err { background:#fff6f6;color:#7a1f1f;border:1px solid #f2c6c6; }
.small { font-size:13px;color:#6b7280; }
.res-block{border:1px solid #eef2f6;padding:12px;border-radius:8px;margin-bottom:12px;display:block}
.res-row{display:grid;grid-template-columns:1fr repeat(3,minmax(120px,160px));gap:12px;align-items:center}
@media (max-width:720px){ .res-row{grid-template-columns:1fr} .perm-cols{display:flex;gap:8px;margin-top:8px} .perm-cols label{flex:1} }
.checkbox{display:inline-flex;gap:8px;align-items:center;cursor:pointer}
.checkbox input[type="checkbox"]{width:16px;height:16px;margin:0;cursor:pointer}
.actions{display:flex;gap:8px;justify-content:flex-end;margin-top:6px}
.note-muted{color:#6b7280;padding:10px;border:1px dashed #eef2f6;border-radius:8px}
.role-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.role-select{padding:8px;border-radius:8px;border:1px solid #e6e9ef}
.role-note{font-size:13px;color:#6b7280}
</style>
</head>
<body>

<?php
// подключаем хедер админки (если есть)
$headerPath = __DIR__ . '/header.php';
if (file_exists($headerPath)) {
    require_once $headerPath;
} else {
    $parentHeader = __DIR__ . '/../header.php';
    if (file_exists($parentHeader)) require_once $parentHeader;
}
?>

<div class="wrap-center">
  <div class="container">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap">
      <div class="controls">
        <a href="<?= htmlspecialchars($basePublic . '/admin/users.php', ENT_QUOTES) ?>" class="btn ghost">← Вернуться в список</a>
      </div>

      <div style="text-align:right;">
        <div class="title">Права — <?= htmlspecialchars($user['name'] ?? ('#'.$uid), ENT_QUOTES) ?> (ID <?= $uid ?>)</div>
        <div class="small"><?= htmlspecialchars($user['phone'] ?? '') ?></div>
      </div>
    </div>

    <?php if ($msg): ?>
      <div class="notice ok"><?= htmlspecialchars($msg, ENT_QUOTES) ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="notice err"><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <div class="legend">Управление правами по ресурсам.</div>

    <!-- Роль пользователя: доступна для изменения только суперадмину -->
    <div class="role-row" role="group" aria-labelledby="roleLabel">
      <div id="roleLabel" style="font-weight:700">Роль пользователя</div>

      <form id="roleForm" method="post" action="<?= htmlspecialchars($basePublic . '/admin/action_user.php?action=update_role', ENT_QUOTES) ?>" style="display:flex;gap:10px;align-items:center;">
        <input type="hidden" name="user_id" value="<?= $uid ?>">
        <?php
          // текущая роль в БД
          $currentRoleValue = $user['role'] ?? 'user';
        ?>
        <select name="role" class="role-select" <?= $isSuper ? '' : 'disabled aria-disabled="true"' ?>>
          <option value="user" <?= $currentRoleValue === 'user' ? 'selected' : '' ?>>User</option>
          <option value="admin" <?= $currentRoleValue === 'admin' ? 'selected' : '' ?>>Admin</option>
          <option value="superadmin" <?= $currentRoleValue === 'superadmin' ? 'selected' : '' ?>>Superadmin</option>
        </select>

        <?php if ($isSuper): ?>
          <button type="submit" class="btn">Сохранить роль</button>
        <?php else: ?>
          <div class="role-note">Изменение роли доступно только супер-админу.</div>
        <?php endif; ?>
      </form>
    </div>

    <div class="legend" style="margin-top:10px">Переключатели прав по ресурсам. Только супер-админ может сохранить изменения.</div>

    <form method="post" action="<?= htmlspecialchars($basePublic . '/admin/action_user.php?action=update_permissions', ENT_QUOTES) ?>">
      <input type="hidden" name="user_id" value="<?= $uid ?>">

      <?php foreach ($resources as $res => $label):
          $cur = $perms[$res] ?? ['can_view'=>0,'can_edit'=>0,'can_delete'=>0];
          $disabled = $isSuper ? '' : 'disabled';
          $ariaDisabled = $isSuper ? 'false' : 'true';
      ?>
        <div class="res-block" aria-labelledby="res-<?= htmlspecialchars($res, ENT_QUOTES) ?>">
          <div class="res-row">
            <div id="res-<?= htmlspecialchars($res, ENT_QUOTES) ?>" style="font-weight:700;"><?= htmlspecialchars($label, ENT_QUOTES) ?></div>

            <div class="perm-cols" style="justify-self:start;">
              <label class="checkbox" aria-disabled="<?= $ariaDisabled ?>">
                <input type="checkbox" name="perm_<?= htmlspecialchars($res, ENT_QUOTES) ?>_view" value="1" <?= $cur['can_view'] ? 'checked' : '' ?> <?= $disabled ?> >
                <span>Просмотр</span>
              </label>
            </div>

            <div class="perm-cols" style="justify-self:start;">
              <label class="checkbox" aria-disabled="<?= $ariaDisabled ?>">
                <input type="checkbox" name="perm_<?= htmlspecialchars($res, ENT_QUOTES) ?>_edit" value="1" <?= $cur['can_edit'] ? 'checked' : '' ?> <?= $disabled ?> >
                <span>Редактирование</span>
              </label>
            </div>

            <div class="perm-cols" style="justify-self:start;">
              <label class="checkbox" aria-disabled="<?= $ariaDisabled ?>">
                <input type="checkbox" name="perm_<?= htmlspecialchars($res, ENT_QUOTES) ?>_delete" value="1" <?= $cur['can_delete'] ? 'checked' : '' ?> <?= $disabled ?> >
                <span>Удаление</span>
              </label>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if ($isSuper): ?>
        <div class="actions">
          <button type="submit" class="btn">Сохранить права</button>
        </div>
      <?php else: ?>
        <div class="note-muted">Вы не супер-админ — права нельзя менять.</div>
      <?php endif; ?>
    </form>
  </div>
</div>

</body>
</html>
