<?php
// public/admin/header.php
// Хедер админки — считает pending'и, подтягивает баланс и обновляет сессию пользователя.

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
// При этом будем сливать свежие поля в уже существующую сессии, чтобы не потерять дополнительные флаги
if (!empty($_SESSION['user']['id'])) {
    $uid = (int)$_SESSION['user']['id'];
    try {
        $fresh = null;
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            if ($st = $mysqli->prepare("SELECT id,name,phone,role,created_at,verify_code,status,ip,
                                               COALESCE(is_superadmin,0) AS is_superadmin,
                                               COALESCE(balance,0.00) AS balance
                                        FROM users WHERE id = ? LIMIT 1")) {
                $st->bind_param('i', $uid);
                $st->execute();
                $res = $st->get_result();
                $fresh = $res ? $res->fetch_assoc() : null;
                $st->close();
            }
        } elseif (isset($pdo) && $pdo instanceof PDO) {
            $st = $pdo->prepare("SELECT id,name,phone,role,created_at,verify_code,status,ip,
                                       COALESCE(is_superadmin,0) AS is_superadmin,
                                       COALESCE(balance,0.00) AS balance
                                FROM users WHERE id = :id LIMIT 1");
            $st->execute([':id' => $uid]);
            $fresh = $st->fetch(PDO::FETCH_ASSOC);
        }
        if ($fresh) {
            // сохраняем/сливаем — чтобы не перезаписывать дополнительные поля, которые могут быть в сессии
            // приводим баланс к float
            if (isset($fresh['balance'])) $fresh['balance'] = (float)$fresh['balance'];
            $_SESSION['user'] = array_merge((array)$_SESSION['user'], (array)$fresh);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

$base = rtrim($config['base_url'] ?? '/mehanik/public', '/');
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

        // cars: объявления, которые не утверждены (pending / rejected / any != approved)
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
    // $link может быть относительным или абсолютным (с базой)
    $linkPath = parse_url($link, PHP_URL_PATH) ?: $link;
    // нормализуем слэши в конце
    $lp = rtrim($linkPath, '/');
    $cp = rtrim($currentPath, '/');

    if ($lp === '') $lp = '/';
    if ($cp === '') $cp = '/';

    if ($strict) {
        return $lp === $cp;
    }
    // точное совпадение
    if ($lp === $cp) return true;
    // стартовое совпадение (например /mehanik/public/admin/products.php и /mehanik/public/admin/products.php?id=1)
    if ($lp !== '/' && strpos($cp, $lp) === 0) return true;
    return false;
}
?>
<style>
/* header занимает всю ширину, внутри — контейнер wrap с отступами */
.admin-top { background:#0f1724; color:#fff; padding:10px 0; box-shadow:0 2px 6px rgba(0,0,0,.08); border-bottom: 1px solid rgba(255,255,255,0.02); }
.admin-top .wrap{ max-width:1200px; margin:0 auto; padding:0 16px; box-sizing:border-box; display:flex; gap:12px; align-items:center; }

/* левый блок (бренд + навигация) растягивается, чтобы навигация могла центрироваться */
.admin-top .wrap > div:first-child { display:flex; align-items:center; gap:8px; flex:1; }

/* бренд */
.admin-top .brand { font-weight:700; font-size:1.05rem; color:#fff; text-decoration:none; margin-right:8px; }

/* навигация по центру */
.nav-admin { display:flex; gap:10px; align-items:center; margin-left:8px; flex-wrap:wrap; justify-content:center; }
.nav-admin a { color:#e6eef7; text-decoration:none; padding:6px 10px; border-radius:8px; font-weight:600; font-size:0.95rem; display:inline-flex; align-items:center; }
.nav-admin a:hover { background: rgba(255,255,255,0.03); color:#fff; }

/* активный пункт */
.nav-admin a.active { background: linear-gradient(180deg,#164e9a,#0b57a4); color:#fff; box-shadow: 0 6px 18px rgba(11,87,164,0.12); }

/* btn-catalog теперь не навязывает цвет по умолчанию,
   он служит только для специального оформления активного состояния */
.btn-catalog { color:#e6eef7; padding:6px 10px; border-radius:8px; text-decoration:none; font-weight:700; }
.btn-catalog:hover { background: rgba(255,255,255,0.03); color:#fff; }
.btn-catalog.active { background: linear-gradient(180deg,#184f96,#0b57a4); box-shadow:0 6px 14px rgba(11,87,164,0.12); color:#fff; }

.badge{ display:inline-block; background:#ef4444; color:#fff; padding:2px 7px; border-radius:999px; margin-left:6px; font-weight:700; font-size:.8rem; vertical-align:middle; }

/* правая часть (информация о текущем админ-пользователе) */
.header-right { margin-left:auto; text-align:right; display:flex; flex-direction:column; gap:6px; align-items:flex-end; }
.header-right .name { font-weight:700; color:#fff; }
.header-right .sub { font-size:.85rem; color:#9ca3af; }
.header-actions { margin-top:6px; display:flex; gap:8px; align-items:center; }

/* баланс и кнопка пополнить */
.balance { font-weight:800; color:#f0f9ff; background:transparent; padding:6px 10px; border-radius:8px; }

/* topup button */
.topup-btn { background:#0b57a4; color:#fff; padding:6px 10px; border-radius:8px; font-weight:700; text-decoration:none; border:0; cursor:pointer; }
.topup-btn[disabled] { opacity:0.6; cursor:not-allowed; }

/* links inside header-actions */
.header-actions a { color:#dbeafe; text-decoration:none; padding:6px 10px; border-radius:8px; background:transparent; border:1px solid rgba(219,234,254,0.06); font-weight:600; }
.header-actions a.logout { background:transparent; border:1px solid rgba(255,255,255,0.03); color:#ffdede; }

/* buttons inside header-actions (Notifications / Accounting) */
.header-actions button {
  background: transparent;
  border: 1px solid rgba(219,234,254,0.06);
  color: #dbeafe;
  padding: 6px 10px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
}
.header-actions button:hover {
  background: rgba(255,255,255,0.03);
  color: #fff;
}

@media (max-width:900px) {
  .admin-top .wrap { flex-direction:column; align-items:stretch; gap:8px; }
  .admin-top .wrap > div:first-child { flex:unset; }
  .header-right { align-items:flex-start; }
}

/* modal для админского пополнения (демо) */
#adminTopupModal { position:fixed; inset:0; display:none; background:rgba(2,6,23,0.6); align-items:center; justify-content:center; z-index:9999; }
#adminTopupModal .m { background:#fff; color:#111; padding:18px; border-radius:10px; min-width:320px; max-width:420px; box-shadow:0 10px 30px rgba(2,6,23,0.3); }
#adminTopupModal .m h3 { margin:0 0 8px 0; }
#adminTopupModal .m .row { display:flex; gap:8px; margin-top:8px; }
#adminTopupModal .m button { padding:8px 12px; border-radius:8px; cursor:pointer; border:0; }
</style>

<header class="admin-top" role="banner">
  <div class="wrap">
    <div>
      <a class="brand" href="<?= htmlspecialchars($base . '/admin/index.php') ?>">Mehanik — Admin</a>

      <nav class="nav-admin" aria-label="Админ навигация">
        <?php
          // Подготовим ссылки — убрал принудительную синию кнопку у Services,
          // и переименовал 'Автомаркет' -> 'Авто'. btn-catalog теперь нейтральна по умолчанию.
          $links = [
            ['href' => $base . '/admin/users.php', 'label' => 'Пользователи', 'badge' => $pendingUsers, 'class' => ''],
            ['href' => $base . '/admin/services.php', 'label' => 'Сервисы/Услуги', 'badge' => $pendingServices, 'class' => ''],
            ['href' => $base . '/admin/products.php', 'label' => 'Запчасти', 'badge' => $pendingProducts, 'class' => ''],
            ['href' => $base . '/admin/chats.php', 'label' => 'Чаты', 'badge' => 0, 'class' => ''],
            ['href' => $base . '/admin/cars.php', 'label' => 'Бренд/Модель', 'badge' => 0, 'class' => ''],
            ['href' => $base . '/admin/cars_moderation.php', 'label' => 'Авто', 'badge' => $pendingCars, 'class' => 'btn-catalog'],
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
              echo '<a href="'.htmlspecialchars($href).'"' . $classHtml . '>' . htmlspecialchars($label);
              if ($badge) echo " <span class='badge'>".htmlspecialchars($badge)."</span>";
              echo '</a>';
          }
        ?>
      </nav>
    </div>

    <div class="header-right" role="region" aria-live="polite">
      <?php if ($user): ?>
        <div class="name"><?= htmlspecialchars($user['name'] ?? $user['phone'] ?? 'admin') ?> <span style="font-weight:400;color:#9ca3af;">#<?= (int)($user['id'] ?? 0) ?></span></div>
        <div class="sub"><?= htmlspecialchars($user['phone'] ?? '') ?> · <?= htmlspecialchars($user['role'] ?? '') ?></div>

        <div class="header-actions" style="margin-top:8px;">
          <div class="balance"><?= number_format((float)($user['balance'] ?? 0.0), 2, '.', ' ') ?> TMT</div>
          <button id="adminTopupBtn" class="topup-btn" type="button">Пополнить</button>

          <!-- Уведомления + Бухгалтерия -->
          <button id="adminNotificationsBtn" type="button">Уведомления</button>
          <button id="adminAccountingBtn" type="button">Бухгалтерия</button>

          <a class="header-action logout" href="<?= htmlspecialchars($base . '/logout.php') ?>">Выйти</a>
        </div>
      <?php else: ?>
        <div style="text-align:right;">
          <a href="<?= htmlspecialchars($base . '/login.php') ?>" class="header-actions">Войти</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</header>

<!-- Admin Top-up modal (demo) -->
<div id="adminTopupModal" role="dialog" aria-modal="true" aria-hidden="true">
  <div class="m" role="document">
    <h3>Пополнить баланс (демо)</h3>
    <p>Тестовое пополнение баланса. В продакшне замените на платёжный шлюз.</p>

    <label>Сумма (TMT)</label>
    <input id="adminTopupAmount" type="number" step="0.01" min="0" value="100" style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd;margin-top:6px;">

    <div class="row" style="justify-content:flex-end;">
      <button id="adminTopupClose" style="background:#f3f4f6;">Отмена</button>
      <button id="adminTopupDemoConfirm" style="background:#0b57a4;color:#fff;">Пополнить (демо)</button>
    </div>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('adminTopupModal');
  const btn = document.getElementById('adminTopupBtn');
  const closeBtn = document.getElementById('adminTopupClose');
  const confirmBtn = document.getElementById('adminTopupDemoConfirm');
  const amountInput = document.getElementById('adminTopupAmount');

  function showModal() {
    if (!modal) return;
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
  }
  function hideModal() {
    if (!modal) return;
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
  }

  if (btn && modal) {
    btn.addEventListener('click', showModal);
    if (closeBtn) closeBtn.addEventListener('click', hideModal);

    modal.addEventListener('click', function(e){
      if (e.target === modal) hideModal();
    });

    if (confirmBtn) {
      confirmBtn.addEventListener('click', async function(){
        const amount = parseFloat(amountInput.value || '0');
        if (!amount || amount <= 0) {
          alert('Введите корректную сумму.');
          return;
        }

        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Обработка...';

        try {
          const form = new FormData();
          form.append('amount', String(amount));

          const res = await fetch('<?= htmlspecialchars($base, ENT_QUOTES) ?>/api/topup_demo.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: form
          });
          const data = await res.json();
          if (data && data.ok) {
            const balanceEl = document.querySelector('.admin-top .balance');
            if (balanceEl && typeof data.balance !== 'undefined') {
              balanceEl.textContent = Number(data.balance).toFixed(2) + ' TMT';
            }
            alert('Баланс успешно пополнен на ' + Number(data.amount).toFixed(2) + ' TMT (демо).');
          } else {
            alert('Ошибка: ' + (data && data.error ? data.error : 'неизвестная'));
          }
        } catch (err) {
          console.error(err);
          alert('Сетевая ошибка при пополнении. Смотрите консоль.');
        } finally {
          confirmBtn.disabled = false;
          confirmBtn.textContent = 'Пополнить (демо)';
          hideModal();
        }
      });
    }
  }

  // --- НОВОЕ: теперь кнопки переходят на страницы ---
  const notifBtn = document.getElementById('adminNotificationsBtn');
  const accBtn = document.getElementById('adminAccountingBtn');

  // БЕЗОПАСНО встраиваем пути из PHP через json_encode
  const notifUrl = <?= json_encode($base . '/admin/notifications.php') ?>;
  const accUrl = <?= json_encode($base . '/admin/accounting.php') ?>;

  if (notifBtn) {
    notifBtn.addEventListener('click', function(){
      // напрямую переходим на страницу Уведомлений
      window.location.href = notifUrl;
    });
  }

  if (accBtn) {
    accBtn.addEventListener('click', function(){
      // напрямую переходим на страницу Бухгалтерии
      window.location.href = accUrl;
    });
  }

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') hideModal();
  });
})();
</script>
