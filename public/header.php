<?php
// public/header.php

$projectRoot = dirname(__DIR__);

// Попытаемся включить middleware — он стартует сессию и подгружает DB (если есть)
$middlewarePath = $projectRoot . '/middleware.php';
if (file_exists($middlewarePath)) {
    require_once $middlewarePath;
} else {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $dbPath = $projectRoot . '/db.php';
    if (file_exists($dbPath)) {
        require_once $dbPath;
    }
}

// load config (fallback)
$configPath = $projectRoot . '/config.php';
$config = ['base_url' => '/mehanik/public'];
if (file_exists($configPath)) {
    $cfg = require $configPath;
    if (is_array($cfg)) $config = array_merge($config, $cfg);
}
$base = rtrim($config['base_url'] ?? '/mehanik/public', '/');

// refresh session user if available
if (function_exists('refresh_session_user')) {
    try { refresh_session_user(); } catch (Throwable $e) { /* ignore */ }
}
if (function_exists('enforce_session_version')) {
    try { enforce_session_version(); } catch (Throwable $e) { /* ignore */ }
}

// get user from session
$user = $_SESSION['user'] ?? null;
$uid = !empty($user['id']) ? (int)$user['id'] : 0;

// try to fetch missing fields if middleware didn't
if ($uid && (!isset($user['role']) || !array_key_exists('balance', $user) || !isset($user['is_superadmin']))) {
    $dbPath = $projectRoot . '/db.php';
    if (file_exists($dbPath) && !function_exists('refresh_session_user')) {
        try {
            require_once $dbPath;
            if (isset($mysqli) && $mysqli instanceof mysqli) {
                $sql = "SELECT id,name,phone,role,created_at,verify_code,status,ip,
                               COALESCE(is_superadmin,0) AS is_superadmin,
                               COALESCE(balance,0.00) AS balance
                        FROM users WHERE id = ? LIMIT 1";
                if ($st = $mysqli->prepare($sql)) {
                    $st->bind_param('i', $uid);
                    $st->execute();
                    $res = $st->get_result();
                    $fresh = $res ? $res->fetch_assoc() : null;
                    $st->close();
                    if ($fresh) {
                        $fresh['is_superadmin'] = (int)($fresh['is_superadmin'] ?? 0);
                        $fresh['balance'] = (float)($fresh['balance'] ?? 0.0);
                        $_SESSION['user'] = array_merge((array)$_SESSION['user'], (array)$fresh);
                        $user = $_SESSION['user'];
                    }
                }
            } elseif (isset($pdo) && $pdo instanceof PDO) {
                $sql = "SELECT id,name,phone,role,created_at,verify_code,status,ip,
                               COALESCE(is_superadmin,0) AS is_superadmin,
                               COALESCE(balance,0.00) AS balance
                        FROM users WHERE id = :id LIMIT 1";
                $st = $pdo->prepare($sql);
                $st->execute([':id' => $uid]);
                $fresh = $st->fetch(PDO::FETCH_ASSOC);
                if ($fresh) {
                    $fresh['is_superadmin'] = (int)($fresh['is_superadmin'] ?? 0);
                    $fresh['balance'] = (float)($fresh['balance'] ?? 0.0);
                    $_SESSION['user'] = array_merge((array)$_SESSION['user'], (array)$fresh);
                    $user = $_SESSION['user'];
                }
            }
        } catch (Throwable $e) {
            // ignore — header must not fail on DB problems
        }
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

<style>
/* lightweight local tweaks to ensure centered nav and responsive movement */
.topbar { background: #0f1724; color: #fff; border-bottom:1px solid rgba(255,255,255,0.03); }
.topbar .wrap { display:flex; align-items:center; gap:12px; max-width:1200px; margin:0 auto; padding:10px 12px; box-sizing:border-box; }
.brand { font-weight:700; font-size:1.15rem; color:#fff; text-decoration:none; }
.nav-center { flex:1; display:flex; justify-content:center; align-items:center; gap:10px; flex-wrap:wrap; }
.nav-center a { color:#e6eef7; text-decoration:none; padding:6px 10px; border-radius:6px; font-weight:600; }
.nav-center a:hover { background: rgba(255,255,255,0.03); color:#fff; }

/* user block now inline: name + meta (ID and balance) on single row */
.user-block { margin-left:12px; display:flex; align-items:center; gap:12px; }
.user-name { font-weight:700; }
.user-meta { font-size:.9rem; color:#e6f2ff; background: rgba(255,255,255,0.02); padding:6px 10px; border-radius:8px; }

/* small screens: stack properly */
@media (max-width:900px) {
  .nav-center { justify-content:flex-start; flex: 1 1 auto; }
  .user-block { flex-direction:column; align-items:flex-end; gap:6px; }
  .user-meta { width:100%; text-align:right; }
}
</style>

<header class="topbar" role="banner">
  <div class="wrap">
    <div style="display:flex;align-items:center;gap:12px;">
      <a class="brand" href="<?= htmlspecialchars($base . '/index.php') ?>">Mehanik</a>
    </div>

    <nav class="nav-center" aria-label="Главная навигация">
      <?php if (!empty($user)): // показываем ссылки на Сервисы/Услуги и Авто только авторизованным ?>
        <a href="<?= htmlspecialchars($base . '/services.php') ?>">Сервисы/Услуги</a>
        <a href="<?= htmlspecialchars($base . '/my-cars.php') ?>">Авто</a>
        <a href="<?= htmlspecialchars($base . '/my-products.php') ?>">Запчасти</a>

        <!-- НОВАЯ КНОПКА: Мои объявления -->
        <a href="<?= htmlspecialchars($base . '/my-ads.php') ?>">Мои объявления</a>

        <a href="<?= htmlspecialchars($base . '/chat.php') ?>">Техподдержка чат</a>

        <?php if ($isAdminPanelVisible): ?>
          <a href="<?= htmlspecialchars($base . '/admin/index.php') ?>">Админка</a>
        <?php endif; ?>

        <a href="<?= htmlspecialchars($base . '/logout.php') ?>">Выйти</a>
      <?php else: ?>
        <a href="<?= htmlspecialchars($base . '/login.php') ?>">Войти</a>
        <a href="<?= htmlspecialchars($base . '/register.php') ?>">Регистрация</a>
      <?php endif; ?>
    </nav>

    <div class="user-block" aria-live="polite">
      <?php if (!empty($user)): ?>
        <?php $status = $user['status'] ?? 'pending'; ?>
        <?php if ($status === 'pending'): ?>
          <div style="font-weight:700;">ОЖИДАНИЕ ПОДТВЕРЖДЕНИЯ</div>
          <div style="font-size:.9rem;">Отправьте SMS с номера <strong><?= htmlspecialchars($user['phone']) ?></strong> код <strong><?= htmlspecialchars($user['verify_code'] ?? '-') ?></strong></div>
          <div style="font-size:.85rem;margin-top:6px;">на номер <strong><?= ADMIN_PHONE_FOR_VERIFY ?></strong></div>
        <?php else: ?>
          <!-- Имя + вровень: ID и баланс -->
          <div style="display:flex;flex-direction:column;line-height:1;">
            <div class="user-name"><?= htmlspecialchars($user['name'] ?? $user['phone']) ?></div>
            <div style="font-size:.85rem;color:#e6f2ff;"><?= htmlspecialchars($user['phone'] ?? '') ?></div>
          </div>

          <div class="user-meta" title="ID пользователя и баланс">
            ID: #<?= htmlspecialchars((int)$uid) ?> — <?= number_format((float)($user['balance'] ?? 0.0), 2, '.', ' ') ?> TMT
          </div>

          <?php if (!empty($user['is_superadmin']) && (int)$user['is_superadmin'] === 1): ?>
            <div style="font-size:.75rem;color:#ffd9a8;margin-top:0;">Superadmin</div>
          <?php endif; ?>
        <?php endif; ?>
      <?php else: ?>
        <div>Гость</div>
      <?php endif; ?>
    </div>
  </div>
</header>
