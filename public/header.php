<?php
// public/header.php — header с кнопками (Сервисы/Услуги, Авто) и логикой видимости для неавторизованных
// Обновлён: надёжно показывает "Админка" для role=admin OR role=superadmin OR is_superadmin=1
if (session_status() === PHP_SESSION_NONE) session_start();

// проектный корень (папка mehanik)
$projectRoot = dirname(__DIR__);

// config и db (если есть)
$configPath = $projectRoot . '/config.php';
$dbPath     = $projectRoot . '/db.php';

$config = ['base_url' => '/mehanik/public'];
if (file_exists($configPath)) {
    $cfg = require $configPath;
    if (is_array($cfg)) $config = array_merge($config, $cfg);
}

// Попытка подключения к БД (db.php должен создавать $mysqli или $pdo)
if (file_exists($dbPath)) require_once $dbPath;

// base URL
$base = rtrim($config['base_url'] ?? '/mehanik/public', '/');

// получим данные пользователя из сессии
$user = $_SESSION['user'] ?? null;
$uid = !empty($user['id']) ? (int)$user['id'] : 0;

// reload данных если не хватает
$needReload = false;
if ($uid) {
    if (!isset($user['role']) || !isset($user['is_superadmin'])) $needReload = true;
}

// Try to reload from DB if needed (supports mysqli and PDO)
if ($needReload && $uid) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        try {
            $sql = "SELECT id,name,phone,role,created_at,verify_code,status,ip,
                           COALESCE(is_superadmin,0) AS is_superadmin,
                           COALESCE(can_view,0) AS can_view,
                           COALESCE(can_edit,0) AS can_edit,
                           COALESCE(can_delete,0) AS can_delete
                    FROM users WHERE id = ? LIMIT 1";
            if ($st = $mysqli->prepare($sql)) {
                $st->bind_param('i', $uid);
                $st->execute();
                $res = $st->get_result();
                $fresh = $res ? $res->fetch_assoc() : null;
                $st->close();
                if ($fresh) {
                    $fresh['is_superadmin'] = (int)($fresh['is_superadmin'] ?? 0);
                    $fresh['can_view'] = (int)($fresh['can_view'] ?? 0);
                    $fresh['can_edit'] = (int)($fresh['can_edit'] ?? 0);
                    $fresh['can_delete'] = (int)($fresh['can_delete'] ?? 0);
                    $_SESSION['user'] = $fresh;
                    $user = $fresh;
                }
            }
        } catch (Throwable $e) { /* ignore */ }
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        try {
            $sql = "SELECT id,name,phone,role,created_at,verify_code,status,ip,
                           COALESCE(is_superadmin,0) AS is_superadmin,
                           COALESCE(can_view,0) AS can_view,
                           COALESCE(can_edit,0) AS can_edit,
                           COALESCE(can_delete,0) AS can_delete
                    FROM users WHERE id = :id LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute([':id' => $uid]);
            $fresh = $st->fetch(PDO::FETCH_ASSOC);
            if ($fresh) {
                $fresh['is_superadmin'] = (int)($fresh['is_superadmin'] ?? 0);
                $fresh['can_view'] = (int)($fresh['can_view'] ?? 0);
                $fresh['can_edit'] = (int)($fresh['can_edit'] ?? 0);
                $fresh['can_delete'] = (int)($fresh['can_delete'] ?? 0);
                $_SESSION['user'] = $fresh;
                $user = $fresh;
            }
        } catch (Throwable $e) { /* ignore */ }
    }
}

// fallback admin phone
if (!defined('ADMIN_PHONE_FOR_VERIFY')) define('ADMIN_PHONE_FOR_VERIFY', '+99363722023');

// compute visibility of admin panel link
$isAdminPanelVisible = false;
if (!empty($user)) {
    $role = strtolower((string)($user['role'] ?? ''));
    $isSuperFlag = ((int)($user['is_superadmin'] ?? 0) === 1);
    $cfgSuperId = isset($config['superadmin_id']) ? (int)$config['superadmin_id'] : 0;
    if ($role === 'admin' || $role === 'superadmin' || $isSuperFlag || ($cfgSuperId && $uid === $cfgSuperId)) {
        $isAdminPanelVisible = true;
    }
}

// css
$cssPath = htmlspecialchars($base . '/assets/css/header.css', ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="<?= $cssPath ?>">

<header class="topbar" role="banner">
  <div class="wrap" style="display:flex;align-items:center;gap:12px;max-width:1100px;margin:0 auto;padding:10px 12px;">
    <a class="brand" href="<?= htmlspecialchars($base . '/index.php') ?>" style="font-weight:700;font-size:1.15rem;color:#fff;text-decoration:none;">Mehanik</a>

    <nav class="nav" aria-label="Главная навигация" style="display:flex;gap:10px;align-items:center;margin-left:16px;">
      <?php if (!empty($user)): // показываем ссылки на Сервисы/Услуги и Авто только авторизованным ?>
        <!-- Кнопка для сервисов -->
        <a href="<?= htmlspecialchars($base . '/services.php') ?>" style="color:#fff;text-decoration:none;padding:6px 10px;border-radius:6px;">Сервисы/Услуги</a>
        <!-- Кнопка Авто (ведёт на страницу моих авто) -->
        <a href="<?= htmlspecialchars($base . '/my-cars.php') ?>" style="color:#fff;text-decoration:none;padding:6px 10px;border-radius:6px;">Авто</a>
      <?php endif; ?>

      <?php if (!empty($user)): ?>
        <a href="<?= htmlspecialchars($base . '/my-products.php') ?>" style="color:#fff;text-decoration:none;padding:6px 10px;border-radius:6px;">Запчасти</a>
        <a href="<?= htmlspecialchars($base . '/chat.php') ?>" style="color:#fff;text-decoration:none;padding:6px 10px;border-radius:6px;">Техподдержка чат</a>

        <?php if ($isAdminPanelVisible): ?>
          <a href="<?= htmlspecialchars($base . '/admin/index.php') ?>" style="color:#fff;text-decoration:none;padding:6px 10px;border-radius:6px;">Админка</a>
        <?php endif; ?>

        <a href="<?= htmlspecialchars($base . '/logout.php') ?>" style="color:#fff;text-decoration:none;padding:6px 10px;border-radius:6px;">Выйти</a>
      <?php else: ?>
        <a href="<?= htmlspecialchars($base . '/login.php') ?>" style="color:#fff;text-decoration:none;padding:6px 10px;border-radius:6px;">Войти</a>
        <a href="<?= htmlspecialchars($base . '/register.php') ?>" style="color:#fff;text-decoration:none;padding:6px 10px;border-radius:6px;">Регистрация</a>
      <?php endif; ?>
    </nav>

    <div class="user-block" aria-live="polite" style="margin-left:auto;color:#fff;text-align:right;">
      <?php if (!empty($user)): ?>
        <?php $status = $user['status'] ?? 'pending'; ?>
        <?php if ($status === 'pending'): ?>
          <div style="font-weight:700;">ОЖИДАНИЕ ПОДТВЕРЖДЕНИЯ</div>
          <div style="font-size:.9rem;">Отправьте SMS с номера <strong><?= htmlspecialchars($user['phone']) ?></strong> код <strong><?= htmlspecialchars($user['verify_code'] ?? '-') ?></strong></div>
          <div style="font-size:.85rem;margin-top:6px;">на номер <strong><?= ADMIN_PHONE_FOR_VERIFY ?></strong></div>
        <?php elseif ($status === 'active' || $status === 'approved'): ?>
          <div><strong><?= htmlspecialchars($user['name'] ?? $user['phone']) ?></strong></div>
          <div style="font-size:.85rem;color:#e6f2ff;"><?= htmlspecialchars($user['phone'] ?? '') ?></div>
          <?php if (!empty($user['is_superadmin']) && (int)$user['is_superadmin'] === 1): ?>
            <div style="font-size:.75rem;color:#ffd9a8;margin-top:6px;">Superadmin</div>
          <?php endif; ?>
        <?php elseif ($status === 'rejected'): ?>
          <div style="color:#ffd2d2;font-weight:700;">Профиль отклонён</div>
        <?php else: ?>
          <div><?= htmlspecialchars($user['name'] ?? $user['phone']) ?></div>
        <?php endif; ?>
      <?php else: ?>
        <div>Гость</div>
      <?php endif; ?>
    </div>
  </div>
</header>
