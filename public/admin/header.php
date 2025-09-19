<?php
// public/admin/header.php
// Хедер админки — считает pending'и, подтягивает данные пользователя и проверяет session_version.

if (session_status() === PHP_SESSION_NONE) session_start();

$projectRoot = dirname(__DIR__); // mehanik/public -> dirname -> mehanik
$configPath = $projectRoot . '/config.php';
$dbPath     = $projectRoot . '/db.php';

// Загрузка конфига (если есть)
if (file_exists($configPath)) {
    $cfg = require $configPath;
    if (is_array($cfg)) $config = $cfg + (isset($config) ? $config : []);
}
if (!isset($config)) $config = ['base_url' => '/mehanik/public'];

// base URL (используется при редиректах и формированиях ссылок)
$base = rtrim($config['base_url'] ?? '/mehanik/public', '/');

// Подключаем DB (db.php должен инициализировать $mysqli или $pdo)
if (file_exists($dbPath)) {
    require_once $dbPath;
}

// Если пользователь залогинен — подтягиваем свежие данные + проверяем session_version
if (!empty($_SESSION['user']['id'])) {
    $uid = (int)$_SESSION['user']['id'];
    try {
        $fresh = null;
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            $sql = "SELECT id,name,phone,role,created_at,verify_code,status,ip,
                           COALESCE(is_superadmin,0) AS is_superadmin,
                           COALESCE(balance,0.00) AS balance,
                           COALESCE(session_version,0) AS session_version
                    FROM users WHERE id = ? LIMIT 1";
            if ($st = $mysqli->prepare($sql)) {
                $st->bind_param('i', $uid);
                $st->execute();
                $res = $st->get_result();
                $fresh = $res ? $res->fetch_assoc() : null;
                $st->close();
            }
        } elseif (isset($pdo) && $pdo instanceof PDO) {
            $sql = "SELECT id,name,phone,role,created_at,verify_code,status,ip,
                           COALESCE(is_superadmin,0) AS is_superadmin,
                           COALESCE(balance,0.00) AS balance,
                           COALESCE(session_version,0) AS session_version
                    FROM users WHERE id = :id LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute([':id' => $uid]);
            $fresh = $st->fetch(PDO::FETCH_ASSOC);
        }

        if ($fresh) {
            // приводим типы
            $fresh['is_superadmin'] = (int)($fresh['is_superadmin'] ?? 0);
            $fresh['balance'] = isset($fresh['balance']) ? (float)$fresh['balance'] : 0.0;
            $fresh['session_version'] = isset($fresh['session_version']) ? (int)$fresh['session_version'] : 0;

            // версия в сессии (если была)
            $sessVersionInSession = isset($_SESSION['user']['session_version']) ? (int)$_SESSION['user']['session_version'] : null;
            $dbSessionVersion = $fresh['session_version'];

            if ($sessVersionInSession !== null && $dbSessionVersion !== $sessVersionInSession) {
                // session_version поменялся — аккуратно разлогиниваем пользователя
                $_SESSION = [];
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000,
                        $params["path"], $params["domain"],
                        $params["secure"], $params["httponly"]
                    );
                }
                session_destroy();

                // Редиректим на логин (с параметром reason для дебага/UX)
                header('Location: ' . $base . '/login.php?reason=session_invalidated');
                exit;
            }

            // Сливаем свежие поля в сессию (если в сессии не было session_version — записываем её)
            $_SESSION['user'] = array_merge((array)$_SESSION['user'], (array)$fresh);
            if ($sessVersionInSession === null) {
                $_SESSION['user']['session_version'] = $dbSessionVersion;
            }
        }
    } catch (Throwable $e) {
        // не прерываем работу хедера из-за ошибок БД
    }
}

$base = rtrim($config['base_url'] ?? '/mehanik/public', '/'); // на всякий случай
$user = $_SESSION['user'] ?? null;

// подсчёт pending для пользователей/запчастей/сервисов/авто
$pendingUsers = $pendingProducts = $pendingServices = $pendingCars = 0;
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $res = $mysqli->query("SELECT COUNT(*) AS c FROM users WHERE status='pending'");
        if ($res) $pendingUsers = (int)($res->fetch_assoc()['c'] ?? 0);

        $res = $mysqli->query("SELECT COUNT(*) AS c FROM products WHERE status!='approved'");
        if ($res) $pendingProducts = (int)($res->fetch_assoc()['c'] ?? 0);

        $res = $mysqli->query("SELECT COUNT(*) AS c FROM services WHERE status='pending'");
        if ($res) $pendingServices = (int)($res->fetch_assoc()['c'] ?? 0);

        $res = $mysqli->query("SELECT COUNT(*) AS c FROM cars WHERE status!='approved'");
        if ($res) $pendingCars = (int)($res->fetch_assoc()['c'] ?? 0);
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $pendingUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='pending'")->fetchColumn();
        $pendingProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status!='approved'")->fetchColumn();
        $pendingServices = (int)$pdo->query("SELECT COUNT(*) FROM services WHERE status='pending'")->fetchColumn();
        $pendingCars = (int)$pdo->query("SELECT COUNT(*) FROM cars WHERE status!='approved'")->fetchColumn();
    }
} catch (Throwable $e) {
    // ignore and leave zeros
}

// текущий путь (без query string) для выделения активного пункта
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';

// helper: возвращает 'active' если $link ведёт на текущий путь (совпадение или startsWith)
function isActiveLink(string $link, string $currentPath, bool $strict = false): bool {
    $linkPath = parse_url($link, PHP_URL_PATH) ?: $link;
    $lp = rtrim($linkPath, '/');
    $cp = rtrim($currentPath, '/');

    if ($lp === '') $lp = '/';
    if ($cp === '') $cp = '/';

    if ($strict) return $lp === $cp;
    if ($lp === $cp) return true;
    if ($lp !== '/' && strpos($cp, $lp) === 0) return true;
    return false;
}
?>
<style>
/* header занимает всю ширину, внутри — контейнер wrap с отступами */
.admin-top { background:#0f1724; color:#fff; padding:10px 0; box-shadow:0 2px 6px rgba(0,0,0,.08); border-bottom: 1px solid rgba(255,255,255,0.02); }
.admin-top .wrap{ max-width:1200px; margin:0 auto; padding:0 16px; box-sizing:border-box; display:flex; gap:12px; align-items:center; }

/* левый блок (бренд + навигация) растягивается */
.admin-top .wrap > div:first-child { display:flex; align-items:center; gap:8px; flex:1 1 auto; min-width:0; }

/* бренд */
.admin-top .brand { font-weight:700; font-size:1.05rem; color:#fff; text-decoration:none; margin-right:8px; }

/* навигация по центру */
.nav-admin {
  display:flex;
  gap:10px;
  align-items:center;
  margin-left:8px;
  flex:1 1 auto;
  min-width:0;
  flex-wrap:wrap;
  padding-bottom:2px;
}

.nav-admin a {
  color:#e6eef7;
  text-decoration:none;
  padding:6px 10px;
  border-radius:8px;
  font-weight:600;
  font-size:0.95rem;
  display:inline-flex;
  align-items:center;
  white-space:nowrap;
}
.nav-admin a:hover { background: rgba(255,255,255,0.03); color:#fff; }

.nav-admin a.active { background: linear-gradient(180deg,#164e9a,#0b57a4); color:#fff; box-shadow: 0 6px 18px rgba(11,87,164,0.12); }

.badge{ display:inline-block; background:#ef4444; color:#fff; padding:2px 7px; border-radius:999px; margin-left:6px; font-weight:700; font-size:.8rem; vertical-align:middle; }

/* правая часть (информация о текущем админ-пользователе) — теперь без баланса/пополнения/выйти */
.header-right { margin-left:auto; text-align:right; display:flex; flex-direction:column; gap:6px; align-items:flex-end; min-width:0; }
.header-right .name { font-weight:700; color:#fff; }
.header-right .sub { font-size:.85rem; color:#9ca3af; }

/* compact buttons style (used in nav too) */
.header-actions .small-btn {
  background: transparent;
  border: 1px solid rgba(219,234,254,0.06);
  color: #dbeafe;
  padding: 6px 10px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
}
.header-actions .small-btn:hover { background: rgba(255,255,255,0.03); color:#fff; }

/* responsive tweaks */
@media (max-width: 800px) {
  .admin-top .wrap { padding: 8px 12px; gap:8px; }
  .admin-top .brand { font-size: 0.98rem; }
  .nav-admin a { padding:5px 8px; font-size:0.88rem; }
  .nav-admin { gap:8px; }
  .header-actions { gap:6px; }
}
@media (max-width: 480px) {
  .admin-top .brand { font-size: 0.92rem; }
  .nav-admin a { padding:4px 7px; font-size:0.82rem; border-radius:6px; }
  .nav-admin { gap:6px; }
}
@media (max-width:900px) {
  .admin-top .wrap { flex-direction:column; align-items:stretch; gap:8px; }
  .admin-top .wrap > div:first-child { flex:unset; }
  .header-right { align-items:flex-start; }
}
</style>

<header class="admin-top" role="banner">
  <div class="wrap">
    <div>
      <a class="brand" href="<?= htmlspecialchars($base . '/admin/index.php') ?>">Mehanik — Admin</a>

      <nav class="nav-admin" aria-label="Админ навигация">
        <?php
          $links = [
            ['href' => $base . '/admin/users.php', 'label' => 'Пользователи', 'badge' => $pendingUsers, 'class' => ''],
            ['href' => $base . '/admin/services.php', 'label' => 'Сервисы/Услуги', 'badge' => $pendingServices, 'class' => ''],
            ['href' => $base . '/admin/products.php', 'label' => 'Запчасти', 'badge' => $pendingProducts, 'class' => ''],
            ['href' => $base . '/admin/chats.php', 'label' => 'Чаты', 'badge' => 0, 'class' => ''],
            ['href' => $base . '/admin/cars.php', 'label' => 'Бренд/Модель', 'badge' => 0, 'class' => ''],
            ['href' => $base . '/admin/cars_moderation.php', 'label' => 'Авто', 'badge' => $pendingCars, 'class' => 'btn-catalog'],
            ['href' => $base . '/admin/notifications.php', 'label' => 'Уведомления', 'badge' => 0, 'class' => ''],
            ['href' => $base . '/admin/accounting.php', 'label' => 'Бухгалтерия', 'badge' => 0, 'class' => ''],
            ['href' => $base . '/index.php', 'label' => 'Открыть сайт', 'badge' => 0, 'class' => '']
          ];

          foreach ($links as $ln) {
              $href = $ln['href'];
              $label = $ln['label'];
              $badge = (int)($ln['badge'] ?? 0);
              $extraClass = trim($ln['class'] ?? '');

              $active = isActiveLink($href, $currentPath) ? ' active' : '';
              $classAttr = trim(($extraClass ? $extraClass : '') . $active);
              $classHtml = $classAttr ? ' class="'.htmlspecialchars($classAttr).'"' : '';

              if ($label === 'Чаты') {
                  if ($badge > 0) {
                      echo '<a href="'.htmlspecialchars($href).'"' . $classHtml . '>' . htmlspecialchars($label)
                           . ' <span id="newChatsBadge" class="badge">'.htmlspecialchars($badge).'</span></a>';
                  } else {
                      echo '<a href="'.htmlspecialchars($href).'"' . $classHtml . '>' . htmlspecialchars($label) . '</a>';
                  }
              } else {
                  echo '<a href="'.htmlspecialchars($href).'"' . $classHtml . '>' . htmlspecialchars($label);
                  if ($badge) echo " <span class='badge'>".htmlspecialchars($badge)."</span>";
                  echo '</a>';
              }
          }
        ?>
      </nav>
    </div>

    <div class="header-right" role="region" aria-live="polite">
      <?php if ($user): ?>
        <div class="name"><?= htmlspecialchars($user['name'] ?? $user['phone'] ?? 'admin') ?> <span style="font-weight:400;color:#9ca3af;">#<?= (int)($user['id'] ?? 0) ?></span></div>
        <div class="sub"><?= htmlspecialchars($user['phone'] ?? '') ?> · <?= htmlspecialchars($user['role'] ?? '') ?></div>

        <!-- Баланс / Пополнить / Выйти удалены по запросу -->
        <div class="header-actions" style="margin-top:8px;">
          <!-- Сейчас пустая область для возможных действий админа -->
        </div>
      <?php else: ?>
        <div style="text-align:right;">
          <a href="<?= htmlspecialchars($base . '/login.php') ?>" class="header-actions">Войти</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</header>

<script>
(function(){
  // small helper: безопасно установить/удалить бейдж новых чатов
  window.setNewChatsCount = function(n) {
    const nav = document.querySelector('.nav-admin');
    if (!nav) return;
    let badge = document.getElementById('newChatsBadge');
    if (!n || n <= 0) {
      if (badge) badge.remove();
      return;
    }
    if (!badge) {
      const links = nav.querySelectorAll('a');
      let chatLink = null;
      links.forEach(a=>{
        if (!chatLink) {
          try {
            if (a.textContent.trim().startsWith('Чаты')) chatLink = a;
          } catch(e){}
        }
      });
      if (!chatLink) chatLink = links[0];
      if (chatLink) {
        const span = document.createElement('span');
        span.id = 'newChatsBadge';
        span.className = 'badge';
        span.textContent = String(n);
        chatLink.appendChild(document.createTextNode(' '));
        chatLink.appendChild(span);
      }
    } else {
      badge.textContent = String(n);
    }
  };
})();
</script>
