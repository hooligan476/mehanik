<?php
// public/header.php — простой хедер без поиска и бургер-меню

if (session_status() === PHP_SESSION_NONE) session_start();

// проектный корень (папка mehanik)
$projectRoot = dirname(__DIR__);

// config и db (если есть)
$configPath = $projectRoot . '/config.php';
$dbPath     = $projectRoot . '/db.php';

if (file_exists($configPath)) {
    $config = require $configPath;
} else {
    $config = ['base_url' => '/mehanik/public'];
}

// Подключаем db.php, если существует (создаёт $mysqli или $pdo)
if (file_exists($dbPath)) {
    require_once $dbPath;
}

// base URL
$base = rtrim($config['base_url'] ?? '/mehanik/public', '/');

// Подтягиваем свежие данные пользователя, если есть id в сессии
$user = $_SESSION['user'] ?? null;
if (!empty($user['id'])) {
    $uid = (int)$user['id'];

    if (isset($mysqli) && $mysqli instanceof mysqli) {
        try {
            if ($st = $mysqli->prepare("SELECT id,name,phone,role,created_at,verify_code,status,ip FROM users WHERE id = ? LIMIT 1")) {
                $st->bind_param('i', $uid);
                $st->execute();
                $res = $st->get_result();
                $fresh = $res ? $res->fetch_assoc() : null;
                $st->close();
                if ($fresh) {
                    $_SESSION['user'] = $fresh;
                    $user = $fresh;
                }
            }
        } catch (Throwable $e) { /* ignore */ }
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        try {
            $st = $pdo->prepare("SELECT id,name,phone,role,created_at,verify_code,status,ip FROM users WHERE id = :id LIMIT 1");
            $st->execute([':id' => $uid]);
            $fresh = $st->fetch(PDO::FETCH_ASSOC);
            if ($fresh) {
                $_SESSION['user'] = $fresh;
                $user = $fresh;
            }
        } catch (Throwable $e) { /* ignore */ }
    }
}

// Admin phone for verification (fallback)
if (!defined('ADMIN_PHONE_FOR_VERIFY')) define('ADMIN_PHONE_FOR_VERIFY', '+99363722023');

// Путь к CSS
$cssPath = htmlspecialchars($base . '/assets/css/header.css', ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="<?= $cssPath ?>">

<header class="topbar" role="banner">
  <div class="wrap" style="display:flex;align-items:center;gap:12px;max-width:1100px;margin:0 auto;">
    <a class="brand" href="<?= htmlspecialchars($base . '/index.php') ?>" style="font-weight:700;font-size:1.15rem;color:#fff;text-decoration:none;">Mehanik</a>

    <nav class="nav" aria-label="Главная навигация" style="display:flex;gap:10px;align-items:center;margin-left:16px;">
      <?php if (!empty($user)): ?>
        <a href="<?= htmlspecialchars($base . '/add-product.php') ?>" style="color:#fff;text-decoration:none;padding:6px 10px;border-radius:6px;">Добавить товар</a>
        <a href="<?= htmlspecialchars($base . '/my-products.php') ?>" style="color:#fff;text-decoration:none;padding:6px 10px;border-radius:6px;">Мои товары</a>
        <a href="<?= htmlspecialchars($base . '/chat.php') ?>" style="color:#fff;text-decoration:none;padding:6px 10px;border-radius:6px;">Техподдержка чат</a>
        <?php if (($user['role'] ?? '') === 'admin'): ?>
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
