<?php
// public/admin/header.php
// Хедер админки — считает pending'и и обновляет сессию пользователя.

if (session_status() === PHP_SESSION_NONE) session_start();

$projectRoot = dirname(__DIR__); // mehanik/public -> dirname -> mehanik
$configPath = $projectRoot . '/config.php';
$dbPath     = $projectRoot . '/db.php';

if (file_exists($configPath)) $config = require $configPath;
else $config = ['base_url' => '/mehanik/public'];

// Подключаем DB (если есть) — db.php должен инициализировать $mysqli или $pdo
if (file_exists($dbPath)) {
    require_once $dbPath;
}

// Обновим сессию текущего пользователя (подтянем свежие данные из БД)
if (!empty($_SESSION['user']['id'])) {
    $uid = (int)$_SESSION['user']['id'];
    try {
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            if ($st = $mysqli->prepare("SELECT id,name,phone,role,created_at,verify_code,status,ip FROM users WHERE id = ? LIMIT 1")) {
                $st->bind_param('i', $uid);
                $st->execute();
                $res = $st->get_result();
                $fresh = $res ? $res->fetch_assoc() : null;
                $st->close();
                if ($fresh) {
                    $_SESSION['user'] = $fresh;
                }
            }
        } elseif (isset($pdo) && $pdo instanceof PDO) {
            $st = $pdo->prepare("SELECT id,name,phone,role,created_at,verify_code,status,ip FROM users WHERE id = :id LIMIT 1");
            $st->execute([':id' => $uid]);
            $fresh = $st->fetch(PDO::FETCH_ASSOC);
            if ($fresh) $_SESSION['user'] = $fresh;
        }
    } catch (Throwable $e) {
        // ignore
    }
}

$base = rtrim($config['base_url'] ?? '/mehanik/public', '/');
$user = $_SESSION['user'] ?? null;

// подсчёт pending
$pendingUsers = $pendingProducts = $pendingServices = 0;
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $res = $mysqli->query("SELECT COUNT(*) AS c FROM users WHERE status='pending'");
        if ($res) $pendingUsers = (int)($res->fetch_assoc()['c'] ?? 0);

        $res = $mysqli->query("SELECT COUNT(*) AS c FROM products WHERE status!='approved'");
        if ($res) $pendingProducts = (int)($res->fetch_assoc()['c'] ?? 0);

        $res = $mysqli->query("SELECT COUNT(*) AS c FROM services WHERE status='pending'");
        if ($res) $pendingServices = (int)($res->fetch_assoc()['c'] ?? 0);
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $pendingUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='pending'")->fetchColumn();
        $pendingProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status!='approved'")->fetchColumn();
        $pendingServices = (int)$pdo->query("SELECT COUNT(*) FROM services WHERE status='pending'")->fetchColumn();
    }
} catch (Throwable $e) {
    // ignore and leave zeros
}
?>
<style>
.admin-top { background:#111827;color:#fff;padding:10px 14px;box-shadow:0 2px 6px rgba(0,0,0,.1); }
.admin-top .wrap{max-width:1100px;margin:0 auto;display:flex;gap:12px;align-items:center;}
.admin-top a{color:#fff;text-decoration:none;padding:6px 10px;border-radius:6px;}
.badge{display:inline-block;background:#ef4444;color:#fff;padding:2px 6px;border-radius:999px;margin-left:6px;font-weight:700;font-size:.85rem;}
.nav-admin { display:flex; gap:8px; align-items:center; }
.btn-catalog { background:#0b57a4; padding:6px 10px; border-radius:6px; font-weight:700; text-decoration:none; }
.btn-catalog:hover { background:#095091; }
</style>

<header class="admin-top">
  <div class="wrap">
    <a href="<?= htmlspecialchars($base . '/admin/index.php') ?>" style="font-weight:700;font-size:1.1rem;">Mehanik — Admin</a>

    <nav class="nav-admin">
      <a href="<?= htmlspecialchars($base . '/admin/users.php') ?>">Пользователи <?php if($pendingUsers) echo "<span class='badge'>{$pendingUsers}</span>"; ?></a>

      <a href="<?= htmlspecialchars($base . '/admin/services.php') ?>" class="btn-catalog">Сервисы/Услуги <?php if($pendingServices) echo "<span class='badge'>{$pendingServices}</span>"; ?></a>

      <a href="<?= htmlspecialchars($base . '/admin/products.php') ?>">Товары <?php if($pendingProducts) echo "<span class='badge'>{$pendingProducts}</span>"; ?></a>
      <a href="<?= htmlspecialchars($base . '/admin/chats.php') ?>">Чаты</a>
      <a href="<?= htmlspecialchars($base . '/admin/cars.php') ?>" class="btn-catalog">Бренд/Модель</a>
      <a href="<?= htmlspecialchars($base . '/index.php') ?>">Открыть сайт</a>
    </nav>

    <div style="margin-left:auto;text-align:right;">
      <?php if ($user): ?>
        <div style="font-weight:700;"><?= htmlspecialchars($user['name'] ?? 'admin') ?> <span style="font-weight:400;color:#9ca3af">#<?= (int)$user['id'] ?></span></div>
        <div style="font-size:.85rem;color:#9ca3af;"><?= htmlspecialchars($user['phone'] ?? '') ?></div>
      <?php else: ?>
        <a href="<?= htmlspecialchars($base . '/login.php') ?>">Войти</a>
      <?php endif; ?>
    </div>
  </div>
</header>
